# Дизайн записи в CRM: подтверждение и `dry_run`

> Статус: предложение для P3 `crm-write-dry-run`. Документ описывает контракт и политику; write-инструменты и код выполнения в этом изменении не добавляются.

## Цели и границы

Запись в CRM должна быть двухфазной: сначала сервер строит план и показывает последствия, затем отдельный явно подтверждённый запрос применяет **тот же** план. MCP-клиент не считается подтверждением сам по себе: подтверждение должно быть явным действием пользователя в доверенном клиенте или политике оператора.

В первой версии:

- все write-методы запрещены по умолчанию и включаются явным allowlist для tenant, метода и роли;
- каждый вызов проходит авторизацию текущего пользователя Bitrix и серверную policy-проверку;
- `dry_run` по умолчанию включён и не имеет побочных эффектов;
- выполнение возможно только с одноразовым подтверждением, коротким TTL и ключом идемпотентности;
- документ не предполагает обход прав Bitrix, прямые SQL-записи или выполнение произвольного REST-метода.

## Жизненный цикл операции

Предлагаются два MCP-инструмента (названия могут быть уточнены при реализации):

- `crm_write_preview` — принимает намерение записи и возвращает нормализованный план; всегда `dry_run=true`;
- `crm_write_execute` — применяет ранее подтверждённый план; требует `dry_run=false`, `confirmation_token` и `idempotency_key`.

Альтернативный единый инструмент допустим только при тех же серверных гарантиях: отсутствие параметра `dry_run` означает безопасный preview, а переключение в execute нельзя сделать одним неявным флагом.

Состояния операции:

```text
preview_requested -> planned -> awaiting_confirmation -> executing
                                      |                  |
                                      v                  v
                                  expired/rejected   succeeded/failed/unknown
```

Preview создаёт `operation_id`, отпечаток нормализованных параметров и одноразовый `confirmation_token`. Токен не является полномочием навсегда: он связан с конкретным исполнителем, tenant, методом, целями, параметрами и сроком действия. Execute повторно проверяет права, схему, состояние целей и политику непосредственно перед записью. Изменение любого значимого параметра требует нового preview.

## Контракт запроса

Концептуальная схема (не PHP-код):

```text
crm_write_preview {
  tenant_id: string,             // выбирается из server-side allowlist
  operation: enum,               // например crm.deal.update
  target: {entity_type, entity_id?},
  input: object,                 // только поля, разрешённые схемой
  dry_run: true,
  client_request_id?: string
}

crm_write_execute {
  operation_id: string,
  confirmation_token: string,
  idempotency_key: string,       // opaque, 16–128 ASCII chars
  dry_run: false,
  client_request_id?: string
}
```

`tenant_id`, operation и target не должны позволять выбрать произвольный портал, URL или REST-метод. `input` проходит строгую схему, ограничения размера и нормализацию (типы, даты, списки, whitespace) **до** расчёта отпечатка. В preview нельзя принимать `execute=true` или эквивалентные флаги.

`dry_run` означает отсутствие любого изменения данных и внешнего побочного эффекта: нельзя создавать сущность, комментарий, activity, webhook/event, notification, бизнес-процесс или загружать файл. Допустимы чтение, проверка схемы/прав, расчёт diff и симуляция в изолированном режиме. Если конкретный Bitrix API не может гарантировать такую симуляцию, preview возвращает `simulation: "not_available"` и не предлагает выполнять операцию автоматически; сервер не подменяет dry-run реальной записью.

## Модель подтверждения

Preview должен возвращать человеку понятное подтверждение, а не только непрозрачный ID:

- что будет сделано: `operation`, tenant/портал (без секретов), тип и ID цели;
- diff `before`/`after` для изменяемых полей; для новых объектов — итоговые поля;
- побочные эффекты и связанные объекты (activities, automation, notifications), если они известны;
- уровень риска, число затрагиваемых объектов и необратимость;
- проверки прав, валидации и предупреждения;
- срок действия (`expires_at`) и точный текст вроде: «Подтвердить изменение сделки 123: ...».

Подтверждение считается действительным только если пользователь/оператор явно согласился с этим summary. Клиент не должен генерировать подтверждение из свободного текста модели. Если клиент не умеет показывать approval, сервер оставляет операцию в preview и возвращает `confirmation_required`.

