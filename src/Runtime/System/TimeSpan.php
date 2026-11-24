<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.TimeSpan: a length of time, kept as .NET does, in ticks of 100 nanoseconds. Immutable.
 *
 *   var timeout = TimeSpan.FromSeconds(90);
 *   Console.WriteLine(timeout);                   // 00:01:30
 *   Console.WriteLine(timeout.TotalMinutes);      // 1.5
 *   TimeSpan left = deadline - DateTime.Now;      // DateTime - DateTime
 *
 * a + b, a - b, -a and the comparisons work on TimeSpans (see the @sharp-operator methods).
 *
 * A struct in .NET, so TimeSpan? has HasValue, Value and GetValueOrDefault():
 *
 * @sharp-struct
 */
final class TimeSpan implements \JsonSerializable
{
    public const int TicksPerMillisecond = 10_000;
    public const int TicksPerSecond = 10_000_000;
    public const int TicksPerMinute = 600_000_000;
    public const int TicksPerHour = 36_000_000_000;
    public const int TicksPerDay = 864_000_000_000;

    public static TimeSpan $Zero;
    public static TimeSpan $MaxValue;
    public static TimeSpan $MinValue;

    /** The length in ticks of 100 ns, negative for a negative span. */
    public readonly int $Ticks;

    public int $Days {
        get => intdiv($this->Ticks, self::TicksPerDay);
    }

    public int $Hours {
        get => intdiv($this->Ticks, self::TicksPerHour) % 24;
    }

    public int $Minutes {
        get => intdiv($this->Ticks, self::TicksPerMinute) % 60;
    }

    public int $Seconds {
        get => intdiv($this->Ticks, self::TicksPerSecond) % 60;
    }

    public int $Milliseconds {
        get => intdiv($this->Ticks, self::TicksPerMillisecond) % 1000;
    }

    public int $Microseconds {
        get => intdiv($this->Ticks, 10) % 1000;
    }

    public float $TotalDays {
        get => $this->Ticks / self::TicksPerDay;
    }

    public float $TotalHours {
        get => $this->Ticks / self::TicksPerHour;
    }

    public float $TotalMinutes {
        get => $this->Ticks / self::TicksPerMinute;
    }

    public float $TotalSeconds {
        get => $this->Ticks / self::TicksPerSecond;
    }

    public float $TotalMilliseconds {
        get => $this->Ticks / self::TicksPerMillisecond;
    }

    /**
     * As .NET's constructors, by the number of arguments: (ticks), (hours, minutes, seconds),
     * (days, hours, minutes, seconds) or (days, hours, minutes, seconds, milliseconds).
     */
    public function __construct(int $days = 0, int $hours = 0, int $minutes = 0, int $seconds = 0, int $milliseconds = 0)
    {
        $ticks = match (func_num_args()) {
            0 => 0,
            1 => $days,
            3 => self::ticksOf(0, $days, $hours, $minutes, 0),
            4 => self::ticksOf($days, $hours, $minutes, $seconds, 0),
            5 => self::ticksOf($days, $hours, $minutes, $seconds, $milliseconds),
            default => throw new ArgumentException('TimeSpan takes (ticks), (hours, minutes, seconds), (days, hours, minutes, seconds) or (days, hours, minutes, seconds, milliseconds).'),
        };
        $this->Ticks = $ticks;
    }

    /** TimeSpan.FromDays(1.5): to the nearest tick; ArgumentException for NaN, OverflowException out of range. */
    public static function FromDays(float $value): self
    {
        return self::fromUnits($value, self::TicksPerDay);
    }

    /** TimeSpan.FromHours(1.5): to the nearest tick; ArgumentException for NaN, OverflowException out of range. */
    public static function FromHours(float $value): self
    {
        return self::fromUnits($value, self::TicksPerHour);
    }

    /** TimeSpan.FromMinutes(90): to the nearest tick; ArgumentException for NaN, OverflowException out of range. */
    public static function FromMinutes(float $value): self
    {
        return self::fromUnits($value, self::TicksPerMinute);
    }

    /** TimeSpan.FromSeconds(0.5): to the nearest tick; ArgumentException for NaN, OverflowException out of range. */
    public static function FromSeconds(float $value): self
    {
        return self::fromUnits($value, self::TicksPerSecond);
    }

    /** TimeSpan.FromMilliseconds(2.5): to the nearest tick; ArgumentException for NaN, OverflowException out of range. */
    public static function FromMilliseconds(float $value): self
    {
        return self::fromUnits($value, self::TicksPerMillisecond);
    }

    /** TimeSpan.FromTicks(n): a length of n ticks of 100 ns, as new TimeSpan(n). */
    public static function FromTicks(int $value): self
    {
        return new self($value);
    }

    /** span.Add(other), also span + other: OverflowException beyond MinValue or MaxValue, as in .NET. */
    public function Add(TimeSpan $ts): self
    {
        return self::checkedTicks($this->Ticks + $ts->Ticks);
    }

