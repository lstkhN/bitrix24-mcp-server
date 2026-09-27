<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Loader;

/**
 * Read-only список CRM-активностей с учётом прав текущего пользователя.
 */
class CrmActivityList extends AbstractTool
{
    // Стабильные идентификаторы CCrmOwnerType для стандартных CRM-сущностей.
    private const OWNER_TYPES = [
        'lead' => 1,
        'deal' => 2,
        'contact' => 3,
        'company' => 4,
    ];

    public function getName(): string
    {
        return 'crm_activity_list';
    }

    public function getDescription(): string
    {
        return 'Получение CRM-активностей Bitrix24 по сущности или фильтру. '
            . 'Только чтение, права текущего пользователя сохраняются.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'enum' => ['lead', 'deal', 'contact', 'company'],
                    'description' => 'Тип владельца активности',
                ],
                'owner_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'ID владельца активности',
                ],
                'filter' => [
                    'type' => 'object',
                    'description' => 'Дополнительный фильтр CCrmActivity',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 50,
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('CRM module is not installed');
        }

        $filter = $this->getParam($arguments, 'filter', []);
        if (!is_array($filter)) {
            throw new \InvalidArgumentException('filter must be an object');
        }

        if (isset($arguments['entity'])) {
            $entity = strtolower((string)$arguments['entity']);
            if (!isset(self::OWNER_TYPES[$entity])) {
                throw new \InvalidArgumentException('Unsupported CRM entity: ' . $entity);
            }
            $filter['OWNER_TYPE_ID'] = self::OWNER_TYPES[$entity];
        }

        if (isset($arguments['owner_id'])) {
            $ownerId = (int)$arguments['owner_id'];
            if ($ownerId < 1) {
                throw new \InvalidArgumentException('owner_id must be a positive integer');
            }
            $filter['OWNER_ID'] = $ownerId;
        }

        $filter['CHECK_PERMISSIONS'] = 'Y';
        $limit = (int)$this->getParam($arguments, 'limit', 50);
        $limit = max(1, min($limit, 100));
        $items = [];

        $result = \CCrmActivity::GetListEx(
            ['ID' => 'DESC'],
            $filter,
            false,
            ['nPageSize' => $limit],
            []
        );

        while ($item = $result->Fetch()) {
            $items[] = $item;
        }

        return [
            'items' => $items,
            'count' => count($items),
            'limit' => $limit,
            'truncated' => count($items) >= $limit,
        ];
    }
}
