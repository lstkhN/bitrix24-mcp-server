# GitHub Release Readiness Review

Дата: 2025-09-25
Проект: bitrix24.mcp — MCP Server for Bitrix24 Box
Ветка: master (1 пустой initial commit, все файлы untracked)

> Это исторический review-снимок. Актуальные инструкции по установке и
> использованию находятся в [`README.md`](../README.md).

---

## 0. Публичное позиционирование релиза

**Короткое название:** **Bitrix24 MCP**.

**Назначение:** read-only MCP-сервер для Bitrix24 Box (On-Premise). Он даёт
доверенному MCP-клиенту доступ к инспекции CRM, кода, схемы БД и структуры
проекта через SSH stdio или HTTP с Bearer-токеном. Это не средство безопасной
публикации Bitrix24 в интернет и не изолированная граница между арендаторами.

### Поддерживаемая платформа и статус функций

| Область | Статус в этом релизе |
|---|---|
| Bitrix24 Box с D7 | ✅ Основной и поддерживаемый сценарий: модуль работает на сервере Bitrix24. |
| MCP CRM для Box | ✅ Только чтение: списки, карточки, активности и timeline; операций записи нет. |
| Bitrix24 Cloud как MCP-сервер | ❌ Не поддерживается: Cloud MCP endpoint и Cloud CRM tools не поставляются. |
| Cloud REST через inbound webhook | ⚠️ Есть отдельная библиотечная основа для ограниченных вызовов; это не готовая Cloud MCP-интеграция. |
| OAuth authorization-code flow, callback, exchange и refresh | ❌ Не интегрированы в готовый к развёртыванию поток. |
| OAuth connection/token persistence | ⚠️ Есть строительные блоки хранения и защиты, но нет сквозного жизненного цикла OAuth. |

### Установка, использование и эксплуатационные границы

1. Устанавливайте только в Box как `local/modules/bitrix24.mcp/`, затем выполните
   `composer install --no-dev --prefer-dist --optimize-autoloader` и установите
   модуль в административном интерфейсе Bitrix24.
2. Для разработки предпочтителен SSH stdio; HTTP требует HTTPS, Bearer-токен и
   сетевое ограничение доступа. После настройки клиента проверьте `tools/list`.
3. Токен, webhook code и OAuth credentials — секреты: не добавляйте их в Git,
   логи, чаты или обычный CI.
4. `sql_query` — не SQL sandbox: blacklist может пропустить запросы с побочным
   эффектом. `file_reader` имеет allowlist путей, но не лимитирует размер файла.
   CORS `*` и Bearer-токен сами по себе не заменяют сетевую изоляцию. Права CRM
   соответствуют identity PHP-процесса Bitrix; процесс с правами администратора
   видит администраторские данные.

### Проверки и лицензия

`composer test` запускает только герметичные unit-тесты. Живые проверки Box и
Cloud webhook отключены в обычном CI и требуют явного локального opt-in:

```bash
BITRIX24_RUN_INTEGRATION=1 composer test:integration
```

Интеграционные проверки не выполняют запись; Cloud-проверка читает `profile`
через inbound webhook и не проверяет OAuth либо Cloud MCP CRM. Проект
распространяется по [MIT License](../LICENSE), включая отказ от гарантий
"AS IS". Bitrix24, Claude и Cursor — сторонние продукты.

---

## 1. Секреты и_credentials_

### Найдено

| Что | Где | Статус |
|-----|-----|--------|
| Bearer-токен генерируется `random_bytes(32)` | `install/index.php:118`, `cli/generate-config.php:39` | ✅ OK — не хардкод |
| Токен хранится в `Option::get('bitrix24.mcp','auth_token')` | Все HTTP endpoints | ✅ OK — Bitrix Option store |
| `hash_equals()` для сравнения токенов | `public/mcp-endpoint.php`, `lib/Controller/ApiController.php:157`, `lib/Transport/HttpTransport.php:104` | ✅ OK — timing-safe |
| Примеры конфигов содержат `YOUR_TOKEN` / `your-server.example` | `examples/*.json`, `README.md` | ✅ OK — placeholder'ы |
| `unserialize()` из БД в `BizprocReader.php` | `lib/Tools/BizprocReader.php:344-767` | ⚠️ см. ниже |

### Критично

Нет захардкоженных секретов, JWT, приватных ключей или URL с credentials.

### Замечания

- **BizprocReader::unserialize** — используется `@unserialize()` для данных из таблиц `b_bp_template`. Это стандартная практика Bitrix, но если БД скомпрометирована, атакующий может внедрить произвольные PHP-объекты. Рекомендация: заменить на `unserialize($data, ['allowed_classes' => false])` (PHP 7+). Это не блокер для релиза, но повышает defense-in-depth.

---

## 2. .gitignore — покрытие

### Текущее состояние

