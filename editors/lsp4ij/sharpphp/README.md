# SharpPHP language server for PhpStorm

Completion, errors while typing, hover and go to definition for `.sphp` files in PhpStorm (and every other
JetBrains IDE), through the free [LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) plugin.
This folder is an LSP4IJ template: it starts `vendor/bin/sharp lsp` of the open project.

## Requirements

- SharpPHP **0.3 or newer** in the project: `composer show armdevstack/sharp-php` prints the version.
  Older projects: `composer require armdevstack/sharp-php:^0.3` (a plain `composer update` does not leave
  0.1.x or 0.2.x, see [Upgrading](../../../README.md#upgrading)).
- Syntax highlighting is separate: see [editors/sharpphp](../../sharpphp/README.md).

## Setup (once, for every project)

1. **Settings → Plugins → Marketplace**: search **LSP4IJ** (by Red Hat), click **Install**, then
   **Restart IDE**.
2. **Settings → Languages & Frameworks → Language Servers (LSP4IJ)**, click **+**.
3. In **Template**, choose **Import from custom template...** and select this folder:
   `vendor/armdevstack/sharp-php/editors/lsp4ij/sharpphp`. Click **OK**.
4. Open a `.sphp` file and type a variable name followed by `.`: its members are listed.

The template runs `php $PROJECT_DIR$/vendor/bin/sharp lsp`, so the same server works in every project that
requires the package.

## Troubleshooting

- **Nothing happens.** Check that LSP4IJ is installed (**Settings → Plugins → Installed**) and that the
  project has SharpPHP 0.3+ (`vendor/bin/sharp lsp` must exist). A restart of PhpStorm alone does not
  turn completion on.
- **The server does not start** (often on macOS, where an IDE started from the Dock does not see Homebrew's
  `php`): put the full path of PHP in the **Command** field of the server, e.g.
  `/opt/homebrew/bin/php $PROJECT_DIR$/vendor/bin/sharp lsp` (`which php` in a terminal prints the path).
- **Server messages and errors** are in the LSP console (**View → Tool Windows → Language Servers**); set
  **Debug → Trace** to `verbose` in the server's settings to see every request.
- **No completion after a dot** while the file has a syntax error somewhere else: fix that error first; the
  other features keep working.
