'use strict';

// SharpPHP for VS Code. Highlighting comes from the grammar declared in package.json; this file is a
// small Language Server Protocol client, without dependencies, for the SharpPHP language server
// ("php vendor/bin/sharp lsp"): completion, errors while typing, hover and go to definition.
//
// One server runs per workspace folder that contains the server (vendor/bin/sharp by default).

const vscode = require('vscode');
const childProcess = require('child_process');
const path = require('path');
const fs = require('fs');

const LANGUAGE = 'sharpphp';
const SELECTOR = { language: LANGUAGE, scheme: 'file' };

/** A server that crashes more than MAX_RESTARTS times within CRASH_WINDOW_MS is not restarted again. */
const MAX_RESTARTS = 3;
const CRASH_WINDOW_MS = 3 * 60 * 1000;
const FIRST_RESTART_DELAY_MS = 500;
const SHUTDOWN_TIMEOUT_MS = 2000;
const EXIT_TIMEOUT_MS = 1000;

const METHOD_NOT_FOUND = -32601;

/** @type {vscode.OutputChannel} */
let output;
/** @type {Map<string, Client>} workspace folder URI => its server */
const clients = new Map();
/** Restarts run one after another (several settings may change at once). */
let restarting = Promise.resolve();

function log(message, folder) {
    const time = new Date().toTimeString().slice(0, 8);
    output.appendLine(`[${time}]${folder ? ` [${folder.name}]` : ''} ${message}`);
}

function errorText(error) {
    return error instanceof Error ? error.message : String(error);
}

/** Shows an error with buttons that open the output, the settings or restart the servers. */
function showError(message, ...actions) {
    vscode.window.showErrorMessage(message, ...actions).then((choice) => {
        if (choice === 'Show Output') {
            output.show(true);
        } else if (choice === 'Open Settings') {
            vscode.commands.executeCommand('workbench.action.openSettings', 'sharpphp');
        } else if (choice === 'Restart Server') {
            vscode.commands.executeCommand('sharpphp.restartServer');
        }
    });
}

/** The PHP executable and the server script for a workspace folder, from the settings. */
function settingsOf(folder) {
    const config = vscode.workspace.getConfiguration('sharpphp', folder.uri);
    const root = folder.uri.fsPath;
    const expand = (value) => (typeof value === 'string' ? value.trim() : '').replace(/\$\{workspaceFolder\}/g, root);
    const php = expand(config.get('php.path', 'php')) || 'php';
    const configured = expand(config.get('server.path', ''));
    const server = configured !== '' ? path.resolve(root, configured) : path.join(root, 'vendor', 'bin', 'sharp');

    return { php, server, configured: configured !== '' };
}

function realpath(file) {
    try {
        return fs.realpathSync(file);
    } catch {
        return file;
    }
}

function isSharpDocument(document) {
    return document.languageId === LANGUAGE && document.uri.scheme === 'file';
}

function clientFor(document) {
    const folder = vscode.workspace.getWorkspaceFolder(document.uri);

    return folder ? clients.get(folder.uri.toString()) : undefined;
}

// ---------------------------------------------------------------------------------------------
// LSP <=> VS Code conversions (LSP positions are 0-based, like VS Code's)

function toPosition(position) {
    const line = Number.isInteger(position && position.line) ? position.line : 0;
    const character = Number.isInteger(position && position.character) ? position.character : 0;

    return new vscode.Position(Math.max(0, line), Math.max(0, character));
}

function toRange(range) {
    return new vscode.Range(toPosition(range && range.start), toPosition(range && (range.end || range.start)));
}

/** LSP MarkupContent / MarkedString => MarkdownString */
function toMarkdown(content) {
    if (typeof content === 'string') {
        return new vscode.MarkdownString(content);
    }
    if (content && typeof content.language === 'string') {
        return new vscode.MarkdownString().appendCodeblock(String(content.value || ''), content.language);
    }
    if (content && content.kind === 'markdown') {
        return new vscode.MarkdownString(String(content.value || ''));
    }

    return new vscode.MarkdownString().appendText(String((content && content.value) || ''));
}

