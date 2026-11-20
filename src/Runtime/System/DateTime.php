<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.DateTime: a date and a time of day, immutable as in .NET.
 *
 *   var deadline = new DateTime(2025, 12, 31, 18, 0, 0);
 *   TimeSpan left = deadline - DateTime.Now;
 *   Console.WriteLine($"{deadline:dddd, MMMM d} at {deadline:HH:mm}");
 *
 * Kept as .NET keeps it: ticks of 100 ns since 0001-01-01 00:00 on the date's own clock, and a Kind
 * (Local, Utc or Unspecified). Local times are those of PHP's default time zone
 * (date_default_timezone_get()). The calendar is the proleptic Gregorian one, years 1 to 9999.
 * Comparisons, as in .NET, look at the ticks only, not at the Kind.
 *
 * Formatting and parsing follow .NET's invariant/en-US conventions ("M/d/yyyy h:mm:ss tt"), as
 * SharpPHP has no cultures. a + span, a - b, a - span and the comparisons are the @sharp-operator
 * methods below.
 *
 * A struct in .NET, so DateTime? has HasValue, Value and GetValueOrDefault():
 *
 * @sharp-struct
 */
final class DateTime implements \JsonSerializable
{
    public static DateTime $MinValue;
    public static DateTime $MaxValue;
    public static DateTime $UnixEpoch;

    private const int MAX_TICKS = 3_155_378_975_999_999_999;

    /** Days from 0001-01-01 to the Unix epoch, 1970-01-01. */
    private const int EPOCH_DAYS = 719_162;

    private const array MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const array DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** Ticks of 100 ns since 0001-01-01 00:00 of the date's own clock. */
    public readonly int $Ticks;

    public readonly DateTimeKind $Kind;

    public int $Year {
        get => $this->civil()[0];
    }

    public int $Month {
        get => $this->civil()[1];
    }

    public int $Day {
        get => $this->civil()[2];
    }

    public int $Hour {
        get => intdiv($this->timeTicks(), TimeSpan::TicksPerHour);
    }

    public int $Minute {
        get => intdiv($this->timeTicks(), TimeSpan::TicksPerMinute) % 60;
    }

    public int $Second {
        get => intdiv($this->timeTicks(), TimeSpan::TicksPerSecond) % 60;
    }

    public int $Millisecond {
        get => intdiv($this->timeTicks(), TimeSpan::TicksPerMillisecond) % 1000;
    }

    public int $Microsecond {
        get => intdiv($this->timeTicks(), 10) % 1000;
    }

    public DayOfWeek $DayOfWeek {
        get => DayOfWeek::from((intdiv($this->Ticks, TimeSpan::TicksPerDay) + 1) % 7); // 0001-01-01 was a Monday
    }

    public int $DayOfYear {
        get => intdiv($this->Ticks, TimeSpan::TicksPerDay) - self::daysFromCivil($this->Year, 1, 1) + 1;
    }

    /** The same day at 00:00. */
    public DateTime $Date {
        get => new self($this->Ticks - $this->timeTicks(), $this->Kind);
    }

    /** The time elapsed since 00:00. */
    public TimeSpan $TimeOfDay {
        get => new TimeSpan($this->timeTicks());
    }

    /** @var array{0: int, 1: int, 2: int}|null year, month and day, worked out once */
    private ?array $civil = null;

