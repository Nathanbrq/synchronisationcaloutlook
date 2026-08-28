<?php

/**
 * Génère le contenu d'un fichier .ics conforme RFC 5545, pour un
 * événement unique (VEVENT), en méthode REQUEST (invitation/mise à jour)
 * ou CANCEL (annulation).
 *
 * Classe volontairement sans dépendance au coeur GLPI (pas de CommonDBTM,
 * pas d'accès DB) : uniquement des DateTime/DateTimeZone et des chaînes,
 * pour rester facilement testable unitairement (ex: PHPUnit ou script
 * autonome), indépendamment d'une instance ITSM-NG démarrée.
 */
class PluginSynchronisationcaloutlookIcsBuilder {

    const MAX_LINE_LENGTH = 75;

    /**
     * Échappe une valeur texte ICS : antislash, point-virgule, virgule,
     * puis remplace les retours à la ligne réels par la séquence littérale
     * \n exigée par la RFC 5545 pour les valeurs TEXT (SUMMARY,
     * DESCRIPTION...). Empêche également l'injection d'un saut de ligne
     * qui casserait la structure du fichier .ics (propriétés supplémentaires
     * injectées par un contenu utilisateur non maîtrisé).
     */
    public static function escapeText(string $value): string {
        $value = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        return $value;
    }

    /**
     * "Folding" de ligne RFC 5545 : une ligne de contenu ne doit pas
     * dépasser 75 octets ; au-delà, on insère un CRLF suivi d'un espace
     * (espace qui sera ignoré par le parseur du destinataire).
     */
    public static function foldLine(string $line): string {
        if (strlen($line) <= self::MAX_LINE_LENGTH) {
            return $line;
        }

        $folded = '';
        $remaining = $line;
        $first = true;

        while (strlen($remaining) > self::MAX_LINE_LENGTH) {
            $chunkSize = $first ? self::MAX_LINE_LENGTH : self::MAX_LINE_LENGTH - 1;
            $folded .= ($first ? '' : "\r\n ") . substr($remaining, 0, $chunkSize);
            $remaining = substr($remaining, $chunkSize);
            $first = false;
        }
        $folded .= "\r\n " . $remaining;

        return $folded;
    }

    /**
     * Construit le fichier .ics complet.
     *
     * @param array $params {
     *   @type string   $uid             UID iCal stable de l'événement.
     *   @type int      $sequence        Numéro de séquence RFC 5545.
     *   @type string   $method          'REQUEST' ou 'CANCEL'.
     *   @type string   $status          'CONFIRMED' ou 'CANCELLED'.
     *   @type DateTime $dtstart         Début (n'importe quel fuseau, converti en UTC).
     *   @type DateTime $dtend           Fin (idem).
     *   @type string   $summary         Résumé (texte brut, sera échappé).
     *   @type string   $description     Description (texte brut, sera échappée).
     *   @type string   $organizer_email Email de l'organisateur.
     *   @type string   $organizer_name  Nom affiché de l'organisateur (optionnel).
     *   @type array    $attendees       Liste ['email' => ..., 'name' => ...][].
     * }
     * @return string Contenu .ics, fins de ligne CRLF.
     */
    public static function build(array $params): string {
        $uid       = (string) $params['uid'];
        $sequence  = (int) ($params['sequence'] ?? 0);
        $method    = strtoupper((string) ($params['method'] ?? 'REQUEST'));
        $status    = strtoupper((string) ($params['status'] ?? 'CONFIRMED'));
        $orgEmail  = (string) $params['organizer_email'];
        $orgName   = (string) ($params['organizer_name'] ?? 'ITSM-NG');
        $attendees = $params['attendees'] ?? [];

        $dtstamp = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

        $dtstart = clone $params['dtstart'];
        $dtstart->setTimezone(new DateTimeZone('UTC'));

        $dtend = clone $params['dtend'];
        $dtend->setTimezone(new DateTimeZone('UTC'));

        $lines   = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//ITSM-NG//synchronisationcaloutlook 1.0//FR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:' . $method;
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = self::foldLine('UID:' . $uid);
        $lines[] = 'DTSTAMP:' . $dtstamp;
        $lines[] = 'DTSTART:' . $dtstart->format('Ymd\THis\Z');
        $lines[] = 'DTEND:' . $dtend->format('Ymd\THis\Z');
        $lines[] = self::foldLine('SUMMARY:' . self::escapeText((string) ($params['summary'] ?? '')));
        $lines[] = self::foldLine('DESCRIPTION:' . self::escapeText((string) ($params['description'] ?? '')));
        $lines[] = self::foldLine(
            'ORGANIZER;CN=' . self::escapeText($orgName) . ':mailto:' . $orgEmail
        );

        foreach ($attendees as $attendee) {
            $email = (string) $attendee['email'];
            $name  = (string) ($attendee['name'] ?? $email);
            $lines[] = self::foldLine(
                'ATTENDEE;CN=' . self::escapeText($name)
                . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $email
            );
        }

        $lines[] = 'STATUS:' . $status;
        $lines[] = 'SEQUENCE:' . $sequence;
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }
}
