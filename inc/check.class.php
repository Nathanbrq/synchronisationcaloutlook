<?php

/**
 * Rattrapage manuel ("Vérifier maintenant") : énumère puis envoie, à la
 * demande explicite d'un administrateur, les invitations calendrier pour
 * les tâches planifiées qui n'ont jamais été notifiées.
 *
 * Historique : ce mécanisme portait à l'origine aussi une tâche cron
 * automatique horaire (classe alors nommée ...Cron, avec cronInfo() /
 * cronSynchronisationcaloutlookCheck()). Elle a été retirée : les envois
 * "temps réel" (hooks item_add / item_update, cf. hook.php) couvrent
 * déjà 100% des cas normaux (web, API, workflow externe), et la tâche
 * planifiée n'apportait de valeur que dans un cas très étroit (tâche
 * créée en contournant totalement les hooks : import SQL direct, plugin
 * désactivé au moment de la création). Le bouton manuel avec
 * prévisualisation (front/checknow.php) reste le seul outil de
 * rattrapage, volontairement non automatique pour éviter tout envoi de
 * masse silencieux (cf. incident ~3400 emails lors du tout premier
 * développement de ce mécanisme).
 *
 * Ne renvoie jamais un email pour une tâche déjà notifiée : ce
 * rattrapage ne fait que combler les manques, il ne duplique pas le
 * comportement temps réel d'item_update (diff REQUEST/CANCEL), qui reste
 * seul responsable des mises à jour d'une tâche déjà suivie.
 */
class PluginSynchronisationcaloutlookCheck {

    const WATCHED_ITEMTYPES = ['TicketTask', 'ProblemTask', 'ChangeTask'];

    const ITEMTYPE_CONFIG_KEY = [
        'TicketTask'  => 'enable_tickettask',
        'ProblemTask' => 'enable_problemtask',
        'ChangeTask'  => 'enable_changetask',
    ];

    /**
     * Énumère, sans rien envoyer, les tâches planifiées jamais notifiées
     * (même règles que le traitement temps réel : begin/end renseignés,
     * itemtype activé, début non déjà passé). Utilisé par l'écran de
     * prévisualisation (front/checknow.php) et par l'envoi réel, pour
     * garantir que "ce qui est prévisualisé" == "ce qui sera envoyé".
     *
     * @return array [ ['itemtype'=>.., 'items_id'=>.., 'label'=>.., 'begin'=>..,
     *                   'recipients'=>[email=>nom], 'parent_name'=>.., 'parent_url'=>..], ... ]
     */
    public static function findPending(): array {
        global $DB;

        $config = PluginSynchronisationcaloutlookConfig::getConfig();

        // Interrupteur global "Actif" : si coupé, aucune tâche n'est
        // proposée au rattrapage — cohérent avec le temps réel (cf.
        // PluginSynchronisationcaloutlookNotifier::process()), pour que
        // "Vérifier maintenant" ne redevienne pas une porte de sortie
        // quand l'admin a volontairement mis le plugin en pause.
        if (empty($config['active'] ?? 1)) {
            return [];
        }

        $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        $pending = [];

        foreach (self::WATCHED_ITEMTYPES as $itemtype) {
            $configKey = self::ITEMTYPE_CONFIG_KEY[$itemtype];
            if (empty($config[$configKey])) {
                continue;
            }

            $reference = new $itemtype();
            $table = $reference->getTable();

            $result = $DB->query("SELECT `id` FROM `{$table}` WHERE `begin` IS NOT NULL AND `end` IS NOT NULL");
            if (!$result) {
                continue;
            }

            while ($row = $DB->fetchAssoc($result)) {
                $items_id = (int) $row['id'];

                if (PluginSynchronisationcaloutlookEvent::getForItem($itemtype, $items_id) !== null) {
                    continue; // déjà notifiée, hors périmètre du rattrapage
                }

                $item = new $itemtype();
                if (!$item->getFromDB($items_id)) {
                    continue;
                }

                // Case "Ne pas envoyer d'invitation" cochée sur la tâche :
                // exclue du rattrapage, y compris envoi forcé via le
                // bouton "Vérifier maintenant".
                if (PluginSynchronisationcaloutlookExclusion::isExcluded($itemtype, $items_id)) {
                    continue;
                }

                // Tâche déjà marquée "Réalisé" (case cochée) : n'a plus de
                // sens d'envoyer une invitation pour un rendez-vous déjà
                // exécuté. Planning::DONE est la constante coeur GLPI pour
                // cet état sur les tâches ITIL (TicketTask/ProblemTask/
                // ChangeTask) ; repli sur la valeur 1 si la classe n'est
                // pas chargée à ce stade.
                $doneState = class_exists('Planning') ? Planning::DONE : 1;
                if ((int) ($item->fields['state'] ?? 0) === (int) $doneState) {
                    continue;
                }

                // Ticket/problème/changement parent déjà résolu ou clos :
                // même règle que le traitement temps réel.
                if (PluginSynchronisationcaloutlookNotifier::isParentResolved($item)) {
                    continue;
                }

                // Même sécurité que le traitement temps réel : un début
                // déjà passé n'est jamais notifié.
                try {
                    $beginDate = new DateTime((string) $item->fields['begin'], new DateTimeZone(date_default_timezone_get()));
                    if ($beginDate <= $now) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    continue;
                }

                $recipients = PluginSynchronisationcaloutlookNotifier::resolveRecipients($item, $config);
                if (empty($recipients)) {
                    continue; // rien à envoyer, pas de destinataire valide
                }

                $parentLink = PluginSynchronisationcaloutlookNotifier::getParentLink($item);

                $pending[] = [
                    'itemtype'    => $itemtype,
                    'items_id'    => $items_id,
                    'label'       => PluginSynchronisationcaloutlookNotifier::taskContentPreview($item),
                    'begin'       => $item->fields['begin'],
                    'recipients'  => $recipients,
                    'parent_name' => $parentLink['name'],
                    'parent_url'  => $parentLink['url'],
                ];
            }
        }

        return $pending;
    }

    /**
     * Envoie réellement les invitations pour la liste fournie (issue de
     * findPending(), typiquement après confirmation explicite de
     * l'utilisateur sur l'écran de prévisualisation).
     *
     * @return int Nombre de tâches effectivement traitées.
     */
    public static function sendPending(array $pending): int {
        $processed = 0;

        foreach ($pending as $entry) {
            $item = new $entry['itemtype']();
            if (!$item->getFromDB($entry['items_id'])) {
                continue;
            }

            // Réutilise exactement la même logique que le hook item_add
            // (résolution destinataires, config, envoi, persistance de
            // l'UID/sequence) : aucune duplication de règle métier.
            PluginSynchronisationcaloutlookNotifier::onItemAdd($item);
            $processed++;
        }

        return $processed;
    }
}
