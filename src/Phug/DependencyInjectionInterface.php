<?php

namespace Phug;

use Closure;
use Phug\DependencyInjection\Dependency;

interface DependencyInjectionInterface
{
    public function import($name);

    public function setAsRequired($name);

    public function dumpDependency($name, $storageVariable);

    public function export($storageVariable);

    public function provider($name, $provider);

    public function register($name, $value);

    public function getProvider($name);

    public function get($name);

    public function set($name, Dependency $dependency);

    public function call($name);
}