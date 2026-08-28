<?php

/**
 * Orchestre la notification calendrier : appelée par les callbacks de
 * hook.php (item_add / item_update / item_purge) pour TicketTask,
 * ProblemTask, ChangeTask.
 *
 * Logique métier centralisée ici (plutôt que dans hook.php) pour rester
 * testable indépendamment du cycle d'appel des hooks GLPI.
 */
class PluginSynchronisationcaloutlookNotifier {

    /**
     * Champs dont la modification doit déclencher un nouvel envoi sur
     * item_update. Un changement de state seul (ex: passage "résolu")
     * ne doit rien déclencher.
     */
    const WATCHED_FIELDS = ['begin', 'end', 'users_id_tech', 'groups_id_tech', 'content'];

    /**
     * Association itemtype => colonne de config permettant de désactiver
     * indépendamment l'envoi par type de tâche.
     */
    const ITEMTYPE_CONFIG_KEY = [
        'TicketTask'  => 'enable_tickettask',
        'ProblemTask' => 'enable_problemtask',
        'ChangeTask'  => 'enable_changetask',
    ];

    /**
     * Association itemtype => [classe parente, clé étrangère] pour
     * retrouver le ticket/problème/changement associé (lien + titre).
     */
    const PARENT_MAP = [
        'TicketTask'  => ['Ticket', 'tickets_id'],
        'ProblemTask' => ['Problem', 'problems_id'],
        'ChangeTask'  => ['Change', 'changes_id'],
    ];

    // ------------------------------------------------------------------
    // Points d'entrée appelés depuis hook.php
    // ------------------------------------------------------------------

    public static function onItemAdd(CommonDBTM $item): void {
        self::process($item);
    }

    public static function onItemUpdate(CommonDBTM $item, bool $forceProcess = false): void {
        // $item->oldvalues est peuplé par CommonDBTM::update() avec les
        // anciennes valeurs des SEULS champs modifiés. On ne traite que si
        // un des champs surveillés a réellement changé, pour éviter tout
        // envoi redondant (ex: simple changement de state) — SAUF si
        // $forceProcess est vrai : la case "Ne pas envoyer d'invitation"
        // vit dans une table à part (pas un champ natif de la tâche), donc
        // décocher/cocher cette case seule n'apparaît JAMAIS dans
        // oldvalues. Sans ce paramètre, décocher la case puis enregistrer
        // (sans toucher begin/end/tech/groupe/contenu) ne déclenchait
        // aucun envoi alors que la tâche devient éligible. hook.php passe
        // $forceProcess=true quand il détecte un changement d'état
        // d'exclusion entre avant et après syncFromInput().
        $changedFields = array_keys($item->oldvalues ?? []);
        $watchedFieldChanged = !empty(array_intersect($changedFields, self::WATCHED_FIELDS));
        if (!$watchedFieldChanged && !$forceProcess) {
            // Journalisé explicitement : c'est le cas typique d'une mise à
            // jour ne concernant pas ce plugin (ex: changement de state),
            // mais aussi de tout scénario où begin/end serait modifié par
            // un mécanisme qui ne peuple pas oldvalues comme attendu (ex:
            // widget Planning enregistrant via un appel séparé) — utile
            // pour distinguer "hook appelé mais ignoré ici" de "hook
            // jamais appelé du tout" (cf. trace hookEntry dans hook.php).
            self::log(
                get_class($item),
                (int) $item->getID(),
                'INFO',
                '-',
                sprintf(
                    'Aucun champ surveillé modifié (oldvalues: %s) : aucun traitement.',
                    $changedFields ? implode(',', $changedFields) : '(aucun)'
                )
            );
            return;
        }
        self::process($item);
    }

    public static function onItemPurge(CommonDBTM $item): void {
        $itemtype = get_class($item);
        if (!isset(self::ITEMTYPE_CONFIG_KEY[$itemtype])) {
            return;
        }

        $items_id = (int) $item->getID();
        $eventRow = PluginSynchronisationcaloutlookEvent::getForItem($itemtype, $items_id);
        if ($eventRow === null) {
            return; // jamais notifiée, rien à annuler
        }

        $recipients = PluginSynchronisationcaloutlookEvent::getLastRecipients($eventRow);
        if (!empty($recipients)) {
            self::sendCancelToEmails($item, $eventRow['ical_uid'], (int) $eventRow['sequence'] + 1, $recipients);
        }
        PluginSynchronisationcaloutlookEvent::deleteForItem($itemtype, $items_id);
    }

    // ------------------------------------------------------------------
    // Logique principale (add + update partagent le même traitement)
    // ------------------------------------------------------------------

