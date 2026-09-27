# Security Migration — HTTP MCP Session Lifecycle (post-initialize gate)

## Что изменено после введения initialize gate

### Provider-neutral безопасный session store

Создан интерфейс `lib/Session/SessionStore.php` с контрактами:
- `create(string $tokenHash, ?string $principal): string` — выпускает криптографически случайный opaque session ID (`random_bytes(32) + bin2hex`).
- `get(string $sessionId, string $tokenHash): ?array` — проверяет TTL, revocation и привязку к хэшу текущего auth-токена.
- `setInitialized(...)` / `revoke(...)` — управление initialized state и однократной инвалидацией.

Никогда не хранит bearer-токен в plaintext. Для привязки используется `hash('sha256', $token)`.

### Реализации

- `MemorySessionStore` — для тестов и stateless-сред, не подходит для concurrent production.
- `BitrixOptionSessionStore` — механизм через `Bitrix\Main\Config\Option` (Box). Сессия шифруется через `SodiumTokenEncryptor` (XChaCha20-Poly1305-IETF) с AAD, привязанным к `session_id:token_hash`. Требуется отдельный 32-байтный секрет `session_encryption_key`; deterministic key derivation не используется. Не хранит plaintext bearer.

### Интеграция в Server (`lib/Server.php`)

- В конструктор добавлен `?SessionStore $sessionStore = null`.
- `handleRequest` теперь:
  - Для `initialize`: позволяет запрос и через `initialize()` устанавливает `initialized` в сессии (если store настроен).
  - Для `tools/list`, `tools/call`, `resources/list`, `resources/read`: требует валидную сессию с `initialized === true`, привязанную к текущему токену. Без сессии или с неинициализированной сессией возвращает `-32603` (`Server not initialized` или `Session required`).
  - Для `initialized` / `notifications/initialized`: не требует повторной инициализации, но проверяет существование сессии (если store активен).
- Добавлен `getBoundTokenHash()` — производная привязка по `X-Authorization` / `Authorization` заголовку или `auth_token` (без хранения токена в сессии).

### Интеграция в `ApiController`

- `createServer()` теперь принимает `?SessionStore $sessionStore` и передаёт его в `Server`.
- При POST `/api/mcp/`:
  - `initialize` выпускает сессию (`create` + `setInitialized`), возвращает `Mcp-Session-Id` в заголовке (не логирует session ID или токен).
  - Последующие запросы требуют `HTTP_MCP_SESSION_ID`, привязанного к текущему Bearer-токену. Без заголовка или с неверной привязкой — ошибка (`-32603`).
  - `DELETE` (`deleteAction`) теперь выполняет фактическую revocation через `sessionStore->revoke()`, использует авторизацию и возвращает `session revoked`.

### Интеграция в `public/mcp-endpoint.php`

- POST endpoint интегрирован с `MemorySessionStore` / `BitrixOptionSessionStore` (выбор через `use_bitrix_session_store` option, по умолчанию `Y`).
- `DELETE` endpoint теперь выполняет реальную revocation с проверкой `Mcp-Session-Id` и авторизации.
- `initialize` выпускает сессию и устанавливает `initialized`.
- Для не-`initialize` запросов проверяется `Mcp-Session-Id`, привязка к токену и `initialized` state.

### Безопасность

- Не используется `*` CORS; строгий `allowlist` через `cors_allowed_origins` сохраняется.
- `Server` gate (`secureByDefault`, `legacyCompatibility`) не ослаблен.
- Логи (`mcp_controller.log`, `mcp_endpoint.log`) не содержат session ID или bearer-токен. Логируется только размер запроса/ответа, метод, статус операций (без чувствительных данных).
- `BitrixOptionSessionStore` использует зашифрованное хранение через `SodiumTokenEncryptor`; требуется ровно 32-байтный секрет `session_encryption_key`. Новые установки генерируют его при установке модуля. Старые установки должны выполнить миграцию; до этого session store работает в fail-closed режиме.

### Тесты

- Добавлен `tests/Unit/Session/MemorySessionStoreTest.php`: проверка создания, привязки токена, revocation, TTL (через `MemorySessionStore`).
- Существующие unit-тесты (`phpunit --testsuite Unit`) проходят без регрессий.

### Документация

- Обновлён `docs/security-migration.md` (настоящее описание).
- `docs/security.md` остаётся актуальным: дополнен ссылкой на session lifecycle.

## Как работает lifecycle

1. Клиент вызывает `initialize` с `Authorization: Bearer <token>`.
2. Сервер генерирует `session_id` (32 байта случайных данных) и связывает с `sha256(token)`.
3. Сервер отвечает `200` + `Mcp-Session-Id: <id>` + `result` (capabilities).
4. Клиент отправляет `notifications/initialized` с тем же `Mcp-Session-Id`.
5. Сервер устанавливает `initialized = true` для сессии.
6. Для последующих `tools/call` / `resources/read` клиент передаёт `Mcp-Session-Id`. Сервер проверяет:
   - `session_id` существует в store,
   - `sha256(current_token)` совпадает с привязанным,
   - `initialized === true`,
   - TTL не истёк, revocation не выполнен.
7. `DELETE` с `Mcp-Session-Id` вызывает `revoke()`; после этого любые запросы с этим `session_id` отклоняются (`-32603`).

## Оставшиеся задачи

- Перевести существующие установки на явные `auth_scopes` и отключить legacy compatibility после проверки клиентов.
- Использовать отдельного MySQL-пользователя только с `SELECT` на утверждённые таблицы/views; PHP-фильтр не заменяет DB-level read-only grants.
- Добавить production rate limiting и concurrency limits на reverse proxy/Bitrix infrastructure.
- Выполнить opt-in Box integration tests для реальных ACL, session store и CORS.
- Планово очищать `session_*` options через Bitrix-агент или cron.
