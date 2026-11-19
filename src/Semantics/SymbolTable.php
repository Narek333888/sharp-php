<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * All types visible to the compiler.
 *
 * Project types are declared by the compiler. Anything else (the runtime library under
 * SharpPhp\Runtime, PHP built-ins, Composer packages) is loaded lazily via reflection.
 * Generic information for PHP classes comes from @template / @return / @var / @sharp-element docblocks.
 */
final class SymbolTable
{
    /** A closure type in a docblock: \Closure(int, string): bool (PHPStan syntax). */
    private const CLOSURE_DOC = '\\\\?(?:Closure|callable)\([^)\r\n]*\)\s*:\s*[^\s$]+';

    /** @var array<string, TypeSymbol> */
    private array $project = [];

    /** @var array<string, TypeSymbol|false> */
    private array $external = [];

    /** @var array<string, true> */
    private array $namespaces = [];

    /** @var array<string, array<string, string>> file => (alias => FQN) from its use statements */
    private array $fileImports = [];

    /** File whose use statements apply to the docblock currently being read. */
    private ?string $docFile = null;

    /** @var list<string> template names of the method being loaded (see load()) */
    private array $methodTemplates = [];

    /** @var array<string, TypeSymbol> anonymous types by name; names are case-sensitive, like their properties */
    private array $anonymous = [];

    public function declare(TypeSymbol $symbol): ?TypeSymbol
    {
        $key = strtolower($symbol->fqn);
        if (isset($this->project[$key])) {
            return $this->project[$key];
        }
        $this->project[$key] = $symbol;
        $this->addNamespace(Names::namespaceOf($symbol->fqn));

        return null;
    }

    /** @return list<TypeSymbol> */
    public function projectTypes(): array
    {
        return array_values($this->project);
    }

    public function isProjectType(string $fqn): bool
    {
        return isset($this->project[strtolower(ltrim($fqn, '\\'))]);
    }

    public function find(string $fqn): ?TypeSymbol
    {
        if (isset($this->anonymous[$fqn])) {
            return $this->anonymous[$fqn];
        }
        $fqn = ltrim($fqn, '\\');
        $key = strtolower($fqn);
        if (isset($this->project[$key])) {
            return $this->project[$key];
        }
        if (!array_key_exists($key, $this->external)) {
            $this->external[$key] = $this->load($fqn) ?? false;
        }

        return $this->external[$key] ?: null;
    }

    public function addNamespace(string $phpNamespace): void
    {
        $parts = $phpNamespace === '' ? [] : explode('\\', strtolower($phpNamespace));
        $prefix = '';
        foreach ($parts as $part) {
            $prefix = $prefix === '' ? $part : $prefix . '\\' . $part;
            $this->namespaces[$prefix] = true;
        }
    }

    public function namespaceExists(string $phpNamespace): bool
    {
        $key = strtolower($phpNamespace);
        if (isset($this->namespaces[$key])) {
            return true;
        }
        $runtime = strtolower(Names::RUNTIME_NAMESPACE) . '\\';
        if (str_starts_with($key . '\\', $runtime . 'system\\')) {
            $relative = substr($phpNamespace, strlen(Names::RUNTIME_NAMESPACE) + 1);

            return is_dir(dirname(__DIR__) . '/Runtime/' . str_replace('\\', '/', $relative));
        }

        return false;
    }

    /** The class behind a class type, or behind a type parameter's class constraint. */
    public function symbolForType(Type $type): ?TypeSymbol
    {
        if (($type->kind === 'class' || $type->kind === 'typeparam') && $type->class !== null) {
            return $this->find($type->class);
        }

        return null;
    }

