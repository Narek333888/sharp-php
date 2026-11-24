<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Net;

/**
 * System.Net.WebUtility: encoding text for HTML pages and URLs.
 *
 *   Results.Html($"<p>Hello, {WebUtility.HtmlEncode(name)}</p>");
 */
final class WebUtility
{
    /** Makes text safe inside HTML: < > & " ' become entities. */
    public static function HtmlEncode(?string $value): ?string
    {
        return $value === null ? null : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * WebUtility.HtmlDecode(text): turns entities back into characters (&lt; &amp; &quot; &#39;
     * &#x27; and the other HTML5 named ones); null for null.
     */
    public static function HtmlDecode(?string $value): ?string
    {
        return $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Encodes text for a URL query value (spaces become "+"). */
    public static function UrlEncode(?string $value): ?string
    {
        return $value === null ? null : urlencode($value);
    }

    /**
     * WebUtility.UrlDecode(text): decodes a URL query value: "+" becomes a space and each %XX the
     * byte it stands for (UTF-8 text); null for null.
     */
    public static function UrlDecode(?string $value): ?string
    {
        return $value === null ? null : urldecode($value);
    }
}
