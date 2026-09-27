<?php

namespace Bitrix24\Mcp\Transport;

/**
 * Stdio транспорт для MCP (используется для SSH подключений)
 * Читает JSON-RPC запросы из stdin, отправляет ответы в stdout
 */
class StdioTransport extends AbstractTransport
{
    private $stdin;
    private $stdout;

    public function __construct()
    {
        $this->stdin = STDIN;
        $this->stdout = STDOUT;

        // Отключаем буферизацию для немедленной отправки
        stream_set_blocking($this->stdin, true);
        stream_set_blocking($this->stdout, true);
    }

    /**
     * Получение запроса из stdin
     */
    public function receive(): ?array
    {
        $line = fgets($this->stdin);

        if ($line === false || feof($this->stdin)) {
            return null;
        }

        $line = trim($line);

        if (empty($line)) {
            return $this->receive(); // Пропускаем пустые строки
        }

        return $this->decodeJson($line);
    }

    /**
     * Отправка ответа в stdout
     */
    public function send(array $response): void
    {
        $json = $this->encodeJson($response);
        fwrite($this->stdout, $json . "\n");
        fflush($this->stdout);
    }
}
