<?php
namespace Koboldsoft\CoachPoolBundle\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class CoachPool
{
    private HubSpotClient $hubspot;
    private Connection $db;
    private LoggerInterface $logger;

    public function __construct(HubSpotClient $hubspot, Connection $db, LoggerInterface $logger)
    {
        $this->hubspot = $hubspot;
        $this->db = $db;
        $this->logger = $logger;
    }

    public function allowed(int $memberId, int $teamId): bool
    {
        return $memberId > 0 && $teamId > 0 && (bool) $this->db->fetchOne(
            'SELECT id FROM mm_vertriebsteam_member WHERE id_coach = ? AND id_vertriebsteam = ?',
            [$memberId, $teamId]
        );
    }

    public function listing(string $description = '', bool $includeInterested = true): array
    {
        return $this->hubspot->openContacts('', $includeInterested);
    }

    /** Freigaberecht = explizite Auswahl im Modul UND aktuelle C-Pro-Mitgliedschaft. */
    public function canReview(int $memberId, int $teamId, array $reviewers): bool
    {
        return in_array($memberId, array_map('intval', $reviewers), true) && $this->allowed($memberId, $teamId);
    }

    public function reviewListing(int $memberId, int $teamId, array $reviewers): array
    {
        if (!$this->canReview($memberId, $teamId, $reviewers)) {
            throw new \DomainException('Für die Vergabeansicht fehlt die Berechtigung.');
        }
        $entries = $this->hubspot->reviewContacts();
        foreach ($entries as &$entry) {
            $entry['coaches'] = [];
            foreach (self::emails((string) ($entry['pool_interesse_von'] ?? '')) as $email) {
                // Nur die Prüferansicht erhält Coach-Namen und -Adressen, nie die Kartenansicht.
                $members = $this->db->fetchAllAssociative('SELECT id, firstname, lastname FROM tl_member WHERE LOWER(email) = ?', [$email]);
                $member = count($members) === 1 ? $members[0] : null;
                $name = $member ? trim($member['firstname'].' '.$member['lastname']) : '';
                $entry['coaches'][] = ['email' => $email, 'name' => $name ?: $email,
                    'eligible' => $member && $this->allowed((int) $member['id'], $teamId)];
            }
        }
        unset($entry);
        return $entries;
    }

    /** Textfelder enthalten eindeutige E-Mail-Adressen, mit Semikolon getrennt. */
    public static function emails(string $value): array
    {
        $emails = [];
        foreach (preg_split('/[;\r\n,]+/', $value) as $email) {
            $email = strtolower(trim($email));
            if ($email === '') { continue; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Unbekannte Altwerte niemals still überschreiben oder als Coach interpretieren.
                throw new \DomainException('Das HubSpot-Feld pool_interesse_von enthält ungültige Coach-Adressen. Bitte das Team kontaktieren.');
            }
            $emails[$email] = $email;
        }
        return array_values($emails);
    }

    public function logLoadFailure(\Throwable $error): void
    {
        // Use the injected logger: Symfony's logger service is private in production.
        // Do not log exception messages or traces containing credentials or contact data.
        $context = ['exception_class' => get_class($error)];
        if (preg_match('/HubSpot-Anfrage fehlgeschlagen \(HTTP (\d{3})\)\./', $error->getMessage(), $match)) {
            $context['hubspot_http_status'] = (int) $match[1];
        }
        if ($error->getMessage() === 'HubSpot-Zugang fehlt.') {
            $context['hubspot_token_missing'] = true;
        }
        $this->logger->error('Coach-Pool konnte nicht geladen werden.', $context);
    }

    public function interests(int $memberId): array
    {
        return $this->db->fetchAllKeyValue('SELECT contact_id, status FROM tl_coach_pool_interest WHERE member_id = ?', [$memberId]);
    }

