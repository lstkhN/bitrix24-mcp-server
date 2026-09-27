<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- Bitrix module installer loads messages before its legacy module class.

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\Directory;

Loc::loadMessages(__FILE__);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace,Squiz.Classes.ValidClassName.NotCamelCaps,PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Bitrix classmap requires this global legacy module class and its method names.
class bitrix24_mcp extends CModule
{
    public $MODULE_ID = 'bitrix24.mcp';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = 'Bitrix24 MCP Server';
        $this->MODULE_DESCRIPTION = 'Model Context Protocol сервер для интеграции AI-ассистентов '
            . '(Claude Code, Cursor) с Bitrix24 Коробка';
        $this->PARTNER_NAME = 'Bitrix24';
        $this->PARTNER_URI = 'https://www.bitrix24.com';
    }

    public function DoInstall()
    {
        global $APPLICATION;

        if ($this->isVersionD7()) {
            ModuleManager::registerModule($this->MODULE_ID);

            $this->InstallFiles();
            $this->InstallDB();

            $APPLICATION->IncludeAdminFile(
                'Установка модуля ' . $this->MODULE_NAME,
                __DIR__ . '/step.php'
            );
        } else {
            $APPLICATION->ThrowException('Требуется версия PHP 8.1 или выше');
        }

        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION;

        $this->UnInstallDB();
        $this->UnInstallFiles();

        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            'Удаление модуля ' . $this->MODULE_NAME,
            __DIR__ . '/unstep.php'
        );

        return true;
    }

    public function InstallDB()
    {
        // Создаём директорию для логов
        $logsDir = $_SERVER['DOCUMENT_ROOT'] . '/local/logs';
        if (!Directory::isDirectoryExists($logsDir)) {
            Directory::createDirectory($logsDir);
        }

        // Генерируем токен авторизации
        $token = $this->generateToken();
        Option::set($this->MODULE_ID, 'auth_token', $token);
        Option::set($this->MODULE_ID, 'legacy_auth_full_access', 'Y');
        Option::set($this->MODULE_ID, 'auth_scopes', '');
        Option::set($this->MODULE_ID, 'max_code_search_depth', '10');
        Option::set($this->MODULE_ID, 'max_code_search_files', '1000');
        Option::set($this->MODULE_ID, 'max_file_size', '1048576'); // 1MB
        Option::set($this->MODULE_ID, 'cache_ttl', '3600'); // 1 hour
        Option::set($this->MODULE_ID, 'log_enabled', 'Y');

        // Generate and store 32-byte session encryption secret
        $sessionKeyRaw = random_bytes(32);
        Option::set($this->MODULE_ID, 'session_encryption_key', $sessionKeyRaw);

        return true;
    }

    public function UnInstallDB()
    {
        Option::delete($this->MODULE_ID);
        return true;
    }

    public function InstallFiles()
    {
        // Копируем контроллер для HTTP роута
        CopyDirFiles(
            __DIR__ . '/components/',
            $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->MODULE_ID . '/install/components/',
            true,
            true
        );

        return true;
    }

    public function UnInstallFiles()
    {
        return true;
    }

    private function isVersionD7(): bool
    {
        return version_compare(PHP_VERSION, '8.1.0', '>=');
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function GetModuleRightList()
    {
        return [
            'reference_id' => ['D', 'R', 'W'],
            'reference' => [
                '[D] Доступ закрыт',
                '[R] Чтение',
                '[W] Полный доступ'
            ]
        ];
    }
}
// phpcs:enable
