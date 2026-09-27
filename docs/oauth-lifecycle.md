# Cloud OAuth: жизненный цикл и требования к будущей реализации

> Статус: проектное требование. Этот документ не выбирает OAuth-провайдера,
> криптографическое хранилище или механизм управления ключами. Выбор должен быть
> сделан отдельно, с документированным анализом компромиссов.

## Цель и границы

Будущая Cloud-интеграция должна получать делегированный доступ пользователя по
OAuth 2.0 Authorization Code с PKCE, безопасно хранить и обновлять токены, а
также прекращать доступ по инициативе пользователя, арендатора или системы.

В область входят браузерный вход, callback, связывание с локальной сессией и
tenant, scope, refresh, revoke, постоянное шифрованное хранение и аудит. В
область не входят выбор конкретного облака, UI, автоматическая миграция
существующих секретов и использование client-credentials как замены
пользовательской авторизации.

## Термины и инварианты

- **Authorization attempt** — одноразовая локальная запись, созданная перед
  redirect на authorization endpoint.
- **Connection** — привязанный к tenant и субъекту набор разрешений и токенов.
- **Субъект** — локальный пользователь или иной явно поддержанный principal.
- **Tenant** — изолированная организационная граница приложения.

Инварианты:

1. Callback принимается только для активной, неистёкшей и ещё не использованной
   попытки авторизации.
2. Результат OAuth привязывается к тому же tenant и субъекту, что инициировали
   попытку; значения из query-параметров не могут менять эту привязку.
3. Refresh/access token и PKCE verifier никогда не попадают в URL, логи,
   трассировки, сообщения ошибок или аудит.
4. Дешифрование токена допускается только на сервере в момент обращения к
   провайдеру и только после авторизации требуемого tenant/субъекта.
5. Отзыв или инвалидирование connection запрещает дальнейшее использование,
   даже если старый access token ещё формально не истёк.

## Authorization Code + PKCE и `state`

1. Перед началом входа сервер аутентифицирует локальную сессию, проверяет право
   подключать интеграцию в tenant и создаёт `authorization_attempt`.
2. Сервер генерирует криптографически случайные `state` и `code_verifier`.
   `code_challenge` вычисляется по PKCE S256; `plain` не используется.
3. В попытке сохраняются как минимум хеш `state`, защищённый verifier (либо
   его надёжно зашифрованное значение), tenant ID, subject ID, ожидаемый
   redirect URI, запрошенные scopes, время создания и короткий срок действия.
   Одноразовые данные удаляются либо помечаются использованными после callback.
4. В authorization request передаются фиксированный зарегистрированный redirect
   URI, `response_type=code`, client ID, `state`, S256 challenge и минимальные
   scopes. Redirect URI не строится из заголовков запроса или пользовательского
   ввода.
5. Callback сверяет `state` в constant-time с записью попытки, срок, факт
   использования, tenant/сессию и ожидаемый redirect URI. При ошибке запись
   инвалидируется, пользователю показывается нейтральная ошибка, а секреты не
   раскрываются.
6. Code обменивается сервер-сервером с исходным verifier. Ответ endpoint-а
   верифицируется в соответствии с возможностями выбранного провайдера (например,
   issuer, audience, nonce/подпись при наличии ID token). Токены не считаются
   доказательством локальной сессии.
7. Попытка потребляется атомарно до или вместе с обменом code, чтобы параллельные
   callbacks не смогли создать две connection. Повторный callback отклоняется.

`state` является непрозрачным случайным коррелятором, а не контейнером tenant,
redirect URI или персональных данных. Подписанное self-contained state возможно
только после отдельной оценки: оно всё равно требует защиты от replay и надёжной
привязки к серверной сессии.

## Scopes и согласие

- Запрашиваются только scopes, необходимые для конкретной функции; набор scopes
  версионируется и документируется для каждого вызова API.
- Повышение привилегий требует нового интерактивного согласия, а не тихого
  расширения refresh token. Пользователь видит tenant, подключаемую функцию и
  запрашиваемые права.
- Сохранённая connection содержит выданные scopes и их источник. Перед каждым
  действием проверяются и локальная политика, и наличие нужного scope.
- Отсутствующий или урезанный scope даёт управляемую ошибку/запрос
  переподключения; попыток обхода через более широкие scopes быть не должно.

## Модель данных и интерфейс хранилища

Постоянное хранилище должно держать только зашифрованные секретные материалы.
Минимальная запись `Connection`:

- непрозрачный ID connection, tenant ID, subject ID, provider-account reference;
- выданные scopes, статус (`active`, `reauthorization_required`, `revoked`,
  `deleted`), timestamps и версия записи;
- зашифрованные access token (если его хранение действительно требуется), refresh
  token и связанные expiry/metadata;
- ID ключа/версии шифрования, дата последнего refresh, время отзыва и причина.

Нужен абстрактный интерфейс, а не привязка к БД, Vault, KMS или файловой системе:

