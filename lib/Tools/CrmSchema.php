<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Loader;
use Bitrix\Crm\LeadTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\QuoteTable;

/**
 * Инструмент для получения схемы CRM сущностей
 */
class CrmSchema extends AbstractTool
{
    private const ENTITY_MAP = [
        'lead' => [
            'class' => LeadTable::class,
            'table' => 'b_crm_lead',
            'name' => 'Лид',
        ],
        'deal' => [
            'class' => DealTable::class,
            'table' => 'b_crm_deal',
            'name' => 'Сделка',
        ],
        'contact' => [
            'class' => ContactTable::class,
            'table' => 'b_crm_contact',
            'name' => 'Контакт',
        ],
        'company' => [
            'class' => CompanyTable::class,
            'table' => 'b_crm_company',
            'name' => 'Компания',
        ],
        'quote' => [
            'class' => QuoteTable::class,
            'table' => 'b_crm_quote',
            'name' => 'Предложение',
        ],
    ];

    public function getName(): string
    {
        return 'crm_schema';
    }

    public function getDescription(): string
    {
        return 'Получение схемы CRM сущностей (Lead, Deal, Contact, Company, Quote) с полями, '
            . 'пользовательскими полями и статусами.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'enum' => ['lead', 'deal', 'contact', 'company', 'quote'],
                    'description' => 'Тип CRM сущности',
                ],
                'include_fields' => [
                    'type' => 'boolean',
                    'description' => 'Включать стандартные поля (по умолчанию true)',
                    'default' => true,
                ],
                'include_uf_fields' => [
                    'type' => 'boolean',
                    'description' => 'Включать пользовательские поля (по умолчанию true)',
                    'default' => true,
                ],
            ],
            'required' => ['entity'],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->validateRequired($arguments, ['entity']);

        $entity = strtolower($arguments['entity']);
        $includeFields = $this->getParam($arguments, 'include_fields', true);
        $includeUfFields = $this->getParam($arguments, 'include_uf_fields', true);

        if (!isset(self::ENTITY_MAP[$entity])) {
            throw new \InvalidArgumentException("Unknown CRM entity: $entity");
        }

        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('CRM module is not installed');
        }

        $entityInfo = self::ENTITY_MAP[$entity];
        $tableClass = $entityInfo['class'];

        $result = [
            'entity' => $entity,
            'entity_name' => $entityInfo['name'],
            'table' => $entityInfo['table'],
            'class' => $tableClass,
        ];

        if ($includeFields) {
            try {
                $result['fields'] = $this->getFields($tableClass);
            } catch (\Throwable $e) {
                $result['fields'] = [];
                $result['fields_error'] = $e->getMessage();
            }
        }

        if ($includeUfFields) {
            try {
                $result['uf_fields'] = $this->getUserFields($entity);
            } catch (\Throwable $e) {
                $result['uf_fields'] = [];
                $result['uf_fields_error'] = $e->getMessage();
            }
        }

        // Добавляем статусы для сущностей, у которых они есть
        if (in_array($entity, ['lead', 'deal'])) {
            try {
                $result['statuses'] = $this->getStatuses($entity);
            } catch (\Throwable $e) {
                $result['statuses'] = [];
                $result['statuses_error'] = $e->getMessage();
            }
        }

        // Добавляем направления для лидов и сделок
        if (in_array($entity, ['lead', 'deal'])) {
            try {
                $result['categories'] = $this->getCategories($entity);
            } catch (\Throwable $e) {
                $result['categories'] = [];
                $result['categories_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Получение полей из D7 ORM
     */
    private function getFields(string $tableClass): array
    {
        $map = $tableClass::getMap();
        $fields = [];

        foreach ($map as $fieldName => $field) {
            $fieldInfo = [
                'name' => $fieldName,
                'type' => $this->getFieldType($field),
            ];

            // Добавляем дополнительную информацию
            if (method_exists($field, 'getTitle') && $field->getTitle()) {
                $fieldInfo['title'] = $field->getTitle();
            }

            if (method_exists($field, 'isRequired')) {
                $fieldInfo['required'] = $field->isRequired();
            }

            if (method_exists($field, 'isAutocomplete')) {
                $fieldInfo['autocomplete'] = $field->isAutocomplete();
            }

            $fields[] = $fieldInfo;
        }

        return $fields;
    }

    /**
     * Определение типа поля
     */
    private function getFieldType($field): string
    {
        $className = get_class($field);
        $parts = explode('\\', $className);
        $type = end($parts);

        // Убираем "Field" из конца
        return str_replace('Field', '', $type);
    }

    /**
     * Получение пользовательских полей
     */
    private function getUserFields(string $entity): array
    {
        $entityTypeId = $this->getEntityTypeId($entity);
        $userType = new \CUserTypeEntity();

        $result = $userType->GetList(
            ['SORT' => 'ASC'],
            ['ENTITY_ID' => 'CRM_' . strtoupper($entity)]
        );

        $fields = [];

        while ($field = $result->Fetch()) {
            $fields[] = [
                'id' => $field['ID'],
                'field_name' => $field['FIELD_NAME'],
                'user_type_id' => $field['USER_TYPE_ID'],
                'xml_id' => $field['XML_ID'],
                'sort' => $field['SORT'],
                'multiple' => $field['MULTIPLE'] === 'Y',
                'mandatory' => $field['MANDATORY'] === 'Y',
                'show_filter' => $field['SHOW_FILTER'],
                'show_in_list' => $field['SHOW_IN_LIST'] === 'Y',
                'edit_in_list' => $field['EDIT_IN_LIST'] === 'Y',
                'is_searchable' => $field['IS_SEARCHABLE'] === 'Y',
                'settings' => $field['SETTINGS'] ?? [],
            ];
        }

        return $fields;
    }

    /**
     * Получение статусов
     */
    private function getStatuses(string $entity): array
    {
        $statusEntityId = $entity === 'lead' ? 'STATUS' : 'DEAL_STAGE';

        $result = \CCrmStatus::GetList(
            ['SORT' => 'ASC'],
            ['ENTITY_ID' => $statusEntityId]
        );

        $statuses = [];

        while ($status = $result->Fetch()) {
            $statuses[] = [
                'id' => $status['STATUS_ID'],
                'name' => $status['NAME'],
                'sort' => $status['SORT'],
                'system' => $status['SYSTEM'] === 'Y',
            ];
        }

        return $statuses;
    }

    /**
     * Получение направлений (категорий)
     */
    private function getCategories(string $entity): array
    {
        if ($entity === 'lead') {
            // У лидов нет категорий в стандартной конфигурации
            return [];
        }

        if ($entity === 'deal') {
            // Используем D7 API вместо устаревшего CCrmDealCategory
            $categories = [];

            // Пробуем D7 Category
            if (class_exists('\Bitrix\Crm\Category\DealCategory')) {
                $categoryList = \Bitrix\Crm\Category\DealCategory::getAll(true);
                foreach ($categoryList as $category) {
                    $categories[] = [
                        'id' => $category['ID'],
                        'name' => $category['NAME'],
                        'sort' => $category['SORT'] ?? 0,
                        'is_default' => ($category['IS_DEFAULT'] ?? 'N') === 'Y',
                    ];
                }
                return $categories;
            }

            // Fallback на старый класс
            if (class_exists('\CCrmDealCategory')) {
                $result = \CCrmDealCategory::GetList(
                    ['SORT' => 'ASC'],
                    []
                );

                while ($category = $result->Fetch()) {
                    $categories[] = [
                        'id' => $category['ID'],
                        'name' => $category['NAME'],
                        'sort' => $category['SORT'],
                        'is_default' => $category['IS_DEFAULT'] === 'Y',
                    ];
                }
                return $categories;
            }

            return $categories;
        }

        return [];
    }

    /**
     * Получение ID типа сущности
     */
    private function getEntityTypeId(string $entity): int
    {
        return match ($entity) {
            'lead' => \CCrmOwnerType::Lead,
            'deal' => \CCrmOwnerType::Deal,
            'contact' => \CCrmOwnerType::Contact,
            'company' => \CCrmOwnerType::Company,
            'quote' => \CCrmOwnerType::Quote,
            default => 0,
        };
    }
}
