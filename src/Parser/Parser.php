<?php

declare(strict_types=1);

namespace SharpPhp\Parser;

use SharpPhp\Compiler\CompileError;
use SharpPhp\Compiler\SourceFile;
use SharpPhp\Lexer\Lexer;
use SharpPhp\Lexer\Token;
use SharpPhp\Lexer\TokenType;
use SharpPhp\Syntax\Accessor;
use SharpPhp\Syntax\Argument;
use SharpPhp\Syntax\ArrayCreationExpr;
use SharpPhp\Syntax\AsExpr;
use SharpPhp\Syntax\AssignExpr;
use SharpPhp\Syntax\BaseExpr;
use SharpPhp\Syntax\BinaryExpr;
use SharpPhp\Syntax\Block;
use SharpPhp\Syntax\BreakStmt;
use SharpPhp\Syntax\CastExpr;
use SharpPhp\Syntax\CatchClause;
use SharpPhp\Syntax\CollectionExpr;
use SharpPhp\Syntax\CompilationUnit;
use SharpPhp\Syntax\ConditionalExpr;
use SharpPhp\Syntax\ContinueStmt;
use SharpPhp\Syntax\DeclarationExpr;
use SharpPhp\Syntax\DefaultExpr;
use SharpPhp\Syntax\DoStmt;
use SharpPhp\Syntax\ElementAccessExpr;
use SharpPhp\Syntax\EmptyStmt;
use SharpPhp\Syntax\EnumMember;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\ExprStmt;
use SharpPhp\Syntax\FieldDecl;
use SharpPhp\Syntax\ForeachStmt;
use SharpPhp\Syntax\ForStmt;
use SharpPhp\Syntax\IfStmt;
use SharpPhp\Syntax\Initializer;
use SharpPhp\Syntax\InitializerItem;
use SharpPhp\Syntax\InterpolatedStringExpr;
use SharpPhp\Syntax\InterpolationHole;
use SharpPhp\Syntax\InvocationExpr;
use SharpPhp\Syntax\IsPatternExpr;
use SharpPhp\Syntax\LambdaExpr;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\LocalDecl;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\MethodDecl;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\NameOfExpr;
use SharpPhp\Syntax\NamespaceDecl;
use SharpPhp\Syntax\NewExpr;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\Parameter;
use SharpPhp\Syntax\Pattern;
use SharpPhp\Syntax\PredefinedTypeExpr;
use SharpPhp\Syntax\PropertyDecl;
use SharpPhp\Syntax\ReturnStmt;
use SharpPhp\Syntax\Stmt;
use SharpPhp\Syntax\SwitchArm;
use SharpPhp\Syntax\SwitchExpr;
use SharpPhp\Syntax\SwitchLabel;
use SharpPhp\Syntax\SwitchSection;
use SharpPhp\Syntax\SwitchStmt;
use SharpPhp\Syntax\ThisExpr;
use SharpPhp\Syntax\ThrowExpr;
use SharpPhp\Syntax\ThrowStmt;
use SharpPhp\Syntax\TryStmt;
use SharpPhp\Syntax\TypeDecl;
use SharpPhp\Syntax\TypeOfExpr;
use SharpPhp\Syntax\TypeRef;
use SharpPhp\Syntax\UnaryExpr;
use SharpPhp\Syntax\UsingDirective;
use SharpPhp\Syntax\VarDeclarator;
use SharpPhp\Syntax\WhileStmt;
use SharpPhp\Syntax\WithExpr;
use SharpPhp\Syntax\YieldStmt;

/**
 * Recursive-descent parser for SharpPHP (a C# subset).
 */
final class Parser
{
    public const PREDEFINED_TYPES = [
        'bool', 'byte', 'char', 'decimal', 'double', 'float', 'int', 'long', 'object', 'sbyte', 'short',
        'string', 'uint', 'ulong', 'ushort',
    ];

    private const MODIFIERS = [
        'public', 'private', 'protected', 'internal', 'static', 'abstract', 'sealed', 'readonly', 'override',
        'virtual', 'const', 'extern', 'unsafe', 'volatile', 'new',
    ];

    private const CONTEXTUAL_MODIFIERS = ['partial', 'async', 'required', 'file'];

