<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\RegularExpressions;

use SharpPhp\Runtime\System\ArgumentException;

/**
 * System.Text.RegularExpressions.Regex, on PHP's PCRE (.NET's syntax is the same for nearly all
 * patterns: groups, (?<name>...), lookarounds, \d \w \s \b, lazy quantifiers ...).
 *
 *   Regex.IsMatch(email, @"^[^@\s]+@[^@\s]+\.[a-z]+$", RegexOptions.IgnoreCase)
 *   Regex.Replace("a1b2", @"\d", "#")                       // "a#b#"
 *   Regex.Replace(text, @"\d+", m => (int.Parse(m.Value) * 2).ToString())
 *   foreach (Match m in Regex.Matches(text, @"(?<word>\w+)")) Console.WriteLine(m.Groups["word"].Value);
 *   private static readonly Regex Digits = new(@"\d+");     Digits.IsMatch(input)
 *
 * Replacements use .NET's $1, ${name}, $0 / $& and $$.
 */
final class Regex
{
    /** The PCRE pattern, with delimiters and modifiers. */
    private readonly string $pcre;

    public function __construct(private readonly string $pattern, RegexOptions|int $options = RegexOptions::None)
    {
        $this->pcre = self::compile($pattern, $options);
    }

    /**
     * Regex.IsMatch(input, pattern) or IsMatch(input, pattern, options): whether the pattern
     * matches somewhere in input; ArgumentException for an invalid pattern.
     */
    public static function IsMatch(string $input, string $pattern, RegexOptions|int $options = RegexOptions::None): bool
    {
        return (new self($pattern, $options))->instanceIsMatch($input);
    }

    public static function Match(string $input, string $pattern, RegexOptions|int $options = RegexOptions::None): Match_
    {
        return (new self($pattern, $options))->instanceMatch($input);
    }

    /**
     * Regex.Matches(input, pattern) or Matches(input, pattern, options): every match, left to right
     * and not overlapping (after an empty match the search goes on one character later); empty when
     * nothing matches. ArgumentException for an invalid pattern.
     */
    public static function Matches(string $input, string $pattern, RegexOptions|int $options = RegexOptions::None): MatchCollection
    {
        return (new self($pattern, $options))->instanceMatches($input);
    }

    /**
     * $replacement is a text with $1 / ${name} / $0 / $$, or a MatchEvaluator (m => ...).
     *
     * @param string|\Closure(Match_): string $replacement
     */
    public static function Replace(string $input, string $pattern, string|\Closure $replacement, RegexOptions|int $options = RegexOptions::None): string
    {
        return (new self($pattern, $options))->instanceReplace($input, $replacement);
    }

    /**
     * The parts between the matches (captured groups are included, as in .NET).
     *
     * @return string[]
     */
    public static function Split(string $input, string $pattern, RegexOptions|int $options = RegexOptions::None): array
    {
        return (new self($pattern, $options))->instanceSplit($input);
    }

    /** Escapes \ * + ? | { [ ( ) ^ $ . # and white space, so the text matches itself. */
    public static function Escape(string $str): string
    {
        return (string) preg_replace_callback('/[\\\\*+?|{\[()^$.#\s]/u', static fn (array $m): string => match ($m[0]) {
            "\n" => '\n', "\r" => '\r', "\t" => '\t', "\f" => '\f', ' ' => '\ ',
            default => '\\' . $m[0],
        }, $str);
    }

    /** @sharp-instance IsMatch */
    public function instanceIsMatch(string $input): bool
    {
        return self::run(fn (): int|false => preg_match($this->pcre, $input)) === 1;
    }

    /** @sharp-instance Match */
    public function instanceMatch(string $input): Match_
    {
        return $this->matchAt($input, 0);
    }

    /** @sharp-instance Matches */
    public function instanceMatches(string $input): MatchCollection
    {
        $matches = [];
        for ($m = $this->matchAt($input, 0); $m->Success; $m = $m->NextMatch()) {
            $matches[] = $m;
        }

        return new MatchCollection($matches);
    }

    /**
     * @sharp-instance Replace
     * @param string|\Closure(Match_): string $replacement
     */
    public function instanceReplace(string $input, string|\Closure $replacement): string
    {
        return (string) self::run(fn (): ?string => preg_replace_callback($this->pcre, function (array $matched) use ($input, $replacement): string {
            $groups = self::withOffsets($matched);
            if ($replacement instanceof \Closure) {
                return (string) $replacement($this->toMatch($groups, $input));
            }

            return self::substitute($replacement, $groups);
        }, $input, -1, $count, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL));
    }

