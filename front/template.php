<?php
include('../../../inc/includes.php');

Session::checkRight('config', READ);

/**
 * Page unique du gabarit (singleton) : soit "+" (aucun gabarit), soit
 * le nom cliquable (édition) + X (suppression) + Export/Import.
 * Remplace l'ancienne liste CRUD : un seul gabarit a un sens pour ce
 * plugin (cf. inc/template.class.php).
 */

if (isset($_POST['export'])) {
    Session::checkRight('config', UPDATE);
    $template = PluginSynchronisationcaloutlookTemplate::getSingleton();
    try {
        $template->exportToFiles();
        Session::addMessageAfterRedirect(__('Gabarit exporté dans le dossier gabarit/ du plugin.'));
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect(Plugin::getWebDir('synchronisationcaloutlook') . '/front/template.php');
}

if (isset($_POST['import'])) {
    Session::checkRight('config', UPDATE);
    $template = new PluginSynchronisationcaloutlookTemplate();
    try {
        $template->importFromFiles();
        Toolbox::logInFile('synchronisationcaloutlook', sprintf(
            "[%s] TEMPLATE import (front) : exists() après import = %s\n",
            date('Y-m-d H:i:s'),
            PluginSynchronisationcaloutlookTemplate::exists() ? 'oui' : 'NON'
        ));
        Session::addMessageAfterRedirect(__('Gabarit importé depuis le dossier gabarit/ du plugin.'));
    } catch (\Throwable $e) {
        Toolbox::logInFile('synchronisationcaloutlook', sprintf(
            "[%s] TEMPLATE import (front) : ÉCHEC — %s dans %s:%d\n%s\n",
            date('Y-m-d H:i:s'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect(Plugin::getWebDir('synchronisationcaloutlook') . '/front/template.php');
}

Html::header(
    PluginSynchronisationcaloutlookTemplate::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'pluginsynchronisationcaloutlookconfig'
);

$exists  = PluginSynchronisationcaloutlookTemplate::exists();
$canedit = Session::haveRight('config', UPDATE);

echo "<div class='center'>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='2'>" . PluginSynchronisationcaloutlookTemplate::getTypeName() . "</th></tr>";

if ($exists) {
    $template = PluginSynchronisationcaloutlookTemplate::getSingleton();
    $editUrl = Plugin::getWebDir('synchronisationcaloutlook') . '/front/template.form.php';

    echo "<tr class='tab_bg_1'><td>";
    echo "<a href='" . htmlspecialchars($editUrl) . "'>" . htmlspecialchars($template->fields['name']) . "</a>";
    echo "</td><td class='center'>";
    if ($canedit) {
        echo "<form method='post' action='" . htmlspecialchars($editUrl) . "' style='display:inline;margin:0;'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        // Pas de champ 'id' caché : front/template.form.php retrouve
        // lui-même l'id réel du gabarit via getSingleton() au moment du
        // purge, plutôt que de dépendre d'une valeur postée par le
        // navigateur (id qui n'est plus prévisible depuis la correction
        // du bug "\r\n" littéral, cf. inc/template.class.php::save()).
        echo "<input type='submit' name='purge' class='submit' title=\"" . htmlspecialchars(__('Supprimer'), ENT_QUOTES) . "\" "
            . "onclick=\"return confirm('" . htmlspecialchars(__('Supprimer ce gabarit ?'), ENT_QUOTES) . "');\" "
            . "value=\"&times;\">";
        echo "</form>";
    }
    echo "</td></tr>";
} else {
    echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";
    if ($canedit) {
        echo "<a href='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/template.form.php' class='submit'>"
            . "+ " . __('Créer un gabarit') . "</a>";
    } else {
        echo __('Aucun gabarit');
    }
    echo "</td></tr>";
}

if ($canedit) {
    echo "<tr><th colspan='2'>" . __('Import / Export') . "</th></tr>";
    echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";

    echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "' style='display:inline;'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    $exportDisabled = $exists ? '' : "disabled title=\"" . htmlspecialchars(__('Aucun gabarit à exporter.'), ENT_QUOTES) . "\"";
    echo "<input type='submit' name='export' class='submit' {$exportDisabled} value=\"" . htmlspecialchars(__('Exporter'), ENT_QUOTES) . "\">";
    echo "</form>";

    echo "&nbsp;&nbsp;";

    echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "' style='display:inline;'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    $importConfirm = $exists
        ? __('Attention, le gabarit présent va être supprimé et remplacé par celui du dossier gabarit/. Continuer ?')
        : __('Importer le gabarit du dossier gabarit/ ?');
    echo "<input type='submit' name='import' class='submit' "
        . "onclick=\"return confirm('" . htmlspecialchars($importConfirm, ENT_QUOTES) . "');\" "
        . "value=\"" . htmlspecialchars(__('Importer'), ENT_QUOTES) . "\">";
    echo "</form>";

    echo "</td></tr>";
}

echo "</table>";
echo "</div>";

Html::footer();