    /** span.Subtract(other), also span - other: OverflowException beyond MinValue or MaxValue, as in .NET. */
    public function Subtract(TimeSpan $ts): self
    {
        return self::checkedTicks($this->Ticks - $ts->Ticks);
    }

    /** The same length, the other way round (-span in C#). MinValue has no positive counterpart, as in .NET. */
    public function Negate(): self
    {
        if ($this->Ticks === PHP_INT_MIN) {
            throw new OverflowException('Negating the minimum value of a twos complement number is invalid.');
        }

        return new self(-$this->Ticks);
    }

    /** The absolute length. MinValue has none, as in .NET. */
    public function Duration(): self
    {
        if ($this->Ticks === PHP_INT_MIN) {
            throw new OverflowException('The duration cannot be returned for TimeSpan.MinValue because the absolute value of TimeSpan.MinValue exceeds the value of TimeSpan.MaxValue.');
        }

        return new self(abs($this->Ticks));
    }

    /**
     * span.Multiply(factor): rounded to the nearest tick (half away from zero); ArgumentException for a NaN factor,
     * OverflowException out of range.
     */
    public function Multiply(float $factor): self
    {
        return self::fromUnits($this->Ticks * $factor, 1);
    }

    /** As in .NET, dividing by zero gives an infinite (or, for Zero, an undefined) length: an OverflowException. */
    public function Divide(float $divisor): self
    {
        if (is_nan($divisor)) {
            throw new ArgumentException('TimeSpan does not accept floating point Not-a-Number values.', 'divisor');
        }

        return self::checkedTicks(fdiv($this->Ticks, $divisor));
    }

    /**
     * a + b
     *
     * @internal
     * @sharp-operator +
     */
    public static function op_Addition(TimeSpan $a, TimeSpan $b): TimeSpan
    {
        return $a->Add($b);
    }

    /**
     * a - b
     *
     * @internal
     * @sharp-operator -
     */
    public static function op_Subtraction(TimeSpan $a, TimeSpan $b): TimeSpan
    {
        return $a->Subtract($b);
    }

    /**
     * -span
     *
     * @internal
     * @sharp-operator -
     */
    public static function op_UnaryNegation(TimeSpan $t): TimeSpan
    {
        return $t->Negate();
    }

    /**
     * TimeSpan.Compare(a, b): negative, zero or positive; also what a < b, a == b, ... compile to.
     *
     * @sharp-operator compare
     */
    public static function Compare(TimeSpan $t1, TimeSpan $t2): int
    {
        return $t1->Ticks <=> $t2->Ticks;
    }

    /**
     * span.CompareTo(other): -1, 0 or 1 by length; 1 for null, ArgumentException for a value that is not a TimeSpan, as
     * in .NET.
     */
    public function CompareTo(mixed $value): int
    {
        if ($value === null) {
            return 1;
        }
        if (!$value instanceof self) {
            throw new ArgumentException('Object must be of type TimeSpan.');
        }

        return $this->Ticks <=> $value->Ticks;
    }

    /** span.Equals(other): true for a TimeSpan of the same length in ticks. */
    public function Equals(mixed $obj): bool
    {
        return $obj instanceof self && $obj->Ticks === $this->Ticks;
    }

    /** From the ticks, as Equals: equal spans have the same hash code. */
    public function GetHashCode(): int
    {
        return $this->Ticks ^ ($this->Ticks >> 32);
    }

