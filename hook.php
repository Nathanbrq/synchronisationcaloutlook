<?php

/**
 * Installation : crée la table de config (ligne unique id=1) et la table
 * de suivi des événements calendrier envoyés.
 */
function plugin_synchronisationcaloutlook_install() {
    global $DB;

    $default_charset   = 'utf8mb4';
    $default_collation = 'utf8mb4_unicode_ci';

    if (!$DB->tableExists('glpi_plugin_synchronisationcaloutlook_configs')) {
        $query = "CREATE TABLE `glpi_plugin_synchronisationcaloutlook_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `organizer_email` varchar(255) NOT NULL DEFAULT 'noreply@rct-concord.hop.fr',
            `enable_tickettask` tinyint NOT NULL DEFAULT 1,
            `enable_problemtask` tinyint NOT NULL DEFAULT 1,
            `enable_changetask` tinyint NOT NULL DEFAULT 1,
            `mailcollectors_id` int unsigned NOT NULL DEFAULT 0,
            `smtp_host` varchar(255) NOT NULL DEFAULT '',
            `smtp_port` int NOT NULL DEFAULT 587,
            `smtp_secure` varchar(10) NOT NULL DEFAULT 'tls',
            `smtp_login` varchar(255) NOT NULL DEFAULT '',
            `smtp_password` varchar(255) NOT NULL DEFAULT '',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};";

        $DB->query($query) or die($DB->error());
    }

    // Migration : colonne de liaison vers un collecteur de mails natif dont
    // les identifiants (login/mot de passe) seront réutilisés à l'envoi.
    // Idempotent, pour une instance déjà installée en 1.0.0 sans cette colonne.
    if (!$DB->fieldExists('glpi_plugin_synchronisationcaloutlook_configs', 'mailcollectors_id')) {
        $DB->query(
            "ALTER TABLE `glpi_plugin_synchronisationcaloutlook_configs`
             ADD COLUMN `mailcollectors_id` int unsigned NOT NULL DEFAULT 0 AFTER `enable_changetask`"
        ) or die($DB->error());
    }

    // Migration : connexion SMTP manuelle (utilisée uniquement quand aucun
    // collecteur n'est sélectionné), sur le même principe que les champs
    // imap_host/imap_port/imap_options/imap_login/imap_password du plugin
    // collecteurformcreator, adaptés ici à un envoi SMTP.
    $smtpColumns = [
        'smtp_host'     => "varchar(255) NOT NULL DEFAULT ''",
        'smtp_port'     => "int NOT NULL DEFAULT 587",
        'smtp_secure'   => "varchar(10) NOT NULL DEFAULT 'tls'",
        'smtp_login'    => "varchar(255) NOT NULL DEFAULT ''",
        'smtp_password' => "varchar(255) NOT NULL DEFAULT ''",
    ];
    $previousColumn = 'mailcollectors_id';
    foreach ($smtpColumns as $column => $definition) {
        if (!$DB->fieldExists('glpi_plugin_synchronisationcaloutlook_configs', $column)) {
            $DB->query(
                "ALTER TABLE `glpi_plugin_synchronisationcaloutlook_configs`
                 ADD COLUMN `{$column}` {$definition} AFTER `{$previousColumn}`"
            ) or die($DB->error());
        }
        $previousColumn = $column;
    }

    // Migration : case "config SMTP standard" (prioritaire sur
    // collecteur/manuel quand cochée).
    $switchColumns = [
        'use_standard_smtp'    => "tinyint NOT NULL DEFAULT 1",
        // Interrupteur global du plugin : coupe tous les envois (temps
        // réel ET rattrapage manuel) sans désactiver le plugin lui-même
        // au sens GLPI (Configuration > Plugins), ce qui couperait aussi
        // l'accès à la config/aux gabarits/à l'historique. Actif par
        // défaut pour ne rien changer au comportement d'une instance déjà
        // en place lors de la mise à jour.
        'active'               => "tinyint NOT NULL DEFAULT 1",
    ];
    foreach ($switchColumns as $column => $definition) {
        if (!$DB->fieldExists('glpi_plugin_synchronisationcaloutlook_configs', $column)) {
            $DB->query(
                "ALTER TABLE `glpi_plugin_synchronisationcaloutlook_configs`
                 ADD COLUMN `{$column}` {$definition}"
            ) or die($DB->error());
        }
    }

    // Migration (nettoyage) : templates_id n'a plus de sens depuis le
    // passage à un gabarit singleton (un seul gabarit possible, utilisé
    // automatiquement s'il existe — cf. inc/template.class.php). Colonne
    // supprimée pour une instance mise à jour depuis une version
    // antérieure à plusieurs gabarits sélectionnables.
    if ($DB->fieldExists('glpi_plugin_synchronisationcaloutlook_configs', 'templates_id')) {
        $DB->query(
            "ALTER TABLE `glpi_plugin_synchronisationcaloutlook_configs`
             DROP COLUMN `templates_id`"
        ) or die($DB->error());
    }

    // Migration (nettoyage) : notify_group_members retiré — le groupe
    // est désormais TOUJOURS notifié dès qu'il est renseigné, qu'un
    // technicien individuel le soit aussi ou non (le besoin est de
    // notifier toute personne assignée, sans exception). L'option n'avait
    // plus de raison d'être qu'à "Oui".
    if ($DB->fieldExists('glpi_plugin_synchronisationcaloutlook_configs', 'notify_group_members')) {
        $DB->query(
            "ALTER TABLE `glpi_plugin_synchronisationcaloutlook_configs`
             DROP COLUMN `notify_group_members`"
        ) or die($DB->error());
    }

    // Migration (nettoyage) : la case "Activer le rattrapage automatique
    // horaire" et la tâche cron associée ont été retirées — les envois
    // temps réel (hooks item_add/item_update) couvrent déjà tous les cas
    // normaux, et le rattrapage manuel ("Vérifier maintenant") reste le
    // seul outil de rattrapage. Suppression idempotente de la colonne
    // pour une instance mise à jour depuis une version antérieure.
    if ($DB->fieldExists('glpi_plugin_synchronisationcaloutlook_configs', 'enable_auto_catchup')) {
        $DB->query(
            "ALTER TABLE `glpi_plugin_synchronisationcaloutlook_configs`
             DROP COLUMN `enable_auto_catchup`"
        ) or die($DB->error());
    }

    // Ligne de config unique (id=1), créée une seule fois. Les mises à jour
    // ultérieures passent uniquement par front/config.form.php.
    $existing = $DB->request(['FROM' => 'glpi_plugin_synchronisationcaloutlook_configs', 'WHERE' => ['id' => 1]]);
    if (count($existing) === 0) {
        $DB->insert('glpi_plugin_synchronisationcaloutlook_configs', [
            'id'                    => 1,
            'organizer_email'       => 'noreply@rct-concord.hop.fr',
            'enable_tickettask'     => 1,
            'enable_problemtask'    => 1,
            'enable_changetask'     => 1,
            'mailcollectors_id'     => 0,
            'smtp_host'             => '',
            'smtp_port'             => 587,
            'smtp_secure'           => 'tls',
            'smtp_login'            => '',
            'smtp_password'         => '',
            'use_standard_smtp'     => 1,
            'active'                => 1,
            'date_creation'         => date('Y-m-d H:i:s'),
            'date_mod'              => date('Y-m-d H:i:s'),
        ]);
    }

    if (!$DB->tableExists('glpi_plugin_synchronisationcaloutlook_events')) {
        $query = "CREATE TABLE `glpi_plugin_synchronisationcaloutlook_events` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int unsigned NOT NULL,
            `ical_uid` varchar(255) NOT NULL,
            `sequence` int unsigned NOT NULL DEFAULT 0,
            `last_recipients` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`itemtype`, `items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};";

        $DB->query($query) or die($DB->error());
    }

    // Gabarit propre au plugin (table dédiée ci-dessous, singleton
    // id=1), pas un modèle de notification natif ITSM-NG : évite la
    // confusion de balises et le risque de modifier un modèle partagé
    // avec de vraies notifications ailleurs dans l'instance.
    if (!$DB->tableExists('glpi_plugin_synchronisationcaloutlook_templates')) {
        $query = "CREATE TABLE `glpi_plugin_synchronisationcaloutlook_templates` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `subject` varchar(255) NOT NULL DEFAULT '',
            `content` text,
            `content_html` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};";

        $DB->query($query) or die($DB->error());
    }

    // Migration : corps HTML optionnel (logo, mise en page). Vide par
    // défaut = comportement inchangé (ICS en corps principal du mail,
    // pour préserver l'invitation native Accepter/Décliner). Rempli =
    // le mail passe en HTML + texte de secours, ICS en pièce jointe
    // method=REQUEST plutôt qu'en corps principal.
    if (!$DB->fieldExists('glpi_plugin_synchronisationcaloutlook_templates', 'content_html')) {
        $DB->query(
            "ALTER TABLE `glpi_plugin_synchronisationcaloutlook_templates`
             ADD COLUMN `content_html` text AFTER `content`"
        ) or die($DB->error());
    }

    // Table d'exclusion : tâches marquées "Ne pas envoyer d'invitation
    // dans le calendrier" depuis le formulaire natif TicketTask/
    // ProblemTask/ChangeTask (case injectée via le hook post_item_form).
    if (!$DB->tableExists('glpi_plugin_synchronisationcaloutlook_exclusions')) {
        $query = "CREATE TABLE `glpi_plugin_synchronisationcaloutlook_exclusions` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int unsigned NOT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`itemtype`, `items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};";

        $DB->query($query) or die($DB->error());
    }

    // Migration (nettoyage) : suppression de l'ancienne tâche cron
    // automatique d'une installation antérieure à cette version — le
    // rattrapage se fait désormais uniquement à la demande, via le
    // bouton "Vérifier maintenant" (cf. inc/check.class.php). Aucune
    // tâche planifiée n'est recréée.
    $DB->delete('glpi_crontasks', ['OR' => [
        'itemtype' => 'PluginSynchronisationcaloutlookCron',
        'name'     => 'SynchronisationcaloutlookCheck',
    ]]);

    // Rafraîchit explicitement les métadonnées stockées par GLPI dans
    // glpi_plugins (name/author/homepage/license) : ces colonnes sont
    // écrites une fois à l'installation et ne sont PAS relues depuis
    // plugin_version_synchronisationcaloutlook() à chaque affichage de
    // la page Configuration > Plugins. Sans cette mise à jour explicite,
    // changer l'auteur/le lien dans setup.php n'aurait d'effet qu'après
    // une DÉSINSTALLATION complète suivie d'une réinstallation — une
    // simple désactivation/réactivation ne suffit pas forcément selon la
    // version du coeur. Idempotent, sans risque à rejouer.
    $DB->update(
        'glpi_plugins',
        [
            'name'     => 'Synchronisation Cal Outlook',
            'author'   => 'HOP!',
            'homepage' => 'https://gitlab.hop.fr/infrastructure/applications/itsmng-workflows',
            'license'  => 'GPLv2+',
        ],
        ['directory' => 'synchronisationcaloutlook']
    );

    // Import automatique du gabarit depuis le dossier gabarit/ du plugin,
    // UNIQUEMENT si aucun gabarit n'existe encore en base : permet de
    // faire monter la config directement via Docker (fichiers copiés/
    // montés dans gabarit/ au build/démarrage du conteneur, sans clic
    // manuel sur "Importer"). Ne s'exécute qu'à l'installation — pas à
    // chaque activation — et n'écrase jamais un gabarit déjà configuré
    // manuellement (cf. inc/template.class.php::importFromFiles(), la
    // même méthode que le bouton "Importer" de l'interface). Une absence
    // du dossier ou de fichiers dedans n'est pas une erreur : c'est le
    // cas normal d'une instance sans config à provisionner.
    if (!PluginSynchronisationcaloutlookTemplate::exists()) {
        try {
            (new PluginSynchronisationcaloutlookTemplate())->importFromFiles();
        } catch (\Throwable $e) {
            // Silencieux : la plupart du temps ça veut juste dire qu'il
            // n'y a rien à importer (dossier gabarit/ absent ou vide),
            // ce qui est le cas normal hors provisioning Docker.
        }
    }

    return true;
}

