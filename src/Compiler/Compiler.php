<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SharpPhp\CodeGen\Emitter;
use SharpPhp\Parser\Parser;
use SharpPhp\Semantics\SymbolTable;
use SharpPhp\Semantics\TypeResolver;
use SharpPhp\Syntax\CompilationUnit;

/**
 * Compiles a set of SharpPHP files as one project.
 *
 * Phases: parse every file -> register all type names -> resolve members -> verify -> emit PHP.
 * Errors are collected per file so one broken file does not hide problems in others.
 */
final class Compiler
{
    public const EXTENSION = 'sphp';

    public function compileDirectory(string $directory): CompilationResult
    {
        return $this->compile(self::findSources($directory));
    }

    public function compileString(string $code, string $path = 'Program.sphp'): CompilationResult
    {
        return $this->compile([new SourceFile($path, $code)]);
    }

    /**
     * @param list<SourceFile> $files
     * @throws CompilationFailed
     */
    public function compile(array $files): CompilationResult
    {
        $symbols = new SymbolTable();
        $resolver = new TypeResolver($symbols);
        $declarator = new Declarator($symbols, $resolver);
        $diagnostics = [];

        /** @var list<CompilationUnit> $units */
        $units = [];
        foreach ($files as $file) {
            try {
                $units[] = Parser::parseFile($file);
            } catch (CompileError $e) {
                $diagnostics[] = $e->diagnostic;
            }
        }

        $units = $this->each($units, $diagnostics, $declarator->register(...));
        $units = $this->each($units, $diagnostics, $declarator->define(...));
        $verifier = new ImplementationVerifier($symbols, $declarator);
        $units = $this->each($units, $diagnostics, $verifier->verify(...));

        $entryPoints = $declarator->entryPoints();
        if (count($entryPoints) > 1) {
            $names = implode(', ', array_map(static fn (array $e): string => $e['type']->csharpName() . '.Main()', $entryPoints));
            foreach ($entryPoints as $entry) {
                $diagnostics[] = new Diagnostic('SP0017', "Program has more than one entry point defined: $names", $entry['file'], $entry['node']->line, $entry['node']->column);
            }
        }

        $generated = [];
        foreach ($units as $unit) {
            try {
                $generated[] = (new Emitter($symbols, $resolver, $declarator))->emit($unit);
            } catch (CompileError $e) {
                $diagnostics[] = $e->diagnostic;
            }
        }

        if ($diagnostics !== []) {
            throw new CompilationFailed($diagnostics);
        }

        return new CompilationResult($generated, $entryPoints !== [] ? $entryPoints[0]['type']->fqn : null);
    }

    /**
     * Analysis for editors: compiles like compile() without producing code, records what names mean
     * in $model, and goes on after errors (inside a file too) instead of stopping.
     *
     * @param list<SourceFile> $files
     * @return array{symbols: SymbolTable, diagnostics: list<Diagnostic>}
     */
    public function analyze(array $files, SemanticModel $model): array
    {
        $symbols = new SymbolTable();
        $resolver = new TypeResolver($symbols);
        $declarator = new Declarator($symbols, $resolver);
        $diagnostics = [];

        $units = [];
        foreach ($files as $file) {
            try {
                $units[] = Parser::parseFile($file);
            } catch (CompileError $e) {
                $diagnostics[] = $e->diagnostic;
            }
        }
        $units = $this->each($units, $diagnostics, $declarator->register(...));
        $units = $this->each($units, $diagnostics, $declarator->define(...));
        foreach ($units as $unit) {
            try {
                (new Emitter($symbols, $resolver, $declarator, $model))->emit($unit);
            } catch (CompileError $e) {
                $diagnostics[] = $e->diagnostic;
            }
        }

        return ['symbols' => $symbols, 'diagnostics' => [...$diagnostics, ...$model->errors]];
    }

    /**
     * Runs $phase on every unit; units that fail are dropped from later phases.
     *
     * @param list<CompilationUnit> $units
     * @param list<Diagnostic> $diagnostics
     * @return list<CompilationUnit>
     */
    private function each(array $units, array &$diagnostics, callable $phase): array
    {
        $ok = [];
        foreach ($units as $unit) {
            try {
                $phase($unit);
                $ok[] = $unit;
            } catch (CompileError $e) {
                $diagnostics[] = $e->diagnostic;
            }
        }

        return $ok;
    }

    /** @return list<SourceFile> */
    public static function findSources(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === self::EXTENSION) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);

        return array_map(static fn (string $p): SourceFile => SourceFile::fromPath($p), $paths);
    }
}
