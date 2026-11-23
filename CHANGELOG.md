# Changelog

## Unreleased

- Faster collections (measured on PHP 8.4, 1M elements): `List.Add` 2.2× faster than 0.8.0 (and 0.7.1),
  `list[i]` 1.6×, `Dictionary` writes and reads 1.9×; LINQ on a `List` back to 0.7.1's speed (0.8.0 was
  1.7× slower). The element type check no longer builds a message for every element, and LINQ walks a
  list's array instead of its iterator
- `foreach` over a `List` checks for changes ("Collection was modified") with less work per element

## 0.8.0

New in the language:

- C# 14 extension blocks: `extension(string text) { public int WordCount => ...; public string Shout() => ...; }`,
  static extension members (`extension(string) { public static string Line(int n) => ...; }`, called as
  `string.Line(5)`) and generic blocks (`extension<T>(IEnumerable<T> items)`), with completion and hover
- Local functions, in methods and in top-level statements (`static int Square(int x) => x * x;`): callable
  before their declaration, recursive, generic, iterators or `async`. They are compiled to private methods,
  so they cannot use the variables around them (SP8421 says so)
- Nested object and collection initializers: `new Order { Items = { "a", "b" }, Address = { City = "Paris" } }`
- Alignment in interpolations and composite formats: `$"{name,-10}|{total,8:N2}"`, `string.Format("{0,5}", x)`
- A string is an `IEnumerable<char>`: `text.Reverse()`, `text.Count(char.IsDigit)`, `text.Distinct()`,
  `new HashSet<char>(text)`, `new List<char>("abc")`
- `ReferenceEquals(a, b)` and `Equals(a, b)` without `object.`

New in the library:

- `System.Enum`: `GetNames`, `GetValues<T>()`, `Parse<T>`, `TryParse<T>`, `IsDefined`, `GetName`
- `System.Text.RegularExpressions`: `Regex` (static and instance `IsMatch`, `Match`, `Matches`, `Replace`
  with `$1`/`${name}` or an evaluator, `Split`, `Escape`), `Match`, `Group`, `MatchCollection`, `RegexOptions`
