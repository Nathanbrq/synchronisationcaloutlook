<?php
/**
 * Plugin Synchronisation Cal Outlook
 *
 * Envoie automatiquement une invitation calendrier iCalendar (.ics,
 * RFC 5545, METHOD:REQUEST/CANCEL) par email dès qu'une TicketTask,
 * ProblemTask ou ChangeTask est planifiée (begin + end renseignés) et
 * assignée à un technicien ou à un groupe de techniciens.
 *
 * Transverse à toute l'instance : se déclenche sur les hooks item_add /
 * item_update / item_purge, quelle que soit l'origine de la planification
 * (interface web, API REST, workflow externe...).
 */

define('PLUGIN_SYNCHRONISATIONCALOUTLOOK_VERSION', '1.0.0');

/**
 * Initialisation du plugin : hooks sur les 3 itemtypes de tâches
 * planifiables, enregistrement de la classe de config, menu.
 */
function plugin_init_synchronisationcaloutlook() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['synchronisationcaloutlook'] = true;

    // Itemtypes concernés par la planification calendrier.
    $watched = [
        'TicketTask'  => 'plugin_synchronisationcaloutlook_item_add',
        'ProblemTask' => 'plugin_synchronisationcaloutlook_item_add',
        'ChangeTask'  => 'plugin_synchronisationcaloutlook_item_add',
    ];
    $PLUGIN_HOOKS['item_add']['synchronisationcaloutlook'] = $watched;

    $PLUGIN_HOOKS['item_update']['synchronisationcaloutlook'] = [
        'TicketTask'  => 'plugin_synchronisationcaloutlook_item_update',
        'ProblemTask' => 'plugin_synchronisationcaloutlook_item_update',
        'ChangeTask'  => 'plugin_synchronisationcaloutlook_item_update',
    ];

    // item_purge : suppression définitive. item_delete est aussi hooké au
    // cas où la mise en corbeille serait activée sur un de ces itemtypes
    // (pas le cas en configuration standard, mais coûte rien de le couvrir) ;
    // le handler est idempotent (no-op si aucun événement suivi).
    $PLUGIN_HOOKS['item_purge']['synchronisationcaloutlook'] = [
        'TicketTask'  => 'plugin_synchronisationcaloutlook_item_purge',
        'ProblemTask' => 'plugin_synchronisationcaloutlook_item_purge',
        'ChangeTask'  => 'plugin_synchronisationcaloutlook_item_purge',
    ];
    $PLUGIN_HOOKS['item_delete']['synchronisationcaloutlook'] = [
        'TicketTask'  => 'plugin_synchronisationcaloutlook_item_purge',
        'ProblemTask' => 'plugin_synchronisationcaloutlook_item_purge',
        'ChangeTask'  => 'plugin_synchronisationcaloutlook_item_purge',
    ];

    // Case "Ne pas envoyer d'invitation dans le calendrier" injectée dans
    // le formulaire natif des tâches (filtrage par itemtype fait dans le
    // callback, ce hook coeur n'est pas spécifique à un itemtype).
    $PLUGIN_HOOKS['post_item_form']['synchronisationcaloutlook'] = 'plugin_synchronisationcaloutlook_post_item_form';

    // Accès à la config uniquement via Configuration > Plugins (icône
    // "configurer" sur la ligne du plugin) : pas de raccourci dans le menu
    // Configuration, pas d'onglet ajouté sur la page Config du coeur.
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['synchronisationcaloutlook'] = 'front/config.php';
    }
}

function plugin_version_synchronisationcaloutlook() {
    return [
        'name'         => 'Synchronisation Cal Outlook',
        'description'  => "Envoie une invitation calendrier iCalendar (RFC 5545) par email "
            . "dès qu'une tâche (Ticket/Problem/Change) est planifiée et assignée à un "
            . "technicien ou un groupe de techniciens.",
        'version'      => PLUGIN_SYNCHRONISATIONCALOUTLOOK_VERSION,
        'author'       => 'HOP!',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://gitlab.hop.fr/infrastructure/applications/concord/plugins/synchronisationcaloutlook',
        'requirements' => [
            // Testé sur ITSM-NG 2.1.4. Dépend uniquement du coeur
            // (TicketTask/ProblemTask/ChangeTask + GLPIMailer), aucun
            // plugin tiers requis.
        ],
    ];
}

// Prérequis techniques : les classes coeur utilisées doivent exister.
// (protège contre une activation sur une version du coeur trop ancienne
// ou trop divergente pour porter ces itemtypes / GLPIMailer).
function plugin_synchronisationcaloutlook_check_prerequisites() {
    $required = ['TicketTask', 'ProblemTask', 'ChangeTask', 'GLPIMailer'];
    foreach ($required as $class) {
        if (!class_exists($class)) {
            echo "La classe coeur <b>{$class}</b> est introuvable : version d'ITSM-NG incompatible.<br>";
            return false;
        }
    }
    return true;
}

function plugin_synchronisationcaloutlook_check_config($verbose = false) {
    return true;
}
