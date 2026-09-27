<?php

namespace Bitrix24\Mcp\Resources;

use Bitrix\Main\ModuleManager;

/**
 * Ресурс с картой структуры проекта
 */
class ProjectStructure extends AbstractResource
{
    public function getUri(): string
    {
        return 'bitrix://project/structure';
    }

    public function getName(): string
    {
        return 'Project Structure';
    }

    public function getDescription(): string
    {
        return 'Карта структуры проекта: модули, компоненты, обработчики событий, классы.';
    }

    public function getMimeType(): string
    {
        return 'application/json';
    }

    public function read(array $params = []): string
    {
        $structure = [
            'modules' => $this->getModulesStructure(),
            'custom_code' => $this->getCustomCodeStructure(),
            'components' => $this->getComponentsStructure(),
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        return json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Структура модулей
     */
    private function getModulesStructure(): array
    {
        $modules = [];
        $installedModules = ModuleManager::getInstalledModules();

        foreach ($installedModules as $moduleId => $moduleData) {
            $modules[] = [
                'id' => $moduleId,
                'installed' => true,
            ];
        }

        return $modules;
    }

    /**
     * Структура кастомного кода
     */
    private function getCustomCodeStructure(): array
    {
        $structure = [
            'paths' => [],
        ];

        // Сканируем /local/
        $localPath = $_SERVER['DOCUMENT_ROOT'] . '/local';

        if (is_dir($localPath)) {
            $structure['paths']['local'] = $this->scanDirectory($localPath, 2);
        }

        return $structure;
    }

    /**
     * Структура компонентов
     */
    private function getComponentsStructure(): array
    {
        $components = [
            'bitrix' => [],
            'local' => [],
        ];

        // Битрикс компоненты
        $bitrixComponentsPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix';
        if (is_dir($bitrixComponentsPath)) {
            $components['bitrix'] = $this->getDirectoryList($bitrixComponentsPath);
        }

        // Локальные компоненты
        $localComponentsPath = $_SERVER['DOCUMENT_ROOT'] . '/local/components';
        if (is_dir($localComponentsPath)) {
            $vendors = $this->getDirectoryList($localComponentsPath);

            foreach ($vendors as $vendor) {
                $vendorPath = $localComponentsPath . '/' . $vendor;
                $components['local'][$vendor] = $this->getDirectoryList($vendorPath);
            }
        }

        return $components;
    }

    /**
     * Сканирование директории
     */
    private function scanDirectory(string $path, int $maxDepth = 1, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth || !is_dir($path)) {
            return [];
        }

        $result = [];
        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;

            if (is_dir($fullPath)) {
                $result[$item] = $this->scanDirectory($fullPath, $maxDepth, $currentDepth + 1);
            }
        }

        return $result;
    }

    /**
     * Получение списка директорий
     */
    private function getDirectoryList(string $path): array
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
}
