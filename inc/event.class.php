<?php

/**
 * CRUD sur glpi_plugin_synchronisationcaloutlook_events : suit, pour chaque tâche
 * planifiée (itemtype + items_id), l'UID iCal généré, le SEQUENCE
 * courant et la liste des derniers destinataires notifiés — nécessaire
 * pour calculer les REQUEST/CANCEL différentiels lors des mises à jour.
 *
 * Table interne uniquement (pas d'onglet ni de recherche exposée) :
 * pas de rawSearchOptions, pas de showForm.
 */
class PluginSynchronisationcaloutlookEvent extends CommonDBTM {

    public static $rightname = 'config';

    public static function getTypeName($nb = 0) {
        return __('Événement Synchronisation Cal Outlook');
    }

    /**
     * Récupère la ligne de suivi pour un item donné, ou null si cette
     * tâche n'a jamais été notifiée.
     */
    public static function getForItem(string $itemtype, int $items_id): ?array {
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            return $row;
        }
        return null;
    }

    /**
     * UID iCal stable et unique pour une tâche donnée. Basé sur
     * itemtype + items_id (stables pour la durée de vie de la tâche) et
     * le domaine de l'instance, conformément au format demandé :
     * itsmng-{itemtype}-{items_id}@rct-concord.hop.fr
     */
    public static function generateUid(string $itemtype, int $items_id): string {
        return 'itsmng-' . strtolower($itemtype) . '-' . $items_id . '@rct-concord.hop.fr';
    }

    /**
     * Enregistre (insert ou update) l'état courant de notification pour
     * une tâche : UID, sequence, et destinataires de ce dernier envoi.
     */
    public static function save(string $itemtype, int $items_id, string $uid, int $sequence, array $recipientEmails): void {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $data = [
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'ical_uid'        => $uid,
            'sequence'        => $sequence,
            'last_recipients' => json_encode(array_values(array_unique($recipientEmails))),
            'date_mod'        => $now,
        ];

        $existing = self::getForItem($itemtype, $items_id);
        if ($existing === null) {
            $data['date_creation'] = $now;
            $DB->insert(self::getTable(), $data);
        } else {
            $DB->update(self::getTable(), $data, ['id' => $existing['id']]);
        }
    }

    /**
     * Supprime le suivi d'une tâche (après annulation complète : tâche
     * purgée, déplanifiée, ou tous les destinataires retirés).
     */
    public static function deleteForItem(string $itemtype, int $items_id): void {
        global $DB;

        $DB->delete(self::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ]);
    }

    /**
     * Décode la liste des derniers destinataires notifiés (emails) à
     * partir d'une ligne de suivi.
     */
    public static function getLastRecipients(array $eventRow): array {
        if (empty($eventRow['last_recipients'])) {
            return [];
        }
        $decoded = json_decode($eventRow['last_recipients'], true);
        return is_array($decoded) ? $decoded : [];
    }
}
