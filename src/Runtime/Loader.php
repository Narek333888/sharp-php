<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * Autoloader for compiled SharpPHP classes, driven by the build manifest's class map.
 */
final class Loader
{
    /** @var array<string, string> lowercase FQN => absolute file */
    private static array $classMap = [];

    private static bool $registered = false;

    /**
     * @param array<string, string> $classMap lowercase FQN => path relative to $baseDir
     */
    public static function register(array $classMap, string $baseDir): void
    {
        foreach ($classMap as $class => $file) {
            self::$classMap[strtolower($class)] = $baseDir . DIRECTORY_SEPARATOR . $file;
        }
        if (!self::$registered) {
            spl_autoload_register(static function (string $class): void {
                $file = self::$classMap[strtolower($class)] ?? null;
                if ($file !== null) {
                    require_once $file;
                }
            }, true, true);
            self::$registered = true;
        }
    }
}
