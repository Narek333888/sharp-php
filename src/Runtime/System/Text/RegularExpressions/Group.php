<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\RegularExpressions;

/**
 * System.Text.RegularExpressions.Group: what one (...) group of a match captured.
 * A group that did not take part in the match has Success false and the Value "".
 */
class Group
{
    /**
     * @internal
     */
    public function __construct(
        public readonly bool $Success,
        public readonly string $Value,
        /** Where the capture starts in the input, in characters. */
        public readonly int $Index,
        public readonly string $Name,
    ) {
    }

    /** The capture's length in characters. */
    public int $Length {
        get => mb_strlen($this->Value);
    }

    /** group.ToString(): the captured text, as Value ("" when the group did not take part in the match). */
    public function ToString(): string
    {
        return $this->Value;
    }

    /** PHP's (string) group (echo, string functions): the captured text, as ToString(). */
    public function __toString(): string
    {
        return $this->Value;
    }
}
