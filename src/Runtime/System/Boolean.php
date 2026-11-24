<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Boolean (C# bool): bool.Parse and bool.TryParse.
 */
final class Boolean
{
    public const string TrueString = 'True';
    public const string FalseString = 'False';

    /** bool.Parse(s): "true" or "false" in any case, with spaces, tabs or line breaks around; else FormatException. */
    public static function Parse(string $s): bool
    {
        return match (strtolower(trim($s))) {
            'true' => true,
            'false' => false,
            default => throw new FormatException("String '$s' was not recognized as a valid Boolean."),
        };
    }

    /** @param-out bool $result */
    public static function TryParse(?string $s, ?bool &$result): bool
    {
        $normalized = strtolower(trim((string) $s));
        $result = $normalized === 'true';

        return $normalized === 'true' || $normalized === 'false';
    }
}
