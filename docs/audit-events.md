# Дизайн audit events

> Статус: предложение для P3 `audit-events`. Документ описывает контракт и эксплуатационную политику; production-код не изменяет и не утверждает, что события уже генерируются.

## Цели и границы

Audit log нужен для ответа на вопросы **кто**, **когда**, **через какой transport/session**, **что запросил**, **какой результат получил** и **почему операция была отклонена**. Он дополняет application/error-логи, но не заменяет их и не должен становиться копией MCP payload.

Обязательные свойства:

- структурированный JSON, одна запись — одно событие;
- UTC и синхронизированные часы; серверное время является источником истины;
- отсутствие credentials, секретов и необезличенных CRM/file/SQL данных;
- возможность связать входящий запрос, вызов инструмента, ошибку и транспортную сессию;
- append-only запись и ограниченный доступ; удаление по retention policy, а не ручное редактирование;
- предсказуемое поведение при недоступности хранилища событий (см. ниже).

В первую очередь регистрируются попытки, а не только успешные операции: `accepted`, `denied`, `failed` и `completed` должны различаться. Для каждого запроса не следует писать несколько независимых «успешных» событий, если достаточно одной записи с `outcome`.

## Схема события

Рекомендуемый envelope (имена полей — стабильный контракт, значения параметров зависят от инструмента):

```json
{
  "schema_version": 1,
  "event_id": "0192f2d0-7b1a-7c2a-8f2d-4f0c7d1c2e10",
  "event_type": "tool.call.completed",
  "occurred_at": "2025-02-14T12:34:56.123Z",
  "producer": "bitrix24.mcp",
  "environment": "production",
  "outcome": "success",
  "severity": "info",
  "actor": {
    "type": "mcp_client",
    "id": "client:sha256:8f31...",
    "auth_method": "bearer"
  },
  "transport": "streamable_http",
  "correlation_id": "01J8Z0FJ2Q7Y8M5W2NQ7T2X6RC",
  "request_id": "01J8Z0FJ2Q7Y8M5W2NQ7T2X6RD",
  "session_id": "01J8Z0FJ1P5H3B2K9Z4C6M7N8A",
  "parent_event_id": null,
  "operation": {
    "name": "crm_entity_get",
    "kind": "tool_call",
    "resource_type": "crm_entity",
    "action": "read"
  },
  "input": {
    "entity_type": "deal",
    "entity_id": 123
  },
  "result": {
    "http_status": 200,
    "item_count": 1,
    "response_bytes": 842
  },
  "error": null,
  "duration_ms": 43,
  "source": {
    "ip": "203.0.113.0/24",
    "user_agent_hash": "sha256:..."
  }
}
```

### Обязательные поля

- `schema_version` — целое число; несовместимые изменения требуют новой версии.
- `event_id` — криптографически случайный UUIDv4 или UUIDv7, уникальный для записи. Его нельзя переиспользовать при retry доставки.
- `event_type` — allowlist, например `request.accepted`, `auth.denied`, `session.created`, `session.terminated`, `tool.call.started`, `tool.call.completed`, `tool.call.denied`, `tool.call.failed`, `security.rate_limited`.
- `occurred_at` — время события в RFC 3339 UTC с миллисекундами.
- `outcome` — одно из `accepted`, `success`, `denied`, `failure`, `cancelled`; `severity` — `info`, `notice`, `warning`, `error`, `critical`.
- `correlation_id`, `request_id` и, если используется, `session_id` — непрозрачные идентификаторы, не содержащие токен или персональные данные.
- `operation` — нормализованное имя операции из allowlist; не принимать имя метода/инструмента как свободный текст в event type.

`input`, `result`, `error` и `source` — условные объекты. В них разрешены только поля из схемы конкретного события. Не следует сохранять полный JSON-RPC request/response, SQL, пути файлов, CRM payload, exception message или HTTP-заголовки.

Для ошибок достаточно типизированного `error`: `category` (`authentication`, `authorization`, `validation`, `rate_limit`, `timeout`, `internal`, `upstream`), стабильный `code`, `retryable` и ссылка на `support_reference`. В `support_reference` допускается `request_id`; исходный текст исключения остаётся в отдельном защищённом диагностическом журнале после masking.