/**
 * Désinstallation : suppression propre des tables du plugin. Aucun droit
 * dédié n'a été créé (le plugin réutilise le droit natif 'config'), donc
 * rien à purger côté glpi_profilerights.
 *
 * Le nettoyage de l'éventuelle tâche cron d'une ancienne version (retirée
 * depuis, cf. plugin_synchronisationcaloutlook_install) est conservé ici
 * par sécurité, au cas où une désinstallation interviendrait avant la
 * prochaine réactivation/migration.
 */
function plugin_synchronisationcaloutlook_uninstall() {
    global $DB;

    $DB->query("DROP TABLE IF EXISTS `glpi_plugin_synchronisationcaloutlook_configs`");
    $DB->query("DROP TABLE IF EXISTS `glpi_plugin_synchronisationcaloutlook_events`");
    $DB->query("DROP TABLE IF EXISTS `glpi_plugin_synchronisationcaloutlook_templates`");
    $DB->query("DROP TABLE IF EXISTS `glpi_plugin_synchronisationcaloutlook_exclusions`");

    $DB->delete('glpi_crontasks', ['OR' => [
        'itemtype' => 'PluginSynchronisationcaloutlookCron',
        'name'     => 'SynchronisationcaloutlookCheck',
    ]]);

    return true;
}

