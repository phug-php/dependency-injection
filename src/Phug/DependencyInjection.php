<?php

namespace Phug;

use Closure;
use Phug\DependencyInjection\Dependency;
use Phug\DependencyInjection\Requirement;
use ReflectionFunction;

class DependencyInjection implements DependencyInjectionInterface
{
    /**
     * @var array[Requirement]
     */
    private $dependencies = [];

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
     * @return $this
     *
     * @throws DependencyException
     */
    public function setAsRequired($name)
    {
        $requirement = $this->getProvider($name);

        $requirement->setRequired(true);

        $provider = $requirement->getDependency();

        try {
            foreach ($provider->getDependencies() as $dependencyName) {
                try {
                    $this->setAsRequired($dependencyName);
                } catch (DependencyException $e) {
                    if ($e->getCode() !== 1) {
                        throw $e;
                    }

                    if ($e->getCode() === 2) {
                        throw new DependencyException(
                            $e->getMessage().
                            ' < '.$dependencyName,
                            2
                        );
                    }

                    throw new DependencyException($dependencyName, 2);
                }
            }
        } catch (DependencyException $e) {
            if ($e->getCode() !== 2) {
                throw $e;
            }

            throw new DependencyException(
                'Dependency not found: '.$e->getMessage()
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

        $code = 'function (';
        $function = new ReflectionFunction($value);
        $parameters = [];
        foreach ($function->getParameters() as $parameter) {
            $string = '';
            if ($parameter->isArray()) {
                $string .= 'array ';
            } else if ($parameter->getClass()) {
                $string .= $parameter->getClass()->name.' ';
            }
            if ($parameter->isPassedByReference()) {
                $string .= '&';
            }
            $string .= '$'.$parameter->name;
            if ($parameter->isOptional()) {
                $string .= ' = '.var_export($parameter->getDefaultValue(), true);
            }
            $parameters [] = $string;
        }
        $code .= implode(', ', $parameters);
        $code .= ')'.($storageVariable ? ' use (&$'.$storageVariable.')' : '').' {'.PHP_EOL;
        if ($storageVariable) {
            foreach ($function->getStaticVariables() as $use => $value) {
                $code .= '    $'.$use.' = $'.$storageVariable.'['.var_export($use, true).'];'.PHP_EOL;
            }
        }
        $lines = file($function->getFileName());
        $startLine = $function->getStartLine();
        $endLine = $function->getEndLine();
        $lines[$startLine - 1] = explode('{', $lines[$startLine - 1]);
        $lines[$startLine - 1] = end($lines[$startLine - 1]);
        $end = strrpos($lines[$endLine - 1], '}');
        if ($end !== false) {
            $lines[$endLine - 1] = substr($lines[$endLine - 1], 0, $end);
        }
        $lines[$endLine - 1] .= '}';
        for ($line = $startLine - 1; $line < $endLine; $line++) {
            $code .= $lines[$line];
        }

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
     * @return DependencyInjection
     *
     * @throws DependencyException
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
     * @return Requirement
     *
     * @throws DependencyException
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

        $arguments = array_map(function ($dependencyName) {
            return $this->get($dependencyName);
        }, $dependency->getDependencies());

        return call_user_func_array($value, $arguments);
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
