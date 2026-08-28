<?php

/**
 * Persiste, par tâche (itemtype + items_id), la case "Ne pas envoyer
 * d'invitation dans le calendrier" injectée dans le formulaire natif
 * TicketTask/ProblemTask/ChangeTask via le hook post_item_form.
 *
 * Simple table d'existence : une ligne présente = exclue. Vérifiée par
 * PluginSynchronisationcaloutlookNotifier::process() (temps réel) et
 * PluginSynchronisationcaloutlookCheck::findPending() (rattrapage) avant
 * tout traitement — y compris l'envoi forcé depuis "Vérifier maintenant".
 */
class PluginSynchronisationcaloutlookExclusion extends CommonDBTM {

    public static $rightname = 'config';

    public static function isExcluded(string $itemtype, int $items_id): bool {
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'LIMIT' => 1,
        ]);

        return count($rows) > 0;
    }

    public static function setExcluded(string $itemtype, int $items_id): void {
        global $DB;

        if (self::isExcluded($itemtype, $items_id)) {
            return;
        }

        $DB->insert(self::getTable(), [
            'itemtype'      => $itemtype,
            'items_id'      => $items_id,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function clearExcluded(string $itemtype, int $items_id): void {
        global $DB;

        $DB->delete(self::getTable(), ['itemtype' => $itemtype, 'items_id' => $items_id]);
    }

    /**
     * Synchronise l'exclusion à partir de l'input reçu par add()/update()
     * (formulaire natif ou API REST avec la même clé). $item->input reste
     * peuplé par le coeur après add()/update(), avant l'appel des hooks
     * item_add/item_update — c'est ce qui permet d'intercepter un champ
     * ajouté par le plugin sans toucher au code natif des itemtypes.
     *
     * Si la clé est absente de l'input (formulaire natif non modifié par
     * ce plugin, appel API sans ce champ), on ne touche à rien : l'état
     * précédent est conservé tel quel.
     */
    public static function syncFromInput(CommonDBTM $item): void {
        $input = $item->input ?? [];
        if (!array_key_exists('_no_calendar_invite', $input)) {
            return;
        }

        $itemtype = get_class($item);
        $items_id = (int) $item->getID();

        if (!empty($input['_no_calendar_invite'])) {
            self::setExcluded($itemtype, $items_id);
        } else {
            self::clearExcluded($itemtype, $items_id);
        }
    }
}