/**
 * Callbacks référencés par PLUGIN_HOOKS dans setup.php. Volontairement
 * minces : toute la logique vit dans inc/notifier.class.php (testable),
 * ces fonctions ne font que le pont attendu par le coeur GLPI/ITSM-NG.
 */

/**
 * Toutes les traces "hookEntry" ci-dessous sont volontairement en dehors
 * de toute condition métier (avant tout filtrage exclusion/begin/end/
 * config) : elles confirment que le hook coeur a bien été appelé pour
 * cette tâche, quel que soit ce qui se passe ensuite. Sans cette trace,
 * un cas où le hook n'est JAMAIS déclenché (ex: le widget Planning de
 * GLPI/ITSM-NG enregistre begin/end via un appel AJAX dédié qui ne passe
 * pas par CommonDBTM::update(), et donc ne déclenche aucun hook item_*)
 * est indiscernable d'un cas où le hook est appelé mais où le traitement
 * s'arrête silencieusement plus loin — les deux situations produisaient
 * un fichier de log vide, impossible à diagnostiquer à distance.
 */
function plugin_synchronisationcaloutlook_hookEntry(string $event, CommonDBTM $item): void {
    Toolbox::logInFile(
        'synchronisationcaloutlook',
        sprintf(
            "[%s] HOOK %s itemtype=%s items_id=%d begin=%s end=%s oldvalues_keys=%s input_keys=%s\n",
            date('Y-m-d H:i:s'),
            $event,
            get_class($item),
            (int) $item->getID(),
            $item->fields['begin'] ?? '(vide)',
            $item->fields['end'] ?? '(vide)',
            implode(',', array_keys($item->oldvalues ?? [])) ?: '(aucun)',
            implode(',', array_keys($item->input ?? [])) ?: '(aucun)'
        )
    );
}

