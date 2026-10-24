<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Koboldsoft\CoachPoolBundle\Service\CoachPool;
use Koboldsoft\CoachPoolBundle\Service\HubSpotClient;
use Koboldsoft\CoachPoolBundle\Service\PrivateResponseSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
    ++$checks;
}
function fails(callable $call, string $message): void
{
    try { $call(); } catch (Throwable $e) { check(strpos($e->getMessage(), $message) !== false, 'Expected error: '.$message); return; }
    throw new RuntimeException('Expected exception: '.$message);
}
function jsonResponse(array $data): MockResponse
{
    return new MockResponse(json_encode($data), ['http_code' => 200, 'response_headers' => ['content-type: application/json']]);
}

$requests = [];
$http = new MockHttpClient(static function ($method, $url, $options) use (&$requests) {
    $body = json_decode($options['body'] ?? '{}', true);
    $requests[] = $body;
    if (count($requests) === 1) {
        return jsonResponse(['results' => [
            ['id' => '1', 'properties' => ['pool_status' => 'offen', 'pool_nummer' => '01', 'gutscheingultigkeit' => '2026-12-01', 'firstname' => 'SECRET', 'email' => 'private@example.org', 'weitere_informationen' => 'SECRET']],
            ['id' => '9', 'properties' => ['pool_status' => 'vergeben']],
        ], 'paging' => ['next' => ['after' => '100']]]);
    }
    return jsonResponse(['results' => [
        ['id' => '2', 'properties' => ['pool_status' => 'zurueck_im_pool', 'pool_nummer' => '02', 'gutscheingultigkeit' => '2026-10-20']],
        ['id' => '3', 'properties' => ['pool_status' => 'offen', 'pool_nummer' => '03']],
    ]]);
});
$client = new HubSpotClient($http, 'test-only-token');
$entries = $client->openContacts('', false);
check(array_keys($entries) === [2, 1, 3], 'sort by voucher expiry, missing last, closed excluded');
check(array_intersect_key($entries[1], array_flip(['firstname', 'email', 'weitere_informationen'])) === [], 'no default PII or raw text in view data');
check($requests[0]['filterGroups'][0]['filters'][0]['values'] === ['offen', 'zurueck_im_pool'], 'exact pool status filter');
check($requests[0]['limit'] === 100 && $requests[1]['after'] === '100', 'pagination');
check($requests[0]['sorts'] === [['propertyName' => 'gutscheingultigkeit', 'direction' => 'ASCENDING']], 'HubSpot receives requested ascending voucher sort');
check(in_array('pool_kurzbeschreibung', $requests[0]['properties'], true) && !in_array('weitere_informationen', $requests[0]['properties'], true), 'short description replaces raw form text');
fails(static fn () => $client->openContacts('weitere_informationen'), 'Beschreibungsfeld');

$failing = new HubSpotClient(new MockHttpClient(new MockResponse('SECRET', ['http_code' => 429])), 'token');
fails(static fn () => $failing->openContacts(), 'HTTP 429');
$mismatch = new HubSpotClient(new MockHttpClient(jsonResponse(['results' => []])), 'token');
fails(static fn () => $mismatch->coach('coach@example.org'), 'nicht eindeutig');

