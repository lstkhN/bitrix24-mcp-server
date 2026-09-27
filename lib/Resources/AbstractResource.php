<?php

namespace Bitrix24\Mcp\Resources;

/**
 * Базовый класс для MCP ресурсов
 */
abstract class AbstractResource
{
    /**
     * Получить URI ресурса
     */
    abstract public function getUri(): string;

    /**
     * Получить имя ресурса
     */
    abstract public function getName(): string;

    /**
     * Получить описание ресурса
     */
    abstract public function getDescription(): string;

    /**
     * Получить MIME тип ресурса
     */
    abstract public function getMimeType(): string;

    /**
     * Прочитать содержимое ресурса
     * @param array $params Параметры чтения
     * @return string Содержимое ресурса
     */
    abstract public function read(array $params = []): string;
}
