<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\System\Collections\Generic\Dictionary;
use SharpPhp\Runtime\System\Collections\Generic\KeyNotFoundException;
use SharpPhp\Runtime\System\Collections\Generic\List_;
use SharpPhp\Runtime\System\Console;
use SharpPhp\Runtime\System\DivideByZeroException;
use SharpPhp\Runtime\System\InvalidCastException;
use SharpPhp\Runtime\System\Math;
use SharpPhp\Runtime\System\String_;

final class RuntimeTest extends TestCase
{
    public function testToStringLikeCSharp(): void
    {
        $this->assertSame(['True', 'False', '', '1.5', '3', '1E+20'], array_map(Op::str(...), [true, false, null, 1.5, 3.0, 1e20]));
    }

    public function testFormatting(): void
    {
        $this->assertSame('3.14', Op::format(3.14159, 'F2'));
        $this->assertSame('1,234,567.9', Op::format(1234567.89, 'N1'));
        $this->assertSame('00042', Op::format(42, 'D5'));
        $this->assertSame('ff', Op::format(255, 'x'));
        $this->assertSame('007.50', Op::format(7.5, '000.00'));
        $this->assertSame('1,234.5', Op::format(1234.5, '#,##0.##'));
        $this->assertSame('2024-03-09', Op::format(new \DateTimeImmutable('2024-03-09'), 'yyyy-MM-dd'));
        $this->assertSame('Hi Ann, 2.50!', String_::Format('Hi {0}, {1:F2}!', 'Ann', 2.5));
    }

    public function testArithmetic(): void
    {
        $this->assertSame('ab', Op::add('a', 'b'));
        $this->assertSame('a1', Op::add('a', 1));
        $this->assertSame(3, Op::add(1, 2));
        $this->assertSame(3, Op::div(7, 2));
        $this->assertSame(3.5, Op::div(7.0, 2));
        $this->assertSame(INF, Op::div(1.0, 0));
        $this->expectException(DivideByZeroException::class);
        Op::div(1, 0);
    }

    public function testCasts(): void
    {
        $this->assertSame('x', Op::as('x', 'string'));
        $this->assertNull(Op::as(1, 'string'));
        $this->assertSame(2.0, Op::cast(2, 'float'));
        $this->expectException(InvalidCastException::class);
        Op::cast('x', 'int');
    }

    public function testBankersRounding(): void
    {
        $this->assertSame([2.0, 4.0, 2.2, -2.0], [Math::Round(2.5), Math::Round(3.5), Math::Round(2.25, 1), Math::Round(-2.5)]);
    }

    public function testTypedListRejectsWrongElements(): void
    {
        $list = new List_([1, 2], of: 'int');
        $list->Add(3);
        $this->assertSame([1, 2, 3], $list->ToArray());
        $this->assertSame(3, $list->Count);

        $this->expectException(ArgumentException::class);
        $list->Add('4');
    }

    public function testListIndexIsChecked(): void
    {
        $this->expectException(ArgumentOutOfRangeException::class);
        (new List_([1]))[1];
    }

    public function testDictionaryKeepsKeyTypes(): void
    {
        $dict = new Dictionary(of: ['string', 'int']);
        $dict['1'] = 1;
        $dict['b'] = 2;
        $this->assertSame(['1', 'b'], $dict->Keys->ToArray());
        $this->assertTrue($dict->ContainsKey('1'));
        $this->assertFalse($dict->ContainsKey(1));
        $this->assertTrue($dict->TryGetValue('b', $value));
        $this->assertSame(2, $value);

        $this->expectException(KeyNotFoundException::class);
        $dict['zzz'];
    }

    public function testLinqChain(): void
    {
        $result = (new List_([5, 3, 8, 1, 4]))->Where(static fn (int $n): bool => $n > 2)->OrderBy()->Select(static fn (int $n): int => $n * 10);
        $this->assertSame([30, 40, 50, 80], $result->ToArray());
    }

    public function testConsoleCompositeFormat(): void
    {
        // Lines end with PHP_EOL, as .NET's Console ends them with Environment.NewLine.
        $this->expectOutputString('1 + 2 = 3' . PHP_EOL . 'True' . PHP_EOL);
        Console::WriteLine('{0} + {1} = {2}', 1, 2, 3);
        Console::WriteLine(true);
    }
}
