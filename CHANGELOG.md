# Changelog

## Unreleased

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
