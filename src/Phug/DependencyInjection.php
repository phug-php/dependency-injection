<?php

namespace Phug;

use Closure;
use Phug\DependencyInjection\Dependency;
use Phug\DependencyInjection\FunctionWrapper;
use Phug\DependencyInjection\Requirement;

class DependencyInjection implements DependencyInjectionInterface
{
    /**
     * @var array[Requirement]
     */
    private $dependencies = [];

    /**
     * @var array
     */
    private $cache = [];

    /**
     * @param $name
     *
     * @return mixed
     */
    public function import($name)
    {
        return $this->setAsRequired($name)->get($name);
    }

    /**
     * @param $name
     *
     * @throws DependencyException
     *
     * @return $this
     */
    public function setAsRequired($name)
    {
        $provider = $this->getProvider($name)
            ->setRequired(true)
            ->getDependency();

        $lastRequired = null;

        try {
            foreach ($provider->getDependencies() as $dependencyName) {
                $lastRequired = $dependencyName;
                $this->setAsRequired($dependencyName);
            }
        } catch (DependencyException $e) {
            throw new DependencyException(
                $e->getCode() === 1
                    ? 'Dependency not found: '.$lastRequired.' < '.$name
                    : $e->getMessage().' < '.$name,
                2
            );
        }

        return $this;
    }

    /**
     * @param string $storageVariable
     *
     * @return string
     */
    public function dumpDependency($name, $storageVariable = null)
    {
        $value = $this->get($name);

        if (!($value instanceof Closure)) {
            return var_export($value, true);
        }

        $function = new FunctionWrapper($value);
        $code = 'function '.$function->dumpParameters();
        $code .= ($storageVariable ? ' use (&$'.$storageVariable.')' : '').' {'.PHP_EOL;
        if ($storageVariable) {
            $dependencies = $this->getProvider($name)
                ->getDependency()
                ->getDependencies();
            foreach (array_keys($function->getStaticVariables()) as $index => $use) {
                $code .= '    $'.$use.' = $'.$storageVariable.'['.var_export($dependencies[$index], true).'];'.PHP_EOL;
            }
        }
        $code .= $function->dumpBody();

        return $code;
    }

    /**
     * @param $storageVariable
     *
     * @return string
     */
    public function export($storageVariable)
    {
        $code = '$'.$storageVariable.' = ['.PHP_EOL;
        foreach ($this->dependencies as $requirement) {
            /**
             * @var Requirement $requirement
             */
            if ($requirement->isRequired()) {
                $dependencyName = $requirement->getDependency()->getName();
                $code .= '  '.var_export($dependencyName, true).
                    ' => '.
                    $this->dumpDependency($dependencyName, $storageVariable).
                    ','.PHP_EOL;
            }
        }
        $code .= '];'.PHP_EOL;

        return $code;
    }

    /**
     * @param string         $name
     * @param array|callable $provider
     *
     * @throws DependencyException
     *
     * @return DependencyInjection
     */
    public function provider($name, $provider)
    {
        if (!is_array($provider) && is_callable($provider)) {
            $provider = [$provider];
        }
        if (!is_array($provider)) {
            throw new DependencyException(
                'Invalid provider passed to '.$name.', '.
                'it must be an array or a callable function.'
            );
        }

        $dependencies = $provider;
        $value = array_pop($dependencies);

        return $this->set($name, new Dependency($value, $name, $dependencies));
    }

    /**
     * @param string $name
     * @param mixed  $value
     *
     * @return DependencyInjection
     */
    public function register($name, $value)
    {
        return $this->provider($name, [function () use (&$value) {
            return $value;
        }]);
    }

    /**
     * @param string $name
     *
     * @throws DependencyException
     *
     * @return Requirement
     */
    public function getProvider($name)
    {
        if (!isset($this->dependencies[$name])) {
            throw new DependencyException(
                $name.' dependency not found.',
                1
            );
        }

        return $this->dependencies[$name];
    }

    /**
     * @param string     $name
     * @param Dependency $dependency
     *
     * @return $this
     */
    public function set($name, Dependency $dependency)
    {
        $this->dependencies[$name] = new Requirement($dependency);

        return $this;
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function get($name)
    {
        $dependency = $this->getProvider($name)->getDependency();
        $value = $dependency->getValue();
        if (!($value instanceof Closure)) {
            return $value;
        }

        $cacheKey = spl_object_hash($value).'_'.$name;

        if (!isset($this->cache[$cacheKey])) {
            $arguments = array_map(function ($dependencyName) {
                return $this->get($dependencyName);
            }, $dependency->getDependencies());

            $this->cache[$cacheKey] = call_user_func_array($value, $arguments);
        }

        return $this->cache[$cacheKey];
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function call($name)
    {
        return call_user_func_array(
            $this->get($name),
            array_slice(func_get_args(), 1)
        );
    }
}
