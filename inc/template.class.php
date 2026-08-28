<?php

/**
 * Gabarit d'invitation propre au plugin : sujet + corps réutilisables,
 * avec des balises ##XXX## remplacées par les informations de la tâche
 * au moment de l'envoi.
 *
 * SINGLETON (id fixe = 1, même pattern que PluginSynchronisationcaloutlookConfig) :
 * un seul gabarit possible, pas de liste. S'il existe, il est utilisé
 * automatiquement pour toute invitation (METHOD:REQUEST) ; sinon, repli
 * sur un gabarit minimal codé en dur. Aucun menu déroulant "lequel
 * utiliser" n'a de sens ici, contrairement à une v1 où plusieurs
 * gabarits coexistaient.
 *
 * Volontairement séparé des modèles de notification natifs ITSM-NG
 * (Configuration > Modèles de notifications) : ceux-ci utilisent leurs
 * propres balises (##ticket.title##...), non résolues par ce plugin, et
 * peuvent être partagés avec de vraies notifications ailleurs dans
 * l'instance — les modifier "pour ce plugin" risquerait de casser autre
 * chose. Un gabarit dédié, avec uniquement nos balises, évite les deux
 * pièges.
 *
 * Export/Import : représentation sur disque dans le dossier gabarit/ à
 * la racine du plugin (nom.txt, sujet.txt, corps.txt, corps_html.html),
 * pour versionner la config avec Git et la faire monter automatiquement
 * au démarrage d'un conteneur Docker (import automatique à l'installation
 * du plugin si le dossier contient des fichiers et qu'aucun gabarit
 * n'existe encore en base, cf. hook.php). Le disque n'est JAMAIS lu au
 * moment d'envoyer un mail : seule la base compte à l'exécution.
 */
class PluginSynchronisationcaloutlookTemplate extends CommonDBTM {

    public static $rightname = 'config';

    /** Balises disponibles dans le sujet et le corps du gabarit. */
    const PLACEHOLDERS = [
        '##TICKET##'       => 'Nom du ticket/dossier lié',
        '##CONTENU##'      => 'Contenu de la tâche',
        '##DEBUT##'        => 'Date/heure de début',
        '##FIN##'          => 'Date/heure de fin',
        '##LIEN##'         => 'Lien vers le ticket',
        '##DESTINATAIRE##' => 'Nom du destinataire',
    ];

    /**
     * Le droit natif 'config' ne définit pas de niveau CREATE/PURGE (juste
     * READ/UPDATE) : les vérifications par défaut de CommonDBTM (qui
     * testent CREATE/PURGE sur $rightname) échouent donc toujours, même
     * pour un Super-Admin. On force ici tout sur UPDATE/READ, cohérent
     * avec le reste du plugin (accès réservé au même droit que la config).
     */
    public static function canCreate() {
        return Session::haveRight('config', UPDATE);
    }

    public static function canUpdate() {
        return Session::haveRight('config', UPDATE);
    }

    public static function canPurge() {
        return Session::haveRight('config', UPDATE);
    }

    public static function canView() {
        return Session::haveRight('config', READ);
    }

    /**
     * Contourne complètement la vérification générique de CommonDBTM
     * (appelée en interne par add()/update()/delete(), PAS seulement par
     * initForm()) : elle teste le niveau de droit correspondant à
     * l'action (CREATE pour add(), PURGE pour delete()...) sur
     * $rightname, en ignorant totalement les surcharges canCreate()/
     * canUpdate()/canPurge() ci-dessus. Le droit natif 'config' ne
     * définissant qu'UPDATE et READ (pas de niveau CREATE/PURGE), cette
     * vérification générique échoue TOUJOURS pour un ajout ou une
     * suppression, même pour un Super-Admin — jamais uniquement un
     * problème d'affichage (initForm()), mais un blocage réel de add()/
     * delete() si on ne la remplace pas ici. On applique donc un contrôle
     * unique et cohérent sur UPDATE, quel que soit $right demandé.
     */
    public function check($ID, $right, array &$input = null) {
        if (!Session::haveRight('config', UPDATE)) {
            Html::displayRightError();
        }
    }

    public static function getTypeName($nb = 0) {
        return __('Gabarit');
    }

    public static function getIcon() {
        return 'fas fa-file-alt';
    }

