<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use Illuminate\Container\Container;
use SharpPhp\Laravel\BuildCommand;
use SharpPhp\Laravel\SharpServiceProvider;
use SharpPhp\Paths;
use SharpPhp\Project;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The Laravel service provider and the sharp:build command, on a container standing in for the app.
 */
final class LaravelTest extends TestCase
{
    private string $root;
    private string $ns;

    protected function setUp(): void
    {
        $this->root = $this->tempDir();
        $this->ns = 'LaravelT' . bin2hex(random_bytes(4));
        mkdir("$this->root/app/Services", 0777, true);
        file_put_contents("$this->root/app/Services/Greeter.sphp", "namespace {$this->ns}.Services;\n\npublic class Greeter\n{\n    public string Greet(string name) => \$\"Hello, {name}!\";\n}\n");
    }

    public function testClassesUnderAppAreLoadable(): void
    {
        $app = $this->app('local');
        (new SharpServiceProvider($app))->register();

        $class = "{$this->ns}\\Services\\Greeter";
        $this->assertSame('Hello, Ann!', (new $class())->Greet('Ann'));
        $this->assertFileExists("$this->root/storage/framework/sharp/manifest.php", 'built into storage/framework/sharp by default');
        $this->assertSame(Paths::normalize((string) realpath($this->root)) . '/app', $app->make(Project::class)->sourceDir);
    }

    public function testProductionUsesTheBuildWithoutCheckingSources(): void
    {
        SharpServiceProvider::project($this->root)->build();
        // A broken source would fail a build; production does not look at the sources.
        file_put_contents("$this->root/app/Services/Broken.sphp", 'class Broken { int x = "text"; }');

        (new SharpServiceProvider($this->app('production')))->register();

        $this->assertTrue(class_exists("{$this->ns}\\Services\\Greeter"));
    }

    public function testCompileErrorsInAWebRequestAndInArtisan(): void
    {
        file_put_contents("$this->root/app/Services/Broken.sphp", "namespace {$this->ns}.Services;\nclass Broken { string Name() => 42; }\n");

        // Artisan keeps working (sharp:build reports the errors).
        (new SharpServiceProvider($this->app('local', console: true)))->register();

        // A web request fails with an exception Laravel renders as its 500 error page.
        try {
            (new SharpServiceProvider($this->app('local', console: false)))->register();
            self::fail('Expected a compilation error');
        } catch (\SharpPhp\Laravel\CompilationException $e) {
            $this->assertStringContainsString("error SP0029: Cannot implicitly convert type 'int' to 'string'", $e->getMessage());
            $this->assertFalse(method_exists($e, 'render'), 'Laravel would call render() to make the response');
        }
    }

    public function testFacadesAndHelpers(): void
    {
        $code = $this->compile(<<<'CS'
            using Illuminate.Support.Facades;
            class P
            {
                public static string Main()
                {
                    var name = Config.get("app.name");
                    Log.info("x");
                    Route.get("/", () => "home");
                    return date("Y") + Date.now() + name;
                }
            }
            CS);

        $this->assertStringContainsString("Config::get('app.name')", $code);
        $this->assertStringContainsString("Log::info('x')", $code);
        $this->assertStringContainsString("Route::get('/', fn () => 'home')", $code);
        $this->assertStringContainsString("\\date('Y')", $code, 'date() is the function, not the Date facade');
        $this->assertStringContainsString('Date::now()', $code);
    }

