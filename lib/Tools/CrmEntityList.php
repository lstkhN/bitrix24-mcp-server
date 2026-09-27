<?php

namespace Bitrix24\Mcp\Tools;

/**
 * Read-only список сущностей Bitrix CRM.
 */
class CrmEntityList extends CrmReadTool
{
    public function getName(): string
    {
        return 'crm_entity_list';
    }

    public function getDescription(): string
    {
        return 'Получение списка лидов, сделок, контактов или компаний из CRM Bitrix24. Только чтение.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'enum' => ['lead', 'deal', 'contact', 'company'],
                    'description' => 'Тип CRM сущности',
                ],
                'filter' => [
                    'type' => 'object',
                    'description' => 'Фильтр Bitrix CRM, например {"ASSIGNED_BY_ID": 1}',
                ],
                'select' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Список полей для выборки',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 50,
                ],
            ],
            'required' => ['entity'],
        ];
    }

    public function execute(array $arguments): array
    {
        $entity = $this->validateEntity($arguments);
        $filter = $this->getParam($arguments, 'filter', []);
        if (!is_array($filter)) {
            throw new \InvalidArgumentException('filter must be an object');
        }

        // Никогда не отключаем проверку прав текущего пользователя Bitrix.
        $filter['CHECK_PERMISSIONS'] = 'Y';

        $class = $this->getEntityClass($entity);
        $limit = $this->getLimit($arguments);
        $select = $this->getSelect($arguments);
        $items = [];

        $result = $class::GetListEx(
            ['ID' => 'DESC'],
            $filter,
            false,
            ['nPageSize' => $limit],
            $select
        );

        while ($item = $result->Fetch()) {
            $items[] = $item;
        }

        return [
            'entity' => $entity,
            'items' => $items,
            'count' => count($items),
            'limit' => $limit,
            'truncated' => count($items) >= $limit,
        ];
    }
}
