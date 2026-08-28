<?php

/**
 * Configuration du plugin Synchronisation Cal Outlook : une ligne unique (id=1).
 *
 * Droit natif GLPI/ITSM-NG 'config' (réservé au profil Super-Admin par
 * défaut), pas de droit dédié.
 */
class PluginSynchronisationcaloutlookConfig extends CommonDBTM {

    public static $rightname = 'config';

    const CONFIG_ID = 1;

    // Utilisé uniquement en dernier recours si aucune config SMTP
    // n'aboutit à une adresse exploitable (fallback silencieux).
    const DEFAULT_ORGANIZER_EMAIL = 'noreply@rct-concord.hop.fr';

    public static function getTypeName($nb = 0) {
        return __('Configuration Synchronisation Cal Outlook');
    }

    public static function getIcon() {
        return 'fas fa-calendar-check';
    }

    /**
     * Toggle on/off natif ITSM-NG (Bootstrap : .form-switch +
     * .form-check-input[type=checkbox][role=switch]), repris à l'identique
     * du cœur (ex: "Utiliser l'autocomplétion" en config générale) plutôt
     * qu'un Dropdown::showYesNo() — plus lisible d'un coup d'oeil, et pur
     * CSS Bootstrap déjà chargé sur toute l'instance, aucun JS custom.
     *
     * Hidden value=0 devant la checkbox : une case DÉCOCHÉE n'est jamais
     * envoyée dans le POST par le navigateur — sans ce champ, décocher
     * puis enregistrer laisserait la clé absente de l'input (même
     * mécanisme déjà utilisé pour la case d'exclusion sur les tâches,
     * cf. hook.php).
     */
    private static function showToggle(string $name, bool $checked, bool $canedit, string $onChangeJs = ''): void {
        static $counter = 0;
        $counter++;
        $id = 'synchronisationcaloutlook-toggle-' . $name . '-' . $counter;

        echo "<div class='form-switch'>";
        echo "<input type='hidden' name='" . htmlspecialchars($name) . "' value='0'>";
        echo "<input role='switch' class='form-check-input' type='checkbox' "
            . "name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($id) . "' value='1'"
            . ($checked ? " checked" : "")
            . ($canedit ? "" : " disabled")
            . ($onChangeJs !== '' ? " onchange=\"" . htmlspecialchars($onChangeJs, ENT_QUOTES) . "\"" : "")
            . ">";
        echo "</div>";
    }

    public function canViewItem() {
        return Session::haveRight('config', READ);
    }

    public function canCreateItem() {
        return false;
    }

    public function canPurgeItem() {
        return false;
    }

    public static function getConfig(): array {
        $config = new self();
        if (!$config->getFromDB(self::CONFIG_ID)) {
            $defaults = [
                'id'                   => self::CONFIG_ID,
                'active'               => 1,
                'enable_tickettask'    => 1,
                'enable_problemtask'   => 1,
                'enable_changetask'    => 1,
                'mailcollectors_id'    => 0,
                'use_standard_smtp'    => 1,
            ];
            $config->add($defaults);
            $config->getFromDB(self::CONFIG_ID);
        }
        return $config->fields;
    }

    public static function getMailCollectorsList(): array {
        global $DB;

        $collectors = [0 => __('Aucun')];

        $result = $DB->request([
            'FROM'  => 'glpi_mailcollectors',
            'ORDER' => 'name ASC',
        ]);

        foreach ($result as $row) {
            $label = $row['name'];
            if (!empty($row['login'])) {
                $label .= ' (' . $row['login'] . ')';
            }
            $collectors[(int) $row['id']] = $label;
        }

        return $collectors;
    }

    public function prepareInputForUpdate($input) {
        foreach ([
            'active', 'enable_tickettask', 'enable_problemtask', 'enable_changetask',
            'use_standard_smtp',
        ] as $checkbox) {
            $input[$checkbox] = !empty($input[$checkbox]) ? 1 : 0;
        }

        return $input;
    }