```text
createConnection(connection, encryptedTokens) -> Connection
getConnection(tenantId, connectionId) -> Connection | null
leaseActiveConnection(tenantId, connectionId, expectedVersion) -> Lease | conflict
compareAndSwapTokens(lease, encryptedTokens, expiry, version) -> updated | conflict
markReauthorizationRequired(tenantId, connectionId, reason) -> void
revokeConnection(tenantId, connectionId, reason, at) -> void
listConnectionsForRevocation(tenantId | subjectId) -> iterable
purgeConnection(tenantId, connectionId) -> void
```

Реализация обязана обеспечивать tenant-фильтрацию в каждом чтении и изменении,
оптимистичную версию либо эквивалентную блокировку для refresh, а также атомарный
переход статуса. `Lease` не должен содержать токены в журналируемом виде.

Шифрование — envelope encryption с аутентифицированным алгоритмом: data-encryption
key защищается ключом из отдельной системы управления ключами либо эквивалентной
границы доверия. Associated data включает как минимум тип записи, connection ID,
tenant ID и версию схемы, чтобы ciphertext нельзя было перенести между tenant или
полями. Ключи версионируются; plaintext не кэшируется на диске. Конкретный
KMS/HSM, библиотека и бэкенд выбираются после оценки доступности, стоимости,
операционной сложности, модели доступа и требований резидентности данных.

## Использование и refresh

Access token применяется только к исходящему запросу целевого провайдера и не
возвращается клиенту. До вызова проверяются статус connection, tenant/субъект,
scopes и expiry. Refresh выполняется заблаговременно либо после допустимого
`invalid_token`, но один connection обновляется только одним владельцем lease.

Если провайдер возвращает новый refresh token, он заменяет старый атомарно вместе
с access token и expiry. При конфликте конкурент перечитывает текущую запись,
а не повторно использует потенциально уже ротированный токен. Ошибки
`invalid_grant`, отзыва, смены пароля/consent или недостающих scopes переводят
connection в `reauthorization_required`; повторять refresh бесконечно нельзя.
Временные сетевые ошибки ограничиваются backoff и не меняют статус на revoked.

## Отзыв, ротация и удаление

Пользователь и tenant-admin должны иметь действие «отключить». Оно немедленно
блокирует локальное использование connection, затем при наличии endpoint-а
отзыва отправляет revoke для refresh token (и иных требуемых токенов). Ошибка
удалённого revoke не отменяет локальную блокировку; она сохраняется как безопасно
санитизированное событие для повторной обработки.

При обнаружении компрометации, удалении пользователя, смене tenant-политики или
сигнале провайдера выполняется тот же fail-closed переход. Ротация ключей
шифрования поддерживает чтение старых версий и переупаковку ciphertext без
экспорта plaintext; компрометированный ключ требует приоритетной ротации и оценки
затронутых connection. После настраиваемого периода хранения revoked/deleted
записи и ciphertext безвозвратно удаляются, кроме минимального аудита согласно
политике хранения.

## Threat model

| Угроза | Обязательная защита |
| --- | --- |
| CSRF, подмена callback, mix-up | случайный одноразовый state, привязка к сессии/tenant, фиксированные issuer и redirect URI |
| Перехват authorization code | PKCE S256, короткий TTL, одноразовый атомарный обмен, TLS |
| Token theft из БД/backup | AEAD envelope encryption, отдельные ключи, минимизация токенов, контроль доступа и ротация |
| Cross-tenant access | server-side tenant binding, tenant predicate в storage, AAD с tenant ID, авторизация на каждом действии |
| Replay/конкурентный refresh | consumed attempt, CAS/lease, refresh-token rotation, идемпотентные revoke-задачи |
| Утечки через логи/ошибки | redaction токенов, code, verifier, state и Authorization; структурированный аудит без секретов |
| SSRF/подмена endpoint | allowlist и metadata выбранного провайдера, TLS verification, запрет endpoint из пользовательского ввода |
| Злоупотребление scopes | least privilege, явное согласие на повышение, проверка scopes перед операцией |

Остающиеся риски (скомпрометированный браузер пользователя, активная сессия с
правами пользователя и компрометация провайдера) не устраняются хранилищем и
должны быть отражены в продуктовой оценке риска и процедуре реагирования.

## Аудит, наблюдаемость и приватность

Аудитируются создание/потребление attempt, успешное подключение, изменение
scopes, refresh (без токенов), локальный и удалённый revoke, ошибки и ротация
ключей. События содержат tenant ID, connection ID, subject/actor ID, время,
результат и код причины; access/refresh token, code, verifier, state, полные
ответы провайдера и PII не записываются. Метрики используют агрегированные
статусы и не включают идентификаторы, если это не одобрено политикой приватности.

## Требования к тестам

До реализации необходимы unit-, integration- и security-тесты, включая:

- генерацию PKCE S256, достаточную энтропию, TTL и одноразовость `state`;
- отклонение отсутствующего/неверного/просроченного/replayed state, callback из
  иной сессии и tenant, неверного issuer/redirect URI;
