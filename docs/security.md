# Безопасность Bitrix24 MCP

## Граница доверия

MCP-клиент получает данные с Bitrix24 от имени окружения, в котором запущен сервер. Bearer-токен подтверждает доступ к endpoint, но не заменяет сетевую изоляцию, SSH-права или права Bitrix. Подключайте только доверенные клиенты и считайте ответы инструментов конфиденциальными.

## Рекомендуемая схема

- Для разработки предпочтителен stdio через SSH: не открывайте MCP-порт в интернет и выдавайте отдельному SSH-пользователю минимальные права.
- Для HTTP используйте HTTPS, reverse proxy/VPN и allowlist источников. CORS отключён по умолчанию; при необходимости разрешайте только точные HTTPS origins через `cors_allowed_origins`.
- Храните токен в секрет-хранилище/переменной окружения клиента, не в Git, тикетах и логах. После подозрения на утечку замените `bitrix24.mcp/auth_token`.
- Ограничьте права на `/local/logs` и регулярно удаляйте старые логи.

## Что разрешено

- CRM-инструменты — только чтение; списки CRM ограничены 100 записями, а права текущего пользователя Bitrix сохраняются.
- `sql_query` — ограниченный read-oriented SELECT. Сервер блокирует известные DML, lock/file/sleep patterns, комментарии и multi-statements; `LIMIT` ограничен 200 строками, запрос — 5000 символами, выполнение — примерно 30 секундами. Это не замена отдельному DB-user с SELECT-only grants и не средство обезличивания данных.
- `file_reader` — только чтение разрешённых файлов внутри document root и каталогов `/local/`, `/bitrix/modules/`, `/bitrix/components/`, `/bitrix/templates/`. Path traversal и symlink escape запрещены; секретные basenames (`.env*`, ключи, сертификаты, credentials, `dbconn.php` и конфигурационные файлы) блокируются на любом уровне.
- Логи MCP не должны содержать тело запроса/ответа в HTTP endpoint; записываются метод, размеры и технические сведения. Не полагайтесь на это как на гарантию отсутствия чувствительных данных в логах PHP, веб-сервера или клиента.

## Tool authorization policy (scope-based)

Начиная с текущей версии сервер поддерживает минимальную provider-neutral авторизацию на уровне инструментов через `auth_scopes`. Политика реализована в `lib/Security/ToolScopePolicy.php` и работает по принципу deny-by-default при явной передаче scope set.

### Scope mapping

| Scope | Разрешённые инструменты |
|---|---|
| `tools:list` | `tools/list` |
| `resources:read` | `resources/list`, `resources/read` |
| `crm:read` | CRM read-only инструменты (`crm_entity_list`, `crm_entity_get`, `crm_activity_list`, `crm_timeline_list`, `crm_schema`) |
| `db:read` | `database_schema`, `sql_query` |
| `files:read` | `file_reader` |
| `code:read` | `code_search` |
| `bizproc:read` | `bizproc_reader` |

### Конфигурация

- `auth_scopes` — безопасная Bitrix-опция (JSON-массив или список через запятую). Пример: `["crm:read","db:read"]` или `crm:read,db:read`.
- `legacy_auth_full_access` — по умолчанию `Y` (для совместимости с существующими установками). При `Y`, если `auth_scopes` пуст, сервер получает безопасный набор read-скопов без чувствительных (`db:read`, `files:read`, `code:read` не включаются в безопасный набор по умолчанию; они доступны только через явную настройку `auth_scopes`).
- `auth_token` является bearer-секретом: он генерируется автоматически в `install/index.php`, но должен храниться только в защищённом хранилище и передаваться по HTTPS/закрытому SSH.

### Миграция безопасности

1. Установите `legacy_auth_full_access` в `N` для новых установок или после аудита.
2. Установите `auth_scopes` в минимальный необходимый набор через админку или прямой запрос в таблицу `b_option`.
3. Не передавайте `db:read`, `files:read` или `code:read` без явной необходимости и без дополнительной сетевой изоляции.
4. Не храните токен в репозитории или логах; ротация через замену `auth_token` в настройках модуля.

## HTTP MCP Session Lifecycle (post-initialize gate)

Начиная с текущей версии HTTP MCP endpoint поддерживает безопасный lifecycle сессий:

- `lib/Session/SessionStore.php` — provider-neutral интерфейс (создание, привязка к токену, TTL, revocation).
- `MemorySessionStore` — для тестов и stateless-сред.
- `BitrixOptionSessionStore` — для Box; использует `Bitrix\Main\Config\Option` + `SodiumTokenEncryptor` (XChaCha20-Poly1305-IETF). Не хранит bearer-токен в plaintext; хранит только его `sha256`-хэш и зашифрованный payload.
- `Server` и `ApiController` / `public/mcp-endpoint.php` интегрированы: `initialize` выпускает сессию; `DELETE` выполняет `revoke`; `tools/*` и `resources/*` требуют валидную инициализированную сессию, привязанную к текущему `Authorization` заголовку.

## Что не следует считать защитой

- Авторизация токеном не ограничивает список IP сама по себе.
- CORS не защищает серверные запросы и не заменяет токен.
- Запрет SQL-команд не исключает чтение персональных данных через допустимый SELECT.
- Скрытие файла из `file_reader` не защищает секрет, если он доступен другому инструменту или пользователю ОС.
- MCP сам по себе не спрашивает подтверждение перед каждым чтением. Включите подтверждения/approval policy в клиенте, если он это поддерживает.

Перед production-подключением проверьте права Bitrix и ОС, доступность endpoint с внешней сети, TLS-сертификат, ротацию токена и правила резервного копирования логов.
