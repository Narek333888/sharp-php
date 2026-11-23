# SharpPHP language server for PhpStorm

An [LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) template: completion, parameter info (also
with ⌘P / Ctrl+P), errors while typing, hover and go to definition for `.sphp` files in PhpStorm and other
JetBrains IDEs. It starts
`php $PROJECT_DIR$/vendor/bin/sharp lsp` of the open project (parameter info needs SharpPHP 0.6 or newer).

1. **Settings → Plugins → Marketplace**: install **LSP4IJ** (restart PhpStorm if it asks).
2. **Settings → Languages & Frameworks → Language Servers (LSP4IJ)**, click **+**.
3. In the **New Language Server** dialog, next to **Template:**, click **Import template...** and select this
   folder (`vendor/armdevstack/sharp-php/editors/lsp4ij/sharpphp`).
4. On macOS with Homebrew PHP, replace `php` in **Command:** with the full path (`which php` prints it), e.g.
   `/opt/homebrew/bin/php $PROJECT_DIR$/vendor/bin/sharp lsp`: PhpStorm started from the Dock does not see
   Homebrew's `PATH`.
5. **OK**, and **OK** in Settings.

After `composer update`, restart the server: **View → Tool Windows → Language Servers**, right-click
**SharpPHP** → **Restart**.

The full guide, with highlighting, running programs and troubleshooting, is
[PhpStorm setup](../../../README.md#phpstorm-setup) in the README.
