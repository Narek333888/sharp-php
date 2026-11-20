<?php

declare(strict_types=1);

namespace SharpPhp\Laravel;

use Illuminate\Console\Command;
use SharpPhp\Compiler\CompilationFailed;
use Symfony\Component\Console\Output\OutputInterface;
use SharpPhp\Project;

/**
 * php artisan sharp:build — compiles the app's .sphp files (run it on deploy; production uses
 * the build without checking the sources).
 */
final class BuildCommand extends Command
{
    /** @var string */
    protected $signature = 'sharp:build {--force : Rebuild even when nothing changed}';

    /** @var string */
    protected $description = 'Compile the SharpPHP (.sphp) sources of the application';

    public function handle(Project $project): int
    {
        $started = microtime(true);
        try {
            $manifest = $project->build((bool) $this->option('force'));
        } catch (CompilationFailed $e) {
            // Raw: the code frames' indentation matters.
            $this->output->writeln($e->render($project->root), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
        $this->info(sprintf(
            'SharpPHP: %d file(s) compiled to %s (%.2fs)',
            count($manifest['sourcemap']),
            $project->buildDir,
            microtime(true) - $started,
        ));

        return self::SUCCESS;
    }
}
