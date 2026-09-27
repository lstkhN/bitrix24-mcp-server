<?php

/**
 * MCP Server маршруты для Битрикс24
 *
 * ВАЖНО: Этот файл нужно скопировать в /local/routes/web.php
 *
 * Или добавить его в /bitrix/.settings.php:
 * 'routing' => [
 *     'value' => [
 *         'config' => ['web.php', 'mcp.php'],
 *     ],
 * ],
 */

use Bitrix\Main\Routing\RoutingConfigurator;
use Bitrix24\Mcp\Controller\ApiController;

return static function (RoutingConfigurator $routes) {
    // MCP Streamable HTTP endpoints
    // POST - основной endpoint для JSON-RPC запросов
    $routes->post('/api/mcp/', [ApiController::class, 'handle'])
        ->name('bitrix24.mcp.handle');

    // GET - SSE стрим (опционально, возвращает 405)
    $routes->get('/api/mcp/', [ApiController::class, 'sse'])
        ->name('bitrix24.mcp.sse');

    // DELETE - завершение сессии (возвращает 405)
    $routes->delete('/api/mcp/', [ApiController::class, 'delete'])
        ->name('bitrix24.mcp.delete');
};
