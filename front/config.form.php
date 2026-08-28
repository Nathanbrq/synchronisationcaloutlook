<?php
include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$item = new PluginSynchronisationcaloutlookConfig();

if (isset($_POST['update'])) {
    $_POST['id'] = PluginSynchronisationcaloutlookConfig::CONFIG_ID;
    $item->check(PluginSynchronisationcaloutlookConfig::CONFIG_ID, UPDATE);
    $item->update($_POST);
    Html::back();

} else {
    Html::redirect(Plugin::getWebDir('synchronisationcaloutlook') . '/front/config.php');
}