function toSeverity(severity) {
    switch (severity) {
        case 2: return vscode.DiagnosticSeverity.Warning;
        case 3: return vscode.DiagnosticSeverity.Information;
        case 4: return vscode.DiagnosticSeverity.Hint;
        default: return vscode.DiagnosticSeverity.Error;
    }
}

function toDiagnostic(item) {
    const diagnostic = new vscode.Diagnostic(toRange(item.range), String(item.message || ''), toSeverity(item.severity));
    if (item.code !== undefined && item.code !== null) {
        diagnostic.code = item.code;
    }
    diagnostic.source = typeof item.source === 'string' ? item.source : 'sharpphp';

    return diagnostic;
}

function toCompletionItem(item) {
    // vscode.CompletionItemKind is the LSP CompletionItemKind minus one (LSP Text = 1, vscode Text = 0).
    const kind = Number.isInteger(item.kind) && item.kind >= 1 && item.kind <= 25 ? item.kind - 1 : undefined;
    const completion = new vscode.CompletionItem(String(item.label), kind);
    if (typeof item.detail === 'string') {
        completion.detail = item.detail;
    }
    if (item.documentation) {
        // A string is plain text here (in hovers it is markdown).
        completion.documentation = typeof item.documentation === 'string' ? item.documentation : toMarkdown(item.documentation);
    }
    if (typeof item.sortText === 'string') {
        completion.sortText = item.sortText;
    }
    if (typeof item.filterText === 'string') {
        completion.filterText = item.filterText;
    }
    if (typeof item.insertText === 'string') {
        completion.insertText = item.insertTextFormat === 2 ? new vscode.SnippetString(item.insertText) : item.insertText;
    }

    return completion;
}

// ---------------------------------------------------------------------------------------------
// One language server process for one workspace folder

class Client {
    constructor(folder) {
        this.folder = folder;
        /** starting | running | restarting | stopping | stopped | failed */
        this.state = 'stopped';
        /** @type {import('child_process').ChildProcess | null} */
        this.process = null;
        this.nextId = 1;
        /** @type {Map<number, {resolve: Function, reject: Function, method: string, cancel?: vscode.Disposable}>} */
        this.pending = new Map();
        this.buffer = Buffer.alloc(0);
        this.stderr = '';
        /** URIs of the documents the server was sent with didOpen */
        this.synced = new Set();
        this.diagnostics = vscode.languages.createDiagnosticCollection(LANGUAGE);
        this.crashes = [];
        this.restartTimer = null;
        this.ready = Promise.resolve(false);
        this.markReady = () => {};
        // The server reports resolved paths (/private/tmp/x for /tmp/x): map them back to the folder.
        this.realRoot = realpath(folder.uri.fsPath);
    }

    log(message) {
        log(message, this.folder);
    }

