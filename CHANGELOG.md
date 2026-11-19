# Changelog

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
