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