    /**
     * As .NET's constructors: (), (ticks), (ticks, kind), (year, month, day),
     * (year, month, day, hour, minute, second[, kind]) and (year, month, day, hour, minute, second, millisecond[, kind]).
     */
    public function __construct(int $year = 1, int|DateTimeKind $month = 1, int $day = 1, int $hour = 0, int $minute = 0, int $second = 0, int|DateTimeKind $millisecond = 0, DateTimeKind $kind = DateTimeKind::Unspecified)
    {
        $count = func_num_args();
        if ($count <= 1 || $month instanceof DateTimeKind) {
            $ticks = $count === 0 ? 0 : $year;
            if ($ticks < 0 || $ticks > self::MAX_TICKS) {
                throw new ArgumentOutOfRangeException('ticks', 'Ticks must be between DateTime.MinValue.Ticks and DateTime.MaxValue.Ticks.');
            }
            $this->Ticks = $ticks;
            $this->Kind = $month instanceof DateTimeKind ? $month : DateTimeKind::Unspecified;

            return;
        }
        if ($millisecond instanceof DateTimeKind) {
            [$kind, $millisecond] = [$millisecond, 0];
        }
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12 || $day < 1 || $day > self::DaysInMonth($year, $month)) {
            throw new ArgumentOutOfRangeException(null, 'Year, Month, and Day parameters describe an un-representable DateTime.');
        }
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new ArgumentOutOfRangeException(null, 'Hour, Minute, and Second parameters describe an un-representable DateTime.');
        }
        if ($millisecond < 0 || $millisecond > 999) {
            throw new ArgumentOutOfRangeException('millisecond', 'Valid values are between 0 and 999, inclusive.');
        }
        $this->Ticks = self::daysFromCivil($year, $month, $day) * TimeSpan::TicksPerDay + $hour * TimeSpan::TicksPerHour
            + $minute * TimeSpan::TicksPerMinute + $second * TimeSpan::TicksPerSecond + $millisecond * TimeSpan::TicksPerMillisecond;
        $this->Kind = $kind;
    }

    // ---- now ------------------------------------------------------------

    /** DateTime.Now: the local date and time (PHP's default time zone). */
    public static function get_Now(): self
    {
        return self::fromPhp(new \DateTimeImmutable('now', self::localZone()), DateTimeKind::Local);
    }

    /** DateTime.UtcNow */
    public static function get_UtcNow(): self
    {
        return self::fromPhp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), DateTimeKind::Utc);
    }

    /** DateTime.Today: the local date at 00:00. */
    public static function get_Today(): self
    {
        return self::get_Now()->Date;
    }

    // ---- arithmetic -----------------------------------------------------

    public function Add(TimeSpan $value): self
    {
        return $this->AddTicks($value->Ticks);
    }

    public function AddTicks(int $value): self
    {
        // Checked before adding, so that the sum cannot overflow.
        if ($value > self::MAX_TICKS - $this->Ticks || $value < -$this->Ticks) {
            throw new ArgumentOutOfRangeException('value', 'The added or subtracted value results in an un-representable DateTime.');
        }

        return new self($this->Ticks + $value, $this->Kind);
    }

    public function AddMilliseconds(float $value): self
    {
        return $this->addUnits($value, TimeSpan::TicksPerMillisecond);
    }

    public function AddSeconds(float $value): self
    {
        return $this->addUnits($value, TimeSpan::TicksPerSecond);
    }

    public function AddMinutes(float $value): self
    {
        return $this->addUnits($value, TimeSpan::TicksPerMinute);
    }

    public function AddHours(float $value): self
    {
        return $this->addUnits($value, TimeSpan::TicksPerHour);
    }

    public function AddDays(float $value): self
    {
        return $this->addUnits($value, TimeSpan::TicksPerDay);
    }

    /** Months later (or earlier); the day is kept when the month has it, else the month's last day is taken. */
    public function AddMonths(int $months): self
    {
        [$year, $month, $day] = $this->civil();
        $total = $year * 12 + ($month - 1) + $months;
        $newYear = intdiv($total, 12);
        $newMonth = $total % 12 + 1;
        if ($total < 12 || $newYear > 9999) {
            throw new ArgumentOutOfRangeException('months', 'The added or subtracted value results in an un-representable DateTime.');
        }
        $newDay = min($day, self::DaysInMonth($newYear, $newMonth));

        return new self(self::daysFromCivil($newYear, $newMonth, $newDay) * TimeSpan::TicksPerDay + $this->timeTicks(), $this->Kind);
    }

    public function AddYears(int $value): self
    {
        return $this->AddMonths($value * 12);
    }

    /** date.Subtract(other) is the TimeSpan between them; date.Subtract(span) the date that much earlier. */
    public function Subtract(DateTime|TimeSpan $value): DateTime|TimeSpan
    {
        return $value instanceof self ? new TimeSpan($this->Ticks - $value->Ticks) : $this->AddTicks(-$value->Ticks);
    }

    /**
     * date + span
     *
     * @internal
     * @sharp-operator +
     */
    public static function op_Addition(DateTime $d, TimeSpan $t): DateTime
    {
        return $d->Add($t);
    }

    /**
     * later - earlier: the TimeSpan between them
     *
     * @internal
     * @sharp-operator -
     */
    public static function op_Subtraction(DateTime $d1, DateTime $d2): TimeSpan
    {
        return new TimeSpan($d1->Ticks - $d2->Ticks);
    }

    /**
     * date - span
     *
     * @internal
     * @sharp-operator -
     */
    public static function op_SubtractTimeSpan(DateTime $d, TimeSpan $t): DateTime
    {
        return $d->AddTicks(-$t->Ticks);
    }

    // ---- comparison -----------------------------------------------------

    /**
     * DateTime.Compare(t1, t2): negative, zero or positive; also what t1 < t2, t1 == t2, ... compile to.
     *
     * @sharp-operator compare
     */
    public static function Compare(DateTime $t1, DateTime $t2): int
    {
        return $t1->Ticks <=> $t2->Ticks;
    }

    public function CompareTo(mixed $value): int
    {
        if ($value === null) {
            return 1;
        }
        if (!$value instanceof self) {
            throw new ArgumentException('Object must be of type DateTime.');
        }

        return $this->Ticks <=> $value->Ticks;
    }

    public function Equals(mixed $value): bool
    {
        return $value instanceof self && $value->Ticks === $this->Ticks;
    }

    public function GetHashCode(): int
    {
        return $this->Ticks ^ ($this->Ticks >> 32);
    }

    // ---- calendar -------------------------------------------------------

    public static function DaysInMonth(int $year, int $month): int
    {
        if ($month < 1 || $month > 12) {
            throw new ArgumentOutOfRangeException('month', 'Month must be between one and twelve.');
        }

        return $month === 2 ? (self::IsLeapYear($year) ? 29 : 28) : [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month - 1];
    }

    public static function IsLeapYear(int $year): bool
    {
        if ($year < 1 || $year > 9999) {
            throw new ArgumentOutOfRangeException('year', 'Year must be between 1 and 9999.');
        }

        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }

    /** The same date and time, with another Kind. */
    public static function SpecifyKind(DateTime $value, DateTimeKind $kind): self
    {
        return new self($value->Ticks, $kind);
    }

    /** The UTC time; a local or unspecified one is taken as local. */
    public function ToUniversalTime(): self
    {
        if ($this->Kind === DateTimeKind::Utc) {
            return $this;
        }
        $local = new \DateTimeImmutable($this->wallClock(), self::localZone());

        return self::fromPhp($local->setTimezone(new \DateTimeZone('UTC')), DateTimeKind::Utc);
    }

    /** The local time; a UTC or unspecified one is taken as UTC. */
    public function ToLocalTime(): self
    {
        if ($this->Kind === DateTimeKind::Local) {
            return $this;
        }
        $utc = new \DateTimeImmutable($this->wallClock(), new \DateTimeZone('UTC'));

        return self::fromPhp($utc->setTimezone(self::localZone()), DateTimeKind::Local);
    }

    // ---- text -----------------------------------------------------------

    /**
     * "M/d/yyyy h:mm:ss tt" by default; a standard format (d, D, f, F, g, G, M, o, R, s, t, T, u, U, Y)
     * or a custom one (yyyy, MM, MMM, dd, ddd, HH, hh, mm, ss, fff, tt, zzz, K, 'literal', \c).
     */
    public function ToString(?string $format = null, mixed $provider = null): string
    {
        if ($format === null || $format === '') {
            $format = 'G';
        }
        if (strlen($format) === 1) {
            [$pattern, $utc] = self::standardFormat($format);

            return ($utc ? $this->ToUniversalTime() : $this)->formatCustom($pattern);
        }

        return $this->formatCustom($format);
    }

    public function ToShortDateString(): string
    {
        return $this->ToString('d');
    }

    public function ToLongDateString(): string
    {
        return $this->ToString('D');
    }

    public function ToShortTimeString(): string
    {
        return $this->ToString('t');
    }

    public function ToLongTimeString(): string
    {
        return $this->ToString('T');
    }

    /**
     * DateTime.Parse("2024-01-15"), ("2024-01-15T10:30:00"), ("1/15/2024 10:30 AM"), ("January 15, 2024")...
     * A time with an offset or "Z" is converted to local time, as in .NET.
     */
    public static function Parse(string $s, mixed $provider = null): self
    {
        return self::tryParseText($s) ?? throw new FormatException("String '$s' was not recognized as a valid DateTime.");
    }

    /** @param-out DateTime $result DateTime.MinValue when the text is not a date */
    public static function TryParse(?string $s, ?DateTime &$result): bool
    {
        $parsed = $s === null ? null : self::tryParseText($s);
        $result = $parsed ?? self::$MinValue;

        return $parsed !== null;
    }

    /** DateTime.ParseExact("15.01.2024", "dd.MM.yyyy", null) */
    public static function ParseExact(string $s, string $format, mixed $provider = null): self
    {
        $pattern = self::phpPattern($format);
        $parsed = \DateTimeImmutable::createFromFormat($pattern . '|', $s, self::localZone());
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new FormatException("String '$s' was not recognized as a valid DateTime.");
        }

        return self::fromPhp($parsed, str_contains($pattern, 'P') ? DateTimeKind::Local : DateTimeKind::Unspecified);
    }

    /** As DateTime.ToString("o") without trailing zeros, as System.Text.Json writes dates. */
    public function jsonSerialize(): string
    {
        $fraction = rtrim(sprintf('%07d', $this->Ticks % TimeSpan::TicksPerSecond), '0');

        return $this->formatCustom("yyyy'-'MM'-'dd'T'HH':'mm':'ss") . ($fraction !== '' ? '.' . $fraction : '') . $this->formatToken('K');
    }

    // ---- internals ------------------------------------------------------

    /**
     * A standard format letter as a custom pattern, and whether the date is converted to UTC first.
     *
     * @return array{0: string, 1: bool}
     */
    private static function standardFormat(string $letter): array
    {
        return match ($letter) {
            'd' => ['M/d/yyyy', false],
            'D' => ['dddd, MMMM d, yyyy', false],
            'f' => ['dddd, MMMM d, yyyy h:mm tt', false],
            'F' => ['dddd, MMMM d, yyyy h:mm:ss tt', false],
            'g' => ['M/d/yyyy h:mm tt', false],
            'G' => ['M/d/yyyy h:mm:ss tt', false],
            'M', 'm' => ['MMMM d', false],
            'O', 'o' => ["yyyy'-'MM'-'dd'T'HH':'mm':'ss'.'fffffffK", false],
            'R', 'r' => ["ddd, dd MMM yyyy HH':'mm':'ss 'GMT'", false],
            's' => ["yyyy'-'MM'-'dd'T'HH':'mm':'ss", false],
            't' => ['h:mm tt', false],
            'T' => ['h:mm:ss tt', false],
            'u' => ["yyyy'-'MM'-'dd HH':'mm':'ss'Z'", false],
            'U' => ['dddd, MMMM d, yyyy h:mm:ss tt', true],
            'Y', 'y' => ['MMMM yyyy', false],
            default => throw new FormatException('Input string was not in a correct format.'),
        };
    }

    private function formatCustom(string $format): string
    {
        $out = '';
        foreach (self::tokens($format) as $token) {
            $text = $this->formatToken($token);
            // An F fraction that is all zeros is left out, with the "." before it.
            if ($token[0] === 'F' && $text === '' && str_ends_with($out, '.')) {
                $out = substr($out, 0, -1);
            }
            $out .= $text;
        }

        return $out;
    }

    /** @return list<string> the specifiers and literals of a custom format */
    private static function tokens(string $format): array
    {
        preg_match_all("/y{1,5}|M{1,4}|d{1,4}|H{1,2}|h{1,2}|m{1,2}|s{1,2}|f{1,7}|F{1,7}|t{1,2}|z{1,3}|K|g{1,2}|'[^']*'|\"[^\"]*\"|\\\\.|%.|./su", $format, $m);

        return $m[0];
    }

    private function formatToken(string $token): string
    {
        [$year, $month, $day] = $this->civil();
        $hour = $this->Hour;
        $fraction = sprintf('%07d', $this->Ticks % TimeSpan::TicksPerSecond);

        return match (true) {
            $token === 'y' => (string) ($year % 100),
            $token === 'yy' => sprintf('%02d', $year % 100),
            $token[0] === 'y' => str_pad((string) $year, strlen($token), '0', STR_PAD_LEFT),
            $token === 'M' => (string) $month,
            $token === 'MM' => sprintf('%02d', $month),
            $token === 'MMM' => substr(self::MONTH_NAMES[$month - 1], 0, 3),
            $token === 'MMMM' => self::MONTH_NAMES[$month - 1],
            $token === 'd' => (string) $day,
            $token === 'dd' => sprintf('%02d', $day),
            $token === 'ddd' => substr(self::DAY_NAMES[$this->DayOfWeek->value], 0, 3),
            $token === 'dddd' => self::DAY_NAMES[$this->DayOfWeek->value],
            $token === 'h' => (string) ($hour % 12 ?: 12),
            $token === 'hh' => sprintf('%02d', $hour % 12 ?: 12),
            $token === 'H' => (string) $hour,
            $token === 'HH' => sprintf('%02d', $hour),
            $token === 'm' => (string) $this->Minute,
            $token === 'mm' => sprintf('%02d', $this->Minute),
            $token === 's' => (string) $this->Second,
            $token === 'ss' => sprintf('%02d', $this->Second),
            $token[0] === 'f' => substr($fraction, 0, strlen($token)),
            $token[0] === 'F' => rtrim(substr($fraction, 0, strlen($token)), '0'),
            $token === 't' => $hour < 12 ? 'A' : 'P',
            $token === 'tt' => $hour < 12 ? 'AM' : 'PM',
            $token[0] === 'z' => $this->offset(strlen($token)),
            $token === 'K' => match ($this->Kind) {
                DateTimeKind::Utc => 'Z',
                DateTimeKind::Local => $this->offset(3),
                DateTimeKind::Unspecified => '',
            },
            $token[0] === 'g' => 'A.D.',
            $token[0] === "'" || $token[0] === '"' => substr($token, 1, -1),
            $token[0] === '\\' => substr($token, 1),
            $token[0] === '%' => $this->formatToken(substr($token, 1)),
            default => $token,
        };
    }

    /** The UTC offset: "+2" (z), "+02" (zz) or "+02:00" (zzz); UTC dates have none. */
    private function offset(int $width): string
    {
        $seconds = $this->Kind === DateTimeKind::Utc ? 0 : (new \DateTimeImmutable($this->wallClock(), self::localZone()))->getOffset();
        $sign = $seconds < 0 ? '-' : '+';
        $hours = intdiv(abs($seconds), 3600);
        $minutes = intdiv(abs($seconds) % 3600, 60);

        return match ($width) {
            1 => $sign . $hours,
            2 => $sign . sprintf('%02d', $hours),
            default => $sign . sprintf('%02d:%02d', $hours, $minutes),
        };
    }

    /** A .NET format as a pattern for \DateTimeImmutable::createFromFormat(). */
    private static function phpPattern(string $format): string
    {
        if (strlen($format) === 1) {
            $format = self::standardFormat($format)[0];
        }
        $map = [
            'yyyy' => 'Y', 'yy' => 'y', 'MMMM' => 'F', 'MMM' => 'M', 'MM' => 'm', 'M' => 'n', 'dddd' => 'l', 'ddd' => 'D',
            'dd' => 'd', 'd' => 'j', 'HH' => 'H', 'H' => 'G', 'hh' => 'h', 'h' => 'g', 'mm' => 'i', 'm' => 'i',
            'ss' => 's', 's' => 's', 'fff' => 'v', 'ffffff' => 'u', 'tt' => 'A', 'zzz' => 'P', 'K' => 'P',
        ];
        $pattern = '';
        foreach (self::tokens($format) as $token) {
            $literal = match (true) {
                $token[0] === "'" || $token[0] === '"' => substr($token, 1, -1),
                $token[0] === '\\' => substr($token, 1),
                isset($map[$token]) => null,
                preg_match('/^[yMdHhmsfFtzKg]/', $token) === 1 => throw new FormatException("The format specifier '$token' cannot be parsed by ParseExact in SharpPHP."),
                default => $token,
            };
            // A literal goes through escaped, character by character.
            $pattern .= $literal === null ? $map[$token] : (string) preg_replace('/./su', '\\\\$0', $literal);
        }

        return $pattern;
    }

    private static function tryParseText(string $s): ?self
    {
        $s = trim($s);
        $info = date_parse($s);
        // PHP also reads relative dates ("tomorrow", "+1 week"), which .NET does not.
        if ($s === '' || $info['error_count'] > 0 || $info['warning_count'] > 0 || isset($info['relative'])) {
            return null;
        }
        try {
            $parsed = new \DateTimeImmutable($s, self::localZone());

            return $info['is_localtime']
                ? self::fromPhp($parsed->setTimezone(self::localZone()), DateTimeKind::Local)
                : self::fromPhp($parsed, DateTimeKind::Unspecified);
        } catch (\Exception) {
            return null;
        }
    }

    /** The date and time a PHP date shows, with the given Kind. */
    private static function fromPhp(\DateTimeInterface $date, DateTimeKind $kind): self
    {
        [$year, $month, $day, $hour, $minute, $second, $micro] = array_map('intval', explode(' ', $date->format('Y n j G i s u')));
        if ($year < 1 || $year > 9999) {
            throw new ArgumentOutOfRangeException(null, 'Year, Month, and Day parameters describe an un-representable DateTime.');
        }

        return new self(
            self::daysFromCivil($year, $month, $day) * TimeSpan::TicksPerDay + $hour * TimeSpan::TicksPerHour
                + $minute * TimeSpan::TicksPerMinute + $second * TimeSpan::TicksPerSecond + $micro * 10,
            $kind,
        );
    }

    /** "2024-01-15 10:30:00.000000": the date's clock, for PHP's date functions. */
    private function wallClock(): string
    {
        [$year, $month, $day] = $this->civil();

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d.%06d', $year, $month, $day, $this->Hour, $this->Minute, $this->Second, intdiv($this->Ticks % TimeSpan::TicksPerSecond, 10));
    }

    private function addUnits(float $value, int $ticksPerUnit): self
    {
        $ticks = round($value * $ticksPerUnit);
        if (is_nan($ticks) || abs($ticks) > self::MAX_TICKS) {
            throw new ArgumentOutOfRangeException('value', 'The added or subtracted value results in an un-representable DateTime.');
        }

        return $this->AddTicks((int) $ticks);
    }

    private function timeTicks(): int
    {
        return $this->Ticks % TimeSpan::TicksPerDay;
    }

    /** @return array{0: int, 1: int, 2: int} year, month and day */
    private function civil(): array
    {
        return $this->civil ??= self::civilFromDays(intdiv($this->Ticks, TimeSpan::TicksPerDay));
    }

    private static function localZone(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }

    /** Days from 0001-01-01 to a date of the proleptic Gregorian calendar (H. Hinnant's days_from_civil). */
    private static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146097 + $dayOfEra - 719468 + self::EPOCH_DAYS;
    }

    /**
     * The date that many days after 0001-01-01 (H. Hinnant's civil_from_days).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function civilFromDays(int $days): array
    {
        $z = $days - self::EPOCH_DAYS + 719468;
        $era = intdiv($z, 146097);
        $dayOfEra = $z - $era * 146097;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096), 365);
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $mp = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp + 3 : $mp - 9;

        return [$yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }
}

DateTime::$MinValue = new DateTime(0);
DateTime::$MaxValue = new DateTime(3_155_378_975_999_999_999);
DateTime::$UnixEpoch = new DateTime(1970, 1, 1, 0, 0, 0, DateTimeKind::Utc);
