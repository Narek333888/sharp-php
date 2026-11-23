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
    /** The runtime classes of the integral types: their constants have the type. */
    private const INTEGRAL_CLASSES = [
        'SharpPhp\\Runtime\\System\\Int32' => 'int', 'SharpPhp\\Runtime\\System\\Int64' => 'long',
        'SharpPhp\\Runtime\\System\\Int16' => 'short', 'SharpPhp\\Runtime\\System\\UInt16' => 'ushort',
        'SharpPhp\\Runtime\\System\\UInt32' => 'uint', 'SharpPhp\\Runtime\\System\\UInt64' => 'ulong',
        'SharpPhp\\Runtime\\System\\Byte' => 'byte', 'SharpPhp\\Runtime\\System\\SByte' => 'sbyte',
    ];

    /** A closure type in a docblock: \Closure(int, string): bool (PHPStan syntax). */
    private const CLOSURE_DOC = '\\\\?(?:Closure|callable)\([^)\r\n]*\)\s*:\s*[^\s$]+';

    /**
     * A type in a docblock, with generics that may contain spaces and nest (array<string, List<int>>),
     * array suffixes and unions: ?int, string[], array<string, int>|null, $this.
     */
    private const TYPE_DOC = '(?:\$this|\??[\\\\\w-]+(?<g><(?:[^<>]|(?&g))*>)?(?:\[\])*)(?:\|(?:\??[\\\\\w-]+(?&g)?(?:\[\])*))*';

    /** @var array<string, TypeSymbol> */
    private array $project = [];

    /** @var array<string, TypeSymbol|false> lowercase FQN => class loaded through reflection */
    private array $external = [];

    /** @var array<string, true> FQNs (exact case) that no class or autoloader answered to */
    private array $missing = [];

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

    /** @var list<MemberSymbol> extension methods of the project, in declaration order */
    private array $extensions = [];

    /** @var list<MemberSymbol> static members of extension blocks (C# 14) */
    private array $staticExtensions = [];

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

    /** Registers an extension method of a project class (see MemberSymbol::$isExtension). */
    public function addExtension(MemberSymbol $method): void
    {
        $this->extensions[] = $method;
    }

    /**
     * The project's extension methods; which of them a file sees, and which apply to a receiver, is
     * for ExtensionMethods to decide.
     *
     * @return list<MemberSymbol>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    public function addStaticExtension(MemberSymbol $member): void
    {
        $this->staticExtensions[] = $member;
    }

    /**
     * Static members of the project's extension blocks: string.Repeat(...), string.Empty-like properties.
     *
     * @return list<MemberSymbol>
     */
    public function staticExtensions(): array
    {
        return $this->staticExtensions;
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
        if (isset($this->external[$key]) && $this->external[$key] !== false) {
            return $this->external[$key];
        }
        // A class PHP knows is found under any case, but an autoloader (PSR-4 on a case-sensitive file
        // system) may load it only under its exact name: remember a miss for that spelling only, so that
        // looking up "date" does not hide the class Date.
        if (isset($this->missing[$fqn])) {
            return null;
        }
        $symbol = $this->load($fqn);
        if ($symbol === null) {
            $this->missing[$fqn] = true;

            return null;
        }
        $this->external[$key] = $symbol;

        return $symbol;
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

    /**
     * The generic arguments $target has as a supertype of $from with arguments $fromArgs: for
     * "class Box<T> : IBox<T>", Box<Dog> is an IBox<Dog>, so [Dog]. Null when they are not known
     * (a PHP class, a base type named without its arguments).
     *
     * @param list<Type> $fromArgs
     * @return list<Type>|null
     */
    public function supertypeArgs(TypeSymbol $from, array $fromArgs, string $target, int $depth = 0): ?array
    {
        if (strcasecmp($from->fqn, ltrim($target, '\\')) === 0) {
            return count($fromArgs) === count($from->typeParams) ? $fromArgs : null;
        }
        if ($depth > 32) {
            return null;
        }
        $map = [];
        foreach ($from->typeParams as $i => $name) {
            $map[$name] = $fromArgs[$i] ?? Type::mixed();
        }
        foreach ([$from->baseClass, ...$from->interfaces] as $super) {
            if ($super === null || !$this->isSubtypeOf($super, $target)) {
                continue;
            }
            $symbol = $this->find($super);
            $args = $from->supertypeArgs[strtolower($super)] ?? null;
            if ($symbol === null || $args === null) {
                return null;
            }
            $result = $this->supertypeArgs($symbol, array_map(static fn (Type $t): Type => $t->substitute($map), $args), $target, $depth + 1);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
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

    /**
     * Parameters of a global PHP function, so that arguments are converted like for methods
     * (a List passed where the function takes an array). By-reference parameters are left untyped.
     *
     * @return list<ParameterSymbol>
     */
    public function functionParams(string $name): array
    {
        try {
            $function = new \ReflectionFunction($name);
        } catch (\ReflectionException) {
            return [];
        }
        $this->docFile = null;
        $params = [];
        foreach ($this->reflectionParams($function, new TypeSymbol('class', 'stdClass', true)) as $param) {
            $params[] = $param->modifier === 'ref' ? new ParameterSymbol($param->name, Type::mixed(), 'ref', $param->optional) : $param;
        }

        return $params;
    }

    /** Classes of the SharpPHP runtime library keep C# arrays; other PHP code has PHP arrays. */
    private function isRuntime(TypeSymbol $self): bool
    {
        return str_starts_with($self->fqn, Names::RUNTIME_NAMESPACE . '\\');
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
        if ($class->getFileName() !== false) {
            $symbol->location = [$class->getFileName(), (int) $class->getStartLine(), 1];
        }

        $symbol->baseClass = $class->getParentClass() !== false ? $class->getParentClass()->getName() : null;
        $symbol->interfaces = $class->getInterfaceNames();
        $symbol->isAbstract = $class->isAbstract() && !$class->isInterface();
        $symbol->hasConstructor = $class->getConstructor() !== null;

        $this->docFile = $class->getFileName() ?: null;
        $doc = (string) $class->getDocComment();
        if (preg_match_all('/@(?:sharp-)?template\s+(\w+)/', $doc, $m)) {
            $symbol->typeParams = $m[1];
            foreach ($m[1] as $name) {
                $symbol->typeParamTypes[$name] = Type::typeParam($name);
            }
        }
        if (preg_match('/@sharp-element\s+([^\r\n*]+)/', $doc, $m)) {
            $symbol->elementType = $this->docType($m[1], $symbol);
        }
        // After the class's @template tags: "@param iterable<T> $collection" means the class's T.
        if ($class->getConstructor()?->isPublic()) {
            $this->docFile = $class->getConstructor()->getFileName() ?: null;
            $this->methodTemplates = preg_match_all('/@(?:sharp-)?template\s+(\w+)/', (string) $class->getConstructor()->getDocComment(), $cm) > 0 ? $cm[1] : [];
            $symbol->constructorParams = $this->reflectionParams($class->getConstructor(), $symbol);
            $this->methodTemplates = [];
        }
        // A runtime class that is a struct in .NET (DateTime, TimeSpan): a value type to C# code.
        $symbol->isStruct = str_contains($doc, '@sharp-struct');

        foreach ($class->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }
            $isCase = $constant->isEnumCase();
            if ($isCase && str_contains((string) $constant->getDocComment(), '@internal')) {
                continue; // a combination of a flags enum's cases (StringSplitOptions: A | B)
            }
            $type = $isCase ? Type::class($symbol->fqn) : $this->valueType($constant->getValue());
            // int.MaxValue is an int, long.MaxValue a long (PHP's integers have no size).
            $integral = self::INTEGRAL_CLASSES[$symbol->fqn] ?? null;
            if ($integral !== null && $type->isInt()) {
                $type = Type::integral($integral);
            }
            $symbol->addMember(new MemberSymbol($isCase ? 'case' : 'const', $constant->getName(), true, $type, $symbol));
        }

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $this->docFile = $property->getDeclaringClass()->getFileName() ?: null;
            $type = $this->docTag((string) $property->getDocComment(), 'var', $symbol)
                ?? $this->reflectionType($property->getType(), $symbol);
            $symbol->addMember(new MemberSymbol('property', $property->getName(), $property->isStatic(), $type, $symbol));
        }

        $instanceVariants = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $doc = (string) $method->getDocComment();
            $this->docFile = $method->getFileName() ?: null;
            // Generic methods (Enumerable::Where<TSource>): their type parameters are in scope in their docs.
            $this->methodTemplates = preg_match_all('/@(?:sharp-)?template\s+(\w+)/', $doc, $m) > 0 ? $m[1] : [];
            // "@sharp-return char" states a C# type PHP has no name for; "@return" is the PHP view.
            $type = $this->docTag($doc, 'sharp-return', $symbol)
                ?? $this->docTag($doc, 'return', $symbol)
                ?? $this->reflectionType($method->getReturnType(), $symbol);
            $params = $this->reflectionParams($method, $symbol);
            // "@sharp-reified TEnum $enumClass": the compiler passes the PHP class of TEnum there
            // (Enum.Parse<Color>("Red")); C# code does not see the parameter.
            $reified = [];
            if (preg_match_all('/@sharp-reified\s+(\w+)\s+\$(\w+)/', $doc, $rm, PREG_SET_ORDER) > 0) {
                foreach ($rm as [, $typeParam, $paramName]) {
                    $reified[$typeParam] = $paramName;
                }
                $params = array_values(array_filter($params, static fn (ParameterSymbol $p): bool => !in_array($p->name, $reified, true)));
            }
            $member = new MemberSymbol('method', $method->getName(), $method->isStatic(), $type, $symbol, $params, $this->methodTemplates);
            $member->reified = $reified;
            if ($method->getFileName() !== false) {
                $member->location = [$method->getFileName(), (int) $method->getStartLine(), 1];
            }
            if ($method->isStatic() && preg_match('/@sharp-operator\s+(\S+)/', $doc, $m) === 1) {
                $member->operator = $m[1];
            }
            $this->methodTemplates = [];
            // "@sharp-instance IsMatch": what regex.IsMatch(input) calls, next to the static Regex.IsMatch(input, pattern).
            if (!$method->isStatic() && preg_match('/@sharp-instance\s+(\w+)/', $doc, $m) === 1) {
                $member->csharpName = $m[1];
                $instanceVariants[$m[1]] = $member;
                continue;
            }
            // Properties and methods live in different PHP namespaces (List.Count vs. list.Count()).
            $symbol->members[isset($symbol->members[$method->getName()]) ? $method->getName() . '()' : $method->getName()] = $member;
        }
        foreach ($instanceVariants as $name => $variant) {
            $static = $symbol->members[$name . '()'] ?? $symbol->members[$name] ?? null;
            if ($static !== null && $static->kind === 'method') {
                $static->instanceVariant = $variant;
            }
        }

        // A static method get_Name() is the static property Name (Environment.TickCount), as for project
        // classes: PHP has no static property hooks.
        foreach ($symbol->members as $member) {
            if ($member->kind === 'method' && $member->isStatic && str_starts_with($member->name, 'get_') && strlen($member->name) > 4
                && array_filter($member->params, static fn (ParameterSymbol $p): bool => !$p->optional) === []) {
                $name = substr($member->name, 4);
                if (!isset($symbol->members[$name])) {
                    $property = new MemberSymbol('property', $name, true, $member->type, $symbol);
                    $property->isStaticAccessor = true;
                    $property->location = $member->location;
                    $symbol->members[$name] = $property;
                }
            }
        }

        $this->docFile = $class->getFileName() ?: null;
        $this->addMagicMembers($symbol, (string) $class->getDocComment(), $class);

        return $symbol;
    }

    /**
     * Members a PHP class has through __call / __callStatic / __get, as its docblock declares them:
     * "@method static Builder where(string $column, mixed $value = null)" on Laravel's facades and
     * models, "@property string $name". Real members win over documented ones.
     *
     * @param \ReflectionClass<object> $class
     */
    private function addMagicMembers(TypeSymbol $symbol, string $doc, \ReflectionClass $class): void
    {
        if ($doc === '') {
            return;
        }
        $location = $class->getFileName() !== false ? [$class->getFileName(), (int) $class->getStartLine(), 1] : null;
        $pattern = '/@method\s+(?<static>static\s+)?(?:(?<type>' . self::TYPE_DOC . ')\s+)?(?<name>\w+)\s*\((?<params>[^)]*)\)/';
        if (preg_match_all($pattern, $doc, $methods, PREG_SET_ORDER) > 0) {
            foreach ($methods as $m) {
                if (isset($symbol->members[$m['name']]) || isset($symbol->members[$m['name'] . '()'])) {
                    continue;
                }
                $type = ($m['type'] ?? '') !== '' ? $this->docType($m['type'], $symbol) : Type::mixed();
                $member = new MemberSymbol('method', $m['name'], ($m['static'] ?? '') !== '', $type, $symbol, $this->docParams($m['params'], $symbol));
                $member->location = $location;
                $symbol->members[$m['name']] = $member;
            }
        }
        if (preg_match_all('/@property(?:-read|-write)?\s+(?<type>' . self::TYPE_DOC . ')\s+\$(?<name>\w+)/', $doc, $properties, PREG_SET_ORDER) > 0) {
            foreach ($properties as $p) {
                if (!isset($symbol->members[$p['name']])) {
                    $member = new MemberSymbol('property', $p['name'], false, $this->docType($p['type'], $symbol), $symbol);
                    $member->location = $location;
                    $symbol->members[$p['name']] = $member;
                }
            }
        }
    }

    /**
     * Parameters of a documented method: "string $uri, array|callable|null $action = null, mixed ...$args".
     *
     * @return list<ParameterSymbol>
     */
    private function docParams(string $list, TypeSymbol $self): array
    {
        $params = [];
        foreach (self::splitTopLevel($list, ',') as $part) {
            if (preg_match('/^(?:(?<type>' . self::CLOSURE_DOC . '|' . self::TYPE_DOC . ')\s+)?(?<ref>&)?(?<variadic>\.\.\.)?\$(?<name>\w+)(?<default>\s*=.*)?$/s', trim($part), $m) !== 1) {
                continue;
            }
            $type = ($m['type'] ?? '') !== '' ? $this->docType($m['type'], $self) : Type::mixed();
            $modifier = ($m['ref'] ?? '') !== '' ? 'ref' : (($m['variadic'] ?? '') !== '' ? 'params' : null);
            if ($modifier === 'params') {
                $type = Type::array($type);
            } elseif ($modifier === 'ref') {
                $type = Type::mixed();
            }
            $params[] = new ParameterSymbol($m['name'], $type, $modifier, ($m['default'] ?? '') !== '' || $modifier === 'params');
        }

        return $params;
    }

    /** @return list<ParameterSymbol> */
    private function reflectionParams(\ReflectionFunctionAbstract $method, TypeSymbol $symbol): array
    {
        // "@param T $item" refines untyped (mixed) parameters of generic runtime classes; for an out
        // parameter, "@param-out T $value" is the type of what the method stores (out var x).
        $docTypes = ['param' => [], 'param-out' => []];
        // "@param \Closure(HttpRequest): mixed $handler" types the parameters of a lambda passed to $handler.
        // "@sharp-param T $x" / "@sharp-param-out T $x" state the C# type where PHPStan needs a PHP one.
        if (preg_match_all('/@(?<sharp>sharp-)?(param|param-out)\s+(?<spec>' . self::CLOSURE_DOC . '|' . self::TYPE_DOC . ')\s+&?(?:\.\.\.)?\$(?<name>\w+)/', (string) $method->getDocComment(), $m, PREG_SET_ORDER)) {
            foreach ([false, true] as $sharp) {
                foreach ($m as $match) {
                    if (($match['sharp'] !== '') === $sharp) {
                        $docTypes[$match[2]][$match['name']] = $match['spec'];
                    }
                }
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
            // "@param iterable<TSource> $source": the element type, which binds TSource from the receiver.
            $untypedSequence = $type->kind === 'iterable' && $type->element()->isUnknown();
            if ($spec !== null && ($type->isUnknown() || $untypedClosure || $untypedSequence || $parameter->isPassedByReference())) {
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
            is_int($value) => Type::integral(Type::UNSIZED),
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
            // PHP's int does not say byte or long: it converts to and from every C# integral type.
            'int' => Type::integral(Type::UNSIZED),
            // C#'s integral types in docblocks of the runtime library (@sharp-return byte).
            'byte', 'sbyte', 'short', 'ushort', 'uint', 'long', 'ulong' => Type::integral(strtolower($name)),
            'float' => Type::float(),
            'string' => Type::string(),
            'char' => Type::char(),
            'bool', 'true', 'false' => Type::bool(),
            'array' => $this->isRuntime($self) ? Type::array() : Type::phpArray(),
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
        if ($doc === '' || !preg_match('/@' . $tag . '\s+(?<spec>' . self::CLOSURE_DOC . '|' . self::TYPE_DOC . ')/', $doc, $m)) {
            return null;
        }

        return $this->docType($m['spec'], $self);
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
            $element = $this->docType(substr($s, 0, -2), $self);

            return ($this->isRuntime($self) ? Type::array($element) : Type::phpArray($element))->withNullable($nullable);
        }

        // PHPStan's refined scalars: int<0, max>, positive-int, non-empty-string, class-string<T>, array-key.
        $base = strtolower((string) preg_replace('/\s*<.*$/s', '', $s));
        $scalar = match (true) {
            $base === 'int' && str_contains($s, '<'), str_ends_with($base, '-int'), $base === 'int-mask', $base === 'int-mask-of' => Type::integral(Type::UNSIZED),
            str_ends_with($base, '-string') => Type::string(),
            $base === 'array-key', $base === 'scalar', $base === 'mixed' && str_contains($s, '<') => Type::mixed(),
            str_ends_with($base, '-float') => Type::float(),
            default => null,
        };
        if ($scalar !== null) {
            return $scalar->withNullable($nullable && !$scalar->isUnknown());
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
                'array', 'list', 'non-empty-list', 'non-empty-array' => $this->isRuntime($self) ? Type::array($last) : Type::phpArray($last),
                'iterable', 'iterator', 'traversable', 'generator', 'iteratoraggregate' => Type::iterable($last),
                default => Type::class($this->docClass($m[1], $self), $args),
            };

            return $type->withNullable($nullable);
        }

        if (in_array($s, $self->typeParams, true) || in_array($s, $this->methodTemplates, true)) {
            return Type::typeParam($s)->withNullable($nullable);
        }
        if (in_array(strtolower($s), ['int', 'float', 'string', 'char', 'bool', 'array', 'iterable', 'void', 'mixed', 'callable', 'object', 'self', 'static', 'true', 'false', 'never', 'closure', '\\closure', 'byte', 'sbyte', 'short', 'ushort', 'uint', 'long', 'ulong'], true)
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