    private const ASSIGNMENT_OPERATORS = ['=', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<=', '??='];

    /** Tokens that may follow a generic argument list in an expression (C# spec §6.2.5). */
    private const GENERIC_FOLLOWERS = ['(', ')', ']', '}', ':', ';', ',', '.', '?', '==', '!=', '|', '^', '&&', '||', '&', '['];

    private int $pos = 0;

    /**
     * @param list<Token> $tokens
     */
    public function __construct(
        private readonly SourceFile $file,
        private readonly array $tokens,
    ) {
    }

    public static function parseFile(SourceFile $file): CompilationUnit
    {
        return (new self($file, Lexer::tokenize($file)))->parseCompilationUnit();
    }

    // ------------------------------------------------------------------
    // Compilation unit & declarations
    // ------------------------------------------------------------------

    public function parseCompilationUnit(): CompilationUnit
    {
        $start = $this->current();
        $usings = $this->parseUsings();
        $namespaces = [];
        $globalTypes = [];
        $topLevel = [];

        while (!$this->atEnd()) {
            if ($this->check('namespace')) {
                $nsToken = $this->advance();
                $name = $this->parseQualifiedName();

                if ($this->accept(';')) {
                    $nsUsings = $this->parseUsings();
                    $types = [];
                    while (!$this->atEnd()) {
                        $types[] = $this->parseTypeDecl();
                    }
                    $namespaces[] = (new NamespaceDecl($name, $nsUsings, $types))->at($nsToken);
                    continue;
                }

                $this->expect('{');
                $nsUsings = $this->parseUsings();
                $types = [];
                while (!$this->check('}') && !$this->atEnd()) {
                    if ($this->check('namespace')) {
                        throw $this->error('Nested namespace blocks are not supported; use dotted names instead', $this->current(), 'SP1010');
                    }
                    $types[] = $this->parseTypeDecl();
                }
                $this->expect('}');
                $namespaces[] = (new NamespaceDecl($name, $nsUsings, $types))->at($nsToken);
                continue;
            }

            if ($this->isTypeDeclarationStart()) {
                $globalTypes[] = $this->parseTypeDecl();
                continue;
            }

            if ($this->check('using')) {
                throw $this->error('A using directive must precede all other elements defined in the file', $this->current());
            }

            $topLevel[] = $this->parseStatement();
        }

        if ($globalTypes !== []) {
            array_unshift($namespaces, (new NamespaceDecl('', [], $globalTypes))->at($start));
        }

        return (new CompilationUnit($this->file, $usings, $namespaces, $topLevel))->at($start);
    }

    /** @return list<UsingDirective> */
    private function parseUsings(): array
    {
        $usings = [];
        while ($this->check('using') && !$this->peek(1)->is('(') && !$this->peek(1)->isIdentifier('var')) {
            $token = $this->advance();
            if ($this->check('static')) {
                throw $this->error("'using static' is not supported", $this->current(), 'SP1010');
            }
            $alias = null;
            if ($this->current()->isIdentifier() && $this->peek(1)->is('=')) {
                $alias = $this->advance()->value;
                $this->advance();
            }
            $name = $this->parseQualifiedName();
            $this->expect(';');
            $usings[] = (new UsingDirective($name, $alias))->at($token);
        }

        return $usings;
    }

    private function parseQualifiedName(): string
    {
        $name = $this->expectIdentifier()->value;
        while ($this->check('.') && $this->peek(1)->isIdentifier()) {
            $this->advance();
            $name .= '.' . $this->advance()->value;
        }

        return $name;
    }

    private function isTypeDeclarationStart(): bool
    {
        $i = 0;
        while ($this->peek($i)->is('[')) {
            $i = $this->skipBalancedFrom($i);
        }
        while ($this->isModifierAt($i)) {
            $i++;
        }
        $token = $this->peek($i);

        return $token->is('class') || $token->is('struct') || $token->is('interface') || $token->is('enum')
            || ($token->isIdentifier('record') && ($this->peek($i + 1)->isIdentifier() || $this->peek($i + 1)->is('class') || $this->peek($i + 1)->is('struct')));
    }

    private function isModifierAt(int $i): bool
    {
        $token = $this->peek($i);
        if ($token->type === TokenType::Keyword && in_array($token->value, self::MODIFIERS, true)) {
            return true;
        }

        return $token->type === TokenType::Identifier
            && in_array($token->value, self::CONTEXTUAL_MODIFIERS, true)
            && ($this->peek($i + 1)->type === TokenType::Keyword || $this->peek($i + 1)->isIdentifier());
    }

    /** @return list<string> */
    private function parseModifiers(): array
    {
        $modifiers = [];
        while ($this->isModifierAt(0)) {
            $token = $this->advance();
            if (in_array($token->value, ['extern', 'unsafe', 'volatile', 'async'], true)) {
                throw $this->error("The '{$token->value}' modifier is not supported", $token, 'SP1010');
            }
            $modifiers[] = $token->value;
        }

        return $modifiers;
    }

    private function skipAttributes(): void
    {
        while ($this->check('[')) {
            $this->pos = $this->skipBalancedFrom(0) + $this->pos;
        }
    }

    /** Returns the relative index just after the bracket group starting at relative index $i. */
    private function skipBalancedFrom(int $i): int
    {
        $open = $this->peek($i)->value;
        $close = ['[' => ']', '(' => ')', '{' => '}', '<' => '>'][$open];
        $depth = 0;
        for (; ; $i++) {
            $token = $this->peek($i);
            if ($token->type === TokenType::EndOfFile) {
                throw $this->error("'$close' expected", $token);
            }
            if ($token->is($open)) {
                $depth++;
            } elseif ($token->is($close)) {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }
    }

    private function parseTypeDecl(): TypeDecl
    {
        $this->skipAttributes();
        $start = $this->current();
        $modifiers = $this->parseModifiers();
        $kindToken = $this->current();

        if ($kindToken->isIdentifier('record')) {
            $this->advance();
            $kind = 'record';
            if ($this->check('struct')) {
                throw $this->error("'record struct' is not supported", $this->current(), 'SP1010');
            }
            $this->accept('class');
        } elseif ($kindToken->is('class') || $kindToken->is('struct') || $kindToken->is('interface') || $kindToken->is('enum')) {
            $this->advance();
            $kind = $kindToken->value;
        } else {
            throw $this->error('Type declaration expected (class, interface, enum, struct or record)', $kindToken);
        }

        $name = $this->expectIdentifier('type name')->value;
        $typeParams = $this->check('<') ? $this->parseTypeParameterList() : [];
        $recordParams = $kind === 'record' && $this->check('(') ? $this->parseParameterList() : [];

        $baseTypes = [];
        if ($this->accept(':')) {
            do {
                $baseTypes[] = $this->parseType();
                if ($this->check('(')) {
                    throw $this->error('Passing arguments to a base record is not supported', $this->current(), 'SP1010');
                }
            } while ($this->accept(','));
        }
        $constraints = $this->parseConstraints();

        $members = [];
        $enumMembers = [];
        if ($kind === 'enum') {
            $enumMembers = $this->parseEnumBody();
        } elseif ($kind === 'record' && $this->accept(';')) {
            // positional record without body
        } else {
            $this->expect('{');
            while (!$this->check('}') && !$this->atEnd()) {
                $members[] = $this->parseMember($name, $kind);
            }
            $this->expect('}');
            $this->accept(';');
        }

        return (new TypeDecl($kind, $name, $modifiers, $typeParams, $baseTypes, $members, $recordParams, $enumMembers, $constraints))->at($start);
    }

    /** @return list<string> */
    private function parseTypeParameterList(): array
    {
        $this->expect('<');
        $names = [];
        do {
            $this->skipAttributes();
            if ($this->check('in') || $this->check('out')) {
                $this->advance();
            }
            $names[] = $this->expectIdentifier('type parameter')->value;
        } while ($this->accept(','));
        $this->expect('>');

        return $names;
    }

    /**
     * where T : class, IComparable<T>, new()
     *
     * @return array<string, list<TypeRef>> Type constraints per type parameter (class/struct/new() are dropped).
     */
    private function parseConstraints(): array
    {
        $constraints = [];
        while ($this->current()->isIdentifier('where')) {
            $this->advance();
            $param = $this->expectIdentifier('type parameter')->value;
            $this->expect(':');
            $constraints[$param] = [];
            do {
                if ($this->check('class') || $this->check('struct')) {
                    $this->advance();
                    $this->accept('?');
                } elseif ($this->check('new')) {
                    $this->advance();
                    $this->expect('(');
                    $this->expect(')');
                } elseif ($this->current()->isIdentifier('notnull') || $this->current()->isIdentifier('unmanaged')) {
                    $this->advance();
                } else {
                    $constraints[$param][] = $this->parseType();
                }
            } while ($this->accept(','));
        }

        return $constraints;
    }

    /** @return list<EnumMember> */
    private function parseEnumBody(): array
    {
        $this->expect('{');
        $members = [];
        while (!$this->check('}')) {
            $this->skipAttributes();
            $token = $this->expectIdentifier('enum member name');
            $value = $this->accept('=') ? $this->parseExpression() : null;
            $members[] = (new EnumMember($token->value, $value))->at($token);
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect('}');
        $this->accept(';');

        return $members;
    }

    private function parseMember(string $typeName, string $typeKind): Node
    {
        $this->skipAttributes();
        $start = $this->current();
        $modifiers = $this->parseModifiers();
        $token = $this->current();

        if ($token->is('class') || $token->is('struct') || $token->is('interface') || $token->is('enum') || $token->isIdentifier('record') && $this->peek(1)->isIdentifier()) {
            throw $this->error('Nested types are not supported; declare the type at namespace level', $token, 'SP1010');
        }
        if ($token->is('~')) {
            throw $this->error('Finalizers are not supported', $token, 'SP1010');
        }
        if ($token->is('event') || $token->is('delegate')) {
            throw $this->error("'{$token->value}' declarations are not supported", $token, 'SP1010');
        }
        if ($token->is('implicit') || $token->is('explicit')) {
            throw $this->error('Conversion operators are not supported', $token, 'SP1010');
        }

        // Constructor
        if ($token->isIdentifier($typeName) && $this->peek(1)->is('(')) {
            $this->advance();
            $params = $this->parseParameterList();
            $initializer = null;
            $initializerArgs = [];
            if ($this->accept(':')) {
                $kind = $this->current();
                if (!$kind->is('base') && !$kind->is('this')) {
                    throw $this->error("'base' or 'this' expected", $kind);
                }
                $this->advance();
                $initializer = $kind->value;
                $initializerArgs = $this->parseArgumentList();
            }
            [$body, $exprBody] = $this->parseMethodBody();
            $kind = in_array('static', $modifiers, true) ? 'static_constructor' : 'constructor';

            return (new MethodDecl($modifiers, null, $typeName, [], $params, $body, $exprBody, $kind, $initializer, $initializerArgs, []))->at($start);
        }

        if ($token->is('void')) {
            $this->advance();
            $type = (new TypeRef('void', [], false))->at($token);
        } else {
            $type = $this->parseType();
        }

        if ($this->check('operator')) {
            throw $this->error('Operator overloading is not supported', $this->current(), 'SP1010');
        }
        if ($this->check('this')) {
            throw $this->error('Indexers are not supported', $this->current(), 'SP1010');
        }

        $nameToken = $this->expectIdentifier('member name');
        if ($this->check('.')) {
            throw $this->error('Explicit interface implementations are not supported', $this->current(), 'SP1010');
        }

        // Method
        if ($this->check('(') || $this->check('<')) {
            $typeParams = $this->check('<') ? $this->parseTypeParameterList() : [];
            $params = $this->parseParameterList();
            $constraints = $this->parseConstraints();
            [$body, $exprBody] = $this->parseMethodBody();

            return (new MethodDecl($modifiers, $type, $nameToken->value, $typeParams, $params, $body, $exprBody, 'method', null, [], $constraints))->at($start);
        }

        // Property with accessors
        if ($this->check('{')) {
            $accessors = $this->parseAccessors();
            $init = null;
            if ($this->accept('=')) {
                $init = $this->parseVariableInitializer();
                $this->expect(';');
            }

            return (new PropertyDecl($modifiers, $type, $nameToken->value, $accessors, null, $init))->at($start);
        }

        // Expression-bodied property
        if ($this->accept('=>')) {
            $expr = $this->parseExpression();
            $this->expect(';');

            return (new PropertyDecl($modifiers, $type, $nameToken->value, [], $expr, null))->at($start);
        }

        // Field(s)
        $declarators = [];
        $declToken = $nameToken;
        while (true) {
            $init = $this->accept('=') ? $this->parseVariableInitializer() : null;
            $declarators[] = (new VarDeclarator($declToken->value, $init))->at($declToken);
            if (!$this->accept(',')) {
                break;
            }
            $declToken = $this->expectIdentifier('field name');
        }
        $this->expect(';');

        if ($typeKind === 'interface') {
            throw $this->error('Interfaces cannot contain fields', $nameToken);
        }

        return (new FieldDecl($modifiers, $type, $declarators, in_array('const', $modifiers, true)))->at($start);
    }

    /** @return array{0: ?Block, 1: ?Expr} */
    private function parseMethodBody(): array
    {
        if ($this->accept(';')) {
            return [null, null];
        }
        if ($this->accept('=>')) {
            $expr = $this->parseExpression();
            $this->expect(';');

            return [null, $expr];
        }

        return [$this->parseBlock(), null];
    }

    /** @return list<Accessor> */
    private function parseAccessors(): array
    {
        $this->expect('{');
        $accessors = [];
        while (!$this->check('}')) {
            $this->skipAttributes();
            $start = $this->current();
            $modifiers = $this->parseModifiers();
            $kind = $this->current();
            if (!$kind->isIdentifier('get') && !$kind->isIdentifier('set') && !$kind->isIdentifier('init')) {
                throw $this->error("A get, set or init accessor expected", $kind);
            }
            $this->advance();
            $body = null;
            $exprBody = null;
            if ($this->accept('=>')) {
                $exprBody = $this->parseExpression();
                $this->expect(';');
            } elseif ($this->check('{')) {
                $body = $this->parseBlock();
            } else {
                $this->expect(';');
            }
            $accessors[] = (new Accessor($kind->value, $modifiers, $body, $exprBody))->at($start);
        }
        $this->expect('}');

        return $accessors;
    }

    /** @return list<Parameter> */
    private function parseParameterList(): array
    {
        $this->expect('(');
        $params = [];
        while (!$this->check(')')) {
            $this->skipAttributes();
            $start = $this->current();
            $modifier = null;
            if ($this->check('ref') || $this->check('out') || $this->check('in') || $this->check('params') || $this->check('this')) {
                $modifier = $this->advance()->value;
                if ($modifier === 'this') {
                    throw $this->error('Extension methods are not supported', $start, 'SP1010');
                }
            }
            $type = $this->parseType();
            $name = $this->expectIdentifier('parameter name');
            $default = $this->accept('=') ? $this->parseExpression() : null;
            $params[] = (new Parameter($name->value, $type, $default, $modifier))->at($start);
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect(')');

        return $params;
    }

    private function parseVariableInitializer(): Expr
    {
        if ($this->check('{')) {
            $token = $this->current();

            return (new ArrayCreationExpr(null, null, $this->parseArrayInitializer()))->at($token);
        }

        return $this->parseExpression();
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    public function parseType(): TypeRef
    {
        $token = $this->current();
        $type = $this->tryParseType();
        if ($type === null) {
            throw $this->error('Type expected', $token);
        }

        return $type;
    }

    private function tryParseType(): ?TypeRef
    {
        $start = $this->pos;
        $token = $this->current();

        if ($token->type === TokenType::Keyword && in_array($token->value, self::PREDEFINED_TYPES, true)) {
            $this->advance();
            $type = (new TypeRef($token->value, [], false))->at($token);
        } elseif ($token->isIdentifier()) {
            $name = $this->advance()->value;
            while ($this->check('.') && $this->peek(1)->isIdentifier()) {
                $this->advance();
                $name .= '.' . $this->advance()->value;
            }
            $args = [];
            if ($this->check('<')) {
                $args = $this->tryParseTypeArgumentList() ?? [];
            }
            $type = (new TypeRef($name, $args, false))->at($token);
        } else {
            $this->pos = $start;

            return null;
        }

        while (true) {
            if ($this->check('?') && $this->nullableMarkerFollows()) {
                $this->advance();
                $type = (new TypeRef($type->name, $type->args, true))->at($token);
                continue;
            }
            if ($this->check('[') && $this->peek(1)->is(']')) {
                $this->advance();
                $this->advance();
                $type = (new TypeRef('[]', [$type], false))->at($token);
                continue;
            }
            if ($this->check('[') && $this->peek(1)->is(',')) {
                throw $this->error('Multi-dimensional arrays are not supported', $this->current(), 'SP1010');
            }
            break;
        }

        return $type;
    }

    private function nullableMarkerFollows(): bool
    {
        $next = $this->peek(1);

        return $next->isIdentifier() || $next->is('[') || $next->is(')') || $next->is('>') || $next->is(',')
            || $next->is(';') || $next->is('=') || $next->is('{') || $next->is('?') || $next->type === TokenType::EndOfFile;
    }

    /**
     * Parses "<T, U>" and returns null (restoring the position) when the tokens are not a type argument list.
     *
     * @return list<TypeRef>|null
     */
    private function tryParseTypeArgumentList(): ?array
    {
        $start = $this->pos;
        $this->expect('<');
        $args = [];
        while (true) {
            $type = $this->tryParseType();
            if ($type === null) {
                $this->pos = $start;

                return null;
            }
            $args[] = $type;
            if ($this->accept(',')) {
                continue;
            }
            if ($this->check('>')) {
                $this->advance();

                return $args;
            }
            $this->pos = $start;

            return null;
        }
    }

    /**
     * Generic arguments in expression context (e.g. Method<int>(x)), with C#'s disambiguation rule.
     *
     * @return list<TypeRef>
     */
    private function tryParseGenericArgumentsInExpression(): array
    {
        if (!$this->check('<')) {
            return [];
        }
        $start = $this->pos;
        $args = $this->tryParseTypeArgumentList();
        if ($args === null) {
            return [];
        }
        $next = $this->current();
        if ($next->type === TokenType::EndOfFile || ($next->type === TokenType::Punct && in_array($next->value, self::GENERIC_FOLLOWERS, true))) {
            return $args;
        }
        $this->pos = $start;

        return [];
    }

    // ------------------------------------------------------------------
    // Statements
    // ------------------------------------------------------------------

    public function parseBlock(): Block
    {
        $start = $this->expect('{');
        $statements = [];
        while (!$this->check('}')) {
            if ($this->atEnd()) {
                throw $this->error("'}' expected", $this->current());
            }
            $statements[] = $this->parseStatement();
        }
        $this->expect('}');

        return (new Block($statements))->at($start);
    }

    public function parseStatement(): Stmt
    {
        $token = $this->current();

        if ($token->type === TokenType::Punct) {
            if ($token->value === '{') {
                return $this->parseBlock();
            }
            if ($token->value === ';') {
                $this->advance();

                return (new EmptyStmt())->at($token);
            }
        }

        if ($token->type === TokenType::Keyword) {
            switch ($token->value) {
                case 'if':
                    return $this->parseIf();
                case 'while':
                    $this->advance();
                    $this->expect('(');
                    $condition = $this->parseExpression();
                    $this->expect(')');

                    return (new WhileStmt($condition, $this->parseEmbeddedStatement()))->at($token);
                case 'do':
                    $this->advance();
                    $body = $this->parseEmbeddedStatement();
                    $this->expect('while');
                    $this->expect('(');
                    $condition = $this->parseExpression();
                    $this->expect(')');
                    $this->expect(';');

                    return (new DoStmt($body, $condition))->at($token);
                case 'for':
                    return $this->parseFor();
                case 'foreach':
                    return $this->parseForeach();
                case 'switch':
                    return $this->parseSwitchStatement();
                case 'return':
                    $this->advance();
                    $value = $this->check(';') ? null : $this->parseExpression();
                    $this->expect(';');

                    return (new ReturnStmt($value))->at($token);
                case 'break':
                    $this->advance();
                    $this->expect(';');

                    return (new BreakStmt())->at($token);
                case 'continue':
                    $this->advance();
                    $this->expect(';');

                    return (new ContinueStmt())->at($token);
                case 'throw':
                    $this->advance();
                    $value = $this->check(';') ? null : $this->parseExpression();
                    $this->expect(';');

                    return (new ThrowStmt($value))->at($token);
                case 'try':
                    return $this->parseTry();
                case 'const':
                    $this->advance();
                    $type = $this->parseType();
                    $declarators = $this->parseDeclarators();
                    $this->expect(';');

                    return (new LocalDecl($type, $declarators, true))->at($token);
                case 'using':
                    throw $this->error("'using' statements are not supported", $token, 'SP1010');
                case 'lock':
                case 'goto':
                case 'checked':
                case 'unchecked':
                case 'unsafe':
                case 'fixed':
                    throw $this->error("'{$token->value}' statements are not supported", $token, 'SP1010');
                case 'else':
                    throw $this->error("Unexpected 'else' without matching 'if'", $token);
            }
        }

        if ($token->isIdentifier('yield') && ($this->peek(1)->is('return') || $this->peek(1)->is('break'))) {
            $this->advance();
            if ($this->advance()->value === 'break') {
                $this->expect(';');

                return (new YieldStmt(null))->at($token);
            }
            $value = $this->parseExpression();
            $this->expect(';');

            return (new YieldStmt($value))->at($token);
        }

        $declaration = $this->tryParseLocalDeclaration();
        if ($declaration !== null) {
            $this->expect(';');

            return $declaration;
        }

        $expr = $this->parseExpression();
        $this->expect(';');

        return (new ExprStmt($expr))->at($token);
    }

    /** Statement used as a body of if/while/for; a bare declaration is not allowed there. */
    private function parseEmbeddedStatement(): Stmt
    {
        return $this->parseStatement();
    }

    private function parseIf(): IfStmt
    {
        $token = $this->advance();
        $this->expect('(');
        $condition = $this->parseExpression();
        $this->expect(')');
        $then = $this->parseEmbeddedStatement();
        $else = $this->accept('else') ? $this->parseEmbeddedStatement() : null;

        return (new IfStmt($condition, $then, $else))->at($token);
    }

    private function parseFor(): ForStmt
    {
        $token = $this->advance();
        $this->expect('(');

        $declaration = null;
        $initializers = [];
        if (!$this->check(';')) {
            $declaration = $this->tryParseLocalDeclaration();
            if ($declaration === null) {
                do {
                    $initializers[] = $this->parseExpression();
                } while ($this->accept(','));
            }
        }
        $this->expect(';');
        $condition = $this->check(';') ? null : $this->parseExpression();
        $this->expect(';');
        $iterators = [];
        if (!$this->check(')')) {
            do {
                $iterators[] = $this->parseExpression();
            } while ($this->accept(','));
        }
        $this->expect(')');

        return (new ForStmt($declaration, $initializers, $condition, $iterators, $this->parseEmbeddedStatement()))->at($token);
    }

    private function parseForeach(): ForeachStmt
    {
        $token = $this->advance();
        $this->expect('(');
        $type = null;
        $variable = null;
        $deconstruction = [];

        if ($this->current()->isIdentifier('var') && $this->peek(1)->is('(')) {
            $this->advance();
            $this->advance();
            do {
                $deconstruction[] = $this->expectIdentifier('variable name')->value;
            } while ($this->accept(','));
            $this->expect(')');
        } elseif ($this->check('(')) {
            throw $this->error('Use "foreach (var (a, b) in ...)" for deconstruction', $this->current(), 'SP1010');
        } else {
            if ($this->current()->isIdentifier('var') && $this->peek(1)->isIdentifier()) {
                $this->advance();
            } else {
                $type = $this->parseType();
            }
            $variable = $this->expectIdentifier('variable name')->value;
        }

        $this->expect('in');
        $collection = $this->parseExpression();
        $this->expect(')');

        return (new ForeachStmt($type, $variable, $deconstruction, $collection, $this->parseEmbeddedStatement()))->at($token);
    }

    private function parseSwitchStatement(): SwitchStmt
    {
        $token = $this->advance();
        $this->expect('(');
        $subject = $this->parseExpression();
        $this->expect(')');
        $this->expect('{');

        $sections = [];
        while (!$this->check('}')) {
            $sectionStart = $this->current();
            $labels = [];
            while ($this->check('case') || ($this->check('default') && $this->peek(1)->is(':'))) {
                $labelToken = $this->advance();
                if ($labelToken->value === 'default') {
                    $this->expect(':');
                    $labels[] = (new SwitchLabel(null, null))->at($labelToken);
                    continue;
                }
                $pattern = $this->parsePattern();
                $when = null;
                if ($this->current()->isIdentifier('when')) {
                    $this->advance();
                    $when = $this->parseExpression();
                }
                $this->expect(':');
                $labels[] = (new SwitchLabel($pattern, $when))->at($labelToken);
            }
            if ($labels === []) {
                throw $this->error("'case' or 'default' expected", $this->current());
            }
            $statements = [];
            while (!$this->check('case') && !($this->check('default') && $this->peek(1)->is(':')) && !$this->check('}')) {
                if ($this->atEnd()) {
                    throw $this->error("'}' expected", $this->current());
                }
                $statements[] = $this->parseStatement();
            }
            $sections[] = (new SwitchSection($labels, $statements))->at($sectionStart);
        }
        $this->expect('}');

        return (new SwitchStmt($subject, $sections))->at($token);
    }

    private function parseTry(): TryStmt
    {
        $token = $this->advance();
        $body = $this->parseBlock();
        $catches = [];
        while ($this->check('catch')) {
            $catchToken = $this->advance();
            $type = null;
            $variable = null;
            if ($this->accept('(')) {
                $type = $this->parseType();
                if ($this->current()->isIdentifier()) {
                    $variable = $this->advance()->value;
                }
                $this->expect(')');
            }
            $when = null;
            if ($this->current()->isIdentifier('when')) {
                $this->advance();
                $this->expect('(');
                $when = $this->parseExpression();
                $this->expect(')');
            }
            $catches[] = (new CatchClause($type, $variable, $this->parseBlock(), $when))->at($catchToken);
        }
        $finally = $this->accept('finally') ? $this->parseBlock() : null;
        if ($catches === [] && $finally === null) {
            throw $this->error('Expected catch or finally', $this->current());
        }

        return (new TryStmt($body, $catches, $finally))->at($token);
    }

    private function tryParseLocalDeclaration(): ?LocalDecl
    {
        $token = $this->current();

        if ($token->isIdentifier('var') && $this->peek(1)->isIdentifier()) {
            $this->advance();

            return (new LocalDecl(null, $this->parseDeclarators(), false))->at($token);
        }
        if ($token->isIdentifier('var') && $this->peek(1)->is('(')) {
            throw $this->error('Deconstructing declarations are not supported', $token, 'SP1010');
        }

        $start = $this->pos;
        $type = $this->tryParseType();
        if ($type !== null && $this->current()->isIdentifier()) {
            $after = $this->peek(1);
            if ($after->is('=') || $after->is(';') || $after->is(',')) {
                return (new LocalDecl($type, $this->parseDeclarators(), false))->at($token);
            }
            if ($after->is('(') || $after->is('<')) {
                throw $this->error('Local functions are not supported; use a lambda instead', $this->current(), 'SP1010');
            }
        }
        $this->pos = $start;

        return null;
    }

    /** @return list<VarDeclarator> */
    private function parseDeclarators(): array
    {
        $declarators = [];
        do {
            $name = $this->expectIdentifier('variable name');
            $init = $this->accept('=') ? $this->parseVariableInitializer() : null;
            $declarators[] = (new VarDeclarator($name->value, $init))->at($name);
        } while ($this->accept(','));

        return $declarators;
    }

    // ------------------------------------------------------------------
    // Expressions
    // ------------------------------------------------------------------

    public function parseExpression(): Expr
    {
        $token = $this->current();

        if ($token->is('throw')) {
            $this->advance();

            return (new ThrowExpr($this->parseExpression()))->at($token);
        }

        if ($this->isLambdaStart()) {
            return $this->parseLambda();
        }

        $left = $this->parseConditional();

        $operator = $this->assignmentOperator();
        if ($operator !== null) {
            $opToken = $this->current();
            $this->advance();
            if ($operator === '>>=') {
                $this->advance();
            }
            $value = $this->parseExpression();

            return (new AssignExpr($operator, $left, $value))->at($opToken);
        }

        return $left;
    }

    private function assignmentOperator(): ?string
    {
        $token = $this->current();
        if ($token->type !== TokenType::Punct) {
            return null;
        }
        if (in_array($token->value, self::ASSIGNMENT_OPERATORS, true)) {
            return $token->value;
        }
        if ($token->value === '>' && $this->peek(1)->is('>=') && $token->touches($this->peek(1))) {
            return '>>=';
        }

        return null;
    }

    private function isLambdaStart(): bool
    {
        $i = $this->current()->is('static') ? 1 : 0;
        $token = $this->peek($i);

        if ($token->isIdentifier() && $this->peek($i + 1)->is('=>')) {
            return true;
        }
        if ($token->is('(')) {
            $depth = 0;
            for ($j = $i; ; $j++) {
                $t = $this->peek($j);
                if ($t->type === TokenType::EndOfFile) {
                    return false;
                }
                if ($t->is('(')) {
                    $depth++;
                } elseif ($t->is(')')) {
                    $depth--;
                    if ($depth === 0) {
                        return $this->peek($j + 1)->is('=>');
                    }
                }
            }
        }

        return false;
    }

    private function parseLambda(): LambdaExpr
    {
        $start = $this->current();
        $isStatic = $this->accept('static') !== null;
        $params = [];

        if ($this->current()->isIdentifier()) {
            $name = $this->advance();
            $params[] = (new Parameter($name->value, null, null, null))->at($name);
        } else {
            $this->expect('(');
            while (!$this->check(')')) {
                $paramStart = $this->current();
                $modifier = null;
                if ($this->check('ref') || $this->check('out') || $this->check('in')) {
                    $modifier = $this->advance()->value;
                }
                if ($this->current()->isIdentifier() && ($this->peek(1)->is(',') || $this->peek(1)->is(')'))) {
                    $params[] = (new Parameter($this->advance()->value, null, null, $modifier))->at($paramStart);
                } else {
                    $type = $this->parseType();
                    $name = $this->expectIdentifier('parameter name');
                    $params[] = (new Parameter($name->value, $type, null, $modifier))->at($paramStart);
                }
                if (!$this->accept(',')) {
                    break;
                }
            }
            $this->expect(')');
        }

        $this->expect('=>');
        $body = $this->check('{') ? $this->parseBlock() : $this->parseExpression();

        return (new LambdaExpr($params, $body, $isStatic))->at($start);
    }

    private function parseConditional(): Expr
    {
        $condition = $this->parseCoalesce();
        if (!$this->check('?')) {
            return $condition;
        }
        $token = $this->advance();
        $then = $this->parseExpression();
        $this->expect(':');
        $else = $this->parseExpression();

        return (new ConditionalExpr($condition, $then, $else))->at($token);
    }

    private function parseCoalesce(): Expr
    {
        $left = $this->parseBinary(0);
        if (!$this->check('??')) {
            return $left;
        }
        $token = $this->advance();
        if ($this->check('throw')) {
            $throwToken = $this->advance();
            $right = (new ThrowExpr($this->parseExpression()))->at($throwToken);
        } else {
            $right = $this->parseCoalesce();
        }

        return (new BinaryExpr('??', $left, $right))->at($token);
    }

    /**
     * Binary operators by C# precedence level (lowest first).
     */
    private function parseBinary(int $level): Expr
    {
        if ($level > 9) {
            return $this->parseSwitchLevel();
        }

        $left = $this->parseBinary($level + 1);

        while (true) {
            $token = $this->current();
            $operator = $this->binaryOperatorAt($level);
            if ($operator === null) {
                return $left;
            }

            if ($operator === 'is') {
                $this->advance();
                $left = (new IsPatternExpr($left, $this->parsePattern()))->at($token);
                continue;
            }
            if ($operator === 'as') {
                $this->advance();
                $left = (new AsExpr($left, $this->parseType()))->at($token);
                continue;
            }

            $this->advance();
            if ($operator === '>>') {
                $this->advance();
            }
            $right = $this->parseBinary($level + 1);
            $left = (new BinaryExpr($operator, $left, $right))->at($token);
        }
    }

    private function binaryOperatorAt(int $level): ?string
    {
        $token = $this->current();
        $value = $token->value;

        if ($token->type === TokenType::Keyword) {
            return $level === 6 && ($value === 'is' || $value === 'as') ? $value : null;
        }
        if ($token->type !== TokenType::Punct) {
            return null;
        }

        $isShiftRight = $value === '>' && $this->peek(1)->is('>') && $token->touches($this->peek(1));
        $isShiftAssign = $value === '>' && $this->peek(1)->is('>=') && $token->touches($this->peek(1));

        return match ($level) {
            0 => $value === '||' ? $value : null,
            1 => $value === '&&' ? $value : null,
            2 => $value === '|' ? $value : null,
            3 => $value === '^' ? $value : null,
            4 => $value === '&' ? $value : null,
            5 => in_array($value, ['==', '!='], true) ? $value : null,
            6 => in_array($value, ['<', '<=', '>='], true) || ($value === '>' && !$isShiftRight && !$isShiftAssign) ? $value : null,
            7 => $value === '<<' ? $value : ($isShiftRight ? '>>' : null),
            8 => in_array($value, ['+', '-'], true) ? $value : null,
            9 => in_array($value, ['*', '/', '%'], true) ? $value : null,
            default => null,
        };
    }

    private function parseSwitchLevel(): Expr
    {
        $expr = $this->parseUnary();
        while (true) {
            if ($this->check('switch')) {
                $expr = $this->parseSwitchExpression($expr);
            } elseif ($this->current()->isIdentifier('with') && $this->peek(1)->is('{')) {
                $token = $this->advance();
                $initializer = $this->parseObjectOrCollectionInitializer();
                foreach ($initializer->items as $item) {
                    if ($item->kind !== 'member') {
                        throw $this->error("Invalid 'with' member: expected 'Name = value'", $this->tokens[$this->pos - 1], 'SP0131');
                    }
                }
                $expr = (new WithExpr($expr, $initializer))->at($token);
            } else {
                return $expr;
            }
        }
    }

    private function parseSwitchExpression(Expr $subject): SwitchExpr
    {
        $token = $this->advance();
        $this->expect('{');
        $arms = [];
        while (!$this->check('}')) {
            $armStart = $this->current();
            $pattern = $this->parsePattern();
            $when = null;
            if ($this->current()->isIdentifier('when')) {
                $this->advance();
                $when = $this->parseExpression();
            }
            $this->expect('=>');
            $arms[] = (new SwitchArm($pattern, $when, $this->parseExpression()))->at($armStart);
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect('}');

        return (new SwitchExpr($subject, $arms))->at($token);
    }

    private function parseUnary(): Expr
    {
        $token = $this->current();

        if ($token->type === TokenType::Punct) {
            if (in_array($token->value, ['+', '-', '!', '~', '++', '--'], true)) {
                $this->advance();

                return (new UnaryExpr($token->value, $this->parseUnary(), false))->at($token);
            }
            if ($token->value === '(' && $this->isCast()) {
                $this->advance();
                $type = $this->parseType();
                $this->expect(')');

                return (new CastExpr($type, $this->parseUnary()))->at($token);
            }
        }
        if ($token->isIdentifier('await') && !$this->peek(1)->is('(') && !$this->peek(1)->is('.')) {
            throw $this->error("'await' is not supported", $token, 'SP1010');
        }

        return $this->parsePostfix($this->parsePrimary());
    }

    private function isCast(): bool
    {
        $start = $this->pos;
        $this->advance(); // (
        $type = $this->tryParseType();
        if ($type === null || !$this->check(')')) {
            $this->pos = $start;

            return false;
        }
        $this->advance(); // )
        $next = $this->current();
        $this->pos = $start;

        $base = $type;
        while ($base->name === '[]') {
            $base = $base->args[0];
        }
        $isKeyword = in_array($base->name, self::PREDEFINED_TYPES, true);

        if ($next->type === TokenType::Identifier || $next->type === TokenType::Int || $next->type === TokenType::Float
            || $next->type === TokenType::String || $next->type === TokenType::Char || $next->type === TokenType::InterpolatedString) {
            return true;
        }
        if ($next->type === TokenType::Keyword && in_array($next->value, ['this', 'base', 'new', 'typeof', 'default', 'true', 'false', 'null', 'sizeof', 'checked'], true)) {
            return true;
        }
        if ($next->is('(') || $next->is('!') || $next->is('~')) {
            return true;
        }
        if ($isKeyword && $next->type === TokenType::Keyword && in_array($next->value, self::PREDEFINED_TYPES, true)) {
            return true;
        }

        return $isKeyword && ($next->is('-') || $next->is('+') || $next->is('++') || $next->is('--'));
    }

    private function parsePrimary(): Expr
    {
        $token = $this->current();

        switch ($token->type) {
            case TokenType::Int:
                $this->advance();

                return (new LiteralExpr('int', $token->value))->at($token);
            case TokenType::Float:
                $this->advance();

                return (new LiteralExpr('float', $token->value))->at($token);
            case TokenType::String:
                $this->advance();

                return (new LiteralExpr('string', $token->value))->at($token);
            case TokenType::Char:
                $this->advance();

                return (new LiteralExpr('char', $token->value))->at($token);
            case TokenType::InterpolatedString:
                $this->advance();

                return $this->parseInterpolatedString($token);
            case TokenType::Identifier:
                if ($token->value === 'nameof' && $this->peek(1)->is('(')) {
                    $this->advance();
                    $this->advance();
                    $expr = $this->parseExpression();
                    $this->expect(')');

                    return (new NameOfExpr($expr))->at($token);
                }
                $this->advance();

                return (new NameExpr($token->value, $this->tryParseGenericArgumentsInExpression()))->at($token);
            case TokenType::Keyword:
                return $this->parseKeywordPrimary($token);
            case TokenType::Punct:
                if ($token->value === '(') {
                    $this->advance();
                    $expr = $this->parseExpression();
                    if ($this->check(',')) {
                        throw $this->error('Tuples are not supported; use a record or a class instead', $token, 'SP1010');
                    }
                    $this->expect(')');

                    return $expr;
                }
                if ($token->value === '[') {
                    $this->advance();
                    $elements = [];
                    while (!$this->check(']')) {
                        if ($this->check('..')) {
                            throw $this->error('Spread elements are not supported', $this->current(), 'SP1010');
                        }
                        $elements[] = $this->parseExpression();
                        if (!$this->accept(',')) {
                            break;
                        }
                    }
                    $this->expect(']');

                    return (new CollectionExpr($elements))->at($token);
                }
                break;
            case TokenType::EndOfFile:
                throw $this->error('Unexpected end of file', $token);
        }

        throw $this->error("Invalid expression term {$token->describe()}", $token, 'SP1003');
    }

    private function parseKeywordPrimary(Token $token): Expr
    {
        switch ($token->value) {
            case 'true':
            case 'false':
                $this->advance();

                return (new LiteralExpr('bool', $token->value === 'true'))->at($token);
            case 'null':
                $this->advance();

                return (new LiteralExpr('null', null))->at($token);
            case 'this':
                $this->advance();

                return (new ThisExpr())->at($token);
            case 'base':
                $this->advance();
                if (!$this->check('.')) {
                    throw $this->error("Use of keyword 'base' is not valid in this context", $token);
                }

                return (new BaseExpr())->at($token);
            case 'new':
                return $this->parseNew();
            case 'typeof':
                $this->advance();
                $this->expect('(');
                if ($this->check('void')) {
                    $voidToken = $this->advance();
                    $type = (new TypeRef('void', [], false))->at($voidToken);
                } else {
                    $type = $this->parseType();
                }
                $this->expect(')');

                return (new TypeOfExpr($type))->at($token);
            case 'default':
                $this->advance();
                if ($this->accept('(')) {
                    $type = $this->parseType();
                    $this->expect(')');

                    return (new DefaultExpr($type))->at($token);
                }

                return (new DefaultExpr(null))->at($token);
        }

        if (in_array($token->value, self::PREDEFINED_TYPES, true)) {
            $this->advance();

            return (new PredefinedTypeExpr($token->value))->at($token);
        }

        if ($token->value === 'delegate') {
            throw $this->error('Anonymous methods are not supported; use a lambda instead', $token, 'SP1010');
        }

        throw $this->error("Invalid expression term '{$token->value}'", $token, 'SP1003');
    }

    private function parsePostfix(Expr $expr): Expr
    {
        while (true) {
            $token = $this->current();
            if ($token->type !== TokenType::Punct) {
                return $expr;
            }

            switch ($token->value) {
                case '.':
                case '?.':
                    $this->advance();
                    $name = $this->expectIdentifier('member name');
                    $typeArgs = $this->tryParseGenericArgumentsInExpression();
                    $expr = (new MemberAccessExpr($expr, $name->value, $token->value === '?.', $typeArgs))->at($name);
                    break;
                case '(':
                    $expr = (new InvocationExpr($expr, $this->parseArgumentList()))->at($token);
                    break;
                case '[':
                    $this->advance();
                    $expr = (new ElementAccessExpr($expr, $this->parseExpressionList(']'), false))->at($token);
                    break;
                case '?':
                    if ($this->peek(1)->is('[') && $token->touches($this->peek(1))) {
                        $this->advance();
                        $this->advance();
                        $expr = (new ElementAccessExpr($expr, $this->parseExpressionList(']'), true))->at($token);
                        break;
                    }

                    return $expr;
                case '++':
                case '--':
                    $this->advance();
                    $expr = (new UnaryExpr($token->value, $expr, true))->at($token);
                    break;
                case '!':
                    // null-forgiving operator: x!
                    $this->advance();
                    break;
                default:
                    return $expr;
            }
        }
    }

    /** @return list<Expr> */
    private function parseExpressionList(string $close): array
    {
        $items = [];
        while (!$this->check($close)) {
            $items[] = $this->parseExpression();
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect($close);

        return $items;
    }

    /** @return list<Argument> */
    private function parseArgumentList(): array
    {
        $this->expect('(');
        $args = [];
        while (!$this->check(')')) {
            $start = $this->current();
            $name = null;
            if ($start->isIdentifier() && $this->peek(1)->is(':')) {
                $name = $this->advance()->value;
                $this->advance();
            }
            $modifier = null;
            if ($this->check('ref') || $this->check('out') || $this->check('in')) {
                $modifier = $this->advance()->value;
            }

            $value = null;
            if ($modifier === 'out') {
                $declStart = $this->current();
                if ($declStart->isIdentifier('var') && $this->peek(1)->isIdentifier()) {
                    $this->advance();
                    $value = (new DeclarationExpr(null, $this->advance()->value))->at($declStart);
                } else {
                    $save = $this->pos;
                    $type = $this->tryParseType();
                    if ($type !== null && $this->current()->isIdentifier() && ($this->peek(1)->is(',') || $this->peek(1)->is(')'))) {
                        $value = (new DeclarationExpr($type, $this->advance()->value))->at($declStart);
                    } else {
                        $this->pos = $save;
                    }
                }
            }
            $value ??= $this->parseExpression();

            $args[] = (new Argument($name, $modifier, $value))->at($start);
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect(')');

        return $args;
    }

    private function parseNew(): Expr
    {
        $token = $this->advance();

        if ($this->check('[')) {
            $this->advance();
            $this->expect(']');

            return (new ArrayCreationExpr(null, null, $this->parseArrayInitializer()))->at($token);
        }

        if ($this->check('(')) {
            $args = $this->parseArgumentList();
            $init = $this->check('{') ? $this->parseObjectOrCollectionInitializer() : null;

            return (new NewExpr(null, $args, $init))->at($token);
        }

        $type = $this->parseType();

        if ($type->name === '[]' && $this->check('{')) {
            return (new ArrayCreationExpr($type->args[0], null, $this->parseArrayInitializer()))->at($token);
        }
        if ($this->check('[')) {
            $this->advance();
            $size = $this->parseExpression();
            $this->expect(']');
            $elements = $this->check('{') ? $this->parseArrayInitializer() : null;

            return (new ArrayCreationExpr($type, $size, $elements))->at($token);
        }

        $hasArgs = $this->check('(');
        $args = $hasArgs ? $this->parseArgumentList() : [];
        $init = $this->check('{') ? $this->parseObjectOrCollectionInitializer() : null;
        if (!$hasArgs && $init === null) {
            throw $this->error('A new expression requires (), [] or {} after the type', $this->current());
        }

        return (new NewExpr($type, $args, $init))->at($token);
    }

    /** @return list<Expr> */
    private function parseArrayInitializer(): array
    {
        $this->expect('{');
        $elements = [];
        while (!$this->check('}')) {
            if ($this->check('{')) {
                $token = $this->current();
                $elements[] = (new ArrayCreationExpr(null, null, $this->parseArrayInitializer()))->at($token);
            } else {
                $elements[] = $this->parseExpression();
            }
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect('}');

        return $elements;
    }

    private function parseObjectOrCollectionInitializer(): Initializer
    {
        $start = $this->expect('{');
        $items = [];
        while (!$this->check('}')) {
            $token = $this->current();
            if ($token->isIdentifier() && $this->peek(1)->is('=')) {
                $this->advance();
                $this->advance();
                if ($this->check('{')) {
                    throw $this->error('Nested object initializers are not supported', $this->current(), 'SP1010');
                }
                $items[] = (new InitializerItem('member', $token->value, null, [$this->parseExpression()]))->at($token);
            } elseif ($token->is('[')) {
                $this->advance();
                $key = $this->parseExpression();
                $this->expect(']');
                $this->expect('=');
                $items[] = (new InitializerItem('index', null, $key, [$this->parseExpression()]))->at($token);
            } elseif ($token->is('{')) {
                $this->advance();
                $items[] = (new InitializerItem('pair', null, null, $this->parseExpressionList('}')))->at($token);
            } else {
                $items[] = (new InitializerItem('element', null, null, [$this->parseExpression()]))->at($token);
            }
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect('}');

        return (new Initializer($items))->at($start);
    }

    private function parseInterpolatedString(Token $token): InterpolatedStringExpr
    {
        $parts = [];
        foreach ($token->value as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            $lexer = new Lexer($this->file, $part['expr'], $part['line'], $part['column'], $part['offset']);
            $parser = new self($this->file, $lexer->all());
            $expr = $parser->parseExpression();
            if (!$parser->atEnd()) {
                throw $parser->error("Unexpected {$parser->current()->describe()} in interpolation", $parser->current());
            }
            $parts[] = (new InterpolationHole($expr, $part['format']))->at($expr);
        }

        return (new InterpolatedStringExpr($parts))->at($token);
    }

    // ------------------------------------------------------------------
    // Patterns
    // ------------------------------------------------------------------

    private function parsePattern(): Pattern
    {
        $left = $this->parseAndPattern();
        while ($this->current()->isIdentifier('or')) {
            $token = $this->advance();
            $left = (new Pattern('or', null, null, null, null, [$left, $this->parseAndPattern()]))->at($token);
        }

        return $left;
    }

    private function parseAndPattern(): Pattern
    {
        $left = $this->parseNotPattern();
        while ($this->current()->isIdentifier('and')) {
            $token = $this->advance();
            $left = (new Pattern('and', null, null, null, null, [$left, $this->parseNotPattern()]))->at($token);
        }

        return $left;
    }

    private function parseNotPattern(): Pattern
    {
        $token = $this->current();
        if ($token->isIdentifier('not')) {
            $this->advance();

            return (new Pattern('not', null, null, null, null, [$this->parseNotPattern()]))->at($token);
        }

        return $this->parsePrimaryPattern();
    }

    private function parsePrimaryPattern(): Pattern
    {
        $token = $this->current();

        if ($token->is('{')) {
            return $this->parsePropertyPattern(null, $token);
        }

        if ($token->is('(')) {
            $this->advance();
            $pattern = $this->parsePattern();
            $this->expect(')');

            return $pattern;
        }

        if ($token->type === TokenType::Punct && in_array($token->value, ['<', '<=', '>', '>='], true)) {
            $this->advance();

            return (new Pattern('relational', $this->parseBinary(7), null, null, $token->value, []))->at($token);
        }

        if ($token->isIdentifier('_') && !$this->peek(1)->is('.') && !$this->peek(1)->is('(')) {
            $this->advance();

            return (new Pattern('discard', null, null, null, null, []))->at($token);
        }

        if ($token->isIdentifier('var') && $this->peek(1)->isIdentifier()) {
            $this->advance();

            return (new Pattern('var', null, null, $this->advance()->value, null, []))->at($token);
        }

        $isKeywordType = $token->type === TokenType::Keyword && in_array($token->value, self::PREDEFINED_TYPES, true) && !$this->peek(1)->is('.');
        if ($isKeywordType || $token->isIdentifier()) {
            $start = $this->pos;
            $type = $this->tryParseType();
            if ($type !== null && $this->check('{')) {
                return $this->parsePropertyPattern($type, $token);
            }
            if ($type !== null) {
                $next = $this->current();
                $hasDesignation = $next->isIdentifier() && !in_array($next->value, ['and', 'or', 'when'], true);
                if ($hasDesignation || $isKeywordType) {
                    $designation = $hasDesignation ? $this->advance()->value : null;

                    return (new Pattern('type', null, $type, $designation, null, []))->at($token);
                }
            }
            $this->pos = $start;
        }

        return (new Pattern('constant', $this->parseBinary(7), null, null, null, []))->at($token);
    }

    /** "{ Name: pattern, Address.City: pattern } designation" after an optional type. */
    private function parsePropertyPattern(?TypeRef $type, Token $start): Pattern
    {
        $this->expect('{');
        $members = [];
        $memberNodes = [];
        $patterns = [];
        while (!$this->check('}')) {
            $nameToken = $this->expectIdentifier('property name');
            $memberNodes[] = (new NameExpr($nameToken->value, []))->at($nameToken);
            $path = [$nameToken->value];
            while ($this->accept('.')) {
                $path[] = $this->expectIdentifier('property name')->value;
            }
            $this->expect(':');
            $pattern = $this->parsePattern();
            // Extended property pattern: { A.B: p } is { A: { B: p } }
            for ($i = count($path) - 1; $i > 0; $i--) {
                $pattern = (new Pattern('property', null, null, null, null, [$pattern], [$path[$i]], [(new NameExpr($path[$i], []))->at($nameToken)]))->at($nameToken);
            }
            $members[] = $path[0];
            $patterns[] = $pattern;
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect('}');

        $next = $this->current();
        $designation = $next->isIdentifier() && !in_array($next->value, ['and', 'or', 'when'], true) ? $this->advance()->value : null;

        return (new Pattern('property', null, $type, $designation, null, $patterns, $members, $memberNodes))->at($start);
    }

    // ------------------------------------------------------------------
    // Token helpers
    // ------------------------------------------------------------------

    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function peek(int $ahead): Token
    {
        return $this->tokens[min($this->pos + $ahead, count($this->tokens) - 1)];
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->pos];
        if ($token->type !== TokenType::EndOfFile) {
            $this->pos++;
        }

        return $token;
    }

    private function atEnd(): bool
    {
        return $this->current()->type === TokenType::EndOfFile;
    }

    private function check(string $value): bool
    {
        return $this->current()->is($value);
    }

    private function accept(string $value): ?Token
    {
        return $this->check($value) ? $this->advance() : null;
    }

    private function expect(string $value): Token
    {
        if (!$this->check($value)) {
            throw $this->error("'$value' expected", $this->current(), 'SP1002');
        }

        return $this->advance();
    }

    private function expectIdentifier(string $what = 'identifier'): Token
    {
        $token = $this->current();
        if (!$token->isIdentifier()) {
            $hint = $token->type === TokenType::Keyword ? " ('{$token->value}' is a keyword; use @{$token->value})" : '';
            throw $this->error(ucfirst($what) . " expected, found {$token->describe()}$hint", $token, 'SP1002');
        }

        return $this->advance();
    }

    private function error(string $message, Token $token, string $code = 'SP1002'): CompileError
    {
        return CompileError::at($code, $message, $this->file, $token->line, $token->column);
    }
}