- запрет cross-tenant чтения, update, refresh и revoke;
- сохранение только ciphertext, проверку AAD и невозможность чтения после
  ротации/удаления ключа без предусмотренной миграции;
- конкурентный refresh, refresh-token rotation, CAS conflict и `invalid_grant`;
- локальную блокировку при неудачном remote revoke и последующую идемпотентную
  доставку;
- redaction логов, отсутствие секретов в ошибках/метриках и тесты негативных
  сценариев scopes;
- контрактные тесты выбранного провайдера в изолированной тестовой среде без
  production credentials.

## Ограничения развёртывания

Production требует TLS до приложения, точных зарегистрированных HTTPS redirect
URI, защищённых cookie (`Secure`, `HttpOnly`, подходящий `SameSite`), синхронных
часов, секретов вне образа/репозитория, раздельных identities для приложения и
администраторов ключей, encrypted backups и ограниченного доступа к ним.

Среды разработки, теста и production разделяют OAuth clients, redirect URI,
ключи и хранилища. Production credentials не используются в CI; тестовые токены
короткоживущие и отзываемые. Перед включением нужны rate limits для start/callback,
alerting по refresh/revoke ошибкам, runbook для компрометации и проверенная
процедура backup/restore, не нарушающая tenant binding и ключевые версии.

Выбор провайдера допускается только после фиксации поддерживаемых OAuth
возможностей (PKCE, refresh rotation, revoke, scopes, test tenant), требований к
SLA/резидентности, стоимости, операционной нагрузки, модели key custody и плана
аварийного восстановления. Решение и отклонённые альтернативы должны быть
оформлены отдельным ADR.

## Реализованное PDO-хранилище

Для локального постоянного backend-а выбран provider-neutral
`PdoOAuthConnectionStore` с интерфейсом `OAuthConnectionStore`. Его схема находится
в [`oauth-connection-schema.sql`](oauth-connection-schema.sql) и намеренно использует
переносимые типы `VARCHAR`, `TEXT` и `BIGINT`, совместимые с SQLite и MySQL 8+.
Миграция применяется механизмом миграций приложения до создания хранилища.

Первичный ключ содержит `tenant_id`, `subject_id`, `connection_id`; эти три значения
передаются во все чтения, обновления и удаления. Метаданные выдаются только в
immutable `OAuthConnection`; он не содержит plaintext или ciphertext токенов.
`getActiveTokens` возвращает отдельный короткоживущий DTO только для активной
connection и только после авторизации вызывающим кодом tenant/subject.

Access и refresh токены перед записью шифруются существующим `TokenEncryptor`.
AAD включает версию схемы, tenant, subject, connection и имя поля, поэтому ciphertext
нельзя переставить между connection, tenant или типами токена. В таблице хранятся
только ciphertext, scopes, статус, expiry, timestamps и версия. Хранилище не
выполняет OAuth HTTP callback, token exchange или remote revoke.

Refresh выполняется через `compareAndSwapTokens`: `UPDATE` требует active-статус и
ожидаемую версию, заменяет access и (при наличии) refresh token атомарно и увеличивает
версию. Конфликт возвращает `false`; вызывающий код обязан перечитать состояние, а не
повторно применить старый refresh token. Local revoke переводит запись в `revoked`
до remote revoke, после чего plaintext получить нельзя; delete удаляет ciphertext
вместе с записью. Все операции используют подготовленные PDO statements без
динамического пользовательского SQL.

## Реализованное PDO-хранилище authorization attempt

Для короткоживущего этапа между redirect и callback выбран provider-neutral
`PdoOAuthAuthorizationAttemptStore` с интерфейсом
`OAuthAuthorizationAttemptStore`; схема приведена в
[`oauth-authorization-attempts-schema.sql`](oauth-authorization-attempts-schema.sql).
Это отдельное от connection хранилище: оно не выполняет HTTP callback, обмен
code или выбор OAuth-провайдера. Вызывающий код передаёт значения, полученные
из `OAuthState` и `Pkce`; persistent store не зависит от этих типов.

`OAuthAuthorizationAttempt` хранит в памяти immutable данные попытки. В БД raw
`state` не записывается: записывается только SHA-256 `state_hash`. PKCE verifier
перед записью шифруется существующим `TokenEncryptor`; AAD связывает ciphertext
с attempt, tenant, subject и session binding. Redirect URI и JSON scopes также
фиксируются в записи, поэтому callback использует исходные параметры, а не
значения из запроса.

`consume` выполняет один условный `UPDATE`: он требует attempt ID, hash
переданного state, tenant, subject, session binding, отсутствие `consumed_at` и
неистёкший TTL. Поэтому mismatched state/session, expired и replayed попытки
отклоняются одинаковым typed exception, а успешная попытка необратимо получает
`consumed_at`. Все запросы prepared; exception messages не включают state или
verifier.