## Корреляция и session IDs

1. При поступлении запроса сервер создаёт новый `request_id`. Внутренние вызовы (`tool`, Bitrix, SQL) наследуют `correlation_id` и `request_id`, но не создают произвольные пользовательские значения.
2. `correlation_id` берётся из доверенного входного trace/correlation header только после валидации формата и длины; иначе генерируется сервером. Внешнее значение не должно позволять подменить identity и в логах помечается как `external`.
3. `session_id` — отдельный случайный идентификатор lifecycle-сессии MCP. Он не является Bearer-токеном, не кодирует пользователя и не заменяет authentication. Его следует принимать только после server-side создания, связывать с auth context, transport и expiry, проверять на каждом запросе и однократно инвалидировать на `DELETE`/timeout. Если приложение остаётся stateless, session ID не следует выдавать как будто он имеет lifecycle.
4. Для асинхронной или вложенной операции используется `parent_event_id`; для retry сохраняются тот же `request_id` и новый `event_id`, а номер попытки — в `operation.attempt`.
5. Значения ID можно передавать клиенту только там, где это уже предусмотрено протоколом или безопасной ошибкой. Нельзя возвращать `event_id` вместе с внутренними exception details.

Пример цепочки: `request.accepted (correlation_id=C, request_id=R)` → `tool.call.started (C,R,session=S)` → `tool.call.completed (C,R,S)`; при отказе вместо completed создаётся `tool.call.denied` с причиной `authorization`.

## Маскирование и классификация данных

Сначала применяется allowlist полей, затем denylist маскирования. Denylist обязателен как страховка и включает имена/пути вроде `authorization`, `cookie`, `set-cookie`, `token`, `access_token`, `refresh_token`, `webhook`, `secret`, `password`, `api_key`, `private_key`, `auth_token`, `query`, `sql`, `body`, `content`, `file`, `email`, `phone` и произвольные HTTP headers. Правило действует рекурсивно по ключам и по строковым значениям, включая URL и exception text.

- Bearer/OAuth/webhook credentials, cookies, session secrets и ключи заменяются на `[REDACTED]`; не хранить даже первые/последние символы.
- SQL и file paths не записываются. Для диагностики разрешаются только классифицированные значения вроде `query_shape_hash` или `path_root` (`local`, `bitrix`), созданные до записи.
- CRM ID может сохраняться только если это необходимо для аудита и политика классификации разрешает его. ФИО, email, телефон, адрес, комментарии и CRM поля — `[REDACTED]` или не записываются.
- Для поиска повторяющегося субъекта использовать HMAC-SHA-256 с ключом из secret manager (`hmac:<key_version>:...`), а не обычный hash PII. Ключи ротируются; старые версии нужны только в пределах retention.
- IP и User-Agent — персональные/квази-персональные данные: по умолчанию IP усекать (например, IPv4 до `/24`, IPv6 до `/64`), User-Agent хранить как allowlisted family/version либо HMAC. Не пытаться маскировать JSON регулярным выражением после сериализации как единственную защиту.
- Числа `item_count`, `duration_ms`, статус и размер ответа допустимы; размеры округлять при необходимости. Payload и произвольные параметры — нет.

Masking должен быть детерминированным для одного события, fail-closed и покрыт тестами на вложенные массивы, URL/query, unicode и exception. Если классификация неизвестна, поле удаляется, а не отправляется «на всякий случай».

## Retention и хранилище

Рекомендуемая базовая политика (может быть сокращена локальными требованиями и должна быть утверждена владельцем данных):

| Класс | Содержимое | Онлайн retention | Архив/удаление |
|---|---|---:|---|
| Audit events | envelope, outcome, operation, IDs, counters, masked actor | 90 дней | затем безвозвратное удаление; архив только при отдельном legal basis |
| Security events | auth failures, rate limits, session lifecycle, policy changes | 180 дней | затем удаление, если нет incident/legal hold |
| Aggregated metrics | только агрегаты без payload и PII | 13 месяцев | агрегации после этого срока пересматриваются |
| Diagnostic errors | masked технический контекст, связанный `request_id` | 30 дней | автоматическое удаление |