// Fake-DB und zustandsbehaftete HubSpot-Antworten: kein produktiver Schreibzugriff.
class Journal extends Connection
{
    public bool $access = true;
    public bool $lockAvailable = true;
    public array $teamMembers = [1, 2, 3];
    public array $rows = [];
    public array $assignments = [];
    public array $members = [
        1 => ['id' => 1, 'email' => 'coach@example.org', 'firstname' => 'Coach', 'lastname' => 'Eins'],
        2 => ['id' => 2, 'email' => 'coach2@example.org', 'firstname' => 'Coach', 'lastname' => 'Zwei'],
        3 => ['id' => 3, 'email' => 'coach3@example.org', 'firstname' => 'Coach', 'lastname' => 'Drei'],
    ];
    public function __construct() {}
    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        if (strpos($query, 'SELECT GET_LOCK') === 0) { return $this->lockAvailable ? 1 : 0; }
        if (strpos($query, 'SELECT RELEASE_LOCK') === 0) { return 1; }
        if (strpos($query, 'SELECT email') === 0) { return $this->members[$params[0]]['email'] ?? false; }
        if (strpos($query, 'FROM tl_coach_pool_assignment') !== false) { return isset($this->assignments[$params[0]]) ? 1 : false; }
        return $this->access && in_array($params[0], $this->teamMembers, true) && $params[1] === 2 ? 1 : false;
    }
    public function fetchAssociative(string $query, array $params = [], array $types = [])
    {
        if (strpos($query, 'FROM tl_coach_pool_interest') !== false) { return $this->rows[$params[0].':'.$params[1]] ?? false; }
        foreach ($this->members as $member) { if ($member['email'] === $params[0]) { return $member; } }
        return false;
    }
    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        return array_values(array_filter($this->members, static function ($member) use ($params) { return $member['email'] === $params[0]; }));
    }
    public function insert($table, array $data, array $types = [])
    {
        if ($table === 'tl_coach_pool_assignment') { $this->assignments[$data['contact_id']] = $data; return 1; }
        $this->rows[$data['member_id'].':'.$data['contact_id']] = $data;
        return 1;
    }
    public function update($table, array $data, array $criteria, array $types = [])
    {
        if ($table === 'tl_coach_pool_assignment') {
            if (isset($this->assignments[$criteria['contact_id']])) { $this->assignments[$criteria['contact_id']] = array_merge($this->assignments[$criteria['contact_id']], $data); }
            return 1;
        }
        $key = $criteria['member_id'].':'.$criteria['contact_id'];
        $this->rows[$key] = array_merge($this->rows[$key], $data);
        return 1;
    }
    public function delete($table, array $criteria, array $types = [])
    {
        unset($this->assignments[$criteria['contact_id']]); return 1;
    }
}
$tasks = [];
$patches = [];
$properties = ['pool_status' => 'offen', 'pool_nummer' => '07', 'pool_interesse_von' => '', 'pool_freigegeben_fuer' => ''];
$taskFailure = false;
$patchFailure = 0;
$coachMismatch = false;
$http = new MockHttpClient(static function ($method, $url, $options) use (&$tasks, &$patches, &$properties, &$taskFailure, &$patchFailure, &$coachMismatch) {
    $body = json_decode($options['body'] ?? '{}', true);
    if (strpos($url, '/contacts/search') !== false) {
        $email = $body['filterGroups'][0]['filters'][0]['value'] ?? null;
        if ($email !== null) {
            return jsonResponse(['results' => [['id' => (string) (100 + crc32($email)), 'properties' => ['email' => $coachMismatch ? 'different@example.org' : $email]]]]);
        }
        return jsonResponse(['results' => [['id' => '7', 'properties' => $properties + ['email' => 'CLIENT-SECRET@example.org']]]]);
    }
    if (strpos($url, '/contacts/7') !== false) {
        if ($method === 'PATCH') {
            $patches[] = $body['properties'];
            if ($patchFailure) { return new MockResponse('{}', ['http_code' => $patchFailure]); }
            $properties = array_merge($properties, $body['properties']);
        }
        return jsonResponse(['id' => '7', 'properties' => $properties]);
    }
    $tasks[] = $body;
    return $taskFailure ? new MockResponse('{}', ['http_code' => 503]) : jsonResponse(['id' => '123']);
});
$journal = new Journal();
$api = new HubSpotClient($http, 'test-token');
$pool = new CoachPool($api, $journal, new NullLogger());
$journal->access = false;
fails(static fn () => $pool->report(1, 2, '7'), 'nur Coaches');
check(count($tasks) === 0 && !$journal->rows && !$patches, 'unauthorized request has no side effects');
$journal->access = true;
$properties['pool_status'] = 'vergeben';
fails(static fn () => $pool->report(1, 2, '7'), 'nicht mehr');
$properties['pool_status'] = 'offen';
$coachMismatch = true;
fails(static fn () => $pool->report(1, 2, '7'), 'nicht eindeutig');
check(!$tasks && !$patches && !$journal->rows, 'email mismatch creates no task, PATCH or journal');
$coachMismatch = false;
$journal->lockAvailable = false;
fails(static fn () => $pool->report(1, 2, '7'), 'gerade bearbeitet');
$journal->lockAvailable = true;
$pool->report(1, 2, '7');
$pool->report(1, 2, '7');
check(count($tasks) === 1 && count($patches) === 1, 'duplicate interest is idempotent');
check($properties['pool_status'] === 'interesse_eingegangen' && $properties['pool_interesse_von'] === 'coach@example.org', 'interest saves status and authenticated email in HubSpot');
check($journal->rows['1:7']['status'] === 'received', 'persist confirmed delivery');
check(!array_key_exists('hubspot_owner_id', $tasks[0]['properties']), 'team task has no individual owner');
check($tasks[0]['associations'][0]['to']['id'] === '7' && count($tasks[0]['associations']) === 2, 'task linked to request and coach');
check($tasks[0]['associations'][0]['types'][0]['associationTypeId'] === 204, 'task association direction');
fails(static fn () => $pool->report(2, 2, '7', false), 'nicht mehr');
$pool->report(2, 2, '7');
check($properties['pool_interesse_von'] === 'coach@example.org;coach2@example.org', 'append a second coach without overwriting first');
check(count($tasks) === 2, 'one task per interested coach');
$taskFailure = true;
fails(static fn () => $pool->report(3, 2, '7'), 'nicht bestätigt');
fails(static fn () => $pool->report(3, 2, '7'), 'Übermittlung wird geprüft');
check(count($tasks) === 3 && $journal->rows['3:7']['status'] === 'sending', 'uncertain task delivery never automatically POSTs again');
$taskFailure = false;

