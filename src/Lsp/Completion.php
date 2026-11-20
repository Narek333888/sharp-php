<?php

declare(strict_types=1);

namespace SharpPhp\Lsp;

use SharpPhp\Compiler\Declarator;
use SharpPhp\Compiler\SemanticModel;
use SharpPhp\Paths;
use SharpPhp\Semantics\Conversions;
use SharpPhp\Semantics\ExtensionMethods;
use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\ParameterSymbol;
use SharpPhp\Semantics\ResolutionContext;
use SharpPhp\Semantics\SymbolTable;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeSymbol;

/**
 * Completion: members after a dot (user. => Name, Age, Greet()), and otherwise the names visible
 * at the cursor (locals, members of the class, types, keywords).
 *
 * Code being typed does not compile ("user." has no member name yet), so DraftAnalyzer analyzes a
 * copy of the document with a placeholder name at the cursor, and the compiler records what the
 * placeholder's receiver is, or what is in scope.
 *
 * @phpstan-type Item array{label: string, kind: int, sortText: string, detail?: string}
 * @phpstan-import-type Receiver from SemanticModel
 * @phpstan-import-type ScopeAt from SemanticModel
 */
final class Completion
{
    /** LSP CompletionItemKind */
    private const METHOD = 2;
    private const FIELD = 5;
    private const VARIABLE = 6;
    private const CLASS_ = 7;
    private const INTERFACE = 8;
    private const MODULE = 9;
    private const PROPERTY = 10;
    private const ENUM = 13;
    private const KEYWORD = 14;
    private const ENUM_MEMBER = 20;
    private const CONSTANT = 21;
    private const STRUCT = 22;

    private const KEYWORDS = [
        'abstract', 'as', 'base', 'bool', 'break', 'case', 'catch', 'char', 'class', 'const', 'continue', 'default',
        'do', 'double', 'else', 'enum', 'false', 'finally', 'for', 'foreach', 'get', 'if', 'in', 'init', 'int',
        'interface', 'internal', 'is', 'long', 'namespace', 'new', 'null', 'object', 'out', 'override', 'params',
        'private', 'protected', 'public', 'readonly', 'record', 'ref', 'return', 'sealed', 'set', 'static', 'string',
        'struct', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'nameof', 'using', 'var', 'virtual', 'void',
        'when', 'where', 'while', 'with', 'yield',
    ];

    /** PHP methods behind C# features (indexers, foreach, Count), not called by name. */
    private const HIDDEN_METHODS = [
        'offsetget', 'offsetset', 'offsetexists', 'offsetunset', 'getiterator', 'count', 'jsonserialize',
        'current', 'key', 'next', 'rewind', 'valid', 'fromvalue', 'ofclass', 'ofspec',
    ];

    /** Members every object has (System.Object). */
    private const OBJECT_MEMBERS = [
        ['ToString', 'string ToString()'],
        ['Equals', 'bool Equals(object obj)'],
        ['GetHashCode', 'int GetHashCode()'],
        ['GetType', 'Type GetType()'],
    ];

    private readonly DraftAnalyzer $drafts;

    public function __construct(private readonly Workspace $workspace)
    {
        $this->drafts = new DraftAnalyzer($workspace);
    }

