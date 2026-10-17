<?php
declare(strict_types=1);

/**
 * Life OS backend end-to-end tests.
 * Start DB + API server first:
 *   php -S 127.0.0.1:8090 -t backendserver backendserver/index.php
 * Usage: php backendserver/tests/run_tests.php
 */

const BASE = 'http://127.0.0.1:8090';
const MASTER_KEY = 'test-master-key-0123456789abcdef0123456789abcdef';

$failures = [];
$tests = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $failures, $tests;
    $tests++;
    if (!$cond) { $failures[] = "$name: $detail"; echo "FAIL  $name  $detail\n"; }
    else echo "ok    $name\n";
}

function api(string $method, string $path, ?array $body = null, ?string $token = null): array {
    $ch = curl_init(BASE . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $raw, true) ?? [], 'raw' => (string) $raw];
}

function master(string $method, string $path, ?array $body = null, ?string $key = MASTER_KEY, ?int $tsOverride = null): array {
    $raw = $body === null ? '' : json_encode($body);
    $ts = $tsOverride ?? time();
    $sig = hash_hmac('sha256', "$ts.$method.$path." . hash('sha256', $raw), $key);
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Master-Ts: $ts", "X-Master-Sig: $sig"],
        CURLOPT_POSTFIELDS => $body === null ? '' : $raw,
    ]);
    $rawOut = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $rawOut, true) ?? []];
}

require __DIR__ . '/../src/bootstrap.php';
use LifeOS\Engine\Plan;

// Plan constants: 11 events, 150 halving, total ≈ 299.854.
$total = 0.0;
for ($i = 1; $i <= 11; $i++) $total += Plan::matchingAmount($i);
check('Plan: 11 matching events total 299.854', abs($total - 299.854) < 0.001, 'got ' . round($total, 6));
check('Plan: event 1 = 150, event 2 = 75', Plan::matchingAmount(1) === 150.0 && Plan::matchingAmount(2) === 75.0);
check('Plan: event 12 = 0 (hard stop)', Plan::matchingAmount(12) === 0.0);

// ---- Register root member A (no sponsor) ----
$r = api('POST', '/api/member/register', ['phone' => '01700000001', 'pin' => '1234', 'name' => 'Root A', 'accepted_terms' => 1]);
check('register A', $r['status'] === 200 && isset($r['json']['data']['token']), $r['raw']);
$tokenA = $r['json']['data']['token'] ?? '';

// Wrong PIN rejected
$r = api('POST', '/api/member/login', ['username' => '01700000001', 'password' => '9999']);
check('login wrong pin rejected', $r['status'] === 401, (string) $r['status']);

$r = api('POST', '/api/member/login', ['username' => '01700000001', 'password' => '1234']);
check('login A', $r['status'] === 200, $r['raw']);

// Duplicate phone rejected
$r = api('POST', '/api/member/register', ['phone' => '01700000001', 'pin' => '1234', 'name' => 'Dup']);
check('duplicate phone rejected', $r['status'] === 409, (string) $r['status']);

// ---- Register B under A, C and D under B ----
function registerAndActivate(string $phone, string $refCode, string $adminToken): array {
    $r = api('POST', '/api/member/register', ['phone' => $phone, 'pin' => '1234', 'name' => 'M ' . $phone, 'referral_code' => $refCode, 'accepted_terms' => 1]);
    if ($r['status'] !== 200) throw new RuntimeException("register $phone failed: " . $r['raw']);
    $tok = $r['json']['data']['token'];
    $me = api('GET', '/api/member/me', null, $tok)['json']['data'];
    $act = api('POST', '/api/member/activations', [], $tok)['json']['data'];
    api('POST', "/api/admin/activations/{$act['activation_id']}/confirm", ['status' => 'SUCCESS', 'payment_ref' => 'TRX-' . $phone], $adminToken);
    return ['id' => (int) $me['id'], 'token' => $tok, 'life_id' => $me['life_id'], 'referral_code' => $me['referral_code']];
}

$r = api('POST', '/api/admin/login', ['email' => 'admin@lifeos.test', 'password' => 'admin123']);
check('admin login', $r['status'] === 200 && isset($r['json']['data']['token']), $r['raw']);
$adminToken = $r['json']['data']['token'];