// Keine Interesse-Adressen in der normalen Kartenansicht; nur freigeschaltete Prüfer sehen sie.
$cards = $pool->listing();
check(isset($cards[7]) && array_intersect_key($cards[7], array_flip(['pool_interesse_von', 'pool_freigegeben_fuer', 'email'])) === [], 'pending card remains visible without coach identities');
check($pool->listing('', false) === [], 'exact original status filter excludes pending cards');
fails(static fn () => $pool->reviewListing(2, 2, [1]), 'Berechtigung');
$review = $pool->reviewListing(1, 2, [1]);
check(count($review[7]['coaches']) === 3 && $review[7]['coaches'][0]['name'] === 'Coach Eins', 'review lists interested coaches with local names');
fails(static fn () => $pool->approve(2, 2, [1], '7', 'coach@example.org'), 'Berechtigung');
fails(static fn () => $pool->approve(1, 2, [1], '7', 'stranger@example.org'), 'kein aktuelles Interesse');
$journal->access = false;
fails(static fn () => $pool->approve(1, 2, [1], '7', 'coach@example.org'), 'Berechtigung');
$journal->access = true;
$journal->teamMembers = [1, 3];
fails(static fn () => $pool->approve(1, 2, [1], '7', 'coach2@example.org'), 'nicht eindeutig');
$journal->teamMembers = [1, 2, 3];
$beforeApproval = count($patches);
$pool->approve(1, 2, [1], '7', 'coach2@example.org');
check($patches[$beforeApproval] === ['pool_status' => 'freigegeben', 'pool_freigegeben_fuer' => 'coach2@example.org'], 'approval atomically writes status and selected coach');
check($journal->assignments[7]['status'] === 'approved', 'approval journal records confirmation');
$pool->approve(1, 2, [1], '7', 'coach2@example.org');
check(count($patches) === $beforeApproval + 1, 'duplicate approval never repeats PATCH/workflow');
fails(static fn () => $pool->approve(1, 2, [1], '7', 'coach@example.org'), 'anderen Coach');
check($pool->listing() === [], 'approved request disappears from coach cards');
check(count($tasks) === 3, 'approval creates no task or outgoing mail');

