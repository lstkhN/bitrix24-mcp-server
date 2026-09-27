<?php

namespace Bitrix24\Mcp\Transport;

/**
 * Базовый класс для транспортов MCP
 */
abstract class AbstractTransport
{
    /**
     * Получение запроса от клиента
     * @return array|null Декодированный JSON-RPC запрос или null при завершении
     */
    abstract public function receive(): ?array;

    /**
     * Отправка ответа клиенту
     * @param array $response JSON-RPC ответ
     */
    abstract public function send(array $response): void;

    /**
     * Декодирование JSON с обработкой ошибок
     */
    protected function decodeJson(string $json, int $maxDepth = 512): ?array
    {
        $data = json_decode($json, true, $maxDepth);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Кодирование в JSON с обработкой ошибок
     */
    protected function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON encode error: ' . json_last_error_msg());
        }

        return $json;
    }
}
