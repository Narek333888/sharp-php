<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\System\DateTime;
use SharpPhp\Runtime\System\DateTimeKind;
use SharpPhp\Runtime\System\DayOfWeek;
use SharpPhp\Runtime\System\FormatException;
use SharpPhp\Runtime\System\OverflowException;
use SharpPhp\Runtime\System\TimeSpan;

/**
 * System.DateTime and System.TimeSpan of the runtime library, against what .NET gives. The local time
 * zone is fixed to Europe/Berlin (UTC+1, UTC+2 in summer) so that conversions are deterministic.
 */
final class DateTimeTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testCalendarMatchesPhpOverTheWholeRange(): void
    {
        $base = new \DateTimeImmutable('0001-01-01', new \DateTimeZone('UTC'));
        for ($days = 0; $days <= 3_652_058; $days += 997) {
            $date = new DateTime($days * TimeSpan::TicksPerDay);
            $php = $base->modify("+$days days");
            $this->assertSame($php->format('Y-m-d'), $date->ToString('yyyy-MM-dd'), "day $days");
            $this->assertSame((int) $php->format('w'), $date->DayOfWeek->value, "day $days");
            $this->assertSame((int) $php->format('z') + 1, $date->DayOfYear, "day $days");
        }
        $this->assertSame('9999-12-31T23:59:59.9999999', DateTime::$MaxValue->ToString('o'));
        $this->assertSame(DayOfWeek::Monday, DateTime::$MinValue->DayOfWeek);
    }

    public function testStandardAndCustomFormats(): void
    {
        $d = new DateTime(2024, 2, 29, 14, 5, 9, 250);
        $this->assertSame('2/29/2024 2:05:09 PM', $d->ToString());
        $this->assertSame('Thursday, February 29, 2024 2:05:09 PM', $d->ToString('F'));
        $this->assertSame('Thu, 29 Feb 2024 14:05:09 GMT', $d->ToString('R'));
        $this->assertSame('2024-02-29 14:05:09Z', $d->ToString('u'));
        $this->assertSame('February 29', $d->ToString('M'));
        $this->assertSame('February 2024', $d->ToString('Y'));
        $this->assertSame('14:05:09.25 | 2:05 P | Day 29 | d=29 | 29', $d->ToString("HH:mm:ss.FFF | h:mm t | 'Day' d | \\d=d | %d"));
        $this->assertSame('14:05', $d->AddMilliseconds(-250)->ToString('HH:mm.FFF'), 'an all-zero F fraction drops its "."');
        $this->assertSame('2024-02-29', Op::format($d, 'yyyy-MM-dd'));
        $this->expectException(FormatException::class);
        $d->ToString('x');
    }

    public function testKindsAndTimeZones(): void
    {
        $local = new DateTime(2024, 7, 1, 12, 0, 0, DateTimeKind::Local);
        $utc = $local->ToUniversalTime();
        $this->assertSame([DateTimeKind::Utc, '2024-07-01T10:00:00.0000000Z'], [$utc->Kind, $utc->ToString('o')]);
        $this->assertSame('2024-07-01T12:00:00.0000000+02:00', $local->ToString('o'));
        $this->assertSame('+02:00 +02 +2', $local->ToString('zzz zz z'));
        $this->assertSame('Monday, July 1, 2024 10:00:00 AM', $local->ToString('U'));
        $this->assertSame('2024-01-15T13:00:00', (new DateTime(2024, 1, 15, 12, 0, 0, DateTimeKind::Utc))->ToLocalTime()->ToString('s'));
        $this->assertSame('', (new DateTime(2024, 1, 1))->ToString('%K'), 'a lone K is not a standard format: %K');
        $this->assertSame(DateTimeKind::Utc, DateTime::SpecifyKind($local, DateTimeKind::Utc)->Kind);
        $this->assertTrue($local->Equals(DateTime::SpecifyKind($local, DateTimeKind::Utc)), 'equality compares the ticks only');
        $this->assertSame('"2024-01-15T09:30:00.25Z"', json_encode(new DateTime(2024, 1, 15, 9, 30, 0, 250, DateTimeKind::Utc)));
    }

    public function testParsing(): void
    {
        $zulu = DateTime::Parse('2024-07-01T10:00:00Z');
        $this->assertSame([DateTimeKind::Local, '2024-07-01T12:00:00'], [$zulu->Kind, $zulu->ToString('s')]);
        $this->assertSame('2024-07-01T10:00:00', DateTime::Parse('2024-07-01T12:00:00+04:00')->ToString('s'));
        $this->assertSame(DateTimeKind::Unspecified, DateTime::Parse('2024-07-01 12:00')->Kind);
        $this->assertSame('2024-01-15T00:00:00', DateTime::Parse('January 15, 2024')->ToString('s'));
        $this->assertSame('2024-07-01T14:30:00', DateTime::ParseExact('01/07/2024 14:30', 'dd/MM/yyyy HH:mm')->ToString('s'));

        foreach (['tomorrow', '+1 week', '', '2024-13-01', null] as $text) {
            $this->assertFalse(DateTime::TryParse($text, $result), (string) $text);
            $this->assertSame(0, $result->Ticks);
        }
        $this->expectException(FormatException::class);
        DateTime::ParseExact('2024-02-30', 'yyyy-MM-dd');
    }

    public function testArithmetic(): void
    {
        $this->assertSame('2024-02-29', (new DateTime(2024, 3, 31))->AddMonths(-1)->ToString('yyyy-MM-dd'));
        $this->assertSame('2023-02-28', (new DateTime(2024, 2, 29))->AddYears(-1)->ToString('yyyy-MM-dd'));
        $span = (new DateTime(2024, 3, 1))->Subtract(new DateTime(2024, 2, 1));
        $this->assertInstanceOf(TimeSpan::class, $span);
        $this->assertSame(29, $span->Days);
        $this->assertSame('1/31/2024', (new DateTime(2024, 2, 1))->Subtract(TimeSpan::FromDays(1))->ToString('d'));
        $this->assertSame(-1, DateTime::Compare(DateTime::$MinValue, DateTime::$MaxValue));
    }

    public function testDateTimeRangeErrors(): void
    {
        $errors = [
            static fn () => DateTime::$MaxValue->AddTicks(1),
            static fn () => DateTime::$MinValue->AddDays(-1),
            static fn () => DateTime::$MinValue->AddMonths(-1),
            static fn () => DateTime::$MaxValue->AddYears(1),
            static fn () => new DateTime(2024, 13, 1),
            static fn () => new DateTime(2024, 1, 1, 24, 0, 0),
            static fn () => new DateTime(-1),
            static fn () => DateTime::DaysInMonth(2024, 0),
        ];
        foreach ($errors as $i => $error) {
            try {
                $error();
                $this->fail("case $i did not throw");
            } catch (ArgumentOutOfRangeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTimeSpanFormatsAndParsing(): void
    {
        $this->assertSame('01:02:03', (new TimeSpan(1, 2, 3))->ToString());
        $this->assertSame('1.02:03:04.0050000', (new TimeSpan(1, 2, 3, 4, 5))->ToString());
        $this->assertSame('-01:30:00', TimeSpan::FromMinutes(-90)->ToString('c'));
        $this->assertSame('0:00:01.5', TimeSpan::FromMilliseconds(1500)->ToString('g'));
        $this->assertSame('0:01:30:00.0000000', TimeSpan::FromHours(1.5)->ToString('G'));
        $this->assertSame('1.01:00:00', TimeSpan::FromHours(25)->ToString('d\\.hh\\:mm\\:ss'));
        $this->assertSame('01:30', Op::format(TimeSpan::FromMinutes(90), 'hh\\:mm'));
        $this->assertSame('"00:00:01.5000000"', json_encode(TimeSpan::FromSeconds(1.5)));

        $this->assertSame('01:30:00', TimeSpan::Parse('1:30')->ToString());
        $this->assertSame('2.00:00:00', TimeSpan::Parse('2')->ToString());
        $this->assertSame(-93784.5, TimeSpan::Parse('-1.02:03:04.5')->TotalSeconds);
        $this->assertFalse(TimeSpan::TryParse('25:00:00', $result));
        $this->assertSame(TimeSpan::$Zero, $result);
    }

    public function testTimeSpanArithmeticAndOverflow(): void
    {
        $this->assertSame('00:15:00', TimeSpan::FromMinutes(10)->Multiply(1.5)->ToString());
        $this->assertSame('00:05:00', TimeSpan::FromMinutes(10)->Divide(2)->ToString());
        $this->assertSame('00:05:00', TimeSpan::FromMinutes(-5)->Duration()->ToString());
        $this->assertSame(['00:05:00', '-00:05:00'], [TimeSpan::FromMinutes(-5)->Negate()->ToString(), TimeSpan::FromMinutes(5)->Negate()->ToString()]);
        $this->assertTrue(TimeSpan::FromHours(1)->Equals(TimeSpan::FromMinutes(60)));
        $this->assertSame(1, TimeSpan::FromHours(1)->CompareTo(null));

        $overflows = [
            static fn () => TimeSpan::$MaxValue->Add(TimeSpan::FromTicks(1)),
            static fn () => TimeSpan::$MinValue->Negate(),
            static fn () => TimeSpan::FromDays(1e20),
            static fn () => TimeSpan::FromMinutes(1)->Divide(0),
            static fn () => TimeSpan::$Zero->Divide(0),
        ];
        foreach ($overflows as $i => $overflow) {
            try {
                $overflow();
                $this->fail("case $i did not throw");
            } catch (OverflowException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ArgumentException::class);
        TimeSpan::FromSeconds(NAN);
    }
}
