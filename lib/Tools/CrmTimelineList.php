<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Loader;

/**
 * Read-only доступ к Timeline CRM через D7 API.
 */
class CrmTimelineList extends AbstractTool
{
    private const ENTITIES = [
        'lead' => ['type' => 1, 'class' => \CCrmLead::class],
        'deal' => ['type' => 2, 'class' => \CCrmDeal::class],
        'contact' => ['type' => 3, 'class' => \CCrmContact::class],
        'company' => ['type' => 4, 'class' => \CCrmCompany::class],
    ];

    public function getName(): string
    {
        return 'crm_timeline_list';
    }

    public function getDescription(): string
    {
        return 'Получение записей Timeline CRM для лида, сделки, контакта или компании. Только чтение.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'enum' => ['lead', 'deal', 'contact', 'company'],
                ],
                'owner_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 50,
                ],
            ],
            'required' => ['entity', 'owner_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('CRM module is not installed');
        }

        $entity = strtolower((string)($arguments['entity'] ?? ''));
        $ownerId = (int)($arguments['owner_id'] ?? 0);
        if (!isset(self::ENTITIES[$entity])) {
            throw new \InvalidArgumentException('Unsupported CRM entity: ' . $entity);
        }
        if ($ownerId < 1) {
            throw new \InvalidArgumentException('owner_id must be a positive integer');
        }

        // Проверяем права чтения через штатный CRM-класс до обращения к Timeline ORM.
        $owner = self::ENTITIES[$entity];
        $ownerRecord = $owner['class']::GetByID($ownerId, true, ['ID']);
        if (!is_array($ownerRecord) || $ownerRecord === []) {
            throw new \RuntimeException(sprintf('%s with ID %d was not found or is not accessible', $entity, $ownerId));
        }

        $timelineClass = '\\Bitrix\\Crm\\Timeline\\Entity\\TimelineTable';
        if (!class_exists($timelineClass) || !method_exists($timelineClass, 'getList')) {
            throw new \RuntimeException('Bitrix Timeline D7 API is unavailable in this installation');
        }

        $limit = max(1, min((int)($arguments['limit'] ?? 50), 100));
        $result = $timelineClass::getList([
            'select' => ['*'],
            'filter' => [
                '=ASSOCIATED_ENTITY_TYPE_ID' => $owner['type'],
                '=ASSOCIATED_ENTITY_ID' => $ownerId,
            ],
            'order' => ['ID' => 'DESC'],
            'limit' => $limit,
        ]);

        $items = [];
        while ($item = $result->fetch()) {
            $items[] = $item;
        }

        return [
            'entity' => $entity,
            'owner_id' => $ownerId,
            'items' => $items,
            'count' => count($items),
            'limit' => $limit,
            'truncated' => count($items) >= $limit,
        ];
    }
}