| Паттерн | Покрывает | Проблемы |
|---------|-----------|----------|
| `/vendor/` | vendor/autoload.php, vendor/bin/, vendor/monolog/ и т.д. | ✅ |
| `composer.lock` | корневой и вложенные | ✅ OK для библиотеки |
| `.phpunit.result.cache` | корневой кэш | ✅ |
| `/.mcp.json`, `/.cursorrules`, `/CLAUDE.md` | локальные конфиги | ✅ |
| `/.settings.php` | настройки | ✅ |
| `/tests/_output/`, `.phpunit.cache/` | тестовые артефакты | ✅ |
| `*.log`, `/local/logs/*.log` | логи | ✅ |
| `/cache/` | кэш | ✅ |

### Проблемы

| Проблема | Серьёзность | Описание |
|----------|-------------|----------|
| **`/var/` не в .gitignore** | ⚠️ Medium | Директория `var/` содержит `phpstan/cache/`. При `git add .` попадёт в коммит. Добавить `/var/` в .gitignore. |
| **`composer.lock` — паттерн без `/`** | ℹ️ Low | Паттерн `composer.lock` (строка 4) совпадёт с любым `composer.lock` в поддиректориях. Для модуля это корректно, но если когда-нибудь появится вложенный `composer.json`, это может быть неожиданным поведением. Не критично. |
| **`tests/_output/` — устаревший паттерн** | ℹ️ Low | PHPUnit 10+ использует `.phpunit.cache/`, который уже покрыт. `_output` — наследиеPHPUnit 9. Можно оставить без вреда. |

### Рекомендация

Добавить в `.gitignore`:

```
# PHPStan cache
/var/
```

---

## 3. vendor/cache/logs на диске vs в git

| Что | На диске | В git | Статус |
|-----|----------|-------|--------|
| `vendor/` | ✅ есть | ❌ нет (gitignored) | ✅ OK |
| `var/phpstan/cache/` | ✅ есть | ❌ нет (untracked) | ⚠️ Нужен .gitignore |
| `vendor/bin/.phpunit.result.cache` | ✅ есть | ❌ нет (gitignored через `/vendor/`) | ✅ OK |
| `composer.lock` | ✅ есть | ❌ нет (gitignored) | ✅ OK для модуля |
| Логи `*.log` | нет на диске | ❌ нет | ✅ OK |

Файлы не будут закоммичены, если `.gitignore` будет применён до `git add .`.

---

## 4. Публичные README claims vs реализация

### Проверенные утверждения

| README claim | Реальность | Статус |
|-------------|-----------|--------|
| "инструменты не предоставляют операций записи в CRM" | Все CRM-инструменты используют только `list`, `get` API; `crm.entity.add/update/delete` не вызываются | ✅ Верно |
| "`sql_query` принимает только SELECT" | `validateQuery()` проверяет `^\s*SELECT\s+` и блокирует DML-слова | ⚠️ Верно, но см. ниже |
| "`file_reader` не записывает файлы и ограничен разрешёнными каталогами" | `ALLOWED_PATHS` + `FORBIDDEN_FILES` + `realpath()` проверка | ✅ Верно |
| "CORS `*` не является авторизацией" | README явно предупреждает о wildcard CORS | ✅ Верно — честное описание |
| "Токен не следует коммитить или передавать в чат" | README: строка 25 | ✅ Верно |
| "Установка создаёт случайный Bearer-токен" | `generateToken()` = `bin2hex(random_bytes(32))` | ✅ Верно |
| "Списки CRM ограничены 100 записями" | `CrmEntityList` + `CrmEntityGet` — да, `LIMIT 100` | ✅ Верно |
| "`sql_query` ограничивается 500 строками и 30 секундами" | `MAX_ROWS=500`, `MAX_EXECUTION_TIME=30` | ✅ Верно |
| "path traversal через `..` запрещён" | `validatePath()` проверяет `strpos($path, '..')` | ✅ Верно |
| "`file_reader` ограничен `/local/`, `/bitrix/modules/`..." | `ALLOWED_PATHS` массив | ✅ Верно |
| "Явно запрещены `dbconn.php`, `.env` и `local/.env`" | `FORBIDDEN_FILES` массив | ✅ Верно |

### Завышенные / неточные claims

| Claim | Проблема | Рекомендация |
|-------|----------|--------------|
| "Безопасные SELECT запросы" (описание `sql_query`) | Blacklist-валидация пропускает `SELECT ... FOR UPDATE`, `GET_LOCK()`, `SLEEP()`, `BENCHMARK()`. Это не "безопасные", а "SELECT без явного DML". | Уточнить в README: "запросы без модифицирующих операций" вместо "безопасные SELECT"; описать ограничения в security.md |
| " Rights Bitrix сохраняются" (CRM tools) | Верно, но если MCP запущен от имени администратора — все CRM-данные доступны. README не уточняет, от чьего имени работает MCP. | Добавить: "MCP наследует права пользователя Bitrix, от имени которого запущен PHP-процесс" |

### Точные предупреждения в README

README **корректно** предупреждает:
- CORS `*` не является авторизацией (строка 138-139)
- Не публикуйте endpoint без токена и сетевого ограничения
- Логи могут раскрыть больше, чем API (строка 147)
- MCP-клиент может отправить инструкции для чтения данных (строка 149)
- Не используйте `php cli/server.php` до установки модуля

---

## 5. Безопасность — сводка по security-review.md