    /**
     * @return list<Item> LSP CompletionItems
     */
    public function complete(string $path, int $line, int $character): array
    {
        $text = $this->workspace->text($path) ?? '';
        $lines = explode("\n", $text);
        $lineText = rtrim($lines[$line] ?? '', "\r");
        $before = mb_substr($lineText, 0, $character);
        $after = mb_substr($lineText, $character);

        $prefix = preg_match('/[A-Za-z_][A-Za-z0-9_]*$/u', $before, $m) === 1 ? $m[0] : '';
        $start = $character - mb_strlen($prefix);
        $head = mb_substr($lineText, 0, $start);
        $tail = preg_replace('/^[A-Za-z0-9_]+/u', '', $after) ?? $after;

        if (preg_match('/^\s*using\s+(?:static\s+)?([\w.]*)$/u', $head . $prefix, $u) === 1) {
            $dot = strrpos($u[1], '.');

            return $this->namespaceItems($dot === false ? '' : substr($u[1], 0, $dot), typesToo: false);
        }
        $afterDot = preg_match('/\.$/', $head) === 1;

        $analysis = $this->drafts->withPlaceholder($path, $lines, $line, $head, $tail);
        if ($analysis === null) {
            return $afterDot ? [] : $this->fallbackItems();
        }
        [$model, $symbols, $realPath] = $analysis;

        if ($afterDot) {
            $receiver = $model->receiverAt($realPath, $line + 1, $start + 1);

            return $receiver === null ? [] : self::unique($this->memberItems($receiver, $symbols));
        }
        $scope = $model->scopeAt($realPath, $line + 1, $start + 1);

        return $scope === null ? $this->fallbackItems($symbols) : $this->nameItems($scope, $symbols);
    }

    /**
     * What can follow "receiver.": a namespace's namespaces and types, a type's static members, or a
     * value's members and the extension methods that apply to it.
     *
     * @param Receiver $receiver
     * @return list<Item>
     */
    private function memberItems(array $receiver, SymbolTable $symbols): array
    {
        return match (true) {
            $receiver['kind'] === 'namespace' => $this->namespaceItems((string) $receiver['namespace'], $symbols),
            $receiver['kind'] === 'type' && $receiver['symbol'] !== null => $this->typeMembers($receiver['symbol'], true, $receiver['class'], $symbols),
            $receiver['kind'] === 'value' => [...$this->valueMembers($receiver, $symbols), ...$this->extensionItems($receiver['type'], $receiver['context'], $symbols)],
            default => $this->valueMembers($receiver, $symbols),
        };
    }

    /**
     * Members of a value: a string's, an array's or a number's as the runtime library provides them,
     * or its class's.
     *
     * @param Receiver $receiver
     * @return list<Item>
     */
    private function valueMembers(array $receiver, SymbolTable $symbols): array
    {
        $type = $receiver['type'];

        return match (true) {
            $type->isString() => [
                self::item('Length', self::PROPERTY, 'int string.Length'),
                ...$this->helperMembers(Names::RUNTIME_NAMESPACE . '\\System\\String_', 'string', $symbols),
                ...self::objectItems(),
            ],
            $type->kind === 'array' || $type->kind === 'iterable' => [
                ...($type->kind === 'array' ? [self::item('Length', self::PROPERTY, 'int Array.Length')] : []),
                ...$this->helperMembers(Names::RUNTIME_NAMESPACE . '\\System\\Linq\\Enumerable', $type->describe(), $symbols),
                ...self::objectItems(),
            ],
            $type->isNumeric() || $type->isBool() || $type->isChar() => [
                ...self::objectItems(),
                self::item('CompareTo', self::METHOD, 'int CompareTo(object value)'),
            ],
            $receiver['symbol'] !== null => $this->typeMembers($receiver['symbol'], false, $receiver['class'], $symbols),
            default => self::objectItems(),
        };
    }

    /**
     * Extension methods in scope that apply to a value of type $type, shown as C# shows them: without
     * their "this" parameter ("string StringExtensions.Shout()").
     *
     * @return list<Item>
     */
    private function extensionItems(Type $type, ResolutionContext $context, SymbolTable $symbols): array
    {
        $items = [];
        foreach ((new ExtensionMethods($symbols, new Conversions($symbols)))->applicable($type, $context) as $method) {
            $detail = $method->type->describe() . " {$method->owner->shortName()}.{$method->name}(" . self::describeParams(array_slice($method->params, 1)) . ')';
            $items[] = self::item($method->name, self::METHOD, $detail);
        }

        return $items;
    }

