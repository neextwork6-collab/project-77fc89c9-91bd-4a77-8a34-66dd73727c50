# Life OS Backend Server (PHP + MySQL)

Self-contained REST API for the Life OS member panel. Deploy this folder to any
PHP 8.1+ host with MySQL (e.g. cPanel). No framework, no Composer.

## Setup

1. Copy `config.example.php` to `config.php` and fill in DB credentials,
   `app_secret` (64+ random chars) and `master_key` (64+ random chars, server-to-server only).
2. Create the MySQL database and user, then apply the schema:
   - `database/001_legacy_schema.sql` — legacy tables (old user data imports as-is)
   - `database/002_lifeos_engine.sql` — commission engine tables
   (or call `POST /api/master/db/migrate` after step 3)
3. Point the web root (or an Alias) at this folder; `index.php` + `.htaccess` route everything.

## Marketing plan constants (fixed by business plan)

Activation ৳1,000 = 1000 BV, split: Cashback ৳100 (service-only, not withdrawable),
Direct Referral ৳200 (direct sponsor only), Matching Reserve ৳300 (max payable
৳299.854 across max 11 events per sale: 150, 75, 37.5, … halving), Generation
Pool ৳200 (10 generations: 50/30/25/20/18/15/12/10/10/10), Rank Pool ৳200.
Daily matching cap ৳5,000 per active ID. Enrollment tree and binary placement
tree are separate; each matching calculates both bills but pays only the larger
one. Consumed volume is never reused; counters never reset on date change.

## API overview

- `POST /api/member/register|login`, `GET /api/member/me|wallet|ledger|tree/binary|tree/enrollment|activations|matching|rank|withdrawals`, `POST /api/member/activations|withdrawals`
- `POST /api/admin/login`, `GET /api/admin/overview|members|withdrawals|matching-events`, `POST /api/admin/activations/{id}/confirm` (only SUCCESS pays), `members/{id}/status`, `withdrawals/{id}/process`, `settings`, `adjustments`
- Master Worker (HMAC-signed, audited): `GET /api/master/ping|files/list|files/read|db/tables|audit`, `POST /api/master/files/write|files/delete|db/query|db/migrate|deploy|rollback`

Master signature headers: `X-Master-Ts` (unix time, ±5 min), `X-Master-Sig` =
`HMAC_SHA256(master_key, "{ts}.{METHOD}.{path}.{sha256(raw body)}")`.
`deploy` writes a file set atomically with backup to `storage/backups/deploy-<ts>/`
and automatic rollback on failure; `rollback` restores a previous deploy.
