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

// 2026-11-07: swapping one meal or day, and food notes.
if (!$hasColumn('meal_plans', 'job_json')) {
    $pdo->exec('ALTER TABLE meal_plans
        ADD COLUMN job_json     JSON NULL AFTER attempts,
        ADD COLUMN base_plan_id INT UNSIGNED NULL AFTER job_json');
    $done[] = 'added meal swap columns';
}
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
$st->execute([$dbName, 'member_preferences']);
if ((int)$st->fetchColumn() === 0) {
    $pdo->exec('CREATE TABLE member_preferences (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        member_id  INT UNSIGNED NOT NULL,
        note       VARCHAR(200) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_prefs_member (member_id),
        CONSTRAINT fk_prefs_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
      ) ENGINE=InnoDB');
    $done[] = 'added food notes';
}

// 2026-11-08: password reset, two-step sign-in by email.
if (!$hasColumn('users', 'twofa_email')) {
    $pdo->exec('ALTER TABLE users
        ADD COLUMN twofa_email TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
        ADD COLUMN email_verified_at DATETIME NULL AFTER twofa_email');
    $done[] = 'added two-step sign-in';
}
$hasTable = static function (string $table) use ($pdo, $dbName): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $st->execute([$dbName, $table]);
    return (int)$st->fetchColumn() > 0;
};
if (!$hasTable('email_codes')) {
    $pdo->exec("CREATE TABLE email_codes (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id        INT UNSIGNED NOT NULL,
        purpose        ENUM('reset','login','enable_2fa') NOT NULL,
        code_hash      CHAR(64) NOT NULL,
        salt           CHAR(32) NOT NULL,
        challenge_hash CHAR(64) NULL,
        attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
        sends          TINYINT UNSIGNED NOT NULL DEFAULT 1,
        last_sent_at   DATETIME NOT NULL,
        expires_at     DATETIME NOT NULL,
        KEY idx_codes_user (user_id, purpose),
        KEY idx_codes_challenge (challenge_hash),
        CONSTRAINT fk_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB");
    $done[] = 'added email codes';
}
if (!$hasTable('outbox')) {
    $pdo->exec("CREATE TABLE outbox (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind       VARCHAR(20) NOT NULL DEFAULT 'mail',
        to_email   VARCHAR(255) NOT NULL,
        subject    VARCHAR(200) NOT NULL,
        body       TEXT NOT NULL,
        error      VARCHAR(255) NULL,
        tries      TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB");
    $done[] = 'added email outbox';
}

echo $done === [] ? "Database is up to date.\n" : 'Database updated: ' . implode(', ', $done) . ".\n";
