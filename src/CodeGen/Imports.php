<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

use SharpPhp\Semantics\Names;

/**
 * Collects "use" statements for one PHP namespace block and decides how each class is written.
 */
final class Imports
{
    /** @var array<string, string> lowercase alias => FQN */
    private array $uses = [];

    /** @var array<string, true> short names that must not be imported */
    private array $reserved = [];

    /**
     * @param list<string> $declaredHere short names of classes declared in this block
     */
    public function __construct(private readonly string $namespace, array $declaredHere)
    {
        foreach ($declaredHere as $name) {
            $this->reserved[strtolower($name)] = true;
        }
    }

    /**
     * How a class is written in this namespace block: by its short name when it is in the block's namespace or
     * imported (a use statement is added on its first use); fully qualified (\Foo\Bar) for a global class in a
     * namespaced block, or when its short name is taken by another import or by a class of this namespace. Names
     * compare case-insensitively, as in PHP.
     */
    public function name(string $fqn): string
    {
        $fqn = ltrim($fqn, '\\');
        $ns = Names::namespaceOf($fqn);
        $short = Names::shortName($fqn);
        $key = strtolower($short);

        if (strcasecmp($ns, $this->namespace) === 0) {
            if (isset($this->uses[$key]) && strcasecmp($this->uses[$key], $fqn) !== 0) {
                return '\\' . $fqn;
            }
            $this->reserved[$key] = true;

            return $short;
        }
        if ($ns === '') {
            return '\\' . $fqn;
        }
        if (isset($this->uses[$key])) {
            return strcasecmp($this->uses[$key], $fqn) === 0 ? $short : '\\' . $fqn;
        }
        if (isset($this->reserved[$key])) {
            return '\\' . $fqn;
        }
        $this->uses[$key] = $fqn;

        return $short;
    }

    /**
     * "use" statements for the classes that $code actually references.
     *
     * @return list<string>
     */
    public function statements(string $code): array
    {
        $uses = [];
        foreach ($this->uses as $fqn) {
            $alias = preg_quote(Names::shortName($fqn), '/');
            if (preg_match('/(?<![\\\\\w$>:])' . $alias . '(?![\\w\\\\])/', $code) === 1) {
                $uses[] = $fqn;
            }
        }
        sort($uses, SORT_STRING | SORT_FLAG_CASE);

        return array_map(static fn (string $fqn): string => "use $fqn;", $uses);
    }
}