    /**
     * @sharp-instance Split
     * @return string[]
     */
    public function instanceSplit(string $input): array
    {
        return self::run(fn (): array|false => preg_split($this->pcre, $input, -1, PREG_SPLIT_DELIM_CAPTURE)) ?: [$input];
    }

    /** regex.ToString(): the pattern given to the constructor, as in .NET. */
    public function ToString(): string
    {
        return $this->pattern;
    }

    /**
     * The first match at or after byte $offset.
     *
     * @internal used by Match.NextMatch()
     */
    public function matchAt(string $input, int $offset): Match_
    {
        if ($offset > strlen($input)) {
            return Match_::failed();
        }
        $groups = [];
        $found = self::run(function () use ($input, $offset, &$groups): int|false {
            return preg_match($this->pcre, $input, $groups, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset);
        });
        if ($found !== 1) {
            return Match_::failed();
        }

        return $this->toMatch($groups, $input);
    }

    /**
     * The groups of a preg_* call with PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL (PHPStan does
     * not see the flags of a preg_replace_callback()).
     *
     * @param array<mixed> $groups
     * @return array<int|string, array{0: ?string, 1: int}>
     */
    private static function withOffsets(array $groups): array
    {
        /** @var array<int|string, array{0: ?string, 1: int}> $groups */
        return $groups;
    }

    /** @param array<int|string, array{0: ?string, 1: int}> $groups */
    private function toMatch(array $groups, string $input): Match_
    {
        $collection = [];
        foreach ($groups as $key => [$text, $byteOffset]) {
            $index = $text === null ? 0 : mb_strlen(substr($input, 0, $byteOffset));
            $collection[$key] = new Group($text !== null, $text ?? '', $index, (string) $key);
        }
        [$value, $start] = $groups[0];
        $value ??= '';
        // After an empty match, go on one character later (as .NET does), not at the same place forever.
        $next = $start + strlen($value) + ($value === '' ? max(1, strlen(mb_substr(substr($input, $start), 0, 1))) : 0);

        return new Match_(true, $value, $collection[0]->Index, new GroupCollection($collection), $this, $input, $next);
    }

    /** @param array<int|string, array{0: ?string, 1: int}> $groups */
    private static function substitute(string $replacement, array $groups): string
    {
        return (string) preg_replace_callback('/\$(?:\$|&|(\d+)|\{(\w+)\})/', static function (array $m) use ($groups): string {
            if ($m[0] === '$$') {
                return '$';
            }
            $key = $m[0] === '$&' ? 0 : (($m[1] ?? '') !== '' ? (int) $m[1] : ($m[2] ?? ''));
            if (is_string($key) && ctype_digit($key)) {
                $key = (int) $key;
            }

            return isset($groups[$key]) ? (string) $groups[$key][0] : $m[0];
        }, $replacement);
    }

    private static function compile(string $pattern, RegexOptions|int $options): string
    {
        $flags = $options instanceof RegexOptions ? $options->value : $options;
        $modifiers = 'u';
        foreach ([1 => 'i', 2 => 'm', 4 => 'n', 16 => 's', 32 => 'x'] as $bit => $modifier) {
            if (($flags & $bit) !== 0) {
                $modifiers .= $modifier;
            }
        }
        // "/" delimits the pattern: escape the slashes that are not escaped yet.
        $body = '';
        for ($i = 0, $n = strlen($pattern); $i < $n; $i++) {
            $c = $pattern[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $body .= $c . $pattern[++$i];
            } else {
                $body .= $c === '/' ? '\/' : $c;
            }
        }
        $pcre = '/' . $body . '/' . $modifiers;
        if (@preg_match($pcre, '') === false) {
            throw new ArgumentException("Invalid pattern '$pattern': " . preg_last_error_msg());
        }

        return $pcre;
    }

    /**
     * @template R
     * @param \Closure(): R $match
     * @return R
     */
    private static function run(\Closure $match): mixed
    {
        $result = $match();
        if (preg_last_error() !== PREG_NO_ERROR) {
            throw new \SharpPhp\Runtime\System\InvalidOperationException('Regex failed: ' . preg_last_error_msg());
        }

        return $result;
    }
}
