# Дизайн отдельного адаптера Bitrix24 Cloud REST

> Статус: реализован базовый adapter (webhook/OAuth token, allowlist, retries, host validation). OAuth authorization-code flow и persistent token store остаются отдельным этапом.

## Цели и границы

Адаптер должен быть самостоятельным слоем интеграции с Bitrix24 Cloud. Он принимает нормализованные команды приложения и скрывает от вызывающего кода URL REST, формат авторизации, ограничения скорости и особенности повторных запросов.

В область первой версии входят:

- один или несколько порталов (tenant) в одном процессе;
- вызовы методов Bitrix24 REST с JSON-ответом;
- два способа авторизации: входящий webhook и OAuth 2.0;
- безопасное хранение и обновление учётных данных;
- rate limiting, timeout и контролируемые retries;
- диагностические метаданные без утечки токенов.

В область первой версии не входят запись в CRM, обработка входящих событий, синхронизация и очередь фоновых задач. Вебхуки в этом документе означают **входящий REST webhook для вызова API**. Outbound-события Bitrix24 (event handlers) потребуют отдельного receiver и проверки подписи/источника.

## Архитектура

Адаптер располагается за портом приложения и не должен быть связан с MCP transport или Bitrix D7. Рекомендуемые слои:

```text
Application / MCP tool
        |
        v
BitrixCloudClient (стабильный интерфейс)
        |
        +-- CredentialProvider (Webhook | OAuthTokenStore)
        +-- RequestPolicy (timeouts, rate limit, retries)
        +-- HttpTransport (PSR-18-подобный контракт)
        +-- ResponseMapper / ErrorMapper
```

Каждый `tenantId` однозначно соответствует одному порталу. URL портала и credentials берутся из хранилища конфигурации, а не из произвольного пользовательского параметра REST-запроса. Это предотвращает SSRF и смешивание данных разных компаний.

Для OAuth допустимы только HTTPS-порталы и заранее разрешённые домены Bitrix24 Cloud (например, `*.bitrix24.*` согласно списку зон развёртывания). Разрешение произвольного host должно быть отдельной, явно подтверждённой настройкой.

## Контракт адаптера

Названия ниже являются проектным контрактом, а не требованием немедленно создавать PHP-классы:

```text
interface BitrixCloudClient {
    authorizeUrl(tenantId, redirectUri, state, codeChallenge?): Uri
    exchangeCode(tenantId, code, redirectUri, codeVerifier?): OAuthToken
    refresh(tenantId): OAuthToken

    call(tenantId, method, params = {}, options = {}): RestResult
}
```

`method` — имя REST-метода (`crm.deal.get`), а `params` — только сериализуемые параметры этого метода. `options` может содержать `idempotencyKey`, `deadline`, `retryPolicy` и `requestId`, но не `access_token`, URL или произвольные HTTP-заголовки. Публичному вызывающему коду не следует выдавать сырой HTTP-клиент.

Минимальные значения результата:

```text
RestResult {
    data: mixed,                 // decoded result/result_next/result_total
    raw: object,                 // только если это разрешено политикой, без токенов
    tenantId: string,
    method: string,
    requestId: string,
    attempts: int,
    rateLimited: bool
}
```

Ошибки должны быть типизированы, а не определяться по тексту сообщения:

- `ConfigurationError` — неизвестный tenant, некорректный host или отсутствующая настройка;
- `AuthenticationError` — отсутствует/просрочен credential, OAuth refresh отклонён;
- `AuthorizationError` — Bitrix24 не разрешил метод или scope;
- `ValidationError` — ошибка параметров REST;
- `RateLimitError` — лимит исчерпан после разрешённых ожиданий;
- `TransientHttpError` — timeout, сеть, 408, 429 или 5xx после/до исчерпания retries;
- `BitrixApiError` — структурированная ошибка Bitrix24, не относящаяся к перечисленным классам.

