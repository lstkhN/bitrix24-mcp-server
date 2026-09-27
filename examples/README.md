# Примеры stdio-конфигурации

- [`claude-code.mcp.json`](claude-code.mcp.json) — содержимое `.mcp.json` для Claude Code.
- [`cursor.mcp.json`](cursor.mcp.json) — содержимое `.cursor/mcp.json` для Cursor.

Оба файла используют stdio-транспорт через SSH. Перед использованием замените
`bitrix@your-server.example` и путь к `server.php` на значения вашего **тестового
или рабочего** Bitrix-окружения. В репозитории нет токенов или приватных ключей;
SSH должен быть настроен отдельно (например, через `~/.ssh/config`). Не добавляйте
секреты в эти JSON-файлы.

## Безопасная проверка stdio

Проверка не вызывает инструменты и не читает CRM/файлы: она отправляет только
`initialize` и `tools/list`, а затем закрывает stdin. Выполняйте её на staging или
в окружении с минимальными правами SSH-пользователя:

```bash
printf '%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"stdio-smoke-test","version":"1.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
| ssh bitrix@your-server.example \
    'php /path/to/bitrix/local/modules/bitrix24.mcp/cli/server.php'
```

Ожидаются две строки JSON-RPC с `id` `1` и `2`: ответ `initialize` с `serverInfo`
и ответ `tools/list` с массивом `tools`. Команда намеренно не выполняет `tools/call`.
Ошибки запуска и логи идут в stderr; JSON-ответы — в stdout. Если сервер доступен
только через SSH-алиас, замените `bitrix@your-server.example` на этот алиас.