`confirmation_token` — случайный непросматриваемый секрет, хранится сервером в хешированном виде либо в короткоживущем защищённом хранилище. Он одноразовый, не передаётся в audit payload, не попадает в URL и логи. TTL рекомендуется 5–10 минут, абсолютный максимум задаётся policy. Привязки токена: authenticated principal, tenant, operation, target, normalized-input hash, policy version и preview expiry. Execute атомарно переводит токен из pending в consumed; повторная отправка не может выполнить операцию ещё раз.

Подтверждение не заменяет авторизацию: при execute проверяются текущие Bitrix-права, allowlist, лимиты и блокировки. Если права изменились или цель изменилась после preview, операция отклоняется и требуется новый preview.

## Идемпотентность и повторная доставка

`idempotency_key` — ключ намерения клиента, генерируемый до первой попытки execute и сохраняемый клиентом при timeout. Его область уникальности: `(authenticated principal, tenant_id, operation, idempotency_key)`, а для более строгой изоляции — также target. Сервер хранит key с TTL не меньше максимального окна повторов (рекомендуется 24–72 часа) и отпечаток запроса.

Правила:

1. Тот же key и тот же отпечаток после успешного выполнения возвращают сохранённый результат без повторной записи (`replayed=true`).
2. Тот же key с другим operation, target, параметрами, confirmation или policy version возвращает `idempotency_conflict`; выполнять нельзя.
3. Key резервируется атомарно до внешней записи. В состоянии `in_progress` повтор получает `operation_in_progress` или безопасно ждёт ограниченный срок — второй worker не стартует запись.
4. После timeout, когда результат неизвестен, сервер не делает слепой retry. Он сначала разрешает состояние по key/журналу поставщика и возвращает `result_unknown`, если достоверного результата нет.
5. Idempotency key не заменяет подтверждение и не является секретом; не включать его целиком в публичный audit (допустим короткий fingerprint).

Если upstream Bitrix не поддерживает idempotency, адаптер обязан сериализовать выполнение по key и сохранять результат/неопределённое состояние. Автоматический retry mutating-запроса разрешён только при подтверждённой идемпотентности; иначе операция останавливается.

## Опасные операции и policy

Риск вычисляется по фактическому эффекту, а не только по названию метода:

| Уровень | Примеры | Политика |
|---|---|---|
| `low` | изменение одного несущественного поля | подтверждение обязательно; один объект; лимит полей |
| `medium` | создание сущности, изменение статуса/ответственного, activity или массовое изменение | подтверждение и расширенный diff; малый лимит объектов |
| `high` | удаление/архивирование, потеря данных, изменение прав, запуск automation, отправка сообщения наружу, bulk-операция | отдельная роль/allowlist, явное повторное подтверждение и жёсткие лимиты; по умолчанию запрещено |
| `critical` | массовое удаление, экспорт/раскрытие персональных данных, изменение credentials или системных настроек | MCP write не поддерживает; только админский внеполосный процесс |

Удаление трактуется как необратимое, даже если Bitrix формально поддерживает восстановление. Bulk-запросы не должны маскироваться под набор одиночных вызовов: в preview показываются количество, список/фильтр и верхняя граница, а политика атомарности указывается явно. Запрещены произвольные поля, серверные выражения, подмена tenant/actor и операции, которые могут изменить данные через callback или SQL.

Для high/critical можно требовать второй фактор/двухчеловеческое approval, но его проверка должна быть серверной. MCP-сообщение «да, выполняй» без валидного approval record недостаточно.

## Audit trail

Каждый этап записывается в структурированный append-only audit trail: `preview_created`, `confirmation_consumed`, `replayed`, `conflict`, `expired`, `rejected`, `execute_started`, `succeeded`, `failed`, `unknown`. Запись содержит:

- `event_id`, timestamp (UTC), `operation_id`, request/correlation ID;
- authenticated actor и service identity, tenant, operation, target и risk level;
- normalized-input hash, policy/schema version, idempotency fingerprint;
- результат/код ошибки, duration, upstream request ID и число попыток;
- для diff — только разрешённые и замаскированные поля, не полный CRM payload.

Токены подтверждения, access tokens, cookies, webhook URL, персональные значения, секреты и полные тела запросов/ответов не записываются. Email, телефон, комментарий и другие PII маскируются allowlist-правилом; неизвестные поля по умолчанию исключаются. Журнал защищается от обычной модификации приложением, ограничивается по retention и доступен только операторам аудита. Не следует обещать неизменяемость, если хранилище её не обеспечивает: в таком случае добавляются hash chain/подпись и контроль экспорта.

