<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\ModuleManager;

/**
 * Инструмент для получения списка модулей Битрикс24
 */
class ModulesList extends AbstractTool
{
    public function getName(): string
    {
        return 'modules_list';
    }

    public function getDescription(): string
    {
        return 'Получение списка модулей Битрикс24 с информацией о версии, описании и пути установки.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'installed_only' => [
                    'type' => 'boolean',
                    'description' => 'Показывать только установленные модули (по умолчанию true)',
                    'default' => true,
                ],
                'include_description' => [
                    'type' => 'boolean',
                    'description' => 'Включать подробное описание модулей (по умолчанию true)',
                    'default' => true,
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $installedOnly = $this->getParam($arguments, 'installed_only', true);
        $includeDescription = $this->getParam($arguments, 'include_description', true);

        $modules = [];
        $installedModules = ModuleManager::getInstalledModules();

        if ($installedOnly) {
            // Только установленные модули
            foreach ($installedModules as $moduleId => $moduleData) {
                $modules[] = $this->getModuleInfo($moduleId, $includeDescription, true);
            }
        } else {
            // Все доступные модули
            $allModules = $this->getAllModules();

            foreach ($allModules as $moduleId) {
                $isInstalled = isset($installedModules[$moduleId]);
                $modules[] = $this->getModuleInfo($moduleId, $includeDescription, $isInstalled);
            }
        }

        // Сортируем по ID
        usort($modules, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        return [
            'modules' => $modules,
            'total' => count($modules),
            'installed' => count($installedModules),
        ];
    }

    /**
     * Получение информации о модуле
     */
    private function getModuleInfo(string $moduleId, bool $includeDescription, bool $isInstalled): array
    {
        $modulePath = $this->getModulePath($moduleId);

        $info = [
            'id' => $moduleId,
            'installed' => $isInstalled,
            'path' => $modulePath,
        ];

        // Читаем версию модуля
        $versionFile = $modulePath . '/install/version.php';
        if (file_exists($versionFile)) {
            $arModuleVersion = [];
            include $versionFile;

            if (isset($arModuleVersion['VERSION'])) {
                $info['version'] = $arModuleVersion['VERSION'];
            }

            if (isset($arModuleVersion['VERSION_DATE'])) {
                $info['version_date'] = $arModuleVersion['VERSION_DATE'];
            }
        }

        if ($includeDescription) {
            $indexFile = $modulePath . '/install/index.php';

            if (file_exists($indexFile)) {
                // Пробуем получить информацию о модуле
                $moduleInfo = $this->parseModuleIndex($indexFile, $moduleId);

                if ($moduleInfo) {
                    $info['name'] = $moduleInfo['name'] ?? null;
                    $info['description'] = $moduleInfo['description'] ?? null;
                    $info['partner_name'] = $moduleInfo['partner_name'] ?? null;
                }
            }
        }

        return $info;
    }

    /**
     * Получение пути к модулю
     */
    private function getModulePath(string $moduleId): string
    {
        // Проверяем в /local/modules/
        $localPath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $moduleId;
        if (is_dir($localPath)) {
            return $localPath;
        }

        // Проверяем в /bitrix/modules/
        $bitrixPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $moduleId;
        if (is_dir($bitrixPath)) {
            return $bitrixPath;
        }

        return '';
    }

    /**
     * Получение всех доступных модулей
     */
    private function getAllModules(): array
    {
        $modules = [];

        // Сканируем /bitrix/modules/
        $bitrixModulesPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules';
        if (is_dir($bitrixModulesPath)) {
            $dirs = scandir($bitrixModulesPath);
            foreach ($dirs as $dir) {
                if ($dir !== '.' && $dir !== '..' && is_dir($bitrixModulesPath . '/' . $dir)) {
                    $modules[] = $dir;
                }
            }
        }

        // Сканируем /local/modules/
        $localModulesPath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules';
        if (is_dir($localModulesPath)) {
            $dirs = scandir($localModulesPath);
            foreach ($dirs as $dir) {
                if ($dir !== '.' && $dir !== '..' && is_dir($localModulesPath . '/' . $dir)) {
                    if (!in_array($dir, $modules)) {
                        $modules[] = $dir;
                    }
                }
            }
        }

        return $modules;
    }

    /**
     * Парсинг install/index.php для получения информации о модуле
     */
    private function parseModuleIndex(string $file, string $moduleId): ?array
    {
        $content = file_get_contents($file);

        // Ищем название модуля
        $name = null;
        if (preg_match('/MODULE_NAME\s*=\s*[\'"](.+?)[\'"]/s', $content, $matches)) {
            $name = $matches[1];
        }

        // Ищем описание
        $description = null;
        if (preg_match('/MODULE_DESCRIPTION\s*=\s*[\'"](.+?)[\'"]/s', $content, $matches)) {
            $description = $matches[1];
        }

        // Ищем партнёра
        $partnerName = null;
        if (preg_match('/PARTNER_NAME\s*=\s*[\'"](.+?)[\'"]/s', $content, $matches)) {
            $partnerName = $matches[1];
        }

        if ($name || $description || $partnerName) {
            return [
                'name' => $name,
                'description' => $description,
                'partner_name' => $partnerName,
            ];
        }

        return null;
    }
}