    /** Interesse schreibt zuerst den HubSpot-Kontakt; erst danach wird die Teamaufgabe angelegt. */
    public function report(int $memberId, int $teamId, string $contactId, bool $includeInterested = true): void
    {
        if (!$this->allowed($memberId, $teamId)) {
            throw new \DomainException('Diese Funktion steht nur Coaches mit Team C-Pro zur Verfügung.');
        }
        $this->locked($contactId, function () use ($memberId, $teamId, $contactId, $includeInterested): void {
            $email = strtolower(trim((string) $this->db->fetchOne('SELECT email FROM tl_member WHERE id = ?', [$memberId])));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \DomainException('Bitte zuerst eine gültige E-Mail-Adresse im Profil hinterlegen.');
            }
            // Der Coach wird ausschließlich über die gespeicherte Login-E-Mail identifiziert.
            $coach = $this->hubspot->coach($email);
            $contact = $this->hubspot->contact($contactId, ['pool_status', 'pool_nummer', 'pool_interesse_von']);
            $properties = $contact['properties'] ?? [];
            $emails = self::emails((string) ($properties['pool_interesse_von'] ?? ''));
            $alreadyInterested = in_array($email, $emails, true);
            $statuses = $includeInterested || $alreadyInterested ? array_merge(HubSpotClient::OPEN, [HubSpotClient::INTERESTED]) : HubSpotClient::OPEN;
            if (!empty($contact['archived']) || !in_array($properties['pool_status'] ?? '', $statuses, true)) {
                throw new \DomainException('Diese Anfrage ist inzwischen nicht mehr im Pool verfügbar.');
            }
            $row = $this->db->fetchAssociative('SELECT status, task_id FROM tl_coach_pool_interest WHERE member_id = ? AND contact_id = ?', [$memberId, $contactId]);
            if ($row && $row['status'] === 'sending') {
                throw new \DomainException('Die Übermittlung wird geprüft. Bitte nicht erneut senden und bei Bedarf das Team kontaktieren.');
            }
            if ($alreadyInterested && ($properties['pool_status'] ?? '') === HubSpotClient::INTERESTED && (!$row || $row['status'] === 'received')) {
                if (!$row) {
                    $this->db->insert('tl_coach_pool_interest', ['tstamp' => time(), 'member_id' => $memberId, 'contact_id' => $contactId, 'status' => 'received', 'task_id' => '']);
                }
                return; // Wiederholter Klick: weder PATCH noch eine zweite Aufgabe.
            }
            $wasReceived = $row && $row['status'] === 'received';
            if (!$row) {
                $this->db->insert('tl_coach_pool_interest', ['tstamp' => time(), 'member_id' => $memberId,
                    'contact_id' => $contactId, 'status' => 'syncing', 'task_id' => '']);
            }
            if (!$alreadyInterested || ($properties['pool_status'] ?? '') !== HubSpotClient::INTERESTED) {
                $emails[] = $email;
                // Die Kontaktsperre schützt das Lesen/Ergänzen/Schreiben vor parallelen Klicks.
                $this->hubspot->updatePool($contactId, ['pool_status' => HubSpotClient::INTERESTED,
                    'pool_interesse_von' => implode(';', array_unique($emails))]);
            }
            if ($wasReceived) { return; } // Vorhandene Aufgabe aus der bisherigen Bundle-Version erhalten.
            $this->db->update('tl_coach_pool_interest', ['status' => 'sending', 'tstamp' => time()], ['member_id' => $memberId, 'contact_id' => $contactId]);
            try {
                $taskId = $this->hubspot->createTask($contactId, (string) $coach['id'], $email, (string) ($properties['pool_nummer'] ?? $contactId));
                $this->db->update('tl_coach_pool_interest', ['status' => 'received', 'task_id' => $taskId, 'tstamp' => time()], ['member_id' => $memberId, 'contact_id' => $contactId]);
            } catch (\Throwable $e) {
                $this->logLoadFailure($e);
                throw new \DomainException('Das Interesse ist in HubSpot gespeichert, die Teamaufgabe konnte aber nicht bestätigt werden. Bitte das Team kontaktieren.');
            }
        });
    }

    /** Die Freigabe erfolgt nur für einen tatsächlich interessierten, weiterhin berechtigten Coach. */
    public function approve(int $reviewerId, int $teamId, array $reviewers, string $contactId, string $email): void
    {
        if (!$this->canReview($reviewerId, $teamId, $reviewers)) {
            throw new \DomainException('Für die Freigabe fehlt die Berechtigung.');
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Bitte einen interessierten Coach auswählen.');
        }
        $this->locked($contactId, function () use ($reviewerId, $teamId, $contactId, $email): void {
            $contact = $this->hubspot->contact($contactId, ['pool_status', 'pool_interesse_von', 'pool_freigegeben_fuer']);
            $p = $contact['properties'] ?? [];
            if (!empty($contact['archived'])) {
                throw new \DomainException('Diese Anfrage ist nicht mehr verfügbar.');
            }
            if (($p['pool_status'] ?? '') === HubSpotClient::APPROVED) {
                if (strcasecmp((string) ($p['pool_freigegeben_fuer'] ?? ''), $email) === 0) {
                    $this->db->update('tl_coach_pool_assignment', ['status' => 'approved', 'tstamp' => time()], ['contact_id' => $contactId, 'coach_email' => $email]);
                    return; // Keine zweite Workflow-Auslösung beim erneuten Absenden.
                }
                throw new \DomainException('Diese Anfrage wurde bereits einem anderen Coach freigegeben.');
            }
            if (($p['pool_status'] ?? '') !== HubSpotClient::INTERESTED || !in_array($email, self::emails((string) ($p['pool_interesse_von'] ?? '')), true)) {
                throw new \DomainException('Der ausgewählte Coach hat für diese Anfrage kein aktuelles Interesse gemeldet.');
            }
            $members = $this->db->fetchAllAssociative('SELECT id FROM tl_member WHERE LOWER(email) = ?', [$email]);
            if (count($members) !== 1 || !$this->allowed((int) $members[0]['id'], $teamId)) {
                throw new \DomainException('Der ausgewählte Coach ist nicht eindeutig dem Team C-Pro zugeordnet.');
            }
            $coach = $this->hubspot->coach($email);
            if ($this->db->fetchOne('SELECT id FROM tl_coach_pool_assignment WHERE contact_id = ?', [$contactId])) {
                throw new \DomainException('Für diese Anfrage existiert bereits ein Freigabeversuch. Bitte den Stand in HubSpot prüfen.');
            }
            // Auch ein unbestätigter API-Aufruf bleibt gesperrt: nach Timeout nicht versehentlich neu vergeben.
            $this->db->insert('tl_coach_pool_assignment', ['contact_id' => $contactId, 'reviewer_id' => $reviewerId,
                'coach_email' => $email, 'coach_id' => (string) $coach['id'], 'status' => 'sending', 'tstamp' => time()]);
            try {
                $this->hubspot->updatePool($contactId, ['pool_status' => HubSpotClient::APPROVED, 'pool_freigegeben_fuer' => $email]);
                $this->db->update('tl_coach_pool_assignment', ['status' => 'approved', 'tstamp' => time()], ['contact_id' => $contactId]);
            } catch (\Throwable $e) {
                $this->logLoadFailure($e);
                // Bei einer expliziten Ablehnung hat HubSpot nicht geschrieben; ein korrigierter Versuch ist sicher.
                if (preg_match('/HTTP (400|401|403|404)\)/', $e->getMessage())) {
                    $this->db->delete('tl_coach_pool_assignment', ['contact_id' => $contactId]);
                }
                throw new \DomainException('Die Freigabe konnte nicht bestätigt werden. Bitte den Kontakt und das Feld pool_freigegeben_fuer in HubSpot prüfen.');
            }
        });
    }

    /** MySQL-Sitzungssperre: Vergabe und Interesse derselben Anfrage laufen nacheinander. */
    private function locked(string $contactId, callable $operation): void
    {
        if (!ctype_digit($contactId)) { throw new \DomainException('Ungültige Anfrage.'); }
        $key = 'coach_pool_'.sha1($contactId);
        if ((int) $this->db->fetchOne('SELECT GET_LOCK(?, 5)', [$key]) !== 1) {
            throw new \DomainException('Die Anfrage wird gerade bearbeitet. Bitte erneut versuchen.');
        }
        try { $operation(); }
        finally { $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [$key]); }
    }
}