Срок считается от `occurred_at`, а не от времени доставки. Часы, timezone и фактическую дату удаления нужно проверять метрикой. Legal hold/расследование может временно приостановить удаление только для явно отмеченного диапазона и с ответственным владельцем; бессрочное хранение по умолчанию запрещено.

Хранилище должно быть append-only (например, защищённое централизованное log/SIEM storage или таблица с запретом UPDATE/DELETE для writer identity), с шифрованием при передаче и хранении, backup lifecycle не длиннее утверждённого срока и журналом доступа. Нужны уникальный `event_id`, индексы по времени/типу/correlation ID и лимит размера события. Данные tenant не должны смешиваться без явного `tenant_id` и контроля доступа; сам `tenant_id` тоже должен быть нормализованным идентификатором, а не URL или credential.

При отказе audit sink: события безопасности (`auth.denied`, session/auth changes, policy violations) должны попасть в локальный bounded spool или дать fail-closed для чувствительной операции; обычный `tool.call.completed` может быть fail-open только если это согласовано с владельцем риска. Нельзя бесконечно повторять отправку и исчерпывать память. Потеря/отбрасывание события повышается отдельным `audit.delivery_failed` (с rate limit) и метрикой.

## Безопасность доступа

- Writer может только добавлять события; reader — отдельная роль с минимально необходимыми полями и audit trail самого чтения.
- Поддержать разделение обязанностей: оператор endpoint не должен автоматически иметь право массово читать audit log.
- Не показывать audit event, секрет, stack trace или внутренний error text MCP-клиенту. При ответе клиенту использовать только `request_id` и стабильное общее сообщение.
- Защитить log pipeline от подмены: TLS, machine identity, проверка схемы, ограничение размера/частоты, по возможности hash-chain или подпись партий для доказательства целостности.
- Не разрешать пользователю управлять `actor.id`, `environment`, `tenant_id`, `outcome` или `severity`. Поля вычисляются сервером.
- Разделить production и non-production sinks/ключи; тестовые события не должны попадать в production retention.
- При обнаружении утечки немедленно ограничить доступ, сохранить incident evidence по legal hold, ротировать masking HMAC/credentials по соответствующей процедуре и проверить цепочку событий.

## Наблюдаемость и контроль качества

Метрики не должны содержать payload. Минимальный набор:

- `audit_events_total{event_type,outcome,transport}`;
- `audit_write_failures_total{sink}` и `audit_spool_depth`;
- `audit_write_latency_ms` и возраст самого старого неотправленного события;
- доля событий без `correlation_id`, с неизвестным полем или отвергнутых schema validator;
- количество `auth.denied`, `security.rate_limited`, повторных session IDs и ошибок masking;
- фактические удаления по retention и ошибки удаления.

Алерты: недоступность sink, рост отказов аутентификации/лимитов относительно baseline, spool выше порога, просроченное удаление, массовая смена `actor`/IP и появление `[UNMASKED]`/запрещенных ключей. Пороги и baseline настраиваются отдельно от схемы и не должны раскрывать значения запросов.

До реализации необходимы contract tests для: обязательных полей и allowlist event types; propagation ID через HTTP/stdio и session lifecycle; повторной доставки без переиспользования `event_id`; рекурсивного masking; неизвестных полей (reject/drop); лимита размера; sink timeout/retry/backpressure; retention и legal hold. Отдельно проверяются запросы `file_reader`, `sql_query`, CRM и ошибки с путями/SQL/DB driver text — ни один из них не должен раскрывать payload.

## Этапы внедрения

1. Утвердить владельца данных, event allowlist, классификацию CRM/IP и retention с юридическими/безопасностными ответственными.
2. Реализовать общий validator/masker и sink без подключения к бизнес-операциям; прогнать contract tests на synthetic fixtures.
3. Добавить события на границе auth/transport/request, затем на вызовах инструментов; rollout сначала в shadow/limited mode.
4. Проверить redaction в реальном log pipeline, доступы reader/writer, алерты, восстановление из spool и процедуру удаления.
5. Только после проверки запретить legacy-логи с body/exception details и объявить schema version production-контрактом.
