# Changelog

## Unreleased

**Breaking: extension methods take their receiver as C# does.** Code that .NET 10 rejects, and that compiled
before, is now a compile error:

- Explicit type arguments apply to the receiver: `"x".Print<bool>()` for `Print<T>(this T arg)` is SP1929
  ("requires a receiver of type 'bool'"; it printed the string), as is `list.Count2<string>()` of a
  `List<int>`; a wrong number of type arguments (`"x".Print<int, int>()`) is SP1061
- The receiver converts to the `this` parameter only by an identity, reference or boxing conversion:
  `5.M()` does not call `M(this long x)`, `M(this double x)` or `M(this int? x)`, a `char` or an enum not
  `M(this int x)`, an `int?` not `M(this int x)` (SP1929); `M(this object x)` and an interface the value
  implements still take it. Under `?.` the receiver is not null, as in C#: `s?.Length.Twice()` and `x?.Twice()`
  of an `int? x` call `Twice(this int x)`
- When an extension method of that name is in scope but takes another receiver, the error is SP1929 naming
  it, as C#'s CS1929 (it was SP1061, or no error)
- A method that a number, a `bool` or a `char` does not have (`5.Foo()`) is SP1061; it compiled and threw
  `InvalidOperationException` at run time

## 0.10.0

Checked against .NET 10 itself: the same programs run in .NET and in SharpPHP, and their output is compared.
Fixtures `net_numbers`, `net_semantics` and `net_collections` have .NET 10's output as their expected output.

**Numbers**

- `double`, `float` and `decimal` are distinct types, with C#'s conversions (`double` to `float` or `decimal`
  needs a cast; `float f = 1.5;` is CS0664; `decimal + double` is CS0019); `float` is computed in single
  precision (`16777217f` is `16777216`)
- A double prints as .NET Core does, with the shortest digits that read back as it: `0.1 + 0.2` is
  `0.30000000000000004`, `1e16` is `10000000000000000` (it printed `0.3` and `1E+16`); a float with its single
  precision digits (`1f / 3` is `0.33333334`); a decimal as before (`0.1m + 0.2m` is `0.3`), but without an
  exponent (`0.0000000001m` printed `1E-10`)
- Numbers keep their C# type where they are written as text by `string.Format`, `Console.WriteLine("{0}", x)`,
  `string.Join`, `string.Concat`, `StringBuilder.Append`, `AppendFormat`, `AppendJoin`, `Insert`,
  `TextWriter.Write` and `Convert.ToString`, and in anonymous types' `ToString()`: `{0:F1}` of `2.25m` is `2.3`
  (it rounded as a double, `2.2`)
- `double.Epsilon` was the smallest normal double; `float.MaxValue`, `MinValue`, `Epsilon` were double's;
  `int.MaxValue` and friends are typed as their type
- Format strings as .NET's: standard formats round a double half to even (`2.5:F0` is `2`), a decimal half
  away from zero (`2.5m:F0` is `3`); custom formats in full (`#.##`, `0.0%`, `‰`, `"0.0;(0.0);zero"`,
  `'literal'`, `\#`, `E+0`, scaling commas `#,##0,,`, `(###) ###-####`); `P` is `25.6%` (was `25.6 %`);
  `NaN` and `∞` in every format; a float's custom formats start from 7 digits; the `B` (binary) format;
  `string.Format("{0,5}")` pads by characters (it counted bytes). Checked against .NET 10 with about
  4,000 values and formats
- `double.Parse` reads `"1,234.5"`, `"NaN"`, `"∞"`; `(int)3e10` saturates to `int.MaxValue` (as .NET 9+), a
  constant out of range is CS0221 (`(long)1e19`); `(int)3.9` is the constant 3
- `Math.Max`/`Min` with NaN are NaN, `Math.Min(-0.0, 0.0)` is -0; `Math.Sign(NaN)` throws; `Math.Clamp` checks
  min <= max; `1 / -0.0` is -Infinity in every expression
- `new Random(seed)` gives .NET's sequence (the same numbers as C#)

**Language**

- Nullable values have C#'s lifted operators: `null + 5` is null (it was 5), `null < 5` is false (it was
  true), `-null` is null, also in `+=` and `++`; `bool?` `&` and `|` are three-valued
- A method that hides a base method (`new`, or no `override`) is called by the static type (it was always the
  derived one); `override` of a non-virtual method is CS0506; a missing `override` of an abstract method CS0534
