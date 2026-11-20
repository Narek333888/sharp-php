<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Cli\VsCodeExtension;

/**
 * "sharp vscode": the VS Code extension packaged as a .vsix that "code --install-extension" accepts.
 */
final class VsCodeExtensionTest extends TestCase
{
    public function testPackage(): void
    {
        $vsix = $this->tempDir() . '/sharpphp.vsix';
        VsCodeExtension::package($vsix);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($vsix));
        foreach (['[Content_Types].xml', 'extension.vsixmanifest', 'extension/package.json', 'extension/extension.js', 'extension/syntaxes/sharpphp.tmLanguage.json'] as $name) {
            $this->assertNotFalse($zip->locateName($name), "$name is in the package");
        }
        $package = json_decode((string) $zip->getFromName('extension/package.json'), true);
        $manifest = (string) $zip->getFromName('extension.vsixmanifest');
        $zip->close();

        $this->assertIsArray($package);
        $this->assertStringContainsString('Id="sharpphp" Version="' . $package['version'] . '" Publisher="armdevstack"', $manifest);
        $this->assertSame('./extension.js', $package['main']);
        $this->assertNotFalse(simplexml_load_string($manifest), 'the manifest is valid XML');
    }
}
