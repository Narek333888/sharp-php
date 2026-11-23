<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SharpPhp\CodeGen\Emitted;
use SharpPhp\Semantics\Type;

/**
 * Checks the PHP produced for individual constructs.
 */
final class CodeGenTest extends TestCase
{
    private function body(string $statements, string $members = ''): string
    {
        $code = $this->compile("using System;\nclass P\n{\n$members\n    static void Main()\n    {\n$statements\n    }\n}\n");
        preg_match('/function Main\(\): void\n    \{\n(.*?)\n    \}\n/s', $code, $m);

        return implode("\n", array_map('trim', explode("\n", $m[1] ?? $code)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function expressions(): iterable
    {
        yield 'int division' => ['int a = 7; var b = a / 2;', "\$a = 7;\n\$b = \\intdiv(\$a, 2);"];
        yield 'float division' => ['double a = 7; var b = a / 2;', "\$a = 7.0;\n\$b = \\fdiv(\$a, 2);"];
        yield 'string concat' => ['var s = "n=" + 1 + 2;', "\$s = 'n=' . 1 . 2;"];
        yield 'numeric then concat' => ['var s = 1 + 2 + "n";', "\$s = 1 + 2 . 'n';"];
        yield 'precedence kept' => ['var x = (1 + 2) * 3 - (4 - 5);', "\$x = (1 + 2) * 3 - (4 - 5);"];
        yield 'strict equality' => ['var a = "x"; var e = a == "y";', "\$a = 'x';\n\$e = \$a === 'y';"];
        yield 'nested ternary' => ['var n = 1; var s = n > 0 ? "p" : n < 0 ? "n" : "z";', "\$n = 1;\n\$s = \$n > 0 ? 'p' : (\$n < 0 ? 'n' : 'z');"];
        yield 'bool to string' => ['var b = true; var s = $"{b}!";', "\$b = true;\n\$s = Op::str(\$b) . '!';"];
        yield 'string length' => ['var s = "abc"; var n = s.Length;', "\$s = 'abc';\n\$n = \\mb_strlen(\$s);"];
        yield 'string method' => ['var s = "abc".ToUpper();', "\$s = String_::ToUpper('abc');"];
        yield 'null conditional' => ['string s = null; var n = s?.Length;', "\$s = null;\n\$n = \$s === null ? null : \\mb_strlen(\$s);"];
        yield 'compound concat' => ['var s = ""; s += 5;', "\$s = '';\n\$s .= 5;"];
        yield 'lambda by value' => ['int k = 2; Func<int, int> f = x => x * k;', "\$k = 2;\n\$f = fn (\$x) => \$x * \$k;"];
        yield 'lambda by reference' => ['int k = 0; Action inc = () => k++;', "\$k = 0;\n\$inc = function () use (&\$k) {\nreturn \$k++;\n};"];
        yield 'type pattern' => ['object o = 1; if (o is int i) Console.WriteLine(i);', "\$o = 1;\nif (\\is_int(\$i = \$o)) {\nConsole::WriteLine(\$i);\n}"];
        yield 'switch expression' => ['int n = 2; var s = n switch { 1 => "a", 2 => "b", _ => "c" };', "\$n = 2;\n\$s = match (\$n) {\n1 => 'a',\n2 => 'b',\ndefault => 'c',\n};"];
        yield 'generic list' => ['var l = new System.Collections.Generic.List<int> { 1, 2 };', "\$l = new List_([1, 2], of: 'int');"];
        yield 'checked unboxing' => ['object o = 1; int i = (int)o;', "\$o = 1;\n\$i = Op::cast(\$o, 'int');"];
        yield 'float truncation' => ['int i = (int)3.9;', "\$i = 3;"];
        yield 'saturating double cast' => ['double d = 3.9; int i = (int)d;', "\$d = 3.9;\n\$i = Op::truncate(\$d, 'int');"];
        yield 'escaped string' => ['var s = "a\nb$c";', "\$s = \"a\\nb\\\$c\";"];
        yield 'static member' => ['Console.WriteLine(Math.PI);', 'Console::WriteLine(Op::doubleText(Math::PI));'];
        yield 'array element write' => ['int[] a = new int[2]; a[1] = 5; a[0]++;', "\$a = \\array_fill(0, 2, 0);\n\$a[Op::index(\$a, 1)] = 5;\n\$a[Op::index(\$a, 0)]++;"];
        yield 'array element read' => ['int[] a = new int[2]; var x = a[1];', "\$a = \\array_fill(0, 2, 0);\n\$x = \$a[1];"];
        yield 'lambda writing an array element' => ['int[] a = new int[1]; Action f = () => a[0] = 1;', "\$a = \\array_fill(0, 1, 0);\n\$f = function () use (&\$a) {\nreturn \$a[Op::index(\$a, 0)] = 1;\n};"];
        yield 'lambda reading an array' => ['int[] a = new int[1]; Func<int> f = () => a[0];', "\$a = \\array_fill(0, 1, 0);\n\$f = fn () => \$a[0];"];
        yield 'operator of a runtime type' => ['var span = new DateTime(2024, 1, 2) - new DateTime(2024, 1, 1);', '$span = DateTime::op_Subtraction(new DateTime(2024, 1, 2), new DateTime(2024, 1, 1));'];
        yield 'comparison of a runtime type' => ['bool later = DateTime.Now > DateTime.Today;', '$later = DateTime::Compare(DateTime::get_Now(), DateTime::get_Today()) > 0;'];
        yield 'negation of a runtime type' => ['var back = -TimeSpan.FromHours(1);', '$back = TimeSpan::op_UnaryNegation(TimeSpan::FromHours(1.0));'];
        yield 'lifted operator' => ['DateTime? d = null; var e = d == DateTime.Today;', "\$d = null;\n\$e = Op::equals(\$d, DateTime::get_Today());"];
        yield 'nullable HasValue and Value' => ['int? n = null; var has = n.HasValue; var v = n.GetValueOrDefault(3);', "\$n = null;\n\$has = \$n !== null;\n\$v = \$n ?? 3;"];
    }

    #[DataProvider('expressions')]
    public function testExpression(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->body($source));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function repeatablePaths(): iterable
    {
        yield 'local' => ['$a', true];
        yield 'property chain' => ['$this->a->b', true];
        yield 'static property' => ['self::$a', true];
        yield 'qualified static property' => ['\App\Config::$a', true];
        yield 'element' => ['$grid[0]', true];
        yield 'element with arithmetic' => ['$this->grid[$i + 1][$j]', true];
        yield 'increment in an index' => ['$grid[$i++]', false];
        yield 'decrement in an index' => ['$grid[--$i]', false];
        yield 'call in an index' => ['$grid[\count($a)]', false];
        yield 'method call' => ['$this->Items()', false];
        yield 'assignment' => ['$a = $b', false];
    }

    /** Code repeated by the array bounds check ($a[Op::index($a, $i)]) must have no side effects. */
    #[DataProvider('repeatablePaths')]
    public function testRepeatablePath(string $code, bool $repeatable): void
    {
        $this->assertSame($repeatable, Emitted::value($code, Type::mixed())->isRepeatablePath());
    }

    public function testClassShape(): void
    {
        $code = $this->compile(<<<'CS'
namespace App.Models;

public abstract class Shape
{
    public abstract double Area { get; }
}

public sealed class Rect : Shape
{
    private static int created;
    public double W { get; }
    public double H { get; private set; }
    public string Label { get; set; } = "rect";
    public override double Area => W * H;

    public Rect(double w, double h)
    {
        W = w;
        H = h;
        created++;
    }

    public static int Created() => created;
}
CS);
        $this->assertStringContainsString(<<<'PHP'
namespace App\Models;

abstract class Shape
{
    abstract public float $Area { get; }
}

final class Rect extends Shape
{
    private static int $created = 0;

    public private(set) float $W = 0.0;

    public private(set) float $H = 0.0;

    public string $Label = 'rect';

    public float $Area {
        get => $this->W * $this->H;
    }

    public function __construct(float $w, float $h)
    {
        $this->W = $w;
        $this->H = $h;
        self::$created++;
    }

    public static function Created(): int
    {
        return self::$created;
    }
}
PHP, $code);
    }

    public function testFieldInitializersMoveIntoTheConstructor(): void
    {
        $code = $this->compile("using System.Collections.Generic;\nclass Bag\n{\n    private List<string> items = new List<string>();\n    private int size = 3;\n}");
        $this->assertStringContainsString("private List_ \$items;", $code);
        $this->assertStringContainsString("private int \$size = 3;", $code);
        $this->assertStringContainsString("public function __construct()\n    {\n        \$this->items = new List_(of: 'string');\n    }", $code);
    }

    public function testGenericClassKeepsTemplateDocAndConstraintTypes(): void
    {
        $code = $this->compile("interface IAnimal { string Name { get; } }\nclass Shelter<T> where T : IAnimal\n{\n    public T First(T a) => a;\n}");
        $this->assertStringContainsString("/**\n * @template T\n */\nclass Shelter", $code);
        $this->assertStringContainsString('public function First(IAnimal $a): IAnimal', $code);
    }

    public function testEnumsAreIntBacked(): void
    {
        $code = $this->compile('enum Level { Low, Mid = 5, High }');
        $this->assertStringContainsString("enum Level: int\n{\n    case Low = 0;\n    case Mid = 5;\n    case High = 6;\n}", $code);
    }

    public function testReservedClassNamesAreMangled(): void
    {
        $code = $this->compile('class List { } class Match { }');
        $this->assertStringContainsString('class List_', $code);
        $this->assertStringContainsString('class Match_', $code);
    }
}
