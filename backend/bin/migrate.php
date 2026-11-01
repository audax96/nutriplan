<?php
// Brings an existing database up to date with database/schema.sql. Safe to run on every start
// (docker-compose runs it before the catalogue import). New installs get everything from schema.sql.
// Usage: php bin/migrate.php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use NutriPlan\Config;
use NutriPlan\Db;

$pdo = Db::pdo();
$dbName = Config::get('DB_NAME', 'nutriplan');

$hasColumn = static function (string $table, string $column) use ($pdo, $dbName): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$dbName, $table, $column]);
    return (int)$st->fetchColumn() > 0;
};

$done = [];

// 2026-10-25: macro targets are grams per day instead of percentages.
if (!$hasColumn('profiles', 'target_protein_g')) {
    $pdo->exec('ALTER TABLE profiles
        ADD COLUMN target_protein_g SMALLINT UNSIGNED NULL AFTER calorie_override,
        ADD COLUMN target_carb_g    SMALLINT UNSIGNED NULL AFTER target_protein_g,
        ADD COLUMN target_fat_g     SMALLINT UNSIGNED NULL AFTER target_carb_g');
    $done[] = 'added gram macro targets';
}
foreach (['protein_pct', 'carb_pct', 'fat_pct'] as $old) {
    if ($hasColumn('profiles', $old)) {
        $pdo->exec("ALTER TABLE profiles DROP COLUMN $old");
        $done[] = "removed $old";
    }
}

echo $done === [] ? "Database is up to date.\n" : 'Database updated: ' . implode(', ', $done) . ".\n";
