<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\Json;

/**
 * System.Text.Json.JsonNamingPolicy: JsonNamingPolicy.CamelCase writes "FirstName" as "firstName".
 */
final class JsonNamingPolicy
{
    public const string CamelCase = 'camelCase';

    /**
     * The name as a naming policy writes it (.NET's policy.ConvertName(name)):
     * JsonNamingPolicy.CamelCase lowers the leading capitals, "FirstName" to "firstName",
     * "URLValue" to "urlValue", "ID" to "id" (ASCII letters only); no policy keeps the name.
     */
    public static function apply(?string $policy, string $name): string
    {
        if ($policy !== self::CamelCase || $name === '') {
            return $name;
        }
        // Like .NET: lower the leading run of capitals, keeping the last one before a lowercase letter
        // ("URLValue" => "urlValue", "ID" => "id", "Name" => "name").
        $length = strlen($name);
        $i = 0;
        while ($i < $length && ctype_upper($name[$i])) {
            if ($i > 0 && $i + 1 < $length && !ctype_upper($name[$i + 1])) {
                break;
            }
            $i++;
        }

        return strtolower(substr($name, 0, max($i, 1))) . substr($name, max($i, 1));
    }
}
