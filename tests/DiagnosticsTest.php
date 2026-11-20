<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SharpPhp\Compiler\CompilationFailed;
use SharpPhp\Compiler\Compiler;
use SharpPhp\Compiler\SourceFile;

final class DiagnosticsTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, int, int}> */
    public static function errors(): iterable
    {
        $main = static fn (string $body): string => "using System;\nclass P\n{\n    static void Main()\n    {\n        $body\n    }\n}\n";

        yield 'unknown name' => [$main('Console.WriteLine(x);'), 'SP0103', "The name 'x' does not exist in the current context", 6, 27];
        yield 'unknown root name' => [$main('Consol.WriteLine(1);'), 'SP0103', "The name 'Consol' does not exist in the current context", 6, 9];
        yield 'unknown type' => [$main('Foo f = null;'), 'SP0246', "The type or namespace name 'Foo' could not be found (are you missing a using directive?)", 6, 9];
        yield 'missing semicolon' => [$main('var x = 1'), 'SP1002', "';' expected", 7, 5];
        yield 'unknown member' => [$main('"text".Shout();'), 'SP1061', "'string' does not contain a definition for 'Shout'", 6, 16];
        yield 'static member via type' => [$main('Console.Nope();'), 'SP0117', "'System.Console' does not contain a definition for 'Nope'", 6, 17];
        $p = "\nclass P { static void Main() {} }";
        yield 'extension method outside a static class' => ['class Ext { public static string Shout(this string s) => s; }' . $p, 'SP1106', 'Extension method must be defined in a non-generic static class', 1, 13];
        yield 'extension method in a generic class' => ['static class G<T> { public static string Shout(this string s) => s; }' . $p, 'SP1106', 'Extension method must be defined in a non-generic static class', 1, 21];
        yield 'instance extension method' => ['static class Ext { public string Shout(this string s) => s; }' . $p, 'SP1105', 'Extension method must be static', 1, 20];
        yield "'this' on a later parameter" => ['static class Ext { public static string Join(string a, this string b) => a; }' . $p, 'SP1100', "Method 'Join' has a parameter modifier 'this' which is not on the first parameter", 1, 56];
        yield 'extension method not in scope' => ["namespace A { public static class E { public static string Shout(this string s) => s; } }\nnamespace B { class P { static void Main() { \"x\".Shout(); } } }", 'SP1061', "'string' does not contain a definition for 'Shout'", 2, 50];
        yield 'ambiguous extension methods' => ["static class E1 { public static string Shout(this string s) => s; }\nstatic class E2 { public static string Shout(this string s) => s; }\nclass P { static void Main() { \"x\".Shout(); } }", 'SP0121', "The call is ambiguous between the following methods or properties: 'E1.Shout(string)' and 'E2.Shout(string)'", 3, 36];
        yield 'implicit var without value' => [$main('var x;'), 'SP0818', 'Implicitly-typed variables must be initialized', 6, 13];
        yield 'duplicate local' => [$main('int a = 1; int a = 2;'), 'SP0128', "A local variable or function named 'a' is already defined in this scope", 6, 24];
        yield 'break outside loop' => [$main('break;'), 'SP0139', 'No enclosing loop out of which to break or continue', 6, 9];
        yield 'rethrow outside catch' => [$main('throw;'), 'SP0156', 'A throw statement with no arguments is not allowed outside of a catch clause', 6, 9];
        yield 'abstract instantiation' => ["abstract class A {}\nclass P { static void Main() { var a = new A(); } }", 'SP0144', "Cannot create an instance of the abstract type or interface 'A'", 2, 40];
        yield 'instance from static' => ["class P\n{\n    int count;\n    static void Main() { count = 1; }\n}", 'SP0120', "An object reference is required for the non-static field, method, or property 'P.count'", 4, 26];
        yield 'overloading' => ["class P\n{\n    void A() {}\n    void A(int x) {}\n    static void Main() {}\n}", 'SP0111', "Method overloading is not supported: 'P' already defines a member called 'A' (PHP method names are case-insensitive)", 4, 5];
        yield 'bad override' => ["class P\n{\n    public override void Run() {}\n    static void Main() {}\n}", 'SP0115', "'P.Run()': no suitable method found to override", 3, 5];
        yield 'unterminated string' => [$main('var s = "abc;'), 'SP1001', 'Newline in constant', 6, 17];
        yield 'void return value' => ["class P { static void Main() { return 1; } }", 'SP0127', 'Since this method returns void, a return keyword must not be followed by an object expression', 1, 32];
        yield 'too many arguments' => ["class P\n{\n    static int Sq(int x) => x * x;\n    static void Main() { Sq(1, 2); }\n}", 'SP1501', "No overload for method 'Sq' takes 2 arguments", 4, 26];
        yield 'missing argument' => ["class P\n{\n    static int Add(int a, int b) => a + b;\n    static void Main() { Add(1); }\n}", 'SP7036', "There is no argument given that corresponds to the required parameter 'b' of 'P.Add(int, int)'", 4, 26];
        yield 'unknown named argument' => ["class P\n{\n    static int Sq(int x) => x * x;\n    static void Main() { Sq(y: 1); }\n}", 'SP1739', "The best overload for 'Sq' does not have a parameter named 'y'", 4, 32];
        yield 'too many constructor arguments' => ["record R(int A);\nclass P { static void Main() { var r = new R(1, 2); } }", 'SP1729', "'R' does not contain a constructor that takes 2 arguments", 2, 40];
        yield 'missing base constructor argument' => ["class A { public A(int x) {} }\nclass B : A { public B() {} }\nclass P { static void Main() {} }", 'SP7036', "There is no argument given that corresponds to the required parameter 'x' of 'A.A(int)'", 2, 15];
        yield 'too many delegate arguments' => [$main('Func<int, int> f = x => x; f(1, 2);'), 'SP1593', "Delegate 'Func<int, int>' does not take 2 arguments", 6, 36];
        yield 'abstract member in concrete class' => ["class A { public abstract void M(int x); }\nclass P { static void Main() {} }", 'SP0513', "'A.M(int)' is abstract but it is contained in non-abstract type 'A'", 1, 1];
        yield 'abstract member not implemented' => ["abstract class A { public abstract int Size { get; } }\nclass B : A { }\nclass P { static void Main() {} }", 'SP0534', "'B' does not implement inherited abstract member 'A.Size'", 2, 1];
        yield 'interface member not implemented' => ["interface IRun { void Run(); }\nclass A : IRun { }\nclass P { static void Main() {} }", 'SP0535', "'A' does not implement interface member 'IRun.Run()'", 2, 1];
        yield 'inherited interface member not implemented' => ["interface IA { void A(); }\ninterface IB : IA { }\nabstract class Base : IB { public void A() {} }\nclass C : Base { }\nclass D : IB { }\nclass P { static void Main() {} }", 'SP0535', "'D' does not implement interface member 'IA.A()'", 5, 1];
        yield 'IComparable without CompareTo' => ["class A : IComparable<A> { }\nclass P { static void Main() {} }", 'SP0535', "'A' does not implement interface member 'System.IComparable<T>.CompareTo(T)'", 1, 1];
        yield 'tuple' => [$main('var t = (1, 2);'), 'SP1010', 'Tuples are not supported; use a record or a class instead', 6, 17];
        yield 'string to int' => [$main('int a = "hello";'), 'SP0029', "Cannot implicitly convert type 'string' to 'int'", 6, 17];
        yield 'int condition' => [$main('int n = 1; if (n) { }'), 'SP0029', "Cannot implicitly convert type 'int' to 'bool'", 6, 24];
        yield 'double to int' => [$main('int a = 2.5;'), 'SP0266', "Cannot implicitly convert type 'double' to 'int'. An explicit conversion exists (are you missing a cast?)", 6, 17];
        yield 'null to int' => [$main('int a = null;'), 'SP0037', "Cannot convert null to 'int' because it is a non-nullable value type", 6, 17];
        yield 'argument type' => ["class P\n{\n    static int Sq(int x) => x * x;\n    static void Main() { Sq(\"a\"); }\n}", 'SP1503', "Argument 1: cannot convert from 'string' to 'int'", 4, 29];
        yield 'generic collection argument' => [$main('var l = new List<int>(); l.Add("s");'), 'SP1503', "Argument 1: cannot convert from 'string' to 'int'", 6, 40];
        yield 'return type' => ["class P\n{\n    static int F() { return \"x\"; }\n    static void Main() {}\n}", 'SP0029', "Cannot implicitly convert type 'string' to 'int'", 3, 29];
        yield 'missing return' => ["class P\n{\n    static int F(bool b) { if (b) return 1; }\n    static void Main() {}\n}", 'SP0161', "'P.F(bool)': not all code paths return a value", 3, 5];
        yield 'missing return in lambda' => [$main('Func<int, int> f = x => { if (x > 0) return 1; };'), 'SP1643', "Not all code paths return a value in lambda expression of type 'Func<int, int>'", 6, 28];
        yield 'private field' => ["class A { int _x; }\nclass P { static void Main() { var a = new A(); var y = a._x; } }", 'SP0122', "'A._x' is inaccessible due to its protection level", 2, 59];
        yield 'get-only property' => ["class A { public int X { get; } }\nclass P { static void Main() { var a = new A(); a.X = 1; } }", 'SP0200', "Property or indexer 'A.X' cannot be assigned to -- it is read only", 2, 51];
        yield 'private setter' => ["class A { public int X { get; private set; } }\nclass P { static void Main() { var a = new A(); a.X = 1; } }", 'SP0272', "The property or indexer 'A.X' cannot be used in this context because the set accessor is inaccessible", 2, 51];
        yield 'init-only property' => ["record R(int X);\nclass P { static void Main() { var r = new R(1); r.X = 2; } }", 'SP8852', "Init-only property or indexer 'R.X' can only be assigned in an object initializer, or on 'this' or 'base' in an instance constructor or an 'init' accessor", 2, 52];
        yield 'readonly field' => ["class A { readonly int _n; void M() { _n = 1; } }\nclass P { static void Main() {} }", 'SP0191', 'A readonly field cannot be assigned to (except in a constructor or init-only setter of the type in which the field is defined or a variable initializer)', 1, 39];
        yield 'unassigned local' => [$main('int x; Console.WriteLine(x);'), 'SP0165', "Use of unassigned local variable 'x'", 6, 34];
        yield 'local assigned in one branch' => ["class P\n{\n    static int F(bool b) { int x; if (b) x = 1; return x; }\n    static void Main() {}\n}", 'SP0165', "Use of unassigned local variable 'x'", 3, 56];
        yield 'with on a class' => ["class A { public int X { get; set; } }\nclass P { static void Main() { var a = new A(); var b = a with { X = 1 }; } }", 'SP8858', "The receiver type 'A' is not a valid record type and is not a struct type.", 2, 59];
        yield 'with on a get-only member' => ["record R(int X) { public int Y { get; } }\nclass P { static void Main() { var r = new R(1); var s = r with { Y = 2 }; } }", 'SP0200', "Property or indexer 'R.Y' cannot be assigned to -- it is read only", 2, 67];
        yield 'with value type' => ["record R(int X);\nclass P { static void Main() { var r = new R(1); var s = r with { X = \"a\" }; } }", 'SP0029', "Cannot implicitly convert type 'string' to 'int'", 2, 71];
        yield 'unknown member in property pattern' => ["class A { public int X { get; set; } }\nclass P { static void Main() { object o = new A(); var b = o is A { Z: 1 }; } }", 'SP1061', "'A' does not contain a definition for 'Z'", 2, 69];
        yield 'static property passed by ref' => ["static class C { public static int X { get => 1; set { } } }\nclass P { static void F(ref int v) {} static void Main() { F(ref C.X); } }", 'SP0206', 'A property or indexer may not be passed as an out or ref parameter', 2, 68];
        yield 'out parameter not assigned' => ["class P\n{\n    static void F(out int x) { }\n    static void Main() {}\n}", 'SP0177', "The out parameter 'x' must be assigned to before control leaves the current method", 3, 5];
        yield 'static interface implementation' => ["interface I { void F(); }\nclass C : I { public static void F() {} }\nclass P { static void Main() {} }", 'SP0736', "'C.F()' cannot implement interface member 'I.F()' because it is static", 2, 1];
        yield 'interface signature mismatch' => ["interface I { int F(int x); }\nclass C : I { public int F() => 1; }\nclass P { static void Main() {} }", 'SP0535', "'C' does not implement interface member 'I.F(int)'", 2, 1];
        yield 'switch section falls through' => [$main('switch (1) { case 1: Console.WriteLine(1); case 2: break; }'), 'SP0163', 'Control cannot fall through from one case label to another; end the section with break, return or throw', 6, 22];
        yield 'assigned only before an unreachable break' => ["class P\n{\n    static int F(int i) { int x; while (true) { if (i > 2) break; else continue; x = 1; } return x; }\n    static void Main() {}\n}", 'SP0165', "Use of unassigned local variable 'x'", 3, 98];
        yield 'char to a user string parameter' => ["class P\n{\n    static void F(string s) {}\n    static void Main() { F('a'); }\n}", 'SP1503', "Argument 1: cannot convert from 'char' to 'string'", 4, 28];
        yield 'missing .NET overload in the runtime' => [$main('"x".Trim(\'a\', \'b\');'), 'SP1501', "No overload for method 'Trim' takes 2 arguments (the SharpPHP runtime library does not implement this .NET overload)", 6, 13];
        yield 'nested types' => ["class P { class Inner {} static void Main() {} }", 'SP1010', 'Nested types are not supported; declare the type at namespace level', 1, 11];
        yield 'anonymous type property assigned' => [$main('var a = new { X = 1 }; a.X = 2;'), 'SP0200', "Property or indexer '<anonymous type: int X>.X' cannot be assigned to -- it is read only", 6, 34];
        yield 'anonymous type unknown property' => [$main('var a = new { X = 1 }; Console.WriteLine(a.Y);'), 'SP1061', "'<anonymous type: int X>' does not contain a definition for 'Y'", 6, 52];
        yield 'anonymous type null property' => [$main('var a = new { X = null };'), 'SP0828', "Cannot assign '<null>' to anonymous type property", 6, 27];
        yield 'anonymous type lambda property' => [$main('var a = new { F = (int x) => x };'), 'SP0828', 'Cannot assign lambda expression to anonymous type property', 6, 27];
        yield 'anonymous type duplicate property' => [$main('var a = new { X = 1, X = 2 };'), 'SP0833', 'An anonymous type cannot have multiple properties with the same name', 6, 30];
        yield 'anonymous type unnamed property' => [$main('var a = new { 1 + 2 };'), 'SP0746', 'Invalid anonymous type member declarator. Anonymous type members must be declared with a member assignment, simple name or member access.', 6, 23];
        yield 'anonymous types of different shape' => [$main('var a = new { X = 1 }; a = new { X = "s" };'), 'SP0029', "Cannot implicitly convert type '<anonymous type: string X>' to '<anonymous type: int X>'", 6, 36];
        yield 'documented magic method arguments' => ["using Illuminate.Support.Facades;\nclass P { static void Main() { Config.set(); } }", 'SP7036', "There is no argument given that corresponds to the required parameter 'key' of 'Illuminate.Support.Facades.Config.set(object, object)'", 2, 39];
        yield 'await outside an async method' => [$main('await Task.Delay(1);'), 'SP4033', "The 'await' operator can only be used within an async method. Consider marking this method with the 'async' modifier and changing its return type to 'Task'.", 6, 9];
        yield 'async method returning int' => ["class P\n{\n    static async int F() => 1;\n    static void Main() {}\n}", 'SP1983', 'The return type of an async method must be void, Task or Task<T>', 3, 5];
        yield 'async method with ref parameter' => ["using System.Threading.Tasks;\nclass P\n{\n    static async Task F(ref int x) { await Task.Delay(1); }\n    static void Main() {}\n}", 'SP1988', 'Async methods cannot have ref, in or out parameters', 4, 25];
        yield 'awaiting an int' => ["using System.Threading.Tasks;\nclass P\n{\n    static async Task F() { await 5; }\n    static void Main() {}\n}", 'SP1061', "'int' does not contain a definition for 'GetAwaiter' (only tasks can be awaited)", 4, 29];
        yield 'async Task returning a value' => ["using System.Threading.Tasks;\nclass P\n{\n    static async Task F() { await Task.Delay(1); return 1; }\n    static void Main() {}\n}", 'SP1997', "Since this is an async method that returns 'Task', a return keyword must not be followed by an object expression. Did you intend to return 'Task<T>'?", 4, 50];
        yield 'async Task<T> result type' => ["using System.Threading.Tasks;\nclass P\n{\n    static async Task<int> F() { await Task.Delay(1); return \"x\"; }\n    static void Main() {}\n}", 'SP0029', "Cannot implicitly convert type 'string' to 'int'", 4, 62];
        yield 'await result type' => ["using System.Threading.Tasks;\nclass P\n{\n    static async Task<int> F() => 1;\n    static async Task G() { string s = await F(); }\n    static void Main() {}\n}", 'SP0029', "Cannot implicitly convert type 'int' to 'string'", 5, 40];
        yield 'route handler request is typed' => ["using System.Web;
class P { static void Main() { WebApp.Create().MapGet(\"/\", req => { int m = req.Method; return m; }); } }", 'SP0029', "Cannot implicitly convert type 'string' to 'int'", 2, 81];
        yield 'invariant generic class' => ['class Animal {}
class Dog : Animal {}
interface IBox<out T> { T Get(); }
class P { static void Main() { List<Animal> a = new List<Dog>(); } }', 'SP0029', 'Cannot implicitly convert type \'System.Collections.Generic.List<Dog>\' to \'System.Collections.Generic.List<Animal>\'', 4, 49];
        yield 'variance in the wrong direction' => ['class Animal {}
class Dog : Animal {}
interface IBox<out T> { T Get(); }
class P { static void Main(IBox<Animal> b) { IBox<Dog> d = b; } }', 'SP0266', 'Cannot implicitly convert type \'IBox<Animal>\' to \'IBox<Dog>\'. An explicit conversion exists (are you missing a cast?)', 4, 60];
        yield 'delegate variance' => ['class Animal {}
class Dog : Animal {}
interface IBox<out T> { T Get(); }
class P { static void Main() { Func<Animal> f = () => new Animal(); Func<Dog> g = f; } }', 'SP0029', 'Cannot implicitly convert type \'Func<Animal>\' to \'Func<Dog>\'', 4, 83];
        yield 'variance on a class' => ['class Box<out T> {}
class P { static void Main() {} }', 'SP1960', 'Invalid variance modifier. Only interface and delegate type parameters can be specified as variant.', 1, 11];
        yield 'covariant parameter' => ['interface IBox<out T> { void Put(T item); }
class P { static void Main() {} }', 'SP1961', 'Invalid variance: The type parameter \'T\' must be contravariantly valid on \'IBox<T>.Put(T)\'. \'T\' is covariant.', 1, 25];
        yield 'using a non-disposable' => [$main('using (var s = "text") { }'), 'SP1674', '\'string\': type used in a using statement must be implicitly convertible to \'System.IDisposable\'', 6, 20];
        yield 'finalizer name' => ['class A { ~B() {} }
class P { static void Main() {} }', 'SP0574', 'Name of destructor must match name of type', 1, 12];
        yield 'finalizer in a struct' => ['struct S { ~S() {} }
class P { static void Main() {} }', 'SP0575', 'Only class types can contain destructors', 1, 12];
        yield 'finalizer modifier' => ['class A { public ~A() {} }
class P { static void Main() {} }', 'SP0106', 'The modifier \'public\' is not valid for this item', 1, 11];
        yield 'finalizer in a static class' => ['static class A { ~A() {} }
class P { static void Main() {} }', 'SP0711', 'Static classes cannot contain destructors', 1, 18];
        yield 'object constructor arguments' => [$main('var o = new object(1);'), 'SP1729', '\'object\' does not contain a constructor that takes 1 arguments', 6, 17];
    }

    #[DataProvider('errors')]
    public function testDiagnostic(string $source, string $code, string $message, int $line, int $column): void
    {
        $diagnostic = $this->compileError($source);

        $this->assertSame($code, $diagnostic->code);
        $this->assertSame($message, $diagnostic->message);
        $this->assertSame([$line, $column], [$diagnostic->line, $diagnostic->column]);
    }

    public function testErrorsFromSeveralFilesAreCollected(): void
    {
        try {
            (new Compiler())->compile([
                new SourceFile('A.sphp', 'class A { void M() { undefinedA(); } }'),
                new SourceFile('B.sphp', 'class B { void M() { int x = } }'),
            ]);
            $this->fail('Expected compile errors');
        } catch (CompilationFailed $e) {
            $this->assertCount(2, $e->diagnostics);
            $this->assertSame(['B.sphp', 'A.sphp'], array_map(static fn ($d) => $d->file->path, $e->diagnostics));
        }
    }

    public function testDuplicateEntryPointsAreReported(): void
    {
        try {
            (new Compiler())->compileString("class A { static void Main() {} }\nclass B { static void Main() {} }");
            $this->fail('Expected a compile error');
        } catch (CompilationFailed $e) {
            $this->assertSame('SP0017', $e->diagnostics[0]->code);
            $this->assertStringContainsString('A.Main(), B.Main()', $e->diagnostics[0]->message);
        }
    }

    public function testRenderedReportShowsCodeFrame(): void
    {
        try {
            (new Compiler())->compile([new SourceFile('/project/src/Program.sphp', "class P {\n    static void Main() { Missing(); }\n}")]);
            $this->fail('Expected a compile error');
        } catch (CompilationFailed $e) {
            $this->assertSame(
                "src/Program.sphp(2,26): error SP0103: The name 'Missing' does not exist in the current context\n"
                . "    2 |     static void Main() { Missing(); }\n"
                . "      |                          ^\n"
                . "\n"
                . 'Build FAILED. 1 error(s).',
                $e->render('/project'),
            );
        }
    }
}