- A static constructor runs at the class's first use (it ran when the file was loaded, before `Main`)
- Exception filters: a `catch` whose `when` is false lets the next clauses try (the exception escaped)
- `obj?.Value = 5` (C# 14) assigns when `obj` is not null (it was a PHP fatal error)
- `[CallerMemberName]`, `[CallerLineNumber]`, `[CallerFilePath]`, `[CallerArgumentExpression]`
- Reference types accept null at run time, as in C# (a `record` or method with a `string` parameter threw
  when given null)
- `(string)x` is a reference conversion: null stays null (it became ""), a non-string is
  `InvalidCastException`, `(string)5` is CS0030
- `default(Point)` of a struct is a struct with default fields (it was null); `default(Guid)` and
  `default(TimeSpan)` are `Guid.Empty` and `TimeSpan.Zero` (`default(Guid)` threw)
- `GetType()` of a `long`, `char`, `double`, ... variable and of arrays is the C# type (`Int64`, `Double[]`)
- `new[] { 1, 2L }` is a `long[]`, C#'s best common type (it was a compile error, SP0266)
- `Console.WriteLine(chars)` writes a `char[]`'s characters; arrays print as `System.Int32[]`

**Collections, LINQ, tasks, JSON**

- `Dictionary` and `HashSet` enumerate in .NET's order after removals (a new entry takes the slot freed
  last); adding during `foreach` throws `InvalidOperationException` (also `Queue`, `Stack`), removing from a
  `Dictionary` does not, as in .NET
- `TryGetValue(key, out var v)`, `TryDequeue`, `list.Find(...)`, `GetValueOrDefault(key)` of value types
  give 0 / false, not null
- `Min`, `Max`, `Average` skip nulls (`int?[] { 1, null, 3 }.Average()` was 1.33, is 2); throw for no elements
  only for value types (`new string[0].Max()` is null); `Sum` of ints is checked (`OverflowException`)
- `AggregateException`: `Task.Wait()` and `.Result` throw it around the task's exceptions, `Task.WhenAll`
  gathers all of them, `Task.Exception` is one; `await` throws the exception itself
- JSON is written as System.Text.Json writes it: `\u0022`, `\u003C`, non-ASCII escaped, numbers as .NET
  (`0.30000000000000004`, `1E+21`); a null property was `""`
- Type names have .NET's arity (`List\`1`); `Queue<T>`/`Stack<T>` `ToString()`; `Enum.GetNames<T>()` works
- `StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries` (a [Flags] enum of the library)

**Strings**

- `ToUpper`/`ToLower` map one character to one character, as .NET (`"ß".ToUpper()` was `"SS"`)
- `Split(',', 2)` takes a count, as in C# (an int was read as options); `Split()` splits at any white space
- `string.Compare(a, b, StringComparison.Ordinal)` returns .NET's values (`31` for "a" and "B")
- `x % 0` and `x / 0` of integers: "Attempted to divide by zero."

**C#'s compile errors**

The compiler rejects what the C# compiler rejects, checked against .NET 10 with 215 programs
(`tests/Fixtures/csharp-rules.txt`, valid ones included):

- operators the operand types do not have (CS0019, CS0023: `true + 1`, `"a" < "b"`, `E1.A == E2.A`,
  `date == "x"`, `!5`, `5 ?? 0`), `?:` without a type (CS0173), `i += 1.5` (CS0266), `1 / 0` (CS0020)
- a local used before its declaration (CS0841, CS0844); a local hiding an outer one or a parameter (CS0136)
- `const`: values that are not constant (CS0133), `const int c;` (CS0145), `const var` (CS0822), assigning a
  constant (CS0131), a `foreach` or `using` variable (CS1656, CS1657, CS1510); `(int)double.NaN` (CS0221)
- duplicate `case` labels (CS0152), `yield` outside an iterator type (CS1624), `foreach` over an `int` or a
  class without `GetEnumerator` (CS1579), `throw "error"` (CS0029), unreachable `catch` (CS0160)
- `ref`/`out` missing or extra at a call (CS1620, CS1615); `Array.Sort(arr)` and `Array.Reverse(arr)` take
  the array by value, as in C# (signature help showed `ref`)
- deriving from `sealed`, struct and static classes (CS0509, CS0709), `new` and variables of a static
  class (CS0712, CS0723), invoking an event outside its class (CS0070), `where` constraints (CS0452,
  CS0453, CS0310, CS0311, CS0315)

**Also fixed**

- Arithmetic of enums: `e - E.A`, `e + 1`, `1 + e`, `e++` (PHP failed at run time)
- `throw null` throws a `NullReferenceException`
- `HttpClient`, `HttpResponseMessage`, `HttpContent` and `Task` are `IDisposable`: `using var response =
  await client.GetAsync(url);` compiles
