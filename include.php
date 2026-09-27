<?php

use Bitrix\Main\Loader;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

// Регистрация автозагрузки классов модуля
Loader::registerAutoLoadClasses("bitrix24.mcp", [
    // Core
    "Bitrix24\\Mcp\\Server" => "lib/Server.php",

    // Transport
    "Bitrix24\\Mcp\\Transport\\AbstractTransport" => "lib/Transport/AbstractTransport.php",
    "Bitrix24\\Mcp\\Transport\\StdioTransport" => "lib/Transport/StdioTransport.php",
    "Bitrix24\\Mcp\\Transport\\HttpTransport" => "lib/Transport/HttpTransport.php",

    // Controller
    "Bitrix24\\Mcp\\Controller\\ApiController" => "lib/Controller/ApiController.php",

    // Tools (Abstract)
    "Bitrix24\\Mcp\\Tools\\AbstractTool" => "lib/Tools/AbstractTool.php",

    // Tools (Concrete)
    "Bitrix24\\Mcp\\Tools\\DatabaseSchema" => "lib/Tools/DatabaseSchema.php",
    "Bitrix24\\Mcp\\Tools\\CrmSchema" => "lib/Tools/CrmSchema.php",
    "Bitrix24\\Mcp\\Tools\\CrmReadTool" => "lib/Tools/CrmReadTool.php",
    "Bitrix24\\Mcp\\Tools\\CrmEntityList" => "lib/Tools/CrmEntityList.php",
    "Bitrix24\\Mcp\\Tools\\CrmEntityGet" => "lib/Tools/CrmEntityGet.php",
    "Bitrix24\\Mcp\\Tools\\CrmActivityList" => "lib/Tools/CrmActivityList.php",
    "Bitrix24\\Mcp\\Tools\\CrmTimelineList" => "lib/Tools/CrmTimelineList.php",
    "Bitrix24\\Mcp\\Tools\\ModulesList" => "lib/Tools/ModulesList.php",
    "Bitrix24\\Mcp\\Tools\\CodeSearch" => "lib/Tools/CodeSearch.php",
    "Bitrix24\\Mcp\\Tools\\ClassInspector" => "lib/Tools/ClassInspector.php",
    "Bitrix24\\Mcp\\Tools\\ComponentsList" => "lib/Tools/ComponentsList.php",
    "Bitrix24\\Mcp\\Tools\\EventHandlers" => "lib/Tools/EventHandlers.php",
    "Bitrix24\\Mcp\\Tools\\FileReader" => "lib/Tools/FileReader.php",
    "Bitrix24\\Mcp\\Tools\\SqlQuery" => "lib/Tools/SqlQuery.php",
    "Bitrix24\\Mcp\\Tools\\BizprocReader" => "lib/Tools/BizprocReader.php",

    // Resources (Abstract)
    "Bitrix24\\Mcp\\Resources\\AbstractResource" => "lib/Resources/AbstractResource.php",

    // Resources (Concrete)
    "Bitrix24\\Mcp\\Resources\\ProjectStructure" => "lib/Resources/ProjectStructure.php",
    "Bitrix24\\Mcp\\Resources\\D7Documentation" => "lib/Resources/D7Documentation.php",
    "Bitrix24\\Mcp\\Resources\\CodingStandards" => "lib/Resources/CodingStandards.php",

    // Cloud REST adapter
    "Bitrix24\\Mcp\\Cloud\\BitrixCloudClient" => "lib/Cloud/BitrixCloudClient.php",
    "Bitrix24\\Mcp\\Cloud\\CloudException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\ConfigurationException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\AuthenticationException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\AuthorizationException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\ValidationException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\RateLimitException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\TransientHttpException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\BitrixApiException" => "lib/Cloud/CloudExceptions.php",
    "Bitrix24\\Mcp\\Cloud\\HttpResponse" => "lib/Cloud/HttpTransport.php",
    "Bitrix24\\Mcp\\Cloud\\HttpTransport" => "lib/Cloud/HttpTransport.php",
    "Bitrix24\\Mcp\\Cloud\\CurlHttpTransport" => "lib/Cloud/HttpTransport.php",
    "Bitrix24\\Mcp\\Cloud\\Credential" => "lib/Cloud/Credentials.php",
    "Bitrix24\\Mcp\\Cloud\\WebhookCredential" => "lib/Cloud/Credentials.php",
    "Bitrix24\\Mcp\\Cloud\\OAuthCredential" => "lib/Cloud/Credentials.php",
]);

// Автозагрузка классов зависимостей через Composer (Monolog и т.д.)
$autoloadFile = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
}
