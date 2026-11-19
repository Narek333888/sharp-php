<?php

declare(strict_types=1);

namespace SharpPhp\Lsp;

use SharpPhp\Compiler\Compiler;
use SharpPhp\Compiler\SourceFile;
use SharpPhp\Project;

/**
 * The files the language server works on: the project's .sphp sources on disk, with the text of
 * documents open in the editor (possibly unsaved) taking the place of the files on disk.
 */
final class Workspace
{
    /** @var array<string, string> path => text of open documents */
    private array $documents = [];

    private ?Project $project = null;

    public function setRoot(?string $root): void
    {
        $this->project = $root !== null && is_dir($root) ? Project::fromDirectory($root) : null;
    }

    public function project(): ?Project
    {
        return $this->project;
    }

    public function open(string $path, string $text): void
    {
        $this->documents[self::normalize($path)] = $text;
    }

    public function close(string $path): void
    {
        unset($this->documents[self::normalize($path)]);
    }

    public function text(string $path): ?string
    {
        $path = self::normalize($path);
        if (isset($this->documents[$path])) {
            return $this->documents[$path];
        }
        $text = is_file($path) ? file_get_contents($path) : false;

        return $text === false ? null : $text;
    }

    /** @return list<string> */
    public function openPaths(): array
    {
        return array_keys($this->documents);
    }

    /**
     * Every source of the project (open documents as they are in the editor), plus open documents
     * outside the project. $replace substitutes the text of one file (completion edits a copy).
     *
     * @param array<string, string> $replace path => text
     * @return list<SourceFile>
     */
    public function sources(array $replace = []): array
    {
        $texts = [];
        if ($this->project !== null) {
            foreach (Compiler::findSources($this->project->sourceDir) as $file) {
                $texts[self::normalize($file->path)] = $file->text;
            }
        }
        foreach ($this->documents as $path => $text) {
            $texts[$path] = $text;
        }
        foreach ($replace as $path => $text) {
            $texts[self::normalize($path)] = $text;
        }
        ksort($texts, SORT_STRING);

        $files = [];
        foreach ($texts as $path => $text) {
            $files[] = new SourceFile($path, $text);
        }

        return $files;
    }

    public static function uriToPath(string $uri): string
    {
        if (!str_starts_with($uri, 'file://')) {
            return $uri;
        }
        $path = rawurldecode(substr($uri, 7));
        // file:///C:/project => C:/project
        if (preg_match('~^/[A-Za-z]:/~', $path) === 1) {
            $path = substr($path, 1);
        }

        return $path;
    }

    public static function pathToUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));
        // keep the drive colon of Windows paths readable: C%3A => C:
        $encoded = preg_replace('~^([A-Za-z])%3A~', '$1:', $encoded) ?? $encoded;

        return 'file://' . (str_starts_with($encoded, '/') ? '' : '/') . $encoded;
    }

    /** One spelling per file (symlinks such as /tmp => /private/tmp resolved). */
    public static function normalize(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $dir = realpath(dirname($path));

        return $dir !== false ? $dir . DIRECTORY_SEPARATOR . basename($path) : $path;
    }
}