// Explizite Ablehnung und unklarer Timeout haben unterschiedliche Wiederholungsregeln.
$properties['pool_status'] = 'interesse_eingegangen';
$properties['pool_freigegeben_fuer'] = '';
$journal->assignments = [];
$patchFailure = 400;
fails(static fn () => $pool->approve(1, 2, [1], '7', 'coach2@example.org'), 'nicht bestätigt');
check(!$journal->assignments, 'explicit API rejection permits corrected attempt');
$patchFailure = 503;
fails(static fn () => $pool->approve(1, 2, [1], '7', 'coach2@example.org'), 'nicht bestätigt');
fails(static fn () => $pool->approve(1, 2, [1], '7', 'coach@example.org'), 'Freigabeversuch');
check($journal->assignments[7]['status'] === 'sending', 'uncertain approval prevents reassignment');

$presentation = \Koboldsoft\CoachPoolBundle\Service\PoolPresentation::forContact([
    'welche_forderung_mochtest_du_fur_dein_coaching_nutzen' => 'DRV', 'zielgruppe' => 'A;B', 'gutscheingultigkeit' => '1792454400000',
]);
check($presentation['badge']['class'] === 'drv' && $presentation['chips']['target'] === ['A', 'B'] && $presentation['expiry'] !== '', 'card formats funding, multiselect chips and millisecond dates');
fails(static fn () => CoachPool::emails('Coach Name'), 'ungültige Coach-Adressen');
check(CoachPool::emails('A@EXAMPLE.ORG;a@example.org') === ['a@example.org'], 'email list canonicalization avoids duplicates');

// Die echte HTML-Vorlage wird mit einem kleinen Contao-Template-Kontext gerendert.
class PoolTemplateHarness
{
    private array $data;
    public function __construct(array $data) { $this->data = $data; }
    public function __get($name) { return $this->data[$name] ?? null; }
    public function extend($name): void {}
    public function block($name): void {}
    public function endblock(): void {}
    public function render(): string
    {
        ob_start();
        include dirname(__DIR__, 2).'/src/Koboldsoft/CoachPoolBundle/coach-pool-bundle/src/Resources/contao/templates/mod_coach_pool.html5';
        return ob_get_clean();
    }
}
$display = ['pool_nummer' => '01', 'pool_status' => 'interesse_eingegangen', 'city' => 'Berlin',
    'coachingformat' => 'Online / Hybrid', 'coachingtyp' => 'Jobcoaching',
    'pool_kurzbeschreibung' => 'Berufliche Neuorientierung und Einstieg in die IT-Branche. <script>alert(1)</script>',
    'weitere_informationen' => 'RAW-FORM-SECRET', 'zeitraum_fur_start' => 'Sofort',
    'bevorzugter_kontaktweg' => 'Telefon', 'zielgruppe' => 'Quereinsteiger;Weiterbildung finden',
    'branche__kategorie' => 'IT, Software & Tech', 'createdate' => '2026-10-15T12:00:00Z',
    'welche_forderung_mochtest_du_fur_dein_coaching_nutzen' => 'DRV',
    'coaches' => [['name' => 'Coach A', 'email' => 'coach@example.org', 'eligible' => true]],
];
$display['presentation'] = \Koboldsoft\CoachPoolBundle\Service\PoolPresentation::forContact($display);
$view = ['authorized' => true, 'reviewMode' => false, 'entries' => [7 => $display], 'message' => '', 'error' => '',
    'interests' => [], 'token' => 'test-only', 'formId' => 'coach_pool_test'];
$cardHtml = (new PoolTemplateHarness($view))->render();
check(strpos($cardHtml, '&lt;script&gt;') !== false && strpos($cardHtml, '<script>') === false, 'description is HTML-escaped');
check(strpos($cardHtml, 'RAW-FORM-SECRET') === false && strpos($cardHtml, 'coach@example.org') === false, 'cards expose neither raw form text nor coach identities');
$reviewHtml = (new PoolTemplateHarness(array_merge($view, ['reviewMode' => true])))->render();
check(strpos($reviewHtml, 'Ausgewählten Coach freigeben') !== false && strpos($reviewHtml, 'Coach A') !== false, 'review template offers interested coach selection and approval');
$deniedHtml = (new PoolTemplateHarness(array_merge($view, ['authorized' => false, 'reviewMode' => true])))->render();
check(strpos($deniedHtml, 'Coach A') === false && strpos($deniedHtml, 'coach@example.org') === false && strpos($deniedHtml, 'Berufliche Neuorientierung') === false, 'unauthorized rendering leaks no review data');
$fundingNo = \Koboldsoft\CoachPoolBundle\Service\PoolPresentation::forContact([
    'hast_du_schon_einen_avgs_gutschein_oder_brauchst_du_unterstutzung_beim_antrag_' => 'Ich habe noch keinen Gutschein',
]);
check($fundingNo['badge']['class'] === 'neutral', 'negative voucher answer is never presented as voucher present');

