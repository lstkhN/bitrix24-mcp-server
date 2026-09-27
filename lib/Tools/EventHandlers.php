<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Application;

/**
 * Инструмент для получения зарегистрированных обработчиков событий
 */
class EventHandlers extends AbstractTool
{
    public function getName(): string
    {
        return 'event_handlers';
    }

    public function getDescription(): string
    {
        return 'Получение списка зарегистрированных обработчиков событий Битрикс из таблицы b_module_to_module.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'module' => [
                    'type' => 'string',
                    'description' => 'Фильтр по модулю (FROM_MODULE_ID)',
                    'default' => '',
                ],
                'event' => [
                    'type' => 'string',
                    'description' => 'Фильтр по событию (MESSAGE_ID)',
                    'default' => '',
                ],
                'to_module' => [
                    'type' => 'string',
                    'description' => 'Фильтр по модулю-обработчику (TO_MODULE_ID)',
                    'default' => '',
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $moduleFilter = $this->getParam($arguments, 'module', '');
        $eventFilter = $this->getParam($arguments, 'event', '');
        $toModuleFilter = $this->getParam($arguments, 'to_module', '');

        $connection = Application::getConnection();

        // Строим SQL запрос
        $sql = "SELECT
                    FROM_MODULE_ID as from_module,
                    MESSAGE_ID as event,
                    TO_MODULE_ID as to_module,
                    TO_PATH as to_path,
                    TO_CLASS as to_class,
                    TO_METHOD as to_method,
                    TO_METHOD_ARG as to_method_arg,
                    SORT as sort
                FROM b_module_to_module
                WHERE 1=1";

        $params = [];

        if (!empty($moduleFilter)) {
            $sql .= " AND FROM_MODULE_ID = ?";
            $params[] = $moduleFilter;
        }

        if (!empty($eventFilter)) {
            $sql .= " AND MESSAGE_ID = ?";
            $params[] = $eventFilter;
        }

        if (!empty($toModuleFilter)) {
            $sql .= " AND TO_MODULE_ID = ?";
            $params[] = $toModuleFilter;
        }

        $sql .= " ORDER BY FROM_MODULE_ID, MESSAGE_ID, SORT";

        $result = $connection->query($sql, $params);

        $handlers = [];

        while ($row = $result->fetch()) {
            $handlers[] = [
                'from_module' => $row['from_module'],
                'event' => $row['event'],
                'to_module' => $row['to_module'],
                'to_path' => $row['to_path'] ?: null,
                'to_class' => $row['to_class'] ?: null,
                'to_method' => $row['to_method'] ?: null,
                'to_method_arg' => $row['to_method_arg'] ?: null,
                'sort' => (int)$row['sort'],
            ];
        }

        return [
            'handlers' => $handlers,
            'total' => count($handlers),
            'filters' => array_filter([
                'module' => $moduleFilter ?: null,
                'event' => $eventFilter ?: null,
                'to_module' => $toModuleFilter ?: null,
            ]),
        ];
    }
}
