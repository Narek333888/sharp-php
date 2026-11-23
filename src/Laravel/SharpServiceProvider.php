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
        // After every provider has booted, so that listeners the app registers itself are known.
        if (method_exists($this->app, 'booted')) { // @phpstan-ignore function.alreadyNarrowedType (a bare Container in tests)
            $this->app->booted(fn () => $this->registerListeners());
        } else {
            $this->registerListeners();
        }
    }

    /**
     * Event listeners written in SharpPHP (app/Listeners/*.sphp): Laravel discovers listeners by their
     * .php files, so the .sphp ones are registered here as its discovery would: each public handle...()
     * or __invoke() method listens to the event class of its first parameter. A listener the app
     * registered itself (Event::listen) is not added a second time.
     */
    private function registerListeners(): void
    {
        if (!$this->app->bound('events')) {
            return;
        }
        $events = $this->app->make('events');
        if (!$events instanceof \Illuminate\Events\Dispatcher) {
            return;
        }
        foreach ($this->app->make(Project::class)->loadedClasses() as $class => $source) {
            if (!str_contains(str_replace('\\', '/', $source), '/Listeners/') || !class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                continue;
            }
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || !(str_starts_with($method->getName(), 'handle') || $method->getName() === '__invoke')) {
                    continue;
                }
                $type = ($method->getParameters()[0] ?? null)?->getType();
                $eventTypes = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
                foreach ($eventTypes as $eventType) {
                    if (!$eventType instanceof \ReflectionNamedType || $eventType->isBuiltin()) {
                        continue;
                    }
                    $listener = [$reflection->getName(), $method->getName()];
                    $registered = (array) ($events->getRawListeners()[$eventType->getName()] ?? []);
                    $known = array_filter($registered, static fn (mixed $l): bool => $l === $listener
                        || $l === $listener[0] . '@' . $listener[1]
                        || ($l === $listener[0] && $listener[1] === 'handle'));
                    if ($known === []) {
                        $events->listen($eventType->getName(), $listener);
                    }
                }
            }
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
