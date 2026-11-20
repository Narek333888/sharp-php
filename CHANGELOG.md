# Changelog

## Unreleased

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
