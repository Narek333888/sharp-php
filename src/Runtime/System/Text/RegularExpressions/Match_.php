<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\RegularExpressions;

/**
 * System.Text.RegularExpressions.Match: a match of a Regex (Success false when there was none).
 *
 *   var m = Regex.Match("x=42", @"x=(\d+)");
 *   if (m.Success) Console.WriteLine(m.Groups[1].Value);   // 42
 */
final class Match_ extends Group
{
    /**
     * @internal
     */
    public function __construct(
        bool $success,
        string $value,
        int $index,
        public readonly GroupCollection $Groups,
        private readonly ?Regex $regex = null,
        private readonly string $input = '',
        /** Where to search for the next match, in bytes. */
        private readonly int $next = 0,
    ) {
        parent::__construct($success, $value, $index, '0');
    }

    /** A failed match. */
    public static function failed(): self
    {
        return new self(false, '', 0, new GroupCollection([]));
    }

    /** The match after this one in the same input (Success false at the end). */
    public function NextMatch(): self
    {
        return $this->Success && $this->regex !== null ? $this->regex->matchAt($this->input, $this->next) : self::failed();
    }
}