Ошибка содержит `tenantId`, `method`, `requestId`, код Bitrix24/HTTP, номер попытки и `retryAfter`, если он известен. Она не содержит access token, webhook code, client secret, полный URL с query string или значения чувствительных параметров.

## Webhook authentication

В конфигурации webhook хранится:

```text
WebhookCredential {
    tenantId
    portalBaseUrl
    userId
    webhookCode        // secret, encrypted at rest
    allowedMethods[]    // explicit allowlist
}
```

Адаптер строит URL вида `https://<portal>/rest/<userId>/<webhookCode>/<method>.json` и отправляет параметры в POST body (для простых read-only вызовов допускается Bitrix-совместимый формат, но секрет не должен попадать в query-лог). `portalBaseUrl`, `userId` и `method` валидируются отдельно; пользовательский ввод не может добавить path segment.

Webhook не даёт полноценной модели OAuth scopes и обычно не предоставляет refresh/revoke. Поэтому его следует применять только для одного доверенного портала, для минимального allowlist методов и преимущественно для read-only операций. При компрометации code его нужно немедленно перевыпустить в Bitrix24 и обновить secret store. При регистрации webhook должен быть выбран отдельный технический пользователь с минимальными правами.

Webhook URL нельзя показывать в ошибках, метриках, трассировках, access-логах и пользовательских сообщениях. В диагностике разрешается только `portal host`, `tenantId` и маскированный credential fingerprint.

## OAuth 2.0

Для multi-tenant интеграции предпочтителен OAuth:

1. Зарегистрировать приложение Bitrix24 с точным HTTPS `redirect_uri` и минимальным набором разрешений.
2. Перед редиректом создать криптографически случайные одноразовые `state` (и PKCE `code_verifier`/`code_challenge`, если это поддерживается выбранным OAuth-потоком), сохранить их с коротким TTL и `tenantId`.
3. Направить пользователя на OAuth authorization endpoint Bitrix24 с `client_id`, `redirect_uri`, `state` и запрошенными scope.
4. На callback проверить точное совпадение `state`, отсутствие повторного использования и код ошибки, затем обменять одноразовый `code` сервер-сервер на token endpoint.
5. Зашифрованно сохранить `access_token`, `refresh_token` (если выдан), `expires_at`, scope, portal URL и идентификатор установки. В браузер и MCP-ответы tokens не возвращать.
6. Перед вызовом обновить истёкший token; при конкурентных запросах использовать lock на `tenantId`, чтобы не выполнять несколько refresh одновременно.

Точные endpoint и поля (`authorize`, `token`, срок действия и выданные scope) должны быть параметризованы по официальной документации Bitrix24 для зарегистрированного приложения, а не захардкожены из предположения о конкретной зоне портала. `client_secret` хранится только на сервере. Если refresh отклонён (`invalid_grant`/отозванная установка), credentials помечаются недействительными, удаляются из активного использования и требуется повторная авторизация; бесконечный refresh запрещён.

OAuth access token передаётся в заголовке/механизме, который требует актуальная версия REST API; fallback на URL допускается только если это необходимо Bitrix24, и тогда URL с token никогда не логируется. OAuth и webhook должны иметь один и тот же внешний контракт `call()`.

## Rate limiting

Ограничитель должен быть отдельным от retry-механизма. Ключ ограничения — минимум `(tenantId, credential identity)`; при необходимости добавляется REST-метод. Для распределённых worker-ов нужен общий Redis/DB-backed token bucket или leaky bucket, а не только in-process counter.

Базовая безопасная политика:

- ограничить частоту и число одновременных запросов консервативным значением конфигурации;
- уважать `Retry-After` и rate-limit headers Bitrix24, если они присутствуют;
- ограничить очередь и общий deadline, чтобы медленный портал не исчерпал память;
- не считать batch способом обойти лимит: batch разрешать только отдельной оптимизацией с учётом лимитов каждого внутреннего метода;
- при достижении локального лимита ждать внутри deadline, затем возвращать `RateLimitError`;
- отдельно учитывать пагинацию: ограничить `start`/число страниц и не скачивать неограниченный результат.

