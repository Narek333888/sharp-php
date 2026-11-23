# SharpPHP syntax highlighting

Highlighting for `.sphp` files in PhpStorm (and every other JetBrains IDE) and in VS Code.
The folder is a VS Code extension, which JetBrains IDEs can load as a TextMate bundle.
Completion and errors while typing come from the language server (`vendor/bin/sharp lsp`): in VS Code
this extension starts it (see [VS Code](#vs-code) below); for PhpStorm see
[PhpStorm setup](../../README.md#phpstorm-setup) in the README.

## PhpStorm

1. **Settings → Editor → TextMate Bundles**, click **+** and choose this folder:
   `vendor/armdevstack/sharp-php/editors/sharpphp` in a project that requires the package.
2. Click **OK**. `.sphp` files are highlighted from now on.

If `.sphp` files still look like plain text, they were once associated with the Text file type:
remove `*.sphp` under **Settings → Editor → File Types → Text**.

## VS Code

In VS Code this folder is a complete extension: highlighting, plus completion, parameter info, errors while
typing, hover and go to definition from the SharpPHP language server. It has no dependencies and no build
step.

### Requirements

- The project requires SharpPHP, so that `vendor/bin/sharp` exists in it (`composer require armdevstack/sharp-php`;
  `composer show armdevstack/sharp-php` prints the version; parameter info needs 0.6 or newer).
- PHP 8.4 or newer, which VS Code can find (see `sharpphp.php.path` below).
- The project folder (the one with `composer.json` and `vendor/`) is open in VS Code, or is a folder of a
  multi-root workspace: a language server starts for each workspace folder that has `vendor/bin/sharp`.
- The workspace is trusted. In Restricted Mode only highlighting works, because the language server runs
  the project's PHP code.

### Install

In a terminal, in the project folder:

```sh
vendor/bin/sharp vscode
```

It packages this folder as a `.vsix` and installs it with VS Code's `code` command. Then reload VS Code
(Command Palette → **Developer: Reload Window**). This is done once: the extension works in every project
that requires SharpPHP and always runs the project's own `vendor/bin/sharp`. Run it again after upgrading
SharpPHP to update the extension.

Without the `code` command (VS Code: Command Palette → **Shell Command: Install 'code' command in PATH**),
write the file with `vendor/bin/sharp vscode --package=sharpphp.vsix` and install it in VS Code:
**Extensions** view → **⋯** → **Install from VSIX...**.

### Settings

**Settings → Extensions → SharpPHP**, or in `settings.json`:

| Setting | Default | Meaning |
|---|---|---|
| `sharpphp.php.path` | `"php"` | PHP executable that runs the language server. Use the full path, e.g. `/opt/homebrew/bin/php` (`which php` in a terminal prints it), when VS Code does not find PHP. |
| `sharpphp.server.path` | `""`, meaning `${workspaceFolder}/vendor/bin/sharp` | The `sharp` command, started as `php <path> lsp`. A relative path is relative to the workspace folder. |

```json
{
    "sharpphp.php.path": "/opt/homebrew/bin/php"
}
```

Changing a setting restarts the language server.

### Check it

Open a `.sphp` file and type `Console.` inside `Main`: `WriteLine`, `Write`, `ReadLine` are listed. Also:

- typing `(` or `,` in a call shows its parameters, the current one in bold, e.g. `Random(int? seed = ...)`
  (also with ⇧⌘Space / Ctrl+Shift+Space);
- errors are underlined while you type and listed under **Problems** (also those of files that are not open);
- hovering a name shows what it is, e.g. `(property) string Person.Name`;
- **F12** or ⌘-click / Ctrl-click goes to the declaration, also in another file.

**View → Output**, channel **SharpPHP**, shows `Language server running (pid ..., SharpPHP 0.10.0)` (the
project's version).

### Troubleshooting

Everything the extension and the server report is in **View → Output** → **SharpPHP**.
**SharpPHP: Restart Language Server** (Command Palette: ⇧⌘P / Ctrl+Shift+P) starts the server again.

| Problem | What to do |
|---|---|
| `.sphp` files are plain text | The extension is not installed or VS Code was not reloaded: run `vendor/bin/sharp vscode`, reload the window; the **Extensions** view lists SharpPHP under **Installed** |
| No completion and no error marks | Look at Output → SharpPHP. `No language server: .../vendor/bin/sharp does not exist`: run `composer require armdevstack/sharp-php` in the project (or open the project folder itself, not a folder above it), then run **SharpPHP: Restart Language Server**. `Restricted Mode`: trust the workspace |
| "PHP was not found" | VS Code does not see PHP on its `PATH`. Set `sharpphp.php.path` to the full path of PHP: `which php` in a terminal prints it (on Windows: `where php`) |
| "exited while starting" | The output shows the reason, for example PHP older than 8.4, which cannot run it (point `sharpphp.php.path` to PHP 8.4+) |
| "crashed 4 times" | The server is not restarted again by itself. The output shows its errors; run **SharpPHP: Restart Language Server** to try again |
| Old results after `composer update` | The running server still has the old SharpPHP loaded: run **SharpPHP: Restart Language Server** (and `vendor/bin/sharp vscode` to update the extension) |
| No completion after a dot | The file has a syntax error somewhere else: fix it first (it is underlined) |
