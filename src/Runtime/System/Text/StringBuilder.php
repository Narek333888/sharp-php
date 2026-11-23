<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\System\String_;

/**
 * System.Text.StringBuilder: text built up piece by piece. Append and the other changing methods return
 * the builder itself, so that calls chain:
 *
 *   var sb = new StringBuilder();
 *   sb.Append("Total: ").Append(total).AppendLine();
 *
 * Positions and lengths count characters (not bytes), as everywhere in SharpPHP.
 *
 * @implements \ArrayAccess<int, string>
 */
final class StringBuilder implements \ArrayAccess
{
    private string $text;

    /** The number of characters; setting it cuts the text, or pads it with "\0" characters. */
    public int $Length {
        get => mb_strlen($this->text);
        set {
            if ($value < 0) {
                throw new ArgumentOutOfRangeException('value', 'Length cannot be less than zero.');
            }
            $length = mb_strlen($this->text);
            $this->text = $value <= $length ? mb_substr($this->text, 0, $value) : $this->text . str_repeat("\0", $value - $length);
        }
    }

    /**
     * The room reserved for characters, never less than Length. PHP strings grow by themselves, so it is
     * only remembered, as .NET reports it.
     */
    public int $Capacity {
        get => max($this->Capacity, $this->Length);
        set {
            if ($value < 0) {
                throw new ArgumentOutOfRangeException('value', 'Capacity cannot be less than zero.');
            }
            $this->Capacity = $value;
        }
    }

    /** new StringBuilder(), new StringBuilder("start"), new StringBuilder(capacity) or new StringBuilder("start", capacity). */
    public function __construct(string|int|null $value = null, int $capacity = 16)
    {
        $this->text = is_string($value) ? $value : '';
        $this->Capacity = is_int($value) ? $value : $capacity;
    }

    /**
     * Appends a value as text: a number, a bool ("True"), a char, an object's ToString().
     *
     * @sharp-typed-numbers $value
     */
    public function Append(mixed $value): self
    {
        $this->text .= Op::str($value);

        return $this;
    }

    /**
     * Appends the value, if any, and a line break (Environment.NewLine).
     *
     * @sharp-typed-numbers $value
     */
    public function AppendLine(mixed $value = ''): self
    {
        $this->text .= Op::str($value) . PHP_EOL;

        return $this;
    }

    /**
     * sb.AppendFormat("{0} costs {1:F2}", name, price), as string.Format.
     *
     * @sharp-typed-numbers $args
     */
    public function AppendFormat(string $format, mixed ...$args): self
    {
        $this->text .= String_::Format($format, ...$args);

        return $this;
    }

    /**
     * sb.AppendJoin(", ", names), as string.Join.
     *
     * @sharp-typed-numbers $values elements
     */
    public function AppendJoin(string $separator, mixed ...$values): self
    {
        $this->text .= String_::Join($separator, ...$values);

        return $this;
    }

    /** @sharp-typed-numbers $value */
    public function Insert(int $index, mixed $value): self
    {
        $this->checkRange($index, 0, 'index');
        $this->text = mb_substr($this->text, 0, $index) . Op::str($value) . mb_substr($this->text, $index);

        return $this;
    }

    public function Remove(int $startIndex, int $length): self
    {
        $this->checkRange($startIndex, $length, 'startIndex');
        $this->text = mb_substr($this->text, 0, $startIndex) . mb_substr($this->text, $startIndex + $length);

        return $this;
    }

    /** Replaces every occurrence of a string (or a char) with another. */
    public function Replace(string $oldValue, ?string $newValue): self
    {
        $this->text = String_::Replace($this->text, $oldValue, $newValue);

        return $this;
    }

    public function Clear(): self
    {
        $this->text = '';

        return $this;
    }

    /** The text, or the part of it from startIndex with the given length. */
    public function ToString(int $startIndex = 0, ?int $length = null): string
    {
        if ($startIndex === 0 && $length === null) {
            return $this->text;
        }
        $this->checkRange($startIndex, $length ?? 0, 'startIndex');

        return mb_substr($this->text, $startIndex, $length);
    }

    /**
     * sb[i]
     *
     * @sharp-return char
     */
    public function offsetGet(mixed $offset): string
    {
        $index = (int) $offset;
        $this->checkRange($index, 1, 'index');

        return mb_substr($this->text, $index, 1);
    }

    /** sb[i] = c */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $index = (int) $offset;
        $this->checkRange($index, 1, 'index');
        $this->text = mb_substr($this->text, 0, $index) . Op::str($value) . mb_substr($this->text, $index + 1);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset >= 0 && $offset < mb_strlen($this->text);
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('The characters of a StringBuilder cannot be unset');
    }

    /** Throws as .NET does when [index, index + count) is not inside the text. */
    private function checkRange(int $index, int $count, string $parameter): void
    {
        if ($index < 0 || $count < 0 || $index + $count > mb_strlen($this->text)) {
            throw new ArgumentOutOfRangeException($parameter, 'Index was out of range. Must be non-negative and less than the size of the collection.');
        }
    }
}
