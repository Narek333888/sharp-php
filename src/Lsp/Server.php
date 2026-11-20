<?php

declare(strict_types=1);

namespace SharpPhp\Lsp;

use SharpPhp\CodeGen\Emitter;

/**
 * The SharpPHP language server ("sharp lsp"): Language Server Protocol over stdin/stdout, for
 * PhpStorm (LSP4IJ), VS Code and any other LSP client.
 *
 * Features: diagnostics while typing, completion (after "." and of names), hover, go to definition,
 * parameter info (the signature of the call whose arguments are typed).
 */
final class Server
{
    private Workspace $workspace;
    private LanguageService $service;

    /** @var array<string, true> files last published with errors, so fixing them clears the editor's markers */
    private array $published = [];

    private bool $shutdown = false;
    private bool $diagnosticsPending = false;

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(private $input, private $output)
    {
        $this->workspace = new Workspace();
        $this->service = new LanguageService($this->workspace);
    }

    /** Reads and answers messages until "exit"; returns the process exit code. */
    public function run(): int
    {
        while (($message = $this->read()) !== null) {
            foreach ($this->handle($message) as $reply) {
                $this->write($reply);
            }
            if (($message['method'] ?? null) === 'exit') {
                return $this->shutdown ? 0 : 1;
            }
            // Typing sends a change per keystroke: report errors once the editor pauses.
            if (!$this->inputWaiting()) {
                foreach ($this->flushDiagnostics() as $notification) {
                    $this->write($notification);
                }
            }
        }

        return 0;
    }

