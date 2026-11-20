<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

use SharpPhp\CodeGen\Concerns\ChecksTypes;
use SharpPhp\CodeGen\Concerns\EmitsDeclarations;
use SharpPhp\CodeGen\Concerns\EmitsExpressions;
use SharpPhp\CodeGen\Concerns\EmitsMembers;
use SharpPhp\CodeGen\Concerns\EmitsPatterns;
use SharpPhp\CodeGen\Concerns\EmitsStatements;
use SharpPhp\Compiler\CompileError;
use SharpPhp\Compiler\Declarator;
use SharpPhp\Compiler\SemanticModel;
use SharpPhp\Compiler\GeneratedFile;
use SharpPhp\Compiler\SourceFile;
use SharpPhp\Semantics\AccessRules;
use SharpPhp\Semantics\ArgumentMatcher;
use SharpPhp\Semantics\Conversions;
use SharpPhp\Semantics\ExtensionMethods;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\ResolutionContext;
use SharpPhp\Semantics\SymbolTable;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeResolver;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Semantics\Violation;
use SharpPhp\Syntax\CompilationUnit;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\TypeDecl;
use SharpPhp\Syntax\TypeRef;

/**
 * Translates one compilation unit into PHP source code.
 */
final class Emitter
{
    use EmitsDeclarations;
    use EmitsStatements;
    use EmitsExpressions;
    use EmitsMembers;
    use EmitsPatterns;
    use ChecksTypes;

    /** System.Threading.Tasks.Task, which async methods return. */
    private const TASK_CLASS = Names::RUNTIME_NAMESPACE . '\\System\\Threading\\Tasks\\Task';

    public const VERSION = '0.6.1';

    private CodeWriter $w;
    private ResolutionContext $ctx;
    private Imports $imports;
    private SourceFile $file;
    private ?TypeSymbol $class = null;
    private ?TypeDecl $classDecl = null;
    private ?FunctionContext $fn = null;
    private Scope $scope;

    private readonly Conversions $conversions;
    private readonly AccessRules $access;
    private readonly ArgumentMatcher $argumentMatcher;
    private readonly ExtensionMethods $extensions;
    private readonly FlowState $flow;

    /** Expression statement being emitted, whose value is not used (see effect()). */
    private ?\SharpPhp\Syntax\Expr $discarded = null;

    /** @var list<string> */
    private array $staticInitCalls = [];

    /** @var list<string> statements that turn PHP arrays passed to collection parameters into collections (see parameters()) */
    private array $paramConversions = [];

    /**
     * @param SemanticModel|null $model analysis mode (Compiler::analyze): record what names mean and
     *        go on after errors, so the editor learns as much as possible about code being typed
     */
    public function __construct(
        private readonly SymbolTable $symbols,
        private readonly TypeResolver $resolver,
        private readonly Declarator $declarator,
        private readonly ?SemanticModel $model = null,
    ) {
        $this->scope = new Scope();
        $this->conversions = new Conversions($symbols);
        $this->access = new AccessRules($symbols);
        $this->argumentMatcher = new ArgumentMatcher();
        $this->extensions = new ExtensionMethods($symbols, $this->conversions);
        $this->flow = new FlowState();
    }

    public function emit(CompilationUnit $unit): GeneratedFile
    {
        $this->file = $unit->file;
        $blocks = [];
        $classes = [];

        $namespaces = $unit->namespaces;
        $hasProgram = $unit->topLevelStatements !== [];

        foreach ($namespaces as $ns) {
            $this->ctx = $this->declarator->contextFor($unit, $ns);
            $phpNs = $this->ctx->phpNamespace();
            $declared = array_map(static fn (TypeDecl $t): string => Names::mangle($t->name), $ns->types);
            if ($hasProgram && $phpNs === '') {
                $declared[] = Declarator::TOP_LEVEL_CLASS;
            }
            $this->imports = new Imports($phpNs, $declared);
            $this->w = new CodeWriter();
            $this->staticInitCalls = [];

            foreach ($ns->types as $i => $decl) {
                if ($i > 0) {
                    $this->w->blank();
                }
                $this->tolerating(fn () => $this->emitType($decl));
                $classes[] = $this->declarator->symbolOf($decl)->fqn;
            }
            if ($hasProgram && $phpNs === '') {
                $this->w->blank();
                $this->emitTopLevelProgram($unit);
                $classes[] = Declarator::TOP_LEVEL_CLASS;
                $hasProgram = false;
            }
            $this->appendStaticInitCalls();

            $blocks[] = [$phpNs, $this->imports, $this->w];
        }

        if ($hasProgram) {
            $this->ctx = $this->declarator->contextFor($unit, null);
            $this->imports = new Imports('', [Declarator::TOP_LEVEL_CLASS]);
            $this->w = new CodeWriter();
            $this->staticInitCalls = [];
            $this->emitTopLevelProgram($unit);
            $classes[] = Declarator::TOP_LEVEL_CLASS;
            $blocks[] = ['', $this->imports, $this->w];
        }

        return new GeneratedFile($unit->file->path, ...$this->assemble($blocks, $unit->file), classes: $classes);
    }