- `Array.Resize(ref numbers, n)` fills an `int[]` with 0 (it was null)

**Breaking, for code that was not valid C#**

- Everything listed under "C#'s compile errors" above, for example a `foreach` variable named as a local of
  the enclosing block: `Student student = ...; foreach (Student student in students)` (CS0136)

- `float f = 1.5;`, `decimal m = 1.5;` (CS0664), `decimal + double` (CS0019), `(string)5` (CS0030),
  `override` without `virtual` (CS0506)
- `Split(',', (int)A | (int)B)` is now a count, as in C#: write
  `StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries`

## 0.9.0

**Integral types are checked as in C#** (they were all one type, so `byte b = 10000;` compiled and printed 10000):

- `byte`, `sbyte`, `short`, `ushort`, `int`, `uint`, `long`, `ulong` are distinct types: a constant must fit
  (`byte x = 10000;` is CS0031), narrowing needs a cast (`byte z = x + y;` is CS0266: `byte + byte` is an `int`),
  `(short)70000` is CS0221; arithmetic promotes as in C# (`int + long` is a `long`, `uint + int` a `long`, `-uint`
  a `long`); literals have C#'s types (`10L`, `10u`, `10UL`, `3000000000` is a `uint`); `char` converts to `ushort`
  and wider
- Casts cut the value as C# does without overflow checks (`(byte)x` of 300 is 44, `(int)` of a long keeps 32 bits),
  and `+=`, `++`, `--` on `byte`, `sbyte`, `short`, `ushort` and `uint` wrap (`byte` 255 + 1 is 0), also on list
  elements and properties
- `Convert.ToByte`, `ToSByte`, `ToInt16`, `ToUInt16`, `ToUInt32`, `ToInt64`, `ToUInt64`, `ToChar`, with
  `OverflowException` out of range (also `ToInt32`, which returned any number)