    start() {
        clearTimeout(this.restartTimer);
        this.restartTimer = null;
        const { php, server } = settingsOf(this.folder);
        const root = this.folder.uri.fsPath;
        this.log(`Starting the language server: ${php} ${server} lsp`);

        let child;
        try {
            child = childProcess.spawn(php, [server, 'lsp'], { cwd: root, stdio: ['pipe', 'pipe', 'pipe'], windowsHide: true });
        } catch (error) {
            this.fail(`SharpPHP: cannot start the language server: ${errorText(error)}`, 'Show Output', 'Open Settings');

            return;
        }
        this.process = child;
        this.state = 'starting';
        this.buffer = Buffer.alloc(0);
        this.stderr = '';
        this.ready = new Promise((resolve) => { this.markReady = resolve; });

        child.stdout.on('data', (chunk) => this.receive(child, chunk));
        child.stderr.setEncoding('utf8');
        child.stderr.on('data', (text) => this.serverLog(child, text));
        child.stdin.on('error', (error) => {
            // EPIPE when the server died: the exit handler deals with that.
            if (this.process === child) {
                this.log(`Cannot write to the language server: ${errorText(error)}`);
            }
        });
        child.on('error', (error) => this.onProcessError(child, error, php));
        child.on('exit', (code, signal) => this.onExit(child, code, signal));

        this.request('initialize', {
            processId: process.pid,
            clientInfo: { name: 'Visual Studio Code', version: vscode.version },
            rootPath: root,
            rootUri: this.folder.uri.toString(),
            workspaceFolders: [{ uri: this.folder.uri.toString(), name: this.folder.name }],
            capabilities: {
                textDocument: {
                    synchronization: { didSave: true, dynamicRegistration: false },
                    completion: { completionItem: { snippetSupport: false, documentationFormat: ['markdown', 'plaintext'] }, contextSupport: true },
                    hover: { contentFormat: ['markdown', 'plaintext'] },
                    definition: { linkSupport: false },
                    publishDiagnostics: { relatedInformation: false },
                },
                workspace: { workspaceFolders: true, configuration: false },
            },
            trace: 'off',
        }).then((result) => {
            if (this.process !== child) {
                return;
            }
            this.state = 'running';
            this.notify('initialized', {});
            const info = result && result.serverInfo;
            this.log(`Language server running (pid ${child.pid}${info ? `, ${info.name} ${info.version}` : ''})`);
            for (const document of vscode.workspace.textDocuments) {
                this.didOpen(document);
            }
            this.markReady(true);
        }, (error) => {
            if (this.process === child && child.exitCode === null) {
                // The server answered initialize with an error: it will not work, so do not retry.
                this.state = 'failed';
                child.kill();
                this.fail(`SharpPHP: the language server did not start: ${errorText(error)}`, 'Show Output');
            }
        });
    }

    /** Stops the server: shutdown request, exit notification, and a kill if it does not exit. */
    async stop() {
        clearTimeout(this.restartTimer);
        this.restartTimer = null;
        const child = this.process;
        if (!child || child.exitCode !== null || child.signalCode !== null) {
            this.process = null;
            this.state = 'stopped';
            this.synced.clear();
            this.diagnostics.clear();

            return;
        }
        this.state = 'stopping';
        const exited = new Promise((resolve) => child.once('exit', resolve));
        try {
            await withTimeout(this.request('shutdown'), SHUTDOWN_TIMEOUT_MS);
            this.notify('exit');
        } catch (error) {
            this.log(`The language server did not shut down cleanly: ${errorText(error)}`);
        }
        if (!(await withTimeout(exited, EXIT_TIMEOUT_MS).then(() => true, () => false))) {
            this.log('The language server did not exit: killing it');
            child.kill();
            await withTimeout(exited, EXIT_TIMEOUT_MS).catch(() => {});
        }
    }

    async dispose() {
        await this.stop();
        this.diagnostics.dispose();
    }

    /** Resolves to true once the server answered initialize (false when it is not running). */
    whenReady() {
        if (this.state === 'running') {
            return Promise.resolve(true);
        }

        return this.state === 'starting' ? this.ready : Promise.resolve(false);
    }

    onProcessError(child, error, php) {
        if (this.process !== child) {
            return;
        }
        if (child.pid !== undefined) {
            this.log(`Language server process error: ${errorText(error)}`);

            return;
        }
        // The process could not be spawned: retrying will not help.
        this.process = null;
        this.cleanUp();
        if (error && error.code === 'ENOENT') {
            this.fail(`SharpPHP: PHP was not found ("${php}"). Set "sharpphp.php.path" to the full path of PHP ("which php" in a terminal prints it).`, 'Open Settings', 'Show Output');
        } else {
            this.fail(`SharpPHP: cannot start "${php}": ${errorText(error)}`, 'Open Settings', 'Show Output');
        }
    }