function plugin_synchronisationcaloutlook_item_add(CommonDBTM $item) {
    plugin_synchronisationcaloutlook_hookEntry('item_add', $item);
    PluginSynchronisationcaloutlookExclusion::syncFromInput($item);
    PluginSynchronisationcaloutlookNotifier::onItemAdd($item);
}

/**
 * item_update : synchronise d'abord la case d'exclusion, puis ne
 * déclenche le traitement métier que si un champ surveillé a changé —
 * OU si l'exclusion elle-même vient de changer d'état. Ce second cas est
 * nécessaire car la case "Ne pas envoyer d'invitation" vit dans une
 * table séparée (pas un champ natif de la tâche) : elle n'apparaît donc
 * jamais dans $item->oldvalues, et sans cette détection explicite,
 * décocher la case puis enregistrer (sans toucher begin/end/tech/groupe)
 * ne déclenchait aucun envoi alors que la tâche devient éligible.
 */
function plugin_synchronisationcaloutlook_item_update(CommonDBTM $item) {
    plugin_synchronisationcaloutlook_hookEntry('item_update', $item);

    $itemtype = get_class($item);
    $items_id = (int) $item->getID();

    $wasExcluded = PluginSynchronisationcaloutlookExclusion::isExcluded($itemtype, $items_id);
    PluginSynchronisationcaloutlookExclusion::syncFromInput($item);
    $isExcluded = PluginSynchronisationcaloutlookExclusion::isExcluded($itemtype, $items_id);

    PluginSynchronisationcaloutlookNotifier::onItemUpdate($item, $wasExcluded !== $isExcluded);
}