Не следует зашивать одно универсальное число запросов в код: лимиты Bitrix24 зависят от REST-контекста и могут изменяться. Значения по умолчанию, burst, concurrency и максимальная задержка должны быть конфигурацией с метриками отклонений (без URL и credentials).

## Retries и идемпотентность

Перед запросом рассчитывается deadline. Для временных ошибок использовать ограниченное число попыток (например, 3) с exponential backoff и full jitter, например `min(cap, base * 2^attempt) * random(0..1)`. Итоговая задержка не должна пересечь deadline. `Retry-After` имеет приоритет, но также ограничивается локальным `maxRetryDelay`.

Повторять можно:

- сетевой disconnect/timeout до получения ответа;
- HTTP 408, 429 и выбранные 5xx;
- структурированную ошибку Bitrix24, только если она классифицирована как временная.

Не повторять 400/401/403/404, ошибки схемы/прав доступа, ошибки валидации и неизвестные ошибки по умолчанию. После 401 для OAuth разрешается ровно один последовательный refresh и повтор исходного запроса; если он не удался, вернуть `AuthenticationError`. Webhook после 401 автоматически не перевыпускать.

Для GET/read-методов retry обычно безопасен. Для будущих mutating-методов повтор разрешён только при явно установленной идемпотентности: подтверждённая идемпотентность метода, поддержанный Bitrix24 idempotency key или надёжно установленный факт, что запрос не был принят. Иначе вернуть ошибку неопределённого результата и не создавать дубли. Retry policy не должна превращать `call()` в скрытую очередь.

## Безопасность и эксплуатация

- Credentials и OAuth tokens хранятся в secret manager либо зашифрованном хранилище с ключом вне репозитория; plaintext не попадает в `.env` примеры, git, исключения и дампы.
- Callback OAuth защищён HTTPS, строгим exact-match `redirect_uri`, `state`, TTL и single-use. CORS для callback не нужен; CSRF-защита обязательна.
- Для каждого tenant действует allowlist REST-методов и scopes. По умолчанию запрещены административные и destructive-методы; добавление write-методов требует отдельного review и confirmation workflow.
- Исходящие соединения используют TLS verification и разумные connect/read timeouts. Redirect на другой host запрещён, если явно не разрешён политикой.
- Логи содержат `requestId`, tenant (не секрет), метод, HTTP-код, latency, attempts и классификацию ошибки. Параметры фильтруются по denylist и allowlist; токены, webhook URL, cookies, Authorization и персональные поля не логируются.
- Метрики агрегируются по tenant/методу и не включают CRM payload. Тело ответа ограничивается политикой размера; большие результаты обрабатываются страницами.
- При подозрении на утечку webhook/OAuth credential немедленно отозвать/перевыпустить его, инвалидировать локальную запись и проверить журналы доступа.

## Наблюдаемость и тестовый контракт

До реализации должны быть определены fake `HttpTransport`, clock и sleeper, чтобы тестировать без Bitrix24:

- корректный URL webhook и отсутствие secret в логах;
- OAuth state, одноразовый code, refresh и конкурентный refresh;
- tenant isolation и запрет неизвестного host/method;
- 429 с `Retry-After`, 5xx с backoff и отсутствие retry для 400/403;
- ровно один retry после OAuth 401;
- deadline, max attempts и отсутствие повторов небезопасных mutating-запросов;
- нормализацию Bitrix24 success/error payload и redaction исключений.

Интеграционные тесты с реальным порталом должны быть opt-in, использовать отдельный тестовый tenant и секреты CI, никогда не включаться в обычный PHPUnit запуск и не выполнять destructive методы. Реализация адаптера возможна только после утверждения этого контракта и политики разрешённых методов.