    onExit(child, code, signal) {
        if (this.process !== child) {
            return;
        }
        this.flushServerLog();
        if (this.buffer.length > 0) {
            // Not a protocol message: a PHP error printed to stdout, for example.
            this.log(`server output: ${this.buffer.toString('utf8').trim().slice(0, 2000)}`);
            this.buffer = Buffer.alloc(0);
        }
        this.process = null;
        const state = this.state;
        this.cleanUp();
        const how = signal ? `signal ${signal}` : `exit code ${code}`;
        if (state === 'stopping') {
            this.state = 'stopped';
            this.log(`Language server stopped (${how})`);

            return;
        }
        if (state === 'failed') {
            return;
        }
        if (state === 'starting') {
            this.fail(`SharpPHP: the language server for "${this.folder.name}" exited while starting (${how}). It needs SharpPHP 0.3+ in the project and PHP 8.4+; the reason is in Output → SharpPHP.`, 'Show Output', 'Restart Server');

            return;
        }
        const now = Date.now();
        this.crashes = this.crashes.filter((time) => now - time < CRASH_WINDOW_MS);
        this.crashes.push(now);
        if (this.crashes.length > MAX_RESTARTS) {
            this.fail(`SharpPHP: the language server for "${this.folder.name}" crashed ${this.crashes.length} times in a few minutes and is not restarted again (last: ${how}).`, 'Show Output', 'Restart Server');

            return;
        }
        const delay = FIRST_RESTART_DELAY_MS * 2 ** (this.crashes.length - 1);
        this.state = 'restarting';
        this.log(`Language server exited unexpectedly (${how}); restarting in ${delay} ms`);
        this.restartTimer = setTimeout(() => this.start(), delay);
    }

    /** After the process is gone: fail its requests, forget its documents and errors. */
    cleanUp() {
        const pending = [...this.pending.values()];
        this.pending.clear();
        for (const request of pending) {
            if (request.cancel) {
                request.cancel.dispose();
            }
            request.reject(new Error('the language server stopped'));
        }
        this.synced.clear();
        this.diagnostics.clear();
        this.markReady(false);
    }

    fail(message, ...actions) {
        this.state = 'failed';
        this.log(message);
        showError(message, ...actions);
    }

    serverLog(child, text) {
        if (this.process !== child) {
            return;
        }
        const lines = (this.stderr + text).split(/\r?\n/);
        this.stderr = lines.pop();
        for (const line of lines) {
            this.log(`server: ${line}`);
        }
    }

    flushServerLog() {
        if (this.stderr !== '') {
            this.log(`server: ${this.stderr}`);
            this.stderr = '';
        }
    }

    // --- JSON-RPC over stdio with Content-Length framing ----------------------------------

    send(message) {
        const child = this.process;
        if (!child || !child.stdin.writable) {
            return false;
        }
        const json = JSON.stringify({ jsonrpc: '2.0', ...message });
        child.stdin.write(`Content-Length: ${Buffer.byteLength(json, 'utf8')}\r\n\r\n${json}`, 'utf8');

        return true;
    }

    notify(method, params) {
        this.send(params === undefined ? { method } : { method, params });
    }

    /** Sends a request; a cancelled request resolves to null. */
    request(method, params, token) {
        return new Promise((resolve, reject) => {
            const id = this.nextId++;
            if (!this.send(params === undefined ? { id, method } : { id, method, params })) {
                reject(new Error('the language server is not running'));

                return;
            }
            const entry = { resolve, reject, method };
            if (token) {
                entry.cancel = token.onCancellationRequested(() => {
                    if (this.pending.delete(id)) {
                        this.notify('$/cancelRequest', { id });
                        resolve(null);
                    }
                });
            }
            this.pending.set(id, entry);
        });
    }

