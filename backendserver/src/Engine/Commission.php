<?php
declare(strict_types=1);

namespace LifeOS\Engine;

use LifeOS\DB;
use LifeOS\HttpError;

/**
 * Commission engine. Processes one payment-verified activation exactly once:
 * cashback, direct referral, generation bonus, matching bills (enrollment + binary,
 * one selected payout), daily cap, rank pool. All inside one transaction.
 */
final class Commission
{
    /**
     * Marks a PENDING activation SUCCESS and distributes commissions.
     * Idempotent: an already-processed activation is a no-op.
     */
    public static function processActivation(int $activationId): void
    {
        DB::tx(function () use ($activationId) {
            $act = DB::one('SELECT * FROM lo_activations WHERE id = ? FOR UPDATE', [$activationId]);
            if (!$act) throw new HttpError('Activation not found', 404);
            if ($act['payment_status'] !== 'SUCCESS') throw new HttpError('Payment not successful', 422);
            if ((int) $act['processed'] === 1) return; // duplicate gateway notification ignored

            $userId = (int) $act['user_id'];
            $tree = DB::one('SELECT * FROM lo_tree WHERE user_id = ? FOR UPDATE', [$userId]);
            if (!$tree) throw new HttpError('User tree node missing', 500);

            // 1) Create the qualifying sale (one per activation).
            $saleId = DB::insert(
                'INSERT INTO lo_sales (sale_code, activation_id, user_id) VALUES (?, ?, ?)',
                [self::code('SALE'), $activationId, $userId]
            );

            // 2) Activate the member.
            DB::run('UPDATE lo_tree SET is_active = 1 WHERE user_id = ?', [$userId]);

            // 3) Service Cashback ৳100 — separate ledger, not withdrawable.
            self::ledger('CASHBACK', $userId, Plan::CASHBACK, $activationId, $saleId, null, null, 'Service cashback (service-use only)');

            // 4) Direct Referral ৳200 — direct sponsor only.
            $sponsorId = $tree['sponsor_id'] !== null ? (int) $tree['sponsor_id'] : null;
            if ($sponsorId !== null) {
                self::ledger('REFERRAL', $sponsorId, Plan::REFERRAL, $activationId, $saleId, null, null, 'Direct referral');
            } else {
                self::pool('REFERRAL_UNPAID', Plan::REFERRAL);
            }

            // 5) Generation Bonus ৳200 — generations 1..10 up the sponsor chain.
            $upline = Placement::sponsorUpline($userId, 10);
            foreach (Plan::GENERATION as $level => $amount) {
                $target = $upline[$level - 1] ?? null;
                if ($target !== null) {
                    self::ledger('GENERATION', $target, (float) $amount, $activationId, $saleId, null, $level, "Generation $level bonus");
                } else {
                    self::pool('GENERATION_UNPAID', (float) $amount);
                }
            }

            // 6) Rank & Reward pool ৳200 — accumulated, distributed by the rank engine.
            self::pool('RANK', Plan::RANK_POOL);

            // 7) Matching: propagate volume through both trees, build bills, pay selected.
            self::propagateBinary($userId, $saleId, $activationId, $sponsorId);
            self::propagateEnrollment($userId, $saleId, $activationId, $sponsorId);

            DB::run('UPDATE lo_activations SET processed = 1, processed_at = NOW() WHERE id = ?', [$activationId]);
        });
    }