function plugin_synchronisationcaloutlook_item_purge(CommonDBTM $item) {
    PluginSynchronisationcaloutlookNotifier::onItemPurge($item);
    PluginSynchronisationcaloutlookExclusion::clearExcluded(get_class($item), (int) $item->getID());
}

/**
 * Injecte la case "Ne pas envoyer d'invitation dans le calendrier" dans
 * le formulaire natif TicketTask/ProblemTask/ChangeTask, comme ligne
 * supplémentaire du tableau natif du widget "Planning"
 * (table[aria-label="Event Classic Form"], confirmé par inspection du
 * HTML réel de ce formulaire).
 *
 * Ce tableau est chargé de façon asynchrone (AJAX) après ce hook : un
 * script exécuté immédiatement ne le trouve donc pas encore. On observe
 * le DOM (MutationObserver) jusqu'à son apparition, avec un repli visible
 * (bloc encadré, hors du widget Planning) si jamais il n'apparaît pas
 * dans un délai raisonnable — pour ne jamais perdre silencieusement la
 * case.
 */
function plugin_synchronisationcaloutlook_post_item_form($params) {
    $item = $params['item'] ?? null;
    if (!($item instanceof CommonDBTM)) {
        return;
    }

    $itemtype = get_class($item);
    if (!in_array($itemtype, ['TicketTask', 'ProblemTask', 'ChangeTask'], true)) {
        return;
    }

    $items_id = (int) $item->getID();
    $excluded = $items_id > 0
        ? PluginSynchronisationcaloutlookExclusion::isExcluded($itemtype, $items_id)
        : false;

    // Identifiants uniques par tâche : évite toute collision si plusieurs
    // formulaires de ce type venaient à coexister sur la même page.
    $uid = $itemtype . '-' . $items_id . '-' . mt_rand(1000, 9999);
    $holderId   = 'synchronisationcaloutlook-noinvite-holder-' . $uid;
    $checkboxId = 'synchronisationcaloutlook-noinvite-checkbox-' . $uid;
    $checkedAttr = $excluded ? ' checked' : '';

    $labelFull  = json_encode(__("Ne pas envoyer d'invitation dans le calendrier"));
    $hiddenId   = 'synchronisationcaloutlook-noinvite-hidden-' . $uid;

    // Champ caché value=0 avant la checkbox : une case DÉCOCHÉE n'est
    // jamais envoyée par le navigateur dans le POST (comportement HTML
    // standard). Sans ce champ, décocher puis enregistrer laisse la clé
    // "_no_calendar_invite" totalement absente de l'input, et
    // PluginSynchronisationcaloutlookExclusion::syncFromInput() (qui ne
    // touche à rien si la clé est absente, pour ne pas écraser un état
    // non lié à ce plugin) ne fait alors rien : l'exclusion reste
    // active. Avec les deux champs de même name, PHP ne garde que la
    // dernière valeur du POST : "0" si la case est décochée (seul le
    // hidden est envoyé), "1" si elle est cochée (le navigateur envoie
    // les deux, mais la checkbox suit le hidden dans l'ordre du DOM).
    echo "<div id='{$holderId}' style='display:none;'>";
    echo "<input type='hidden' id='{$hiddenId}' name='_no_calendar_invite' value='0'>";
    echo "<input type='checkbox' id='{$checkboxId}' name='_no_calendar_invite' value='1'{$checkedAttr}>";
    echo "</div>";

    echo "<script>";
    echo "(function() {";
    echo "  var holder = document.getElementById('{$holderId}');";
    echo "  var checkbox = document.getElementById('{$checkboxId}');";
    echo "  var hidden = document.getElementById('{$hiddenId}');";
    echo "  if (!holder || !checkbox || !hidden) { return; }";
    echo "";
    echo "  function showFallback() {";
    echo "    holder.style.display = '';";
    echo "    holder.style.margin = '12px 0';";
    echo "    holder.style.padding = '10px 12px';";
    echo "    holder.style.border = '1px solid #ccc';";
    echo "    holder.style.borderRadius = '4px';";
    echo "    holder.style.background = '#f8f8f8';";
    echo "    var label = document.createElement('label');";
    echo "    label.style.cursor = 'pointer';";
    echo "    label.style.display = 'flex';";
    echo "    label.style.alignItems = 'center';";
    echo "    label.style.gap = '6px';";
    echo "    label.appendChild(hidden);";
    echo "    label.appendChild(checkbox);";
    echo "    var span = document.createElement('span');";
    echo "    span.textContent = {$labelFull};";
    echo "    label.appendChild(span);";
    echo "    holder.appendChild(label);";
    echo "  }";
    echo "";
    echo "  function tryAttach() {";
    echo "    var table = document.querySelector('table[aria-label=\"Event Classic Form\"]');";
    echo "    if (!table) { return false; }";
    echo "    var tbody = table.querySelector('tbody') || table;";
    echo "    var tr = document.createElement('tr');";
    echo "    tr.className = 'tab_bg_2';";
    echo "    var tdLabel = document.createElement('td');";
    echo "    tdLabel.textContent = {$labelFull};";
    // Séparation visuelle du bloc natif au-dessus (ex: ligne "Rappel") :
    // filet + espace supplémentaire, pour que la case ne se fonde pas
    // dans les lignes du widget Planning.
    echo "    tdLabel.style.borderTop = '2px solid #ccc';";
    echo "    tdLabel.style.paddingTop = '14px';";
    echo "    var tdInput = document.createElement('td');";
    echo "    tdInput.appendChild(hidden);";
    echo "    tdInput.appendChild(checkbox);";
    echo "    tdInput.style.borderTop = '2px solid #ccc';";
    echo "    tdInput.style.paddingTop = '14px';";
    echo "    tr.appendChild(tdLabel);";
    echo "    tr.appendChild(tdInput);";
    echo "    tbody.appendChild(tr);";
    echo "    holder.parentNode.removeChild(holder);";
    echo "    return true;";
    echo "  }";
    echo "";
    echo "  if (tryAttach()) { return; }";
    echo "";
    echo "  /* Tableau pas encore chargé (rendu AJAX asynchrone, potentiellement seulement au moment où l'utilisateur déplie le panneau Planning) : on observe le DOM SANS jamais abandonner tant que la case n'est pas réellement dans le tableau natif. Le repli visuel à 6s n'est qu'un affichage temporaire en attendant : si le tableau apparaît ensuite (panneau ouvert plus tard), la case est rapatriée dedans et le repli disparaît. */";
    echo "  var attached = false;";
    echo "  var observer = new MutationObserver(function() {";
    echo "    if (attached) { return; }";
    echo "    if (tryAttach()) {";
    echo "      attached = true;";
    echo "      observer.disconnect();";
    echo "    }";
    echo "  });";
    echo "  observer.observe(document.body, { childList: true, subtree: true });";
    echo "";
    echo "  setTimeout(function() {";
    echo "    if (attached) { return; }";
    echo "    if (document.getElementById('{$holderId}')) {";
    echo "      showFallback();";
    echo "    }";
    echo "  }, 6000);";
    echo "})();";
    echo "</script>";
}
