# Bitrix24 MCP Server

MCP-сервер для **коробочной версии Bitrix24**. Он позволяет Claude Code, Cursor и другим MCP-клиентам безопасно читать данные CRM, структуру базы данных и исходный код проекта через протокол Model Context Protocol (MCP).

Пакет Composer: `bitrix24/mcp`<br>
ID модуля Bitrix: `bitrix24.mcp`<br>
PHP namespace: `Bitrix24\Mcp`

> **Важно:** проект предназначен для Bitrix24 Box и устанавливается на тот же сервер, где работает Bitrix24. Это не готовый MCP-коннектор для Bitrix24 Cloud. Cloud REST-адаптер является отдельным заделом; см. [`docs/rest-adapter.md`](docs/rest-adapter.md).

## Возможности

- чтение лидов, сделок, контактов и компаний;
- чтение CRM-активностей и записей таймлайна;
- получение схемы CRM и схемы MySQL через `INFORMATION_SCHEMA`;
- поиск по файлам проекта и чтение разрешённых файлов;
- анализ PHP-классов через reflection;
- список установленных модулей, компонентов и обработчиков событий;
- чтение шаблонов, экземпляров, задач и истории бизнес-процессов;
- MCP-ресурсы с картой проекта, документацией D7 и стандартами разработки;
- два транспорта: stdio через SSH и Streamable HTTP с Bearer-токеном;
- HTTP-сессии MCP с привязкой к токену и ограничение доступа к инструментам по scope.

**Сервер не содержит операций записи в CRM.** Не используйте `sql_query` как способ изменения данных: он предназначен для read-oriented `SELECT` и не является полноценной SQL-песочницей.

## Требования

- PHP 8.1 или новее;
- Bitrix24 Box с D7 и возможностью устанавливать локальные модули;
- Composer;
- для stdio — SSH-доступ к серверу Bitrix24;
- для HTTP — HTTPS и возможность подключить endpoint или роут Bitrix.

## Установка

1. Скопируйте репозиторий в каталог модуля:

   ```text
   /local/modules/bitrix24.mcp/
   ```

2. Установите зависимости на сервере Bitrix24:

   ```bash
   cd /path/to/bitrix/local/modules/bitrix24.mcp
   composer install --no-dev --prefer-dist --optimize-autoloader
   ```

3. В административной панели откройте **Marketplace → Установленные решения** (`/bitrix/admin/partner_modules.php`), найдите **Bitrix24 MCP Server** и нажмите **Установить**.

   Установка зарегистрирует модуль, создаст `/local/logs`, сгенерирует случайный Bearer-токен и сохранит настройки модуля. Токен не следует помещать в Git, тикеты, чаты или shell history.

4. Проверьте модуль:

   ```bash
   php /path/to/bitrix/local/modules/bitrix24.mcp/cli/test-connection.php
   ```

5. При необходимости получите токен и готовые примеры конфигурации:

   ```bash
   php /path/to/bitrix/local/modules/bitrix24.mcp/cli/generate-config.php
   ```

   Скрипт выводит секрет в консоль, поэтому запускайте его только в защищённом окружении. Он также может сохранить примеры в `/local/.mcp.json.example` и `/local/.cursorrules.example`.

## Подключение к MCP-клиенту

### Вариант 1: stdio через SSH (рекомендуется для разработки)

Этот способ не открывает MCP endpoint наружу: клиент запускает `server.php` по SSH.

```json
{
  "mcpServers": {
    "bitrix24-mcp": {
      "command": "ssh",
      "args": [
        "bitrix@your-server.example",
        "php /path/to/bitrix/local/modules/bitrix24.mcp/cli/server.php"
      ]
    }
  }
}
```

Конфигурацию для Claude Code положите в `.mcp.json`, для Cursor используйте пример [`examples/cursor.mcp.json`](examples/cursor.mcp.json). В примерах нет приватных ключей и секретов; SSH должен быть настроен отдельно, например через `~/.ssh/config`.

Проверка без вызова инструментов:

```bash
printf '%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"smoke-test","version":"1.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
| ssh bitrix@your-server.example \
    'php /path/to/bitrix/local/modules/bitrix24.mcp/cli/server.php'
```