    /** Binary tree: add 1 BV-carry up the placement chain; each upline may match. */
    private static function propagateBinary(int $userId, int $saleId, int $activationId, ?int $sponsorId): void
    {
        foreach (Placement::binaryUpline($userId) as $step) {
            $up = DB::one('SELECT * FROM lo_tree WHERE user_id = ? FOR UPDATE', [$step['user_id']]);
            if (!$up) continue;
            $col = $step['side'] === 'L' ? 'left_carry' : 'right_carry';
            DB::run("UPDATE lo_tree SET $col = $col + 1 WHERE user_id = ?", [$step['user_id']]);

            $left = (float) $up['left_carry'] + ($step['side'] === 'L' ? 1 : 0);
            $right = (float) $up['right_carry'] + ($step['side'] === 'R' ? 1 : 0);
            $pairs = (int) min($left, $right);
            if ($pairs < 1 || !(int) $up['is_active']) continue;

            // Consume matched volume — consumed volume is never reused.
            DB::run(
                'UPDATE lo_tree SET left_carry = left_carry - ?, right_carry = right_carry - ?, left_consumed = left_consumed + ?, right_consumed = right_consumed + ? WHERE user_id = ?',
                [$pairs, $pairs, $pairs, $pairs, $step['user_id']]
            );
            for ($i = 0; $i < $pairs; $i++) {
                self::matchingEvent($step['user_id'], $saleId, $activationId, $sponsorId, 'BINARY');
            }
        }
    }

    /** Enrollment tree: add carry per (upline, direct-leg) up the sponsor chain. */
    private static function propagateEnrollment(int $userId, int $saleId, int $activationId, ?int $sponsorId): void
    {
        $legUser = $userId;
        $cur = $sponsorId;
        $depth = 500;
        while ($cur !== null && $depth-- > 0) {
            DB::run(
                'INSERT INTO lo_enroll_legs (upline_id, leg_user_id, carry) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE carry = carry + 1',
                [$cur, $legUser]
            );
            $up = DB::one('SELECT t.is_active, t.sponsor_id FROM lo_tree t WHERE t.user_id = ? FOR UPDATE', [$cur]);
            if (!$up) break;

            // Match across this upline's legs: min carry over legs with carry > 0, need >= 2 legs.
            $legs = DB::all('SELECT leg_user_id, carry FROM lo_enroll_legs WHERE upline_id = ? AND carry > 0 FOR UPDATE', [$cur]);
            if (count($legs) >= 2 && (int) $up['is_active']) {
                $pairs = min(array_column($legs, 'carry'));
                for ($i = 0; $i < (int) $pairs; $i++) {
                    foreach ($legs as $leg) {
                        DB::run('UPDATE lo_enroll_legs SET carry = carry - 1, consumed = consumed + 1 WHERE upline_id = ? AND leg_user_id = ?', [$cur, $leg['leg_user_id']]);
                    }
                    self::matchingEvent($cur, $saleId, $activationId, $sponsorId, 'ENROLLMENT');
                }
            }
            $legUser = $cur;
            $cur = $up['sponsor_id'] !== null ? (int) $up['sponsor_id'] : null;
        }
    }