    private static function process(CommonDBTM $item): void {
        $itemtype = get_class($item);
        $items_id = (int) $item->getID();

        if (!isset(self::ITEMTYPE_CONFIG_KEY[$itemtype])) {
            return; // itemtype hors périmètre du plugin, rien à logger (ne devrait pas arriver vu les hooks déclarés)
        }

        $config = PluginSynchronisationcaloutlookConfig::getConfig();

        // Interrupteur global "Actif" : coupe tout envoi sans désactiver
        // le plugin au sens GLPI. Vérifié dès que possible, avant tout
        // autre filtre métier, pour être le coupe-circuit le plus direct.
        if (empty($config['active'] ?? 1)) {
            self::log($itemtype, $items_id, 'INFO', '-', "Plugin désactivé (\"Actif\" = Non dans la config) : aucun envoi.");
            return;
        }

        if (empty($config[self::ITEMTYPE_CONFIG_KEY[$itemtype]])) {
            self::log($itemtype, $items_id, 'INFO', '-', "Itemtype désactivé dans la config du plugin (\"Itemtypes surveillés\") : aucun envoi.");
            return;
        }

        $begin    = $item->fields['begin'] ?? null;
        $end      = $item->fields['end'] ?? null;
        $eventRow = PluginSynchronisationcaloutlookEvent::getForItem($itemtype, $items_id);

        // Tâche non planifiée (ou déplanifiée) : rien à envoyer. Si elle
        // avait déjà été notifiée auparavant, on annule proprement.
        if (empty($begin) || empty($end)) {
            self::log($itemtype, $items_id, 'INFO', '-', sprintf('begin ou end non renseigné (begin=%s end=%s) : aucun envoi.', $begin ?: '(vide)', $end ?: '(vide)'));
            self::cancelExisting($item, $eventRow);
            return;
        }

        // Case "Ne pas envoyer d'invitation dans le calendrier" cochée sur
        // la tâche : aucun traitement, ni envoi direct (temps réel) ni via
        // le rattrapage/bouton forcé (cf. PluginSynchronisationcaloutlookExclusion).
        if (PluginSynchronisationcaloutlookExclusion::isExcluded($itemtype, $items_id)) {
            self::log($itemtype, $items_id, 'INFO', '-', "Exclue par la case \"Ne pas envoyer d'invitation\" : aucun envoi.");
            return;
        }

        // Ticket/problème/changement parent déjà résolu ou clos : plus la
        // peine d'inviter à un rendez-vous lié à un dossier terminé.
        if (self::isParentResolved($item)) {
            self::log($itemtype, $items_id, 'INFO', '-', 'Ticket/dossier parent résolu ou clos : aucun envoi.');
            return;
        }

        // Sécurité : un rendez-vous dont l'heure de début est déjà passée
        // ne doit pas générer d'invitation (n'a plus de sens à envoyer).
        // Ne touche pas à un éventuel suivi existant, on se contente de
        // ne rien envoyer pour cette occurrence.
        try {
            $beginDate = new DateTime((string) $begin, new DateTimeZone(date_default_timezone_get()));
            if ($beginDate <= new DateTime('now', new DateTimeZone(date_default_timezone_get()))) {
                self::log($itemtype, $items_id, 'INFO', '-', 'Début déjà passé : aucun envoi.');
                return;
            }
        } catch (\Throwable $e) {
            // Date illisible : on laisse le traitement continuer normalement
            // plutôt que de bloquer un envoi sur une erreur de parsing.
        }

        $newRecipients = self::resolveRecipients($item, $config); // [email => nom]

        if (empty($newRecipients)) {
            self::log($itemtype, $items_id, 'INFO', '-', 'Planifiée mais aucun destinataire valide (tech/groupe sans email) : aucun envoi.');
            self::cancelExisting($item, $eventRow);
            return;
        }

        if ($eventRow === null) {
            // Première planification notifiée pour cette tâche.
            $uid = PluginSynchronisationcaloutlookEvent::generateUid($itemtype, $items_id);
            self::sendRequestToAll($item, $uid, 0, $newRecipients);
            PluginSynchronisationcaloutlookEvent::save($itemtype, $items_id, $uid, 0, array_keys($newRecipients));
            return;
        }

        // Mise à jour d'un événement déjà notifié : diff des destinataires.
        $uid      = $eventRow['ical_uid'];
        $sequence = (int) $eventRow['sequence'] + 1;
        $oldEmails = PluginSynchronisationcaloutlookEvent::getLastRecipients($eventRow);
        $newEmails = array_keys($newRecipients);

        $removedEmails = array_diff($oldEmails, $newEmails);
        if (!empty($removedEmails)) {
            // Ancien(s) destinataire(s) retiré(s) (ex: changement de tech) :
            // CANCEL ciblé, sans attendre du destinataire restant.
            self::sendCancelToEmails($item, $uid, $sequence, $removedEmails);
        }

        // REQUEST (mise à jour ou nouvelle invitation) à tous les
        // destinataires actuellement actifs, avec la liste ATTENDEE
        // complète pour que chacun voie qui d'autre est invité.
        self::sendRequestToAll($item, $uid, $sequence, $newRecipients);

        PluginSynchronisationcaloutlookEvent::save($itemtype, $items_id, $uid, $sequence, $newEmails);
    }

    private static function cancelExisting(CommonDBTM $item, ?array $eventRow): void {
        if ($eventRow === null) {
            return;
        }
        $itemtype = get_class($item);
        $items_id = (int) $item->getID();
        $recipients = PluginSynchronisationcaloutlookEvent::getLastRecipients($eventRow);
        if (!empty($recipients)) {
            self::sendCancelToEmails($item, $eventRow['ical_uid'], (int) $eventRow['sequence'] + 1, $recipients);
        }
        PluginSynchronisationcaloutlookEvent::deleteForItem($itemtype, $items_id);
    }

