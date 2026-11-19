<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Web;

use SharpPhp\Runtime\System\ArgumentException;

/**
 * A route of a WebApp: HTTP methods, a path pattern and its handler.
 *
 * Patterns, as in ASP.NET Core: "/users", "/users/{id}", "/users/{id:int}", "/files/{*path}",
 * "/archive/{year?}". Literal segments match case-insensitively; a trailing "/" is ignored.
 * Constraints: int, long, bool, alpha, guid.
 *
 * @internal used by WebApp
 */
final class Route
{
    private const CONSTRAINTS = [
        'int' => '/^-?\d+$/',
        'long' => '/^-?\d+$/',
        'bool' => '/^(true|false)$/i',
        'alpha' => '/^[a-z]+$/i',
        'guid' => '/^\{?[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}\}?$/i',
    ];

    /** @var list<array{kind: 'literal'|'param'|'optional'|'catchall', text: string, constraint: ?string}> */
    private array $segments = [];

    /**
     * @param list<string> $methods upper-case HTTP methods; empty for every method
     * @param \Closure(HttpRequest): mixed $handler
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly \Closure $handler,
    ) {
        $parts = self::split($pattern);
        foreach ($parts as $i => $part) {
            if (!preg_match('/^\{(\*{1,2})?(\w+)(?::(\w+))?(\?)?\}$/', $part, $m)) {
                if (str_contains($part, '{') || str_contains($part, '}')) {
                    throw new ArgumentException("Invalid route pattern '$pattern': a placeholder must be a whole segment, like {id}");
                }
                $this->segments[] = ['kind' => 'literal', 'text' => $part, 'constraint' => null];
                continue;
            }
            $constraint = ($m[3] ?? '') !== '' ? strtolower($m[3]) : null;
            if ($constraint !== null && !isset(self::CONSTRAINTS[$constraint])) {
                throw new ArgumentException("Unknown route constraint '$constraint' in '$pattern' (use int, long, bool, alpha or guid)");
            }
            $kind = $m[1] !== '' ? 'catchall' : (($m[4] ?? '') === '?' ? 'optional' : 'param');
            if ($kind !== 'param' && $i !== count($parts) - 1) {
                throw new ArgumentException("Invalid route pattern '$pattern': {*{$m[2]}} and {{$m[2]}?} must be the last segment");
            }
            $this->segments[] = ['kind' => $kind, 'text' => $m[2], 'constraint' => $constraint];
        }
    }

    public function allows(string $method): bool
    {
        return $this->methods === [] || in_array($method, $this->methods, true)
            || ($method === 'HEAD' && in_array('GET', $this->methods, true));
    }

    /**
     * The route values when the path matches, else null.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        $parts = self::split($path);
        $values = [];
        foreach ($this->segments as $i => $segment) {
            $part = $parts[$i] ?? null;
            switch ($segment['kind']) {
                case 'catchall':
                    $values[$segment['text']] = implode('/', array_slice($parts, $i));

                    return $values;
                case 'optional':
                    if ($part === null) {
                        return count($parts) === $i ? $values : null;
                    }
                    // a present optional value is checked like a parameter
                    // no break
                case 'param':
                    if ($part === null || ($segment['constraint'] !== null && !preg_match(self::CONSTRAINTS[$segment['constraint']], $part))) {
                        return null;
                    }
                    $values[$segment['text']] = $part;
                    break;
                default:
                    if ($part === null || strcasecmp($part, $segment['text']) !== 0) {
                        return null;
                    }
            }
        }

        return count($parts) === count($this->segments) ? $values : null;
    }

    /**
     * How specific the route is, segment by segment (higher wins): literal text beats a constrained
     * parameter, which beats a parameter, an optional one, and a catch-all. "/users/me" is chosen
     * over "/users/{id}" whatever the order they were mapped in.
     *
     * @return list<int>
     */
    public function precedence(): array
    {
        return array_map(static fn (array $s): int => match ($s['kind']) {
            'literal' => 5,
            'param' => $s['constraint'] !== null ? 4 : 3,
            'optional' => 2,
            'catchall' => 1,
        }, $this->segments);
    }

    /** @return list<string> */
    private static function split(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }
}
