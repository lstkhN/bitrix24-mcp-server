<?php

namespace Bitrix24\Mcp\Tools;

/**
 * Инструмент для получения списка компонентов Битрикс
 */
class ComponentsList extends AbstractTool
{
    public function getName(): string
    {
        return 'components_list';
    }

    public function getDescription(): string
    {
        return 'Получение списка компонентов Битрикс с информацией о шаблонах и параметрах.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'namespace' => [
                    'type' => 'string',
                    'description' => 'Пространство имён (bitrix, local или пусто для всех)',
                    'default' => '',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Паттерн имени компонента (например: "crm.*")',
                    'default' => '',
                ],
                'include_parameters' => [
                    'type' => 'boolean',
                    'description' => 'Включать параметры компонента из .parameters.php',
                    'default' => false,
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $namespace = $this->getParam($arguments, 'namespace', '');
        $pattern = $this->getParam($arguments, 'pattern', '');
        $includeParameters = $this->getParam($arguments, 'include_parameters', false);

        $components = [];

        // Определяем пути для сканирования
        $paths = [];

        if (empty($namespace) || $namespace === 'bitrix') {
            $paths['bitrix'] = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix';
        }

        if (empty($namespace) || $namespace === 'local') {
            $paths['local'] = $_SERVER['DOCUMENT_ROOT'] . '/local/components';
        }

        // Сканируем каждый путь
        foreach ($paths as $ns => $path) {
            if (!is_dir($path)) {
                continue;
            }

            $foundComponents = $this->scanComponents($path, $ns, $pattern, $includeParameters);
            $components = array_merge($components, $foundComponents);
        }

        // Сортируем по имени
        usort($components, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return [
            'components' => $components,
            'total' => count($components),
        ];
    }

    /**
     * Сканирование компонентов в директории
     */
    private function scanComponents(
        string $basePath,
        string $namespace,
        string $pattern,
        bool $includeParameters
    ): array {
        $components = [];

        if ($namespace === 'bitrix') {
            // Компоненты битрикса находятся прямо в /bitrix/components/bitrix/
            $componentDirs = $this->getDirectories($basePath);

            foreach ($componentDirs as $componentName) {
                $componentPath = $basePath . '/' . $componentName;

                if ($this->isComponent($componentPath)) {
                    $fullName = 'bitrix:' . $componentName;

                    if ($this->matchesPattern($componentName, $pattern)) {
                        $components[] = $this->getComponentInfo($fullName, $componentPath, $includeParameters);
                    }
                }
            }
        } else {
            // Локальные компоненты могут быть вложенными: /local/components/{vendor}/{component}/
            $vendorDirs = $this->getDirectories($basePath);

            foreach ($vendorDirs as $vendor) {
                $vendorPath = $basePath . '/' . $vendor;
                $componentDirs = $this->getDirectories($vendorPath);

                foreach ($componentDirs as $componentName) {
                    $componentPath = $vendorPath . '/' . $componentName;

                    if ($this->isComponent($componentPath)) {
                        $fullName = $vendor . ':' . $componentName;

                        if ($this->matchesPattern($componentName, $pattern)) {
                            $components[] = $this->getComponentInfo($fullName, $componentPath, $includeParameters);
                        }
                    }
                }
            }
        }

        return $components;
    }

    /**
     * Получение информации о компоненте
     */
    private function getComponentInfo(string $name, string $path, bool $includeParameters): array
    {
        $info = [
            'name' => $name,
            'path' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $path),
            'has_class' => file_exists($path . '/class.php'),
            'has_ajax' => file_exists($path . '/ajax.php'),
            'has_component' => file_exists($path . '/component.php'),
        ];

        // Получаем шаблоны
        $info['templates'] = $this->getTemplates($path);

        // Получаем параметры
        if ($includeParameters) {
            $info['parameters'] = $this->getParameters($path);
        }

        // Описание компонента
        $descriptionFile = $path . '/.description.php';
        if (file_exists($descriptionFile)) {
            $arComponentDescription = [];
            include $descriptionFile;

            if (isset($arComponentDescription['NAME'])) {
                $info['description_name'] = $arComponentDescription['NAME'];
            }

            if (isset($arComponentDescription['DESCRIPTION'])) {
                $info['description_text'] = $arComponentDescription['DESCRIPTION'];
            }
        }

        return $info;
    }

    /**
     * Получение списка шаблонов компонента
     */
    private function getTemplates(string $componentPath): array
    {
        $templatesPath = $componentPath . '/templates';

        if (!is_dir($templatesPath)) {
            return [];
        }

        $templates = [];
        $dirs = $this->getDirectories($templatesPath);

        foreach ($dirs as $templateName) {
            $templatePath = $templatesPath . '/' . $templateName;

            $templates[] = [
                'name' => $templateName,
                'has_template' => file_exists($templatePath . '/template.php'),
                'has_script' => file_exists($templatePath . '/script.js'),
                'has_style' => file_exists($templatePath . '/style.css'),
            ];
        }

        return $templates;
    }

    /**
     * Получение параметров компонента
     */
    private function getParameters(string $componentPath): array
    {
        $parametersFile = $componentPath . '/.parameters.php';

        if (!file_exists($parametersFile)) {
            return [];
        }

        $arComponentParameters = [];
        include $parametersFile;

        $parameters = [];

        if (isset($arComponentParameters['PARAMETERS']) && is_array($arComponentParameters['PARAMETERS'])) {
            foreach ($arComponentParameters['PARAMETERS'] as $key => $param) {
                $parameters[] = [
                    'code' => $key,
                    'name' => $param['NAME'] ?? '',
                    'type' => $param['TYPE'] ?? '',
                    'default' => $param['DEFAULT'] ?? null,
                    'values' => $param['VALUES'] ?? null,
                    'multiple' => $param['MULTIPLE'] ?? false,
                    'required' => $param['REQUIRED'] ?? false,
                ];
            }
        }

        return $parameters;
    }

    /**
     * Проверка, является ли директория компонентом
     */
    private function isComponent(string $path): bool
    {
        return is_dir($path) &&
            (file_exists($path . '/component.php') ||
                file_exists($path . '/class.php'));
    }

    /**
     * Получение списка директорий
     */
    private function getDirectories(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $dirs = [];
        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (is_dir($path . '/' . $item)) {
                $dirs[] = $item;
            }
        }

        return $dirs;
    }

    /**
     * Проверка соответствия паттерну
     */
    private function matchesPattern(string $name, string $pattern): bool
    {
        if (empty($pattern)) {
            return true;
        }

        // Преобразуем паттерн в регулярное выражение
        // Пример: "crm.*" -> "/^crm\..*$/"
        $regex = '/^' . str_replace(['.', '*'], ['\.', '.*'], $pattern) . '$/';

        return preg_match($regex, $name) === 1;
    }
}
