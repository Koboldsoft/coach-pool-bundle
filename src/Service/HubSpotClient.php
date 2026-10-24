<?php
namespace Koboldsoft\CoachPoolBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class HubSpotClient
{
    public const OPEN = ['offen', 'zurueck_im_pool'];
    public const INTERESTED = 'interesse_eingegangen';
    public const APPROVED = 'freigegeben';
    public const FIELDS = [
        'pool_status', 'pool_nummer', 'pool_kurzbeschreibung', 'createdate', 'city', 'coachingformat', 'coachingtyp',
        'welche_forderung_mochtest_du_fur_dein_coaching_nutzen',
        'hast_du_schon_einen_avgs_gutschein_oder_brauchst_du_unterstutzung_beim_antrag_',
        'falls_du_noch_keinen_avgs_hast_hattest_du_bereits_kontakt_mit_deinerdeinem_sachbearbeiterin_zum_avgs',
        'gutscheingultigkeit', 'zeitraum_fur_start', 'bevorzugter_kontaktweg',
        'zielgruppe', 'branche__kategorie',
    ];

    private HttpClientInterface $http;
    private string $token;

    public function __construct(HttpClientInterface $http, string $token)
    {
        $this->http = $http;
        $this->token = $token;
    }

    /** Die normale Ansicht erhält niemals die E-Mail-Adressen anderer Coaches. */
    public function openContacts(string $descriptionProperty = '', bool $includeInterested = true): array
    {
        // Das frühere konfigurierbare Beschreibungsfeld bleibt nur API-kompatibel.
        if ($descriptionProperty !== '' && !preg_match('/^pool_[a-z0-9_]+$/D', $descriptionProperty)) {
            throw new \InvalidArgumentException('Ungültiges Beschreibungsfeld.');
        }
        return $this->searchContacts($includeInterested ? array_merge(self::OPEN, [self::INTERESTED]) : self::OPEN, self::FIELDS);
    }

    /** Personenbezogene Vergabedaten werden ausschließlich nach der Prüferprüfung geladen. */
    public function reviewContacts(): array
    {
        return $this->searchContacts(array_merge(self::OPEN, [self::INTERESTED, self::APPROVED]), array_merge(self::FIELDS, ['pool_interesse_von', 'pool_freigegeben_fuer']));
    }

    private function searchContacts(array $statuses, array $properties): array
    {
        $body = [
            'filterGroups' => [['filters' => [['propertyName' => 'pool_status', 'operator' => 'IN', 'values' => $statuses]]]],
            'properties' => $properties,
            'sorts' => [['propertyName' => 'gutscheingultigkeit', 'direction' => 'ASCENDING']],
            'limit' => 100,
        ];
        $contacts = [];
        $seen = [];
        do {
            $page = $this->request('POST', '/crm/v3/objects/contacts/search', $body);
            if (!isset($page['results']) || !is_array($page['results'])) {
                throw new \RuntimeException('Ungültige HubSpot-Suchantwort.');
            }
            foreach ($page['results'] as $contact) {
                // Search returns default PII properties even when they were not requested.
                $p = array_intersect_key($contact['properties'] ?? [], array_flip($properties));
                if (in_array($p['pool_status'] ?? '', $statuses, true)) {
                    $contacts[(string) $contact['id']] = $p;
                }
            }
            $after = $page['paging']['next']['after'] ?? null;
            if ($after !== null) {
                if (isset($seen[(string) $after]) || count($seen) >= 99) {
                    throw new \RuntimeException('HubSpot-Paginierung konnte nicht abgeschlossen werden.');
                }
                $seen[(string) $after] = true;
                $body['after'] = (string) $after;
            }
        } while ($after !== null);
        uasort($contacts, static function (array $a, array $b): int {
            $date = static function (array $p): int {
                $value = (string) ($p['gutscheingultigkeit'] ?? '');
                if (ctype_digit((string) $value) && strlen((string) $value) >= 12) {
                    return (int) ((int) $value / 1000);
                }
                return $value !== '' ? (strtotime($value) ?: PHP_INT_MAX) : PHP_INT_MAX;
            };
            return ($date($a) <=> $date($b)) ?: strnatcmp($a['pool_nummer'] ?? '', $b['pool_nummer'] ?? '');
        });
        return $contacts;
    }

    public function contact(string $id, array $properties): array
    {
        if (!ctype_digit($id)) {
            throw new \InvalidArgumentException('Ungültige Kontakt-ID.');
        }
        return $this->request('GET', '/crm/v3/objects/contacts/'.$id.'?'.http_build_query(['properties' => implode(',', $properties)]));
    }

    public function coach(string $email): array
    {
        $result = $this->request('POST', '/crm/v3/objects/contacts/search', [
            'filterGroups' => [['filters' => [['propertyName' => 'email', 'operator' => 'EQ', 'value' => $email]]]],
            'properties' => ['email'], 'limit' => 2,
        ]);
        $matches = $result['results'] ?? [];
        if (count($matches) !== 1 || strcasecmp($matches[0]['properties']['email'] ?? '', $email) !== 0) {
            throw new \DomainException('Die Coach-E-Mail konnte in HubSpot nicht eindeutig zugeordnet werden. Bitte das Team kontaktieren.');
        }
        return $matches[0];
    }

    /** Ein PATCH schreibt Status und Coach-Werte gemeinsam, damit Workflows sie konsistent sehen. */
    public function updatePool(string $id, array $properties): void
    {
        if (!ctype_digit($id) || array_diff(array_keys($properties), ['pool_status', 'pool_interesse_von', 'pool_freigegeben_fuer'])) {
            throw new \InvalidArgumentException('Ungültige Pool-Aktualisierung.');
        }
        $this->request('PATCH', '/crm/v3/objects/contacts/'.$id, ['properties' => $properties]);
    }

    public function createTask(string $contactId, string $coachId, string $email, string $number): string
    {
        $associations = [];
        foreach (array_unique([$contactId, $coachId]) as $id) {
            $associations[] = ['to' => ['id' => $id], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 204]]];
        }
        $task = $this->request('POST', '/crm/v3/objects/tasks', [
            'properties' => [
                'hs_timestamp' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 weekday')),
                'hs_task_subject' => 'Coach-Pool: Interesse an Anfrage '.$number,
                'hs_task_body' => htmlspecialchars('Coach: '.$email.'. Bitte Passung prüfen und anschließend manuell freigeben. Es wurde keine Zusage versendet.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'hs_task_status' => 'NOT_STARTED', 'hs_task_priority' => 'MEDIUM',
                'hs_task_type' => 'TODO',
            ], 'associations' => $associations,
        ]);
        if (empty($task['id'])) {
            throw new \RuntimeException('HubSpot hat keine Aufgaben-ID geliefert.');
        }
        return (string) $task['id'];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if (trim($this->token) === '') {
            throw new \RuntimeException('HubSpot-Zugang fehlt.');
        }
        $options = ['auth_bearer' => $this->token, 'timeout' => 15, 'max_duration' => 25, 'max_redirects' => 0];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $this->http->request($method, 'https://api.hubapi.com'.$path, $options);
        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300) {
            // Never include contact bodies or credentials in errors/logs.
            throw new \RuntimeException('HubSpot-Anfrage fehlgeschlagen (HTTP '.$code.').');
        }
        return $response->toArray(false);
    }
}
