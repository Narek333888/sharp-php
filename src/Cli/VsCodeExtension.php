<?php

declare(strict_types=1);

namespace SharpPhp\Cli;

/**
 * Packages editors/sharpphp (highlighting + language server client) as a .vsix file and installs it
 * with VS Code's command line: "sharp vscode".
 */
final class VsCodeExtension
{
    public static function sourceDir(): string
    {
        return dirname(__DIR__, 2) . '/editors/sharpphp';
    }

    /** Writes the .vsix to $target and returns its path. */
    public static function package(string $target): string
    {
        $source = self::sourceDir();
        $manifest = json_decode((string) file_get_contents("$source/package.json"), true);
        if (!is_array($manifest)) {
            throw new \RuntimeException("Cannot read $source/package.json");
        }
        $text = static fn (string $key): string => htmlspecialchars(is_string($manifest[$key] ?? null) ? $manifest[$key] : '', ENT_XML1);
        $engine = is_array($manifest['engines'] ?? null) && is_string($manifest['engines']['vscode'] ?? null) ? $manifest['engines']['vscode'] : '^1.80.0';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="utf-8"?>' . "\n"
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension=".json" ContentType="application/json" /><Default Extension=".js" ContentType="application/javascript" />'
                . '<Default Extension=".md" ContentType="text/markdown" /><Default Extension=".vsixmanifest" ContentType="text/xml" /></Types>' . "\n",
            'extension.vsixmanifest' => <<<XML
                <?xml version="1.0" encoding="utf-8"?>
                <PackageManifest Version="2.0.0" xmlns="http://schemas.microsoft.com/developer/vsx-schema/2011" xmlns:d="http://schemas.microsoft.com/developer/vsx-schema-design/2011">
                  <Metadata>
                    <Identity Language="en-US" Id="{$text('name')}" Version="{$text('version')}" Publisher="{$text('publisher')}" />
                    <DisplayName>{$text('displayName')}</DisplayName>
                    <Description xml:space="preserve">{$text('description')}</Description>
                    <Categories>Programming Languages</Categories>
                    <Properties>
                      <Property Id="Microsoft.VisualStudio.Code.Engine" Value="{$engine}" />
                    </Properties>
                  </Metadata>
                  <Installation>
                    <InstallationTarget Id="Microsoft.VisualStudio.Code" />
                  </Installation>
                  <Dependencies />
                  <Assets>
                    <Asset Type="Microsoft.VisualStudio.Code.Manifest" Path="extension/package.json" Addressable="true" />
                  </Assets>
                </PackageManifest>

                XML,
        ];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files['extension/' . str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        @unlink($target);
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Cannot write $target");
            }
            foreach ($files as $name => $contents) {
                $zip->addFromString($name, $contents);
            }
            $zip->close();

            return $target;
        }

        return self::packageWithZipCommand($files, $target);
    }

    /** @param array<string, string> $files */
    private static function packageWithZipCommand(array $files, string $target): string
    {
        $dir = sys_get_temp_dir() . '/sharpphp-vsix-' . bin2hex(random_bytes(4));
        foreach ($files as $name => $contents) {
            if (!is_dir(dirname("$dir/$name"))) {
                mkdir(dirname("$dir/$name"), 0777, true);
            }
            file_put_contents("$dir/$name", $contents);
        }
        $process = proc_open(['zip', '-q', '-r', $target, '.'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        $exit = is_resource($process) ? proc_close($process) : -1;
        if ($exit !== 0 || !is_file($target)) {
            throw new \RuntimeException('Cannot package the VS Code extension: install the PHP zip extension or the zip command');
        }

        return $target;
    }
}
