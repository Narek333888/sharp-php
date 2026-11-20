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

### Upgrading

```bash
composer update armdevstack/sharp-php        # bug-fix releases: 0.3.0 → 0.3.x
composer require armdevstack/sharp-php:^0.3  # a newer minor version: 0.1.x / 0.2.x → 0.3.x
```

While SharpPHP is 0.x, a plain `composer update` stays within the minor version you installed: the
constraint Composer writes for you, e.g. `^0.1.3`, means `>=0.1.3 <0.2.0`, because `^` does not cross a 0.x
minor version. Require the newer version once, as above, and `composer update` follows it from then on.
`composer show armdevstack/sharp-php` prints the installed version; [CHANGELOG.md](CHANGELOG.md) lists
what changed. The build in `var/sharp` is redone automatically after an upgrade.

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

PhpStorm (and every other JetBrains IDE) gets highlighting, completion, errors while typing, hover and
go to definition for `.sphp` files. Setting it up takes about two minutes and is done **once per IDE**: it
then works in every project that requires `armdevstack/sharp-php` 0.3 or newer
(`composer show armdevstack/sharp-php` prints the version; older projects: see [Upgrading](#upgrading)).

### 1. Syntax highlighting

1. **Settings → Editor → TextMate Bundles**, click **+**.
2. Choose the folder `vendor/armdevstack/sharp-php/editors/sharpphp` of your project and click **OK**.

Keywords, types, strings, numbers and comments in `.sphp` files are now colored.

### 2. Completion, errors while typing, hover, go to definition

These come from the SharpPHP language server (`vendor/bin/sharp lsp`) through the free
[LSP4IJ](https://plugins.jetbrains.com/plugin/23257-lsp4ij) plugin by Red Hat:

1. **Settings → Plugins → Marketplace**: search **LSP4IJ**, click **Install** (restart PhpStorm if it asks).
2. **Settings → Languages & Frameworks → Language Servers (LSP4IJ)**, click **+**.
3. In the **New Language Server** dialog, open the **Template** list, choose **Import template...** and
   select the folder `vendor/armdevstack/sharp-php/editors/lsp4ij/sharpphp`. Click **OK**.

**Check it:** open a `.sphp` file, type `Console.` inside `Main`: `WriteLine`, `Write`, `ReadLine` are listed.
**View → Tool Windows → Language Servers** shows the SharpPHP server running.

What you get:

| Feature | Example |
|---|---|
| Members after a dot, also in unfinished code | `user.` → `Name`, `Age`, `Greet()`; `Person.` → static members; `names.` → `Add`, `Count`, `Where`, ... |
| Names in scope | local variables, parameters, members of the class, types, keywords |
| Namespaces | `using System.` → `Collections`, `Linq`, `Web`, ... |
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
| No completion and no error marks | Check that LSP4IJ is installed (**Settings → Plugins → Installed**) and step 2 was done. The project needs SharpPHP 0.3+: `composer require armdevstack/sharp-php:^0.3`. Restarting PhpStorm alone does not turn completion on |
| The **Language Servers** window shows that SharpPHP cannot start | PhpStorm did not find `php` (common on macOS, where an IDE started from the Dock does not see Homebrew). In **Settings → Languages & Frameworks → Language Servers (LSP4IJ) → SharpPHP**, set **Command:** to the full path of PHP: `/opt/homebrew/bin/php $PROJECT_DIR$/vendor/bin/sharp lsp` (`which php` in a terminal prints the path) |
| You want to see what the server does | **View → Tool Windows → Language Servers** → SharpPHP: the **Logs** tab; for every request set **Trace:** to `verbose` on the server's **Debug** tab |
| No completion after a dot | The file has a syntax error somewhere else: fix it first (it is underlined) |

## VS Code setup

VS Code gets the same features through the SharpPHP extension, which ships with the package: highlighting,
completion, errors while typing (also in the **Problems** panel), hover and go to definition (F12). It is
installed once and works in every project with SharpPHP 0.3+.

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
| "PHP was not found" | Set **Settings → Extensions → SharpPHP → Php: Path** (`sharpphp.php.path`) to the full path of PHP 8.4+, e.g. `/opt/homebrew/bin/php` (`which php` prints it) |
| No completion and no error marks | **View → Output → SharpPHP** says why: no `vendor/bin/sharp` (`composer require armdevstack/sharp-php:^0.3`), an untrusted workspace, or an older SharpPHP |
| Anything else | Command Palette → **SharpPHP: Restart Language Server**; details in [editors/sharpphp](editors/sharpphp/README.md#troubleshooting) |

After upgrading SharpPHP, run `vendor/bin/sharp vscode` again to update the extension.

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
- Constructors with `: base(...)`, expression-bodied constructors, static constructors
- Fields, `const`, `readonly`, `static`
- Properties: auto (`{ get; set; }`, `{ get; private set; }`, `{ get; init; }`, get-only), with initializers, expression-bodied (`=> ...`), full `get { } set { }` with `value`, abstract and interface properties, static properties (with accessor bodies they become `get_Name()`/`set_Name()` methods)
- Methods: `virtual` / `override` / `abstract` (an override may return a more derived type), expression bodies, default and named arguments, `params`, `ref`, `out` (incl. `out var x` and the discard `out _`), `in`
- Finalizers `~Name() { ... }`, which run when the object is freed (see [Memory](#memory))
- Records: `record Point(int X, int Y);` with value equality (`==`), C#-style `ToString()` (every public property) and `with` expressions (`p with { X = 5 }`)
- Enums: `enum Color { Red, Green = 5 }`, `(int)color`, `(Color)1`, `color.ToString()`

### Generics

- Generic classes, interfaces and methods: `class Box<T>`, `interface IRepository<T>`, `T Max<T>(T a, T b)`
- Constraints: `where T : IShape, new()` — a class/interface constraint becomes the PHP parameter type
- Variance as in C#: `List<Dog>` is not a `List<Animal>` (classes are invariant), while `IEnumerable<Dog>`, `Dog[]` and `Func<Dog>` are an `IEnumerable<Animal>`, an `Animal[]` and a `Func<Animal>`, and an `Action<Animal>` is an `Action<Dog>`. Interfaces declare it: `interface IProducer<out T>`, `interface IConsumer<in T>`; the compiler checks that `out T` is only produced and `in T` only consumed (SP1961)
- Type inference for generic methods, including through lambdas: `box.Map(x => x * 2)` is a `Box<int>`
- The compiler tracks generic arguments, so `List<string>` elements are known to be strings (`names[0].Length`)
- Runtime checks: `new List<int>()` rejects non-integers, like C# (`ArgumentException`)
- Implementing a generic interface with a concrete type (`IRepository<User>`) is supported

### Statements and expressions

- `if`/`else`, `while`, `do`, `for`, `foreach` (incl. `foreach (var (key, value) in dict)`), `break`, `continue`
- `switch` statements (with patterns and `when`), `switch` expressions
- `try`/`catch`/`finally`, exception filters (`catch (E e) when (...)`), `throw;`, throw expressions (`x ?? throw ...`)
- `using (var file = Open()) { ... }`, `using (expression) ...` and `using var file = Open();` (disposed at the end of the block): `Dispose()` runs however the block is left, in reverse order for several resources
- `yield return` / `yield break` with `IEnumerable<T>`
- Lambdas `x => x * 2`, `(a, b) => { ... }`, closures with C# capture semantics, method groups (`list.ForEach(Print)`)
- Pattern matching: `is T x`, `is null`, `is not null`, `is > 0 and < 10`, `or`, `not`, `var`, `_`, property patterns (`Circle { R: > 10 } c`, `{ Name: var n, Age: >= 18 }`, `{ Home.City: "Paris" }`)
- String interpolation with format specifiers `$"{price:F2}"`, verbatim strings `@"C:\path"`, `$@"..."`
- `?.`, `?[]`, `??`, `??=`, `is`, `as`, casts, `typeof` / `GetType()` (a `System.Type` with `Name`, `FullName`, `Namespace`), `nameof`, `default`
- Object and collection initializers: `new User { Name = "Ann" }`, `new Dictionary<string, int> { ["a"] = 1 }`, `new()`, `[1, 2, 3]`
- Anonymous types: `new { Id = 1, user.Name }`, with read-only typed properties, value equality and C#'s `ToString()` (`{ Id = 1, Name = Ann }`)

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
| `arr[10]`, `arr[10] = x` (out of range) | `IndexOutOfRangeException` (PHP would add the element) |
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
- `System.Web`: `WebApp`, `HttpRequest`, `HttpResponse`, `Results`, `IResult` (see [Web apps](#web-apps)); `System.Net.WebUtility` (`HtmlEncode`, `UrlEncode`, ...)
- `System.Threading.Tasks`: `Task`, `Task<T>` (see [Async and await](#async-and-await)); `System.Net.Http`: `HttpClient`, `HttpResponseMessage`, `StringContent`
- `System.Text.Json`: `JsonSerializer.Serialize(value, options)` with `JsonSerializerOptions` (`WriteIndented`, `PropertyNamingPolicy = JsonNamingPolicy.CamelCase`)
- Memory: `GC` (`Collect`, `GetTotalMemory`, `SuppressFinalize`, `CollectionCount`, ...), `WeakReference<T>` / `WeakReference`, `System.Runtime.CompilerServices.ConditionalWeakTable<TKey, TValue>`; `new object()`
- Exceptions: `Exception`, `ArgumentException`, `ArgumentNullException`, `ArgumentOutOfRangeException`, `InvalidOperationException`, `NotImplementedException`, `NotSupportedException`, `FormatException`, `IndexOutOfRangeException`, `DivideByZeroException`, `InvalidCastException`, `NullReferenceException`, `KeyNotFoundException`, ...

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
  `.Result` and `.Wait()` (blocking), `IsCompleted`, `IsFaulted`, `Exception`. An exception thrown in a task
  comes out of the `await`.
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

- Any PHP class is visible: global classes directly (`new DateTime()`), namespaced ones through `using`:
  `using GuzzleHttp;` then `new Client()`. PHP's types (and PHPStan-style docblocks such as
  `@return array<string, int>`) are known to the compiler.
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
| A `List`, `Dictionary`, `HashSet` passed to PHP code that takes an `array` | it becomes a PHP array (a dictionary becomes `key => value`): `implode(", ", names)`, `Store.save(names)` |
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
  `Route.has(...)`, `DB.table(...)`; helper functions as they are: `view("welcome")`, `url("/")`,
  `response()`. The compiler knows the facades' methods from their docblocks (`@method static ...`), so
  arguments and results are checked, and the language server completes them. Calls it knows nothing about
  (Eloquent's `User.where(...)`, other `__callStatic`/`__call` magic) are passed through to PHP.
- **Name clashes:** `Request` is both `Illuminate.Http.Request` and the `Request` facade; with both
  namespaces imported the compiler reports the ambiguity, as C# does. Use an alias
  (`using HttpRequest = Illuminate.Http.Request;`) or the full name.
- **Responses:** return a string, a view, a Laravel response, or SharpPHP values: a `List`, a `Dictionary`, a
  record or an anonymous object becomes a JSON response.
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
- Arrays (`int[]`) are PHP arrays and are copied on assignment (value semantics)
- Integer arithmetic is 64-bit and does not wrap: `int.MaxValue + 1` is `2147483648`, not `-2147483648`
- `ulong` is limited to `long.MaxValue` (there is no `ulong.MaxValue`); `decimal`/`float`/`double` are PHP floats
- `char` is a one-character string at runtime (it converts to its code where C# would use it as a number)
- Values only know their PHP type at runtime: `5L.GetType()` is `System.Int32`, while `typeof(long)` is `System.Int64`
- The runtime library implements the common .NET APIs, not all of them (no `StringComparison`, `ThenBy`, `Zip`, `DateTime.Now`, ...)
- A named argument for a `params` parameter (`string.Join(separator: ",", values: words)`) is not supported; pass it positionally
- `async`/`await` runs on one thread (Fibers and an event loop): waiting overlaps, CPU work does not; there is no `CancellationToken`, `ConfigureAwait`, `ValueTask` or `IAsyncEnumerable`
- Not supported: nested types, operator overloading, indexers, extension methods, events/delegates declarations, LINQ query syntax, tuples, `goto`, `ref` locals and `ref` returns, local functions, positional patterns (`Point(0, 0)`), passing arguments to a base record, implementing `IEnumerable<T>` in a class (derive from `List<T>` instead), explicit interface implementations, `dynamic` arguments for `params` parameters

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
