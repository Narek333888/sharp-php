<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Guid: a 128-bit identifier, written as 32 hex digits (lowercase, as .NET does).
 *
 *   var id = Guid.NewGuid();                Guid.Parse("...")    Guid.TryParse(text, out var id)
 *   id.ToString()  // "0f8fad5b-d9cb-469f-a165-70867728950e"    id.ToString("N") (no hyphens)
 *
 * @sharp-struct
 */
final class Guid implements \JsonSerializable
{
    /** 00000000-0000-0000-0000-000000000000 */
    public static Guid $Empty;

    /** @param string $hex 32 lowercase hex digits */
    private function __construct(private readonly string $hex)
    {
    }

    /** A random (version 4) Guid. */
    public static function NewGuid(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return new self(bin2hex($bytes));
    }

    /** "D" (with hyphens), "N" (digits only), "B" ({...}) or "P" ((...)); FormatException otherwise. */
    public static function Parse(string $input): self
    {
        return self::read($input) ?? throw new FormatException('Unrecognized Guid format.');
    }

    /** @param-out Guid $result Guid.Empty when the text is not a Guid */
    public static function TryParse(?string $input, mixed &$result): bool
    {
        $parsed = $input === null ? null : self::read($input);
        $result = $parsed ?? self::$Empty;

        return $parsed !== null;
    }

    private static function read(string $input): ?self
    {
        $text = strtolower(trim($input));
        if (preg_match('/^\{([0-9a-f-]{36})\}$|^\(([0-9a-f-]{36})\)$/', $text, $m) === 1) {
            $text = $m[1] !== '' ? $m[1] : $m[2];
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $text) === 1) {
            return new self(str_replace('-', '', $text));
        }

        return preg_match('/^[0-9a-f]{32}$/', $text) === 1 ? new self($text) : null;
    }

    /** "D" (default): 8-4-4-4-12 digits; "N": 32 digits; "B": {D}; "P": (D). */
    public function ToString(?string $format = null): string
    {
        $h = $this->hex;
        $d = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);

        return match (strtoupper($format ?? 'D')) {
            'D', '' => $d,
            'N' => $h,
            'B' => '{' . $d . '}',
            'P' => '(' . $d . ')',
            default => throw new FormatException('Format string can be only "D", "d", "N", "n", "P", "p", "B", "b", "X" or "x".'),
        };
    }

    /** id.Equals(other): true for a Guid with the same 128 bits, however each was written ("N", "B", upper case). */
    public function Equals(mixed $other): bool
    {
        return $other instanceof self && $other->hex === $this->hex;
    }

    /** From the digits, as Equals: equal Guids have the same hash code. */
    public function GetHashCode(): int
    {
        return crc32($this->hex);
    }

    /** id.CompareTo(other): -1, 0 or 1, in .NET's order (by the digits as ToString() writes them). */
    public function CompareTo(self $other): int
    {
        return self::compare($this, $other);
    }

    /**
     * What a == b, a != b, a < b ... compile to: Guids are values, equal when their digits are.
     *
     * @internal
     * @sharp-operator compare
     */
    public static function compare(Guid $a, Guid $b): int
    {
        return strcmp($a->hex, $b->hex) <=> 0;
    }

    /** The "D" form, as ToString(): what a Guid gives when PHP code uses it as a string (echo, "$id"). */
    public function __toString(): string
    {
        return $this->ToString();
    }

    /** The "D" form, as System.Text.Json writes a Guid: "0f8fad5b-d9cb-469f-a165-70867728950e". */
    public function jsonSerialize(): string
    {
        return $this->ToString();
    }
}

Guid::$Empty = (static fn (): Guid => Guid::Parse('00000000000000000000000000000000'))();