    /**
     * Answers one message.
     *
     * @param array<mixed> $message
     * @return list<array<string, mixed>> responses and notifications to send
     */
    public function handle(array $message): array
    {
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $id = $message['id'] ?? null;

        ob_start();
        try {
            $result = $this->dispatch($method, $params);
        } catch (\Throwable $e) {
            fwrite(STDERR, "SharpPHP LSP: $method failed: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}" . PHP_EOL);

            return $id !== null ? [['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $e->getMessage()]]] : [];
        } finally {
            // Nothing may reach stdout but protocol messages.
            $stray = (string) ob_get_clean();
            if ($stray !== '') {
                fwrite(STDERR, $stray);
            }
        }

        return $id !== null ? [['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]] : [];
    }

    /** @param array<mixed> $params */
    private function dispatch(string $method, array $params): mixed
    {
        switch ($method) {
            case 'initialize':
                $this->workspace->setRoot(self::rootOf($params));

                return [
                    'capabilities' => [
                        'textDocumentSync' => ['openClose' => true, 'change' => 1, 'save' => ['includeText' => false]],
                        'completionProvider' => ['triggerCharacters' => ['.'], 'resolveProvider' => false],
                        'hoverProvider' => true,
                        'definitionProvider' => true,
                        // Shown when "(" opens the arguments and updated at each ",".
                        'signatureHelpProvider' => ['triggerCharacters' => ['(', ','], 'retriggerCharacters' => [',']],
                    ],
                    'serverInfo' => ['name' => 'SharpPHP', 'version' => Emitter::VERSION],
                ];
            case 'initialized':
                $this->diagnosticsPending = true;

                return null;
            case 'shutdown':
                $this->shutdown = true;

                return null;
            case 'textDocument/didOpen':
                $document = self::arrayOf($params['textDocument'] ?? null);
                $this->workspace->open(self::path($document), self::stringOf($document['text'] ?? ''));
                $this->diagnosticsPending = true;

                return null;
            case 'textDocument/didChange':
                $changes = self::arrayOf($params['contentChanges'] ?? null);
                $last = self::arrayOf(end($changes) ?: null);
                if (isset($last['text'])) {
                    $this->workspace->open(self::path(self::arrayOf($params['textDocument'] ?? null)), self::stringOf($last['text']));
                }
                $this->diagnosticsPending = true;

                return null;
            case 'textDocument/didSave':
                $this->diagnosticsPending = true;

                return null;
            case 'textDocument/didClose':
                $this->workspace->close(self::path(self::arrayOf($params['textDocument'] ?? null)));
                $this->diagnosticsPending = true;

                return null;
            case 'textDocument/completion':
                [$path, $line, $character] = self::position($params);

                return ['isIncomplete' => false, 'items' => $this->service->completion($path, $line, $character)];
            case 'textDocument/hover':
                [$path, $line, $character] = self::position($params);

                return $this->service->hover($path, $line, $character);
            case 'textDocument/definition':
                [$path, $line, $character] = self::position($params);

                return $this->service->definition($path, $line, $character);
            case 'textDocument/signatureHelp':
                [$path, $line, $character] = self::position($params);

                return $this->service->signatureHelp($path, $line, $character);
            default:
                return null; // notifications we do not use ($/cancelRequest, workspace/didChangeConfiguration, ...)
        }
    }

    /**
     * publishDiagnostics notifications when files changed since the last ones (empty lists clear
     * the markers of files that are fixed now).
     *
     * @return list<array<string, mixed>>
     */
    public function flushDiagnostics(): array
    {
        if (!$this->diagnosticsPending) {
            return [];
        }
        $this->diagnosticsPending = false;
        $byFile = $this->service->diagnostics();
        foreach ($this->published as $path => $_) {
            $byFile[$path] ??= [];
        }
        $open = array_flip($this->workspace->openPaths());
        $notifications = [];
        $current = [];
        foreach ($byFile as $path => $diagnostics) {
            // Open documents always get a list; other files only when they have errors or had them.
            if ($diagnostics === [] && !isset($this->published[$path]) && !isset($open[$path])) {
                continue;
            }
            $notifications[] = self::notification('textDocument/publishDiagnostics', ['uri' => Workspace::pathToUri($path), 'diagnostics' => $diagnostics]);
            if ($diagnostics !== []) {
                $current[$path] = true;
            }
        }
        $this->published = $current;

        return $notifications;
    }

    /** @return array<mixed>|null */
    private function read(): ?array
    {
        $length = null;
        while (($line = fgets($this->input)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($length === null) {
                    continue;
                }
                break;
            }
            if (preg_match('/^Content-Length:\s*(\d+)$/i', $line, $m) === 1) {
                $length = (int) $m[1];
            }
        }
        if ($length === null) {
            return null;
        }
        $body = '';
        while (strlen($body) < $length && !feof($this->input)) {
            $chunk = fread($this->input, max(1, $length - strlen($body)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        $message = json_decode($body, true);

        return is_array($message) ? $message : [];
    }

    /** @param array<string, mixed> $message */
    private function write(array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }
        fwrite($this->output, 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json);
        fflush($this->output);
    }

    /**
     * Whether another message is already there, so that diagnostics can wait for the editor to pause.
     *
     * Messages that arrived along with the last one wait in the stream's read buffer. Otherwise the
     * pipe is watched for 150 ms; but not on Windows, where stream_select() reports a pipe as readable
     * even when it is empty, which would hold diagnostics back for good. There a message that is still
     * on its way is not seen, and diagnostics follow every change.
     */
    private function inputWaiting(): bool
    {
        if (stream_get_meta_data($this->input)['unread_bytes'] > 0) {
            return true;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        $read = [$this->input];
        $write = $except = null;

        return @stream_select($read, $write, $except, 0, 150_000) > 0;
    }

    /** @param array<mixed> $params */
    private static function rootOf(array $params): ?string
    {
        $folders = self::arrayOf($params['workspaceFolders'] ?? null);
        $first = self::arrayOf($folders[0] ?? null);
        $uri = $first['uri'] ?? $params['rootUri'] ?? null;
        if (is_string($uri)) {
            return Workspace::uriToPath($uri);
        }

        return is_string($params['rootPath'] ?? null) ? $params['rootPath'] : (getcwd() ?: null);
    }

    /**
     * @param array<mixed> $params
     * @return array{0: string, 1: int, 2: int}
     */
    private static function position(array $params): array
    {
        $position = self::arrayOf($params['position'] ?? null);

        return [
            self::path(self::arrayOf($params['textDocument'] ?? null)),
            is_int($position['line'] ?? null) ? $position['line'] : 0,
            is_int($position['character'] ?? null) ? $position['character'] : 0,
        ];
    }

    /** @param array<mixed> $document */
    private static function path(array $document): string
    {
        return Workspace::uriToPath(self::stringOf($document['uri'] ?? ''));
    }

    /** @return array<mixed> */
    private static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function notification(string $method, array $params): array
    {
        return ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
    }
}