// Optionaler visueller Prüfstand mit erfundenen Daten, niemals mit produktiven Kontaktwerten.
if (in_array('--preview', $argv, true)) {
    $display['pool_kurzbeschreibung'] = 'Die Person sucht ein Berufsorientierungscoaching und möchte als Quereinsteiger in die IT-Branche wechseln. Branchenkenntnisse und Unterstützung bei Qualifizierungsmöglichkeiten sind gewünscht.';
    $view['entries'] = [];
    foreach (['Berlin', 'Zwingenberg', 'Berlin'] as $i => $city) {
        $item = $display;
        $item['pool_nummer'] = sprintf('%02d', $i + 1);
        $item['city'] = $city;
        if ($i === 1) { $item['zielgruppe'] = 'International Community;Menschen mit Migrationshintergrund'; }
        $item['presentation'] = \Koboldsoft\CoachPoolBundle\Service\PoolPresentation::forContact($item);
        $view['entries'][$i + 1] = $item;
    }
    $css = file_get_contents(dirname(__DIR__, 2).'/files/themes/webapp/css/coach-pool.css');
    $html = '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Coach-Pool Vorschau</title><style>body{font-family:Arial,sans-serif;background:#f5f6f8;margin:24px auto;max-width:1240px;padding:0 16px}'.$css.'</style><h1>Coach-Pool</h1>';
    $html .= (new PoolTemplateHarness($view))->render();
    $html .= (new PoolTemplateHarness(array_merge($view, ['reviewMode' => true])))->render();
    file_put_contents('/tmp/coach-pool-vorschau.html', $html.'</html>');
}

// The subscriber must override existing public cache headers.
$request = new Request();
$request->attributes->set('_coach_pool_private', true);
$response = new Response();
$response->setPublic()->setSharedMaxAge(3600);
$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true) { return new Response(); }
};
(new PrivateResponseSubscriber())->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MASTER_REQUEST, $response));
check($response->headers->hasCacheControlDirective('private') && $response->headers->hasCacheControlDirective('no-store'), 'personalized responses cannot be shared-cached');

// Regression: error reporting must work with an inlined private logger service.
class PoolTestLogger extends \Psr\Log\AbstractLogger
{
    public static array $records = [];
    public function log($level, $message, array $context = [])
    {
        self::$records[] = [$message, $context];
    }
}
$container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
$container->register('http_client', MockHttpClient::class);
$container->register('database_connection', Connection::class)->setSynthetic(true);
$container->register('logger', PoolTestLogger::class)->setPublic(false);
(new \Koboldsoft\CoachPoolBundle\DependencyInjection\CoachPoolExtension())->load([], $container);
$container->compile();
$container->set('database_connection', $journal);
check(!$container->has('logger'), 'logger is private or inlined in compiled production container');
$compiledPool = $container->get(CoachPool::class);
$compiledPool->logLoadFailure(new RuntimeException('HubSpot-Anfrage fehlgeschlagen (HTTP 401).'));
check(PoolTestLogger::$records[0][1]['hubspot_http_status'] === 401, 'injected logger records HubSpot status without secondary exception');
$compiledPool->logLoadFailure(new RuntimeException('SECRET private@example.org'));
check(strpos(json_encode(PoolTestLogger::$records), 'SECRET') === false && strpos(json_encode(PoolTestLogger::$records), 'private@example.org') === false, 'error reporting does not expose exception payloads');
echo 'OK: '.$checks." checks passed\n";
