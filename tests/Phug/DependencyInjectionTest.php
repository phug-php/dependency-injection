<?php


namespace Phug\Test;

use Phug\DependencyInjection;

class DependencyInjectionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @covers \Phug\DependencyInjection::<public>
     * @covers \Phug\DependencyInjection\Dependency::<public>
     * @covers \Phug\DependencyInjection\Requirement::<public>
     */
    public function testGetProvider()
    {
        $injector = new DependencyInjection();
        $injector->register('foo', function ($value) {
            return strtoupper($value);
        });
        $injector->provider('bar', ['foo', function ($foo) {
            return function ($start, $end) use ($foo) {
                return $foo($start).$end;
            };
        }]);

        self::assertSame('ABcd', $injector->call('bar', 'ab', 'cd'));
    }

    /**
     * @covers \Phug\DependencyInjection::<public>
     * @covers \Phug\DependencyInjection\Dependency::<public>
     * @covers \Phug\DependencyInjection\Requirement::<public>
     */
    public function testExport()
    {
        $a = new DependencyInjection();
        $a->provider('a', ['b', 'c', function ($b, $c) {
            return function ($n) use ($b, $c) {
                return $n + $b() + $c;
            };
        }]);
        $a->provider('b', ['d', 'c', function ($d, $c) {
            return function () use ($d, $c) {
                return $d + $c;
            };
        }]);
        $a->register('c', 1);
        $a->register('d', 2);

        self::assertSame(7, $a->call('a', 3));

        $a->setAsRequired('a');

        $expected = [
            '$module = [',
            "  'a' => function (\$n) use (&\$module) {",
            "    \$b = \$module['b'];",
            "    \$c = \$module['c'];",
            "    return \$n + \$b() + \$c;",
            "  },",
            "  'b' => function () use (&\$module) {",
            "    \$d = \$module['d'];",
            "    \$c = \$module['c'];",
            "    return \$d + \$c;",
            "  },",
            "  'c' => 1,",
            "  'd' => 2,",
            '];',
        ];
        $expected = array_filter(array_map(function ($line) {
            return ltrim($line);
        }, $expected));
        $expected = implode(PHP_EOL, $expected);

        $export = $a->export('module');
        $actual = explode(PHP_EOL, $export);
        $actual = array_filter(array_map(function ($line) {
            return ltrim($line);
        }, $actual));
        $actual = implode(PHP_EOL, $actual);

        self::assertSame($expected, $actual);
        self::assertSame(7, eval($export.'return $module["a"](3);'));
    }
}
