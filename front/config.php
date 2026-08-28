<?php
include('../../../inc/includes.php');

Session::checkRight('config', READ);

Html::header(
    PluginSynchronisationcaloutlookConfig::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'pluginsynchronisationcaloutlookconfig'
);

$config = new PluginSynchronisationcaloutlookConfig();
$config->showForm(PluginSynchronisationcaloutlookConfig::CONFIG_ID);

Html::footer();