Audit и бизнес-транзакция должны быть согласованы настолько, чтобы не потерять факт попытки. `execute_started` пишется до вызова upstream; `succeeded` — только после подтверждённого ответа. При неопределённом результате фиксируется `unknown`, а не угадывается успех.

## MCP responses

Успешный preview возвращает MCP `result` с `structuredContent` и человекочитаемым `content`:

```json
{
  "status": "awaiting_confirmation",
  "operation_id": "op_01...",
  "dry_run": true,
  "operation": "crm.deal.update",
  "risk": "medium",
  "target": {"entity_type": "deal", "entity_id": 123},
  "changes": [{"field": "STAGE_ID", "before": "NEW", "after": "PREPARATION"}],
  "warnings": [],
  "confirmation_required": true,
  "expires_at": "2025-01-01T12:05:00Z"
}
```

В execute допустимы только следующие итоговые статусы: `succeeded`, `replayed`, `failed`, `result_unknown`; `dry_run` всегда `false` в запросе и явно отражается в ответе. При успехе возвращаются `operation_id`, `idempotency_key_fingerprint`, изменённые target IDs, upstream request ID и минимальный результат — без секретов и лишних CRM-полей.

Ошибки — MCP `isError: true` со стабильным машинным `code`, безопасным `message`, `operation_id`/correlation ID и, где уместно, `retryable`:

- `validation_error`, `policy_denied`, `authorization_required`;
- `confirmation_required`, `confirmation_expired`, `confirmation_mismatch`, `confirmation_already_used`;
- `idempotency_conflict`, `operation_in_progress`, `result_unknown`;
- `target_changed`, `upstream_rejected`, `rate_limited`.

В `message` не выдаются stack trace, SQL/REST URL, токены, внутренние пути или полный upstream payload. `retryable` не должен советовать повтор для небезопасной mutating-операции; для `result_unknown` рекомендация — запросить состояние по `operation_id`/key, а не повторить запись.

## Решение по PDO persistence

Реализован provider-neutral слой `CrmWriteOperationStore` и
`PdoCrmWriteOperationStore`; схема приведена в
[`crm-write-operations-schema.sql`](crm-write-operations-schema.sql). Это только
хранилище подтверждений, а не адаптер Bitrix: его API не принимает REST-метод,
URL, SQL или raw CRM payload и не выполняет CRM-записей. Preview передаёт в него
только hash нормализованного payload и безопасный human summary. Raw
`confirmation_token` и `idempotency_key` не сохраняются: PDO-реализация хранит
только SHA-256 hash (ключ дополнительно scoped по tenant, actor и logical
operation). `consume` использует транзакцию, уникальную idempotency reservation
и conditional update, поэтому подтверждение pending можно забрать только один
раз; точный fingerprint возвращает сохранённую операцию как replay, иной —
`idempotency_conflict`.

Хранилище не является audit log. Для audit trail предусмотрены
`CrmAuditEventStore` и append-only `PdoCrmAuditEventStore`; схема приведена в
[`crm-audit-events-schema.sql`](crm-audit-events-schema.sql). `CrmAuditEvent`
неизменяем, получает стабильный внешний `event_id` и обязательно связывается с
tenant, actor, `operation_id` и logical operation. Его payload допускает только
SHA-256 hashes/fingerprint и небольшой allowlist redacted metadata (версии,
risk, correlation/upstream ID, безопасный error code и числовые counters).
Никаких raw token, idempotency key, credentials, URL, PII или полного CRM
payload API не принимает. PDO store выполняет исключительно prepared `INSERT`;
update/delete API отсутствуют. База должна выдать application credential только
`INSERT`/`SELECT`, а retention/export выполнять отдельной audit-учётной записью.

Для безопасного контекста confirmation используйте только
`CrmWriteOperation::auditContext()`; он исключает summary, raw token,
idempotency key и payload. Summary должен строиться allowlist-ом до передачи в
store и не должен содержать секреты.

## Критерии готовности к реализации

До появления write-кода нужны contract-тесты для: полного отсутствия побочных эффектов preview; binding и истечения confirmation; повторной доставки и конфликтов idempotency; конкурентных execute; повторной проверки прав и изменившейся цели; redaction audit/MCP; всех опасных policy-классов; timeout с `result_unknown`. Интеграционные тесты с Bitrix должны быть opt-in на отдельном тестовом tenant и не включать destructive операции. До утверждения этого контракта существующие read-only инструменты и transport не меняются.
