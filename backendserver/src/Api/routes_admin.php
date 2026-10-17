<?php
declare(strict_types=1);

use LifeOS\Auth;
use LifeOS\DB;
use LifeOS\Http;
use LifeOS\HttpError;
use LifeOS\Engine\Commission;

/** Admin API. */

$router->post('/api/admin/login', function () {
    $b = Http::body();
    $a = DB::one('SELECT * FROM admin_users WHERE email = ?', [$b['email'] ?? '']);
    if (!$a || !password_verify((string) ($b['password'] ?? ''), (string) ($a['password'] ?? ''))) {
        throw new HttpError('Invalid credentials', 401);
    }
    Http::ok(['token' => Auth::issue('a', (int) $a['id']), 'admin' => ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email']]]);
});

$router->get('/api/admin/overview', function () {
    Auth::admin();
    Http::ok([
        'members' => (int) DB::val('SELECT COUNT(*) FROM users'),
        'active_members' => (int) DB::val('SELECT COUNT(*) FROM lo_tree WHERE is_active = 1'),
        'activations_pending' => (int) DB::val("SELECT COUNT(*) FROM lo_activations WHERE payment_status = 'PENDING'"),
        'matching_paid_total' => (float) DB::val("SELECT COALESCE(SUM(amount),0) FROM lo_ledger WHERE ledger_type = 'MATCHING' AND status = 'PAID'"),
        'pools' => DB::all('SELECT * FROM lo_pools'),
        'withdrawals_pending' => (int) DB::val("SELECT COUNT(*) FROM lo_withdrawals WHERE status = 'PENDING'"),
    ]);
});

$router->get('/api/admin/members', function () {
    Auth::admin();
    $q = '%' . ($_GET['q'] ?? '') . '%';
    Http::ok(DB::all(
        'SELECT u.id, u.life_id, u.phone, u.email, u.status, u.created_at, t.is_active, t.lifetime_matchings
         FROM users u LEFT JOIN lo_tree t ON t.user_id = u.id
         WHERE u.life_id LIKE ? OR u.phone LIKE ? ORDER BY u.id DESC LIMIT 200',
        [$q, $q]
    ));
});

$router->post('/api/admin/members/{id}/status', function ($p) {
    Auth::admin();
    $b = Http::body();
    if (!in_array($b['status'] ?? '', ['active', 'suspended', 'pending'], true)) throw new HttpError('Invalid status');
    DB::run('UPDATE users SET status = ? WHERE id = ?', [$b['status'], (int) $p['id']]);
    Http::ok();
});

// Payment gateway / manual confirmation: only SUCCESS triggers commissions.
$router->post('/api/admin/activations/{id}/confirm', function ($p) {
    Auth::admin();
    $b = Http::body();
    $status = $b['status'] ?? '';
    if (!in_array($status, ['SUCCESS', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) throw new HttpError('Invalid status');
    $ref = $b['payment_ref'] ?? null;
    DB::tx(function () use ($p, $status, $ref) {
        $act = DB::one('SELECT * FROM lo_activations WHERE id = ? FOR UPDATE', [(int) $p['id']]);
        if (!$act) throw new HttpError('Activation not found', 404);
        if ($act['payment_status'] === 'SUCCESS') return; // duplicate notification ignored
        if ($ref) {
            // Unique payment_ref: same gateway trx can never confirm twice.
            DB::run('UPDATE lo_activations SET payment_ref = ? WHERE id = ?', [$ref, (int) $p['id']]);
        }
        DB::run('UPDATE lo_activations SET payment_status = ? WHERE id = ?', [$status, (int) $p['id']]);
        if ($status === 'SUCCESS') Commission::processActivation((int) $p['id']);
    });
    Http::ok();
});

$router->get('/api/admin/withdrawals', function () {
    Auth::admin();
    Http::ok(DB::all(
        'SELECT w.*, u.life_id, u.phone FROM lo_withdrawals w JOIN users u ON u.id = w.user_id ORDER BY w.id DESC LIMIT 200'
    ));
});

$router->post('/api/admin/withdrawals/{id}/process', function ($p) {
    Auth::admin();
    $b = Http::body();
    if (!in_array($b['status'] ?? '', ['APPROVED', 'REJECTED'], true)) throw new HttpError('Invalid status');
    DB::run('UPDATE lo_withdrawals SET status = ?, admin_note = ?, processed_at = NOW() WHERE id = ? AND status = "PENDING"',
        [$b['status'], $b['note'] ?? null, (int) $p['id']]);
    Http::ok();
});

$router->post('/api/admin/settings', function ($p) {
    Auth::admin();
    $b = Http::body();
    foreach (['matching_paused', 'daily_matching_cap', 'withdraw_min', 'withdraw_charge_pct'] as $k) {
        if (isset($b[$k])) DB::run('INSERT INTO lo_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, (string) $b[$k]]);
    }
    Http::ok();
});

$router->get('/api/admin/matching-events', function () {
    Auth::admin();
    Http::ok(DB::all('SELECT * FROM lo_matching_events ORDER BY id DESC LIMIT 300'));
});

$router->post('/api/admin/adjustments', function () {
    Auth::admin();
    $b = Http::body();
    if (empty($b['user_id']) || !isset($b['amount'])) throw new HttpError('user_id and amount required');
    DB::run(
        'INSERT INTO lo_ledger (payout_code, user_id, ledger_type, amount, note) VALUES (?,?,?,?,?)',
        ['ADJ-' . strtoupper(bin2hex(random_bytes(8))), (int) $b['user_id'], 'ADJUSTMENT', (float) $b['amount'], $b['note'] ?? 'Admin adjustment']
    );
    Http::ok();
});
