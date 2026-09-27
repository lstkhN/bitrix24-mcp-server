<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Loader;

/**
 * Общая база для read-only инструментов CRM.
 */
abstract class CrmReadTool extends AbstractTool
{
    protected const ENTITIES = [
        'lead' => \CCrmLead::class,
        'deal' => \CCrmDeal::class,
        'contact' => \CCrmContact::class,
        'company' => \CCrmCompany::class,
    ];

    protected function validateEntity(array $arguments): string
    {
        $this->validateRequired($arguments, ['entity']);
        $entity = strtolower((string)$arguments['entity']);

        if (!isset(self::ENTITIES[$entity])) {
            throw new \InvalidArgumentException('Unsupported CRM entity: ' . $entity);
        }

        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('CRM module is not installed');
        }

        return $entity;
    }

    protected function getLimit(array $arguments): int
    {
        $limit = (int)$this->getParam($arguments, 'limit', 50);
        return max(1, min($limit, 100));
    }

    protected function getSelect(array $arguments): array
    {
        $select = $this->getParam($arguments, 'select', ['ID', 'TITLE', 'NAME', 'LAST_NAME']);

        if (!is_array($select)) {
            throw new \InvalidArgumentException('select must be an array');
        }

        $select = array_values(array_unique(array_filter(
            array_map(static fn($field): string => strtoupper(trim((string)$field)), $select),
            static fn(string $field): bool => preg_match('/^[A-Z][A-Z0-9_\.]*$/', $field) === 1
        )));

        if ($select === []) {
            throw new \InvalidArgumentException('select must contain at least one valid field');
        }

        return array_slice($select, 0, 100);
    }

    protected function getEntityClass(string $entity): string
    {
        return self::ENTITIES[$entity];
    }
}