Существующий `docs/security-review.md` (дата: 2025-02-14) документирует 6 findings:

| ID | Что | Severity | Статус для релиза |
|----|-----|----------|-------------------|
| SEC-01 | CORS `*` на привилегированном API | High | ⚠️ Документировано, README предупреждает. Для open-source модуля — приемлемо с оговорками. |
| SEC-02 | SQL blacklist пропускает side-effect запросы | High | ⚠️ Документировано. Требует отдельной БД-учётки и SQL parser для полного исправления. |
| SEC-03 | FileReader без лимита размера файла | Medium | ⚠️ Документировано. Не исправлено. |
| SEC-04 | TOCTOU для symlink в FileReader | Medium | ⚠️ Документировано. Условный риск. |
| SEC-05 | Fake session lifecycle | Medium | ⚠️ Документировано. Session ID генерируется, но не хранится. |
| SEC-06 | Exception messages наружу | Low | ⚠️ Документировано. `$e->getMessage()` в JSON-RPC error. |

**Все findings задокументированы в security-review.md и security.md.** Для GitHub-релиза это приемлемо при условии, что:
1. README содержит предупреждения (есть)
2. security.md объясняет модель угроз (есть)
3. security-review.md описывает limitations (есть)

---

## 6. Код — наблюдения (без изменения PHP)

### Хорошо

- ✅ `hash_equals()` для всех проверок токена
- ✅ `random_bytes(32)` для генерации токена
- ✅ Логирование только размеров request/response, не содержимого
- ✅ Тесты безопасности для `SqlQuery` и `FileReader`
- ✅ PHPStan level 5, PSR-12 через PHPCS
- ✅ Composer scripts: `composer quality` запускает оба анализатора
- ✅ `not_check_permissions = true` + собственная Bearer-проверка — корректная модель для Bitrix-модуля
- ✅ Примеры конфигов не содержат секретов
- ✅ Описание транспортов (stdio/HTTP) и их ограничений

### Замечания

| Что | Файл | Описание |
|-----|------|----------|
| `error_log()` fallback | `lib/Server.php:365-370` | Используется если файловый логгер недоступен. Пригодится в dev, но в production лучше guiding на мониторинг. Не блокер. |
| `print_r()` в CLI-скрипте | `cli/test-connection.php:116` | CLI-only, не попадёт в HTTP. OK. |
| `@` error suppression | `BizprocReader.php`, `mcp-endpoint.php` | `@mkdir`, `@file_put_contents`, `@unserialize` — подавление ошибок. Для `mkdir`/`file_put_contents` — стандартная практика Bitrix. Для `unserialize` — см. рекомендацию выше. |
| Лог-файл с world-readable правами | `mcp-endpoint.php:49` | `@mkdir($logDir, 0775)` — директория доступна группе. Убедитесь, что группа web-сервера. |

---

## 7. Структура релиза — что должно быть в git

### Сейчас (все файлы untracked)

```
bitrix24-mcp-server/
├── .gitignore          ← untracked (нужен для first commit)
├── README.md           ← untracked
├── composer.json       ← untracked
├── include.php         ← untracked
├── phpcs.xml.dist      ← untracked
├── phpstan.neon.dist   ← untracked
├── docs/roadmap.md     ← публичный roadmap проекта
├── cli/                ← untracked
├── docs/               ← untracked
├── examples/           ← untracked
├── install/            ← untracked
├── lib/                ← untracked
├── public/             ← untracked
├── routes/             ← untracked
├── tests/              ← untracked
├── var/                ← ⚠️ НЕ коммитить (phpstan cache)
├── vendor/             ← ⚠️ НЕ коммитить (gitignored)
└── composer.lock       ← ⚠️ НЕ коммитить (gitignored)
```

### Рекомендация для первого коммита

```bash
git add .gitignore README.md composer.json include.php \
        phpcs.xml.dist phpstan.neon.dist \
        cli/ docs/ examples/ install/ lib/ public/ routes/ tests/
git commit -m "Initial release: MCP Server for Bitrix24"
```

**НЕ добавлять:** `var/`, `vendor/`, `composer.lock`, `*.log`, `.phpunit.result.cache`.

---

## 8. Итоговая оценка

### Готовность к GitHub-релизу: ✅ С замечаниями

**Сильные стороны:**
- Честная документация.security-review.md и security.md описываютlimitations без приукрашивания
- README содержит конкретные предупреждения о CORS, токенах и моделях доступа
- Нет секретов в коде или примерах
- Тесты безопасности покрывают path traversal и SQL-валидацию
- Статический анализ (PHPStan, PHPCS) настроен

**Требует исправления перед публикацией:**
1. Добавить `/var/` в `.gitignore`
2. Roadmap уже перенесён в `docs/roadmap.md` для публичной версии проекта

**Рекомендуется (не блокер):**
- Заменить `@unserialize()` на `unserialize($data, ['allowed_classes' => false])` в `BizprocReader.php`
- Уточнить "безопасные SELECT" → "SELECT без модифицирующих операций" в описании инструмента
- Добавить в README уточнение, что MCP наследует права PHP-процесса Bitrix
- Рассмотреть увеличение PHPStan level до 6-7

