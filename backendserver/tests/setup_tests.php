<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use LifeOS\Migrations;

function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: $label\n";
}

$sql = "-- heading\nCREATE TABLE users (id INT); DROP TABLE users; INSERT INTO users VALUES (1);";
$safe = Migrations::safeStatements('001_legacy_schema.sql', $sql);
check($safe === ['CREATE TABLE IF NOT EXISTS users (id INT)'], 'Existing user data is not dropped or reseeded');
check(Migrations::databaseName('lifeos') === '`lifeos`', 'Configured XAMPP database name is used');
try { Migrations::databaseName('lifeos`; DROP DATABASE other'); $rejected = false; }
catch (\LifeOS\HttpError $e) { $rejected = true; }
check($rejected, 'Unsafe database identifiers are rejected');
check(Migrations::statements("-- comment\nSELECT 'a;b'; /* ignored ; */ SELECT 'it''s;ok';") === ["SELECT 'a;b'", "SELECT 'it''s;ok'"], 'Quoted semicolons and comments preserve SQL boundaries');
$legacy = Migrations::safeStatements('001_legacy_schema.sql', (string) file_get_contents(dirname(__DIR__) . '/database/001_legacy_schema.sql'));
check(count($legacy) === 58, 'Every legacy table is initialized additively');