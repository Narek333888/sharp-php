<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use Illuminate\Container\Container;
use SharpPhp\Laravel\BuildCommand;
use SharpPhp\Laravel\SharpServiceProvider;
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
        $this->assertSame(realpath($this->root) . "/app", $app->make(Project::class)->sourceDir);
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
}
