<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\Json;

/**
 * System.Text.Json.JsonSerializerOptions
 *
 *   var options = new JsonSerializerOptions { WriteIndented = true, PropertyNamingPolicy = JsonNamingPolicy.CamelCase };
 */
final class JsonSerializerOptions
{
    /** Pretty-print with line breaks and indentation. */
    public bool $WriteIndented = false;

    /** How property names are written: null keeps them as declared, JsonNamingPolicy.CamelCase makes "Name" "name". */
    public ?string $PropertyNamingPolicy = null;

    /** The options ASP.NET Core uses for web APIs: camelCase property names. */
    public static function Web(): self
    {
        $options = new self();
        $options->PropertyNamingPolicy = JsonNamingPolicy::CamelCase;

        return $options;
    }
}