    /**
     * Interfaces of the type, of its base classes and their base interfaces (each once).
     *
     * @return list<TypeSymbol>
     */
    public function interfacesOf(TypeSymbol $type): array
    {
        $queue = [];
        for ($current = $type; $current !== null; $current = $current->baseClass !== null ? $this->find($current->baseClass) : null) {
            array_push($queue, ...$current->interfaces);
        }
        $seen = [];
        $result = [];
        while ($queue !== []) {
            $fqn = array_shift($queue);
            $interface = $this->find($fqn);
            if ($interface === null || isset($seen[strtolower($fqn)])) {
                continue;
            }
            $seen[strtolower($fqn)] = true;
            $result[] = $interface;
            array_push($queue, ...$interface->interfaces);
        }

        return $result;
    }

    /** Finds a member on the type, its base classes and its interfaces. */
    public function findMember(TypeSymbol $type, string $name): ?MemberSymbol
    {
        $seen = [];
        $queue = [$type];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current->fqn])) {
                continue;
            }
            $seen[$current->fqn] = true;
            if (isset($current->members[$name])) {
                return $current->members[$name];
            }
            if ($current->baseClass !== null && ($base = $this->find($current->baseClass)) !== null) {
                $queue[] = $base;
            }
            foreach ($current->interfaces as $interface) {
                if (($symbol = $this->find($interface)) !== null) {
                    $queue[] = $symbol;
                }
            }
        }

        return null;
    }

    public function isSubtypeOf(string $fqn, string $baseFqn): bool
    {
        $fqn = ltrim($fqn, '\\');
        $baseFqn = ltrim($baseFqn, '\\');
        if (strcasecmp($fqn, $baseFqn) === 0) {
            return true;
        }
        $symbol = $this->find($fqn);
        if ($symbol === null) {
            return false;
        }
        if ($symbol->baseClass !== null && $this->isSubtypeOf($symbol->baseClass, $baseFqn)) {
            return true;
        }
        foreach ($symbol->interfaces as $interface) {
            if ($this->isSubtypeOf($interface, $baseFqn)) {
                return true;
            }
        }

        return false;
    }

    public function isThrowable(string $fqn): bool
    {
        return $this->isSubtypeOf($fqn, 'Throwable');
    }

    /** True when the class (or one of its ancestors) declares a constructor. */
    public function hasConstructor(string $fqn): bool
    {
        $symbol = $this->find($fqn);
        while ($symbol !== null) {
            if ($symbol->hasConstructor) {
                return true;
            }
            $symbol = $symbol->baseClass !== null ? $this->find($symbol->baseClass) : null;
        }

        return false;
    }

    /**
     * Parameters accepted by "new T(...)": the constructor PHP will run, which may be inherited.
     * Null when the class has a constructor whose parameters are unknown.
     *
     * @return list<ParameterSymbol>|null
     */
    public function constructorParams(TypeSymbol $symbol): ?array
    {
        $current = $symbol;
        while ($current !== null) {
            if ($current->constructorParams !== null) {
                return $current->constructorParams;
            }
            if ($current->hasConstructor) {
                return null;
            }
            $current = $current->baseClass !== null ? $this->find($current->baseClass) : null;
        }

        return [];
    }

    /**
     * The anonymous type with these read-only properties, in this order. Like C#, objects with the
     * same property names and types share one type, named as C# shows it: "<anonymous type: int Id>".
     *
     * @param array<string, Type> $properties
     */
    public function anonymousType(array $properties): Type
    {
        $parts = [];
        foreach ($properties as $name => $type) {
            $parts[] = $type->describe() . ' ' . $name;
        }
        $name = $parts === [] ? Type::ANONYMOUS_PREFIX . '>' : Type::ANONYMOUS_PREFIX . ': ' . implode(', ', $parts) . '>';
        if (!isset($this->anonymous[$name])) {
            $symbol = new TypeSymbol('class', $name, false);
            foreach ($properties as $property => $type) {
                $symbol->addMember(new MemberSymbol('property', $property, false, $type, $symbol));
            }
            $this->anonymous[$name] = $symbol;
        }

        return Type::class($name);
    }

    /** Return type of a global PHP function (strlen => int). */
    public function functionReturnType(string $name): Type
    {
        try {
            $function = new \ReflectionFunction($name);
        } catch (\ReflectionException) {
            return Type::mixed();
        }

        return $this->reflectionType($function->getReturnType(), new TypeSymbol('class', 'stdClass', true));
    }

    // ------------------------------------------------------------------
    // Reflection import
    // ------------------------------------------------------------------

    private function load(string $fqn): ?TypeSymbol
    {
        if ($fqn === '' || !preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff\\\\]*$/', $fqn)) {
            return null;
        }
        if (!class_exists($fqn) && !interface_exists($fqn)) {
            return null;
        }

        $class = new ReflectionClass($fqn);
        $kind = $class->isInterface() ? 'interface' : ($class->isEnum() ? 'enum' : 'class');
        $symbol = new TypeSymbol($kind, $class->getName(), true);
        $this->external[strtolower($fqn)] = $symbol; // guard against recursion

        $symbol->baseClass = $class->getParentClass() !== false ? $class->getParentClass()->getName() : null;
        $symbol->interfaces = $class->getInterfaceNames();
        $symbol->isAbstract = $class->isAbstract() && !$class->isInterface();
        $symbol->hasConstructor = $class->getConstructor() !== null;
        if ($class->getConstructor()?->isPublic()) {
            $this->docFile = $class->getConstructor()->getFileName() ?: null;
            $symbol->constructorParams = $this->reflectionParams($class->getConstructor(), $symbol);
        }

        $this->docFile = $class->getFileName() ?: null;
        $doc = (string) $class->getDocComment();
        if (preg_match_all('/@template\s+(\w+)/', $doc, $m)) {
            $symbol->typeParams = $m[1];
            foreach ($m[1] as $name) {
                $symbol->typeParamTypes[$name] = Type::typeParam($name);
            }
        }
        if (preg_match('/@sharp-element\s+([^\r\n*]+)/', $doc, $m)) {
            $symbol->elementType = $this->docType($m[1], $symbol);
        }

        foreach ($class->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }
            $isCase = $constant->isEnumCase();
            $type = $isCase ? Type::class($symbol->fqn) : $this->valueType($constant->getValue());
            $symbol->addMember(new MemberSymbol($isCase ? 'case' : 'const', $constant->getName(), true, $type, $symbol));
        }

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $this->docFile = $property->getDeclaringClass()->getFileName() ?: null;
            $type = $this->docTag((string) $property->getDocComment(), 'var', $symbol)
                ?? $this->reflectionType($property->getType(), $symbol);
            $symbol->addMember(new MemberSymbol('property', $property->getName(), $property->isStatic(), $type, $symbol));
        }

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $doc = (string) $method->getDocComment();
            $this->docFile = $method->getFileName() ?: null;
            // Generic methods (Enumerable::Where<TSource>): their type parameters are in scope in their docs.
            $this->methodTemplates = preg_match_all('/@template\s+(\w+)/', $doc, $m) > 0 ? $m[1] : [];
            // "@sharp-return char" states a C# type PHP has no name for; "@return" is the PHP view.
            $type = $this->docTag($doc, 'sharp-return', $symbol)
                ?? $this->docTag($doc, 'return', $symbol)
                ?? $this->reflectionType($method->getReturnType(), $symbol);
            $params = $this->reflectionParams($method, $symbol);
            $member = new MemberSymbol('method', $method->getName(), $method->isStatic(), $type, $symbol, $params, $this->methodTemplates);
            $this->methodTemplates = [];
            // Properties and methods live in different PHP namespaces (List.Count vs. list.Count()).
            $symbol->members[isset($symbol->members[$method->getName()]) ? $method->getName() . '()' : $method->getName()] = $member;
        }

        return $symbol;
    }

    /** @return list<ParameterSymbol> */
    private function reflectionParams(ReflectionMethod $method, TypeSymbol $symbol): array
    {
        // "@param T $item" refines untyped (mixed) parameters of generic runtime classes; for an out
        // parameter, "@param-out T $value" is the type of what the method stores (out var x).
        $docTypes = ['param' => [], 'param-out' => []];
        // "@param \Closure(HttpRequest): mixed $handler" types the parameters of a lambda passed to $handler.
        if (preg_match_all('/@(param|param-out)\s+(' . self::CLOSURE_DOC . '|\S+(?:\s*<[^>\r\n]*>)?)\s+&?(?:\.\.\.)?\$(\w+)/', (string) $method->getDocComment(), $m, PREG_SET_ORDER)) {
            foreach ($m as [, $tag, $spec, $name]) {
                $docTypes[$tag][$name] = $spec;
            }
        }
        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $this->reflectionType($parameter->getType(), $symbol);
            $name = $parameter->getName();
            $spec = $parameter->isPassedByReference()
                ? $docTypes['param-out'][$name] ?? $docTypes['param'][$name] ?? null
                : $docTypes['param'][$name] ?? null;
            $untypedClosure = $type->kind === 'closure' && $type->args === [];
            if ($spec !== null && ($type->isUnknown() || $untypedClosure || $parameter->isPassedByReference())) {
                $type = $this->docType($spec, $symbol);
            }
            $params[] = new ParameterSymbol(
                $parameter->getName(),
                $type,
                $parameter->isPassedByReference() ? 'ref' : ($parameter->isVariadic() ? 'params' : null),
                $parameter->isOptional(),
                $this->isNumericUnion($parameter->getType()) || preg_match('/@sharp-char-as-int\s+\$' . $name . '\b/', (string) $method->getDocComment()) === 1,
            );
        }

        return $params;
    }

    private function isNumericUnion(?ReflectionType $type): bool
    {
        if (!$type instanceof ReflectionUnionType) {
            return false;
        }
        $names = array_map(static fn (ReflectionType $t): string => $t instanceof ReflectionNamedType ? $t->getName() : '', $type->getTypes());

        return array_diff($names, ['int', 'float', 'null']) === [];
    }

    private function valueType(mixed $value): Type
    {
        return match (true) {
            is_int($value) => Type::int(),
            is_float($value) => Type::float(),
            is_string($value) => Type::string(),
            is_bool($value) => Type::bool(),
            is_array($value) => Type::array(),
            is_object($value) => Type::class($value::class),
            default => Type::mixed(),
        };
    }

    private function reflectionType(?ReflectionType $type, TypeSymbol $self): Type
    {
        if ($type instanceof ReflectionNamedType) {
            $nullable = $type->allowsNull() && !in_array($type->getName(), ['mixed', 'null'], true);

            return $this->namedType($type->getName(), $self)->withNullable($nullable);
        }
        if ($type instanceof ReflectionUnionType) {
            $parts = array_values(array_filter(
                $type->getTypes(),
                static fn (ReflectionType $t): bool => !($t instanceof ReflectionNamedType && $t->getName() === 'null'),
            ));
            if (count($parts) === 1 && $parts[0] instanceof ReflectionNamedType) {
                return $this->namedType($parts[0]->getName(), $self)->withNullable();
            }
        }

        return Type::mixed();
    }

    private function namedType(string $name, TypeSymbol $self): Type
    {
        return match (strtolower($name)) {
            'int' => Type::int(),
            'float' => Type::float(),
            'string' => Type::string(),
            'char' => Type::char(),
            'bool', 'true', 'false' => Type::bool(),
            'array' => Type::array(),
            'iterable' => Type::iterable(),
            'void', 'never' => Type::void(),
            'null' => Type::null(),
            'mixed', 'object' => Type::mixed(),
            'callable', 'closure' => Type::closure(),
            'self', 'static' => $this->selfType($self),
            default => Type::class($name),
        };
    }

    private function selfType(TypeSymbol $self): Type
    {
        return Type::class($self->fqn, array_values($self->typeParamTypes));
    }

    private function docTag(string $doc, string $tag, TypeSymbol $self): ?Type
    {
        if ($doc === '' || !preg_match('/@' . $tag . '\s+([^\s*]+(?:\s*<[^>\r\n]*>)?)/', $doc, $m)) {
            return null;
        }

        return $this->docType($m[1], $self);
    }

    private function docType(string $spec, TypeSymbol $self): Type
    {
        $spec = trim($spec);
        $parts = self::splitTopLevel($spec, '|');
        $nullable = false;
        $parts = array_values(array_filter($parts, static function (string $p) use (&$nullable): bool {
            if (strtolower(trim($p)) === 'null') {
                $nullable = true;

                return false;
            }

            return true;
        }));
        if (count($parts) !== 1) {
            return Type::mixed();
        }
        $s = trim($parts[0]);
        if (str_starts_with($s, '?')) {
            $nullable = true;
            $s = substr($s, 1);
        }

        if (str_ends_with($s, '[]')) {
            return Type::array($this->docType(substr($s, 0, -2), $self))->withNullable($nullable);
        }

        if (preg_match('/^\\\\?(?:closure|callable)\s*\((.*)\)\s*:\s*(.+)$/is', $s, $m)) {
            $params = trim($m[1]) === '' ? [] : array_map(fn (string $p): Type => $this->docType($p, $self), self::splitTopLevel($m[1], ','));

            return Type::closure([...$params, $this->docType($m[2], $self)])->withNullable($nullable);
        }

        if (preg_match('/^([\\\\\w]+)\s*<(.*)>$/s', $s, $m)) {
            $args = array_map(fn (string $a): Type => $this->docType($a, $self), self::splitTopLevel($m[2], ','));
            $base = strtolower(ltrim($m[1], '\\'));
            $last = $args[array_key_last($args)] ?? Type::mixed();
            $type = match ($base) {
                'array', 'list', 'non-empty-list', 'non-empty-array' => Type::array($last),
                'iterable', 'iterator', 'traversable', 'generator', 'iteratoraggregate' => Type::iterable($last),
                default => Type::class($this->docClass($m[1], $self), $args),
            };

            return $type->withNullable($nullable);
        }

        if (in_array($s, $self->typeParams, true) || in_array($s, $this->methodTemplates, true)) {
            return Type::typeParam($s)->withNullable($nullable);
        }
        if (in_array(strtolower($s), ['int', 'float', 'string', 'char', 'bool', 'array', 'iterable', 'void', 'mixed', 'callable', 'object', 'self', 'static', 'true', 'false', 'never', 'closure', '\\closure'], true)
            || $s === '$this') {
            return $this->namedType(ltrim($s === '$this' ? 'static' : $s, '\\'), $self)->withNullable($nullable);
        }

        return Type::class($this->docClass($s, $self))->withNullable($nullable);
    }

    private function docClass(string $name, TypeSymbol $self): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = explode('\\', $name, 2);
        $imports = $this->importsOf($this->docFile);
        if (isset($imports[strtolower($first[0])])) {
            return $imports[strtolower($first[0])] . (isset($first[1]) ? '\\' . $first[1] : '');
        }
        $candidate = Names::namespaceOf($self->fqn) . '\\' . $name;
        if (class_exists($candidate) || interface_exists($candidate)) {
            return $candidate;
        }

        return $name;
    }

    /** @return array<string, string> lowercase alias => FQN */
    private function importsOf(?string $file): array
    {
        if ($file === null) {
            return [];
        }
        if (!isset($this->fileImports[$file])) {
            $imports = [];
            $code = @file_get_contents($file);
            if ($code !== false && preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $m, PREG_SET_ORDER)) {
                foreach ($m as $use) {
                    $alias = $use[2] ?? '';
                    $alias = $alias !== '' ? $alias : substr($use[1], (int) strrpos('\\' . $use[1], '\\'));
                    $imports[strtolower($alias)] = ltrim($use[1], '\\');
                }
            }
            $this->fileImports[$file] = $imports;
        }

        return $this->fileImports[$file];
    }

    /** @return list<string> */
    private static function splitTopLevel(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];
            if ($c === '<' || $c === '(' || $c === '{') {
                $depth++;
            } elseif ($c === '>' || $c === ')' || $c === '}') {
                $depth--;
            }
            if ($c === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $parts[] = trim($current);

        return $parts;
    }
}
