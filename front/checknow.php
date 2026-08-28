<?php
include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

Html::header(
    __('Vérification Synchronisation Cal Outlook'),
    $_SERVER['PHP_SELF'],
    'config',
    'pluginsynchronisationcaloutlookconfig'
);

/**
 * Envoi ciblé d'une seule tâche (bouton "Envoyer" par ligne), utile pour
 * tester ou rattraper un cas précis sans déclencher l'envoi en masse de
 * "Confirmer l'envoi" (ex: doute sur un destinataire, bug suspecté sur
 * une tâche en particulier). La tâche est recalculée à neuf via
 * findPending() avant envoi : jamais de confiance dans les champs postés
 * seuls, comme pour l'envoi groupé.
 */
if (isset($_POST['send_one'])) {
    $targetItemtype = $_POST['itemtype'] ?? '';
    $targetItemsId  = (int) ($_POST['items_id'] ?? 0);

    $pending = PluginSynchronisationcaloutlookCheck::findPending();
    $match = null;
    foreach ($pending as $entry) {
        if ($entry['itemtype'] === $targetItemtype && $entry['items_id'] === $targetItemsId) {
            $match = $entry;
            break;
        }
    }

    if ($match === null) {
        Session::addMessageAfterRedirect(
            __("Cette tâche n'est plus en attente (déjà notifiée ou n'est plus éligible)."),
            false,
            WARNING
        );
    } else {
        $processed = PluginSynchronisationcaloutlookCheck::sendPending([$match]);
        Session::addMessageAfterRedirect(
            $processed > 0
                ? sprintf(__('Invitation envoyée pour %s #%d.'), $targetItemtype, $targetItemsId)
                : sprintf(__("Échec de l'envoi pour %s #%d, voir le journal du plugin."), $targetItemtype, $targetItemsId),
            false,
            $processed > 0 ? INFO : ERROR
        );
    }

    Html::redirect(Plugin::getWebDir('synchronisationcaloutlook') . '/front/checknow.php');
}

if (isset($_POST['confirm'])) {
    // Recalcul à l'instant T (jamais de confiance dans une liste postée
    // depuis l'écran précédent : entre l'affichage et la confirmation, une
    // tâche a pu changer). C'est cette liste fraîche qui est réellement
    // envoyée, garantissant "ce qui a été prévisualisé" == "ce qui part".
    $pending = PluginSynchronisationcaloutlookCheck::findPending();
    $processed = PluginSynchronisationcaloutlookCheck::sendPending($pending);

    echo "<div class='center'>";
    echo "<p>" . sprintf(
        _n('%d invitation envoyée.', '%d invitations envoyées.', $processed),
        $processed
    ) . "</p>";
    echo "<a href='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/config.php'>" . __('Retour à la configuration') . "</a>";
    echo "</div>";

    Html::footer();
    exit;
}

$pending = PluginSynchronisationcaloutlookCheck::findPending();

echo "<div class='center'>";

$config = PluginSynchronisationcaloutlookConfig::getConfig();
if (empty($config['active'] ?? 1)) {
    echo "<p>" . __('Le plugin est actuellement désactivé ("Actif" = Non dans la config) : aucune vérification n\'est effectuée.') . "</p>";
    echo "<a href='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/config.php'>" . __('Retour à la configuration') . "</a>";
    echo "</div>";
    Html::footer();
    exit;
}

if (empty($pending)) {
    echo "<p>" . __('Aucune invitation manquante : toutes les tâches planifiées sont déjà notifiées.') . "</p>";
    echo "<a href='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/config.php'>" . __('Retour à la configuration') . "</a>";
    echo "</div>";
    Html::footer();
    exit;
}

$totalRecipients = [];
foreach ($pending as $entry) {
    foreach (array_keys($entry['recipients']) as $email) {
        $totalRecipients[$email] = true;
    }
}

echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='6'>"
    . sprintf(__('%d tâche(s) à notifier, %d destinataire(s) au total'), count($pending), count($totalRecipients))
    . "</th></tr>";
echo "<tr><th>" . __('Type') . "</th><th>" . __('Ticket') . "</th><th>" . __('Contenu de la tâche') . "</th><th>" . __('Début') . "</th><th>" . __('Destinataires') . "</th><th>" . __('Action') . "</th></tr>";

foreach ($pending as $entry) {
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . htmlspecialchars($entry['itemtype']) . " #" . (int) $entry['items_id'] . "</td>";

    // Lien vers le ticket/problème/changement parent, quand identifiable.
    if (!empty($entry['parent_url'])) {
        echo "<td><a href='" . htmlspecialchars($entry['parent_url']) . "' target='_blank'>"
            . htmlspecialchars($entry['parent_name'] ?: __('Voir'))
            . "</a></td>";
    } else {
        echo "<td>-</td>";
    }

    echo "<td>" . htmlspecialchars($entry['label']) . "</td>";
    echo "<td>" . htmlspecialchars($entry['begin']) . "</td>";
    echo "<td>" . htmlspecialchars(implode(', ', array_keys($entry['recipients']))) . "</td>";

    // Envoi ciblé de cette seule ligne : pratique pour tester ou
    // rattraper un cas précis sans relancer l'envoi groupé complet.
    echo "<td>";
    echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "' style='margin:0;'>";
    echo "<input type='hidden' name='itemtype' value='" . htmlspecialchars($entry['itemtype']) . "'>";
    echo "<input type='hidden' name='items_id' value='" . (int) $entry['items_id'] . "'>";
    // Jeton CSRF obligatoire sur tout POST GLPI/ITSM-NG (sinon "CSRF
    // token is invalid" au clic) : absent ici jusqu'ici, contrairement
    // au formulaire groupé plus bas qui passe par Html::closeForm()
    // (lequel l'injecte automatiquement). Ajouté explicitement (et pas
    // via Html::closeForm() ici) pour ne pas risquer d'ajouter un
    // balisage supplémentaire qui casserait la mise en page en ligne
    // dans la cellule du tableau.
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "<input type='submit' name='send_one' class='submit' "
        . "onclick=\"return confirm('" . htmlspecialchars(sprintf(__('Envoyer uniquement cette invitation (%s #%d) ?'), $entry['itemtype'], $entry['items_id']), ENT_QUOTES) . "');\" "
        . "value=\"" . htmlspecialchars(__('Envoyer')) . "\">";
    echo "</form>";
    echo "</td>";

    echo "</tr>";
}

echo "</table>";

echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "'>";
echo "<p>" . __('Confirmer l\'envoi de ces invitations ?') . "</p>";
echo "<input type='submit' name='confirm' class='submit' "
    . "onclick=\"return confirm('" . htmlspecialchars(sprintf(__('Envoyer %d invitations maintenant ?'), count($pending)), ENT_QUOTES) . "');\" "
    . "value=\"" . htmlspecialchars(__("Confirmer l'envoi")) . "\">";
Html::closeForm();

echo "<a href='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/config.php'>" . __('Annuler') . "</a>";
echo "</div>";

Html::footer();