- `System.IO`: `File`, `Path`, `Directory`, `IOException`, `FileNotFoundException`, `DirectoryNotFoundException`
- `Guid` (with `==` by value), `System.Diagnostics.Stopwatch`, `MidpointRounding` for `Math.Round`
- LINQ: `MinBy`, `MaxBy`, `DistinctBy`, `Chunk`
- `List<T>.Capacity`, `EnsureCapacity`, `TrimExcess` and `new List<T>(capacity)`, which grow as in .NET
  (the capacity is never a limit, as in C#; a negative one throws `ArgumentOutOfRangeException`)
- `new string('-', 5)`, `new string(chars)`, `new string(chars, start, length)`; `string.Concat(sequence)`;
  `StringSplitOptions` (`RemoveEmptyEntries`, `TrimEntries`) for `Split`
- `double.ToString("R")` (the shortest exact digits) and `"G5"`-style precision

Fixed:

- `Count(predicate)` on a `List`, `Dictionary`, `HashSet`, `Queue`, `Stack` or group ignored the predicate and
  returned the whole count (PHP method names ignore case, so `Countable::count()` replaced LINQ's `Count`)
- A lambda captured the value of a variable instead of the variable when only the code around it changed it:
  `Func<int> get = () => count; count = 10; get()` gave the old value (C# gives 10)
- `1.0 / 0` threw `DivideByZeroException`; it is `∞` (and `0.0 / 0` `NaN`), as in C#
- Changing a `List<T>` in a `foreach` over it now throws `InvalidOperationException` ("Collection was
  modified"), as in .NET, instead of going on with a copy
- `list.Foo()` and `list.Lenght` on the runtime library's types compiled and failed when run; they are
  compile errors now (SP1061)
- `double` and `decimal` print up to 15 significant digits (they printed 14: `1.0 / 3` is `0.333333333333333`
  now); `ToString("E3")` rounds a half away from zero, as .NET does
- `A | B` of enum values failed at run time; it is a compile error with what to do instead (SP1010)
- `new int[2] { 1, 2, 3 }` (SP0847) and `new int[-1]` (SP0248) are compile errors; a negative size computed
  at run time throws `OverflowException`
- A default interface method gave "does not implement interface member" on the class; the error is now on
  the method: default interface methods are not supported (SP8701)
- `static int F() => ...` in top-level statements gave "Invalid expression term 'static'"
- `sharp vscode` on Windows found no `code` command (it is `code.cmd`)
- README: exact PhpStorm (LSP4IJ 0.30) and VS Code steps, including PHP from Homebrew on macOS and
  restarting the language server after an upgrade; the differences from C# for structs, arrays, `decimal`,
  `[Flags]` enums and local functions

## 0.7.1

- Laravel models and Artisan commands written in SharpPHP work:
  - a field that redeclares a property of a PHP base class (`Model::$fillable`, `$table`, `$casts`,
    `Command::$signature`) keeps the PHP declaration; it was emitted with a type
    (`protected array $fillable`), which PHP refuses for Laravel's untyped properties, so loading the class
    failed. A redeclaration that is more private (SP0507) or static where PHP's is not (SP0108) is a compile
    error now instead of a PHP fatal error
  - a SharpPHP class derived from a PHP class with `__callStatic`, `__call`, `__get` or `__set` answers names
    it does not declare, as the PHP class does: `Post.create(...)`, `Post.where(...)`, `post.title`
    (SP0117/SP1061 before)
  - `List`, `Dictionary` and `HashSet` arguments of such magic calls go as PHP arrays
    (`Post.create(new Dictionary<string, object> { ... })`)
  - Artisan commands in `app/Console/Commands/*.sphp` are registered (Laravel only finds `.php` files there)

## 0.7.0

- `DateTime` and `TimeSpan`, as in .NET: `DateTime.Now`, `new DateTime(2024, 12, 31)`, the date parts,
  `AddDays`, `AddMonths`, ..., the standard and custom formats (`ToString("yyyy-MM-dd")`,
  `$"{date:dddd, MMMM d}"`), `Parse`, `TryParse`, `ParseExact`, UTC and local time; `TimeSpan.FromMinutes(90)`,
  `TotalHours`, `ToString(@"hh\:mm")`, `Parse`. With their operators: `end - start` is a `TimeSpan`,
  `date + span`, `-span`, and dates and spans compare with `<`, `>`, `==` by value. Also `DateTimeKind`
  and `DayOfWeek`
- **Changed:** `DateTime` now means `System.DateTime` (as `Exception` means `System.Exception`); for PHP's
  `\DateTime` use an alias: `using PhpDateTime = DateTime;`
- Nullable value types have `HasValue`, `Value` (`InvalidOperationException` when there is none) and
  `GetValueOrDefault()`: `int? n`, `DateTime? due`; `==` and `<` on a `DateTime?` work as C#'s lifted
  operators
- `default(T)`, new arrays and `FirstOrDefault()` give C#'s default of an enum (its member of value 0), of
  `DateTime` and `TimeSpan`, and `null` for `int?` (they gave `null`, `null` and `0`)
- Interpolated strings apply escapes in a format, as C# does: `$"{span:hh\\:mm}"` formats with `hh\:mm`
- New compile errors from C#: CS0019 for an operator a type does not define (`DateTime + DateTime`) and
  CS0023 for `-` on a type that cannot be negated (`-date`, `-person`)
- `System.Text.StringBuilder`: `Append`, `AppendLine`, `AppendFormat`, `AppendJoin`, `Insert`, `Remove`,
  `Replace`, `Clear`, `ToString`, `Length`, `Capacity` and `sb[i]`, chaining as in .NET
- `StringComparison` for `Equals`, `Compare`, `Contains`, `StartsWith`, `EndsWith`, `IndexOf`, `LastIndexOf`
  and `Replace` (`name.Equals("ann", StringComparison.OrdinalIgnoreCase)`)
- LINQ: `ThenBy` and `ThenByDescending` after `OrderBy`/`OrderByDescending` (which return an
  `IOrderedEnumerable<T>` now, as in .NET), and `Zip(second, (a, b) => ...)`
- README: on Windows, stop the language server before upgrading (it keeps `vendor/bin/sharp` open), and
  how to complete an upgrade that stopped half-way
- CI: `actions/checkout@v6` (v4 ran on the deprecated Node.js 20)

## 0.6.1

- A lambda that writes an element of a captured array (`() => names[0] = "Bob"`, `i => counts[i]++`)
  changes the array the method sees, as in C#; it changed a copy (PHP arrays are values)
- Windows:
  - unhandled exceptions show the `.sphp` lines again (`at App.Program.Main() in .../Program.sphp:line 9`)
  - sources with the same name in different folders (`src/A/Helper.sphp`, `src/B/Helper.sphp`) no longer
    overwrite each other in the build, which made one of the classes missing
  - compile errors name `src/Program.sphp` instead of the full path
  - the language server reports errors while typing: it waited for a pause in the editor's messages that
    it could not see, as `stream_select()` reports pipes as always readable on Windows
  - completion after `using System.` lists `Collections`, `Linq`, ...
- Development: the tests pass on Windows too (LF line ends in every checkout, `NUL` for `/dev/null`); the
  test of parallel `HttpClient` requests is skipped there, as PHP's built-in web server answers one request
  at a time on Windows

## 0.6.0

- Extension methods: a static method of a non-generic static class whose first parameter is declared `this`
  (`public static string Shout(this string text)`) is called as `text.Shout()`, compiled to
  `StringExtensions::Shout($text)`. Found in the file's namespace, the ones around it and the imported ones;
  instance methods win, then the most specific `this` type; generic ones (`this IEnumerable<T> items`)
  infer `T` from the receiver; `?.` and null receivers work as in C#. Errors SP1106, SP1105, SP1100 and
  SP0121 (ambiguous call). Completion after a dot lists them; hover shows `(extension)`
- Parameter info in PhpStorm (LSP4IJ), VS Code and other LSP editors: typing `(` or `,` in a call shows the
  signature of the method or constructor, e.g. `Random(int? seed = ...)`, with the argument being typed in
  bold, also for unfinished calls, named arguments, `params` and type arguments (`list.Add(` on a
  `List<int>` shows `int item`). VS Code: run `vendor/bin/sharp vscode` again to update the extension
- Writing to an array element outside the array throws `IndexOutOfRangeException`, as in C#: `arr[3] = x`
  on an array of three elements, and `arr[i] ??= x`, `arr[i] += x`, `arr[i]++`, `out arr[i]`, added the
  element instead (PHP grows arrays), so the array got longer. Reading outside an array already threw.
  PHP arrays (from PHP code) still take new keys
- Composer: `ext-ctype` is required (the compiler's lexer uses it), `ext-curl` is suggested for `HttpClient`;
  a link to the documentation and more keywords for Packagist

## 0.5.0

- Generic variance as in C#: the compiler compares generic arguments in conversions. `List<Dog>` is no
  longer accepted as a `List<Animal>` (or a `List<int>` as a `List<string>`), while covariant and
  contravariant conversions work: `IEnumerable<Dog>` and `Dog[]` to `IEnumerable<Animal>`, `Func<Dog>` to
  `Func<Animal>`, `Action<Animal>` to `Action<Dog>`, and interfaces declared `interface I<out T>` /
  `interface I<in T>`. Variance safety of interfaces is checked (SP1961), and `in`/`out` on a class or
  method type parameter is an error (SP1960). Casts between invariant generic classes with other
  arguments are errors too; delegate types are named `Func<...>` / `Action<...>` in messages
- `using` statements and declarations (`using (var r = ...) { }`, `using (expr) ...`, `using var r = ...;`),
  disposing in reverse order on every exit (SP1674 for a type that is not `IDisposable`)
- Finalizers `~Name()`, compiled to `__destruct` (the base finalizer runs after), with `GC.SuppressFinalize`
- `System.GC` (`Collect`, `GetTotalMemory`, `CollectionCount`, `SuppressFinalize`, `ReRegisterForFinalize`,
  `WaitForPendingFinalizers`, `KeepAlive`), `WeakReference<T>` / `WeakReference`, and
  `System.Runtime.CompilerServices.ConditionalWeakTable<TKey, TValue>`
- `new object()`, and the discard `out _`
- README: a **Memory** section on how PHP's memory management differs from .NET's

## 0.4.2

- Records and anonymous objects implement `JsonSerializable`: a Laravel controller that returns one sends
  JSON (an anonymous object failed with a TypeError, a record was sent as its `ToString()` text), and
  `json_encode()` writes their properties, computed ones too, positional properties first
- Completion lists a class's own members before inherited ones: a facade's methods (`Log.info`,
  `Log.error`) before `Facade`'s `spy()` and `shouldReceive()`

## 0.4.1

- Class lookup on case-sensitive file systems (Linux): looking up a name such as `date` no longer hides the
  class `Date` that a PSR-4 autoloader loads only under its exact name

## 0.4.0

- `async` / `await`: async methods and lambdas, `Task` / `Task<T>` (`Run`, `Delay`, `WhenAll`, `WhenAny`,
  `FromResult`, `CompletedTask`, `Result`, `Wait`), `static async Task Main()` and `await` in top-level
  statements, on PHP Fibers and an event loop; C#'s errors for misuse (SP4033, SP1983, SP1988, SP1997, ...)
- `System.Net.Http.HttpClient`: requests in the background (curl_multi), so several are in flight at once
- `System.Threading.Tasks` and `System.Net.Http` are imported implicitly, as in .NET
- Runtime static properties written as `get_Name()` methods: `Environment.TickCount` is a property, as in C#
- VS Code: the extension in `editors/sharpphp` now starts the language server (completion, errors while
  typing, hover, go to definition), with settings `sharpphp.php.path` / `sharpphp.server.path` and the command
  **SharpPHP: Restart Language Server**; `sharp vscode` installs it (or writes the `.vsix`)
- Laravel: the package registers itself (package discovery); `.sphp` files under `app/` are compiled and
  their classes used by routes, the container and PHP code; `php artisan sharp:build`; production uses
  the existing build; compile errors show on Laravel's error page while Artisan keeps working
- PHP classes' `@method` and `@property` docblocks (Laravel's facades, models) declare members; static calls
  to classes with `__callStatic` pass through to PHP
- A function wins over a class whose name differs only in case: `url("/")` with the `URL` facade imported
- PHPStan's refined scalar types (`int<0, max>`, `non-empty-string`, `class-string<T>`) in docblocks
- The build directory gets a `.gitignore`
- Mixing PHP and SharpPHP: `Project::autoload()` makes compiled classes loadable from PHP in one line;
  `List`, `Dictionary` and `HashSet` become PHP arrays where PHP code takes an `array` (and PHP arrays become
  them where SharpPHP expects one); compiled methods with collection parameters accept PHP arrays;
  collections implement `JsonSerializable`
- Docblock types with spaces and nesting (`array<string, List<int>>`) are read correctly

## 0.3.3

- README: a complete **PhpStorm setup** guide (highlighting, completion and errors through LSP4IJ, running
  programs, the generated PHP, troubleshooting); the LSP4IJ menu item is **Import template...**

## 0.3.2

- `editors/lsp4ij/sharpphp/README.md`, missing from 0.3.1 (the README links to it)

## 0.3.1

- README: how to upgrade (a plain `composer update` stays within a 0.x minor version:
  `composer require armdevstack/sharp-php:^0.3`)
- Step-by-step PhpStorm setup and troubleshooting for the language server in `editors/lsp4ij/sharpphp`

## 0.3.0

- Language server: `sharp lsp` gives editors completion (members after a dot, names in scope, namespaces
  after `using`), errors while typing, hover and go to definition; PhpStorm uses it through the LSP4IJ
  plugin with the template in `editors/lsp4ij/sharpphp`
- The compiler's analysis mode (`Compiler::analyze`) records what names mean and goes on after errors

## 0.2.1

- `sharp serve` (and `app.Run()` from the command line) always shows error details on the 500 page,
  also when `display_errors` is off in php.ini: it is a development server

## 0.2.0

- Web apps: `System.Web` with `WebApp` (routes with `MapGet`/`MapPost`/..., route constraints, optional and
  catch-all segments, fallback, 404/405), `HttpRequest`, `HttpResponse`, `Results` and `IResult`, in the
  style of ASP.NET Core minimal APIs; `app.Handle(request)` for tests
- `sharp serve`: serves a project with PHP's built-in web server (static files from `wwwroot/`);
  `app.Run()` from the command line does the same
- Programs work under a web server: compile errors and unhandled exceptions answer HTTP 500 with an error
  page (details while `display_errors` is on) instead of failing on the undefined `STDERR`; console output
  is sent as text/plain; `Console.ReadLine()` returns null
- Anonymous types: `new { Id = 1, user.Name }`
- `System.Text.Json.JsonSerializer.Serialize`, `System.Net.WebUtility`
- Runtime methods can type the parameters of lambdas passed to them (`@param \Closure(HttpRequest): mixed`)

## 0.1.4

- README: step-by-step quick start for an empty project (example program, `index.php`, `.gitignore`, editor setup)

## 0.1.3

- Syntax highlighting for `.sphp` files in PhpStorm (TextMate bundle) and VS Code: `editors/sharpphp`

## 0.1.2

- The package is now `armdevstack/sharp-php` (it was published as `narek/sharp-php` before):
  `composer require armdevstack/sharp-php`

## 0.1.1

- `sharp` command: read the command line from `$_SERVER['argv']`, which is set even when
  `register_argc_argv` is off
- CI runs PHP 8.4 and 8.5 independently

## 0.1.0

First release.

- C#-like language (`.sphp`) compiled to PHP 8.4+: classes, records, structs, interfaces, enums,
  generics, properties (including static ones with accessors), lambdas, LINQ, pattern matching
  (type, relational and property patterns), `switch` expressions, `with` expressions, exceptions
- C# semantics where PHP differs: integer division, `char` as an integral type, value equality for
  records, banker's rounding, `NullReferenceException`, IComparable-aware ordering
- Compile-time checks like the C# compiler: conversions, argument counts, definite assignment,
  missing returns, switch fall-through, access modifiers, read-only members, interface implementation
- Runtime library: `System`, `System.Collections.Generic`, `System.Linq`
- `sharp` command (`run`, `build`, `check`, `compile`, `clean`) and cached builds
