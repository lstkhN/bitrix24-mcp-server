<?php

namespace Bitrix24\Mcp\Tools;

/**
 * Read-only получение одной сущности Bitrix CRM.
 */
class CrmEntityGet extends CrmReadTool
{
    public function getName(): string
    {
        return 'crm_entity_get';
    }

    public function getDescription(): string
    {
        return 'Получение лида, сделки, контакта или компании по ID из CRM Bitrix24. Только чтение.';
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
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'ID сущности',
                ],
                'select' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Список полей для выборки',
                ],
            ],
            'required' => ['entity', 'id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $entity = $this->validateEntity($arguments);
        $id = (int)$arguments['id'];
        if ($id < 1) {
            throw new \InvalidArgumentException('id must be a positive integer');
        }

        $class = $this->getEntityClass($entity);
        $select = $this->getSelect($arguments);
        $item = $class::GetByID($id, true, $select);

        if (!is_array($item) || $item === []) {
            throw new \RuntimeException(sprintf('%s with ID %d was not found', $entity, $id));
        }

        return [
            'entity' => $entity,
            'id' => $id,
            'item' => $item,
        ];
    }
}
