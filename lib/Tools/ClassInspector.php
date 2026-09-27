<?php

namespace Bitrix24\Mcp\Tools;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Инструмент для анализа PHP классов через Reflection API
 */
class ClassInspector extends AbstractTool
{
    public function getName(): string
    {
        return 'class_inspector';
    }

    public function getDescription(): string
    {
        return 'Анализ PHP классов через Reflection API. Возвращает информацию о методах, свойствах, '
            . 'константах, родительских классах и трейтах.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'description' => 'Полное имя класса (например: \Bitrix\Crm\LeadTable)',
                ],
                'include_methods' => [
                    'type' => 'boolean',
                    'description' => 'Включать методы класса',
                    'default' => true,
                ],
                'include_properties' => [
                    'type' => 'boolean',
                    'description' => 'Включать свойства класса',
                    'default' => true,
                ],
                'include_constants' => [
                    'type' => 'boolean',
                    'description' => 'Включать константы класса',
                    'default' => true,
                ],
                'include_parent' => [
                    'type' => 'boolean',
                    'description' => 'Включать информацию о родительском классе',
                    'default' => true,
                ],
            ],
            'required' => ['class'],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->validateRequired($arguments, ['class']);

        $className = $arguments['class'];
        $includeMethods = $this->getParam($arguments, 'include_methods', true);
        $includeProperties = $this->getParam($arguments, 'include_properties', true);
        $includeConstants = $this->getParam($arguments, 'include_constants', true);
        $includeParent = $this->getParam($arguments, 'include_parent', true);

        // Убираем ведущий слеш, если есть
        $className = ltrim($className, '\\');

        if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
            throw new \InvalidArgumentException("Class not found: $className");
        }

        $reflection = new ReflectionClass($className);

        $result = [
            'class' => $className,
            'short_name' => $reflection->getShortName(),
            'namespace' => $reflection->getNamespaceName(),
            'file' => $reflection->getFileName() ?: null,
            'type' => $this->getClassType($reflection),
            'abstract' => $reflection->isAbstract(),
            'final' => $reflection->isFinal(),
        ];

        // Документация класса
        if ($reflection->getDocComment()) {
            $result['doc_comment'] = $this->cleanDocComment($reflection->getDocComment());
        }

        // Родительский класс
        if ($includeParent && $reflection->getParentClass()) {
            $result['parent'] = $reflection->getParentClass()->getName();
        }

        // Интерфейсы
        $result['interfaces'] = array_values($reflection->getInterfaceNames());

        // Трейты
        $result['traits'] = array_values(array_map(function ($trait) {
            return $trait->getName();
        }, $reflection->getTraits()));

        // Методы
        if ($includeMethods) {
            $result['methods'] = $this->getMethods($reflection);
        }

        // Свойства
        if ($includeProperties) {
            $result['properties'] = $this->getProperties($reflection);
        }

        // Константы
        if ($includeConstants) {
            $result['constants'] = $reflection->getConstants();
        }

        return $result;
    }

    /**
     * Определение типа класса
     */
    private function getClassType(ReflectionClass $reflection): string
    {
        if ($reflection->isInterface()) {
            return 'interface';
        }

        if ($reflection->isTrait()) {
            return 'trait';
        }

        return 'class';
    }

    /**
     * Получение методов класса
     */
    private function getMethods(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            $methodInfo = [
                'name' => $method->getName(),
                'visibility' => $this->getVisibility($method),
                'static' => $method->isStatic(),
                'abstract' => $method->isAbstract(),
                'final' => $method->isFinal(),
                'declaring_class' => $method->getDeclaringClass()->getName(),
            ];

            // Параметры
            $methodInfo['parameters'] = $this->getParameters($method);

            // Тип возвращаемого значения
            if ($method->hasReturnType()) {
                $returnType = $method->getReturnType();
                $methodInfo['return_type'] = $returnType->getName();
                $methodInfo['return_nullable'] = $returnType->allowsNull();
            }

            // Документация
            if ($method->getDocComment()) {
                $methodInfo['doc_comment'] = $this->cleanDocComment($method->getDocComment());
            }

            $methods[] = $methodInfo;
        }

        return $methods;
    }

    /**
     * Получение свойств класса
     */
    private function getProperties(ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $propertyInfo = [
                'name' => $property->getName(),
                'visibility' => $this->getVisibility($property),
                'static' => $property->isStatic(),
                'declaring_class' => $property->getDeclaringClass()->getName(),
            ];

            // Значение по умолчанию
            if ($property->isDefault()) {
                $propertyInfo['has_default'] = true;

                // Получаем значение по умолчанию только для статических свойств
                if ($property->isStatic()) {
                    $propertyInfo['default_value'] = $property->getValue();
                }
            }

            // Тип свойства (PHP 7.4+)
            if ($property->hasType()) {
                $type = $property->getType();
                $propertyInfo['type'] = $type->getName();
                $propertyInfo['nullable'] = $type->allowsNull();
            }

            // Документация
            if ($property->getDocComment()) {
                $propertyInfo['doc_comment'] = $this->cleanDocComment($property->getDocComment());
            }

            $properties[] = $propertyInfo;
        }

        return $properties;
    }

    /**
     * Получение параметров метода
     */
    private function getParameters(ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $param) {
            $paramInfo = [
                'name' => $param->getName(),
                'position' => $param->getPosition(),
                'optional' => $param->isOptional(),
            ];

            // Тип параметра
            if ($param->hasType()) {
                $type = $param->getType();
                $paramInfo['type'] = $type->getName();
                $paramInfo['nullable'] = $type->allowsNull();
            }

            // Значение по умолчанию
            if ($param->isDefaultValueAvailable()) {
                try {
                    $paramInfo['default_value'] = $param->getDefaultValue();
                } catch (\ReflectionException $e) {
                    $paramInfo['default_value'] = '<cannot retrieve>';
                }
            }

            // Передача по ссылке
            if ($param->isPassedByReference()) {
                $paramInfo['passed_by_reference'] = true;
            }

            // Variadic (...)
            if ($param->isVariadic()) {
                $paramInfo['variadic'] = true;
            }

            $parameters[] = $paramInfo;
        }

        return $parameters;
    }

    /**
     * Определение видимости (public, protected, private)
     */
    private function getVisibility($reflector): string
    {
        if ($reflector->isPublic()) {
            return 'public';
        }

        if ($reflector->isProtected()) {
            return 'protected';
        }

        if ($reflector->isPrivate()) {
            return 'private';
        }

        return 'unknown';
    }

    /**
     * Очистка PHPDoc комментария
     */
    private function cleanDocComment(string $docComment): string
    {
        // Убираем /** и */
        $docComment = preg_replace('/^\/\*\*\s*/', '', $docComment);
        $docComment = preg_replace('/\s*\*\/$/', '', $docComment);

        // Убираем * в начале строк
        $lines = explode("\n", $docComment);
        $lines = array_map(function ($line) {
            return preg_replace('/^\s*\*\s?/', '', $line);
        }, $lines);

        return trim(implode("\n", $lines));
    }
}
