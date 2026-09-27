<?php

namespace Bitrix24\Mcp\Resources;

/**
 * Ресурс со стандартами кодирования для Битрикс24
 */
class CodingStandards extends AbstractResource
{
    public function getUri(): string
    {
        return 'bitrix://docs/standards';
    }

    public function getName(): string
    {
        return 'Coding Standards';
    }

    public function getDescription(): string
    {
        return 'Стандарты кодирования и best practices для разработки на Битрикс24.';
    }

    public function getMimeType(): string
    {
        return 'text/markdown';
    }

    public function read(array $params = []): string
    {
        return <<<'MARKDOWN'
# Стандарты кодирования Битрикс24

## Общие правила

### 1. PSR-12 Code Style

Следуйте стандарту PSR-12 для PHP кода:

```php
<?php

namespace Local\MyModule;

use Bitrix\Main\Loader;

class MyClass
{
    private string $property;

    public function myMethod(int $param): string
    {
        return 'result';
    }
}
```

### 2. Namespace

Используйте namespace для всех классов:

- Модули: `\Vendor\ModuleName\`
- Локальный код: `\Local\YourNamespace\`

```php
<?php

namespace Local\Handlers;

class LeadHandler
{
    // ...
}
```

### 3. Autoload

Используйте composer autoload или создайте автозагрузку в `include.php` модуля.

## D7 ORM

### Использование D7 вместо старого API

❌ **Неправильно:**
```php
$res = CUser::GetList(($by='id'), ($order='desc'));
while($user = $res->Fetch()) {
    // ...
}
```

✅ **Правильно:**
```php
use Bitrix\Main\UserTable;

$result = UserTable::getList([
    'select' => ['ID', 'NAME', 'EMAIL'],
    'order' => ['ID' => 'DESC'],
]);

while($user = $result->fetch()) {
    // ...
}
```

### Добавление данных

```php
use Bitrix\Crm\LeadTable;

$result = LeadTable::add([
    'TITLE' => 'Новый лид',
    'NAME' => 'Иван',
    'STATUS_ID' => 'NEW',
]);

if ($result->isSuccess()) {
    $leadId = $result->getId();
} else {
    $errors = $result->getErrorMessages();
}
```

### Обновление данных

```php
$result = LeadTable::update($id, [
    'TITLE' => 'Обновлённый заголовок',
]);

if (!$result->isSuccess()) {
    $errors = $result->getErrorMessages();
}
```

## События

### Регистрация обработчиков

Используйте классы вместо функций:

❌ **Неправильно:**
```php
// В init.php
AddEventHandler('crm', 'OnAfterCrmLeadAdd', 'myLeadHandler');

function myLeadHandler(&$arFields) {
    // ...
}
```

✅ **Правильно:**
```php
// В /local/php_interface/init.php
use Bitrix\Main\EventManager;

$eventManager = EventManager::getInstance();
$eventManager->addEventHandler(
    'crm',
    'OnAfterCrmLeadAdd',
    ['Local\Handlers\LeadHandler', 'onAfterAdd']
);

// В /local/classes/Handlers/LeadHandler.php
namespace Local\Handlers;

class LeadHandler
{
    public static function onAfterAdd(&$arFields)
    {
        // ...
    }
}
```

### D7 события

```php
use Bitrix\Main\EventManager;
use Bitrix\Main\Event;

$eventManager = EventManager::getInstance();

$eventManager->addEventHandler(
    'crm',
    'OnAfterCrmLeadAdd',
    function(Event $event) {
        $fields = $event->getParameter('fields');
        // ...
    }
);
```

## Компоненты

### Структура компонента

```
/local/components/vendor/component.name/
├── .description.php      # Описание компонента
├── .parameters.php       # Параметры компонента
├── class.php             # Класс компонента (рекомендуется)
├── component.php         # Логика компонента (если нет class.php)
├── templates/
│   ├── .default/
│   │   ├── template.php  # Шаблон
│   │   ├── style.css     # Стили
│   │   ├── script.js     # JS
│   │   └── component_epilog.php  # Эпилог (опционально)
```

### Класс компонента

```php
<?php

namespace Local\Components;

use Bitrix\Main\Loader;

class MyComponent extends \CBitrixComponent
{
    public function onPrepareComponentParams($arParams)
    {
        // Обработка параметров
        return $arParams;
    }

    public function executeComponent()
    {
        // Загрузка модулей
        Loader::includeModule('crm');

        // Получение данных
        $this->arResult['ITEMS'] = $this->getItems();

        // Подключение шаблона
        $this->includeComponentTemplate();
    }

    private function getItems(): array
    {
        // ...
        return [];
    }
}
```

## REST API

### Использование API через REST

```php
use Bitrix\Main\Web\HttpClient;

$http = new HttpClient();
$response = $http->post('https://your-domain.bitrix24.ru/rest/1/xxxxx/crm.lead.list.json', [
    'select' => ['ID', 'TITLE'],
    'filter' => ['STATUS_ID' => 'NEW'],
]);
```

## Безопасность

### 1. SQL Injection

Всегда используйте подготовленные запросы:

❌ **Неправильно:**
```php
$id = $_GET['id'];
$query = "SELECT * FROM b_user WHERE ID = $id";
```

✅ **Правильно:**
```php
use Bitrix\Main\Application;

$id = (int)$_GET['id'];
$connection = Application::getConnection();
$result = $connection->query("SELECT * FROM b_user WHERE ID = ?", [$id]);
```

### 2. XSS

Экранируйте вывод:

```php
// В шаблоне
echo htmlspecialcharsbx($arResult['TITLE']);

// Или используйте функцию Битрикс
echo \Bitrix\Main\Text\HtmlFilter::encode($arResult['TITLE']);
```

### 3. CSRF

Используйте bitrix_sessid() для форм:

```php
// В форме
<input type="hidden" name="sessid" value="<?= bitrix_sessid() ?>">

// При обработке
if (check_bitrix_sessid()) {
    // Обработка формы
}
```

## Производительность

### 1. Кэширование

```php
use Bitrix\Main\Data\Cache;

$cache = Cache::createInstance();

if ($cache->initCache(3600, 'my_cache_key')) {
    $data = $cache->getVars();
} elseif ($cache->startDataCache()) {
    // Получение данных
    $data = $this->getData();

    // Сохранение в кэш
    $cache->endDataCache($data);
}
```

### 2. Используйте limit

Всегда ограничивайте выборку:

```php
$result = LeadTable::getList([
    'select' => ['ID', 'TITLE'],
    'limit' => 100,
]);
```

### 3. Выбирайте только нужные поля

```php
// ❌ Неправильно
$result = LeadTable::getList(['select' => ['*']]);

// ✅ Правильно
$result = LeadTable::getList(['select' => ['ID', 'TITLE', 'NAME']]);
```

## Структура проекта

```
/local/
├── modules/              # Локальные модули
│   └── mycompany.mymodule/
├── components/           # Локальные компоненты
│   └── mycompany/
├── classes/              # Общие классы (с autoload)
│   └── Handlers/
├── templates/            # Локальные шаблоны
├── php_interface/        # Настройки
│   ├── init.php          # Инициализация
│   └── include/
└── logs/                 # Логи
```

## Логирование

```php
use Bitrix\Main\Diag\Debug;

// Лог в файл
Debug::writeToFile($data, 'My log message', '/local/logs/debug.log');

// Лог в консоль (для AJAX)
Debug::dump($data);
```

## Дополнительные ресурсы

- [Официальная документация D7](https://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=43)
- [PSR-12](https://www.php-fig.org/psr/psr-12/)
- [Битрикс24 REST API](https://dev.1c-bitrix.ru/rest_help/)

MARKDOWN;
    }
}
