<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * A double, float or decimal with its C# type, which the compiler knows and a PHP float does not, for
 * a runtime method that writes its argument as text: string.Format's and Console.WriteLine's
 * arguments (which "{0:F2}" formats): "{0}" of 0.1 + 0.2 is 0.30000000000000004, of 0.1m + 0.2m 0.3
 * and of 1f / 3 0.33333334, as in .NET. The compiler makes them only for the params parameters that
 * are marked "@sharp-typed-numbers" (others get the number's text); no other code sees them.
 *
 * @internal
 */
final class TypedNumber
{
    /** @param 'double'|'float'|'decimal' $type */
    public function __construct(
        public readonly int|float|null $value,
        public readonly string $type,
    ) {
    }

    /**
     * The values of a sequence as the type writes them (string.Join(", ", prices)).
     *
     * @param iterable<int|float|null> $values
     * @param 'double'|'float'|'decimal' $type
     * @return list<string>
     */
    public static function texts(iterable $values, string $type): array
    {
        $texts = [];
        foreach ($values as $value) {
            $texts[] = (new self($value, $type))->text();
        }

        return $texts;
    }

    /** The value as its type's ToString() writes it. */
    public function text(): string
    {
        $value = $this->value === null ? null : (float) $this->value;

        return match ($this->type) {
            'decimal' => Op::decimalText($value),
            'float' => Op::singleText($value),
            default => Op::doubleText($value),
        };
    }

    /** The value as its type's ToString(format) writes it. */
    public function format(string $format): string
    {
        return $this->value === null ? '' : NumberFormat::format($this->value, $format, $this->type);
    }
}