    /**
     * Members of a class (inherited ones too): static ones for "Type.", instance ones for "value.".
     *
     * @return list<Item>
     */
    private function typeMembers(TypeSymbol $type, bool $static, ?TypeSymbol $current, SymbolTable $symbols): array
    {
        $items = [];
        $seen = [];
        foreach ($this->hierarchy($type, $symbols) as $symbol) {
            foreach ($symbol->members as $member) {
                $isStatic = $member->isStatic || $member->kind === 'const' || $member->kind === 'case';
                $key = strtolower($member->name);
                if (isset($seen[$key]) || $isStatic !== $static || !$this->visible($member, $current, $symbols) || $this->hidden($member)) {
                    continue;
                }
                $seen[$key] = true;
                // The class's own members first (a facade's documented methods before Facade's spy() and
                // shouldReceive()), then inherited ones. Reflection lists inherited PHP methods as the
                // class's own, so the file that declares the member decides.
                $own = $symbol === $type && ($member->location === null || $type->location === null || $member->location[0] === $type->location[0]);
                $item = self::memberItem($member);
                $item['sortText'] = ($own ? '0' : '1') . $member->name;
                $items[] = $item;
            }
        }
        if (!$static) {
            foreach (self::objectItems() as $item) {
                if (!isset($seen[strtolower($item['label'])])) {
                    $item['sortText'] = '2' . $item['label'];
                    $items[] = $item;
                }
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['sortText'], $b['sortText']));

        return $items;
    }

    /**
     * Static runtime helpers called like instance methods: text.ToUpper() is String_::ToUpper($text),
     * list.Where(...) on an array is Enumerable::Where($array, ...).
     *
     * @return list<Item>
     */
    private function helperMembers(string $class, string $receiverName, SymbolTable $symbols): array
    {
        $symbol = $symbols->find($class);
        if ($symbol === null) {
            return [];
        }
        $items = [];
        foreach ($symbol->members as $member) {
            $first = $member->params[0] ?? null;
            if ($member->kind !== 'method' || !$member->isStatic || $first === null || !in_array($first->name, ['s', 'source'], true) || $this->hidden($member)) {
                continue;
            }
            $params = array_slice($member->params, 1);
            $items[] = self::item($member->name, self::METHOD, $member->type->describe() . " $receiverName.{$member->name}(" . self::describeParams($params) . ')');
        }

        return $items;
    }

    /**
     * @param ScopeAt $scope
     * @return list<Item>
     */
    private function nameItems(array $scope, SymbolTable $symbols): array
    {
        $items = [];
        foreach ($scope['locals'] as $name => $type) {
            if ($name !== 'this') {
                $items[] = self::item($name, self::VARIABLE, $type->describe() . ' ' . $name, '0');
            }
        }
        $class = $scope['class'];
        if ($class !== null && $class->fqn !== Declarator::TOP_LEVEL_CLASS) {
            $members = $this->typeMembers($class, true, $class, $symbols);
            if (!$scope['static']) {
                $members = [...$members, ...$this->typeMembers($class, false, $class, $symbols)];
            }
            foreach ($members as $item) {
                $item['sortText'] = '1' . $item['label'];
                $items[] = $item;
            }
        }
        foreach ($this->typeItems($scope['context'], $symbols) as $item) {
            $items[] = $item;
        }
        foreach (self::KEYWORDS as $keyword) {
            $items[] = self::item($keyword, self::KEYWORD, null, '3');
        }

        return $items;
    }

    /**
     * Types visible through the file's namespace and using directives.
     *
     * @return list<Item>
     */
    private function typeItems(?ResolutionContext $context, SymbolTable $symbols): array
    {
        $namespaces = $context !== null
            ? array_values(array_unique([...ResolutionContext::IMPLICIT_USINGS, $context->namespace, ...$context->usings]))
            : ResolutionContext::IMPLICIT_USINGS;
        $items = [];
        $seen = [];
        foreach ($symbols->projectTypes() as $type) {
            $namespace = $type->csharpNamespace();
            if ($type->fqn === Declarator::TOP_LEVEL_CLASS || ($context !== null && !in_array($namespace, $namespaces, true) && $namespace !== '')) {
                continue;
            }
            $seen[$type->shortName()] = true;
            $items[] = self::typeItem($type);
        }
        foreach ($namespaces as $namespace) {
            foreach (self::runtimeTypes($namespace) as $name) {
                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    $items[] = self::item($name, self::CLASS_, $namespace . '.' . $name, '2');
                }
            }
        }