- Integers of PHP code and of the runtime library have no size the compiler knows, so they convert to every
  integral type (`byte b = byte.Parse(s)` compiles, as in C#)

**Delegates, events and threads:**

- Delegate types: `delegate int Transform(int x);` (generic, or declared in a class), `new Transform(Square)`,
  `Invoke`; multicast delegates: `a += b`, `a -= b`, `a + b`, `GetInvocationList()` (`a += b` threw at run time)
- Events: `public event Action? Changed;`, raised with `Changed?.Invoke()`; outside the class only `+=` and `-=`
  (CS0070); an event must have a delegate type (CS0066)
- `lock (gate) { ... }` (was a compile error), with C#'s rules (CS0185, CS1996)
- `CancellationTokenSource`, `CancellationToken`, `OperationCanceledException`, `TaskCanceledException`,
  `Task.Delay(ms, token)`, `Task.Run(func, token)`, `Task.IsCanceled`, `Timeout.Infinite` (`Task.Delay(-1)` ended at
  once)
- `Thread` (on fibers: threads take turns while the program waits), `Thread.Sleep`, `Interlocked`, `Parallel.For`,
  `ForEach`, `Invoke`, `ForEachAsync`

**System.IO, filled in:**

- `StreamReader`, `StreamWriter`, `StringReader`, `StringWriter` (`using var writer = new StreamWriter(path)`),
  `FileInfo`, `DirectoryInfo`, `SearchOption.AllDirectories`
- `File.ReadLines`, `ReadAllBytes`, `WriteAllBytes`, `OpenText`, `CreateText`, `AppendText`, `GetLastWriteTime`, and
  `ReadAllTextAsync`, `WriteAllTextAsync`, ... ; an `Encoding` for the text methods
- `Directory.GetFileSystemEntries`, `EnumerateFiles`, `EnumerateDirectories`, `Move`, `GetParent`,
  `SetCurrentDirectory`; `CreateDirectory` returns a `DirectoryInfo`
- `Path.Join`, `GetRelativePath`, `GetPathRoot`, `EndsInDirectorySeparator`, `TrimEndingDirectorySeparator`,
  `GetTempFileName`, `GetInvalidFileNameChars`
- `System.Text.Encoding` (`UTF8`, `ASCII`, `Unicode`, `UTF32`, `Latin1`: `GetBytes`, `GetString`)

**LINQ and iterators behave as in C#:**

- An iterator method (`yield return`) can be enumerated more than once (the second `foreach` threw "Cannot
  traverse an already closed generator"); its body runs on each enumeration, and nothing runs before the first
- LINQ is deferred: `Where`, `Select`, `SelectMany`, `Take`, `Skip`, `TakeWhile`, `SkipWhile`, `Distinct`,
  `DistinctBy`, `Concat`, `Zip`, `Chunk`, `Reverse`, `GroupBy`, `OrderBy`/`ThenBy`, `Enumerable.Range` and `Repeat`
  return an `IEnumerable<T>` that runs when it is enumerated. Endless sequences work (`Naturals().Take(5)` ran out
  of memory), `First`/`Any`/`Take` stop early, and chains build no lists in between (faster and smaller)
- **Breaking, for code that was not valid C#:** a query is an `IEnumerable<T>`, not a `List<T>`: `query.Count`
  (use `Count()`), `query[0]` (use `First()` or `ElementAt(0)`), `List<int> l = query;` (use `ToList()`) and
  `query.Add(...)` are compile errors now, as in C#
- `Distinct`, `DistinctBy` and `GroupBy` take linear time (they compared every pair: 300 000 elements took 45 s);
  `OrderBy` sorts 300 000 elements by two keys in about a quarter of the memory
- `Single` stops at a second match and has .NET's messages ("Sequence contains more than one matching element")
- `Sum`, `Min`, `Max` and `Aggregate` have the type of the elements (or of what the selector returns): `bool b =
  list.Sum();` was accepted
- LINQ on arrays infers the element type (`new[] { 1 }.Where(...)` was an `IEnumerable<TSource>`), and the runtime's
  `IEnumerable<T>` parameters are checked: `new HashSet<int>(new[] { "a" })` is CS1503

**Equality as in C#:**

- `HashSet` items and `Dictionary` keys use `Equals` and `GetHashCode`: records, structs, `DateTime`, `TimeSpan`,
  `Guid` and classes that override them are found by value (`dict[new Point(0, 0)]` threw `KeyNotFoundException`,
  a `HashSet<DateTime>` kept duplicates); `0.1 + 0.2` and `0.3` are different `double` keys
- Records compare every field exactly (`new R(null)` equaled `new R("")` and `new R(0)`, and `"1"` equaled `"01"`,
  by PHP's loose comparison) and have a matching `GetHashCode()`; structs are equal by their fields (as
  `ValueType.Equals`); `GetHashCode()` of numbers and strings is stable

**Collection interfaces:**

- `IList<T>`, `ICollection<T>`, `IReadOnlyList<T>`, `IReadOnlyCollection<T>` have their members: `Count`, an indexer,
  `Add`, `Remove`, `Clear`, `Insert`, `RemoveAt`, `IndexOf` (`ICollection<Order> Orders = new List<Order>();
  Orders.Add(o)` did not compile); `IEnumerable<T>` has no `Count` or indexer, as in C#
- Conversions follow .NET: a `List` is every one of them, `HashSet`/`Dictionary` an `ICollection`, `Queue`/`Stack` an
  `IReadOnlyCollection`, an array an `IList` (of a fixed size); `IList<T>` and `ICollection<T>` are invariant; an
  `IEnumerable<T>` becomes a class or an array only with a cast (checked: `InvalidCastException`)
- `x is IList<int>` (and other generic or array types without a variable) was parsed as a constant

Fixed:

- `new Num(0)` passed no argument when the constructor has one parameter (an `int?`): the argument `0` was dropped
- `"n = " + query.Count`, with `Count` a method, printed `System.Delegate`; it is CS0019, as in C#
- `using var x = ...;` in top-level statements after other statements was taken for a using directive
- A delegate declaration gave "Anonymous methods are not supported"

## 0.8.2

Laravel, checked in a Laravel 13 app (controllers, models, relationships, form requests, validation,
middleware, Blade, queued jobs, events, Artisan):

- Eloquent relationships: `this.hasMany(typeof(Comment))` (it was a compile error: `typeof` gave a
  `System.Type`, PHP takes the class name). `typeof(X)` goes to PHP code as `X::class` wherever it takes a
  class name: a `string` parameter, or an untyped one of PHP code
- `casts`, `hidden`, ... declared as a `Dictionary` or `List` (a field that redeclares a property of a PHP
  base class) are stored as the PHP array Laravel reads (loading any model with `casts` failed)
- `rules()`, `messages()`, `casts()` ... of a form request or model return their `Dictionary`/`List` to
  Laravel as a PHP array (validation failed); SharpPHP code calling them still gets the collection
- A `List` or `Dictionary` given to an untyped parameter of PHP code (`view("posts", data)`) or in a call on a
  `dynamic` value is a PHP array, nested collections included (Blade views got an object)
- Event listeners in `app/Listeners/*.sphp` are registered automatically (Laravel only discovers `.php`
  files); one the app registers itself is not added twice

## 0.8.1

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
