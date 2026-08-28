<?php
include('../../../inc/includes.php');

Session::checkRight('config', READ);

$item = new PluginSynchronisationcaloutlookTemplate();

if (isset($_POST['save'])) {
    Session::checkRight('config', UPDATE);
    // save() passe par l'ORM standard (add()/update()) depuis la
    // correction du bug "\r\n" littéral — voir le commentaire de
    // inc/template.class.php::save() pour l'historique complet. Aucun id
    // à transmettre : la ligne existante est retrouvée automatiquement.
    $item->save($_POST);
    Html::redirect(Plugin::getWebDir('synchronisationcaloutlook') . '/front/template.php');

} else if (isset($_POST['purge'])) {
    Session::checkRight('config', UPDATE);
    // L'id réel du gabarit n'est plus prévisible (AUTO_INCREMENT normal
    // depuis la correction du bug "\r\n" littéral) : on le retrouve via
    // getSingleton() plutôt que de faire confiance à un id posté par le
    // navigateur.
    $existing = PluginSynchronisationcaloutlookTemplate::getSingleton();
    if ($existing->getID()) {
        $item->delete(['id' => $existing->getID()], true);
    }
    Html::redirect(Plugin::getWebDir('synchronisationcaloutlook') . '/front/template.php');

} else {
    Html::header(
        PluginSynchronisationcaloutlookTemplate::getTypeName(),
        $_SERVER['PHP_SELF'],
        'config',
        'pluginsynchronisationcaloutlookconfig'
    );
    // Singleton : showForm() détecte lui-même s'il s'agit d'une création
    // ou d'une édition en vérifiant l'existence en base (cf.
    // inc/template.class.php) ; le paramètre $ID n'est pas utilisé par
    // cette classe, 0 est passé par convention.
    $item->showForm(0);
    Html::footer();
}
