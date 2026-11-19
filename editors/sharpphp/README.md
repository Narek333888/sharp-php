# SharpPHP syntax highlighting

Highlighting for `.sphp` files in PhpStorm (and every other JetBrains IDE) and in VS Code.
The folder is a VS Code extension, which JetBrains IDEs can load as a TextMate bundle.
Completion and errors while typing come from the language server: see [editors/lsp4ij/sharpphp](../lsp4ij/sharpphp/README.md).

## PhpStorm

1. **Settings → Editor → TextMate Bundles**, click **+** and choose this folder:
   `vendor/armdevstack/sharp-php/editors/sharpphp` in a project that requires the package.
2. Click **OK**. `.sphp` files are highlighted from now on.

If `.sphp` files still look like plain text, they were once associated with the Text file type:
remove `*.sphp` under **Settings → Editor → File Types → Text**.

## VS Code

Copy (or symlink) this folder to `~/.vscode/extensions/armdevstack.sharpphp`, then restart VS Code.
