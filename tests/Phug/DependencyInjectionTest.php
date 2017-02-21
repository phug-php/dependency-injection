<?php

namespace Phug\Test;

use Phug\DependencyInjection;
use Phug\Util\UnorderedArguments;

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
    public function testProvider()
    {
        $injector = new DependencyInjection();
        $injector->provider('escape', 'htmlspecialchars');
        $injector->register('upper', 'strtoupper');

        self::assertSame('&LT;', $injector->call('upper', $injector->call('escape', '<')));
    }

    /**
     * @covers \Phug\DependencyInjection::import
     */
    public function testImport()
    {
        $injector = new DependencyInjection();
        $injector->register('answer', 42);
        self::assertFalse($injector->getProvider('answer')->isRequired());
        self::assertSame(42, $injector->import('answer'));
        self::assertTrue($injector->getProvider('answer')->isRequired());
    }

    /**
     * @covers                   \Phug\DependencyInjection::setAsRequired
     * @expectedException        \Phug\DependencyException
     * @expectedExceptionCode    2
     * @expectedExceptionMessage Dependency not found: baz < bar < foo
     */
    public function testRequiredFailure()
    {
        $injector = new DependencyInjection();
        $injector->provider('bar', ['baz', 1]);
        $injector->provider('foo', ['bar', 2]);
        $injector->setAsRequired('foo');
    }

    /**
     * @covers                   \Phug\DependencyInjection::getProvider
     * @expectedException        \Phug\DependencyException
     * @expectedExceptionCode    1
     * @expectedExceptionMessage foobar dependency not found.
     */
    public function testGetProviderException()
    {
        $injector = new DependencyInjection();
        $injector->getProvider('foobar');
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
            '    return $n + $b() + $c;',
            '  },',
            "  'b' => function () use (&\$module) {",
            "    \$d = \$module['d'];",
            "    \$c = \$module['c'];",
            '    return $d + $c;',
            '  },',
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

        self::assertSame(3, $a->call('b'));

        $a->setAsRequired('b');

        $expected = [
            '$module = [',
            "  'b' => function () use (&\$module) {",
            "    \$d = \$module['d'];",
            "    \$c = \$module['c'];",
            '    return $d + $c;',
            '  },',
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
        self::assertSame(3, eval($export.'return $module["b"]();'));
    }

    /**
     * @covers \Phug\DependencyInjection::dumpDependency
     */
    public function testDumpDependency()
    {
        $a = new DependencyInjection();
        $a->provider('a', function () {
            return function (array $array, UnorderedArguments $args) {
                return $args->required($array[0]);
            };
        });
        $a->setAsRequired('a');

        $expected = [
            '$module = [',
            "  'a' => function (array \$array, Phug\\Util\\UnorderedArguments \$args) use (&\$module) {",
            '    return $args->required($array[0]);',
            '  },',
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
        self::assertSame(true, eval($export.'return $module["a"](["boolean"], new \\Phug\\Util\\UnorderedArguments([true]));'));

        $a = new DependencyInjection();
        $a->provider('a', function () {
            return function (&$pass = null) {
                $pass = 42;
            };
        });
        $a->setAsRequired('a');

        $expected = [
            '$module = [',
            "  'a' => function (&\$pass = NULL) use (&\$module) {",
            '    $pass = 42;',
            '  },',
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
        self::assertSame(42, eval($export.'$module["a"]($box); return $box;'));
    }

    /**
     * @covers                   \Phug\DependencyInjection::provider
     * @expectedException        \Phug\DependencyException
     * @expectedExceptionMessage Invalid provider passed to foobar,
     * @expectedExceptionMessage it must be an array or a callable function.
     */
    public function testProviderException()
    {
        $a = new DependencyInjection();
        $a->provider('foobar', '-');
    }
}