    /**
     * @param list<array{0: string, 1: Imports, 2: CodeWriter}> $blocks
     * @return array{code: string, lineMap: array<int, int>}
     */
    private function assemble(array $blocks, SourceFile $file): array
    {
        $out = new CodeWriter();
        $out->line('<?php');
        $out->line();
        $out->line('declare(strict_types=1);');
        $out->line();
        $out->line('// Generated by SharpPHP ' . self::VERSION . ' from ' . basename($file->path) . '. Do not edit.');
        $out->line();

        $bracketed = count($blocks) > 1;
        foreach ($blocks as $i => [$ns, $imports, $writer]) {
            if ($bracketed) {
                if ($i > 0) {
                    $out->line();
                }
                $out->line($ns === '' ? 'namespace {' : "namespace $ns {");
                $out->line();
            } elseif ($ns !== '') {
                $out->line("namespace $ns;");
                $out->line();
            }
            $uses = $imports->statements($writer->code());
            foreach ($uses as $use) {
                $out->line($use);
            }
            if ($uses !== []) {
                $out->line();
            }
            $out->append($writer);
            if ($bracketed) {
                $out->line();
                $out->line('}');
            }
        }

        return ['code' => $out->code(), 'lineMap' => $out->lineMap()];
    }

    private function appendStaticInitCalls(): void
    {
        if ($this->staticInitCalls === []) {
            return;
        }
        $this->w->sourceLine = 0;
        $this->w->line();
        foreach ($this->staticInitCalls as $call) {
            $this->w->line($call);
        }
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /** The method, accessor or lambda being emitted (statements only occur inside one). */
    private function currentFunction(): FunctionContext
    {
        return $this->fn ?? throw new \LogicException('Not emitting a function body');
    }

    /** The class being emitted (members only occur inside one). */
    private function currentClass(): TypeSymbol
    {
        return $this->class ?? throw new \LogicException('Not emitting a class');
    }

    private function classRef(string $fqn): string
    {
        // Every anonymous type is the one runtime class at run time.
        return $this->imports->name(Type::isAnonymousName($fqn) ? Type::ANONYMOUS_CLASS : $fqn);
    }

    /** Reference to a runtime helper class, e.g. runtime('Op') => Op (imported). */
    private function runtime(string $class): string
    {
        return $this->classRef(Names::RUNTIME_NAMESPACE . '\\' . $class);
    }

    private function typeDecl(Type $type): ?string
    {
        return $type->phpDeclaration(fn (string $fqn): string => $this->classRef($fqn));
    }

    private function resolveType(TypeRef $ref): Type
    {
        $type = $this->resolver->resolve($ref, $this->ctx, $this->typeParamsInScope());
        if ($this->model !== null) {
            $this->recordTypeRef($ref, $type);
        }

        return $type;
    }

    /** Analysis mode: the classes named in a type ("List<Person>": List and Person), for hover and go to definition. */
    private function recordTypeRef(TypeRef $ref, Type $type): void
    {
        if ($ref->name === '[]') {
            if (isset($ref->args[0])) {
                $this->recordTypeRef($ref->args[0], $type->element());
            }

            return;
        }
        $symbol = $type->kind === 'class' ? $this->symbolForType($type) : null;
        if ($symbol !== null) {
            $this->model?->reference($this->file, $ref, $ref->name, 'type', $type, symbol: $symbol);
        }
        foreach ($ref->args as $i => $arg) {
            if (isset($type->args[$i])) {
                $this->recordTypeRef($arg, $type->args[$i]);
            }
        }
    }

    /** @return array<string, Type> */
    private function typeParamsInScope(): array
    {
        return [...($this->class->typeParamTypes ?? []), ...($this->fn->typeParams ?? [])];
    }

    private function error(string $code, string $message, Node $at): CompileError
    {
        return CompileError::at($code, $message, $this->file, $at->line, $at->column);
    }

    /** A rule violation reported where the rule points, or else at $at. */
    private function violation(Violation $violation, Node $at): CompileError
    {
        return $this->error($violation->code, $violation->message, $violation->at ?? $at);
    }

    private function wrap(Emitted $e, int $minPrec): string
    {
        return $e->prec < $minPrec ? '(' . $e->code . ')' : $e->code;
    }

    /**
     * Runs $callback inside a new lexical scope.
     *
     * @template R
     * @param callable(): R $callback
     * @return R
     */
    private function inScope(callable $callback, ?LambdaContext $lambda = null): mixed
    {
        $outer = $this->scope;
        $this->scope = new Scope($outer, $lambda);
        try {
            return $callback();
        } finally {
            $this->scope = $outer;
        }
    }

    private function declareLocal(string $name, Type $type, Node $at, bool $assigned = true): void
    {
        if ($name === 'this') {
            throw $this->error('SP1001', "'this' cannot be used as a variable name", $at);
        }
        if ($this->scope->declaresLocally($name)) {
            throw $this->error('SP0128', "A local variable or function named '$name' is already defined in this scope", $at);
        }
        $this->scope->declare($name, $type);
        $this->flow->declare($this->scope, $name, $assigned);
        $this->model?->localDeclared($this->file, $this->scope->id, $name, $at);
    }

    /**
     * Runs $emit; in analysis mode an error in it is recorded and emission goes on with what follows
     * (the next statement or member), as an IDE needs. Otherwise the error stops the file.
     */
    private function tolerating(callable $emit): void
    {
        if ($this->model === null) {
            $emit();

            return;
        }
        try {
            $emit();
        } catch (CompileError $e) {
            $this->model->errors[] = $e->diagnostic;
        }
    }

    /** Analysis mode: what a member access's receiver is ("user" in user.Name), for completion after the dot. */
    private function recordReceiver(Node $memberName, Emitted $target): void
    {
        $this->model?->receiver(
            $this->file,
            $memberName,
            $target->kind,
            $target->type,
            $target->symbol ?? $this->symbolForType($target->type),
            $target->namespace,
            $this->class,
            $this->ctx,
        );
    }

    /** Analysis mode: what a name or member name refers to, for hover and go to definition. */
    private function recordReference(Node $at, string $name, Emitted $result): void
    {
        if ($this->model === null) {
            return;
        }
        $member = $result->member;
        if ($member !== null) {
            $this->model->reference($this->file, $at, $name, $member->kind, $member->type, $member);
        } elseif ($result->kind === 'type' && $result->symbol !== null) {
            $this->model->reference($this->file, $at, $name, 'type', $result->type, symbol: $result->symbol);
        } elseif ($result->kind === 'namespace') {
            $this->model->reference($this->file, $at, $name, 'namespace', Type::mixed());
        }
    }

    /** Definite assignment: "int x; Console.WriteLine(x);" does not compile in C#. */
    private function requireAssigned(string $name, Node $at): void
    {
        if ($this->flow->isUnassigned($this->scope, $name)) {
            throw $this->error('SP0165', "Use of unassigned local variable '$name'", $at);
        }
    }

    private function markAssigned(\SharpPhp\Syntax\Expr $target): void
    {
        if ($target instanceof \SharpPhp\Syntax\NameExpr) {
            $this->flow->assign($this->scope, $target->name);
        }
    }


    private static function phpString(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }

        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $c = $value[$i];
            $out .= match ($c) {
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\v" => '\\v',
                "\f" => '\\f',
                "\x1B" => '\\e',
                '\\' => '\\\\',
                '"' => '\\"',
                '$' => '\\$',
                default => ord($c) < 0x20 || ord($c) === 0x7f ? sprintf('\\x%02X', ord($c)) : $c,
            };
        }

        return '"' . $out . '"';
    }

    private static function phpFloat(float $value): string
    {
        if (is_nan($value)) {
            return '\\NAN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '\\INF' : '-\\INF';
        }
        $text = var_export($value, true);

        return str_contains($text, '.') || str_contains($text, 'E') || str_contains($text, 'e') ? $text : $text . '.0';
    }
}