    /**
     * Crée/met à jour le gabarit singleton.
     *
     * HISTORIQUE DU BUG "\r\n" LITTÉRAL (cf. prompt_debug_gabarit_rn.md) :
     * cette méthode bypassait auparavant CommonDBTM::add()/update() pour
     * appeler directement $DB->insert()/update() bas niveau, dans le seul
     * but de forcer id=1 sur une colonne AUTO_INCREMENT (add() ignore un
     * id forcé sur ce type de colonne). Deux comportements strictement
     * reproductibles et opposés ont été observés selon qu'on échappait
     * manuellement les valeurs avant cet appel bas niveau :
     *   - sans $DB->escape() manuel : une apostrophe dans le contenu
     *     casse la requête SQL (erreur 1064) ;
     *   - avec $DB->escape() manuel : plus d'erreur SQL, mais les vrais
     *     retours à la ligne ressortaient en base comme la séquence
     *     littérale à 2 caractères "\r\n" (backslash+r+backslash+n) au
     *     lieu de vrais octets CR/LF — cohérent avec un DOUBLE
     *     échappement (le \n réel devient d'abord "\n" littéral via
     *     l'escape manuel, puis le backslash de ce "\n" littéral est
     *     lui-même ré-échappé par l'échappement interne de
     *     buildInsert()/buildUpdate(), donnant au final "\\n" stocké tel
     *     quel par MySQL).
     *
     * Plutôt que de continuer à manipuler l'échappement bas niveau (et
     * dépendre de $@@sql_mode, NO_BACKSLASH_ESCAPES, etc.), on abandonne
     * complètement le contournement de l'ORM : plus d'id forcé, plus de
     * $DB->insert()/update() manuel, plus de $DB->escape() manuel. On
     * repasse par le chemin de code standard de GLPI (CommonDBTM::add()/
     * update()), utilisé sans ce genre de bug partout ailleurs dans le
     * coeur, et on retrouve "le" gabarit singleton par
     * ORDER BY id ASC LIMIT 1 (cf. firstRow()) plutôt que par un id fixe.
     * check() est toujours surchargé plus haut pour autoriser add()/
     * update() sous le droit 'config' (qui ne définit pas de niveau
     * CREATE natif), donc l'appel à $this->add()/$this->update() ne sera
     * pas bloqué.
     */
    public function save(array $input): void {
        // Filtrage strict : $input peut venir directement de $_POST (nom
        // du bouton, jeton CSRF, id...) — ne jamais transmettre ça tel
        // quel à l'ORM. On ne garde que les colonnes réellement gérées ;
        // aucun échappement manuel ici, add()/update() s'en chargent
        // correctement eux-mêmes (chemin standard de l'ORM).
        $clean = [
            'name'         => trim((string) ($input['name'] ?? '')),
            'subject'      => (string) ($input['subject'] ?? ''),
            'content'      => (string) ($input['content'] ?? ''),
            'content_html' => (string) ($input['content_html'] ?? ''),
        ];

        $existingRow = self::firstRow();

        if ($existingRow !== null) {
            $this->getFromDB((int) $existingRow['id']);
            $clean['id'] = (int) $existingRow['id'];
            $this->update($clean);
        } else {
            $this->getEmpty();
            $this->add($clean);
        }

        Toolbox::logInFile('synchronisationcaloutlook', sprintf(
            "[%s] TEMPLATE save() mode=%s id=%s name=%s\n",
            date('Y-m-d H:i:s'),
            $existingRow !== null ? 'update' : 'insert',
            $this->getID() ?: '(inconnu)',
            $clean['name']
        ));
    }

    /**
     * Première (et normalement unique) ligne de la table, triée par id,
     * sous forme de tableau brut — ou null si la table est vide. Sert de
     * base commune à exists()/getSingleton()/save() : la source de
     * vérité pour "le" gabarit singleton n'est plus un id fixe, mais
     * "la ligne la plus ancienne s'il y en a une" (il ne peut normalement
     * y en avoir qu'une seule, save() faisant toujours un update() sur la
     * ligne existante plutôt qu'un nouvel add()).
     */
    private static function firstRow(): ?array {
        global $DB;
        $result = $DB->request([
            'FROM'  => self::getTable(),
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ]);
        foreach ($result as $row) {
            return $row;
        }
        return null;
    }

    /** Existe-t-il un gabarit ? (source de vérité : la base, pas le disque) */
    public static function exists(): bool {
        return self::firstRow() !== null;
    }

    /**
     * Charge le gabarit singleton (fields vides si inexistant, comme
     * getEmpty()). Pratique pour éviter de dupliquer la recherche de la
     * ligne existante partout.
     */
    public static function getSingleton(): self {
        $template = new self();
        $row = self::firstRow();
        if ($row !== null) {
            $template->getFromDB((int) $row['id']);
        } else {
            $template->getEmpty();
        }
        return $template;
    }