    receive(child, chunk) {
        if (this.process !== child) {
            return;
        }
        this.buffer = this.buffer.length === 0 ? chunk : Buffer.concat([this.buffer, chunk]);
        for (;;) {
            const headerEnd = this.buffer.indexOf('\r\n\r\n');
            if (headerEnd < 0) {
                return;
            }
            const header = this.buffer.subarray(0, headerEnd).toString('ascii');
            const match = /Content-Length:\s*(\d+)/i.exec(header);
            if (!match) {
                this.log(`Ignoring a message without Content-Length: ${header}`);
                this.buffer = this.buffer.subarray(headerEnd + 4);
                continue;
            }
            const start = headerEnd + 4;
            const end = start + Number(match[1]);
            if (this.buffer.length < end) {
                return;
            }
            const body = this.buffer.subarray(start, end).toString('utf8');
            this.buffer = this.buffer.subarray(end);
            try {
                this.dispatch(JSON.parse(body));
            } catch (error) {
                this.log(`Cannot handle a message from the server: ${errorText(error)}: ${body.slice(0, 200)}`);
            }
        }
    }

    dispatch(message) {
        if (typeof message.method === 'string') {
            if (message.id !== undefined && message.id !== null) {
                this.answer(message);
            } else {
                this.onNotification(message.method, message.params || {});
            }

            return;
        }
        const request = this.pending.get(message.id);
        if (!request) {
            return; // cancelled, or not ours
        }
        this.pending.delete(message.id);
        if (request.cancel) {
            request.cancel.dispose();
        }
        if (message.error) {
            request.reject(new Error(`${request.method}: ${message.error.message || 'error'}`));
        } else {
            request.resolve(message.result === undefined ? null : message.result);
        }
    }

    /** Answers a request of the server at once, so that it never waits for the editor. */
    answer(message) {
        const params = message.params || {};
        let result = null;
        switch (message.method) {
            case 'workspace/configuration':
                result = Array.isArray(params.items) ? params.items.map(() => null) : [];
                break;
            case 'workspace/workspaceFolders':
                result = [{ uri: this.folder.uri.toString(), name: this.folder.name }];
                break;
            case 'client/registerCapability':
            case 'client/unregisterCapability':
            case 'window/workDoneProgress/create':
                break;
            case 'window/showMessageRequest':
                this.onNotification('window/showMessage', params);
                break;
            default:
                this.send({ id: message.id, error: { code: METHOD_NOT_FOUND, message: `Unsupported request: ${message.method}` } });

                return;
        }
        this.send({ id: message.id, result });
    }

    onNotification(method, params) {
        switch (method) {
            case 'textDocument/publishDiagnostics':
                if (typeof params.uri === 'string') {
                    const diagnostics = Array.isArray(params.diagnostics) ? params.diagnostics : [];
                    this.diagnostics.set(this.toLocalUri(params.uri), diagnostics.map(toDiagnostic));
                }
                break;
            case 'window/logMessage':
                this.log(`server: ${params.message}`);
                break;
            case 'window/showMessage': {
                const text = `SharpPHP: ${params.message}`;
                if (params.type === 1) {
                    vscode.window.showErrorMessage(text);
                } else if (params.type === 2) {
                    vscode.window.showWarningMessage(text);
                } else {
                    vscode.window.showInformationMessage(text);
                }
                break;
            }
            default:
                break; // $/progress, telemetry/event, ...
        }
    }

    /** A URI from the server, with the folder's resolved path mapped back to the path VS Code uses. */
    toLocalUri(value) {
        const uri = vscode.Uri.parse(value);
        const root = this.folder.uri.fsPath;
        if (uri.scheme === 'file' && this.realRoot !== root && uri.fsPath.startsWith(this.realRoot + path.sep)) {
            return vscode.Uri.file(root + uri.fsPath.slice(this.realRoot.length));
        }

        return uri;
    }

    // --- Document synchronization (full text) ----------------------------------------------

    owns(document) {
        const folder = vscode.workspace.getWorkspaceFolder(document.uri);

        return isSharpDocument(document) && folder !== undefined && folder.uri.toString() === this.folder.uri.toString();
    }

