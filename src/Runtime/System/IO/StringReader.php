<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.StringReader: a TextReader over a string (new StringReader(text).ReadLine()).
 */
class StringReader extends TextReader
{
    /** @var list<string> */
    private array $chars;

    private int $position = 0;

    public function __construct(string $s)
    {
        $this->chars = mb_str_split($s);
    }

    /** reader.Peek(): the next character's code without reading it, or -1 at the end of the string. */
    public function Peek(): int
    {
        return isset($this->chars[$this->position]) ? mb_ord($this->chars[$this->position]) : -1;
    }

    /**
     * reader.Read(): the next character's code, or -1 at the end. A character above U+FFFF is one
     * code point here, not two UTF-16 chars as in .NET.
     */
    public function Read(): int
    {
        return isset($this->chars[$this->position]) ? mb_ord($this->chars[$this->position++]) : -1;
    }

    /** reader.ReadToEnd(): the rest of the string ("" at the end). */
    public function ReadToEnd(): string
    {
        $rest = implode('', array_slice($this->chars, $this->position));
        $this->position = count($this->chars);

        return $rest;
    }
}