    private static function placeholdersHelp(): string {
        return implode(' ', array_keys(self::PLACEHOLDERS));
    }

    /** Dossier gabarit/ à la racine du plugin (PAS lu à l'envoi d'un mail). */
    private static function gabaritDir(): string {
        return dirname(__DIR__) . '/gabarit';
    }

    /**
     * Écrit le gabarit actuel (base) dans le dossier gabarit/ du plugin,
     * un fichier par champ. corps_html.html n'est écrit que si non vide ;
     * s'il existait d'un export précédent et que le champ est maintenant
     * vide, il est supprimé pour rester cohérent avec l'état réel.
     */
    public function exportToFiles(): void {
        $dir = self::gabaritDir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf(__("Impossible de créer le dossier %s."), $dir));
            }
        }

        // Décodage des entités HTML AVANT écriture sur disque — pendant
        // opposé exact de sanitizeLikeCore() côté import. Sans ce
        // décodage, le fichier écrit contient la valeur brute de la base
        // (déjà en entités : "&lt;html&gt;..." si le gabarit a été saisi
        // via le formulaire, désinfecté automatiquement par le coeur).
        // Un import ultérieur de ce fichier ré-encoderait alors une
        // SECONDE fois ce contenu déjà encodé ("&amp;lt;html&amp;gt;"),
        // que resolveTemplate() (un seul passage de décodage à l'envoi)
        // ne peut plus complètement défaire : il reste "&lt;html&gt;"
        // littéral en base, que le client mail affiche comme texte
        // visible au lieu de l'interpréter comme balisage HTML. Bug
        // rencontré et confirmé : cycle Export → Import sur un gabarit
        // déjà saisi via le formulaire web cassait le rendu HTML du mail.
        // Avec ce décodage, Export → Import est idempotent : le fichier
        // reste toujours du HTML/texte lisible, jamais pré-encodé.
        $writes = [
            'nom.txt'   => Html::entity_decode_deep((string) ($this->fields['name'] ?? '')),
            'sujet.txt' => Html::entity_decode_deep((string) ($this->fields['subject'] ?? '')),
            'corps.txt' => Html::entity_decode_deep((string) ($this->fields['content'] ?? '')),
        ];

        foreach ($writes as $filename => $content) {
            if (file_put_contents($dir . '/' . $filename, $content) === false) {
                throw new \RuntimeException(sprintf(__("Échec de l'écriture de %s."), $filename));
            }
        }

        $htmlPath = $dir . '/corps_html.html';
        $contentHtml = Html::entity_decode_deep((string) ($this->fields['content_html'] ?? ''));
        if ($contentHtml !== '') {
            if (file_put_contents($htmlPath, $contentHtml) === false) {
                throw new \RuntimeException(__("Échec de l'écriture de corps_html.html."));
            }
        } elseif (file_exists($htmlPath)) {
            unlink($htmlPath);
        }
    }

    /**
     * Lit le dossier gabarit/ du plugin et écrase le gabarit en base avec
     * son contenu (création si aucun gabarit n'existait, mise à jour
     * sinon). nom.txt/sujet.txt/corps.txt sont obligatoires ;
     * corps_html.html est optionnel (absent = pas de gabarit HTML).
     *
     * @throws \RuntimeException si le dossier ou un fichier obligatoire
     *                           est absent.
     */
    public function importFromFiles(): void {
        $dir = self::gabaritDir();

        Toolbox::logInFile('synchronisationcaloutlook', sprintf(
            "[%s] TEMPLATE importFromFiles() dossier=%s existe=%s contenu=%s\n",
            date('Y-m-d H:i:s'),
            $dir,
            is_dir($dir) ? 'oui' : 'NON',
            is_dir($dir) ? implode(',', scandir($dir)) : '(dossier absent)'
        ));

        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf(__("Aucun dossier %s trouvé : rien à importer."), 'gabarit/'));
        }

        $required = ['nom.txt' => 'name', 'sujet.txt' => 'subject', 'corps.txt' => 'content'];
        $input = [];

        foreach ($required as $filename => $field) {
            $path = $dir . '/' . $filename;
            if (!file_exists($path)) {
                throw new \RuntimeException(sprintf(__("Fichier manquant : %s."), $filename));
            }
            // rtrim("\n") uniquement : évite qu'un simple saut de ligne de
            // fin de fichier (ajouté par la plupart des éditeurs) se
            // retrouve dans le champ, sans toucher aux lignes vides
            // délibérées à l'intérieur du contenu.
            $input[$field] = rtrim((string) file_get_contents($path), "\n");
        }

        $htmlPath = $dir . '/corps_html.html';
        $input['content_html'] = file_exists($htmlPath)
            ? rtrim((string) file_get_contents($htmlPath), "\n")
            : '';

        // DÉSINFECTION MANQUANTE — cause réelle du blocage SQL 1064 sur
        // l'apostrophe (ex: "Texte d'intro"), y compris après le passage
        // à l'ORM standard ($this->add()/update()) :
        //
        // Un enregistrement via le formulaire web (front/template.form.php,
        // $item->save($_POST)) fonctionne SANS le moindre échappement
        // manuel de notre part, apostrophe comprise — parce que GLPI/
        // ITSM-NG désinfecte automatiquement TOUT $_POST/$_GET (encodage
        // des caractères spéciaux <, >, &, ', " en entités HTML) au tout
        // début du cycle de requête HTTP, AVANT que le code du plugin ne
        // s'exécute. C'est confirmé par le contenu réellement stocké en
        // base pour un gabarit saisi à la main (ex: id=2 fourni par
        // l'utilisateur) : "&lt;html&gt;" et non "<html>". C'est aussi
        // cohérent avec inc/notifier.class.php::resolveTemplate(), qui
        // applique Html::entity_decode_deep() sur ces mêmes champs au
        // moment de l'envoi pour annuler cet encodage.
        //
        // CommonDBTM::add()/update() ne fait de son côté AUCUN échappement
        // (confirmé empiriquement : erreur SQL 1064 obtenue malgré le
        // passage par l'ORM standard) — elle suppose que $input est déjà
        // désinfecté en amont par le coeur, comme c'est le cas pour un
        // vrai $_POST.
        //
        // importFromFiles() lit le disque directement, HORS de tout cycle
        // de requête HTTP : ce contenu brut ne passe jamais par la
        // désinfection automatique du coeur. Il faut donc reproduire
        // manuellement ici la même opération, pour que le contenu importé
        // arrive dans save() dans le même état qu'un $_POST réel.
        $input = self::sanitizeLikeCore($input);

        Toolbox::logInFile('synchronisationcaloutlook', sprintf(
            "[%s] TEMPLATE importFromFiles() lu : name=%s subject=%s content_len=%d content_html_len=%d\n",
            date('Y-m-d H:i:s'),
            $input['name'],
            $input['subject'],
            strlen($input['content']),
            strlen($input['content_html'])
        ));

        $this->save($input);
    }

    /**
     * Contrepartie EXACTE de Html::entity_decode_deep() (déjà confirmée
     * fonctionner sur cette instance, utilisée dans
     * inc/notifier.class.php::resolveTemplate() pour relire ces mêmes
     * champs) : encode récursivement <, >, &, ', " en entités HTML, pour
     * amener un contenu lu sur disque au même état qu'un $_POST réel déjà
     * désinfecté par le coeur GLPI/ITSM-NG.
     *
     * NON reconfirmé sur cette instance précise que le nom exact de la
     * méthode coeur "aller" est bien Html::entities_deep() (contrairement
     * à Html::entity_decode_deep(), déjà testé en conditions réelles) —
     * c'est l'API standard GLPI pour cette opération, mais à vérifier une
     * fois sur le serveur si un doute subsiste (comparer un import à un
     * gabarit saisi à la main, cf. section dédiée du README). Un repli
     * manuel (htmlspecialchars() récursif) est appliqué automatiquement
     * si la méthode coeur est absente, pour ne jamais faire planter
     * l'import.
     *
     * ENT_QUOTES SANS ENT_HTML5 dans le repli, volontairement : ENT_HTML5
     * encode l'apostrophe en "&apos;", que certaines implémentations de
     * html_entity_decode()/Html::entity_decode_deep() ne décodent pas
     * selon les drapeaux qu'elles utilisent en interne (testé et
     * reproduit : "&apos;" restait tel quel après décodage dans un cas).
     * ENT_QUOTES seul encode l'apostrophe en "&#039;" (entité numérique),
     * décodée sans ambiguïté par toute implémentation standard, quels que
     * soient ses drapeaux — plus sûr comme repli générique.
     */
    private static function sanitizeLikeCore(array $input): array {
        if (method_exists('Html', 'entities_deep')) {
            return Html::entities_deep($input);
        }
        $out = [];
        foreach ($input as $key => $value) {
            $out[$key] = is_string($value)
                ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
                : $value;
        }
        return $out;
    }

    public function showForm($ID, $options = []) {
        $canedit = Session::haveRight('config', UPDATE);

        // Chargement manuel, sans passer par initForm()/check() : ces
        // méthodes génériques de CommonDBTM font leurs propres
        // vérifications de droits en interne (indépendamment de mes
        // surcharges canCreate()/canUpdate() ci-dessus) et bloquaient
        // l'affichage même pour un Super-Admin. Même logique que
        // PluginSynchronisationcaloutlookConfig::showForm(), qui fonctionne.
        // $ID (paramètre reçu) n'est volontairement pas utilisé : il n'y
        // a plus d'id fixe depuis la correction du bug "\r\n" littéral
        // (cf. save()), la ligne existante est retrouvée via firstRow().
        $row = self::firstRow();
        $isNew = ($row === null);
        if (!$isNew) {
            $this->getFromDB((int) $row['id']);
        } else {
            $this->getEmpty();
        }

        echo "<div class='center'>";
        echo "<form method='post' action='" . Plugin::getWebDir('synchronisationcaloutlook') . "/front/template.form.php'>";

        echo "<table class='tab_cadre_fixe'>";
        // Pas de htmlspecialchars() ici : $this->fields['name'] est déjà
        // désinfecté (entités HTML) par le coeur GLPI/ITSM-NG, que ce
        // gabarit ait été saisi via le formulaire (désinfection
        // automatique de $_POST) ou importé (désinfection manuelle
        // reproduite dans importFromFiles(), cf. sanitizeLikeCore()).
        // Un htmlspecialchars() supplémentaire ici double-encoderait
        // (ex: "AT&amp;T" deviendrait "AT&amp;amp;T" à l'affichage).
        echo "<tr><th colspan='2'>" . ($isNew ? __('Créer un gabarit') : ($this->fields['name'] ?? '')) . "</th></tr>";

        echo "<tr class='tab_bg_1'><td colspan='2'>"
            . "<strong>" . __('Balises disponibles') . " :</strong> "
            . htmlspecialchars(self::placeholdersHelp())
            . "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Nom') . "</td><td>";
        $nameOptions = ['value' => $this->fields['name'] ?? ''];
        if (!$canedit) {
            // Un attribut HTML booléen (disabled) est actif dès qu'il est
            // présent, quelle que soit sa valeur ('disabled', '0', '') :
            // ne JAMAIS passer 'disabled' => false, sous peine de griser
            // le champ même quand $canedit vaut true. On n'ajoute donc la
            // clé que lorsqu'elle doit réellement s'appliquer.
            $nameOptions['disabled'] = 'disabled';
        }
        echo Html::input('name', $nameOptions);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Sujet') . "</td><td>";
        $subjectOptions = ['value' => $this->fields['subject'] ?? '', 'size' => 60];
        if (!$canedit) {
            $subjectOptions['disabled'] = 'disabled';
        }
        echo Html::input('subject', $subjectOptions);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Corps') . "</td><td>";
        // Idem : pas de htmlspecialchars() supplémentaire, le champ est
        // déjà en entités HTML (même raisonnement qu'au-dessus, cohérent
        // avec Html::input() utilisé pour 'name'/'subject' juste avant,
        // qui n'en ajoute pas non plus).
        echo "<textarea name='content' rows='8' cols='60' " . (!$canedit ? 'disabled' : '') . ">"
            . ($this->fields['content'] ?? '')
            . "</textarea>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Corps HTML (optionnel)') . " "
            . "<i class='fas fa-info-circle pointer' title=\""
            . htmlspecialchars(__('Vide = calendrier natif (défaut). Rempli = mail HTML, .ics en pièce jointe.'), ENT_QUOTES)
            . "\"></i>"
            . "</td><td>";
        echo "<textarea name='content_html' rows='12' cols='60' " . (!$canedit ? 'disabled' : '') . ">"
            . ($this->fields['content_html'] ?? '')
            . "</textarea>";
        echo "</td></tr>";

        if ($canedit) {
            echo "<tr class='tab_bg_1'><td colspan='2' class='center'>";
            // Pas de champ 'id' caché : save() retrouve lui-même la ligne
            // existante (firstRow()) plutôt que de dépendre d'un id posté
            // par le navigateur — cf. correction du bug "\r\n" littéral.
            echo "<input type='submit' name='save' class='submit' value=\"" . _sx('button', 'Save') . "\">";
            if (!$isNew) {
                echo "&nbsp;<input type='submit' name='purge' class='submit' value=\"" . _sx('button', 'Delete permanently') . "\" onclick=\"return confirm('" . htmlspecialchars(__('Supprimer ce gabarit ?'), ENT_QUOTES) . "');\">";
            }
            echo "</td></tr>";
        }

        echo "</table>";
        Html::closeForm();
        echo "</div>";

        return true;
    }
}