    public function testBuildCommand(): void
    {
        $app = $this->app('local');
        $app->singleton(Project::class, fn (): Project => SharpServiceProvider::project($this->root));
        $command = new BuildCommand();
        $command->setLaravel($app);
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput([]), $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit, $text);
        $this->assertStringContainsString('SharpPHP: 1 file(s) compiled', $text);
        $this->assertFileExists("$this->root/storage/framework/sharp/manifest.php");
    }

    public function testArtisanCommandsWrittenInSharpPhpAreRegistered(): void
    {
        mkdir("$this->root/app/Console/Commands", 0777, true);
        file_put_contents("$this->root/app/Console/Commands/Greet.sphp", "using Illuminate.Console;\n\nnamespace {$this->ns}.Console.Commands;\n\npublic class Greet : Command\n{\n    protected string signature = \"greet {name}\";\n    protected string description = \"Says hello\";\n\n    public void handle() => this.info(\$\"Hello, {this.argument(\"name\")}!\");\n}\n");
        $app = $this->app('local', console: true);
        $provider = new SharpServiceProvider($app);
        $provider->register();
        \Illuminate\Console\Application::forgetBootstrappers();
        $provider->boot();

        $artisan = new \Illuminate\Console\Application($app, new \Illuminate\Events\Dispatcher($app), 'test');
        $this->assertTrue($artisan->has('greet'), 'found without a .php file in app/Console/Commands');
        $this->assertTrue($artisan->has('sharp:build'));
        $this->assertFalse($artisan->has('services:greeter'), 'only classes under Console/Commands that are commands');

        $output = new BufferedOutput();
        $this->assertSame(0, $artisan->find('greet')->run(new ArrayInput(['name' => 'Ann']), $output));
        $this->assertSame("Hello, Ann!\n", $output->fetch());
    }

    public function testEventListenersWrittenInSharpPhpAreRegistered(): void
    {
        mkdir("$this->root/app/Events", 0777, true);
        mkdir("$this->root/app/Listeners", 0777, true);
        file_put_contents("$this->root/app/Events/Greeted.sphp", "namespace {$this->ns}.Events;\n\npublic class Greeted\n{\n    public string Name { get; }\n    public Greeted(string name) => Name = name;\n}\n");
        file_put_contents("$this->root/app/Listeners/RememberGreeting.sphp", "using {$this->ns}.Events;\n\nnamespace {$this->ns}.Listeners;\n\npublic class RememberGreeting\n{\n    public static List<string> Seen = new();\n    public void handle(Greeted e) => Seen.Add(e.Name);\n}\n");
        $app = $this->app('local', console: false);
        $events = new \Illuminate\Events\Dispatcher($app);
        $app->instance('events', $events);
        $provider = new SharpServiceProvider($app);
        $provider->register();
        $listener = "{$this->ns}\\Listeners\\RememberGreeting";
        $event = "{$this->ns}\\Events\\Greeted";
        $events->listen($event, $listener); // registered by the app as well: must not run twice
        $provider->boot();

        $events->dispatch(new $event('Ann'));
        $this->assertSame(['Ann'], iterator_to_array($listener::$Seen, false), 'found without a .php file in app/Listeners, called once');
        $this->assertCount(1, $events->getRawListeners()[$event]);
    }

    private function app(string $env, bool $console = true): Container
    {
        $root = $this->root;
        $app = new class ($root, $console) extends Container {
            public function __construct(private readonly string $root, private readonly bool $console)
            {
            }

            public function basePath(string $path = ''): string
            {
                return $this->root . ($path !== '' ? '/' . $path : '');
            }

            public function runningInConsole(): bool
            {
                return $this->console;
            }

            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $app->instance('env', $env);
        Container::setInstance($app);

        return $app;
    }

    public function testClassLookupWithACaseSensitiveAutoloader(): void
    {
        // PSR-4 autoloaders on Linux find a class only under its exact name: looking up "date" first
        // must not hide the class Date.
        $ns = 'CaseT' . bin2hex(random_bytes(4));
        $file = $this->tempDir() . '/Date.php';
        file_put_contents($file, "<?php namespace $ns\\Facades; class Date { public static function now(): string { return 'now'; } }");
        $loader = static function (string $class) use ($ns, $file): void {
            if ($class === "$ns\\Facades\\Date") {
                require $file;
            }
        };
        spl_autoload_register($loader);
        try {
            $code = $this->compile("using $ns.Facades;\nclass P { public static string M() => date(\"Y\") + Date.now(); }");
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertStringContainsString("\\date('Y') . Date::now()", $code);
    }
}