    public function showForm($ID = self::CONFIG_ID, $options = []) {
        if (!Session::haveRight('config', UPDATE) && !Session::haveRight('config', READ)) {
            return false;
        }

        $this->getFromDB(self::CONFIG_ID);
        $canedit = Session::haveRight('config', UPDATE);

        echo "<div class='center'>";
        if ($canedit) {
            echo "<form method='post' action='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/config.form.php'>";
        }

        $collectorValue = (int) ($this->fields['mailcollectors_id'] ?? 0);
        $standardSmtp   = (int) ($this->fields['use_standard_smtp'] ?? 1);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='4'>" . __('Configuration Synchronisation Cal Outlook') . "</th></tr>";

        $dropdownDisabled = $canedit ? [] : ['disabled' => 'disabled'];

        $activeValue = (int) ($this->fields['active'] ?? 1);
        echo "<tr class='tab_bg_1'><td>"
            . "<strong>" . __('Actif') . "</strong>"
            . "</td><td colspan='3'>";
        self::showToggle('active', (bool) $activeValue, $canedit);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Gabarit du mail') . "</td><td colspan='3'>";
        echo (PluginSynchronisationcaloutlookTemplate::exists() ? __('Un gabarit est configuré.') : __('Aucun gabarit configuré (gabarit minimal par défaut).'));
        // PAS de <form> ici : on est déjà à l'intérieur du <form> de
        // config ouvert plus haut dans cette méthode — un <form> imbriqué
        // dans un autre est invalide en HTML, le navigateur casse la
        // structure et le clic finit par soumettre le mauvais formulaire
        // (bug réel rencontré : le bouton "ne fonctionnait plus"). Un
        // simple <button type='button'> avec navigation JS évite tout
        // formulaire imbriqué, tout en gardant le style natif d'un vrai
        // bouton (contrairement à un <a> qui ne se rend pas pareil).
        $templateUrl = Plugin::getWebDir('synchronisationcaloutlook') . '/front/template.php';
        echo "&nbsp;&nbsp;<button type='button' class='submit' onclick=\"window.location.href='" . htmlspecialchars($templateUrl, ENT_QUOTES) . "';\">"
            . __('Gérer le gabarit') . "</button>";
        echo "</td></tr>";

        echo "<tr><th colspan='4'>" . __('Envoi des emails') . "</th></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Utiliser la configuration SMTP standard') . " "
            . "<i class='fas fa-info-circle pointer' title=\""
            . htmlspecialchars(__('Celle de Configuration > Notifications.'), ENT_QUOTES)
            . "\"></i>"
            . "</td><td colspan='3'>";
        // this.checked (pas this.value : une checkbox garde une value
        // fixe quelle que soit son état, contrairement à un <select>).
        self::showToggle('use_standard_smtp', (bool) $standardSmtp, $canedit, 'synchronisationcaloutlookToggleSmtp(this.checked ? 1 : 0)');
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 synchronisationcaloutlook-custom-smtp'><td>" . __('Collecteur de mails natif existant') . " "
            . "<i class='fas fa-info-circle pointer' title=\""
            . htmlspecialchars(__("Ses identifiants servent à l'envoi."), ENT_QUOTES)
            . "\"></i>"
            . "</td><td colspan='3'>";
        Dropdown::showFromArray('mailcollectors_id', self::getMailCollectorsList(), $dropdownDisabled + [
            'value' => $collectorValue,
        ]);
        echo "</td></tr>";

        echo "<tr><th colspan='4'>" . __('Itemtypes surveillés') . "</th></tr>";

        $types = [
            'enable_tickettask'  => __('TicketTask (tâches de ticket)'),
            'enable_problemtask' => __('ProblemTask (tâches de problème)'),
            'enable_changetask'  => __('ChangeTask (tâches de changement)'),
        ];
        foreach ($types as $field => $label) {
            echo "<tr class='tab_bg_1'><td>{$label}</td><td colspan='3'>";
            self::showToggle($field, (bool) ($this->fields[$field] ?? 1), $canedit);
            echo "</td></tr>";
        }

        if ($canedit) {
            echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
            echo "<input type='hidden' name='id' value='" . self::CONFIG_ID . "'>";
            echo "<input type='submit' name='update' class='submit' value=\"" . _sx('button', 'Save') . "\">";
            echo "</td></tr>";
        }

        echo "</table>";

        if ($canedit) {
            echo "<p><a href='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/checknow.php' "
                . "title=\"" . htmlspecialchars(__('Prévisualise puis envoie les invitations manquantes.'), ENT_QUOTES) . "\">"
                . "<i class='fas fa-sync'></i>&nbsp;" . __('Vérifier maintenant') . "</a></p>";
        }

        echo Html::scriptBlock("
            function synchronisationcaloutlookToggleSmtp(value) {
                var custom = (parseInt(value, 10) || 0) === 0;
                $('.synchronisationcaloutlook-custom-smtp').toggle(custom);
            }
            $(function() {
                synchronisationcaloutlookToggleSmtp('{$standardSmtp}');
            });
        ");

        if ($canedit) {
            Html::closeForm();
        }
        echo "</div>";

        return true;
    }
}