        return $items;
    }

    /**
     * After "using " or "System.": namespaces and types inside $namespace ('' for the top level).
     *
     * @return list<Item>
     */
    private function namespaceItems(string $namespace, ?SymbolTable $symbols = null, bool $typesToo = true): array
    {
        $children = [];
        $all = self::runtimeNamespaces();
        if ($symbols !== null) {
            foreach ($symbols->projectTypes() as $type) {
                $all[] = $type->csharpNamespace();
            }
        } else {
            foreach ($this->workspace->sources() as $file) {
                if (preg_match_all('/^\s*namespace\s+([\w.]+)/m', $file->text, $m) > 0) {
                    array_push($all, ...$m[1]);
                }
            }
        }
        $prefix = $namespace === '' ? '' : $namespace . '.';
        foreach ($all as $candidate) {
            if ($candidate !== '' && str_starts_with($candidate . '.', $prefix) && $candidate !== $namespace) {
                $next = explode('.', substr($candidate, strlen($prefix)))[0];
                $children[$next] = self::item($next, self::MODULE, 'namespace ' . $prefix . $next);
            }
        }
        $items = array_values($children);
        if ($namespace !== '' && $typesToo) {
            foreach (self::runtimeTypes($namespace) as $name) {
                $items[] = self::item($name, self::CLASS_, $namespace . '.' . $name);
            }
            foreach ($symbols?->projectTypes() ?? [] as $type) {
                if ($type->csharpNamespace() === $namespace) {
                    $items[] = self::typeItem($type);
                }
            }
        }

        return $items;
    }

    /** @return list<Item> keywords, project types and System types, when the code cannot be analyzed */
    private function fallbackItems(?SymbolTable $symbols = null): array
    {
        $items = $symbols !== null ? $this->typeItems(null, $symbols) : array_map(
            static fn (string $name): array => self::item($name, self::CLASS_, 'System.' . $name, '2'),
            self::runtimeTypes('System'),
        );
        foreach (self::KEYWORDS as $keyword) {
            $items[] = self::item($keyword, self::KEYWORD, null, '3');
        }

        return $items;
    }

    /** @return list<TypeSymbol> the type, its base classes and interfaces */
    private function hierarchy(TypeSymbol $type, SymbolTable $symbols): array
    {
        $result = [];
        $queue = [$type];
        $seen = [];
        while ($queue !== []) {
            $symbol = array_shift($queue);
            if (isset($seen[$symbol->fqn])) {
                continue;
            }
            $seen[$symbol->fqn] = true;
            $result[] = $symbol;
            foreach ([$symbol->baseClass, ...$symbol->interfaces] as $parent) {
                if ($parent !== null && ($found = $symbols->find($parent)) !== null) {
                    $queue[] = $found;
                }
            }
        }

        return $result;
    }

    private function visible(MemberSymbol $member, ?TypeSymbol $current, SymbolTable $symbols): bool
    {
        return match ($member->visibility) {
            'private' => $current !== null && strcasecmp($current->fqn, $member->owner->fqn) === 0,
            'protected' => $current !== null && (strcasecmp($current->fqn, $member->owner->fqn) === 0 || $symbols->isSubtypeOf($current->fqn, $member->owner->fqn)),
            default => true,
        };
    }

    private function hidden(MemberSymbol $member): bool
    {
        $name = $member->name;
        if (str_starts_with($name, '__') || str_ends_with($name, '()') || preg_match('/^[gs]et_/', $name) === 1) {
            return true;
        }
        if (!$member->owner->external || $member->kind !== 'method') {
            return false;
        }
        if (in_array(strtolower($name), self::HIDDEN_METHODS, true)) {
            return true;
        }
        try {
            $doc = (string) (new \ReflectionMethod($member->owner->fqn, $name))->getDocComment();
        } catch (\ReflectionException) {
            return false;
        }

        return str_contains($doc, '@internal');
    }

    /**
     * @param list<Item> $items
     * @return list<Item> the first item of each label
     */
    private static function unique(array $items): array
    {
        $seen = [];

        return array_values(array_filter($items, static function (array $item) use (&$seen): bool {
            $label = $item['label'];
            if (isset($seen[$label])) {
                return false;
            }

            return $seen[$label] = true;
        }));
    }

    /** @return list<Item> */
    private static function objectItems(): array
    {
        return array_map(static fn (array $m): array => self::item($m[0], self::METHOD, $m[1], '1'), self::OBJECT_MEMBERS);
    }

    /** @return Item */
    private static function memberItem(MemberSymbol $member): array
    {
        $owner = $member->owner->csharpName();
        [$kind, $detail] = match ($member->kind) {
            'method' => [self::METHOD, $member->type->describe() . " $owner.{$member->name}(" . self::describeParams($member->params) . ')'],
            'field' => [self::FIELD, $member->type->describe() . " $owner.{$member->name}"],
            'const' => [self::CONSTANT, 'const ' . $member->type->describe() . " $owner.{$member->name}"],
            'case' => [self::ENUM_MEMBER, "$owner.{$member->name}"],
            default => [self::PROPERTY, $member->type->describe() . " $owner.{$member->name}"],
        };

        return self::item($member->name, $kind, $detail);
    }

    /** @return Item */
    private static function typeItem(TypeSymbol $type): array
    {
        $kind = match (true) {
            $type->isInterface() => self::INTERFACE,
            $type->isEnum() => self::ENUM,
            $type->isStruct => self::STRUCT,
            default => self::CLASS_,
        };

        return self::item($type->shortName(), $kind, $type->csharpName(), '2');
    }

    /** @param list<ParameterSymbol> $params */
    private static function describeParams(array $params): string
    {
        return implode(', ', array_map(
            static fn (ParameterSymbol $p): string => ($p->modifier !== null ? $p->modifier . ' ' : '') . $p->type->describe() . ' ' . $p->name,
            $params,
        ));
    }

    /** @return Item */
    private static function item(string $label, int $kind, ?string $detail = null, string $sortGroup = '1'): array
    {
        $item = ['label' => $label, 'kind' => $kind, 'sortText' => $sortGroup . $label];
        if ($detail !== null) {
            $item['detail'] = $detail;
        }

        return $item;
    }

    /**
     * Classes of the runtime library in a System namespace: "System.Collections.Generic" => List, Dictionary, ...
     *
     * @return list<string>
     */
    private static function runtimeTypes(string $namespace): array
    {
        if ($namespace !== 'System' && !str_starts_with($namespace, 'System.')) {
            return [];
        }
        $dir = dirname(__DIR__) . '/Runtime/' . str_replace('.', '/', $namespace);
        $names = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $fqn = Names::phpNamespace($namespace) . '\\' . basename($file, '.php');
            if (!class_exists($fqn) && !interface_exists($fqn) && !enum_exists($fqn)) {
                continue; // a trait (EnumerableMethods)
            }
            if (!str_contains((string) (new \ReflectionClass($fqn))->getDocComment(), '@internal')) {
                $name = Names::csharpName($fqn);
                $names[] = substr((string) strrchr('.' . $name, '.'), 1);
            }
        }

        return $names;
    }

    /** @return list<string> System, System.Collections, System.Collections.Generic, ... */
    private static function runtimeNamespaces(): array
    {
        $base = dirname(__DIR__) . '/Runtime';
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/System', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        $result[] = 'System';
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir()) {
                // "System/Collections/Generic" ("System\Collections\Generic" on Windows) => System.Collections.Generic
                $result[] = str_replace('/', '.', (string) Paths::relative($file->getPathname(), $base));
            }
        }

        return $result;
    }
}