$meA = api('GET', '/api/member/me', null, $tokenA)['json']['data'];
$B = registerAndActivate('01700000002', $meA['referral_code'], $adminToken);
$C = registerAndActivate('01700000003', $B['referral_code'], $adminToken);
$D = registerAndActivate('01700000004', $B['referral_code'], $adminToken);

// ---- Wallet math after 4 activations (wave 1) ----
// Sale B: A gets REFERRAL 200 + GEN1 50. Sale C: B REFERRAL 200 + GEN1 50, A GEN2 30.
// Sale D: B REFERRAL 200 + GEN1 50, A GEN2 30.
// Matching on sale D (binary first): BINARY pair at B ev1 150, at A ev2 75; ENROLLMENT pair at B ev3 37.5.
// A: 200 + 110 + 75 = 385. B: 400 + 100 + 187.5 = 687.5. C: cashback only.
$wa = api('GET', '/api/member/wallet', null, $tokenA)['json']['data'];
$wb = api('GET', '/api/member/wallet', null, $B['token'])['json']['data'];
check('A wallet total 310', abs(($wa['withdrawable'] ?? 0) - 310.0) < 0.01, 'got ' . ($wa['withdrawable'] ?? 'null'));
check('B wallet total 725', abs(($wb['withdrawable'] ?? 0) - 725.0) < 0.01, 'got ' . ($wb['withdrawable'] ?? 'null'));
check('A referral 200', abs(($wa['by_type']['REFERRAL'] ?? 0) - 200.0) < 0.01, 'got ' . ($wa['by_type']['REFERRAL'] ?? 'none'));
check('B referral 400', abs(($wb['by_type']['REFERRAL'] ?? 0) - 400.0) < 0.01, 'got ' . ($wb['by_type']['REFERRAL'] ?? 'none'));
check('A generation 110', abs(($wa['by_type']['GENERATION'] ?? 0) - 110.0) < 0.01, 'got ' . ($wa['by_type']['GENERATION'] ?? 'none'));
check('B generation 100', abs(($wb['by_type']['GENERATION'] ?? 0) - 100.0) < 0.01, 'got ' . ($wb['by_type']['GENERATION'] ?? 'none'));
check('B matching 225', abs(($wb['by_type']['MATCHING'] ?? 0) - 225.0) < 0.01, 'got ' . ($wb['by_type']['MATCHING'] ?? 'none'));
check('A matching 0 (single-leg subtree never matches)', abs(($wa['by_type']['MATCHING'] ?? 0) - 0.0) < 0.01, 'got ' . ($wa['by_type']['MATCHING'] ?? 'none'));
$wc = api('GET', '/api/member/wallet', null, $C['token'])['json']['data'];
check('C withdrawable 0, cashback 100 service-only', abs($wc['withdrawable']) < 0.01 && abs($wc['cashback_service_only'] - 100.0) < 0.01, 'got wd=' . $wc['withdrawable'] . ' cb=' . $wc['cashback_service_only']);

// ---- Matching events ----
$mev = api('GET', '/api/member/matching', null, $B['token'])['json']['data'];
$paid = array_values(array_filter($mev, fn ($e) => $e['payout_status'] === 'PAID'));
check('B has 2 paid matching events', count($paid) === 2, 'got ' . count($paid));
$ranks = array_values(array_map(fn ($e) => (float) $e['calculated_payout'], $paid));
sort($ranks);
check('B events are 150 and 75 (halving, sale counter)', $ranks === [75.0, 150.0], json_encode($ranks));
$amev = api('GET', '/api/member/matching', null, $tokenA)['json']['data'];
$aev = array_values(array_filter($amev, fn ($e) => $e['payout_status'] === 'PAID'));
check('A has no matching events in wave 1 (single-leg subtree)', count($aev) === 0, 'got ' . count($aev));
$types = array_map(fn ($e) => $e['selected_type'] ?? null, $paid);
$nums = array_map(fn ($e) => (int) ($e['event_number'] ?? 0), $paid);
sort($nums);
check('B events numbered 1,2 on one sale, different bill types', $nums === [1, 2] && count(array_unique($types)) === 2, json_encode([$nums, $types]));

// ---- Rank ----
$rr = api('GET', '/api/member/rank', null, $B['token'])['json']['data'];
check('B rank Member at 2 matchings', ($rr['current']['name'] ?? '') === 'Member', json_encode($rr['current'] ?? null));
check('B lifetime matchings = 2', ($rr['lifetime_matchings'] ?? 0) === 2, 'got ' . ($rr['lifetime_matchings'] ?? 0));
$rr = api('GET', '/api/member/rank', null, $tokenA)['json']['data'];
check('A has no rank row yet (never earned a matching)', ($rr['current'] ?? []) === null || $rr['current'] === null, json_encode($rr['current'] ?? null));