Ожидаются ответы JSON-RPC с идентификаторами `1` и `2`. Команда намеренно не вызывает инструменты.

### Вариант 2: Streamable HTTP

#### Публичный endpoint

Скопируйте `public/mcp-endpoint.php` в `/local/mcp/index.php`. Адресом MCP будет:

```text
https://example.com/local/mcp/
```

Endpoint принимает POST JSON-RPC, поддерживает GET для SSE keep-alive и DELETE для завершения сессии. Все методы, кроме OPTIONS, требуют заголовок `Authorization: Bearer <TOKEN>`.

#### Роут Bitrix

Подключите `routes/mcp.php` в конфигурации маршрутов Bitrix. Файл регистрирует:

```text
POST   /api/mcp/   — JSON-RPC запросы
GET    /api/mcp/   — SSE endpoint (в текущей реализации 405)
DELETE /api/mcp/   — завершение сессии (в текущей реализации 405)
```

Для Bitrix с маршрутизацией скопируйте файл в `/local/routes/mcp.php` и добавьте его в список конфигураций роутера (`web.php`, `mcp.php`) согласно настройкам конкретной установки.

Пример запроса:

```bash
curl -sS https://example.com/local/mcp/ \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

Для HTTP-клиента используйте URL endpoint и заголовок `Authorization`. Не передавайте токен в URL.

## Доступные инструменты

Точный JSON Schema всегда возвращается методом `tools/list`.

| Инструмент | Назначение | Ограничения |
|---|---|---|
| `database_schema` | Схема таблиц MySQL | Только чтение |
| `crm_schema` | Поля, пользовательские поля и статусы CRM | Только чтение |
| `crm_entity_list` | Списки лидов, сделок, контактов и компаний | До 100 записей за вызов |
| `crm_entity_get` | Одна CRM-сущность по ID | Только чтение |
| `crm_activity_list` | Активности CRM | Только чтение |
| `crm_timeline_list` | Записи таймлайна CRM | Только чтение |
| `bizproc_reader` | Шаблоны, экземпляры, задачи и история бизнес-процессов | Только чтение |
| `modules_list` | Установленные модули и их версии | Только чтение |
| `code_search` | Поиск текста или регулярного выражения в проекте | Ограничиваются глубина и число файлов |
| `class_inspector` | Анализ PHP-классов | Только чтение |
| `components_list` | Компоненты, шаблоны и параметры Bitrix | Только чтение |
| `event_handlers` | Зарегистрированные обработчики событий | Только чтение |
| `file_reader` | Чтение разрешённых файлов построчно | По умолчанию максимум 1 MiB |
| `sql_query` | Read-oriented `SELECT` | До 200 строк, запрос до 5000 символов, около 30 секунд |

Фактический набор зависит от точки входа и политик доступа. Операции записи CRM и произвольный DML не предоставляются.

## MCP-ресурсы

Сервер публикует ресурсы для:

- структуры проекта;
- документации Bitrix D7;
- стандартов разработки проекта.

После `initialize` клиент должен использовать MCP-сессию. Запросы `tools/list`, `tools/call`, `resources/list` и `resources/read` через HTTP требуют действительный `Mcp-Session-Id`, привязанный к текущему Bearer-токену.

## Права и scopes

MCP-клиент выполняет запросы с правами PHP/пользователя Bitrix, под которыми запущен сервер. Bearer-токен подтверждает доступ к endpoint, но не заменяет сетевую изоляцию и права ОС/Bitrix.

Для минимальных прав можно задать опцию модуля `auth_scopes` в виде JSON-массива или списка через запятую:

```text
["crm:read","tools:list","resources:read"]
```

Поддерживаемые scopes:

| Scope | Доступ |
|---|---|
| `tools:list` | `tools/list` |
| `resources:read` | `resources/list`, `resources/read` |
| `crm:read` | CRM-инструменты только для чтения |
| `db:read` | `database_schema`, `sql_query` |
| `files:read` | `file_reader` |
| `code:read` | `code_search` |
| `bizproc:read` | `bizproc_reader` |

Подробности миграции и совместимости старых установок: [`docs/security.md`](docs/security.md). Для production рекомендуется разрешать только необходимые scopes и отдельно пересмотреть доступ к базе, файлам и исходному коду.

## Безопасность

- Для разработки используйте stdio через SSH, отдельного SSH-пользователя и минимальные права.
- Для HTTP используйте HTTPS, reverse proxy или VPN и сетевой allowlist.
- CORS выключен по умолчанию; если он нужен, разрешайте только точные origins через `cors_allowed_origins`.
- Храните Bearer-токен в secret storage и ротируйте его при подозрении на утечку.
- `file_reader` работает только с разрешёнными каталогами (`/local/`, `/bitrix/modules/`, `/bitrix/components/`, `/bitrix/templates/`), блокирует traversal, symlink escape и типичные секретные файлы.
- `sql_query` не является гарантированной защитой от чтения персональных данных и не заменяет отдельного DB-пользователя с правами только `SELECT`.
- Ограничивайте права на `/local/logs` и очищайте старые журналы.

Полный разбор угроз и рекомендаций: [`docs/security.md`](docs/security.md) и [`docs/security-review.md`](docs/security-review.md).

## Настройки модуля

Настройки хранятся в Bitrix Option system, а не в `.env`. Шаблон находится в [`.env.example`](.env.example). Основные параметры:

- `auth_token` — Bearer-токен HTTP;
- `auth_scopes` — разрешённые scopes;
- `legacy_auth_full_access` — режим совместимости старых установок;
- `max_code_search_depth` — глубина поиска (по умолчанию 10);
- `max_code_search_files` — максимум файлов поиска (по умолчанию 1000);
- `max_file_size` — максимум файла (по умолчанию 1 MiB);
- `cache_ttl` — TTL кэша (по умолчанию 3600 секунд);
- `log_enabled` — включение логирования.

## Диагностика

- `401` — отсутствует Bearer-заголовок;
- `403` — токен недействителен или не хватает scope;
- `405` — использован неподдерживаемый HTTP-метод для выбранного endpoint;
- ошибка `Module ... is not installed` — сначала установите модуль через административную панель;
- ошибка автозагрузки — проверьте `composer install` и наличие `vendor/autoload.php`;
- ошибка сессии — повторите `initialize` и передайте выданный `Mcp-Session-Id`.

Логи обычно находятся в `/local/logs/`. Не публикуйте их вместе с токенами и данными CRM.

## Тесты и проверки качества

Обычные тесты не обращаются к рабочему Bitrix24:

```bash
composer test
composer lint
composer phpcs
composer phpstan
composer quality
```

Интеграционные проверки запускаются только явно и требуют настроенного окружения:

```bash
BITRIX24_RUN_INTEGRATION=1 composer test:integration
```

Используйте staging или disposable-окружение и минимальные права. Подробнее: [`docs/integration-tests.md`](docs/integration-tests.md).

## Структура проекта

```text
cli/              CLI-сервер, диагностика и генерация конфигурации
lib/              PHP-код модуля
  Cloud/          REST/OAuth-адаптер Bitrix24 Cloud
  Controller/     HTTP-контроллер
  Resources/      MCP-ресурсы
  Security/       политика scopes
  Session/        хранилища MCP-сессий
  Tools/          MCP-инструменты
  Transport/      stdio и HTTP-транспорт
public/           готовая HTTP-точка входа
routes/           интеграция с роутером Bitrix
docs/             безопасность, схемы и проектная документация
examples/         конфигурации Claude Code и Cursor
tests/            PHPUnit-тесты
install/          установщик модуля
```

## Документация

- [`docs/security.md`](docs/security.md) — модель безопасности и scopes;
- [`docs/security-review.md`](docs/security-review.md) — известные риски и review;
- [`docs/rest-adapter.md`](docs/rest-adapter.md) — границы Cloud REST-адаптера;
- [`docs/integration-tests.md`](docs/integration-tests.md) — интеграционные тесты;
- [`docs/audit-events.md`](docs/audit-events.md) — аудит событий;
- [`CHANGELOG.md`](CHANGELOG.md) — история изменений;
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — правила разработки.

## Лицензия

Проект распространяется по лицензии [MIT](LICENSE). Copyright © 2025 Bitrix24 Team.
