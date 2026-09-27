<?php

namespace Bitrix24\Mcp\Tools;

/**
 * Базовый класс для MCP инструментов
 */
abstract class AbstractTool
{
    /**
     * Получить имя инструмента
     */
    abstract public function getName(): string;

    /**
     * Получить описание инструмента
     */
    abstract public function getDescription(): string;

    /**
     * Получить JSON схему входных параметров
     */
    abstract public function getInputSchema(): array;

    /**
     * Выполнить инструмент
     * @param array $arguments Входные параметры
     * @return array Результат выполнения
     */
    abstract public function execute(array $arguments): array;

    /**
     * Валидация обязательных параметров
     */
    protected function validateRequired(array $arguments, array $required): void
    {
        foreach ($required as $param) {
            if (!isset($arguments[$param])) {
                throw new \InvalidArgumentException("Required parameter '$param' is missing");
            }
        }
    }

    /**
     * Получить значение параметра с дефолтом
     */
    protected function getParam(array $arguments, string $key, $default = null)
    {
        return $arguments[$key] ?? $default;
    }
}