// ---- Enrollment tree ----
$et = api('GET', '/api/member/tree/enrollment', null, $B['token'])['json']['data'];
check('B has 2 directs (C, D)', count($et['directs'] ?? []) === 2, 'got ' . count($et['directs'] ?? []));
$bt = api('GET', '/api/member/tree/binary', null, $B['token'])['json']['data'];
check('B binary has C left and D right', ($bt['left']['life_id'] ?? '') === $C['life_id'] && ($bt['right']['life_id'] ?? '') === $D['life_id'], json_encode([$bt['left']['life_id'] ?? null, $bt['right']['life_id'] ?? null]));

// ---- Daily matching cap: force C's used cap to 4999, then a match pays 1 taka ----
$pdo = new PDO('mysql:host=127.0.0.1;dbname=lifeos', 'lifeos_user', 'testpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$today = date('Y-m-d');
$pdo->prepare('INSERT INTO lo_daily_caps (user_id, cap_date, paid) VALUES (?, ?, 4999) ON DUPLICATE KEY UPDATE paid = 4999')->execute([$C['id'], $today]);

$G = registerAndActivate('01700000007', $C['referral_code'], $adminToken);
$H = registerAndActivate('01700000008', $C['referral_code'], $adminToken);
$cev = api('GET', '/api/member/matching', null, $C['token'])['json']['data'];
$cPaid = array_values(array_filter($cev, fn ($e) => $e['payout_status'] === 'PAID'));
check('C paid event capped at 1 taka (5000 cap hit)', abs(($cPaid[0]['actual_payout'] ?? 0) - 1.0) < 0.01, json_encode($cPaid[0] ?? []));
$buf = (float) $pdo->query("SELECT balance FROM lo_pools WHERE pool = 'MATCHING_BUFFER'")->fetchColumn();
check('flushed amount goes to MATCHING_BUFFER', $buf > 0, 'got ' . $buf);

// Second match under C with cap fully exhausted → FLUSHED
$I = registerAndActivate('01700000009', $C['referral_code'], $adminToken);
$J = registerAndActivate('01700000010', $C['referral_code'], $adminToken);
$cev = api('GET', '/api/member/matching', null, $C['token'])['json']['data'];
$cFlush = array_values(array_filter($cev, fn ($e) => $e['payout_status'] === 'FLUSHED'));
check('C flushed event when cap exhausted', count($cFlush) >= 1, 'got ' . count($cFlush));

// ---- Withdrawals ----
$waBefore = api('GET', '/api/member/wallet', null, $tokenA)['json']['data'];
$r = api('POST', '/api/member/withdrawals', ['amount' => 400, 'method' => 'bkash', 'account_number' => '01700000002'], $B['token']);
check('withdrawal below minimum 500 rejected', $r['status'] === 400, (string) $r['status']);
$r = api('POST', '/api/member/withdrawals', ['amount' => 500, 'method' => 'bkash', 'account_number' => '01700000002'], $B['token']);
check('withdrawal 500 created PENDING', $r['status'] === 200 && ($r['json']['data']['status'] ?? '') === 'PENDING', $r['raw']);
$wdId = $r['json']['data']['withdrawal_id'] ?? 0;
$r = api('GET', '/api/admin/withdrawals', null, $adminToken);
check('admin sees pending withdrawal', $r['status'] === 200 && count($r['json']['data'] ?? []) >= 1, 'got ' . count($r['json']['data'] ?? []));
$r = api('POST', "/api/admin/withdrawals/$wdId/process", ['status' => 'APPROVED', 'note' => 'paid'], $adminToken);
check('admin approves withdrawal', $r['status'] === 200, $r['raw']);
$wb2 = api('GET', '/api/member/wallet', null, $B['token'])['json']['data'];
check('wallet reflects 500 withdrawn', abs(($wb2['withdrawn'] ?? 0) - 500.0) < 0.01, 'got ' . ($wb2['withdrawn'] ?? 'none'));

// ---- Access boundaries ----
$r = api('GET', '/api/admin/overview', null, $tokenA);
check('member token rejected on admin API', in_array($r['status'], [401, 403], true), (string) $r['status']);
$r = api('GET', '/api/member/me');
check('no token rejected', $r['status'] === 401, (string) $r['status']);
$r = api('GET', '/api/admin/overview', null, 'bogus.token');
check('bad admin token rejected', in_array($r['status'], [401, 403], true), (string) $r['status']);

// ---- Idempotent activation confirm ----
$cntBefore = (int) $pdo->query('SELECT COUNT(*) FROM lo_ledger')->fetchColumn();
$act = api('GET', '/api/member/activations', null, $C['token'])['json']['data'];
$apiAct = $act[0]['id'] ?? 0;
$r = api('POST', "/api/admin/activations/$apiAct/confirm", ['status' => 'SUCCESS', 'payment_ref' => 'TRX-replay'], $adminToken);
$cntAfter = (int) $pdo->query('SELECT COUNT(*) FROM lo_ledger')->fetchColumn();
check('re-confirm does not double-pay', $cntAfter === $cntBefore, "before=$cntBefore after=$cntAfter");

// ---- Master Worker API ----
$r = master('GET', '/api/master/ping');
check('master ping ok', $r['status'] === 200 && ($r['json']['ok'] ?? false), json_encode($r['json']));
$r = master('GET', '/api/master/ping', null, 'wrong-key');
check('master wrong key rejected', $r['status'] === 401, (string) $r['status']);
$r = master('GET', '/api/master/ping', null, MASTER_KEY, time() - 3600);
check('master stale timestamp rejected', $r['status'] === 401, (string) $r['status']);

$r = master('GET', '/api/master/db/tables');
$tables = array_column($r['json']['data'] ?? [], 'table');
check('master db/tables lists lifeos tables', $r['status'] === 200 && in_array('lo_ledger', $tables, true), json_encode($tables));
$r = master('POST', '/api/master/db/query', ['sql' => 'SELECT COUNT(*) AS c FROM lo_ledger']);
check('master db/query select works', $r['status'] === 200, json_encode($r['json']));
$r = master('POST', '/api/master/db/query', ['sql' => 'DELETE FROM lo_ledger']);
check('master db/query blocks unconfirmed writes', $r['status'] === 403, (string) $r['status']);

// files round-trip (content is base64)
master('POST', '/api/master/files/write', ['path' => 'tests/tmp-master.txt', 'content' => base64_encode('hello master')]);
$r = master('GET', '/api/master/files/read?path=' . rawurlencode('tests/tmp-master.txt'));
check('master file write+read', base64_decode((string) ($r['json']['data']['content'] ?? '')) === 'hello master', json_encode($r['json']));
$r = master('POST', '/api/master/files/delete', ['path' => 'tests/tmp-master.txt']);
check('master file delete', $r['status'] === 200, json_encode($r['json']));

// deploy with backup + rollback restores overwritten file
master('POST', '/api/master/files/write', ['path' => 'tests/deploy-a.php', 'content' => base64_encode('<?php // old content')]);
$r = master('POST', '/api/master/deploy', ['files' => [
    ['path' => 'tests/deploy-a.php', 'content' => base64_encode('<?php // deploy test a')],
    ['path' => 'tests/deploy-b.php', 'content' => base64_encode('<?php // deploy test b')],
]]);
check('master deploy ok', $r['status'] === 200 && count($r['json']['data']['files'] ?? []) === 2, json_encode($r['json']));
$deployId = $r['json']['data']['deploy_id'] ?? '';
$r = master('POST', '/api/master/rollback', ['deploy_id' => $deployId]);
$restored = in_array('tests/deploy-a.php', $r['json']['data']['restored'] ?? [], true);
check('master rollback restores overwritten file', $r['status'] === 200 && $restored, json_encode($r['json']));
$r = master('GET', '/api/master/files/read?path=' . rawurlencode('tests/deploy-a.php'));
check('rollback restores old content', base64_decode((string) ($r['json']['data']['content'] ?? '')) === '<?php // old content', json_encode($r['json']));
master('POST', '/api/master/files/delete', ['path' => 'tests/deploy-a.php']);

$r = master('GET', '/api/master/audit');
check('master audit trail recorded', $r['status'] === 200 && count($r['json']['data'] ?? []) >= 3, 'rows=' . count($r['json']['data'] ?? []));

echo "\n{$tests} checks, " . count($failures) . " failures\n";
if ($failures) { echo implode("\n", $failures), "\n"; exit(1); }
