<?php

declare(strict_types=1);

namespace SharpPhp\Laravel;

use Illuminate\Support\ServiceProvider;
use SharpPhp\Compiler\CompilationFailed;
use SharpPhp\Project;

/**
 * Laravel integration, registered automatically (package discovery): classes written in SharpPHP
 * (.sphp files under app/ by default) are compiled and loadable like the app's PHP classes, so
 * routes, the container and PHP code use them as they are:
 *
 *   // app/Http/Controllers/HelloController.sphp
 *   public class HelloController : Controller
 *   {
 *       public string Show(string name) => $"Hello, {name}!";
 *   }
 *
 *   // routes/web.php
 *   Route::get('/hello/{name}', [HelloController::class, 'Show']);
 *
 * In development the sources are recompiled when they change. In production ("APP_ENV=production")
 * the existing build is used without looking at the sources: run "php artisan sharp:build" on deploy.
 */
final class SharpServiceProvider extends ServiceProvider
{
    /** Where .sphp files are when composer.json has no "extra.sharp" (next to the app's PHP classes). */
    public const DEFAULT_SOURCE = Project::LARAVEL_SOURCE;

    /** Where the compiled PHP goes when composer.json has no "extra.sharp". */
    public const DEFAULT_OUTPUT = Project::LARAVEL_OUTPUT;

    public function register(): void
    {
        $this->app->singleton(Project::class, fn (): Project => self::project($this->basePath()));
        $production = $this->app->bound('env') && $this->app->make('env') === 'production';
        try {
            $this->app->make(Project::class)->autoload(checkSources: !$production);
        } catch (CompilationFailed $e) {
            // A web request shows the errors (Laravel's error page). Artisan must keep working, so that
            // "php artisan sharp:build" can report them and other commands are not blocked.
            if (!$this->app->runningInConsole()) {
                throw CompilationException::from($e, $this->basePath());
            }
            fwrite(STDERR, 'SharpPHP: the .sphp sources do not compile (' . count($e->diagnostics) . ' error(s)); run "php artisan sharp:build" to see them.' . PHP_EOL);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([BuildCommand::class, ...$this->sharpCommands()]);
        }
    }

    /**
     * Artisan commands written in SharpPHP: Laravel finds the app's commands by their .php files in
     * app/Console/Commands, which .sphp ones are not, so they are registered here.
     *
     * @return list<class-string>
     */
    private function sharpCommands(): array
    {
        $commands = [];
        foreach ($this->app->make(Project::class)->loadedClasses() as $class => $source) {
            if (str_contains(str_replace('\\', '/', $source), '/Console/Commands/')
                && class_exists($class) && is_subclass_of($class, \Illuminate\Console\Command::class)) {
                $commands[] = (new \ReflectionClass($class))->getName();
            }
        }

        return $commands;
    }

    public static function project(string $basePath): Project
    {
        return Project::fromDirectory($basePath, self::DEFAULT_SOURCE, self::DEFAULT_OUTPUT);
    }

    private function basePath(): string
    {
        return $this->app->basePath();
    }
}