    /**
     * "c" (the default): [-][d.]hh:mm:ss[.fffffff]; "g": [-][d:]h:mm:ss[.FFFFFFF]; "G": [-]d:hh:mm:ss.fffffff;
     * or a custom format of d, hh, h, mm, m, ss, s, f.., F.. and literals ('text', \c).
     */
    public function ToString(?string $format = null): string
    {
        $sign = $this->Ticks < 0 ? '-' : '';
        // |MinValue| is one tick more than an int holds: it is shown one tick short.
        $ticks = $this->Ticks === PHP_INT_MIN ? PHP_INT_MAX : abs($this->Ticks);
        $days = intdiv($ticks, self::TicksPerDay);
        $hours = intdiv($ticks, self::TicksPerHour) % 24;
        $minutes = intdiv($ticks, self::TicksPerMinute) % 60;
        $seconds = intdiv($ticks, self::TicksPerSecond) % 60;
        $fraction = $ticks % self::TicksPerSecond;
        $fraction7 = sprintf('%07d', $fraction);

        return match ($format) {
            null, '', 'c', 't', 'T' => $sign . ($days > 0 ? $days . '.' : '') . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds)
                . ($fraction > 0 ? '.' . $fraction7 : ''),
            'g' => $sign . ($days > 0 ? $days . ':' : '') . sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
                . ($fraction > 0 ? '.' . rtrim($fraction7, '0') : ''),
            'G' => $sign . sprintf('%d:%02d:%02d:%02d.%s', $days, $hours, $minutes, $seconds, $fraction7),
            default => $this->custom($format, $days, $hours, $minutes, $seconds, $fraction7),
        };
    }

    /** TimeSpan.Parse("1.02:03:04.5"), ("02:30"), ("00:00:01.25"), ("3") for 3 days. */
    public static function Parse(string $s): self
    {
        return self::tryParseText($s) ?? throw new FormatException("String '$s' was not recognized as a valid TimeSpan.");
    }

    /** @param-out TimeSpan $result TimeSpan.Zero when the text is not a TimeSpan */
    public static function TryParse(?string $s, ?TimeSpan &$result): bool
    {
        $parsed = $s === null ? null : self::tryParseText($s);
        $result = $parsed ?? self::$Zero;

        return $parsed !== null;
    }

    /** "00:01:30" in JSON, as System.Text.Json writes TimeSpans. */
    public function jsonSerialize(): string
    {
        return $this->ToString();
    }

    private static function tryParseText(string $s): ?self
    {
        if (preg_match('/^\s*(-)?(\d+)\s*$/', $s, $m) === 1) {
            return new self(($m[1] === '-' ? -1 : 1) * (int) $m[2] * self::TicksPerDay);
        }
        if (preg_match('/^\s*(-)?(?:(\d+)\.)?(\d{1,2}):(\d{1,2})(?::(\d{1,2})(?:\.(\d{1,7}))?)?\s*$/', $s, $m) !== 1) {
            return null;
        }
        [$hours, $minutes, $seconds] = [(int) $m[3], (int) $m[4], (int) ($m[5] ?? 0)];
        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return null;
        }
        $ticks = self::ticksOf((int) $m[2], $hours, $minutes, $seconds, 0) + (int) str_pad($m[6] ?? '', 7, '0');

        return new self(($m[1] === '-' ? -1 : 1) * $ticks);
    }

    /** A custom format string; the parts are those of the absolute length, the sign is not written. */
    private function custom(string $format, int $days, int $hours, int $minutes, int $seconds, string $fraction7): string
    {
        $out = '';
        preg_match_all("/d{1,8}|hh?|mm?|ss?|f{1,7}|F{1,7}|'[^']*'|\"[^\"]*\"|\\\\.|%.|./su", $format, $tokens);
        foreach ($tokens[0] as $token) {
            $out .= match (true) {
                $token[0] === 'd' => str_pad((string) $days, strlen($token), '0', STR_PAD_LEFT),
                $token === 'hh' => sprintf('%02d', $hours),
                $token === 'h' => (string) $hours,
                $token === 'mm' => sprintf('%02d', $minutes),
                $token === 'm' => (string) $minutes,
                $token === 'ss' => sprintf('%02d', $seconds),
                $token === 's' => (string) $seconds,
                $token[0] === 'f' => substr($fraction7, 0, strlen($token)),
                $token[0] === 'F' => rtrim(substr($fraction7, 0, strlen($token)), '0'),
                $token[0] === "'" || $token[0] === '"' => substr($token, 1, -1),
                $token[0] === '\\' => substr($token, 1),
                $token[0] === '%' => $this->custom(substr($token, 1), $days, $hours, $minutes, $seconds, $fraction7),
                default => $token,
            };
        }

        return $out;
    }

    private static function ticksOf(int $days, int $hours, int $minutes, int $seconds, int $milliseconds): int
    {
        return $days * self::TicksPerDay + $hours * self::TicksPerHour + $minutes * self::TicksPerMinute
            + $seconds * self::TicksPerSecond + $milliseconds * self::TicksPerMillisecond;
    }

    /** $value units of $ticksPerUnit ticks, rounded to the nearest tick. */
    private static function fromUnits(float $value, int $ticksPerUnit): self
    {
        if (is_nan($value)) {
            throw new ArgumentException('TimeSpan does not accept floating point Not-a-Number values.');
        }

        return self::checkedTicks($value * $ticksPerUnit);
    }

    /** Ticks as a TimeSpan; a sum or product beyond the range of a long (PHP makes it a float) overflows. */
    private static function checkedTicks(int|float $ticks): self
    {
        if (is_float($ticks)) {
            $ticks = round($ticks);
            // 2^63 is the first float out of range: PHP_INT_MAX is not exact as a float.
            if (is_nan($ticks) || $ticks >= 9.2233720368547758E18 || $ticks < -9.2233720368547758E18) {
                throw new OverflowException('TimeSpan overflowed because the duration is too long.');
            }
        }

        return new self((int) $ticks);
    }
}

TimeSpan::$Zero = new TimeSpan(0);
TimeSpan::$MaxValue = new TimeSpan(PHP_INT_MAX);
TimeSpan::$MinValue = new TimeSpan(PHP_INT_MIN);