    didOpen(document) {
        const uri = document.uri.toString();
        if (this.state !== 'running' || this.synced.has(uri) || !this.owns(document)) {
            return;
        }
        this.synced.add(uri);
        this.notify('textDocument/didOpen', { textDocument: { uri, languageId: LANGUAGE, version: document.version, text: document.getText() } });
    }

    didChange(document) {
        const uri = document.uri.toString();
        if (this.state === 'running' && this.synced.has(uri)) {
            this.notify('textDocument/didChange', { textDocument: { uri, version: document.version }, contentChanges: [{ text: document.getText() }] });
        }
    }

    didSave(document) {
        const uri = document.uri.toString();
        if (this.state === 'running' && this.synced.has(uri)) {
            this.notify('textDocument/didSave', { textDocument: { uri } });
        }
    }

    didClose(document) {
        const uri = document.uri.toString();
        if (this.synced.delete(uri) && this.state === 'running') {
            this.notify('textDocument/didClose', { textDocument: { uri } });
        }
    }

    // --- Requests for the providers -----------------------------------------------------------

    /** A textDocument/* request at a position, or null when the server is not available. */
    async at(method, document, position, token, extra) {
        if (!(await this.whenReady()) || (token && token.isCancellationRequested)) {
            return null;
        }
        this.didOpen(document); // normally done already; harmless if so
        try {
            return await this.request(method, {
                textDocument: { uri: document.uri.toString() },
                position: { line: position.line, character: position.character },
                ...extra,
            }, token);
        } catch (error) {
            if (this.state === 'running') {
                this.log(`${method} failed: ${errorText(error)}`);
            }

            return null;
        }
    }
}

function withTimeout(promise, ms) {
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(`no answer within ${ms} ms`)), ms);
    });

    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

// ---------------------------------------------------------------------------------------------
// Servers for the workspace folders

/** Starts the server of a folder unless it runs (or gave up) already. */
function ensureClient(folder, quiet) {
    const key = folder.uri.toString();
    if (clients.has(key) || !vscode.workspace.isTrusted) {
        return clients.get(key);
    }
    const { server, configured } = settingsOf(folder);
    if (!fs.existsSync(server)) {
        if (!quiet) {
            log(`No language server: ${server} does not exist${configured ? ' (sharpphp.server.path)' : ' (composer require armdevstack/sharp-php)'}`, folder);
            if (configured) {
                showError(`SharpPHP: the language server "${server}" set in "sharpphp.server.path" does not exist.`, 'Open Settings');
            }
        }

        return undefined;
    }
    const client = new Client(folder);
    clients.set(key, client);
    client.start();

    return client;
}

function startAll() {
    if (!vscode.workspace.isTrusted) {
        log('Restricted Mode: the language server starts once the workspace is trusted.');

        return 0;
    }
    let started = 0;
    for (const folder of vscode.workspace.workspaceFolders || []) {
        if (ensureClient(folder, false)) {
            started++;
        }
    }

    return started;
}

async function stopAll() {
    const all = [...clients.values()];
    clients.clear();
    await Promise.all(all.map((client) => client.dispose().catch((error) => client.log(`Stopping failed: ${errorText(error)}`))));
}

function restartAll(fromCommand) {
    restarting = restarting.then(async () => {
        if (clients.size > 0) {
            log('Restarting the language servers');
        }
        await stopAll();
        const started = startAll();
        if (fromCommand && started === 0) {
            vscode.window.showWarningMessage(vscode.workspace.isTrusted
                ? 'SharpPHP: no workspace folder has the language server (vendor/bin/sharp). Run "composer require armdevstack/sharp-php" in the project, or set "sharpphp.server.path".'
                : 'SharpPHP: the language server does not run in Restricted Mode. Trust the workspace first.');
        }
    }).catch((error) => log(`Restart failed: ${errorText(error)}`));

    return restarting;
}

// ---------------------------------------------------------------------------------------------
// Providers