    // ------------------------------------------------------------------
    // Résolution des destinataires
    // ------------------------------------------------------------------

    /**
     * @return array [email(minuscule) => nom affiché]
     */
    public static function resolveRecipients(CommonDBTM $item, array $config): array {
        $techId   = (int) ($item->fields['users_id_tech'] ?? 0);
        $groupId  = (int) ($item->fields['groups_id_tech'] ?? 0);
        $recipients = [];

        if ($techId > 0) {
            self::addUserRecipient($recipients, $techId, $item);
        }

        // Le groupe est TOUJOURS notifié dès qu'un groupe est renseigné,
        // qu'un technicien individuel le soit aussi ou non : le besoin
        // est de notifier toute personne assignée à la tâche, sans
        // exception (option de config retirée, elle n'avait plus de
        // raison d'être qu'à "Oui").
        $includeGroup = ($groupId > 0);

        if ($includeGroup) {
            global $DB;
            $iterator = $DB->request([
                'SELECT' => 'users_id',
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => $groupId],
            ]);
            foreach ($iterator as $row) {
                self::addUserRecipient($recipients, (int) $row['users_id'], $item);
            }
        }

        return $recipients;
    }

    private static function addUserRecipient(array &$recipients, int $users_id, CommonDBTM $item): void {
        if ($users_id <= 0) {
            return;
        }

        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return;
        }

        $email = method_exists($user, 'getDefaultEmail')
            ? $user->getDefaultEmail()
            : (string) ($user->fields['email'] ?? '');
        $email = trim((string) $email);

        if ($email === '' || !self::isValidEmailAddress($email)) {
            self::log(
                get_class($item),
                (int) $item->getID(),
                'SKIP',
                '-',
                sprintf("Utilisateur #%d (%s) ignoré : pas d'adresse email valide.", $users_id, $user->getFriendlyName())
            );
            return;
        }

        $email = strtolower($email);
        // La clé email assure la déduplication naturelle (tech direct ET
        // membre du groupe en même temps => un seul envoi).
        $recipients[$email] = $user->getFriendlyName() ?: $email;
    }

    // ------------------------------------------------------------------
    // Construction ICS + envoi mail
    // ------------------------------------------------------------------

    private static function sendRequestToAll(CommonDBTM $item, string $uid, int $sequence, array $recipients): void {
        $config = PluginSynchronisationcaloutlookConfig::getConfig();
        $identity = self::resolveMailIdentity($config);
        $attendeesList = self::toAttendeesList($recipients);

        foreach ($recipients as $email => $name) {
            $ics = PluginSynchronisationcaloutlookIcsBuilder::build([
                'uid'             => $uid,
                'sequence'        => $sequence,
                'method'          => 'REQUEST',
                'status'          => 'CONFIRMED',
                'dtstart'         => self::toDateTime((string) $item->fields['begin']),
                'dtend'           => self::toDateTime((string) $item->fields['end']),
                'summary'         => self::buildSummary($item, $config, 'REQUEST', $name),
                'description'     => self::buildDescription($item, $config, 'REQUEST', $name),
                'organizer_email' => $identity['email'],
                'organizer_name'  => $identity['name'],
                'attendees'       => $attendeesList,
            ]);

            self::dispatchMail($item, $email, $name, $ics, 'REQUEST', $identity, $config);
        }
    }

    /**
     * @param array $emails Liste d'adresses email (sans nom associé, ex:
     *                       destinataires retirés dont on ne connaît plus
     *                       que l'email via last_recipients).
     */
    private static function sendCancelToEmails(CommonDBTM $item, string $uid, int $sequence, array $emails): void {
        $config = PluginSynchronisationcaloutlookConfig::getConfig();
        $identity = self::resolveMailIdentity($config);

        foreach ($emails as $email) {
            $ics = PluginSynchronisationcaloutlookIcsBuilder::build([
                'uid'             => $uid,
                'sequence'        => $sequence,
                'method'          => 'CANCEL',
                'status'          => 'CANCELLED',
                'dtstart'         => self::toDateTime((string) ($item->fields['begin'] ?? 'now')),
                'dtend'           => self::toDateTime((string) ($item->fields['end'] ?? 'now')),
                'summary'         => self::buildSummary($item, $config, 'CANCEL', $email),
                'description'     => self::buildDescription($item, $config, 'CANCEL', $email),
                'organizer_email' => $identity['email'],
                'organizer_name'  => $identity['name'],
                // CANCEL ciblé : un seul ATTENDEE, celui à qui on annule.
                'attendees'       => [['email' => $email, 'name' => $email]],
            ]);

            self::dispatchMail($item, $email, $email, $ics, 'CANCEL', $identity, $config);
        }
    }

    /**
     * Résout l'identité d'envoi (adresse organisateur/expéditeur + éventuel
     * override des identifiants SMTP) à partir de la config du plugin.
     *
     * Si la case "Utiliser la configuration SMTP standard" est cochée,
     * aucun override : GLPIMailer utilise directement la config native
     * (Configuration > Notifications), point final. Sinon, un collecteur
     * de mails natif doit être sélectionné : son login et son mot de
     * passe déchiffré sont réutilisés pour l'authentification SMTP à
     * l'envoi et comme adresse organisateur. Le serveur/port/sécurité
     * SMTP restent ceux de la config standard, car glpi_mailcollectors ne
     * stocke pas de host SMTP exploitable (son champ host est une chaîne
     * de connexion IMAP).
     *
     * @return array {
     *   @type string      $email         Adresse à utiliser en ORGANIZER/From.
     *   @type string      $name          Nom affiché associé.
     *   @type string|null $smtp_username Login SMTP à forcer, ou null (config standard).
     *   @type string|null $smtp_password Mot de passe SMTP à forcer, ou null.
     * }
     */
    private static function resolveMailIdentity(array $config): array {
        global $CFG_GLPI;

        // Repli ultime : rien d'exploitable trouvé nulle part (ni config
        // standard, ni collecteur) — constante codée en dur, non éditable
        // (cf. section 2.7 du cahier des charges).
        $fallback = [
            'email'         => PluginSynchronisationcaloutlookConfig::DEFAULT_ORGANIZER_EMAIL,
            'name'          => 'ITSM-NG',
            'smtp_username' => null,
            'smtp_password' => null,
        ];

        // Case cochée = priorité absolue à la config SMTP standard.
        // "Aucun override" signifie utiliser la VRAIE identité configurée
        // dans Configuration > Notifications, pas une valeur codée en
        // dur : $CFG_GLPI['from_email']/['from_email_name'] sont les
        // champs confirmés (lecture de glpi_configs) utilisés par
        // NotificationEventMailing pour le "From" des notifications
        // natives (ex: "CONCORD" / dsi-supp-prod-it@hop.fr). Repli sur
        // admin_email si from_email est vide, puis sur $fallback en tout
        // dernier recours.
        if (!empty($config['use_standard_smtp'])) {
            $email = (string) ($CFG_GLPI['from_email'] ?? '');
            $name  = (string) ($CFG_GLPI['from_email_name'] ?? '');

            if ($email === '') {
                $email = (string) ($CFG_GLPI['admin_email'] ?? '');
            }

            if ($email === '' || !self::isValidEmailAddress($email)) {
                return $fallback;
            }

            return [
                'email'         => $email,
                'name'          => $name !== '' ? $name : 'ITSM-NG',
                'smtp_username' => null,
                'smtp_password' => null,
            ];
        }

        $collectorId = (int) ($config['mailcollectors_id'] ?? 0);

        if ($collectorId > 0) {
            $collector = new MailCollector();
            if (!$collector->getFromDB($collectorId) || empty($collector->fields['login'])) {
                Toolbox::logInFile('synchronisationcaloutlook', sprintf(
                    "[%s] Collecteur #%d introuvable ou sans login : repli config standard.\n",
                    date('Y-m-d H:i:s'),
                    $collectorId
                ));
                return $fallback;
            }

            $login = $collector->fields['login'];
            if (!self::isValidEmailAddress($login)) {
                Toolbox::logInFile('synchronisationcaloutlook', sprintf(
                    "[%s] Login du collecteur #%d (\"%s\") non exploitable comme adresse email : repli config standard.\n",
                    date('Y-m-d H:i:s'),
                    $collectorId,
                    $login
                ));
                return $fallback;
            }

            $password = null;
            if (!empty($collector->fields['passwd'])) {
                try {
                    $password = Toolbox::sodiumDecrypt($collector->fields['passwd']);
                } catch (\Throwable $e) {
                    $password = null;
                }
            }

            return [
                'email'         => $login,
                'name'          => $collector->fields['name'] ?: $login,
                'smtp_username' => $login,
                'smtp_password' => $password,
            ];
        }

        return $fallback;
    }

    private static function toAttendeesList(array $recipients): array {
        $list = [];
        foreach ($recipients as $email => $name) {
            $list[] = ['email' => $email, 'name' => $name];
        }
        return $list;
    }

    /**
     * Envoie l'email via le mailer interne GLPI/ITSM-NG (GLPIMailer),
     * déjà connecté au serveur SMTP configuré dans Configuration >
     * Notifications. Si $identity porte des identifiants issus d'un
     * collecteur de mails (cf. resolveMailIdentity), ils surchargent
     * Username/Password/From de ce mailer ; aucune credential n'est en
     * dur dans le plugin. Le message est structuré en multipart/alternative
     * (texte + text/calendar) pour être reconnu comme une vraie invitation
     * calendrier par le client mail, pas comme une simple pièce jointe.
     * Journalise systématiquement succès/échec.
     *
     * @param array $identity Résultat de resolveMailIdentity().
     */
    private static function dispatchMail(CommonDBTM $item, string $email, string $name, string $ics, string $method, array $identity, array $config): void {
        $itemtype = get_class($item);
        $items_id = (int) $item->getID();

        try {
            $mail = new GLPIMailer();

            if (!empty($identity['smtp_username'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $identity['smtp_username'];
                if (!empty($identity['smtp_password'])) {
                    $mail->Password = $identity['smtp_password'];
                }
            }

            $mail->SetFrom($identity['email'], $identity['name']);
            $mail->AddAddress($email, $name);
            $mail->Subject = self::buildSubject($item, $method, $config, $name);

            $htmlBody = self::buildHtmlBody($item, $config, $method, $name);

            if ($htmlBody !== null) {
                // Gabarit avec corps HTML configuré (logo, mise en page) :
                // structure classique multipart/alternative(texte, HTML),
                // ICS en pièce jointe method=REQUEST/CANCEL plutôt qu'en
                // corps principal. La plupart des clients (Gmail, Outlook
                // récent/web) reconnaissent quand même l'invitation via
                // cette pièce jointe ; à vérifier par un envoi réel sur
                // Outlook classique/desktop avant généralisation.
                //
                // Historique : plusieurs réglages CharSet/Encoding
                // explicites (quoted-printable, puis base64) ont été
                // essayés pour corriger un corps HTML affiché brut chez le
                // destinataire — aucun n'a résolu le problème. La cause
                // réelle, trouvée en comparant avec le code natif
                // NotificationEventMailing::send() (cœur ITSM-NG), est
                // ailleurs : voir resolveTemplate() et l'appel à
                // Html::entity_decode_deep() sur le contenu du gabarit.
                // AUCUN réglage CharSet/Encoding n'est nécessaire ici — le
                // code natif n'en applique aucun non plus, et fonctionne
                // avec les seules valeurs par défaut de GLPIMailer.
                $mail->IsHTML(true);
                $mail->AltBody = self::buildPlainBody($item, $method, $config, $name);
                $mail->Body = $htmlBody;
            } else {
                // Comportement par défaut (aucun gabarit HTML) : structure
                // RFC 5546 (iTIP/iMIP), multipart/alternative avec une
                // partie text/plain lisible partout et une partie
                // text/calendar portant METHOD:REQUEST/CANCEL comme corps
                // principal (pas une pièce jointe). C'est cette seconde
                // partie qui fait apparaître le message comme une
                // véritable invitation avec boutons Accepter/Décliner
                // dans Outlook, Gmail et Apple Mail — comportement testé
                // et confirmé fonctionnel en conditions réelles avant
                // l'ajout du gabarit HTML (cf. README). Volontairement
                // AUCUNE modification de CharSet/Encoding ici : rien n'a
                // jamais été signalé cassé sur ce chemin, et les clients
                // calendrier (Outlook en tête) sont réputés sensibles à
                // l'encodage exact d'un corps text/calendar pour le
                // reconnaître comme invitation cliquable — on ne touche
                // pas à ce qui fonctionne sans preuve d'un problème réel.
                $mail->IsHTML(false);
                $mail->AltBody = self::buildPlainBody($item, $method, $config, $name);
                $mail->Body = $ics;
                $mail->ContentType = 'text/calendar; charset=UTF-8; method=' . $method;
            }

            // Copie en pièce jointe dans tous les cas : pour les clients
            // qui préfèrent l'enregistrement/import manuel du fichier, et
            // c'est la seule copie du calendrier quand un corps HTML est
            // utilisé (cf. ci-dessus).
            $mail->AddStringAttachment(
                $ics,
                'invite.ics',
                'base64',
                'text/calendar; charset=UTF-8; method=' . $method
            );

            $sent = $mail->Send();

            self::log(
                $itemtype,
                $items_id,
                $method,
                $email,
                ($sent ? 'OK' : ('ECHEC : ' . $mail->ErrorInfo))
                    . sprintf(
                        ' [ContentType=%s Encoding=%s From=%s <%s>]',
                        $mail->ContentType,
                        $mail->Encoding,
                        $mail->FromName,
                        $mail->From
                    )
            );
        } catch (\Throwable $e) {
            self::log($itemtype, $items_id, $method, $email, 'EXCEPTION : ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Construction du contenu (sujet / corps / résumé / description)
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Utilisé par l'écran de prévisualisation (front/checknow.php)
    // ------------------------------------------------------------------

    /**
     * Libellé court d'une tâche pour la liste de prévisualisation avant
     * envoi (mêmes règles que le SUMMARY de l'ICS qui sera réellement
     * envoyé, gabarit compris).
     */
    public static function previewLabel(CommonDBTM $item): string {
        $config = PluginSynchronisationcaloutlookConfig::getConfig();
        return self::buildSummary($item, $config, 'REQUEST');
    }

    /**
     * Aperçu du contenu propre de la tâche (indépendant du ticket parent),
     * pour la colonne "Tâche" de l'écran de prévisualisation
     * (front/checknow.php). Volontairement distinct de previewLabel(),
     * qui reprend le titre du ticket parent quand il existe (même règle
     * que le SUMMARY réellement envoyé) : la colonne "Ticket" affiche
     * déjà ce titre avec son lien, donc la colonne "Tâche" doit montrer
     * autre chose (la description propre à la tâche), sinon les deux
     * colonnes affichent le même texte et donnent l'impression trompeuse
     * que le lien du ticket reprend en fait le nom de la tâche.
     */
    public static function taskContentPreview(CommonDBTM $item): string {
        $content = self::cleanContent((string) ($item->fields['content'] ?? ''));
        if ($content === '') {
            return sprintf(__('Tâche planifiée #%d'), (int) $item->getID());
        }
        $short = mb_substr($content, 0, 80);
        return $short . (mb_strlen($content) > 80 ? '…' : '');
    }

    /**
     * Nom + URL du ticket/problème/changement parent, pour affichage dans
     * l'écran de prévisualisation "Vérifier maintenant" (front/checknow.php).
     * Simple wrapper public autour de getParentInfo() (privée), sans le
     * statut qui ne concerne que la logique interne isParentResolved().
     *
     * @return array{name: ?string, url: ?string}
     */
    public static function getParentLink(CommonDBTM $item): array {
        $info = self::getParentInfo($item);
        return ['name' => $info['name'], 'url' => $info['url']];
    }

    private static function getParentInfo(CommonDBTM $item): array {
        $itemtype = get_class($item);
        if (!isset(self::PARENT_MAP[$itemtype])) {
            return ['name' => null, 'url' => null, 'status' => null];
        }

        [$parentClass, $fk] = self::PARENT_MAP[$itemtype];
        $parentId = (int) ($item->fields[$fk] ?? 0);
        if ($parentId <= 0) {
            return ['name' => null, 'url' => null, 'status' => null];
        }

        $parent = new $parentClass();
        if (!$parent->getFromDB($parentId)) {
            return ['name' => null, 'url' => null, 'status' => null];
        }

        global $CFG_GLPI;
        $formUrl = $parentClass::getFormURLWithID($parentId);
        // Robustesse : selon la version, getFormURLWithID() peut renvoyer
        // un chemin relatif (root_doc + front/...) ou déjà une URL absolue.
        // On ne préfixe par url_base QUE si ce n'est pas déjà le cas, pour
        // éviter un lien cassé (domaine dupliqué) si le comportement
        // diffère de ce qui a été observé initialement — sans jamais
        // deviner silencieusement cette API coeur.
        if (strpos($formUrl, '://') !== false) {
            $url = $formUrl;
        } else {
            $url = rtrim($CFG_GLPI['url_base'] ?? '', '/') . '/' . ltrim($formUrl, '/');
        }

        return [
            'name'   => self::cleanContent((string) ($parent->fields['name'] ?? ('#' . $parentId))),
            'url'    => $url,
            'status' => isset($parent->fields['status']) ? (int) $parent->fields['status'] : null,
        ];
    }

    /**
     * Un ticket/problème/changement déjà résolu ou clos ne doit plus
     * générer d'invitation pour ses tâches planifiées. SOLVED/CLOSED sont
     * les constantes coeur GLPI/ITSM-NG communes à Ticket/Problem/Change
     * (héritées de CommonITILObject) ; repli sur 5/6 si la classe n'est
     * pas chargée à ce stade.
     */
    public static function isParentResolved(CommonDBTM $item): bool {
        $parent = self::getParentInfo($item);
        if ($parent['status'] === null) {
            return false; // pas de parent identifié : on ne bloque rien sur cette base
        }

        if (class_exists('CommonITILObject')) {
            $resolvedStatuses = [CommonITILObject::SOLVED, CommonITILObject::CLOSED];
        } else {
            $resolvedStatuses = [5, 6];
        }

        return in_array($parent['status'], $resolvedStatuses, true);
    }

    /**
     * Nettoie un champ de contenu ITSM-NG (texte riche) pour un usage en
     * texte brut (email, ICS). Le contenu est stocké "sanitizé" (balises
     * échappées en entités HTML) par le cœur ; sans décodage préalable,
     * strip_tags() ne retire rien et des séquences comme "&lt;p&gt;"
     * restent visibles telles quelles dans le résultat final.
     */
    private static function cleanContent(string $raw): string {
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($decoded, '&lt;') !== false || strpos($decoded, '&amp;') !== false) {
            // Contenu doublement échappé selon la version : second passage.
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // Retire le contenu (pas seulement les balises) des blocs <style>
        // et <script> AVANT strip_tags() : sinon le CSS/JS qu'ils
        // contiennent reste comme texte visible dans le résultat (cas
        // vécu : un gabarit HTML complet — avec son <style> d'en-tête —
        // collé par erreur dans le champ "Corps" texte simple laissait
        // apparaître "/* Resets */ ..." dans la description de l'ICS).
        $decoded = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $decoded) ?? $decoded;
        $decoded = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $decoded) ?? $decoded;
        $text = strip_tags($decoded);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        return trim($text ?? '');
    }

    /**
     * Résout le gabarit sélectionné en config, ou null si aucun (dans ce
     * cas, un gabarit minimal par défaut, codé en dur, est utilisé). Table
     * propre au plugin : voir PluginSynchronisationcaloutlookTemplate pour
     * le choix de ne pas réutiliser les modèles de notification natifs.
     */
    private static function resolveTemplate(): ?array {
        // Singleton (cf. inc/template.class.php) : plus de sélection par
        // id via la config, un seul gabarit possible. S'il existe, il
        // est utilisé automatiquement.
        if (!PluginSynchronisationcaloutlookTemplate::exists()) {
            return null;
        }

        $template = PluginSynchronisationcaloutlookTemplate::getSingleton();

        // Décodage systématique des entités HTML sur les 3 champs : GLPI/
        // ITSM-NG sanitize automatiquement les champs texte/textarea en
        // entrée (add()/update() génériques de CommonDBTM), donc le HTML
        // saisi dans "Corps HTML" est stocké échappé en base (ex:
        // "&lt;html&gt;" au lieu de "<html>"). Sans ce décodage, le corps
        // envoyé est littéralement le texte échappé : Content-Type reste
        // bien text/html et le client mail l'affiche fidèlement... mais
        // les entités s'affichent comme du texte visible ("<html>...")
        // plutôt que d'être interprétées comme du balisage. Reproduit du
        // comportement natif confirmé fonctionnel :
        // NotificationEventMailing::send() applique exactement ce
        // décodage sur body_html avant de l'assigner au mailer (cf.
        // inc/notificationeventmailing.class.php du cœur).
        return [
            'subject'      => Html::entity_decode_deep((string) ($template->fields['subject'] ?? '')),
            'content'      => Html::entity_decode_deep((string) ($template->fields['content'] ?? '')),
            'content_html' => Html::entity_decode_deep((string) ($template->fields['content_html'] ?? '')),
        ];
    }

    /**
     * Remplace les balises ##XXX## d'un gabarit par les valeurs de la
     * tâche. Balises disponibles : ##TICKET##, ##CONTENU##, ##DEBUT##,
     * ##FIN##, ##LIEN##, ##DESTINATAIRE##.
     */
    private static function renderPlaceholders(string $template, CommonDBTM $item, array $parent, string $content, string $recipientName): string {
        $values = [
            '##TICKET##'       => $parent['name'] ?? '',
            '##CONTENU##'      => $content,
            '##DEBUT##'        => (string) ($item->fields['begin'] ?? ''),
            '##FIN##'          => (string) ($item->fields['end'] ?? ''),
            '##LIEN##'         => $parent['url'] ?? '',
            '##DESTINATAIRE##' => $recipientName,
        ];
        return strtr($template, $values);
    }

    /**
     * Titre de l'événement (SUMMARY de l'ICS + repris dans le sujet du
     * mail). Utilise le gabarit sélectionné pour une invitation
     * (METHOD:REQUEST) ; une annulation garde un intitulé neutre, non
     * personnalisable, indépendant du gabarit.
     */
    private static function buildSummary(CommonDBTM $item, array $config, string $method, string $recipientName = ''): string {
        $parent  = self::getParentInfo($item);
        $content = self::cleanContent((string) ($item->fields['content'] ?? ''));

        if ($method === 'REQUEST') {
            $template = self::resolveTemplate();
            if ($template !== null && $template['subject'] !== '') {
                // cleanContent() en sécurité : le champ "Sujet" du gabarit
                // est censé être du texte simple, mais rien n'empêche un
                // admin d'y coller (par erreur ou par copier-coller) du
                // HTML — un SUMMARY d'ICS avec des balises brutes s'affiche
                // tel quel, lisiblement cassé, dans tout client calendrier.
                return self::cleanContent(self::renderPlaceholders($template['subject'], $item, $parent, $content, $recipientName));
            }
        }

        // Gabarit par défaut : titre du ticket/dossier s'il existe, sinon
        // extrait du contenu de la tâche.
        if ($parent['name'] !== null && $parent['name'] !== '') {
            return $parent['name'];
        }
        $short = mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '…' : '');
        return $short !== '' ? $short : ('Tâche planifiée #' . $item->getID());
    }

    /**
     * Corps de l'événement (DESCRIPTION de l'ICS). Même logique de
     * gabarit que buildSummary().
     */
    private static function buildDescription(CommonDBTM $item, array $config, string $method, string $recipientName = ''): string {
        $parent  = self::getParentInfo($item);
        $content = mb_substr(self::cleanContent((string) ($item->fields['content'] ?? '')), 0, 1000);

        if ($method === 'REQUEST') {
            $template = self::resolveTemplate();
            if ($template !== null && $template['content'] !== '') {
                // cleanContent() OBLIGATOIRE ici : le champ "Corps" (texte
                // simple) du gabarit alimente directement la DESCRIPTION de
                // l'ICS et le corps texte de secours du mail (cf.
                // buildPlainBody()) — jamais le corps HTML principal. Ce
                // sont deux endroits lus par des clients qui n'interprètent
                // PAS le HTML (Outlook/Google Calendar affichent DESCRIPTION
                // comme du texte brut). Sans ce filet de sécurité, un admin
                // ayant collé le même contenu que "Corps HTML (optionnel)"
                // dans "Corps" par erreur se retrouve avec les balises
                // brutes affichées telles quelles dans la fiche de
                // rendez-vous du destinataire (symptôme observé : "<html>
                // <head> <meta charset=..." visible en toutes lettres dans
                // la vue calendrier). "Corps HTML" reste, lui, intact et
                // n'est JAMAIS filtré : c'est le seul champ autorisé à
                // contenir du balisage.
                return self::cleanContent(self::renderPlaceholders($template['content'], $item, $parent, $content, $recipientName));
            }
        }

        $lines = [];
        if ($content !== '') {
            $lines[] = $content;
        }
        if ($parent['url'] !== null) {
            $lines[] = __('Lien') . ' : ' . $parent['url'];
        }
        return implode("\n", $lines);
    }

    private static function buildSubject(CommonDBTM $item, string $method, array $config, string $recipientName = ''): string {
        // Si un gabarit est configuré avec un Sujet non vide (uniquement
        // pertinent pour REQUEST : une annulation garde toujours un sujet
        // neutre non gabarisé), CE sujet EST le sujet du mail tel quel —
        // l'admin l'a rédigé en entier dans le champ "Sujet" du gabarit,
        // préfixe "[ITSM-NG] ..." compris s'il le souhaite. On ne doit
        // SURTOUT PAS rajouter le préfixe générique par-dessus : ça
        // produisait un doublon ("[ITSM-NG] Invitation planifiée :
        // [ITSM-NG] Invitation planifiée : ..." si le gabarit reprend
        // lui-même ce préfixe, ce qui est un choix légitime et même
        // suggéré par défaut). Le préfixe générique ci-dessous ne sert
        // donc que de repli quand AUCUN gabarit avec sujet n'est configuré.
        if ($method === 'REQUEST') {
            $template = self::resolveTemplate();
            if ($template !== null && $template['subject'] !== '') {
                $parent  = self::getParentInfo($item);
                $content = self::cleanContent((string) ($item->fields['content'] ?? ''));
                // cleanContent() ici aussi : voir la note détaillée dans
                // buildDescription(). Un sujet de mail avec des balises
                // HTML brutes ne "casse" rien techniquement (un sujet est
                // toujours du texte brut), mais afficherait les balises en
                // toutes lettres dans la boîte de réception si le même
                // contenu que "Corps HTML" y était collé par erreur.
                return self::cleanContent(self::renderPlaceholders($template['subject'], $item, $parent, $content, $recipientName));
            }
        }

        $summary = self::buildSummary($item, $config, $method, $recipientName);
        $prefix = $method === 'CANCEL' ? __('Rendez-vous annulé') : __('Invitation planifiée');
        return '[ITSM-NG] ' . $prefix . ' : ' . $summary;
    }

    private static function buildPlainBody(CommonDBTM $item, string $method, array $config, string $recipientName = ''): string {
        $begin = (string) ($item->fields['begin'] ?? '');
        $end   = (string) ($item->fields['end'] ?? '');
        $parent = self::getParentInfo($item);
        $description = self::buildDescription($item, $config, $method, $recipientName);

        $lines = [];
        if ($method === 'CANCEL') {
            $lines[] = __("La tâche planifiée suivante a été annulée ou n'est plus assignée à cette adresse :");
        } else {
            $lines[] = __('Une tâche vous a été planifiée :');
        }
        $lines[] = '';
        $lines[] = __('Début') . ' : ' . $begin;
        $lines[] = __('Fin') . ' : ' . $end;
        if ($parent['name'] !== null) {
            $lines[] = __('Ticket/dossier') . ' : ' . $parent['name'];
        }
        if ($description !== '') {
            $lines[] = '';
            $lines[] = $description;
        }
        $lines[] = '';
        $lines[] = __("Une invitation calendrier (.ics) est jointe à ce message.");

        return implode("\n", $lines);
    }

    /**
     * Corps HTML du mail, uniquement si le gabarit sélectionné en fournit
     * un (champ "Corps HTML (optionnel)") et pour une invitation
     * (METHOD:REQUEST) — une annulation reste en texte + calendrier
     * simple. Retourne null si aucun gabarit HTML n'est configuré, auquel
     * cas dispatchMail() garde le comportement d'origine (calendrier en
     * corps principal du mail).
     */
    private static function buildHtmlBody(CommonDBTM $item, array $config, string $method, string $recipientName = ''): ?string {
        if ($method !== 'REQUEST') {
            return null;
        }

        $template = self::resolveTemplate();
        if ($template === null || empty($template['content_html'])) {
            return null;
        }

        $parent  = self::getParentInfo($item);
        $content = mb_substr(self::cleanContent((string) ($item->fields['content'] ?? '')), 0, 1000);

        return self::renderPlaceholders($template['content_html'], $item, $parent, $content, $recipientName);
    }

    /**
     * Convertit une date GLPI ('Y-m-d H:i:s', fuseau serveur PHP) en
     * DateTime. Limitation v1 : suppose que le fuseau horaire configuré
     * pour PHP (date_default_timezone_get) correspond à celui affiché aux
     * utilisateurs dans ITSM-NG — voir README, section Limitations.
     */
    private static function toDateTime(string $value): DateTime {
        try {
            return new DateTime($value, new DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable $e) {
            return new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        }
    }

    /**
     * Validation d'adresse email en PHP natif (filter_var), sans dépendre
     * d'une méthode de Toolbox qui peut ne pas exister selon la version/le
     * fork du cœur (ex: Toolbox::isValidEmail absente sur cette instance
     * ITSM-NG 2.1.4).
     */
    private static function isValidEmailAddress(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Journalise dans files/_log/synchronisationcaloutlook.log via le mécanisme de logs
     * standard GLPI/ITSM-NG (Toolbox::logInFile).
     */
    private static function log(string $itemtype, int $items_id, string $method, string $recipient, string $result): void {
        Toolbox::logInFile(
            'synchronisationcaloutlook',
            sprintf(
                "[%s] itemtype=%s items_id=%d methode=%s destinataire=%s resultat=%s\n",
                date('Y-m-d H:i:s'),
                $itemtype,
                $items_id,
                $method,
                $recipient,
                $result
            )
        );
    }
}
