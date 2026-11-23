<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\RegularExpressions;

/**
 * System.Text.RegularExpressions.RegexOptions. To combine options, combine their numbers:
 * (int)RegexOptions.IgnoreCase | (int)RegexOptions.Multiline (SharpPHP's enums have no combined values).
 */
enum RegexOptions: int
{
    case None = 0;
    case IgnoreCase = 1;
    /** ^ and $ match at the start and end of every line. */
    case Multiline = 2;
    /** (...) groups do not capture; only named (?<name>...) groups do. */
    case ExplicitCapture = 4;
    /** Accepted and ignored: PHP caches compiled patterns anyway. */
    case Compiled = 8;
    /** . matches \n too. */
    case Singleline = 16;
    /** Whitespace in the pattern is ignored and # starts a comment. */
    case IgnorePatternWhitespace = 32;
    case CultureInvariant = 512;
}