const completionProvider = {
    async provideCompletionItems(document, position, token, context) {
        const client = clientFor(document);
        if (!client) {
            return undefined;
        }
        const lspContext = context
            ? { triggerKind: context.triggerKind + 1, ...(context.triggerCharacter ? { triggerCharacter: context.triggerCharacter } : {}) }
            : { triggerKind: 1 };
        const result = await client.at('textDocument/completion', document, position, token, { context: lspContext });
        if (!result) {
            return undefined;
        }
        const items = Array.isArray(result) ? result : Array.isArray(result.items) ? result.items : [];

        return new vscode.CompletionList(items.map(toCompletionItem), !Array.isArray(result) && result.isIncomplete === true);
    },
};

const hoverProvider = {
    async provideHover(document, position, token) {
        const client = clientFor(document);
        const result = client ? await client.at('textDocument/hover', document, position, token) : null;
        if (!result || !result.contents) {
            return undefined;
        }
        const contents = (Array.isArray(result.contents) ? result.contents : [result.contents]).map(toMarkdown);

        return new vscode.Hover(contents, result.range ? toRange(result.range) : undefined);
    },
};

const definitionProvider = {
    async provideDefinition(document, position, token) {
        const client = clientFor(document);
        const result = client ? await client.at('textDocument/definition', document, position, token) : null;
        if (!result) {
            return undefined;
        }
        const toLocation = (location) => (location.targetUri
            ? new vscode.Location(client.toLocalUri(location.targetUri), toRange(location.targetSelectionRange || location.targetRange))
            : new vscode.Location(client.toLocalUri(location.uri), toRange(location.range)));
        const locations = (Array.isArray(result) ? result : [result]).filter((l) => l && (l.uri || l.targetUri));

        return locations.length === 1 ? toLocation(locations[0]) : locations.map(toLocation);
    },
};

// ---------------------------------------------------------------------------------------------

function activate(context) {
    output = vscode.window.createOutputChannel('SharpPHP');
    context.subscriptions.push(
        output,
        vscode.languages.registerCompletionItemProvider(SELECTOR, completionProvider, '.'),
        vscode.languages.registerHoverProvider(SELECTOR, hoverProvider),
        vscode.languages.registerDefinitionProvider(SELECTOR, definitionProvider),
        vscode.commands.registerCommand('sharpphp.restartServer', () => restartAll(true)),
        vscode.workspace.onDidOpenTextDocument((document) => {
            if (!isSharpDocument(document)) {
                return;
            }
            const folder = vscode.workspace.getWorkspaceFolder(document.uri);
            // A folder whose vendor/bin/sharp appeared after "composer install" gets its server now.
            const client = folder && ensureClient(folder, true);
            if (client) {
                client.didOpen(document);
            }
        }),
        vscode.workspace.onDidChangeTextDocument((event) => {
            if (event.contentChanges.length > 0) {
                const client = clientFor(event.document);
                if (client) {
                    client.didChange(event.document);
                }
            }
        }),
        vscode.workspace.onDidSaveTextDocument((document) => {
            const client = clientFor(document);
            if (client) {
                client.didSave(document);
            }
        }),
        vscode.workspace.onDidCloseTextDocument((document) => {
            const client = clientFor(document);
            if (client) {
                client.didClose(document);
            }
        }),
        vscode.workspace.onDidChangeConfiguration((event) => {
            if (event.affectsConfiguration('sharpphp')) {
                restartAll(false);
            }
        }),
        vscode.workspace.onDidChangeWorkspaceFolders((event) => {
            for (const folder of event.removed) {
                const client = clients.get(folder.uri.toString());
                if (client) {
                    clients.delete(folder.uri.toString());
                    client.dispose();
                }
            }
            for (const folder of event.added) {
                ensureClient(folder, false);
            }
        }),
        vscode.workspace.onDidGrantWorkspaceTrust(() => startAll()),
    );
    startAll();
}

function deactivate() {
    return stopAll();
}

module.exports = { activate, deactivate };
