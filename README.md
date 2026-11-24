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

## Features

- **The C# you know:** classes, records, structs, interfaces, enums, properties, generics with constraints and
  variance, extension methods and C# 14 extension blocks, local functions, lambdas and closures, delegates and
  events, LINQ, pattern matching, `switch` expressions, exceptions with filters, `using` and `IDisposable`,
  `yield return`, `async`/`await`, `lock`: see the [Language guide](#language-guide)
- **C#'s semantics, not PHP's:** integer division, value equality of records, banker's rounding, checked casts,
  `IndexOutOfRangeException` and `NullReferenceException`: see [Semantics that match C#](#semantics-that-match-c)
- **The C# compiler's errors at compile time:** types, arguments, definite assignment, access rules, missing
  returns, with C#'s messages; exceptions are reported with `.sphp` lines: see [Errors](#errors)
- **A .NET-style library:** `Console`, `Math`, `string`, `StringBuilder`, `DateTime`, `TimeSpan`, `List`,
  `Dictionary`, `HashSet`, `Queue`, `Stack`, LINQ, `Regex`, `File`, `Path`, `Guid`, `Stopwatch`, `Task`,
  `HttpClient`, `JsonSerializer`, `GC`: see [Runtime library](#runtime-library)
- **Web apps** in the style of ASP.NET Core minimal APIs: `app.MapGet("/users/{id:int}", ...)`, see [Web apps](#web-apps)
- **Async and await** on PHP fibers, with HTTP requests running at the same time: see [Async and await](#async-and-await)
- **PHP both ways:** SharpPHP calls PHP classes, functions and Composer packages; PHP calls SharpPHP classes;
  collections and PHP arrays are converted: see [Mixing PHP and SharpPHP](#mixing-php-and-sharpphp)
- **Laravel:** controllers, services, models and jobs written in SharpPHP: see [Laravel](#laravel)
- **Editors:** PhpStorm and VS Code get highlighting, completion, parameter info, errors while typing, hover
  and go to definition: see [PhpStorm setup](#phpstorm-setup) and [VS Code setup](#vs-code-setup)
- **Command line:** cached builds, `sharp run`, `sharp serve`, `sharp check` for CI: see [Command line](#command-line)

## Contents

[Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start) ·
[Command line](#command-line) · [Web apps](#web-apps) · [PhpStorm setup](#phpstorm-setup) ·
[VS Code setup](#vs-code-setup) · [Configuration](#configuration) · [Language guide](#language-guide) ·
[Extension methods](#extension-methods) · [Runtime library](#runtime-library) · [Async and await](#async-and-await) ·
[Mixing PHP and SharpPHP](#mixing-php-and-sharpphp) · [Laravel](#laravel) · [Errors](#errors) · [Memory](#memory) ·
[Differences from C#](#differences-from-c) · [Development](#development)

## Requirements

- PHP 8.4 or newer, tested on 8.4 and 8.5 (the generated code uses property hooks and asymmetric visibility)
- `ext-mbstring` and `ext-ctype` (enabled in almost every PHP build)
- `ext-curl`, only for `HttpClient`

## Installation

In your project folder (an empty folder or one with only `index.php` is fine: Composer creates
`composer.json` and `vendor/` itself):

```bash
composer require armdevstack/sharp-php
```

### Upgrading

```bash
composer require armdevstack/sharp-php:^0.12  # a newer minor version: 0.11.x → 0.12.x
composer update armdevstack/sharp-php         # bug-fix releases: 0.12.0 → 0.12.x
```

While SharpPHP is 0.x, a plain `composer update` stays within the minor version you installed: the
constraint Composer writes for you, e.g. `^0.11.0`, means `>=0.11.0 <0.12.0`, because `^` does not cross a 0.x
minor version. Require the newer version once, as above, and `composer update` follows it from then on.
`composer show armdevstack/sharp-php` prints the installed version; [CHANGELOG.md](CHANGELOG.md) lists
what changed. The build in `var/sharp` is redone automatically after an upgrade.

**After upgrading, restart the language server** so that the editor uses the new version: in PhpStorm
**View → Tool Windows → Language Servers**, right-click **SharpPHP** → **Restart**; in VS Code, Command
Palette → **SharpPHP: Restart Language Server** (and run `vendor/bin/sharp vscode` once to update the
extension).

**On Windows, stop the language server before upgrading.** While an editor runs it, it keeps
`vendor/bin/sharp` open, Windows does not let Composer replace that file, and the upgrade stops half-way
(`Failed to open stream: Permission denied`). In PhpStorm: **View → Tool Windows → Language Servers**,
right-click **SharpPHP** → **Stop** (this also disables it); in VS Code: close the project's window.
Upgrade, then in PhpStorm right-click **SharpPHP** → **Enable and Start** (VS Code starts it when the
project is opened again). After a failed upgrade, stopping the server and running `composer install`
completes it.

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

**4. Set up your editor** for highlighting, completion and errors while typing: see [PhpStorm setup](#phpstorm-setup)
or [VS Code setup](#vs-code-setup) (two minutes, once per editor).

## Command line

| Command | Description |
|---|---|
| `sharp run [--project=DIR] [-- args]` | Build if needed and run the entry point |
| `sharp serve [--project=DIR] [--port=8000] [--host=localhost]` | Serve a web app with PHP's built-in web server |
| `sharp build [--project=DIR] [--force]` | Compile all sources to PHP |
| `sharp check [--project=DIR]` | Compile without writing files (CI) |
| `sharp compile <file.sphp>` | Print the PHP generated for one file |
| `sharp clean [--project=DIR]` | Delete the build directory |
| `sharp lsp` | The language server, for editors (LSP over stdin/stdout): see [Other editors](#other-editors) |
| `sharp vscode [--package=FILE]` | Install the VS Code extension, or only write its `.vsix`: see [VS Code setup](#vs-code-setup) |
| `sharp --version` | Print the version |

In a Laravel app, `php artisan sharp:build [--force]` builds the sources (see [Laravel](#laravel)).

## Web apps

The same program can answer HTTP requests. `System.Web` is a small web framework in the style of
ASP.NET Core minimal APIs:

```csharp
using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Web;

namespace App;

public class Program
{
    public static void Main(string[] args)
    {
        var users = new List<User> { new(1, "Ann"), new(2, "Bob") };
        var app = WebApp.Create();

        app.MapGet("/", req => "Hello from SharpPHP!");
        app.MapGet("/hello/{name}", req => Results.Html($"<h1>Hello, {WebUtility.HtmlEncode(req.Route["name"])}!</h1>"));
        app.MapGet("/users", req => users.Select(u => new { u.Id, u.Name }));
        app.MapGet("/users/{id:int}", req =>
        {
            var user = users.FirstOrDefault(u => u.Id == int.Parse(req.Route["id"]!));
            return user is null ? Results.NotFound() : Results.Json(user);
        });
        app.MapPost("/users", req =>
        {
            var name = req.Form["name"];
            if (string.IsNullOrEmpty(name))
            {
                return Results.BadRequest(new { Error = "name is required" });
            }
            return Results.Created("/users/3", new { Id = 3, Name = name });
        });

        app.Run();
    }
}

public record User(int Id, string Name);
```

**Run it:** `vendor/bin/sharp serve` (or `php index.php`, or `sharp run`: `app.Run()` started from the
command line starts the server, like `dotnet run`) and open http://localhost:8000. Sources are
recompiled on the next request after a change. Files in `wwwroot/` (CSS, images, scripts) are served as
they are; nothing else of the project is reachable from the browser.

**Routes:** `MapGet`, `MapPost`, `MapPut`, `MapPatch`, `MapDelete`, `Map` (any method), `MapFallback`.
Patterns: `/users/{id}`, constraints `{id:int}` (`int`, `long`, `bool`, `alpha`, `guid`), optional
`{year?}`, catch-all `{*path}`. Literal segments match case-insensitively; the most specific route
wins (`/users/me` beats `/users/{id}`); a path with no route for the method gets `405` with `Allow`.

**The request** (`HttpRequest req`): `Method`, `Path`, `QueryString`, `Url`, `Body`, `ContentType`, and the
read-only collections `Query`, `Form`, `Route`, `Headers`, `Cookies`. Their keys are case-insensitive and a
missing key reads as `null`: `req.Query["page"] ?? "1"`. `GetValues("tag")` returns every value of a
repeated key, `TryGetValue("q", out var q)` and `ContainsKey(...)` work as on a dictionary.

**The response:** what the handler returns.

| Handler returns | Response |
|---|---|
| a `string` | `200`, `text/plain` |
| `Results.Html(html)`, `Results.Text(...)`, `Results.Content(text, type)` | that content |
| `Results.Json(value)` or any other value (objects, anonymous objects, lists, dictionaries) | JSON with camelCase names |
| `Results.Ok()`, `Created(uri, value)`, `NoContent()`, `BadRequest()`, `Unauthorized()`, `Forbid()`, `NotFound()`, `Conflict()`, `StatusCode(n)`, `Redirect(url)` | that status (with the value as JSON when one is given) |
| nothing | an empty `200` |

Responses are immutable; add headers and cookies with `.WithHeader(name, value)`,
`.WithCookie(name, value, maxAgeSeconds: 3600)` and `.WithStatusCode(n)`. A class of your own can
implement `IResult` (`HttpResponse ToResponse()`).

**Testing:** `app.Handle(new HttpRequest("GET", "/users/2"))` returns the `HttpResponse`
(`StatusCode`, `Body`, `Headers`, `ContentType`) without a server.

**In production** point the web server at the project and send every request that is not a file in
`wwwroot/` to `index.php` (PHP-FPM, Apache or nginx), e.g. for nginx:

```nginx
root /var/www/app/wwwroot;
location / { try_files $uri /index.php$is_args$args; }
location = /index.php { fastcgi_pass unix:/run/php/php-fpm.sock; include fastcgi_params;
                        fastcgi_param SCRIPT_FILENAME /var/www/app/index.php; }
```

**Errors:** an unhandled exception or a compile error answers `500` with a page that shows the error and
the `.sphp` line while PHP's `display_errors` is on (development), and a generic page when it is off
(production). The details always go to the server log. `Console.WriteLine` in a handler writes to the
server's terminal or log, not to the page, as in ASP.NET Core; a console program (no `WebApp`) opened in
a browser shows its output as plain text.

## PhpStorm setup

PhpStorm (and every other JetBrains IDE) gets highlighting, completion, parameter info, errors while typing,
hover and go to definition for `.sphp` files. Setting it up takes about two minutes and is done **once per IDE**: it
then works in every project that requires `armdevstack/sharp-php` (parameter info needs 0.6 or newer:
`composer show armdevstack/sharp-php` prints the version, [Upgrading](#upgrading) tells how to update).
Run `composer require armdevstack/sharp-php` first: both steps use files of the installed package.

### 1. Syntax highlighting

1. **Settings** (macOS: **PhpStorm → Settings...**, ⌘,; Windows/Linux: **File → Settings...**, Ctrl+Alt+S)
   **→ Editor → TextMate Bundles**, click **+**.
2. Choose the folder `vendor/armdevstack/sharp-php/editors/sharpphp` of your project and click **OK**, then
   **OK** in Settings.

Keywords, types, strings, numbers and comments in `.sphp` files are now colored.

### 2. Completion, parameter info, errors while typing, hover, go to definition

These come from the SharpPHP language server (`vendor/bin/sharp lsp`) through the free
[LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) plugin by Red Hat (these steps are checked
with LSP4IJ 0.30):

1. **Settings → Plugins → Marketplace**: search **LSP4IJ**, click **Install** (restart PhpStorm if it asks).
2. **Settings → Languages & Frameworks → Language Servers (LSP4IJ)**, click **+**.
3. The **New Language Server** dialog opens. Next to **Template:**, click the link **Import template...**,
   select the folder `vendor/armdevstack/sharp-php/editors/lsp4ij/sharpphp` of your project and confirm.
   The dialog is now filled in: name **SharpPHP**, **Command:** `php $PROJECT_DIR$/vendor/bin/sharp lsp`,
   and on the **Mappings** tab `*.sphp` files with the language id `sharpphp`.
4. **macOS with Homebrew PHP:** PhpStorm started from the Dock or Finder does not see Homebrew's
   `/opt/homebrew/bin`, so replace `php` in **Command:** with the full path that `which php` prints in a
   terminal, e.g. `/opt/homebrew/bin/php $PROJECT_DIR$/vendor/bin/sharp lsp`. (Elsewhere, if `php` is not
   on the `PATH`, do the same with its full path, e.g. `C:\php\php.exe $PROJECT_DIR$/vendor/bin/sharp lsp`.)
5. Click **OK**, then **OK** in Settings.

**Check it:** open a `.sphp` file, type `Console.` inside `Main`: `WriteLine`, `Write`, `ReadLine` are listed.
**View → Tool Windows → Language Servers** shows the SharpPHP server running. The server belongs to the IDE,
not to one project: other projects that require SharpPHP use it too, each with its own `vendor/bin/sharp`.

What you get:

| Feature | Example |
|---|---|
| Members after a dot, also in unfinished code | `user.` → `Name`, `Age`, `Greet()`; `Person.` → static members; `names.` → `Add`, `Count`, `Where`, ... |
| Names in scope | local variables, parameters, members of the class, types, keywords |
| Namespaces | `using System.` → `Collections`, `Linq`, `Web`, ... |
| Parameter info | `new Random(` → `Random(int? seed = ...)`, `title.PadLeft(5, ` → `string string.PadLeft(int totalWidth, string paddingChar = ...)` with the argument being typed in bold; shown at `(` and `,`, and with ⌘P / Ctrl+P |
| Errors while typing | the same errors as `sharp build`, underlined where they are |
| Hover | `(property) string Person.Name`, `(method) string Person.Greet(string greeting)` |
| Go to definition | ⌘-click / Ctrl-click a name: its declaration, also in another file |

### 3. Running programs

- **Console programs:** right-click `index.php` → **Run 'index.php'** (or the green ▶). PhpStorm needs a
  PHP interpreter for this: **Settings → PHP → CLI Interpreter**. In the terminal: `vendor/bin/sharp run`.
- **Web apps** ([Web apps](#web-apps)): run `vendor/bin/sharp serve` in the terminal (or `index.php` as above)
  and open http://localhost:8000.
- Errors (compile errors and exceptions) name the `.sphp` file and line.

### 4. Optional: the generated PHP

The compiled PHP goes to `var/sharp`. If your PHP code uses classes written in SharpPHP, keep the folder
indexed: PhpStorm then completes those classes in `.php` files too. Otherwise right-click `var/sharp` →
**Mark Directory as → Excluded**, so that searches show only your sources.

### Troubleshooting

| Problem | What to do |
|---|---|
| `.sphp` files are plain text | Remove `*.sphp` under **Settings → Editor → File Types → Text**, if it is there; check step 1 |
| No completion and no error marks | Check that LSP4IJ is installed (**Settings → Plugins → Installed**) and step 2 was done. The project must require SharpPHP: `composer require armdevstack/sharp-php`. Restarting PhpStorm alone does not turn completion on |
| The **Language Servers** window shows that SharpPHP cannot start | PhpStorm did not find `php` (common on macOS, where an IDE started from the Dock does not see Homebrew), or found a PHP older than 8.4. In **Settings → Languages & Frameworks → Language Servers (LSP4IJ) → SharpPHP**, set **Command:** to the full path of PHP 8.4+: `/opt/homebrew/bin/php $PROJECT_DIR$/vendor/bin/sharp lsp` (`which php` in a terminal prints the path, `php -v` its version) |
| Completion shows old members after `composer update` | The server still runs the old version: **View → Tool Windows → Language Servers**, right-click **SharpPHP** → **Restart** |
| The server was stopped (or disabled after failed starts) | **View → Tool Windows → Language Servers**, right-click **SharpPHP** → **Enable and Start** |
| You want to see what the server does | **View → Tool Windows → Language Servers** → SharpPHP: the **Logs** tab; to see every request, set **Trace:** to `verbose` on the server's **Debug** tab (in Settings), then look at the **Traces** tab |
| No completion after a dot | The file has a syntax error somewhere else: fix it first (it is underlined) |
| `composer update` fails on Windows with `vendor\bin/sharp): Failed to open stream: Permission denied` | The running language server keeps that file open: stop it (**Language Servers** → right-click **SharpPHP** → **Stop**), run `composer install`, then open a `.sphp` file again (see [Upgrading](#upgrading)) |

## VS Code setup

VS Code gets the same features through the SharpPHP extension, which ships with the package: highlighting,
completion, parameter info, errors while typing (also in the **Problems** panel), hover and go to definition
(F12). It is installed once and works in every project that requires SharpPHP.

1. In a terminal, in the project folder: `vendor/bin/sharp vscode`. It installs the extension with VS Code's
   `code` command (without it: `vendor/bin/sharp vscode --package=sharpphp.vsix`, then
   **Extensions → ⋯ → Install from VSIX...**).
2. Reload VS Code: Command Palette (⇧⌘P / Ctrl+Shift+P) → **Developer: Reload Window**.
3. Open the project folder (the one with `vendor/`) and trust it when VS Code asks: in Restricted Mode only
   highlighting works, because the language server runs the project's PHP.

**Check it:** open a `.sphp` file, type `Console.` inside `Main`: `WriteLine`, `Write`, `ReadLine` are listed.
**View → Output → SharpPHP** shows `Language server running`.

| Problem | What to do |
|---|---|
| "PHP was not found" | Set **Settings → Extensions → SharpPHP → Php: Path** (`sharpphp.php.path`) to the full path of PHP 8.4+, e.g. `/opt/homebrew/bin/php` (`which php` prints it; on Windows `where php`, e.g. `C:\php\php.exe`) |
| `code` is not found by `sharp vscode` | In VS Code: Command Palette → **Shell Command: Install 'code' command in PATH** (macOS), or use `--package=sharpphp.vsix` and **Install from VSIX...** as in step 1 |
| No completion and no error marks | **View → Output → SharpPHP** says why: no `vendor/bin/sharp` (`composer require armdevstack/sharp-php`) or an untrusted workspace |
| Anything else | Command Palette → **SharpPHP: Restart Language Server**; details in [editors/sharpphp](editors/sharpphp/README.md#troubleshooting) |

After upgrading SharpPHP, run `vendor/bin/sharp vscode` again to update the extension, and
**SharpPHP: Restart Language Server** (or reload the window) so that the server runs the new version.

### Other editors

Any editor with an LSP client (Neovim, Sublime Text, Zed, Helix, ...) can run `php vendor/bin/sharp lsp`
for `*.sphp` files, language id `sharpphp`. The TextMate grammar for highlighting is
`editors/sharpphp/syntaxes/sharpphp.tmLanguage.json`.

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
- `System`, `System.Collections.Generic`, `System.Linq`, `System.Threading.Tasks` and `System.Net.Http` are imported implicitly (as in .NET)
- Entry point: a `static void Main()` / `static int Main(string[] args)` method, or top-level statements (the returned `int` becomes the exit code)

### Types

- `class`, `abstract`, `sealed`, `static class`, `interface`, `enum`, `record`, `struct` (compiled as a final class, so it has reference semantics)
- Single inheritance plus interfaces: `class Book : Product, IComparable<Book>`
- Constructors with `: base(...)`, expression-bodied constructors, static constructors (run at the class's first use, as in C#)
- Inheritance as in C#: `override` overrides a `virtual` or `abstract` method (else CS0506), and a method that has the name of a base method without `override` (or with `new`) hides it: a call picks by the static type, `((Base)obj).M()` calls `Base.M`
- Fields, `const`, `readonly`, `static`
- Integral types as in C#: `byte`, `sbyte`, `short`, `ushort`, `int`, `uint`, `long`, `ulong` (and `char`) are
  checked by the compiler: a constant must fit (`byte b = 300;` is CS0031), narrowing needs a cast
  (`byte z = x + y;` is CS0266, as `byte + byte` is an `int`), arithmetic promotes (`int + long` is a `long`,
  `uint + int` a `long`), and literals have C#'s types (`10L`, `10u`, `3000000000` is a `uint`). A cast cuts the
  value as C# does without overflow checks (`(byte)300` of a variable is 44, `(int)` of a long keeps 32 bits), and
  so do `+=`, `++` and `--` on `byte`, `sbyte`, `short`, `ushort` and `uint` (`byte` 255 + 1 is 0)
- Delegate types: `delegate int Transform(int x);` (also generic, and declared inside a class), used like `Func<...>`:
  lambdas, method groups, `new Transform(Square)`, `t(5)`, `t.Invoke(5)`. Delegates are multicast:
  `handler += OnSave; handler -= OnSave;` (`Delegate.Combine`/`Remove`; a combined delegate calls each in turn and
  returns the last result), `GetInvocationList()`
- Events: `public event Action<string>? Changed;` and `event EventHandler<T>`; code outside the class can only
  `+=` and `-=` them (CS0070), the class raises them with `Changed?.Invoke("x")`
- Properties: auto (`{ get; set; }`, `{ get; private set; }`, `{ get; init; }`, get-only), with initializers, expression-bodied (`=> ...`), full `get { } set { }` with `value`, abstract and interface properties, static properties (with accessor bodies they become `get_Name()`/`set_Name()` methods)
- Methods: `virtual` / `override` / `abstract` (an override may return a more derived type), expression bodies, default and named arguments, `params`, `ref`, `out` (incl. `out var x` and the discard `out _`), `in`
- Extension methods and C# 14 extension blocks (`extension(string s) { ... }`, with extension properties and static extension members such as `string.Repeat(...)`), see [Extension methods](#extension-methods)
- Local functions: `static int Square(int x) => x * x;` inside a method or in top-level statements, callable before their declaration, recursive, generic, iterators or `async`. They are compiled to private methods of the class, so they cannot use the local variables of the code around them (SP8421: pass them as parameters, or use a lambda); in an instance method they may use `this` and the class's members
- Finalizers `~Name() { ... }`, which run when the object is freed (see [Memory](#memory))
- Records: `record Point(int X, int Y);` with value equality (`==`, `Equals` and `GetHashCode` by every field, so records work as `Dictionary` keys and `HashSet` items), C#-style `ToString()` (every public property) and `with` expressions (`p with { X = 5 }`); structs are equal by their fields too
- Enums: `enum Color { Red, Green = 5 }`, `(int)color`, `(Color)1`, `color.ToString()`, and `System.Enum`: `Enum.GetNames(typeof(Color))`, `Enum.GetValues<Color>()`, `Enum.Parse<Color>("Green")` (also `ignoreCase: true`), `Enum.TryParse<Color>(text, out var c)`, `Enum.IsDefined(typeof(Color), 2)`, `Enum.GetName(...)`. `[Flags]` combinations (`A | B`) do not exist: see [Differences from C#](#differences-from-c)
- Nullable value types: `int?`, `Color?`, `DateTime?` with `HasValue`, `Value` (`InvalidOperationException` when there is none) and `GetValueOrDefault()`; `default(T)`, new arrays and `FirstOrDefault()` give C#'s defaults (`0`, `false`, an enum's member of value 0, `DateTime.MinValue`, `null` for `int?`)

### Generics

- Generic classes, interfaces and methods: `class Box<T>`, `interface IRepository<T>`, `T Max<T>(T a, T b)`
- Constraints: `where T : IShape, new()` — a class/interface constraint becomes the PHP parameter type
- Variance as in C#: `List<Dog>` is not a `List<Animal>` (classes are invariant), while `IEnumerable<Dog>`, `Dog[]` and `Func<Dog>` are an `IEnumerable<Animal>`, an `Animal[]` and a `Func<Animal>`, and an `Action<Animal>` is an `Action<Dog>`. Interfaces declare it: `interface IProducer<out T>`, `interface IConsumer<in T>`; the compiler checks that `out T` is only produced and `in T` only consumed (SP1961)
- Type inference for generic methods, including through lambdas: `box.Map(x => x * 2)` is a `Box<int>`
- The compiler tracks generic arguments, so `List<string>` elements are known to be strings (`names[0].Length`)
- Runtime checks: `new List<int>()` rejects non-integers, like C# (`ArgumentException`)
- Implementing a generic interface with a concrete type (`IRepository<User>`) is supported

### Extension methods

```csharp
namespace App.Extensions;

public static class StringExtensions
{
    public static string Shout(this string text) => text.ToUpper() + "!";
}

public static class SequenceExtensions
{
    public static T Second<T>(this IEnumerable<T> items) => items.Skip(1).First();
}
```

```csharp
using App.Extensions;

"hi".Shout();                              // "HI!": compiled to StringExtensions::Shout('hi')
new List<string> { "a", "b" }.Second();    // "b", a string: T comes from the receiver
```

- Found as in C#: in the file's namespace, the namespaces around it, and the imported ones (`using App.Extensions;`)
- A method of the receiver's own type wins over an extension method of the same name. Among extension methods the most specific `this` type wins (`this string` before `this object`); two equally specific ones make the call ambiguous (SP0121)
- Strings, numbers, arrays, collections, classes, interfaces and type parameters can be extended. A `null` receiver is passed to the method (which can check it); `text?.Shout()` skips the call
- The static form works too: `StringExtensions.Shout("hi")`, and PHP code calls `StringExtensions::Shout('hi')`
- Only in a non-generic `static class`, as a `static` method, with `this` on the first parameter (SP1106, SP1105, SP1100)
- Completion after a dot lists the extension methods that apply; parameter info and hover show them as C# does

**Extension blocks (C# 14)** add extension properties and static extension members, in the same kind of
`static class`:

```csharp
public static class TextExtensions
{
    extension(string text)                  // members of a string, which they name "text"
    {
        public int WordCount => text.Split(' ', StringSplitOptions.RemoveEmptyEntries).Length;
        public string Shout() => text.ToUpper() + "!";
    }

    extension(string)                       // static members: called on the type
    {
        public static string Line(int width) => new string('-', width);
    }

    extension<T>(IEnumerable<T> items)      // generic: T comes from the receiver
    {
        public bool IsEmpty => !items.Any();
    }
}
```

```csharp
using App.Extensions;

Console.WriteLine("the quick brown fox".WordCount);   // 4
Console.WriteLine("hi".Shout());                      // HI!
Console.WriteLine(string.Line(5));                    // -----
Console.WriteLine(new List<int>().IsEmpty);           // True
```

Extension properties are read-only (a property with `set` or `init`, or an auto-property, is an error), and
only methods and properties can be declared in a block.

### Statements and expressions

- `if`/`else`, `while`, `do`, `for`, `foreach` (incl. `foreach (var (key, value) in dict)`), `break`, `continue`
- `switch` statements (with patterns and `when`), `switch` expressions
- `try`/`catch`/`finally`, exception filters (`catch (E e) when (...)`), `throw;`, throw expressions (`x ?? throw ...`)
- `using (var file = Open()) { ... }`, `using (expression) ...` and `using var file = Open();` (disposed at the end of the block): `Dispose()` runs however the block is left, in reverse order for several resources
- `yield return` / `yield break` with `IEnumerable<T>`: as in C#, the method's body runs when the result is enumerated, again
  on every `foreach`, and only as far as it is read, so a sequence can be endless (`Naturals().Take(5)`)
- `lock (gate) { ... }`: SharpPHP code runs on one thread, so a lock is always free; C#'s rules hold (the gate is an
  object, not `null`; no `await` inside, CS1996)
- Lambdas `x => x * 2`, `(a, b) => { ... }`, method groups (`list.ForEach(Print)`), closures with C#'s capture semantics: a lambda sees the variable, not a copy (`Func<int> get = () => count; count = 10; get()` is 10), a `for` loop's variable is one variable for all iterations, and a variable declared in a loop body is a new one in every iteration
- A string is an `IEnumerable<char>`: `foreach (char c in text)`, `text.Reverse()`, `text.Count(char.IsDigit)`, `text.Distinct()`, `new HashSet<char>(text)`; `string`'s own methods (`Contains`, `IndexOf`, ...) win over LINQ's
- Pattern matching: `is T x`, `is null`, `is not null`, `is > 0 and < 10`, `or`, `not`, `var`, `_`, property patterns (`Circle { R: > 10 } c`, `{ Name: var n, Age: >= 18 }`, `{ Home.City: "Paris" }`)
- String interpolation with format specifiers and alignment: `$"{price:F2}"`, `$"{name,-10}|{total,8:N2}"` (also in `string.Format("{0,5}", x)`); verbatim strings `@"C:\path"`, `$@"..."`
- Nullable value types with C#'s lifted operators: with a null operand, `a + b` and `-a` are null and `a < b` is false (also in `+=` and `++`); `bool?`'s `&` and `|` are three-valued (`null & false` is false)
- `obj?.Total = 5` (C# 14): assigns, and evaluates `5`, only when `obj` is not null
- Caller information: `[CallerMemberName]`, `[CallerLineNumber]`, `[CallerFilePath]`, `[CallerArgumentExpression("value")]` parameters get what C# passes (`ArgumentNullException.ThrowIfNull(x)` names `x`)
- Exception filters as in C#: `catch (E e) when (...)` clauses are tried in order, the next one when a filter is false
- `?.`, `?[]`, `??`, `??=`, `is`, `as`, casts, `typeof` / `GetType()` (a `System.Type` with `Name`, `FullName`, `Namespace`), `nameof`, `default`
- Object and collection initializers: `new User { Name = "Ann" }`, `new Dictionary<string, int> { ["a"] = 1 }`, `new()`, `[1, 2, 3]`, and nested ones, which fill the object a property already holds: `new Order { Items = { "a", "b" }, Address = { City = "Paris" } }`
- Anonymous types: `new { Id = 1, user.Name }`, with read-only typed properties, value equality and C#'s `ToString()` (`{ Id = 1, Name = Ann }`)

### Semantics that match C#

| C# | Generated PHP |
|---|---|
| `7 / 2` (ints) | `\intdiv(7, 2)` → 3 |
| `7.0 / 2`, `1.0 / 0` | `\fdiv(7.0, 2)` → 3.5, `∞` (`0.0 / 0` is `NaN`; an integer division by zero throws `DivideByZeroException`) |
| `"a" + 1` | `'a' . 1` |
| `a == b` | `$a === $b` (records compare by value) |
| `true.ToString()` | `"True"` |
| `(int)obj` | checked unboxing (`InvalidCastException`) |
| `(Laptop)product` | checked cast |
| `catch (Exception e)` | catches every PHP `\Throwable` |
| `arr[10]`, `arr[10] = x` (out of range) | `IndexOutOfRangeException` (PHP would add the element) |
| `Math.Round(2.5)` | 2 (banker's rounding) |
| `(int)'A'`, `'a' + 1`, `c - '0'` | `65`, `97 + 1`, `\mb_ord($c) - 48` (char is an integral type) |
| `(char)66`, `c++` | `\mb_chr(66)`, `$c = \mb_chr(\mb_ord($c) + 1)` |
| `obj.Name` when `obj` is null | `NullReferenceException` |
| `end - start`, `date + span` (`DateTime`, `TimeSpan`) | `DateTime::op_Subtraction($end, $start)` (a `TimeSpan`), `DateTime::op_Addition($date, $span)` |
| `a < b`, `a == b` (dates, spans) | `DateTime::Compare($a, $b) < 0`, `DateTime::Compare($a, $b) == 0` (by value) |
| `n.HasValue`, `n.Value` (`int? n`) | `$n !== null`, `$n ?? throw new InvalidOperationException(...)` |
| changing a `List<T>` in a `foreach` over it | `InvalidOperationException` ("Collection was modified") at the next step of the loop |
| `list.Foo()`, `list.Lenght` (not a member) | a compile error (SP1061), also for the runtime library's types |

## Runtime library

`System` maps to `SharpPhp\Runtime\System`, and works as in .NET. `System`, `System.Collections.Generic`,
`System.Linq`, `System.Threading.Tasks` and `System.Net.Http` are imported implicitly; the other namespaces
below need a `using`.

| Type | Members |
|---|---|
| `Console` | `WriteLine`, `Write`, `ReadLine`; composite formatting: `Console.WriteLine("{0} costs {1:F2}", name, price)` |
| `Math` | `Abs`, `Atan2`, `Cbrt`, `Ceiling`, `Clamp`, `Cos`, `Exp`, `Floor`, `Log`, `Log10`, `Log2`, `Max`, `Min`, `Pow`, `Round` (banker's rounding; `Round(x, digits)`, `Round(x, MidpointRounding.AwayFromZero)`, `Round(x, digits, mode)`), `Sign`, `Sin`, `Sqrt`, `Tan`, `Truncate`; `PI`, `E`, `Tau` |
| `string` / `String` | `Length`, `s[i]`; `Compare`, `CompareTo`, `Concat`, `Contains`, `EndsWith`, `Equals`, `Format`, `IndexOf`, `Insert`, `IsNullOrEmpty`, `IsNullOrWhiteSpace`, `Join`, `LastIndexOf`, `PadLeft`, `PadRight`, `Remove`, `Replace`, `Split`, `StartsWith`, `Substring`, `ToCharArray`, `ToLower`, `ToLowerInvariant`, `ToUpper`, `ToUpperInvariant`, `Trim`, `TrimEnd`, `TrimStart`; `string.Empty`; `new string('-', 5)`, `new string(chars)`, `new string(chars, start, length)`; `Split(separators, StringSplitOptions.RemoveEmptyEntries)` (also `TrimEntries`); `string.Concat(sequence)`; LINQ over the chars (`text.Reverse()`). `Equals`, `Compare`, `Contains`, `StartsWith`, `EndsWith`, `IndexOf`, `LastIndexOf` and `Replace` take a `StringComparison` (`OrdinalIgnoreCase`, ...): the IgnoreCase kinds ignore case, and as SharpPHP has no cultures the culture kinds compare as `Ordinal` |
| `StringBuilder` (`System.Text`) | `Append`, `AppendLine`, `AppendFormat`, `AppendJoin`, `Insert`, `Remove`, `Replace`, `Clear`, `ToString`, `Length` (settable), `Capacity`, `sb[i]`; the changing methods return the builder, so calls chain |
| `char` | `IsDigit`, `IsLetter`, `IsLetterOrDigit`, `IsLower`, `IsUpper`, `IsWhiteSpace`, `ToLower`, `ToUpper`; a `char` is a number in arithmetic (`c - '0'`) |
| `int`, `long`, `short`, `byte`, `sbyte`, `ushort`, `uint`, `ulong` | `Parse`, `TryParse` (`OverflowException` out of range), `MaxValue`, `MinValue`, `CompareTo`, `ToString("D3")` |
| `double`, `float`, `decimal` | `Parse` and `TryParse` (as .NET: `"1,234.5"`, `"NaN"`, `"∞"`; decimal without an exponent), `IsNaN`, `IsInfinity`, `NaN`, `PositiveInfinity`, `NegativeInfinity`, `Epsilon`, `MaxValue`, `MinValue` (float's are float's); `ToString()` as .NET Core writes it: a double with the shortest digits that read back as it (`0.1 + 0.2` is `0.30000000000000004`), a float with its single-precision digits (`1f / 3` is `0.33333334`), a decimal with up to 15 significant digits (`0.1m + 0.2m` is `0.3`, see [Differences](#differences-from-c)) |
| Format strings (`ToString(format)`, `$"{x:format}"`, `string.Format`) | .NET's standard formats `B`, `C`, `D`, `E`, `F`, `G`, `N`, `P`, `R`, `X` with a precision (a double rounds half to even, as .NET Core: `2.5.ToString("F0")` is `2`; a decimal and an integer half away from zero: `2.5m` is `3`), and custom formats: `0`, `#`, `.`, `,` (groups, and scaling: `"#,##0,,"`), `%`, `‰`, `E+0`/`e-00`, `'literal'`, `\x`, sections `"0.0;(0.0);zero"` (from 15 significant digits of a double, 7 of a float, as .NET); en-US: `$1,234.50`, `-$5.00`, `25.6%`. A number keeps its C# type in `string.Format`, `Console.WriteLine("{0}", x)`, `string.Join`, `string.Concat`, `StringBuilder.Append`/`AppendFormat`/`AppendJoin`, `TextWriter.Write` and `Convert.ToString`, and in anonymous types' `ToString()` |
| `bool` | `Parse`, `TryParse`; `ToString()` gives `True` / `False` |
| `DateTime` | `Now`, `UtcNow`, `Today`, `MinValue`, `MaxValue`, `UnixEpoch`; `new DateTime(2024, 12, 31[, 18, 30, 0[, 500]][, DateTimeKind.Utc])`, `new DateTime(ticks)`; `Year`, `Month`, `Day`, `Hour`, `Minute`, `Second`, `Millisecond`, `Microsecond`, `DayOfWeek`, `DayOfYear`, `Date`, `TimeOfDay`, `Ticks`, `Kind`; `Add`, `AddYears`, `AddMonths` (Jan 31 + 1 month is the end of February), `AddDays`, `AddHours`, `AddMinutes`, `AddSeconds`, `AddMilliseconds`, `AddTicks`, `Subtract`, `ToUniversalTime`, `ToLocalTime`, `SpecifyKind`, `Compare`, `CompareTo`, `Equals`, `DaysInMonth`, `IsLeapYear`; `ToString()` with the standard formats (`d`, `D`, `f`, `F`, `g`, `G`, `M`, `o`, `R`, `s`, `t`, `T`, `u`, `U`, `Y`) and custom ones (`yyyy-MM-dd HH:mm:ss.fff`, `ddd`, `dddd`, `MMM`, `MMMM`, `h`, `tt`, `zzz`, `K`, `'literal'`), also `$"{date:yyyy-MM-dd}"`; `ToShortDateString`, `ToLongDateString`, `ToShortTimeString`, `ToLongTimeString`; `Parse`, `TryParse`, `ParseExact`; `date - date` (a `TimeSpan`), `date + span`, `date - span`, `+=`, `-=`, `<`, `>`, `<=`, `>=`, `==`, `!=`; JSON as ISO 8601 (`"2024-01-15T09:30:00"`); `DateTimeKind`, `DayOfWeek` |
| `TimeSpan` | `new TimeSpan(h, m, s)`, `(d, h, m, s[, ms])`, `(ticks)`; `FromDays`, `FromHours`, `FromMinutes`, `FromSeconds`, `FromMilliseconds`, `FromTicks`, `Zero`, `MaxValue`, `MinValue`; `Days`, `Hours`, `Minutes`, `Seconds`, `Milliseconds`, `Microseconds`, `Ticks`, `TotalDays`, `TotalHours`, `TotalMinutes`, `TotalSeconds`, `TotalMilliseconds`; `Add`, `Subtract`, `Negate`, `Duration`, `Multiply`, `Divide`, `Compare`, `CompareTo`, `Equals`; `ToString()` (`c`, `g`, `G`, custom `hh\:mm\:ss`, `d\.hh\:mm`), `Parse`, `TryParse`; `+`, `-`, `-span`, comparisons; `OverflowException` beyond its range |
| `Convert` | `ToBoolean`, `ToDouble`, `ToString`, `ToChar`, `ToInt32`, `ToInt64`, `ToInt16`, `ToByte`, `ToSByte`, `ToUInt16`, `ToUInt32`, `ToUInt64` (doubles rounded to even, `OverflowException` out of range, `Convert.ToInt32('A')` is 65) |
| `Environment` | `NewLine`, `Exit`, `GetEnvironmentVariable` |
| `Random` | `Next`, `NextDouble`; `new Random(seed)` gives the numbers .NET's `new Random(seed)` gives |
| `Array` | `Empty`, `Exists`, `Fill`, `Find`, `IndexOf`, `Resize`, `Reverse`, `Sort`; arrays have `Length` and the LINQ methods |
| `Type` (`typeof(T)`, `value.GetType()`) | `Name`, `FullName`, `Namespace` |
| `Enum` | `GetNames`, `GetValues<TEnum>()`, `GetName`, `IsDefined`, `Parse<TEnum>(text[, ignoreCase])`, `Parse(typeof(TEnum), text)`, `TryParse<TEnum>(text, out var value)` |
| `Guid` | `NewGuid`, `Parse`, `TryParse`, `Empty`, `ToString()` / `ToString("N")` (`"B"`, `"P"`), `==` and `!=` by value, `Equals`, `CompareTo`; JSON as its text |
| `List<T>` | `Count`, `list[i]`, `Capacity` (grows as in .NET; it is never a limit), `Add`, `AddRange`, `Clear`, `Contains`, `EnsureCapacity`, `Exists`, `Find`, `FindAll`, `FindIndex`, `ForEach`, `GetRange`, `IndexOf`, `Insert`, `Remove`, `RemoveAll`, `RemoveAt`, `Reverse`, `Sort`, `ToArray`, `TrimExcess`, `TrueForAll`; `new List<T>(capacity)`, `new List<T>(collection)` |
| `Dictionary<TKey, TValue>` | `Count`, `Keys`, `Values`, `dict[key]` (`KeyNotFoundException` when missing), `Add`, `Clear`, `ContainsKey`, `ContainsValue`, `GetValueOrDefault`, `Remove`, `TryAdd`, `TryGetValue`; `foreach (var (key, value) in dict)`, `KeyValuePair` |
| `HashSet<T>` | `Count`, `Add`, `Clear`, `Contains`, `ExceptWith`, `IntersectWith`, `Remove`, `UnionWith`; like `Dictionary` keys, items are equal as .NET's default comparer sees them: numbers and strings by value, records, structs, `DateTime`, `TimeSpan`, `Guid` and classes overriding `Equals`/`GetHashCode` by those, other objects by reference |
| `IEnumerable<T>`, `ICollection<T>`, `IList<T>`, `IReadOnlyCollection<T>`, `IReadOnlyList<T>` | what each has in .NET: `IEnumerable<T>` only LINQ (`Count()`), the collections `Count`, the lists `list[i]` (read-only for `IReadOnlyList`), `ICollection<T>` `Add`, `Remove`, `Clear`, `Contains`, `IList<T>` also `IndexOf`, `Insert`, `RemoveAt`; a `List` is all of them, `HashSet` and `Dictionary` an `ICollection`, `Queue` and `Stack` an `IReadOnlyCollection`, an array an `IList` of a fixed size (`Add` throws `NotSupportedException`) |
| `Queue<T>` / `Stack<T>` | `Count`, `Enqueue`, `Dequeue`, `TryDequeue`, `Peek`, `Clear` / `Count`, `Push`, `Pop`, `Peek`, `Clear` |
| LINQ, on arrays, collections and strings | `Aggregate`, `All`, `Any`, `Average`, `Chunk`, `Concat`, `Contains`, `Count`, `Distinct`, `DistinctBy`, `ElementAt`, `First`, `FirstOrDefault`, `GroupBy`, `Last`, `LastOrDefault`, `Max`, `MaxBy`, `Min`, `MinBy`, `OrderBy`, `OrderByDescending` (then `ThenBy`, `ThenByDescending`), `Reverse`, `Select`, `SelectMany`, `SequenceEqual`, `Single`, `Skip`, `SkipWhile`, `Sum`, `Take`, `TakeWhile`, `ToArray`, `ToDictionary`, `ToList`, `Where`, `Zip` (with a result selector); `Enumerable.Range`, `Enumerable.Repeat`; deferred, as in .NET: `Where`, `Select`, `Take`, `OrderBy`, `GroupBy`, ... return an `IEnumerable<T>` that reads its source when it is enumerated (and again each time, seeing the source's changes), and `ToList()`, `ToArray()`, `Count()`, `Sum()`, `First()`, ... run it |
| `Task`, `Task<T>` | `Run(func[, token])`, `Delay(ms[, token])`, `WhenAll`, `WhenAny`, `FromResult`, `FromException`, `CompletedTask`; `Result` and `Wait()` (a failed task throws an `AggregateException`, `await` the exception itself, as in .NET), `IsCompleted`, `IsCompletedSuccessfully`, `IsFaulted`, `IsCanceled`, `Exception` (an `AggregateException`): see [Async and await](#async-and-await) |
| Cancellation (`System.Threading`) | `CancellationTokenSource` (`Token`, `Cancel`, `CancelAfter`, `IsCancellationRequested`, `CreateLinkedTokenSource`, `Dispose`, `new CancellationTokenSource(ms)`), `CancellationToken` (`IsCancellationRequested`, `ThrowIfCancellationRequested`, `Register`, `CanBeCanceled`, `None`, `default`), `Timeout.Infinite`; a canceled `Task.Delay` throws `TaskCanceledException` |
| Threads (`System.Threading`) | `Thread` (`new Thread(() => ...)`, `Start`, `Join`, `IsAlive`, `Name`, `ManagedThreadId`, `Thread.Sleep`, `Thread.CurrentThread`), `Interlocked` (`Increment`, `Decrement`, `Add`, `Exchange`, `CompareExchange`), `Parallel` (`For`, `ForEach`, `Invoke`, `ForEachAsync`): see [Differences from C#](#differences-from-c) for what one thread means |
| `HttpClient` (needs `ext-curl`) | `GetStringAsync`, `GetAsync`, `PostAsync`, `PutAsync`, `DeleteAsync`, `AddDefaultHeader`, `BaseAddress`, `Timeout`, `Dispose`; `HttpResponseMessage` (`StatusCode`, `IsSuccessStatusCode`, `ReasonPhrase`, `Headers`, `Content.ReadAsStringAsync()`, `EnsureSuccessStatusCode()`), `StringContent` |
| `WebUtility` (`System.Net`) | `HtmlEncode`, `HtmlDecode`, `UrlEncode`, `UrlDecode` |
| `Regex` (`System.Text.RegularExpressions`) | static and instance (`new Regex(pattern[, options])`): `IsMatch`, `Match`, `Matches`, `Replace` (with `$1`, `${name}`, `$0`, `$$`, or a `m => ...` evaluator), `Split`; `Regex.Escape`; `Match` (`Success`, `Value`, `Index`, `Length`, `Groups[1]` / `Groups["name"]`, `NextMatch()`), `MatchCollection` (a list: `Count`, `[i]`, `foreach`, LINQ), `RegexOptions` (`IgnoreCase`, `Multiline`, `Singleline`, `IgnorePatternWhitespace`, `ExplicitCapture`; several: `(int)RegexOptions.IgnoreCase \| (int)RegexOptions.Multiline`). Patterns run on PHP's PCRE, which reads .NET's syntax |
| `File` (`System.IO`) | `ReadAllText`, `ReadAllLines`, `ReadLines` (one line at a time), `ReadAllBytes`, `WriteAllText`, `WriteAllLines`, `WriteAllBytes`, `AppendAllText`, `AppendAllLines` (UTF-8, or an `Encoding`), the `...Async` forms of these, `OpenText`, `CreateText`, `AppendText`, `Exists`, `Delete`, `Copy`, `Move`, `GetLastWriteTime` |
| `Directory`, `Path` (`System.IO`) | `Directory`: `Exists`, `CreateDirectory` (returns a `DirectoryInfo`), `GetFiles` / `GetDirectories` / `GetFileSystemEntries` / `EnumerateFiles` / `EnumerateDirectories` (`(path[, "*.log"[, SearchOption.AllDirectories]])`), `Delete(path[, recursive])`, `Move`, `GetParent`, `GetCurrentDirectory`, `SetCurrentDirectory`, `GetLastWriteTime`; `Path`: `Combine`, `Join`, `GetFileName`, `GetFileNameWithoutExtension`, `GetExtension`, `HasExtension`, `GetDirectoryName`, `ChangeExtension`, `GetFullPath`, `GetRelativePath`, `GetPathRoot`, `IsPathRooted`, `EndsInDirectorySeparator`, `TrimEndingDirectorySeparator`, `GetTempPath`, `GetTempFileName`, `GetRandomFileName`, `GetInvalidFileNameChars` |
| Readers, writers, infos (`System.IO`) | `StreamReader(path)` and `StringReader` (`ReadLine`, `ReadToEnd`, `Read`, `Peek`, `EndOfStream`, `ReadLineAsync`, `ReadToEndAsync`), `StreamWriter(path[, append])` and `StringWriter` (`Write`, `WriteLine` (also with a format), `Flush`, `AutoFlush`, `NewLine`, `WriteAsync`, `WriteLineAsync`; a `StreamWriter` writes on `Flush` and `Dispose`), all `IDisposable` for `using`; `FileInfo` (`Name`, `FullName`, `Extension`, `Length`, `Exists`, `DirectoryName`, `Directory`, `LastWriteTime`, `IsReadOnly`, `Delete`, `CopyTo`, `MoveTo`, `OpenText`, `CreateText`, `AppendText`), `DirectoryInfo` (`Name`, `FullName`, `Parent`, `Exists`, `Create`, `CreateSubdirectory`, `Delete`, `GetFiles`, `GetDirectories`, `MoveTo`); `IOException`, `FileNotFoundException`, `DirectoryNotFoundException` |
| `Encoding` (`System.Text`) | `UTF8`, `ASCII`, `Unicode`, `BigEndianUnicode`, `UTF32`, `Latin1`: `GetBytes`, `GetString`, `GetByteCount` (`byte[]`) |
| `Stopwatch` (`System.Diagnostics`) | `StartNew`, `Start`, `Stop`, `Reset`, `Restart`, `IsRunning`, `Elapsed`, `ElapsedMilliseconds`, `ElapsedTicks`, `GetTimestamp`, `Frequency` |
| `JsonSerializer` (`System.Text.Json`) | `Serialize(value, options)` with `JsonSerializerOptions` (`WriteIndented`, `PropertyNamingPolicy = JsonNamingPolicy.CamelCase`) |
| `System.Web` | `WebApp`, `HttpRequest`, `HttpResponse`, `Results`, `IResult`: see [Web apps](#web-apps) |
| Memory | `GC` (`Collect`, `CollectionCount`, `GetTotalMemory`, `KeepAlive`, `ReRegisterForFinalize`, `SuppressFinalize`, `WaitForPendingFinalizers`), `WeakReference<T>`, `WeakReference`, `ConditionalWeakTable<TKey, TValue>` (`System.Runtime.CompilerServices`), `new object()`: see [Memory](#memory) |
| Interfaces | `IDisposable` (for `using`), `IComparable<T>` (for `OrderBy` and `Sort`), `IEquatable<T>`, and the collection interfaces above |
| Exceptions | `Exception` (`Message`, `InnerException`, `StackTrace`), `SystemException`, `ArgumentException`, `ArgumentNullException`, `ArgumentOutOfRangeException`, `ArithmeticException`, `DivideByZeroException`, `FormatException`, `IndexOutOfRangeException`, `InvalidCastException`, `InvalidOperationException`, `KeyNotFoundException`, `NotImplementedException`, `NotSupportedException`, `NullReferenceException`, `OverflowException`, `TimeoutException`, `HttpRequestException`, `IOException`, `FileNotFoundException`, `DirectoryNotFoundException`, `ObjectDisposedException`, `OperationCanceledException`, `TaskCanceledException`; classes of your own can derive from them |

## Async and await

`async` methods and lambdas, `await`, `Task` and `Task<T>` work as in C#, and `HttpClient` sends requests in
the background, so a program can wait for many things at once:

```csharp
using System;
using System.Linq;

public class Program
{
    public static async Task Main()
    {
        var http = new HttpClient();
        var urls = new[] { "https://example.com", "https://example.org", "https://example.net" };

        // The three downloads run at the same time: this takes as long as the slowest one.
        string[] pages = await Task.WhenAll(urls.Select(u => http.GetStringAsync(u)));
        Console.WriteLine(pages.Sum(p => p.Length));

        int seconds = await CountdownAsync(3);
        Console.WriteLine($"{seconds} seconds later");
    }

    static async Task<int> CountdownAsync(int seconds)
    {
        for (var i = seconds; i > 0; i--)
        {
            Console.WriteLine(i);
            await Task.Delay(1000);
        }
        return seconds;
    }
}
```

- **Methods:** `async Task` / `async Task<T>` (and `async void`); inside, `return value;` returns the `T`.
  `static async Task Main()` (or `Task<int>`) and `await` in top-level statements work: the program ends when
  its task does. An async method runs right away until its first `await`, as in C#.
- **Lambdas:** `async () => await LoadAsync()`, `async x => { ...; return x; }` for `Func<Task>`, `Func<int, Task<string>>`, ...
- **Task:** `Task.Run(() => ...)`, `Task.Delay(ms)`, `Task.WhenAll(tasks)` (a `T[]` of the results, in order;
  also for a collection of tasks), `Task.WhenAny(tasks)`, `Task.FromResult(x)`, `Task.CompletedTask`; on a task:
  `.Result` and `.Wait()` (blocking), `IsCompleted`, `IsFaulted`, `IsCanceled`, `Exception`. An exception thrown
  in a task comes out of the `await`.
- **Cancellation:** pass a `CancellationToken` and check it, as in .NET:

  ```csharp
  using var cts = new CancellationTokenSource();
  cts.CancelAfter(500);
  try
  {
      await Task.Delay(Timeout.Infinite, cts.Token);    // TaskCanceledException after 500 ms
  }
  catch (OperationCanceledException) { Console.WriteLine("canceled"); }

  static async Task WorkAsync(CancellationToken token = default)
  {
      while (true)
      {
          token.ThrowIfCancellationRequested();
          await Task.Delay(100, token);
      }
  }
  ```
- **HttpClient** (`System.Net.Http`, needs PHP's curl extension): `GetStringAsync`, `GetAsync`, `PostAsync`,
  `PutAsync`, `DeleteAsync` with `new StringContent(text, null, "application/json")`; `BaseAddress`, `Timeout`
  (seconds), `AddDefaultHeader(name, value)`. The response has `StatusCode`, `IsSuccessStatusCode`,
  `ReasonPhrase`, `Headers`, `Content.ReadAsStringAsync()` and `EnsureSuccessStatusCode()`; failures throw
  `HttpRequestException`.

**How it works:** each async method runs in a PHP Fiber on SharpPHP's event loop; `await` suspends the fiber
until the task completes. It is one thread, like JavaScript: timers and network requests overlap, CPU work
does not, and PHP calls that block (`sleep()`, `file_get_contents()` of a URL, PDO queries) block everything
while they run. Use `Task.Delay` and `HttpClient` to wait without blocking.

## Mixing PHP and SharpPHP

A project can be partly PHP and partly SharpPHP: write typed business logic in `.sphp` files and keep
frameworks, libraries and existing code in PHP. Each side calls the other.

**SharpPHP calling PHP**

- Any PHP class is visible: global classes directly (`new ArrayObject()`), namespaced ones through `using`:
  `using GuzzleHttp;` then `new Client()`. PHP's types (and PHPStan-style docblocks such as
  `@return array<string, int>`) are known to the compiler.
- Where a .NET type of the runtime library has the name of a global PHP class (`DateTime`, `Exception`),
  the .NET type wins, as `System` is imported. Name the PHP class through an alias:
  `using PhpDateTime = DateTime;`, then `new PhpDateTime("now").format("Y-m-d")`.
- Global PHP functions and constants can be called directly: `strlen(s)`, `in_array(x, list)`, `PHP_EOL`.

**PHP calling SharpPHP**

Compiled classes are regular PHP classes. Make them loadable with one line:

```php
require 'vendor/autoload.php';
SharpPhp\Project::fromDirectory(__DIR__)->autoload(); // builds if needed

echo App\Pricing::Total([new App\Item('Book', 12.5, 2)], 'AM');
```

**Collections and PHP arrays** are converted where the two sides meet:

| Where | What happens |
|---|---|
| A `List`, `Dictionary`, `HashSet` or a LINQ query passed to PHP code that takes an `array` | it becomes a PHP array (a dictionary becomes `key => value`): `implode(", ", names)`, `implode(", ", names.Where(n => n != ""))`, `Store.save(names)`; PHP's `count()` and `json_encode()` take a query as they take a list |
| A PHP array assigned to (or passed as) a `List<T>`, `Dictionary<K, V>`, `HashSet<T>` | it becomes that collection, with C#'s element type checks: `Dictionary<string, int> stock = Store.stock();` |
| PHP code passing an array to a SharpPHP method with a `List<T>` / `Dictionary<K, V>` / `HashSet<T>` parameter | the method converts it on entry: `Pricing::Total([$item1, $item2])` |
| `json_encode()` (and Laravel's JSON responses) of a SharpPHP collection | a JSON array, or a JSON object for a dictionary |

A PHP array kept as it is (`var stock = Store.stock();`) can be indexed (`stock["apple"]`), iterated over
its values and queried with LINQ. PHP turns the array key `"7"` into the integer `7`; collections of
strings and dictionaries with string keys turn such keys back into strings.

## Laravel

In a Laravel app (11 or newer), controllers, services, models and jobs can be written in SharpPHP next
to the PHP ones. `composer require armdevstack/sharp-php` registers the integration automatically
(package discovery): `.sphp` files under `app/` are compiled, and Laravel's routes, container and PHP code
use their classes like any other.

```csharp
// app/Http/Controllers/UserController.sphp
using System.Collections.Generic;
using System.Linq;
using Illuminate.Support.Facades;
using HttpRequest = Illuminate.Http.Request;

namespace App.Http.Controllers;

public record User(int Id, string Name);

public class UserController : Controller
{
    private readonly List<User> users = new() { new(1, "Ann"), new(2, "Bob") };

    public string Show(string name) => $"Hello, {name}!";

    public List<User> Index(HttpRequest request)
    {
        Log.info("users listed");
        return users.Take(int.Parse(request.query("limit", "10"))).ToList();   // sent as JSON
    }
}
```

```php
// routes/web.php (PHP, as usual)
use App\Http\Controllers\UserController;

Route::get('/hello/{name}', [UserController::class, 'Show']);
Route::get('/users', [UserController::class, 'Index']);
```

- **Namespaces** follow the folders, as in PHP: `app/Http/Controllers` is `namespace App.Http.Controllers;`.
  A SharpPHP class can extend a PHP class (`Controller`, `Model`, `Job`), and PHP classes can use SharpPHP ones.
- **Dependency injection and route parameters** work as for PHP controllers: Laravel passes `Request`,
  services and `{name}` by parameter type and name.
- **Facades and helpers:** `using Illuminate.Support.Facades;` then `Log.info(...)`, `Config.get(...)`,
  `Cache.put(...)`, `Route.has(...)`, `DB.table(...)`, `Event.dispatch(...)`; helper functions as they are:
  `view("welcome")`, `url("/")`, `response()`, `dispatch(job)`. The compiler knows the facades' methods from
  their docblocks (`@method static ...`), so arguments and results are checked, and the language server
  completes them. Calls it knows nothing about (Eloquent's `User.where(...)`, other `__callStatic`/`__call`
  magic) are passed through to PHP.
- **Models** in SharpPHP extend `Model` and redeclare its properties as fields; Eloquent's static and
  instance magic works on them, and on their attributes (`post.title`), as in PHP. Relationships take the
  related class as `typeof(...)`:

  ```csharp
  // app/Models/Post.sphp
  using Illuminate.Database.Eloquent;
  using Illuminate.Database.Eloquent.Relations;

  namespace App.Models;

  public class Post : Model
  {
      protected string[] fillable = new[] { "title", "body", "published" };
      protected Dictionary<string, string> casts = new() { ["published"] = "boolean" };

      public HasMany comments() => this.hasMany(typeof(Comment));   // Comment::class for PHP

      public string Headline() => ((string)this.title).ToUpper();
  }

  // in a controller
  var post = (Post)Post.create(request.validated());
  post.comments().create(new Dictionary<string, object> { ["text"] = "Nice" });
  var posts = Post.with("comments").get();               // returned: JSON with the comments
  Post? first = (Post?)Post.where("title", "Hello").first();
  ```

  A field that redeclares a property of a PHP base class (`fillable`, `casts`, `hidden`, `table`, a
  command's `signature`) keeps the PHP declaration (Laravel's are untyped) and may not be more private than
  it (SP0507); a `List` or `Dictionary` there is stored as the PHP array Laravel reads. Eloquent's magic
  calls are not checked by the compiler; their results are `object`. Migrations stay PHP files (Laravel
  writes them as anonymous classes, which C# does not have).
- **What Laravel calls, it gets as PHP:** in a class derived from a Laravel class, a method returning a
  `List`, `Dictionary` or `HashSet` (`rules()`, `messages()`, `casts()`) returns it to Laravel as a PHP array,
  while SharpPHP code calling it still gets the collection it declares. `typeof(Comment)` goes to PHP as
  `Comment::class` wherever PHP takes a class name, and a collection given to an untyped PHP parameter
  (`view("posts", data)`, a `dynamic` call) goes as an array, nested collections included.
- **Form requests, middleware, jobs, events:**

  ```csharp
  public class StorePostRequest : FormRequest                    // using Illuminate.Foundation.Http;
  {
      public bool authorize() => true;
      public Dictionary<string, string> rules() => new() { ["title"] = "required|min:3", ["body"] = "required" };
  }

  public class AddVersionHeader       // using HttpRequest = Illuminate.Http.Request; Route::middleware(AddVersionHeader::class)
  {
      public object handle(HttpRequest request, Func<HttpRequest, object> next)
      {
          var response = next(request);
          ((dynamic)response).headers.set("X-App-Version", "1.0");
          return response;
      }
  }

  public class CountWords : ShouldQueue   // using Illuminate.Contracts.Queue; dispatch(new CountWords((int)post.id));
  {
      public int PostId { get; }
      public CountWords(int postId) => PostId = postId;
      public void handle() => Cache.put($"words:{PostId}", 42, 600);
  }

  public class LogPostCreated                                    // app/Listeners: registered automatically
  {
      public void handle(PostCreated e) => Log.info($"post created: {e.Post.Headline()}");
  }
  ```

  Event listeners in `app/Listeners/*.sphp` are registered automatically, like Laravel's discovery does for
  `.php` ones (a public `handle...()` or `__invoke()` method listens to the event class of its first
  parameter); one the app also registers itself (`Event::listen`) is not added twice. Queued jobs are
  serialized and run by `php artisan queue:work` as PHP ones are.
- **Artisan commands** in `app/Console/Commands/*.sphp` are registered automatically, like PHP ones:

  ```csharp
  public class Hello : Command
  {
      protected string signature = "app:hello {name}";
      protected string description = "Says hello";

      public void handle() => this.info($"Hello, {this.argument("name")}!");
  }
  ```
- **Name clashes:** `Request` is both `Illuminate.Http.Request` and the `Request` facade; with both
  namespaces imported the compiler reports the ambiguity, as C# does. Use an alias
  (`using HttpRequest = Illuminate.Http.Request;`) or the full name.
- **Responses:** return a string, a view (`view("posts", new Dictionary<string, object> { ["heading"] = "Posts" })`),
  a Laravel response (`response().json(post, 201)`), an Eloquent model or collection, or SharpPHP values: a
  `List`, a `Dictionary`, a record or an anonymous object becomes a JSON response.
- **Development:** sources are recompiled on the next request after a change. Compile errors show on
  Laravel's error page with the `.sphp` file and line; Artisan keeps working and
  `php artisan sharp:build` lists them.
- **Production:** run `php artisan sharp:build` when deploying. With `APP_ENV=production` the app uses that
  build without checking the sources on every request.
- **Configuration:** `.sphp` files under `app/`, compiled to `storage/framework/sharp` (kept out of git by
  its own `.gitignore`). Change it in the app's `composer.json`: `"extra": { "sharp": { "source": "app",
  "output": "storage/framework/sharp" } }`.

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
- classes that do not implement their interfaces or inherited abstract members: an interface member is implemented by a
  public member with the same parameter types (`ref`/`out` too) and return type, the interface's type arguments put in
  (`Delete(long)` does not implement `Delete(T)` of a `BaseRepository<int>`, CS0535; CS0737, CS0738), and a property's
  `set` or `init` by a public one of the same kind (CS0277, CS8854)
- members that do not exist, also on the runtime library's types: `list.Lenght`, `text.Shout()` without an extension method
- array initializers that do not match their size (`new int[2] { 1, 2, 3 }`), constant negative array sizes
- operators the operand types do not have: `true + 1`, `"a" * 2`, `"a" < "b"`, `E1.A == E2.A`, `date == "x"`, `-"a"`, `!5`, `5 ?? 0` (CS0019, CS0023); `?:` whose branches have no type in common, `var v = c ? 1 : "a"` (CS0173); `i += 1.5` of an int (CS0266); a constant division by zero (CS0020)
- names: a local used before its declaration (CS0841, CS0844), a local or parameter hidden by a nested one (`int x; { int x; }`, CS0136; lambdas and local functions may hide them, as since C# 8)
- `const`: a value that is not constant (`const int Year = DateTime.Now.Year;`, CS0133), a missing value, `const var`; assignments to a constant (CS0131), to a `foreach` or `using` variable (CS1656, CS1657); `(int)double.NaN` and other constants out of range (CS0221)
- statements: duplicate `case` labels (CS0152), `yield` in a method that does not return `IEnumerable<T>` or `IEnumerator<T>` (CS1624), `foreach` over a value without `GetEnumerator` (CS1579), `throw` of something that is not an exception (CS0029), a `catch` that an earlier one already catches (CS0160), `using` of something that is not `IDisposable` (CS1674)
- `ref` and `out` written at the call as the parameter declares them (CS1620, CS1615)
- types: deriving from a `sealed` class, a struct or a `static` class (CS0509, CS0709), instances and variables of a static class (CS0712, CS0723), invoking an event outside its class (CS0070), type arguments that do not meet `where` constraints: `class`, `struct`, `new()`, a base class (CS0452, CS0453, CS0310, CS0311, CS0315)

The checks never reject valid C#, so where SharpPHP cannot be sure it accepts the code:

- a conversion is only rejected when both types are known: values of type `object`, `dynamic` or unconstrained type parameters, and untyped PHP code, are checked at runtime; so are their members and operators (`object o; o.Length` compiles: PHP code's results are `object` too)
- integers of PHP code and of the runtime library have no size the compiler knows (PHP's `int`), so they convert to every integral type: `byte b = byte.Parse(s)` and `byte m = Math.Max(a, b)` compile, as in C#, and so does `byte b = someList.Count`, which C# rejects; nullable-to-value assignments (`int i = maybeNull;`) are not reported
- assignments inside `&&`, `||` and `?:` count as definite assignments

Uncaught exceptions are printed like .NET, with locations mapped back to the `.sphp` sources:

```
Unhandled exception. System.InvalidOperationException: Queue empty.
   at App.Worker.Next() in /app/src/Worker.sphp:line 14
   at App.Program.Main() in /app/src/Program.sphp:line 8
```

## Memory

SharpPHP uses PHP's memory management, which frees objects earlier and more predictably than .NET's
garbage collector:

- An object is freed as soon as its last reference goes (reference counting); objects that only reference
  each other are freed by PHP's cycle collector, which runs by itself and on `GC.Collect()`.
- So a finalizer (`~Name()`, compiled to `__destruct`) runs right then, not on some later collection, and
  `GC.WaitForPendingFinalizers()` has nothing to wait for. `GC.SuppressFinalize(this)` in `Dispose()` stops it,
  as in .NET. The base class's finalizer runs after the derived one.
- For resources (files, connections, locks) use `IDisposable` with `using`: it releases them at a known point,
  also when an exception is thrown.
- In a web request all memory is freed when the request ends, so leaks matter only in long-running processes
  (console daemons, async servers). `GC.GetTotalMemory(false)` is `memory_get_usage()`.
- PHP's `memory_limit` caps a process; going over it is a fatal error, not a catchable `OutOfMemoryException`.
- `WeakReference<T>` and `ConditionalWeakTable` don't keep their targets alive (PHP's `WeakReference` and `WeakMap`).

## Differences from C#

SharpPHP is a C# *subset* on top of PHP's runtime. Notable differences:

- No method or constructor overloading (PHP has one method per name; names are case-insensitive)
- Generics are checked by the compiler and erased at runtime (only the built-in collections check element types); `typeof(List<int>).Name` is `List`, and `new T()` is not supported
- `object` stands for any value, also as a generic argument: `IEnumerable<object> xs = new List<int>()` compiles (C# rejects it, `int` being a value type)
- Finalizers run when the object is freed, at a known point (see [Memory](#memory)), not when a garbage collection happens to run
- Arrays (`int[]`) are PHP arrays and are copied on assignment and when passed to a method (value semantics): a method that changes an element of an array parameter does not change the caller's array (pass a `List<T>` for that); a lambda that writes an element of a captured array changes it, as in C#
- A `struct` is compiled to a class, so it has reference semantics: after `var b = a; b.X = 5;` `a.X` is 5 too (C# copies the struct). Prefer structs whose values do not change (`readonly struct`, get-only properties) or records with `with` expressions (`record struct` is not supported)
- `ulong` is limited to `long.MaxValue` (there is no `ulong.MaxValue`)
- `decimal` is a PHP float (a 64-bit double) to the runtime: the compiler checks it as C#'s decimal (no implicit conversion from or to double, `1.5m` literals, CS0664), and it prints with up to 15 significant digits and rounds half away from zero in formats, but its arithmetic has double's precision (`0.1m + 0.2m == 0.3m` is false; compare money in cents, or round), `10m / 3` is `3.33333333333333` and `1.50m` prints `1.5`. JSON writes a decimal as a double (`0.30000000000000004` for `0.1m + 0.2m`). `float` is computed in single precision, as in C#
- A number in a variable of type `object` (or of a generic type parameter `T`) has lost its C# type: it prints with up to 15 significant digits (`object o = 0.1 + 0.2;` prints `0.3`, `0.30000000000000004` in C#)
- `[Flags]` enums: an enum value is a PHP enum case, so combinations such as `A | B` do not exist (a compile error, SP1010). Combine the numbers instead: `(int)A | (int)B`; `RegexOptions` parameters take such a number
- An enum variable holds one of the enum's members: `(Color)99` and `Color.Red + 100` throw an `ArgumentException` when the enum has no member with that value (C# keeps any number)
- `char` is a one-character string at runtime (it converts to its code where C# would use it as a number)
- A value only knows its PHP type at run time: `GetType()` of an `object` holding a long is `System.Int32` (of a `long` variable it is `System.Int64`, which the compiler knows), and a `char` is a one-character string, so `object o = 'c'; o is string` is true; two boxed values are equal by value (`(object)1 == (object)1` is true, false in C#); a string's `Length` counts characters, not UTF-16 code units (`"😀".Length` is 1, 2 in C#); generic code erases its type arguments: `default(T)` is null for every `T`
- The runtime library implements the common .NET APIs, not all of them (no `DateTimeOffset`, `TimeZoneInfo`, `DateOnly`, `TimeOnly`, `Zip` without a result selector, which returns tuples, ...); `MinBy` and `MaxBy` of an empty sequence give `null` (.NET throws for value types)
- There are no cultures: numbers, `DateTime` and `TimeSpan` format and parse as .NET's en-US culture does (`1/15/2024 9:30:00 AM`, `$1,234.50`, `25.6%`, English names; `N`, `F` and `P` without a precision have 2 decimals and the time has a plain space before AM/PM, as with Windows' en-US settings; .NET on macOS and Linux reads ICU's en-US data instead, with 3 decimals and U+202F before AM/PM), strings sort by their lower-case characters, lower case first on a tie (`"apple"` before `"Banana"`, `"a"` before `"A"`), not by .NET's culture rules (where `"_"` sorts before `"1"` and `"ä"` next to `"a"`), and local time is PHP's default time zone (`date_default_timezone_get()`). `DateTime.Parse` reads what PHP's date parser reads (ISO 8601, `1/15/2024 4:30 PM`, `January 15, 2024`), except relative dates such as `tomorrow`
- A field of a struct type without an initializer (`public DateTime Due;`) is null until it is set; C# starts it at `DateTime.MinValue`. `default`, new arrays and `GetValueOrDefault()` give C#'s defaults
- A named argument for a `params` parameter (`string.Join(separator: ",", values: words)`) is not supported; pass it positionally
- `async`/`await` runs on one thread (Fibers and an event loop): waiting overlaps, CPU work does not; there is no `ConfigureAwait`, `ValueTask` or `IAsyncEnumerable`
- Threads take turns on that one thread: a `Thread` is a fiber that runs while the program waits (`Join`, `Thread.Sleep`, `await`), so code written for threads works, but CPU work is not spread over cores; `Parallel.For`/`ForEach` run the bodies one after another (`ForEachAsync` overlaps their waiting), `lock` and `Interlocked` never have to wait
- `int` and `long` arithmetic is 64-bit and does not wrap (`int.MaxValue + 1` is `2147483648`, and `1 << 40` is not `256`); a `long` that overflows becomes a double; values stored in `byte`, `sbyte`, `short`, `ushort` and `uint` by `+=`, `++`, `--` and casts do wrap, as in C#. A double cast to an integral type saturates as in .NET 9 and later (`(int)3e10` is `int.MaxValue`)
- Local functions are compiled to private methods of the class: they cannot use the local variables and parameters of the code around them (SP8421), and two local functions of one class cannot have the same name (SP0128)
- Extension properties of extension blocks are read-only
- Not supported: nested types, operator overloading in your own types (`DateTime`, `TimeSpan` and `Guid` have their operators), indexers, events with `add`/`remove` accessors, anonymous methods (`delegate (int x) { ... }`: use a lambda), default interface methods (SP8701), LINQ query syntax, tuples, `goto`, `ref` locals and `ref` returns, raw string literals (`"""..."""`), positional patterns (`Point(0, 0)`), passing arguments to a base record, implementing `IEnumerable<T>` in a class (derive from `List<T>` instead), explicit interface implementations, `dynamic` arguments for `params` parameters, `JsonSerializer.Deserialize` (use PHP's `json_decode`)

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
