# SharpPHP

[![tests](https://github.com/Narek333888/sharp-php/actions/workflows/tests.yml/badge.svg)](https://github.com/Narek333888/sharp-php/actions/workflows/tests.yml)

SharpPHP is a C#-like language that compiles to modern PHP. You write `.sphp` files with C# syntax — classes, `static void Main`, properties, generics, LINQ, `switch` expressions, pattern matching — and the compiler turns them into clean, readable PHP 8.4 code.

```csharp
using System;
using System.Collections.Generic;
using System.Linq;

namespace App;

public class Program
{
    public static void Main(string[] args)
    {
        var people = new List<Person> { new("Ann", 31), new("Bob", 17) };

        foreach (var adult in people.Where(p => p.Age >= 18))
        {
            Console.WriteLine($"{adult.Name} is {adult.Age}");
        }
    }
}

public record Person(string Name, int Age);
```

## Requirements

- PHP 8.4 or newer, tested on 8.4 and 8.5 (the generated code uses property hooks and asymmetric visibility)
- `ext-mbstring`

## Installation

In your project folder (an empty folder or one with only `index.php` is fine: Composer creates
`composer.json` and `vendor/` itself):

```bash
composer require armdevstack/sharp-php
```

## Quick start

**1. Write a program** in `src/Program.sphp` (any number of `.sphp` files in any sub-directories of `src/`):

```csharp
using System;

namespace App;

public class Program
{
    public static void Main(string[] args)
    {
        Console.WriteLine("Hello from SharpPHP!");
    }
}
```

**2. Run it** from the command line:

```bash
vendor/bin/sharp run            # build (if needed) and run Main
vendor/bin/sharp run -- a b c   # pass arguments to Main(string[] args)
```

or from PHP, e.g. `index.php` (PhpStorm runs it with the green ▶ button):

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

exit(SharpPhp\Project::fromDirectory(__DIR__)->run(array_slice($argv ?? [], 1)));
```

```console
$ php index.php
Hello from SharpPHP!
```

Builds are cached: sources are recompiled only when a `.sphp` file (or the compiler) changes.

**3. Ignore the build output** if the project uses git — add to `.gitignore`:

```gitignore
/vendor/
/var/
```

**4. Turn on highlighting** for `.sphp` files in your editor: see [Editor support](#editor-support).

## Command line

| Command | Description |
|---|---|
| `sharp run [--project=DIR] [-- args]` | Build if needed and run the entry point |
| `sharp build [--project=DIR] [--force]` | Compile all sources to PHP |
| `sharp check [--project=DIR]` | Compile without writing files (CI) |
| `sharp compile <file.sphp>` | Print the PHP generated for one file |
| `sharp clean [--project=DIR]` | Delete the build directory |

## Editor support

Syntax highlighting for `.sphp` files in PhpStorm (and other JetBrains IDEs) and VS Code ships with the
package: in PhpStorm, open **Settings → Editor → TextMate Bundles**, click **+** and choose
`vendor/armdevstack/sharp-php/editors/sharpphp`. See [editors/sharpphp](editors/sharpphp/README.md).

## Configuration

Optional, in the project's `composer.json`:

```json
"extra": {
    "sharp": { "source": "src", "output": "var/sharp" }
}
```

The output directory contains the generated PHP (`classes/`) and `manifest.php` (class map, entry point, source map). Add it to `.gitignore`.

## Language guide

### Program structure

- `namespace App;` (file-scoped) or `namespace App { ... }`
- `using System;`, `using MyApp.Models;`, aliases `using Col = System.Collections.Generic;`
- `System`, `System.Collections.Generic` and `System.Linq` are imported implicitly
- Entry point: a `static void Main()` / `static int Main(string[] args)` method, or top-level statements (the returned `int` becomes the exit code)

### Types

- `class`, `abstract`, `sealed`, `static class`, `interface`, `enum`, `record`, `struct` (compiled as a final class, so it has reference semantics)
- Single inheritance plus interfaces: `class Book : Product, IComparable<Book>`
- Constructors with `: base(...)`, expression-bodied constructors, static constructors
- Fields, `const`, `readonly`, `static`
- Properties: auto (`{ get; set; }`, `{ get; private set; }`, `{ get; init; }`, get-only), with initializers, expression-bodied (`=> ...`), full `get { } set { }` with `value`, abstract and interface properties, static properties (with accessor bodies they become `get_Name()`/`set_Name()` methods)
- Methods: `virtual` / `override` / `abstract`, expression bodies, default and named arguments, `params`, `ref`, `out` (incl. `out var x`)
- Records: `record Point(int X, int Y);` with value equality (`==`), C#-style `ToString()` (every public property) and `with` expressions (`p with { X = 5 }`)
- Enums: `enum Color { Red, Green = 5 }`, `(int)color`, `(Color)1`, `color.ToString()`

### Generics

- Generic classes, interfaces and methods: `class Box<T>`, `interface IRepository<T>`, `T Max<T>(T a, T b)`
- Constraints: `where T : IShape, new()` — a class/interface constraint becomes the PHP parameter type
- Type inference for generic methods, including through lambdas: `box.Map(x => x * 2)` is a `Box<int>`
- The compiler tracks generic arguments, so `List<string>` elements are known to be strings (`names[0].Length`)
- Runtime checks: `new List<int>()` rejects non-integers, like C# (`ArgumentException`)
- Implementing a generic interface with a concrete type (`IRepository<User>`) is supported

### Statements and expressions

- `if`/`else`, `while`, `do`, `for`, `foreach` (incl. `foreach (var (key, value) in dict)`), `break`, `continue`
- `switch` statements (with patterns and `when`), `switch` expressions
- `try`/`catch`/`finally`, exception filters (`catch (E e) when (...)`), `throw;`, throw expressions (`x ?? throw ...`)
- `yield return` / `yield break` with `IEnumerable<T>`
- Lambdas `x => x * 2`, `(a, b) => { ... }`, closures with C# capture semantics, method groups (`list.ForEach(Print)`)
- Pattern matching: `is T x`, `is null`, `is not null`, `is > 0 and < 10`, `or`, `not`, `var`, `_`, property patterns (`Circle { R: > 10 } c`, `{ Name: var n, Age: >= 18 }`, `{ Home.City: "Paris" }`)
- String interpolation with format specifiers `$"{price:F2}"`, verbatim strings `@"C:\path"`, `$@"..."`
- `?.`, `?[]`, `??`, `??=`, `is`, `as`, casts, `typeof` / `GetType()` (a `System.Type` with `Name`, `FullName`, `Namespace`), `nameof`, `default`
- Object and collection initializers: `new User { Name = "Ann" }`, `new Dictionary<string, int> { ["a"] = 1 }`, `new()`, `[1, 2, 3]`

### Semantics that match C#

| C# | Generated PHP |
|---|---|
| `7 / 2` (ints) | `\intdiv(7, 2)` → 3 |
| `"a" + 1` | `'a' . 1` |
| `a == b` | `$a === $b` (records compare by value) |
| `true.ToString()` | `"True"` |
| `(int)obj` | checked unboxing (`InvalidCastException`) |
| `(Laptop)product` | checked cast |
| `catch (Exception e)` | catches every PHP `\Throwable` |
| `arr[10]` (out of range) | `IndexOutOfRangeException` |
| `Math.Round(2.5)` | 2 (banker's rounding) |
| `(int)'A'`, `'a' + 1`, `c - '0'` | `65`, `97 + 1`, `\mb_ord($c) - 48` (char is an integral type) |
| `(char)66`, `c++` | `\mb_chr(66)`, `$c = \mb_chr(\mb_ord($c) + 1)` |
| `obj.Name` when `obj` is null | `NullReferenceException` |

## Runtime library

`System` maps to `SharpPhp\Runtime\System`:

- `Console` (`WriteLine`, `Write`, `ReadLine`, composite formatting `"{0:F2}"`)
- `Math`, `Convert`, `Environment`, `Random`, `Array`
- `string` / `String` (`Join`, `Format`, `IsNullOrEmpty`, `Split`, `Substring`, `PadLeft`, `Contains`, ...), `char`, `int.Parse`/`TryParse`, `double.Parse`
- Integral types with their real ranges: `int.MaxValue`, `long.MaxValue`, `short`, `byte`, `sbyte`, `ushort`, `uint` (`Parse` throws `OverflowException` when out of range)
- `System.Collections.Generic`: `List<T>`, `Dictionary<TKey, TValue>`, `HashSet<T>`, `Queue<T>`, `Stack<T>`, `KeyValuePair`
- `System.Linq`: `Where`, `Select`, `SelectMany`, `OrderBy(Descending)`, `GroupBy`, `First(OrDefault)`, `Any`, `All`, `Count`, `Sum`, `Min`, `Max`, `Average`, `Distinct`, `Take`, `Skip`, `Aggregate`, `ToList`, `ToArray`, `ToDictionary`, `Enumerable.Range`, ... (on arrays and all collections)
- Exceptions: `Exception`, `ArgumentException`, `ArgumentNullException`, `ArgumentOutOfRangeException`, `InvalidOperationException`, `NotImplementedException`, `NotSupportedException`, `FormatException`, `IndexOutOfRangeException`, `DivideByZeroException`, `InvalidCastException`, `NullReferenceException`, `KeyNotFoundException`, ...

## Using PHP from SharpPHP

- Any PHP class is visible: global classes directly (`new DateTime()`), namespaced ones through `using`: `using GuzzleHttp;` then `new Client()`
- Global PHP functions and constants can be called directly: `strlen(s)`, `PHP_EOL`
- Generated classes are regular PHP classes, so PHP code can use them too

## Errors

Compile errors are reported in MSBuild style with a code frame:

```
src/Program.sphp(12,27): error SP0103: The name 'x' does not exist in the current context
   12 |         Console.WriteLine(x);
      |                           ^
```

Like the C# compiler, SharpPHP rejects at compile time (not at runtime):

- values that do not convert to the target type: `int a = "text";`, `if (count)`, `Sq("abc")`, `return "x";` from an `int` method, `list.Add("s")` on a `List<int>`
- wrong argument counts and unknown named arguments, for methods, constructors, `base(...)` and delegates
  (calling a .NET overload the runtime library does not implement is reported as such)
- methods, getters and lambdas where not all code paths return a value; `switch` sections that fall through
- reading a local variable before it is definitely assigned (`break`, `continue`, `return`, `try`/`finally` and
  constant conditions such as `while (true)` are taken into account); `out` parameters not assigned before returning
- `private`/`protected` members and constructors used from outside, assignments to get-only, init-only and `readonly` members, setters that are not accessible
- classes that do not implement their interfaces (with matching parameter counts) or inherited abstract members

The checks never reject valid C#, so where SharpPHP cannot be sure it accepts the code:

- a conversion is only rejected when both types are known: values of type `object`, `dynamic` or unconstrained type parameters, and untyped PHP code, are checked at runtime
- all integer types are one type to the compiler, so narrowing (`int i = someLong;`) and nullable-to-value (`int i = maybeNull;`) assignments are not reported
- assignments inside `&&`, `||` and `?:` count as definite assignments

Uncaught exceptions are printed like .NET, with locations mapped back to the `.sphp` sources:

```
Unhandled exception. System.InvalidOperationException: Queue empty.
   at App.Worker.Next() in /app/src/Worker.sphp:line 14
   at App.Program.Main() in /app/src/Program.sphp:line 8
```

## Differences from C#

SharpPHP is a C# *subset* on top of PHP's runtime. Notable differences:

- No method or constructor overloading (PHP has one method per name; names are case-insensitive)
- Generics are checked by the compiler and erased at runtime (only the built-in collections check element types); `typeof(List<int>).Name` is `List`
- Arrays (`int[]`) are PHP arrays and are copied on assignment (value semantics)
- Integer arithmetic is 64-bit and does not wrap: `int.MaxValue + 1` is `2147483648`, not `-2147483648`
- `ulong` is limited to `long.MaxValue` (there is no `ulong.MaxValue`); `decimal`/`float`/`double` are PHP floats
- `char` is a one-character string at runtime (it converts to its code where C# would use it as a number)
- Values only know their PHP type at runtime: `5L.GetType()` is `System.Int32`, while `typeof(long)` is `System.Int64`
- The runtime library implements the common .NET APIs, not all of them (no `StringComparison`, `ThenBy`, `Zip`, `DateTime.Now`, ...)
- A named argument for a `params` parameter (`string.Join(separator: ",", values: words)`) is not supported; pass it positionally
- Not supported: nested types, operator overloading, indexers, extension methods, events/delegates declarations, `async`/`await`, LINQ query syntax, tuples, `using` statements, `goto`, local functions, positional patterns (`Point(0, 0)`), passing arguments to a base record, implementing `IEnumerable<T>` in a class (derive from `List<T>` instead), explicit interface implementations, `dynamic` arguments for `params` parameters

## Development

```bash
composer install
composer test        # PHPStan (max level) + PHPUnit: lexer, diagnostics, code generation, runtime, end-to-end fixtures
composer analyse     # PHPStan only
```

End-to-end tests live in `tests/Fixtures/<name>/`: `src/*.sphp` plus the expected output.

### Architecture

```
src/
  Lexer/       source text -> tokens
  Parser/      tokens -> syntax tree (src/Syntax)
  Semantics/   types, symbols (project + PHP reflection), name resolution, and the language
               rules: Conversions, AccessRules, ArgumentMatcher
  Compiler/    Declarator (project-wide symbols), ImplementationVerifier, Compiler (pipeline), diagnostics
  CodeGen/     Emitter: syntax tree -> PHP, with type inference and source maps; FlowState
               (reachability and definite assignment)
  Runtime/     the System library and helpers used by generated code
  Project.php  cached builds and running
  Cli/         the `sharp` command
```
