<?php

use Bitrix\Main\Localization\Loc;

if (!check_bitrix_sessid()) {
    return;
}

Loc::loadMessages(__FILE__);
?>

<form action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="bitrix24.mcp">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="2">

    <p style="color: green; font-weight: bold;">✓ Модуль "MCP Server для Битрикс24" успешно установлен!</p>

    <h3>📋 Что дальше?</h3>

    <ol>
        <li>
            <strong>Установите зависимости через Composer:</strong>
            <pre
                style="background: #f5f5f5;
                    padding: 10px;
                    border-radius: 4px;">cd /local/modules/bitrix24.mcp
composer install --no-dev</pre>
        </li>

        <li>
            <strong>Проверьте установку:</strong>
            <pre
                style="background: #f5f5f5;
                    padding: 10px;
                    border-radius: 4px;">php /local/modules/bitrix24.mcp/cli/test-connection.php</pre>
        </li>

        <li>
            <strong>Сгенерируйте конфигурацию для AI-ассистентов:</strong>
            <pre
                style="background: #f5f5f5;
                    padding: 10px;
                    border-radius: 4px;">php /local/modules/bitrix24.mcp/cli/generate-config.php</pre>
        </li>

        <li>
            <strong>Сгенерируйте карту проекта (CLAUDE.md):</strong>
            <pre
                style="background: #f5f5f5;
                    padding: 10px;
                    border-radius: 4px;">php /local/modules/bitrix24.mcp/cli/generate-map.php</pre>
        </li>

        <li>
            <strong>Настройте роут для HTTP API:</strong>
            <p>Создайте роут <code>/api/mcp/</code> в вашем Битрикс24 и подключите
                контроллер <code>\Bitrix24\Mcp\Controller\ApiController</code></p>
        </li>

        <li>
            <strong>Подключите AI-ассистента (Claude Code или Cursor):</strong>
            <p>Следуйте инструкциям из <code>/local/modules/bitrix24.mcp/README.md</code></p>
        </li>
    </ol>

    <h3>🔑 Токен авторизации</h3>
    <p>Токен был автоматически сгенерирован при установке. Получить его можно командой:</p>
    <pre
        style="background: #f5f5f5;
            padding: 10px;
            border-radius: 4px;">php /local/modules/bitrix24.mcp/cli/generate-config.php</pre>

    <h3>📚 Документация</h3>
    <p>Полная документация доступна в файле <code>/local/modules/bitrix24.mcp/README.md</code></p>

    <input type="submit" name="inst" value="<?= Loc::getMessage('MOD_BACK') ?>">
</form>
