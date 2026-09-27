<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;

/**
 * Инструмент для чтения бизнес-процессов Битрикс
 */
class BizprocReader extends AbstractTool
{
    private const MAX_RESULTS = 100;

    public function getName(): string
    {
        return 'bizproc_reader';
    }

    public function getDescription(): string
    {
        return 'Чтение бизнес-процессов Битрикс: шаблоны, экземпляры, задания, история. '
            . 'Позволяет просматривать структуру БП, активные workflow и их состояние.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'templates', 'instances', 'tasks', 'history', 'template_detail', 'activities',
                        'scripts', 'script_detail', 'robots', 'triggers', 'link_scan',
                    ],
                    'description' => 'Действие: templates - шаблоны БП, scripts - сценарии, '
                        . 'script_detail - детали сценария, robots - роботы автоматизации, '
                        . 'triggers - триггеры, instances - запущенные БП, tasks - задания, '
                        . 'history - история, template_detail - детали шаблона, activities - доступные действия, '
                        . 'link_scan - поиск {{... host url ...}} и http(s) URL во всех активных шаблонах',
                ],
                'template_id' => [
                    'type' => 'integer',
                    'description' => 'ID шаблона БП (для template_detail)',
                ],
                'script_id' => [
                    'type' => 'integer',
                    'description' => 'ID сценария (для script_detail)',
                ],
                'document_type' => [
                    'type' => 'string',
                    'description' => 'Тип документа для фильтрации (например: DEAL, LEAD, CONTACT)',
                ],
                'module_id' => [
                    'type' => 'string',
                    'description' => 'ID модуля (например: crm, lists, disk)',
                ],
                'workflow_id' => [
                    'type' => 'string',
                    'description' => 'ID экземпляра workflow',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'completed', 'all'],
                    'description' => 'Фильтр по статусу (для instances/tasks)',
                    'default' => 'all',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Ограничение количества результатов',
                    'default' => 50,
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->validateRequired($arguments, ['action']);

        if (!Loader::includeModule('bizproc')) {
            throw new \RuntimeException('Module bizproc is not installed');
        }

        $action = $arguments['action'];
        $limit = min($this->getParam($arguments, 'limit', 50), self::MAX_RESULTS);

        return match ($action) {
            'templates' => $this->getTemplates($arguments, $limit),
            'instances' => $this->getInstances($arguments, $limit),
            'tasks' => $this->getTasks($arguments, $limit),
            'history' => $this->getHistory($arguments, $limit),
            'template_detail' => $this->getTemplateDetail($arguments),
            'activities' => $this->getActivities(),
            'scripts' => $this->getScripts($arguments, $limit),
            'script_detail' => $this->getScriptDetail($arguments),
            'robots' => $this->getRobots($arguments, $limit),
            'triggers' => $this->getTriggers($arguments, $limit),
            'link_scan' => $this->getLinkScan(),
            default => throw new \InvalidArgumentException("Unknown action: $action"),
        };
    }

    /**
     * Получение списка шаблонов БП
     */
    private function getTemplates(array $arguments, int $limit): array
    {
        $filter = ['ACTIVE' => 'Y'];

        if ($moduleId = $this->getParam($arguments, 'module_id')) {
            $filter['MODULE_ID'] = $moduleId;
        }

        if ($documentType = $this->getParam($arguments, 'document_type')) {
            $filter['%DOCUMENT_TYPE'] = $documentType;
        }

        $connection = Application::getConnection();

        $sql = "SELECT
                    ID, MODULE_ID, ENTITY, DOCUMENT_TYPE, DOCUMENT_STATUS,
                    AUTO_EXECUTE, NAME, DESCRIPTION, MODIFIED, USER_ID,
                    ACTIVE, IS_SYSTEM, SORT, TYPE, SYSTEM_CODE
                FROM b_bp_workflow_template
                WHERE ACTIVE = 'Y'";

        if (isset($filter['MODULE_ID'])) {
            $sql .= " AND MODULE_ID = '" . $connection->getSqlHelper()->forSql($filter['MODULE_ID']) . "'";
        }

        if (isset($filter['%DOCUMENT_TYPE'])) {
            $sql .= " AND DOCUMENT_TYPE LIKE '%"
                . $connection->getSqlHelper()->forSql($filter['%DOCUMENT_TYPE']) . "%'";
        }

        $sql .= " ORDER BY MODIFIED DESC LIMIT " . (int)$limit;

        $result = $connection->query($sql);
        $templates = [];

        while ($row = $result->fetch()) {
            $templates[] = [
                'id' => (int)$row['ID'],
                'name' => $row['NAME'],
                'description' => $row['DESCRIPTION'],
                'module_id' => $row['MODULE_ID'],
                'entity' => $row['ENTITY'],
                'document_type' => $row['DOCUMENT_TYPE'],
                'document_status' => $row['DOCUMENT_STATUS'],
                'auto_execute' => $this->parseAutoExecute((int)$row['AUTO_EXECUTE']),
                'type' => $row['TYPE'],
                'is_system' => $row['IS_SYSTEM'] === 'Y',
                'system_code' => $row['SYSTEM_CODE'],
                'sort' => (int)$row['SORT'],
                'modified' => $row['MODIFIED'],
                'user_id' => (int)$row['USER_ID'],
            ];
        }

        return [
            'action' => 'templates',
            'count' => count($templates),
            'templates' => $templates,
        ];
    }

    /**
     * Получение запущенных экземпляров БП
     */
    private function getInstances(array $arguments, int $limit): array
    {
        $connection = Application::getConnection();
        $status = $this->getParam($arguments, 'status', 'all');

        $sql = "SELECT
                    wi.ID, wi.MODULE_ID, wi.ENTITY, wi.DOCUMENT_ID,
                    wi.WORKFLOW_TEMPLATE_ID, wi.STARTED, wi.STARTED_BY,
                    wi.STATUS, wi.MODIFIED,
                    wt.NAME as TEMPLATE_NAME, wt.DOCUMENT_TYPE
                FROM b_bp_workflow_instance wi
                LEFT JOIN b_bp_workflow_template wt ON wi.WORKFLOW_TEMPLATE_ID = wt.ID
                WHERE 1=1";

        if ($status === 'active') {
            $sql .= " AND wi.STATUS IN (0, 1)"; // Running or suspended
        } elseif ($status === 'completed') {
            $sql .= " AND wi.STATUS = 2"; // Completed
        }

        if ($templateId = $this->getParam($arguments, 'template_id')) {
            $sql .= " AND wi.WORKFLOW_TEMPLATE_ID = " . (int)$templateId;
        }

        if ($moduleId = $this->getParam($arguments, 'module_id')) {
            $sql .= " AND wi.MODULE_ID = '" . $connection->getSqlHelper()->forSql($moduleId) . "'";
        }

        $sql .= " ORDER BY wi.MODIFIED DESC LIMIT " . (int)$limit;

        $result = $connection->query($sql);
        $instances = [];

        while ($row = $result->fetch()) {
            $instances[] = [
                'workflow_id' => $row['ID'],
                'template_id' => (int)$row['WORKFLOW_TEMPLATE_ID'],
                'template_name' => $row['TEMPLATE_NAME'],
                'module_id' => $row['MODULE_ID'],
                'entity' => $row['ENTITY'],
                'document_type' => $row['DOCUMENT_TYPE'],
                'document_id' => $row['DOCUMENT_ID'],
                'status' => $this->parseWorkflowStatus((int)$row['STATUS']),
                'started' => $row['STARTED'],
                'started_by' => (int)$row['STARTED_BY'],
                'modified' => $row['MODIFIED'],
            ];
        }

        return [
            'action' => 'instances',
            'status_filter' => $status,
            'count' => count($instances),
            'instances' => $instances,
        ];
    }

    /**
     * Получение заданий БП
     */
    private function getTasks(array $arguments, int $limit): array
    {
        $connection = Application::getConnection();
        $status = $this->getParam($arguments, 'status', 'all');

        $sql = "SELECT
                    t.ID, t.WORKFLOW_ID, t.ACTIVITY, t.ACTIVITY_NAME,
                    t.MODIFIED, t.OVERDUE_DATE, t.NAME, t.DESCRIPTION,
                    t.STATUS, t.IS_INLINE, t.DELEGATION_TYPE, t.DOCUMENT_NAME,
                    t.CREATED_DATE,
                    wt.NAME as TEMPLATE_NAME
                FROM b_bp_task t
                LEFT JOIN b_bp_workflow_instance wi ON t.WORKFLOW_ID = wi.ID
                LEFT JOIN b_bp_workflow_template wt ON wi.WORKFLOW_TEMPLATE_ID = wt.ID
                WHERE 1=1";

        if ($status === 'active') {
            $sql .= " AND t.STATUS = 0"; // Pending
        } elseif ($status === 'completed') {
            $sql .= " AND t.STATUS IN (1, 2, 3, 4)"; // Completed statuses
        }

        if ($workflowId = $this->getParam($arguments, 'workflow_id')) {
            $sql .= " AND t.WORKFLOW_ID = '" . $connection->getSqlHelper()->forSql($workflowId) . "'";
        }

        $sql .= " ORDER BY t.MODIFIED DESC LIMIT " . (int)$limit;

        $result = $connection->query($sql);
        $tasks = [];

        while ($row = $result->fetch()) {
            $tasks[] = [
                'id' => (int)$row['ID'],
                'workflow_id' => $row['WORKFLOW_ID'],
                'template_name' => $row['TEMPLATE_NAME'],
                'name' => $row['NAME'],
                'description' => $row['DESCRIPTION'],
                'activity' => $row['ACTIVITY'],
                'activity_name' => $row['ACTIVITY_NAME'],
                'status' => $this->parseTaskStatus((int)$row['STATUS']),
                'document_name' => $row['DOCUMENT_NAME'],
                'is_inline' => $row['IS_INLINE'] === 'Y',
                'delegation_type' => (int)$row['DELEGATION_TYPE'],
                'created_date' => $row['CREATED_DATE'],
                'modified' => $row['MODIFIED'],
                'overdue_date' => $row['OVERDUE_DATE'],
            ];
        }

        return [
            'action' => 'tasks',
            'status_filter' => $status,
            'count' => count($tasks),
            'tasks' => $tasks,
        ];
    }

    /**
     * Получение истории БП
     */
    private function getHistory(array $arguments, int $limit): array
    {
        $connection = Application::getConnection();

        $sql = "SELECT
                    ID, MODULE_ID, ENTITY, DOCUMENT_ID, NAME, MODIFIED, USER_ID
                FROM b_bp_history
                WHERE 1=1";

        if ($moduleId = $this->getParam($arguments, 'module_id')) {
            $sql .= " AND MODULE_ID = '" . $connection->getSqlHelper()->forSql($moduleId) . "'";
        }

        $sql .= " ORDER BY MODIFIED DESC LIMIT " . (int)$limit;

        $result = $connection->query($sql);
        $history = [];

        while ($row = $result->fetch()) {
            $history[] = [
                'id' => (int)$row['ID'],
                'module_id' => $row['MODULE_ID'],
                'entity' => $row['ENTITY'],
                'document_id' => $row['DOCUMENT_ID'],
                'name' => $row['NAME'],
                'modified' => $row['MODIFIED'],
                'user_id' => (int)$row['USER_ID'],
            ];
        }

        return [
            'action' => 'history',
            'count' => count($history),
            'history' => $history,
        ];
    }

    /**
     * Получение детальной информации о шаблоне
     */
    private function getTemplateDetail(array $arguments): array
    {
        $this->validateRequired($arguments, ['template_id']);
        $templateId = (int)$arguments['template_id'];

        $connection = Application::getConnection();

        // Основная информация о шаблоне
        $sql = "SELECT * FROM b_bp_workflow_template WHERE ID = " . $templateId;
        $result = $connection->query($sql);
        $template = $result->fetch();

        if (!$template) {
            throw new \InvalidArgumentException("Template not found: $templateId");
        }

        // Параметры шаблона
        $parameters = [];
        if (!empty($template['PARAMETERS'])) {
            $parameters = @unserialize($template['PARAMETERS'], ['allowed_classes' => false]) ?: [];
        }

        // Переменные шаблона
        $variables = [];
        if (!empty($template['VARIABLES'])) {
            $variables = @unserialize($template['VARIABLES'], ['allowed_classes' => false]) ?: [];
        }

        // Константы шаблона
        $constants = [];
        if (!empty($template['CONSTANTS'])) {
            $constants = @unserialize($template['CONSTANTS'], ['allowed_classes' => false]) ?: [];
        }

        // Структура шаблона (actions tree) - может быть большой
        // Данные могут быть сжаты gzip (начинаются с 0x78)
        $templateStructure = null;
        if (!empty($template['TEMPLATE'])) {
            $templateData = $this->unpackTemplateData($template['TEMPLATE']);
            if ($templateData) {
                $templateStructure = $this->extractTemplateStructure($templateData);
            }
        }

        // Статистика использования
        $statsSql = "SELECT COUNT(*) as cnt FROM b_bp_workflow_instance WHERE WORKFLOW_TEMPLATE_ID = " . $templateId;
        $statsResult = $connection->query($statsSql)->fetch();
        $instancesCount = (int)($statsResult['cnt'] ?? 0);

        // Активные экземпляры
        $activeSql = 'SELECT COUNT(*) as cnt FROM b_bp_workflow_instance '
            . 'WHERE WORKFLOW_TEMPLATE_ID = ' . $templateId . ' AND STATUS IN (0, 1)';
        $activeResult = $connection->query($activeSql)->fetch();
        $activeCount = (int)($activeResult['cnt'] ?? 0);

        return [
            'action' => 'template_detail',
            'template' => [
                'id' => (int)$template['ID'],
                'name' => $template['NAME'],
                'description' => $template['DESCRIPTION'],
                'module_id' => $template['MODULE_ID'],
                'entity' => $template['ENTITY'],
                'document_type' => $template['DOCUMENT_TYPE'],
                'document_status' => $template['DOCUMENT_STATUS'],
                'auto_execute' => $this->parseAutoExecute((int)$template['AUTO_EXECUTE']),
                'type' => $template['TYPE'],
                'active' => $template['ACTIVE'] === 'Y',
                'is_system' => $template['IS_SYSTEM'] === 'Y',
                'system_code' => $template['SYSTEM_CODE'],
                'sort' => (int)$template['SORT'],
                'modified' => $template['MODIFIED'],
                'created_by' => (int)$template['CREATED_BY'],
                'updated_by' => (int)$template['UPDATED_BY'],
            ],
            'parameters' => $this->formatProperties($parameters),
            'variables' => $this->formatProperties($variables),
            'constants' => $this->formatProperties($constants),
            'structure' => $templateStructure,
            'statistics' => [
                'total_instances' => $instancesCount,
                'active_instances' => $activeCount,
            ],
        ];
    }

    /**
     * Получение доступных действий (activities)
     */
    private function getActivities(): array
    {
        $activities = [];

        // Стандартные действия Битрикс
        if (class_exists('\CBPRuntime')) {
            try {
                $runtime = \CBPRuntime::GetRuntime();
                $runtime->StartRuntime();

                // Получаем все зарегистрированные активити
                $allActivities = $runtime->SearchActivitiesByType('activity');

                foreach ($allActivities as $activityFile => $activityData) {
                    $activities[] = [
                        'file' => $activityFile,
                        'name' => $activityData['NAME'] ?? basename($activityFile),
                        'description' => $activityData['DESCRIPTION'] ?? '',
                        'type' => 'standard',
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore errors
            }
        }

        // REST активити
        $connection = Application::getConnection();
        $sql = 'SELECT ID, APP_ID, CODE, INTERNAL_CODE, NAME, DESCRIPTION, IS_ROBOT '
            . 'FROM b_bp_rest_activity ORDER BY ID';

        try {
            $result = $connection->query($sql);
            while ($row = $result->fetch()) {
                $name = $row['NAME'];
                if (is_string($name) && strpos($name, 'a:') === 0) {
                    $nameArr = @unserialize($name, ['allowed_classes' => false]);
                    $name = is_array($nameArr) ? ($nameArr['ru'] ?? $nameArr['en'] ?? reset($nameArr)) : $row['CODE'];
                }

                $description = $row['DESCRIPTION'];
                if (is_string($description) && strpos($description, 'a:') === 0) {
                    $descArr = @unserialize($description, ['allowed_classes' => false]);
                    $description = is_array($descArr) ? ($descArr['ru'] ?? $descArr['en'] ?? '') : '';
                }

                $activities[] = [
                    'id' => (int)$row['ID'],
                    'app_id' => $row['APP_ID'],
                    'code' => $row['CODE'],
                    'internal_code' => $row['INTERNAL_CODE'],
                    'name' => $name,
                    'description' => $description,
                    'is_robot' => $row['IS_ROBOT'] === 'Y',
                    'type' => 'rest',
                ];
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }

        return [
            'action' => 'activities',
            'count' => count($activities),
            'activities' => $activities,
        ];
    }

    /**
     * Получение списка сценариев
     */
    private function getScripts(array $arguments, int $limit): array
    {
        $connection = Application::getConnection();

        $sql = "SELECT
                    s.ID, s.MODULE_ID, s.ENTITY, s.DOCUMENT_TYPE, s.NAME, s.DESCRIPTION,
                    s.WORKFLOW_TEMPLATE_ID, s.CREATED_DATE, s.CREATED_BY,
                    s.MODIFIED_DATE, s.MODIFIED_BY, s.SORT, s.ACTIVE,
                    wt.NAME as TEMPLATE_NAME
                FROM b_bp_script s
                LEFT JOIN b_bp_workflow_template wt ON s.WORKFLOW_TEMPLATE_ID = wt.ID
                WHERE 1=1";

        if ($moduleId = $this->getParam($arguments, 'module_id')) {
            $sql .= " AND s.MODULE_ID = '" . $connection->getSqlHelper()->forSql($moduleId) . "'";
        }

        if ($documentType = $this->getParam($arguments, 'document_type')) {
            $sql .= " AND s.DOCUMENT_TYPE LIKE '%" . $connection->getSqlHelper()->forSql($documentType) . "%'";
        }

        $status = $this->getParam($arguments, 'status', 'all');
        if ($status === 'active') {
            $sql .= " AND s.ACTIVE = 'Y'";
        }

        $sql .= " ORDER BY s.MODIFIED_DATE DESC LIMIT " . (int)$limit;

        $result = $connection->query($sql);
        $scripts = [];

        while ($row = $result->fetch()) {
            $scripts[] = [
                'id' => (int)$row['ID'],
                'name' => $row['NAME'],
                'description' => $row['DESCRIPTION'],
                'module_id' => $row['MODULE_ID'],
                'entity' => $row['ENTITY'],
                'document_type' => $row['DOCUMENT_TYPE'],
                'template_id' => (int)$row['WORKFLOW_TEMPLATE_ID'],
                'template_name' => $row['TEMPLATE_NAME'],
                'active' => $row['ACTIVE'] === 'Y',
                'sort' => (int)$row['SORT'],
                'created_date' => $row['CREATED_DATE'],
                'created_by' => (int)$row['CREATED_BY'],
                'modified_date' => $row['MODIFIED_DATE'],
                'modified_by' => (int)$row['MODIFIED_BY'],
            ];
        }

        return [
            'action' => 'scripts',
            'count' => count($scripts),
            'scripts' => $scripts,
        ];
    }

    /**
     * Получение детальной информации о сценарии
     */
    private function getScriptDetail(array $arguments): array
    {
        $this->validateRequired($arguments, ['script_id']);
        $scriptId = (int)$arguments['script_id'];

        $connection = Application::getConnection();

        // Основная информация о сценарии
        $sql = "SELECT s.*, wt.NAME as TEMPLATE_NAME
                FROM b_bp_script s
                LEFT JOIN b_bp_workflow_template wt ON s.WORKFLOW_TEMPLATE_ID = wt.ID
                WHERE s.ID = " . $scriptId;
        $result = $connection->query($sql);
        $script = $result->fetch();

        if (!$script) {
            throw new \InvalidArgumentException("Script not found: $scriptId");
        }

        // Получаем структуру шаблона
        $templateStructure = null;
        $parameters = [];
        $variables = [];
        $constants = [];

        if ($script['WORKFLOW_TEMPLATE_ID']) {
            $tplSql = 'SELECT TEMPLATE, PARAMETERS, VARIABLES, CONSTANTS FROM b_bp_workflow_template '
                . 'WHERE ID = ' . (int)$script['WORKFLOW_TEMPLATE_ID'];
            $tplResult = $connection->query($tplSql);
            $template = $tplResult->fetch();

            if ($template) {
                if (!empty($template['TEMPLATE'])) {
                    $templateData = $this->unpackTemplateData($template['TEMPLATE']);
                    if ($templateData) {
                        $templateStructure = $this->extractTemplateStructure($templateData);
                    }
                }

                if (!empty($template['PARAMETERS'])) {
                    $parameters = @unserialize($template['PARAMETERS'], ['allowed_classes' => false]) ?: [];
                }

                if (!empty($template['VARIABLES'])) {
                    $variables = @unserialize($template['VARIABLES'], ['allowed_classes' => false]) ?: [];
                }

                if (!empty($template['CONSTANTS'])) {
                    $constants = @unserialize($template['CONSTANTS'], ['allowed_classes' => false]) ?: [];
                }
            }
        }

        // Очередь выполнения сценария
        $queueSql = "SELECT sq.*, COUNT(sqd.ID) as documents_count
                     FROM b_bp_script_queue sq
                     LEFT JOIN b_bp_script_queue_document sqd ON sq.ID = sqd.QUEUE_ID
                     WHERE sq.SCRIPT_ID = " . $scriptId . "
                     GROUP BY sq.ID
                     ORDER BY sq.STARTED_DATE DESC
                     LIMIT 10";
        $queueResult = $connection->query($queueSql);
        $recentRuns = [];

        while ($queueRow = $queueResult->fetch()) {
            $recentRuns[] = [
                'queue_id' => (int)$queueRow['ID'],
                'status' => $this->parseScriptQueueStatus((int)$queueRow['STATUS']),
                'documents_count' => (int)$queueRow['documents_count'],
                'started_date' => $queueRow['STARTED_DATE'],
                'started_by' => (int)$queueRow['STARTED_BY'],
                'modified_date' => $queueRow['MODIFIED_DATE'],
            ];
        }

        return [
            'action' => 'script_detail',
            'script' => [
                'id' => (int)$script['ID'],
                'name' => $script['NAME'],
                'description' => $script['DESCRIPTION'],
                'module_id' => $script['MODULE_ID'],
                'entity' => $script['ENTITY'],
                'document_type' => $script['DOCUMENT_TYPE'],
                'template_id' => (int)$script['WORKFLOW_TEMPLATE_ID'],
                'template_name' => $script['TEMPLATE_NAME'],
                'active' => $script['ACTIVE'] === 'Y',
                'sort' => (int)$script['SORT'],
                'created_date' => $script['CREATED_DATE'],
                'created_by' => (int)$script['CREATED_BY'],
                'modified_date' => $script['MODIFIED_DATE'],
                'modified_by' => (int)$script['MODIFIED_BY'],
            ],
            'parameters' => $this->formatProperties($parameters),
            'variables' => $this->formatProperties($variables),
            'constants' => $this->formatProperties($constants),
            'structure' => $templateStructure,
            'recent_runs' => $recentRuns,
        ];
    }

    /**
     * Получение роботов автоматизации CRM
     */
    private function getRobots(array $arguments, int $limit): array
    {
        $connection = Application::getConnection();

        // Роботы автоматизации хранятся в b_bp_workflow_template с:
        // - type='custom_robots' (пользовательские роботы)
        // - system_code='bitrix_bizproc_automation' (шаблоны автоматизации)
        $sql = "SELECT
                    ID, MODULE_ID, ENTITY, DOCUMENT_TYPE, DOCUMENT_STATUS,
                    AUTO_EXECUTE, NAME, DESCRIPTION, MODIFIED, USER_ID,
                    ACTIVE, IS_SYSTEM, SORT, TYPE, SYSTEM_CODE
                FROM b_bp_workflow_template
                WHERE (TYPE IN ('robot', 'custom_robots') OR SYSTEM_CODE = 'bitrix_bizproc_automation')";

        if ($moduleId = $this->getParam($arguments, 'module_id')) {
            $sql .= " AND MODULE_ID = '" . $connection->getSqlHelper()->forSql($moduleId) . "'";
        }

        if ($documentType = $this->getParam($arguments, 'document_type')) {
            $sql .= " AND DOCUMENT_TYPE LIKE '%" . $connection->getSqlHelper()->forSql($documentType) . "%'";
        }

        $status = $this->getParam($arguments, 'status', 'all');
        if ($status === 'active') {
            $sql .= " AND ACTIVE = 'Y'";
        }

        $sql .= " ORDER BY MODIFIED DESC LIMIT " . (int)$limit;

        $result = $connection->query($sql);
        $robots = [];

        while ($row = $result->fetch()) {
            // Получаем структуру робота
            $robotStructure = null;
            $templateSql = "SELECT TEMPLATE FROM b_bp_workflow_template WHERE ID = " . (int)$row['ID'];
            $templateResult = $connection->query($templateSql);
            $template = $templateResult->fetch();

            if ($template && !empty($template['TEMPLATE'])) {
                $templateData = @unserialize($template['TEMPLATE'], ['allowed_classes' => false]);
                if ($templateData) {
                    $robotStructure = $this->extractTemplateStructure($templateData);
                }
            }

            $robots[] = [
                'id' => (int)$row['ID'],
                'name' => $row['NAME'],
                'description' => $row['DESCRIPTION'],
                'module_id' => $row['MODULE_ID'],
                'entity' => $row['ENTITY'],
                'document_type' => $row['DOCUMENT_TYPE'],
                'document_status' => $row['DOCUMENT_STATUS'],
                'auto_execute' => $this->parseAutoExecute((int)$row['AUTO_EXECUTE']),
                'active' => $row['ACTIVE'] === 'Y',
                'is_system' => $row['IS_SYSTEM'] === 'Y',
                'system_code' => $row['SYSTEM_CODE'],
                'sort' => (int)$row['SORT'],
                'modified' => $row['MODIFIED'],
                'structure' => $robotStructure,
            ];
        }

        return [
            'action' => 'robots',
            'count' => count($robots),
            'robots' => $robots,
        ];
    }

    /**
     * Получение триггеров автоматизации
     */
    private function getTriggers(array $arguments, int $limit): array
    {
        $connection = Application::getConnection();
        $triggers = [];

        // CRM триггеры хранятся в b_crm_automation_trigger
        $sql = "SELECT
                    t.ID, t.NAME, t.CODE, t.ENTITY_TYPE_ID, t.ENTITY_STATUS, t.APPLY_RULES
                FROM b_crm_automation_trigger t
                WHERE 1=1";

        if ($entityTypeId = $this->getParam($arguments, 'entity_type_id')) {
            $sql .= " AND t.ENTITY_TYPE_ID = " . (int)$entityTypeId;
        }

        if ($documentType = $this->getParam($arguments, 'document_type')) {
            // Пытаемся извлечь entity_type_id из document_type (например DEAL -> 2)
            $entityTypeMap = [
                'LEAD' => 1,
                'DEAL' => 2,
                'CONTACT' => 3,
                'COMPANY' => 4,
                'QUOTE' => 7,
            ];
            if (isset($entityTypeMap[strtoupper($documentType)])) {
                $sql .= " AND t.ENTITY_TYPE_ID = " . $entityTypeMap[strtoupper($documentType)];
            }
        }

        $sql .= " ORDER BY t.ID DESC LIMIT " . (int)$limit;

        $result = $connection->query($sql);

        // Маппинг entity_type_id на названия
        $entityTypeNames = [
            1 => 'LEAD',
            2 => 'DEAL',
            3 => 'CONTACT',
            4 => 'COMPANY',
            5 => 'INVOICE',
            7 => 'QUOTE',
            31 => 'ORDER',
        ];

        while ($row = $result->fetch()) {
            $applyRules = null;
            if (!empty($row['APPLY_RULES'])) {
                $applyRules = @unserialize($row['APPLY_RULES'], ['allowed_classes' => false]);
                if ($applyRules === false) {
                    $applyRules = json_decode($row['APPLY_RULES'], true);
                }
            }

            $entityTypeId = (int)$row['ENTITY_TYPE_ID'];
            $entityTypeName = $entityTypeNames[$entityTypeId] ?? "DYNAMIC_$entityTypeId";

            $triggers[] = [
                'id' => (int)$row['ID'],
                'name' => $row['NAME'],
                'code' => $row['CODE'],
                'entity_type_id' => $entityTypeId,
                'entity_type_name' => $entityTypeName,
                'entity_status' => $row['ENTITY_STATUS'],
                'apply_rules' => $applyRules,
            ];
        }

        // Также проверяем b_bp_automation_trigger (общие триггеры БП)
        $bpSql = "SELECT ID, NAME, CODE, MODULE_ID, ENTITY, DOCUMENT_TYPE, DOCUMENT_STATUS, APPLY_RULES
                  FROM b_bp_automation_trigger
                  ORDER BY ID DESC LIMIT " . (int)$limit;

        try {
            $bpResult = $connection->query($bpSql);
            while ($row = $bpResult->fetch()) {
                $applyRules = null;
                if (!empty($row['APPLY_RULES'])) {
                    $applyRules = @unserialize($row['APPLY_RULES'], ['allowed_classes' => false])
                        ?: json_decode($row['APPLY_RULES'], true);
                }

                $triggers[] = [
                    'id' => (int)$row['ID'],
                    'name' => $row['NAME'],
                    'code' => $row['CODE'],
                    'module_id' => $row['MODULE_ID'],
                    'entity' => $row['ENTITY'],
                    'document_type' => $row['DOCUMENT_TYPE'],
                    'document_status' => $row['DOCUMENT_STATUS'],
                    'apply_rules' => $applyRules,
                    'source' => 'bizproc',
                ];
            }
        } catch (\Throwable $e) {
            // Таблица может не существовать
        }

        return [
            'action' => 'triggers',
            'count' => count($triggers),
            'triggers' => $triggers,
        ];
    }

    /**
     * Парсинг статуса очереди сценария
     */
    private function parseScriptQueueStatus(int $status): array
    {
        $statuses = [
            0 => 'pending',
            1 => 'running',
            2 => 'completed',
            3 => 'error',
        ];

        return [
            'code' => $status,
            'name' => $statuses[$status] ?? 'unknown',
        ];
    }

    /**
     * Распаковка данных шаблона (может быть сжат gzip)
     */
    private function unpackTemplateData($data): ?array
    {
        if (empty($data)) {
            return null;
        }

        // Проверяем, сжаты ли данные (zlib magic bytes: 0x78)
        $firstByte = ord($data[0]);
        if ($firstByte === 0x78) {
            // Данные сжаты zlib
            $uncompressed = @gzuncompress($data);
            if ($uncompressed === false) {
                return null;
            }
            $data = $uncompressed;
        }

        // Пробуем десериализовать
        $result = @unserialize($data, ['allowed_classes' => false]);
        if ($result === false && $data !== 'b:0;') {
            return null;
        }

        return is_array($result) ? $result : null;
    }

    /**
     * Сканирование всех активных шаблонов: плейсхолдеры {{... host ... url ...}}
     * и явные http(s) URL.
     */
    private function getLinkScan(): array
    {
        $connection = Application::getConnection();
        $sql = 'SELECT ID, NAME, TEMPLATE, PARAMETERS, VARIABLES, CONSTANTS, DESCRIPTION '
            . "FROM b_bp_workflow_template WHERE ACTIVE = 'Y' ORDER BY ID";
        $result = $connection->query($sql);

        $hostUrlRows = [];
        $plainUrlRows = [];
        $seenHost = [];
        $seenUrl = [];
        $templatesScanned = 0;

        while ($row = $result->fetch()) {
            $templatesScanned++;
            $templateId = (int)$row['ID'];
            $templateName = (string)$row['NAME'];
            $strings = [];

            if ($row['DESCRIPTION'] !== null && $row['DESCRIPTION'] !== '') {
                $strings[] = (string)$row['DESCRIPTION'];
            }

            foreach (['PARAMETERS', 'VARIABLES', 'CONSTANTS'] as $col) {
                if (empty($row[$col])) {
                    continue;
                }
                $un = @unserialize($row[$col], ['allowed_classes' => false]);
                if (is_array($un)) {
                    $this->collectStringsRecursive($un, $strings);
                }
            }

            if (!empty($row['TEMPLATE'])) {
                $tplData = $this->unpackTemplateData($row['TEMPLATE']);
                if (is_array($tplData)) {
                    $this->collectStringsRecursive($tplData, $strings);
                }
            }

            foreach ($strings as $s) {
                if (!is_string($s) || $s === '') {
                    continue;
                }

                if ($this->stringContainsHostUrlPlaceholder($s)) {
                    $snippet = $this->truncateSnippet($s);
                    $key = $templateId . "\0" . md5($snippet);
                    if (!isset($seenHost[$key])) {
                        $seenHost[$key] = true;
                        $hostUrlRows[] = [
                            'template_id' => $templateId,
                            'template_name' => $templateName,
                            'snippet' => $snippet,
                        ];
                    }
                }

                if (preg_match_all('/https?:\/\/[^\s\"\'\]\}\)]+/u', $s, $m)) {
                    foreach ($m[0] as $url) {
                        $url = rtrim($url, '.,;:!?)');
                        $ukey = $templateId . "\0" . $url;
                        if (!isset($seenUrl[$ukey])) {
                            $seenUrl[$ukey] = true;
                            $plainUrlRows[] = [
                                'template_id' => $templateId,
                                'template_name' => $templateName,
                                'url' => $url,
                                'context_snippet' => $this->truncateSnippet($s),
                            ];
                        }
                    }
                }
            }
        }

        return [
            'action' => 'link_scan',
            'count_templates_scanned' => $templatesScanned,
            'host_url_placeholder' => $hostUrlRows,
            'plain_http_urls' => $plainUrlRows,
            'notes' => [
                'host_url_placeholder' => 'Глобальные подстановки: {=GlobalVar:VariableNNN…} (host url), '
                    . '{=Variable:VariableNNN…}, {{Переменные глобальные: host url}}, {{VariableNNN…}}',
                'plain_http_urls' => 'Явные URL, начинающиеся с http:// или https://',
            ],
        ];
    }

    private function collectStringsRecursive($data, array &$out): void
    {
        if (is_string($data)) {
            $out[] = $data;
            return;
        }
        if (is_array($data)) {
            foreach ($data as $v) {
                $this->collectStringsRecursive($v, $out);
            }
        }
    }

    private function stringContainsHostUrlPlaceholder(string $s): bool
    {
        // Подстановка глобальной переменной в тексте БП: {=Variable:VariableNNN…}
        // (в конструкторе может отображаться как {{Переменные глобальные: host url}})
        if (preg_match('/\{=Variable:Variable\d+\}/i', $s)) {
            return true;
        }

        // Адрес портала из глобальных настроек БП: {=GlobalVar:Variable1737473282761} и т.п.
        if (preg_match('/\{=GlobalVar:Variable\d+\}/i', $s)) {
            return true;
        }

        if (strpos($s, '{{') === false) {
            return false;
        }

        // Как в конструкторе БП: {{Переменные глобальные: host url}}
        if (
            preg_match(
                '/\{\{[^}]*Переменные\s+глобальные\s*:\s*host\s+url[^}]*\}\}/ui',
                $s
            )
        ) {
            return true;
        }

        // Слова host и url внутри одной пары {{ }}
        if (
            preg_match('/\{\{[^}]*host[^}]*url[^}]*\}\}/ui', $s)
            || preg_match('/\{\{[^}]*url[^}]*host[^}]*\}\}/ui', $s)
        ) {
            return true;
        }

        // В шаблоне только имя: {{Variable1737473282761}} (в UI — «host url»)
        if (preg_match('/\{\{\s*Variable\d+\s*\}\}/', $s)) {
            return true;
        }

        return false;
    }

    private function truncateSnippet(string $s, int $max = 500): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }

    /**
     * Извлечение структуры шаблона (детальное дерево активити)
     */
    private function extractTemplateStructure(array $templateData, int $depth = 0): array
    {
        $structure = [];

        foreach ($templateData as $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $type = $activity['Type'] ?? 'unknown';
            $item = [
                'type' => $type,
                'name' => $activity['Name'] ?? '',
                'title' => $activity['Properties']['Title'] ?? '',
            ];

            // Добавляем описание типа активити
            $item['type_description'] = $this->getActivityTypeDescription($type);

            // Добавляем ключевые свойства в зависимости от типа
            if (isset($activity['Properties'])) {
                $props = $activity['Properties'];

                // Условия
                if (isset($props['Condition'])) {
                    $item['condition'] = $props['Condition'];
                }

                // Таймауты
                if (isset($props['TimeoutDuration'])) {
                    $item['timeout'] = $props['TimeoutDuration'];
                }

                // Поля для изменения (с значениями)
                if (isset($props['FieldValue']) && is_array($props['FieldValue'])) {
                    $item['field_changes'] = [];
                    foreach ($props['FieldValue'] as $field => $value) {
                        $item['field_changes'][$field] = $this->formatFieldValue($value);
                    }
                }

                // Уведомления
                if (isset($props['MessageText'])) {
                    $item['message'] = $props['MessageText'];
                }
                if (isset($props['MessageUserFrom'])) {
                    $item['message_from'] = $props['MessageUserFrom'];
                }
                if (isset($props['MessageUserTo'])) {
                    $item['message_to'] = $props['MessageUserTo'];
                }

                // Задания
                if (isset($props['TaskButtonMessage'])) {
                    $item['task_buttons'] = $props['TaskButtonMessage'];
                }
                if (isset($props['TaskUsers'])) {
                    $item['task_users'] = $props['TaskUsers'];
                }

                // Webhook/HTTP запросы
                if (isset($props['Url'])) {
                    $item['url'] = $props['Url'];
                }
                if (isset($props['RequestType'])) {
                    $item['request_type'] = $props['RequestType'];
                }

                // Почта
                if (isset($props['MailMessageTo'])) {
                    $item['mail_to'] = $props['MailMessageTo'];
                }
                if (isset($props['MailMessageSubject'])) {
                    $item['mail_subject'] = $props['MailMessageSubject'];
                }

                // Задачи
                if (isset($props['TaskTitle'])) {
                    $item['task_title'] = $props['TaskTitle'];
                }
                if (isset($props['TaskResponsible'])) {
                    $item['task_responsible'] = $props['TaskResponsible'];
                }

                // Математика/переменные
                if (isset($props['VariableValue'])) {
                    $item['variable_value'] = $props['VariableValue'];
                }

                // PHP код (CodeActivity)
                if (isset($props['ExecuteCode'])) {
                    $item['php_code'] = $props['ExecuteCode'];
                }

                // Робот/активити с REST
                if (isset($props['robot_code'])) {
                    $item['robot_code'] = $props['robot_code'];
                }
                if (isset($props['activity_code'])) {
                    $item['activity_code'] = $props['activity_code'];
                }

                // Ожидание
                if (isset($props['WaitTimeType'])) {
                    $item['wait_type'] = $props['WaitTimeType'];
                }
                if (isset($props['WaitTime'])) {
                    $item['wait_time'] = $props['WaitTime'];
                }

                // Статус документа
                if (isset($props['TargetStatus'])) {
                    $item['target_status'] = $props['TargetStatus'];
                }
            }

            // Рекурсивно обрабатываем дочерние элементы
            if (isset($activity['Children']) && is_array($activity['Children']) && $depth < 10) {
                $item['children'] = $this->extractTemplateStructure($activity['Children'], $depth + 1);
            }

            $structure[] = $item;
        }

        return $structure;
    }

    /**
     * Получение описания типа активити
     */
    private function getActivityTypeDescription(string $type): string
    {
        $descriptions = [
            'SequenceActivity' => 'Последовательность действий',
            'ParallelActivity' => 'Параллельное выполнение',
            'IfElseActivity' => 'Условие (если/иначе)',
            'IfElseBranchActivity' => 'Ветка условия',
            'WhileActivity' => 'Цикл',
            'DelayActivity' => 'Задержка/ожидание',
            'SetFieldActivity' => 'Изменение полей документа',
            'SetVariableActivity' => 'Изменение переменной',
            'MailActivity' => 'Отправка email',
            'SocialNetworkMessageActivity' => 'Сообщение в соцсеть/чат',
            'NotifyActivity' => 'Уведомление',
            'ApproveActivity' => 'Согласование',
            'ReviewActivity' => 'Ознакомление',
            'RequestInformationActivity' => 'Запрос информации',
            'RequestInformationOptionalActivity' => 'Необязательный запрос информации',
            'SetStatusActivity' => 'Смена статуса/стадии',
            'WebHookActivity' => 'Webhook запрос',
            'RestActivity' => 'REST действие',
            'CodeActivity' => 'PHP код',
            'ListenActivity' => 'Ожидание события',
            'EventActivity' => 'Событие',
            'HandleExternalEventActivity' => 'Внешнее событие',
            'PublishActivity' => 'Публикация',
            'UnpublishActivity' => 'Снятие с публикации',
            'CreateTaskActivity' => 'Создание задачи',
            'CreateDocumentActivity' => 'Создание документа',
            'ModifyDocumentActivity' => 'Изменение документа',
            'DeleteDocumentActivity' => 'Удаление документа',
            'LogActivity' => 'Запись в лог',
            'AbortActivity' => 'Прерывание процесса',
            'TerminateActivity' => 'Завершение процесса',
            'RobotActivity' => 'Робот',
            'ForeachActivity' => 'Цикл по элементам',
            'MathOperationActivity' => 'Математическая операция',
            'GetUserInfoActivity' => 'Получить информацию о пользователе',
            'CalendarEventActivity' => 'Событие календаря',
            'PhoneActivity' => 'Телефонный звонок',
            'SmsActivity' => 'SMS сообщение',
        ];

        return $descriptions[$type] ?? $type;
    }

    /**
     * Форматирование значения поля для вывода
     */
    private function formatFieldValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string)$value;
    }

    /**
     * Форматирование свойств (параметров/переменных)
     */
    private function formatProperties(array $properties): array
    {
        $formatted = [];

        foreach ($properties as $code => $prop) {
            if (!is_array($prop)) {
                continue;
            }

            $formatted[] = [
                'code' => $code,
                'name' => $prop['Name'] ?? $code,
                'description' => $prop['Description'] ?? '',
                'type' => $prop['Type'] ?? 'string',
                'required' => ($prop['Required'] ?? 'N') === 'Y',
                'multiple' => ($prop['Multiple'] ?? 'N') === 'Y',
                'default' => $prop['Default'] ?? null,
            ];
        }

        return $formatted;
    }

    /**
     * Парсинг флага AUTO_EXECUTE
     */
    private function parseAutoExecute(int $value): array
    {
        return [
            'value' => $value,
            'on_create' => ($value & 1) > 0,
            'on_edit' => ($value & 2) > 0,
            'manual' => $value === 0,
        ];
    }

    /**
     * Парсинг статуса workflow
     */
    private function parseWorkflowStatus(int $status): array
    {
        $statuses = [
            0 => 'running',
            1 => 'suspended',
            2 => 'completed',
            3 => 'terminated',
        ];

        return [
            'code' => $status,
            'name' => $statuses[$status] ?? 'unknown',
        ];
    }

    /**
     * Парсинг статуса задания
     */
    private function parseTaskStatus(int $status): array
    {
        $statuses = [
            0 => 'pending',
            1 => 'approved',
            2 => 'rejected',
            3 => 'completed',
            4 => 'timeout',
        ];

        return [
            'code' => $status,
            'name' => $statuses[$status] ?? 'unknown',
        ];
    }
}
