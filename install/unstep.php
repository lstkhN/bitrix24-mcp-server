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
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <p style="color: orange; font-weight: bold;">ℹ Модуль "MCP Server для Битрикс24" успешно удалён</p>

    <h3>⚠️ Обратите внимание:</h3>

    <ul>
        <li>Настройки модуля удалены из базы данных</li>
        <li>Токен авторизации удалён</li>
        <li>Файлы модуля остались в <code>/local/modules/bitrix24.mcp/</code></li>
        <li>Логи остались в <code>/local/logs/mcp.log</code></li>
    </ul>

    <p>Для полного удаления модуля выполните:</p>
    <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">rm -rf /local/modules/bitrix24.mcp
rm -f /local/logs/mcp.log
rm -f /local/.mcp.json.example
rm -f /local/.cursorrules.example
rm -f /local/CLAUDE.md</pre>

    <input type="submit" name="inst" value="<?= Loc::getMessage('MOD_BACK') ?>">
</form>