    /**
     * One matching event for a beneficiary: both bills are calculated, the larger
     * eligible bill is selected, ONE payout is made — subject to the sale's 11-event
     * counter, the ৳300 reserve hard limit, and the ৳5,000 daily cap.
     */
    private static function matchingEvent(int $beneficiaryId, int $saleId, int $activationId, ?int $sponsorId, string $billType): void
    {
        if (DB::val("SELECT v FROM lo_settings WHERE k = 'matching_paused'") === '1') return;

        $sale = DB::one('SELECT * FROM lo_sales WHERE id = ? FOR UPDATE', [$saleId]);
        if (!$sale || $sale['status'] !== 'OPEN') return;
        if ((int) $sale['matching_count'] >= Plan::MAX_MATCHING_EVENTS) {
            DB::run("UPDATE lo_sales SET status = 'EXHAUSTED' WHERE id = ?", [$saleId]);
            return;
        }

        $eventNo = (int) $sale['matching_count'] + 1;
        $amount = Plan::matchingAmount($eventNo);

        // Reserve hard limit: never pay more than what remains of the ৳300 reserve.
        $remaining = (float) $sale['matching_reserve'] - (float) $sale['matching_paid'];
        if ($amount > $remaining) $amount = $remaining;
        if ($amount <= 0) {
            DB::run("UPDATE lo_sales SET status = 'EXHAUSTED' WHERE id = ?", [$saleId]);
            return;
        }

        // Both bills are calculation results for the same event; one is selected.
        DB::run('INSERT INTO lo_matching_bills (sale_id, beneficiary_id, bill_type, amount, status) VALUES (?, ?, ?, ?, ?)', [$saleId, $beneficiaryId, 'ENROLLMENT', $billType === 'ENROLLMENT' ? $amount : 0, $billType === 'ENROLLMENT' ? 'SELECTED' : 'CALCULATED']);
        DB::run('INSERT INTO lo_matching_bills (sale_id, beneficiary_id, bill_type, amount, status) VALUES (?, ?, ?, ?, ?)', [$saleId, $beneficiaryId, 'BINARY', $billType === 'BINARY' ? $amount : 0, $billType === 'BINARY' ? 'SELECTED' : 'CALCULATED']);

        // Daily cap ৳5,000 per active ID (matching only).
        $today = date('Y-m-d');
        DB::run('INSERT IGNORE INTO lo_daily_caps (user_id, cap_date) VALUES (?, ?)', [$beneficiaryId, $today]);
        $cap = DB::one('SELECT paid FROM lo_daily_caps WHERE user_id = ? AND cap_date = ? FOR UPDATE', [$beneficiaryId, $today]);
        $dailyBefore = (float) $cap['paid'];
        $capLimit = (float) (DB::val("SELECT v FROM lo_settings WHERE k = 'daily_matching_cap'") ?: 5000);
        $payout = min($amount, max(0.0, $capLimit - $dailyBefore));
        $flushed = $payout <= 0;

        $reserveAfter = (float) $sale['matching_paid'] + $amount;
        $eventCode = self::code('ME');
        DB::run(
            'INSERT INTO lo_matching_events
             (event_code, sale_id, activation_id, beneficiary_id, sponsor_id, event_number,
              enrollment_bill, binary_bill, selected_bill, selected_type, calculated_payout, actual_payout,
              reserve_before, reserve_after, daily_before, daily_after, payout_status, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $eventCode, $saleId, $activationId, $beneficiaryId, $sponsorId, $eventNo,
                $billType === 'ENROLLMENT' ? $amount : 0, $billType === 'BINARY' ? $amount : 0,
                $amount, $billType, $amount, $flushed ? 0 : $payout,
                (float) $sale['matching_paid'], $reserveAfter, $dailyBefore, $dailyBefore + ($flushed ? 0 : $payout),
                $flushed ? 'FLUSHED' : 'PAID', $flushed ? 'Daily cap reached' : null,
            ]
        );

        // The sale's counter advances on every event, paid or flushed — never reset by date.
        DB::run('UPDATE lo_sales SET matching_count = matching_count + 1, matching_paid = matching_paid + ? WHERE id = ?', [$amount, $saleId]);
        DB::run('UPDATE lo_tree SET lifetime_matchings = lifetime_matchings + 1 WHERE user_id = ?', [$beneficiaryId]);

        if (!$flushed) {
            DB::run('UPDATE lo_daily_caps SET paid = paid + ? WHERE user_id = ? AND cap_date = ?', [$payout, $beneficiaryId, $today]);
            self::ledger('MATCHING', $beneficiaryId, $payout, $activationId, $saleId, null, null, "Matching #$eventNo ($billType)", $eventCode);
        } else {
            self::pool('MATCHING_BUFFER', $amount);
        }

        Rank::check($beneficiaryId);
    }

    private static function ledger(string $type, int $userId, float $amount, ?int $activationId, ?int $saleId, ?int $refId, ?int $level, string $note, ?string $code = null): void
    {
        DB::run(
            'INSERT INTO lo_ledger (payout_code, user_id, ledger_type, amount, activation_id, sale_id, ref_id, level, note) VALUES (?,?,?,?,?,?,?,?,?)',
            [$code ?? self::code('PAY'), $userId, $type, $amount, $activationId, $saleId, $refId, $level, $note]
        );
    }

    private static function pool(string $pool, float $amount): void
    {
        DB::run('UPDATE lo_pools SET balance = balance + ? WHERE pool = ?', [$amount, $pool]);
    }

    private static function code(string $prefix): string
    {
        return $prefix . '-' . strtoupper(bin2hex(random_bytes(8)));
    }
}
