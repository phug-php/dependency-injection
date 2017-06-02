<?php

namespace Phug;

use Closure;
use Phug\DependencyInjection\Dependency;
use Phug\DependencyInjection\FunctionWrapper;
use Phug\DependencyInjection\Requirement;
use ReflectionParameter;

class DependencyInjection implements DependencyInjectionInterface
{
    /**
     * @var array[Requirement]
     */
    private $dependencies = [];

    /**
     * @var array
     */
    private $dependenciesParams = [];

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
        return $this->setAsRequired($name)
            ->get($name);
    }

    /**
     * @param $name
     *
     * @return mixed
     */
    public function importDependency($name)
    {
        return $this->getProvider($name)
            ->setRequired(true)
            ->getDependency();
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
        $provider = $this->importDependency($name);
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
     * @param string $dependency dependency name
     *
     * @return string
     */
    public function getStorageItem($name, $storageVariable)
    {
        return '$'.$storageVariable.'['.var_export($name, true).']';
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
            foreach (array_keys($function->getStaticVariables()) as $use) {
                $index = array_search($use, $this->dependenciesParams[$name]);
                $dependency = $this->getStorageItem($dependencies[$index], $storageVariable);
                $code .= '    $'.$use.' = '.$dependency.';'.PHP_EOL;
            }
        }
        $code .= $function->dumpBody();

        return $code;
    }

    /**
     * @return int
     */
    public function countRequiredDependencies()
    {
        return array_sum(array_map(function (Requirement $requirement) {
            return $requirement->isRequired() ? 1 : 0;
        }, $this->dependencies));
    }

    /**
     * Return the state of each requirement as an array where the key is the
     * requirement name and the value is true if it's already required,
     * false else.
     *
     * @return array
     */
    public function getRequirementsStates()
    {
        return array_map(function (Requirement $requirement) {
            return $requirement->isRequired();
        }, $this->dependencies);
    }

    /**
     * @param $storageVariable
     *
     * @return string
     */
    public function export($storageVariable)
    {
        return '$'.$storageVariable.' = ['.PHP_EOL.
            implode('', array_map(function (Requirement $requirement) use ($storageVariable) {
                if ($requirement->isRequired()) {
                    $dependencyName = $requirement->getDependency()->getName();

                    return '  '.var_export($dependencyName, true).
                        ' => '.
                        $this->dumpDependency($dependencyName, $storageVariable).
                        ','.PHP_EOL;
                }

                return '';
            }, $this->dependencies)).
            '];'.PHP_EOL;
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
     * @return bool
     */
    public function has($name)
    {
        return isset($this->dependencies[$name]);
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
        if (!$this->has($name)) {
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
        $required = isset($this->dependencies[$name]) && $this->dependencies[$name]->isRequired();
        $this->dependencies[$name] = new Requirement($dependency, $required);

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

        $function = new FunctionWrapper($value);
        $this->dependenciesParams[$name] = array_map(function (ReflectionParameter $param) {
            return $param->name;
        }, $function->getParameters());

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
