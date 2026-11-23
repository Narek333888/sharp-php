<?php

declare(strict_types=1);

namespace SharpPhp\Parser;

use SharpPhp\Compiler\CompileError;
use SharpPhp\Compiler\SourceFile;
use SharpPhp\Lexer\Lexer;
use SharpPhp\Lexer\Token;
use SharpPhp\Lexer\TokenType;
use SharpPhp\Syntax\Accessor;
use SharpPhp\Syntax\AnonymousObjectExpr;
use SharpPhp\Syntax\Argument;
use SharpPhp\Syntax\ArrayCreationExpr;
use SharpPhp\Syntax\AsExpr;
use SharpPhp\Syntax\AssignExpr;
use SharpPhp\Syntax\AwaitExpr;
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
use SharpPhp\Syntax\ExtensionBlock;
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
use SharpPhp\Syntax\LockStmt;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\MethodDecl;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\NameOfExpr;
use SharpPhp\Syntax\NamespaceDecl;
use SharpPhp\Syntax\NewExpr;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\NodeFinder;
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
use SharpPhp\Syntax\UsingStmt;
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

    /** Parsing the body of an async method or lambda (or top-level statements), where await is an operator. */
    private bool $inAsync = false;

    /** @var list<MethodDecl> local functions parsed in the current member (they become methods of the type) */
    private array $localFunctions = [];

    /** @var list<TypeDecl> delegate types declared inside a class: types of the namespace (C# nests them) */
    private array $hoistedTypes = [];

    /** The member being parsed is static (or top-level code): its local functions are static methods. */
    private bool $inStaticMember = false;

    /** @var list<string> the type parameters of the methods around the code being parsed (local functions get them too) */
    private array $enclosingTypeParams = [];

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
        $topLevelFunctions = [];

        while (!$this->atEnd()) {
            if ($this->check('namespace')) {
                $nsToken = $this->advance();
                $name = $this->parseQualifiedName();

                if ($this->accept(';')) {
                    $nsUsings = $this->parseUsings();
                    $types = [];
                    while (!$this->atEnd()) {
                        $types[] = $this->parseTypeDecl();
                        array_push($types, ...$this->takeHoistedTypes());
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
                    array_push($types, ...$this->takeHoistedTypes());
                }
                $this->expect('}');
                $namespaces[] = (new NamespaceDecl($name, $nsUsings, $types))->at($nsToken);
                continue;
            }

            if ($this->isTypeDeclarationStart()) {
                $globalTypes[] = $this->parseTypeDecl();
                array_push($globalTypes, ...$this->takeHoistedTypes());
                continue;
            }

            if ($this->check('using') && !$this->isUsingStatement()) {
                throw $this->error('A using directive must precede all other elements defined in the file', $this->current());
            }

            // C# allows await in top-level statements: Main becomes async.
            $this->inStaticMember = true;
            $statement = $this->inAsyncContext(true, fn (): Stmt => $this->parseStatement());
            $topLevel[] = $statement;
            $topLevelFunctions = [...$topLevelFunctions, ...$this->takeLocalFunctions([$statement])];
        }

        if ($globalTypes !== []) {
            array_unshift($namespaces, (new NamespaceDecl('', [], $globalTypes))->at($start));
        }

        $statements = $this->withUsingDeclarations($topLevel);
        foreach ($topLevelFunctions as $function) {
            $function->enclosingLocals = self::declaredNames($statements);
        }

        return (new CompilationUnit($this->file, $usings, $namespaces, $statements, $topLevelFunctions))->at($start);
    }

    /**
     * The local functions parsed since the last call, which become methods of the type. $enclosing
     * is the code around them: they cannot use its variables.
     *
     * @param list<Node> $enclosing
     * @return list<MethodDecl>
     */
    private function takeLocalFunctions(array $enclosing): array
    {
        $functions = $this->localFunctions;
        $this->localFunctions = [];
        if ($functions !== []) {
            $names = self::declaredNames($enclosing);
            foreach ($functions as $function) {
                $function->enclosingLocals = $names;
            }
        }

        return $functions;
    }

    /**
     * The names of the variables and parameters declared in $roots.
     *
     * @param list<Node> $roots
     * @return list<string>
     */
    private static function declaredNames(array $roots): array
    {
        $names = [];
        foreach (NodeFinder::all($roots, static fn (Node $n): bool => true) as $node) {
            $name = match (true) {
                $node instanceof VarDeclarator, $node instanceof Parameter, $node instanceof DeclarationExpr => $node->name,
                $node instanceof ForeachStmt, $node instanceof CatchClause => $node->variable,
                $node instanceof Pattern => $node->designation,
                default => null,
            };
            if ($name !== null && $name !== '_') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
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
                $alias = $this->advance()->text();
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
        $name = $this->expectIdentifier()->text();
        while ($this->check('.') && $this->peek(1)->isIdentifier()) {
            $this->advance();
            $name .= '.' . $this->advance()->text();
        }

        return $name;
    }

    /**
     * "using var r = ...;", "using (...)" and "using Stream s = ...;" are statements (also in top-level
     * code after other statements); "using System;" and "using A = B;" are directives.
     */
    private function isUsingStatement(): bool
    {
        if ($this->peek(1)->is('(') || ($this->peek(1)->isIdentifier('var') && $this->peek(2)->isIdentifier())) {
            return true;
        }
        $start = $this->pos;
        $this->advance();
        try {
            return $this->tryParseType() !== null && $this->current()->isIdentifier();
        } finally {
            $this->pos = $start;
        }
    }

    /** @return list<TypeDecl> */
    private function takeHoistedTypes(): array
    {
        $types = $this->hoistedTypes;
        $this->hoistedTypes = [];

        return $types;
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
            || ($token->isIdentifier('record') && ($this->peek($i + 1)->isIdentifier() || $this->peek($i + 1)->is('class') || $this->peek($i + 1)->is('struct')))
            // delegate int Transform(int x); (not an anonymous method: delegate (int x) { ... })
            || ($token->is('delegate') && !$this->peek($i + 1)->is('(') && !$this->peek($i + 1)->is('{'));
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
            if (in_array($token->value, ['extern', 'unsafe', 'volatile'], true)) {
                throw $this->error("The '{$token->value}' modifier is not supported", $token, 'SP1010');
            }
            $modifiers[] = $token->text();
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
        $open = $this->peek($i)->text();
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

    /**
     * delegate TResult Transform<in T, out TResult>(T value);  a type that is a function of that signature,
     * the same as Func<T, TResult> to the compiler (see TypeResolver). Its one member is Invoke.
     *
     * @param list<string> $modifiers
     */
    private function parseDelegate(Token $start, array $modifiers): TypeDecl
    {
        $this->expect('delegate');
        if ($this->check('void')) {
            $voidToken = $this->advance();
            $returnType = (new TypeRef('void', [], false))->at($voidToken);
        } else {
            $returnType = $this->parseType();
        }
        $name = $this->expectIdentifier('delegate name');
        $variance = [];
        $typeParams = $this->check('<') ? $this->parseTypeParameterList(true, $variance) : [];
        $params = $this->parseParameterList();
        $constraints = $this->parseConstraints();
        $this->expect(';');
        $invoke = (new MethodDecl(['public'], $returnType, 'Invoke', [], $params, null, null, 'method', null, [], []))->at($name);

        return (new TypeDecl('delegate', $name->text(), $modifiers, $typeParams, [], [$invoke], [], [], $constraints, $variance))->at($start);
    }

    private function parseTypeDecl(): TypeDecl
    {
        $this->skipAttributes();
        $start = $this->current();
        $modifiers = $this->parseModifiers();
        $kindToken = $this->current();

        if ($kindToken->is('delegate')) {
            return $this->parseDelegate($start, $modifiers);
        }
        if ($kindToken->isIdentifier('record')) {
            $this->advance();
            $kind = 'record';
            if ($this->check('struct')) {
                throw $this->error("'record struct' is not supported", $this->current(), 'SP1010');
            }
            $this->accept('class');
        } elseif ($kindToken->is('class') || $kindToken->is('struct') || $kindToken->is('interface') || $kindToken->is('enum')) {
            $this->advance();
            $kind = $kindToken->text();
        } else {
            throw $this->error('Type declaration expected (class, interface, enum, struct or record)', $kindToken);
        }

        $name = $this->expectIdentifier('type name')->text();
        $variance = [];
        $typeParams = $this->check('<') ? $this->parseTypeParameterList($kind === 'interface', $variance) : [];
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
                $member = $this->parseMember($name, $kind);
                if ($member instanceof ExtensionBlock) {
                    array_push($members, ...$member->members);
                } else {
                    $members[] = $member;
                }
                array_push($members, ...$this->takeLocalFunctions([$member]));
            }
            $this->expect('}');
            $this->accept(';');
        }

        return (new TypeDecl($kind, $name, $modifiers, $typeParams, $baseTypes, $members, $recordParams, $enumMembers, $constraints, $variance))->at($start);
    }

    /**
     * <T, in TIn, out TOut>. Variance (in/out) is allowed only where $variant (interfaces), as in C#.
     *
     * @param array<string, 'in'|'out'> $variance the variant parameters, by name
     * @return list<string>
     */
    private function parseTypeParameterList(bool $variant = false, array &$variance = []): array
    {
        $this->expect('<');
        $names = [];
        do {
            $this->skipAttributes();
            $modifier = null;
            if ($this->check('in') || $this->check('out')) {
                $token = $this->advance();
                if (!$variant) {
                    throw $this->error('Invalid variance modifier. Only interface and delegate type parameters can be specified as variant.', $token, 'SP1960');
                }
                $modifier = $token->text() === 'in' ? 'in' : 'out';
            }
            $name = $this->expectIdentifier('type parameter')->text();
            $names[] = $name;
            if ($modifier !== null) {
                $variance[$name] = $modifier;
            }
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
            $param = $this->expectIdentifier('type parameter')->text();
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
            $members[] = (new EnumMember($token->text(), $value))->at($token);
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect('}');
        $this->accept(';');

        return $members;
    }

    /**
     * ~Name() { ... }: runs when the object is freed (compiled to PHP's __destruct).
     *
     * @param list<string> $modifiers
     */
    private function parseFinalizer(Token $start, array $modifiers, string $typeName, string $typeKind): MethodDecl
    {
        $tilde = $this->advance();
        if ($modifiers !== []) {
            throw $this->error("The modifier '{$modifiers[0]}' is not valid for this item", $start, 'SP0106');
        }
        $name = $this->expectIdentifier('type name');
        if ($name->text() !== $typeName) {
            throw $this->error('Name of destructor must match name of type', $name, 'SP0574');
        }
        if ($typeKind === 'struct' || $typeKind === 'interface') {
            throw $this->error('Only class types can contain destructors', $tilde, 'SP0575');
        }
        $this->expect('(');
        if (!$this->check(')')) {
            throw $this->error("')' expected (a finalizer has no parameters)", $this->current(), 'SP1026');
        }
        $this->expect(')');
        [$body, $exprBody] = $this->parseMethodBody();

        return (new MethodDecl([], null, '~' . $typeName, [], [], $body, $exprBody, 'finalizer', null, [], []))->at($tilde);
    }

    /**
     * extension(string s) { ... } / extension<T>(IEnumerable<T> items) { ... } / extension(string) { ... }:
     * C# 14 extension members, lowered to static methods of the enclosing class (see ExtensionBlock).
     */
    private function parseExtensionBlock(string $typeName): ExtensionBlock
    {
        $keyword = $this->advance();
        $typeParams = $this->check('<') ? $this->parseTypeParameterList() : [];
        $this->expect('(');
        $receiverType = $this->parseType();
        $receiverName = $this->current()->isIdentifier() ? $this->advance()->text() : null;
        $this->expect(')');
        $constraints = $this->parseConstraints();
        $this->expect('{');
        $members = [];
        while (!$this->check('}') && !$this->atEnd()) {
            $member = $this->parseMember($typeName, 'extension');
            $members[] = $this->lowerExtensionMember($member, $receiverType, $receiverName, $typeParams, $constraints);
        }
        $this->expect('}');

        return (new ExtensionBlock($members))->at($keyword);
    }

    /**
     * @param list<string> $typeParams the extension block's type parameters
     * @param array<string, list<TypeRef>> $constraints
     */
    private function lowerExtensionMember(Node $member, TypeRef $receiverType, ?string $receiverName, array $typeParams, array $constraints): MethodDecl
    {
        if ($member instanceof MethodDecl && $member->kind === 'method') {
            $static = in_array('static', $member->modifiers, true);
            $allTypeParams = [...$typeParams, ...$member->typeParams];
            $allConstraints = [...$constraints, ...$member->constraints];
            if ($static) {
                return (new MethodDecl($member->modifiers, $member->returnType, $member->name, $allTypeParams, $member->params, $member->body, $member->exprBody, 'method', null, [], $allConstraints, $receiverType))->at($member);
            }
            $receiver = $this->extensionReceiver($receiverType, $receiverName, $member);

            return (new MethodDecl([...$member->modifiers, 'static'], $member->returnType, $member->name, $allTypeParams, [$receiver, ...$member->params], $member->body, $member->exprBody, 'method', null, [], $allConstraints))->at($member);
        }
        if ($member instanceof PropertyDecl) {
            if ($member->init !== null) {
                throw $this->errorAt('An extension property cannot have an initializer: it has no storage', $member, 'SP9282');
            }
            [$body, $exprBody] = [null, $member->exprBody];
            foreach ($member->accessors as $accessor) {
                if ($accessor->kind !== 'get') {
                    throw $this->errorAt("Extension properties with a '{$accessor->kind}' accessor are not supported; declare a method instead", $accessor, 'SP1010');
                }
                if ($accessor->body === null && $accessor->exprBody === null) {
                    throw $this->errorAt('An extension property must have a get accessor with a body: it has no storage for an auto-property', $member, 'SP9282');
                }
                [$body, $exprBody] = [$accessor->body, $accessor->exprBody];
            }
            if ($body === null && $exprBody === null) {
                throw $this->errorAt('An extension property must have a get accessor with a body', $member, 'SP9282');
            }
            $static = in_array('static', $member->modifiers, true);
            $params = $static ? [] : [$this->extensionReceiver($receiverType, $receiverName, $member)];
            $modifiers = $static ? $member->modifiers : [...$member->modifiers, 'static'];

            return (new MethodDecl($modifiers, $member->type, 'get_' . $member->name, $typeParams, $params, $body, $exprBody, 'method', null, [], $constraints, $static ? $receiverType : null, $member->name))->at($member);
        }

        throw $this->errorAt('Only methods and properties can be declared in an extension block', $member, 'SP9282');
    }

    private function extensionReceiver(TypeRef $type, ?string $name, Node $member): Parameter
    {
        if ($name === null) {
            throw $this->errorAt('An extension block without a receiver name can only declare static members: extension(Type name) { ... }', $member, 'SP9303');
        }

        return (new Parameter($name, $type, null, 'this'))->at($type);
    }

    private function parseMember(string $typeName, string $typeKind): Node
    {
        $this->skipAttributes();
        $start = $this->current();
        $modifiers = $this->parseModifiers();
        $token = $this->current();
        // Members of an extension block become static methods, and so do their local functions.
        $this->inStaticMember = in_array('static', $modifiers, true) || $typeKind === 'extension';

        if ($token->is('class') || $token->is('struct') || $token->is('interface') || $token->is('enum') || $token->isIdentifier('record') && $this->peek(1)->isIdentifier()) {
            throw $this->error('Nested types are not supported; declare the type at namespace level', $token, 'SP1010');
        }
        if ($token->is('~')) {
            return $this->parseFinalizer($start, $modifiers, $typeName, $typeKind);
        }
        if ($token->isIdentifier('extension') && ($this->peek(1)->is('(') || $this->peek(1)->is('<'))) {
            if ($typeKind === 'extension') {
                throw $this->error('An extension block cannot be declared inside another one', $token, 'SP9282');
            }
            if ($modifiers !== []) {
                throw $this->error("The modifier '{$modifiers[0]}' is not valid for this item", $start, 'SP0106');
            }

            return $this->parseExtensionBlock($typeName);
        }
        if ($token->is('delegate')) {
            // A delegate type declared in a class: C# nests it; here it is a type of the namespace.
            $this->hoistedTypes[] = $this->parseDelegate($start, $modifiers);

            return (new ExtensionBlock([]))->at($start);
        }
        if ($token->is('event')) {
            // public event Action<string>? Changed;  a field holding the delegate, which code outside
            // the class can only add to and remove from (+= and -=).
            $this->advance();
            $eventType = $this->parseType();
            $nameToken = $this->expectIdentifier('event name');
            if ($this->check('{')) {
                throw $this->error('Events with add and remove accessors are not supported; declare a field-like event (event Action Changed;)', $this->current(), 'SP1010');
            }
            $declarators = [];
            $declToken = $nameToken;
            while (true) {
                $init = $this->accept('=') ? $this->parseVariableInitializer() : null;
                $declarators[] = (new VarDeclarator($declToken->text(), $init))->at($declToken);
                if (!$this->accept(',')) {
                    break;
                }
                $declToken = $this->expectIdentifier('event name');
            }
            $this->expect(';');

            return (new FieldDecl([...$modifiers, 'event'], $eventType, $declarators, false))->at($start);
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
                $initializer = $kind->text();
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
            $this->enclosingTypeParams = $typeParams;
            try {
                [$body, $exprBody] = $this->inAsyncContext(in_array('async', $modifiers, true), fn (): array => $this->parseMethodBody());
            } finally {
                $this->enclosingTypeParams = [];
            }
            if ($typeKind === 'interface' && ($body !== null || $exprBody !== null)) {
                throw $this->error('Default interface methods are not supported; use an abstract base class', $nameToken, 'SP8701');
            }

            return (new MethodDecl($modifiers, $type, $nameToken->text(), $typeParams, $params, $body, $exprBody, 'method', null, [], $constraints))->at($start);
        }

        // Property with accessors
        if ($this->check('{')) {
            $accessors = $this->parseAccessors();
            $init = null;
            if ($this->accept('=')) {
                $init = $this->parseVariableInitializer();
                $this->expect(';');
            }

            return (new PropertyDecl($modifiers, $type, $nameToken->text(), $accessors, null, $init))->at($start);
        }

        // Expression-bodied property
        if ($this->accept('=>')) {
            $expr = $this->parseExpression();
            $this->expect(';');

            return (new PropertyDecl($modifiers, $type, $nameToken->text(), [], $expr, null))->at($start);
        }

        // Field(s)
        $declarators = [];
        $declToken = $nameToken;
        while (true) {
            $init = $this->accept('=') ? $this->parseVariableInitializer() : null;
            $declarators[] = (new VarDeclarator($declToken->text(), $init))->at($declToken);
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
            $accessors[] = (new Accessor($kind->text(), $modifiers, $body, $exprBody))->at($start);
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
            // "this" marks the receiver of an extension method; the Declarator checks where it may be.
            if ($this->check('ref') || $this->check('out') || $this->check('in') || $this->check('params') || $this->check('this')) {
                $modifier = $this->advance()->text();
            }
            $type = $this->parseType();
            $name = $this->expectIdentifier('parameter name');
            $default = $this->accept('=') ? $this->parseExpression() : null;
            $params[] = (new Parameter($name->text(), $type, $default, $modifier))->at($start);
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
            $type = (new TypeRef($token->text(), [], false))->at($token);
        } elseif ($token->isIdentifier()) {
            $name = $this->advance()->text();
            while ($this->check('.') && $this->peek(1)->isIdentifier()) {
                $this->advance();
                $name .= '.' . $this->advance()->text();
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

        return (new Block($this->withUsingDeclarations($statements)))->at($start);
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
                    return $this->parseUsingStatement();
                case 'lock':
                    return $this->parseLock();
                case 'goto':
                case 'checked':
                case 'unchecked':
                case 'unsafe':
                case 'fixed':
                    throw $this->error("'{$token->text()}' statements are not supported", $token, 'SP1010');
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

        $function = $this->tryParseLocalFunction();
        if ($function !== null) {
            return $function;
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

    /**
     * The type of an integer literal, as in C#: 10L is a long, 10u a uint, 10UL a ulong; without a
     * suffix the first of int, uint, long that holds the value (3000000000 is a uint).
     */
    private function integerLiteralType(Token $token): string
    {
        $text = strtolower(substr($this->file->text, $token->offset, $token->length));
        $suffix = substr($text, strlen(rtrim($text, 'ul')));
        $value = is_int($token->value) ? $token->value : 0;
        $fits = static fn (string $type): bool => $value >= \SharpPhp\Semantics\Type::INTEGRAL_RANGES[$type][0] && $value <= \SharpPhp\Semantics\Type::INTEGRAL_RANGES[$type][1];

        return match (true) {
            str_contains($suffix, 'u') && str_contains($suffix, 'l') => 'ulong',
            $suffix === 'l' => 'long',
            $suffix === 'u' => $fits('uint') ? 'uint' : 'ulong',
            $fits('int') => 'int',
            $fits('uint') => 'uint',
            default => 'long',
        };
    }

    /** lock (gate) { ... } */
    private function parseLock(): LockStmt
    {
        $token = $this->advance();
        $this->expect('(');
        $target = $this->parseExpression();
        $this->expect(')');

        return (new LockStmt($target, $this->parseEmbeddedStatement()))->at($token);
    }

    /** using (var r = ...) statement, using (expression) statement, or "using var r = ...;". */
    private function parseUsingStatement(): UsingStmt
    {
        $token = $this->advance();
        if ($this->accept('(')) {
            $declaration = $this->tryParseLocalDeclaration();
            $expression = $declaration === null ? $this->parseExpression() : null;
            $this->expect(')');

            return (new UsingStmt($declaration, $expression, $this->parseEmbeddedStatement(), false))->at($token);
        }
        $declaration = $this->tryParseLocalDeclaration()
            ?? throw $this->error("'(' or a variable declaration expected after 'using'", $this->current());
        $this->expect(';');

        return (new UsingStmt($declaration, null, null, true))->at($token);
    }

    /**
     * "using var r = ...;" disposes r at the end of the enclosing block: the statements after it
     * become the body of the using statement.
     *
     * @param list<Stmt> $statements
     * @return list<Stmt>
     */
    private function withUsingDeclarations(array $statements): array
    {
        foreach ($statements as $i => $statement) {
            if ($statement instanceof UsingStmt && $statement->body === null) {
                $rest = $this->withUsingDeclarations(array_slice($statements, $i + 1));
                $body = (new Block($rest))->at($statement);

                return [...array_slice($statements, 0, $i), (new UsingStmt($statement->declaration, null, $body, true))->at($statement)];
            }
        }

        return $statements;
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
                $deconstruction[] = $this->expectIdentifier('variable name')->text();
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
            $variable = $this->expectIdentifier('variable name')->text();
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
            $sections[] = (new SwitchSection($labels, $this->withUsingDeclarations($statements)))->at($sectionStart);
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
                    $variable = $this->advance()->text();
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
        // "await Fail();" in async code is an expression statement, not a declaration of a type "await".
        if ($this->inAsync && $token->isIdentifier('await') && $this->startsAwaitOperand($this->peek(1))) {
            return null;
        }

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
        }
        $this->pos = $start;

        return null;
    }

    /**
     * A local function ("static int Square(int x) => x * x;", "async Task SaveAsync() { ... }"):
     * compiled to a private method of the type (static in static code), so it can be generic,
     * recursive, an iterator or async, and be called before its declaration. It cannot use the
     * variables of the code around it. The statement itself is empty.
     */
    private function tryParseLocalFunction(): ?Stmt
    {
        $start = $this->pos;
        $token = $this->current();
        if ($this->inAsync && $token->isIdentifier('await') && $this->startsAwaitOperand($this->peek(1))) {
            return null;
        }
        $modifiers = [];
        while ($this->current()->is('static') || ($this->current()->isIdentifier('async') && !$this->peek(1)->is('(') && !$this->peek(1)->is('=>'))) {
            $modifiers[] = $this->advance()->text();
        }
        $typeToken = $this->current();
        if ($typeToken->is('void')) {
            $this->advance();
            $type = (new TypeRef('void', [], false))->at($typeToken);
        } else {
            $type = $this->tryParseType();
        }
        if ($type === null || !$this->current()->isIdentifier() || !($this->peek(1)->is('(') || $this->peek(1)->is('<'))) {
            $this->pos = $start;

            return null;
        }
        $nameToken = $this->advance();
        $typeParams = $this->check('<') ? $this->parseTypeParameterList() : [];
        if (!$this->check('(')) {
            $this->pos = $start;

            return null;
        }
        // A local function of a generic method may use the method's T: the method it becomes has it too
        // (inferred from the arguments).
        $typeParams = [...array_values(array_diff($this->enclosingTypeParams, $typeParams)), ...$typeParams];
        $params = $this->parseParameterList();
        $constraints = $this->parseConstraints();
        $outerStatic = $this->inStaticMember;
        $outerTypeParams = $this->enclosingTypeParams;
        $static = in_array('static', $modifiers, true) || $outerStatic;
        try {
            $this->inStaticMember = $static;
            $this->enclosingTypeParams = $typeParams;
            [$body, $exprBody] = $this->inAsyncContext(in_array('async', $modifiers, true), fn (): array => $this->parseMethodBody());
        } finally {
            $this->inStaticMember = $outerStatic;
            $this->enclosingTypeParams = $outerTypeParams;
        }
        if ($body === null && $exprBody === null) {
            throw $this->error("'{$nameToken->text()}' must declare a body", $nameToken, 'SP0501');
        }
        $methodModifiers = ['private', ...($static ? ['static'] : []), ...(in_array('async', $modifiers, true) ? ['async'] : [])];
        $this->localFunctions[] = (new MethodDecl($methodModifiers, $type, $nameToken->text(), $typeParams, $params, $body, $exprBody, 'method', null, [], $constraints, isLocalFunction: true))->at($nameToken);

        return (new EmptyStmt())->at($token);
    }

    /** @return list<VarDeclarator> */
    private function parseDeclarators(): array
    {
        $declarators = [];
        do {
            $name = $this->expectIdentifier('variable name');
            $init = $this->accept('=') ? $this->parseVariableInitializer() : null;
            $declarators[] = (new VarDeclarator($name->text(), $init))->at($name);
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
        // async x => ..., async (a, b) => ... ("async => ..." is a lambda whose parameter is named async)
        if ($this->peek($i)->isIdentifier('async') && !$this->peek($i + 1)->is('=>')) {
            $i++;
        }
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
        $isAsync = false;
        if ($this->current()->isIdentifier('async') && !$this->peek(1)->is('=>')) {
            $this->advance();
            $isAsync = true;
        }
        $params = [];

        if ($this->current()->isIdentifier()) {
            $name = $this->advance();
            $params[] = (new Parameter($name->text(), null, null, null))->at($name);
        } else {
            $this->expect('(');
            while (!$this->check(')')) {
                $paramStart = $this->current();
                $modifier = null;
                if ($this->check('ref') || $this->check('out') || $this->check('in')) {
                    $modifier = $this->advance()->text();
                }
                if ($this->current()->isIdentifier() && ($this->peek(1)->is(',') || $this->peek(1)->is(')'))) {
                    $params[] = (new Parameter($this->advance()->text(), null, null, $modifier))->at($paramStart);
                } else {
                    $type = $this->parseType();
                    $name = $this->expectIdentifier('parameter name');
                    $params[] = (new Parameter($name->text(), $type, null, $modifier))->at($paramStart);
                }
                if (!$this->accept(',')) {
                    break;
                }
            }
            $this->expect(')');
        }

        $this->expect('=>');
        $body = $this->inAsyncContext($isAsync, fn (): Block|Expr => $this->check('{') ? $this->parseBlock() : $this->parseExpression());

        return (new LambdaExpr($params, $body, $isStatic, $isAsync))->at($start);
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

                return (new UnaryExpr($token->text(), $this->parseUnary(), false))->at($token);
            }
            if ($token->value === '(' && $this->isCast()) {
                $this->advance();
                $type = $this->parseType();
                $this->expect(')');

                return (new CastExpr($type, $this->parseUnary()))->at($token);
            }
        }
        if ($token->isIdentifier('await') && $this->startsAwaitOperand($this->peek(1))) {
            if (!$this->inAsync) {
                throw $this->error("The 'await' operator can only be used within an async method. Consider marking this method with the 'async' modifier and changing its return type to 'Task'.", $token, 'SP4033');
            }
            $this->advance();

            return (new AwaitExpr($this->parseUnary()))->at($token);
        }

        return $this->parsePostfix($this->parsePrimary());
    }

    /**
     * After "await": the start of its operand. Outside async code "await" may still be a name
     * (await(x), await.Run(), var await = 1;), so only an operand that cannot follow a name counts.
     */
    private function startsAwaitOperand(Token $next): bool
    {
        if ($this->inAsync) {
            return !$next->is(';') && !$next->is(')') && !$next->is(',') && !$next->is('=') && !$next->is('.')
                && !$next->is(']') && !$next->is('}') && !$next->is('=>') && $next->type !== TokenType::EndOfFile;
        }

        return $next->isIdentifier() || $next->is('new') || $next->is('this') || $next->is('base');
    }

    /**
     * @template R
     * @param callable(): R $parse
     * @return R
     */
    private function inAsyncContext(bool $async, callable $parse): mixed
    {
        $outer = $this->inAsync;
        $this->inAsync = $async;
        try {
            return $parse();
        } finally {
            $this->inAsync = $outer;
        }
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

                return (new LiteralExpr('int', $token->value, $this->integerLiteralType($token)))->at($token);
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

                return (new NameExpr($token->text(), $this->tryParseGenericArgumentsInExpression()))->at($token);
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

            return (new PredefinedTypeExpr($token->text()))->at($token);
        }

        if ($token->value === 'delegate') {
            throw $this->error('Anonymous methods are not supported; use a lambda instead', $token, 'SP1010');
        }

        throw $this->error("Invalid expression term '{$token->text()}'", $token, 'SP1003');
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
                    $expr = (new MemberAccessExpr($expr, $name->text(), $token->text() === '?.', $typeArgs))->at($name);
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
                    $expr = (new UnaryExpr($token->text(), $expr, true))->at($token);
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
                $name = $this->advance()->text();
                $this->advance();
            }
            $modifier = null;
            if ($this->check('ref') || $this->check('out') || $this->check('in')) {
                $modifier = $this->advance()->text();
            }

            $value = null;
            if ($modifier === 'out') {
                $declStart = $this->current();
                if ($declStart->isIdentifier('var') && $this->peek(1)->isIdentifier()) {
                    $this->advance();
                    $value = (new DeclarationExpr(null, $this->advance()->text()))->at($declStart);
                } else {
                    $save = $this->pos;
                    $type = $this->tryParseType();
                    if ($type !== null && $this->current()->isIdentifier() && ($this->peek(1)->is(',') || $this->peek(1)->is(')'))) {
                        $value = (new DeclarationExpr($type, $this->advance()->text()))->at($declStart);
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

        if ($this->check('{')) {
            return $this->parseAnonymousObject($token);
        }

        if ($this->check('[')) {
            $this->advance();
            $this->expect(']');

            return (new ArrayCreationExpr(null, null, $this->parseArrayInitializer()))->at($token);
        }

        if ($this->check('(')) {
            $open = $this->current();
            $args = $this->parseArgumentList();
            $init = $this->check('{') ? $this->parseObjectOrCollectionInitializer() : null;

            return (new NewExpr(null, $args, $init))->at($token)->argumentsAt($open);
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

        $open = $this->current();
        $hasArgs = $this->check('(');
        $args = $hasArgs ? $this->parseArgumentList() : [];
        $init = $this->check('{') ? $this->parseObjectOrCollectionInitializer() : null;
        if (!$hasArgs && $init === null) {
            throw $this->error('A new expression requires (), [] or {} after the type', $this->current());
        }
        $new = (new NewExpr($type, $args, $init))->at($token);

        return $hasArgs ? $new->argumentsAt($open) : $new;
    }

    /** new { Id = 1, name, user.Email }: a property name is given, or taken from a name or member access. */
    private function parseAnonymousObject(Token $new): AnonymousObjectExpr
    {
        $this->expect('{');
        $names = [];
        $values = [];
        while (!$this->check('}')) {
            $token = $this->current();
            if ($token->isIdentifier() && $this->peek(1)->is('=')) {
                $this->advance();
                $this->advance();
                $name = $token->text();
                $value = $this->parseExpression();
            } else {
                $value = $this->parseExpression();
                $name = match (true) {
                    $value instanceof NameExpr && $value->typeArgs === [] => $value->name,
                    $value instanceof MemberAccessExpr => $value->name,
                    default => throw $this->error('Invalid anonymous type member declarator. Anonymous type members must be declared with a member assignment, simple name or member access.', $token, 'SP0746'),
                };
            }
            if (in_array($name, $names, true)) {
                throw $this->error('An anonymous type cannot have multiple properties with the same name', $token, 'SP0833');
            }
            $names[] = $name;
            $values[] = $value;
            if (!$this->accept(',')) {
                break;
            }
        }
        $this->expect('}');

        return (new AnonymousObjectExpr($names, $values))->at($new);
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
                    $items[] = (new InitializerItem('member', $token->text(), null, [], $this->parseObjectOrCollectionInitializer()))->at($token);
                } else {
                    $items[] = (new InitializerItem('member', $token->text(), null, [$this->parseExpression()]))->at($token);
                }
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
        foreach ($token->interpolationParts() as $part) {
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
            $parts[] = (new InterpolationHole($expr, $part['format'], $part['alignment']))->at($expr);
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

            return (new Pattern('relational', $this->parseBinary(7), null, null, $token->text(), []))->at($token);
        }

        if ($token->isIdentifier('_') && !$this->peek(1)->is('.') && !$this->peek(1)->is('(')) {
            $this->advance();

            return (new Pattern('discard', null, null, null, null, []))->at($token);
        }

        if ($token->isIdentifier('var') && $this->peek(1)->isIdentifier()) {
            $this->advance();

            return (new Pattern('var', null, null, $this->advance()->text(), null, []))->at($token);
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
                    $designation = $hasDesignation ? $this->advance()->text() : null;

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
            $memberNodes[] = (new NameExpr($nameToken->text(), []))->at($nameToken);
            $path = [$nameToken->text()];
            while ($this->accept('.')) {
                $path[] = $this->expectIdentifier('property name')->text();
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
        $designation = $next->isIdentifier() && !in_array($next->text(), ['and', 'or', 'when'], true) ? $this->advance()->text() : null;

        return (new Pattern('property', null, $type, $designation, null, $patterns, $members, $memberNodes))->at($start);
    }

    // ------------------------------------------------------------------
    // Token helpers
    // ------------------------------------------------------------------

    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    /** @phpstan-impure reads the parser position */
    private function peek(int $ahead): Token
    {
        return $this->tokens[min($this->pos + $ahead, count($this->tokens) - 1)];
    }

    /** @phpstan-impure reads the parser position */
    private function advance(): Token
    {
        $token = $this->tokens[$this->pos];
        if ($token->type !== TokenType::EndOfFile) {
            $this->pos++;
        }

        return $token;
    }

    /** @phpstan-impure reads the parser position */
    private function atEnd(): bool
    {
        return $this->current()->type === TokenType::EndOfFile;
    }

    /** @phpstan-impure reads the parser position */
    private function check(string $value): bool
    {
        return $this->current()->is($value);
    }

    /** @phpstan-impure reads the parser position */
    private function accept(string $value): ?Token
    {
        return $this->check($value) ? $this->advance() : null;
    }

    /** @phpstan-impure reads the parser position */
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
            $hint = $token->type === TokenType::Keyword ? " ('{$token->text()}' is a keyword; use @{$token->text()})" : '';
            throw $this->error(ucfirst($what) . " expected, found {$token->describe()}$hint", $token, 'SP1002');
        }

        return $this->advance();
    }

    private function error(string $message, Token $token, string $code = 'SP1002'): CompileError
    {
        return CompileError::at($code, $message, $this->file, $token->line, $token->column);
    }

    private function errorAt(string $message, Node $node, string $code): CompileError
    {
        return CompileError::at($code, $message, $this->file, $node->line, $node->column);
    }
}
