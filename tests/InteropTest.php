<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Project;
use SharpPhp\Runtime\Interop;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\Collections\Generic\Dictionary;
use SharpPhp\Runtime\System\Collections\Generic\List_;

/**
 * A project mixing PHP and SharpPHP: each side calls the other, and collections become PHP arrays
 * (and back) where they meet.
 */
final class InteropTest extends TestCase
{
    public function testPhpAndSharpPhpCallEachOther(): void
    {
        $root = $this->tempDir();
        $ns = 'InteropT' . bin2hex(random_bytes(4));
        mkdir("$root/src");
        file_put_contents("$root/src/Store.php", <<<PHP
            <?php
            namespace $ns;
            final class Store
            {
                /** @return array<string, int> */
                public static function stock(): array { return ['apple' => 3, 'pear' => 0, '7' => 1]; }
                /** @param array<int, string> \$names */
                public static function save(array \$names): string { return implode('|', \$names); }
            }
            PHP);
        file_put_contents("$root/src/Pricing.sphp", <<<CS
            using System.Collections.Generic;
            using System.Linq;

            namespace $ns;

            public record Item(string Name, double Price, int Quantity);

            public class Pricing
            {
                public static double Total(List<Item> items) => items.Sum(i => i.Price * i.Quantity);

                public static List<string> InStock(Dictionary<string, int> stock) => stock.Where(p => p.Value > 0).Select(p => p.Key).ToList();

                public static string FromPhp()
                {
                    Dictionary<string, int> stock = Store.stock();
                    return Store.save(InStock(stock)) + " " + stock.ContainsKey("7");
                }
            }
            CS);
        require "$root/src/Store.php";

        Project::fromDirectory($root)->autoload();

        $pricing = "$ns\\Pricing";
        $item = "$ns\\Item";
        // PHP passes plain arrays where SharpPHP takes List<Item> and Dictionary<string, int>.
        $this->assertSame(40.0, $pricing::Total([new $item('Book', 12.5, 2), new $item('Pen', 1.5, 10)]));
        $inStock = $pricing::InStock(['apple' => 3, 'pear' => 0, 'kiwi' => 5]);
        $this->assertInstanceOf(List_::class, $inStock);
        $this->assertSame('["apple","kiwi"]', json_encode($inStock), 'collections are JSON arrays for PHP');
        $this->assertSame('apple|7 True', $pricing::FromPhp(), 'SharpPHP calls PHP with and receives arrays');

        $this->expectException(ArgumentException::class);
        $pricing::Total([1, 2]); // not Items: rejected like in C#
    }

    /**
     * A SharpPHP class derived from a PHP class that works like Laravel's Model: untyped properties to
     * redeclare ($fillable), and names answered by __callStatic, __call and __get.
     */
    public function testClassDerivedFromAMagicPhpClass(): void
    {
        $root = $this->tempDir();
        $ns = 'InteropM' . bin2hex(random_bytes(4));
        mkdir("$root/src");
        file_put_contents("$root/src/Model.php", <<<PHP
            <?php
            namespace $ns;
            abstract class Model
            {
                protected \$fillable = [];
                protected \$table;
                protected static \$booted = false;
                /** @var array<string, mixed> */
                private array \$attributes = [];
                public static function __callStatic(string \$name, array \$args): mixed
                {
                    \$model = new static();
                    if (\$name === 'create') {
                        foreach (\$args[0] as \$key => \$value) {
                            if (in_array(\$key, \$model->fillable, true)) {
                                \$model->attributes[\$key] = \$value;
                            }
                        }
                        return \$model;
                    }
                    return \$name . ' on ' . \$model->table;
                }
                public function __call(string \$name, array \$args): mixed { return "\$name()"; }
                public function __get(string \$name): mixed { return \$this->attributes[\$name] ?? null; }
                public function __set(string \$name, mixed \$value): void { \$this->attributes[\$name] = \$value; }
            }
            PHP);
        file_put_contents("$root/src/Post.sphp", <<<CS
            using System.Collections.Generic;

            namespace $ns;

            public class Post : Model
            {
                protected string[] fillable = new[] { "title" };
                protected string table = "posts";

                public string Shout() => ((string)this.title).ToUpper() + "!";

                public static string Demo()
                {
                    var post = (Post)Post.create(new Dictionary<string, object> { ["title"] = "hello", ["secret"] = "no" });
                    post.views = 3;
                    return post.Shout() + " " + post.views + " " + post.secret + "|" + Post.where("x") + "|" + post.save();
                }
            }
            CS);
        require "$root/src/Model.php";

        Project::fromDirectory($root)->autoload();

        $post = "$ns\\Post";
        $this->assertSame('HELLO! 3 |where on posts|save()', $post::Demo(), 'static, instance and property magic; $fillable and $table redeclared');
        $code = (string) file_get_contents("$root/var/sharp/classes/Post.php");
        $this->assertStringContainsString('protected $fillable = [\'title\'];', $code, 'untyped, like the PHP property it redeclares');
        $this->assertStringContainsString('protected $table = \'posts\';', $code);
    }

    public function testRedeclaringAPhpPropertyFollowsPhpRules(): void
    {
        $root = $this->tempDir();
        $ns = 'InteropR' . bin2hex(random_bytes(4));
        mkdir("$root/src");
        file_put_contents("$root/src/Base.php", "<?php\nnamespace $ns;\nclass Base { protected \$name; public \$size = 0; protected static \$count = 0; }\n");
        require "$root/src/Base.php";

        $private = $this->compileError("namespace $ns;\nclass Derived : Base { private string name = \"x\"; }\nclass P { static void Main() {} }");
        $this->assertSame('SP0507', $private->code);
        $this->assertSame("'$ns.Derived.name': cannot change access modifiers of the inherited 'protected' member '$ns.Base.name'", $private->message);

        $static = $this->compileError("namespace $ns;\nclass Derived : Base { protected int count = 1; }\nclass P { static void Main() {} }");
        $this->assertSame('SP0108', $static->code);

        $narrower = $this->compileError("namespace $ns;\nclass Derived : Base { protected int size = 1; }\nclass P { static void Main() {} }");
        $this->assertSame('SP0507', $narrower->code, 'public in PHP, protected here');

        $code = $this->compile("namespace $ns;\nclass Derived : Base { public int size = 5; protected string name = \"x\"; }\nclass P { static void Main() {} }");
        $this->assertStringContainsString('public $size = 5;', $code);
        $this->assertStringContainsString('protected $name = \'x\';', $code);
    }

    public function testConversions(): void
    {
        $dictionary = Interop::toDictionary(['7' => 'x', 'a' => 'y'], ['string', 'string']);
        $this->assertInstanceOf(Dictionary::class, $dictionary);
        $this->assertTrue($dictionary->ContainsKey('7'), 'PHP\'s integer key 7 is the string "7" again');
        $this->assertSame(['7' => 'x', 'a' => 'y'], Interop::toArray($dictionary));

        $list = Interop::toList([5 => 'b', 9 => 'c']);
        $this->assertSame(['b', 'c'], Interop::toArray($list), 'keys are dropped');
        $this->assertNull(Interop::toList(null));
        $this->assertSame(['a'], Interop::toArray(['a']), 'arrays pass through');
        $this->assertSame('{"a":1}', json_encode(new Dictionary(['a' => 1])));
    }
}
