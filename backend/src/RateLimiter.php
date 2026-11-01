<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Counts events per (bucket, key) in a sliding window, stored in MySQL so every PHP worker
 * sees the same numbers. Keys are hashed, so no email or IP address is stored in clear.
 */
final class RateLimiter
{
    private static bool $ready = false;

    public function __construct(private \PDO $db)
    {
        $this->ensureSchema();
    }

    /** Creates the table on databases made before it existed (schema.sql has it for new ones). */
    private function ensureSchema(): void
    {
        if (self::$ready) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS rate_limits (
               id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               bucket     VARCHAR(30) NOT NULL,
               key_hash   CHAR(64) NOT NULL,
               created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
               KEY idx_rl (bucket, key_hash, created_at)
             ) ENGINE=InnoDB'
        );
        self::$ready = true;
    }

    private static function hashKey(string $bucket, string $key): string
    {
        return hash('sha256', $bucket . "\0" . strtolower($key));
    }

    public function count(string $bucket, string $key, int $windowSeconds): int
    {
        $st = $this->db->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND key_hash = ? AND created_at >= (NOW() - INTERVAL ? SECOND)'
        );
        $st->execute([$bucket, self::hashKey($bucket, $key), $windowSeconds]);
        return (int)$st->fetchColumn();
    }

    public function tooMany(string $bucket, string $key, int $max, int $windowSeconds): bool
    {
        return $this->count($bucket, $key, $windowSeconds) >= $max;
    }

    public function hit(string $bucket, string $key): void
    {
        $this->db->prepare('INSERT INTO rate_limits (bucket, key_hash) VALUES (?, ?)')
            ->execute([$bucket, self::hashKey($bucket, $key)]);
        // Housekeeping: nothing here is needed for more than a day.
        if (random_int(1, 50) === 1) {
            $this->db->exec('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    }

    public function clear(string $bucket, string $key): void
    {
        $this->db->prepare('DELETE FROM rate_limits WHERE bucket = ? AND key_hash = ?')
            ->execute([$bucket, self::hashKey($bucket, $key)]);
    }

    /**
     * Records the event and throws 429 if that went over $max in the window. Counting after
     * inserting means two requests at the same moment cannot both slip through.
     */
    public function consume(string $bucket, string $key, int $max, int $windowSeconds, string $message): void
    {
        // Already over: refuse without recording, so a flood cannot grow the table.
        if ($this->tooMany($bucket, $key, $max, $windowSeconds)) {
            throw new ApiException(429, 'too_many_requests', $message);
        }
        $this->hit($bucket, $key);
        if ($this->count($bucket, $key, $windowSeconds) > $max) {
            throw new ApiException(429, 'too_many_requests', $message);
        }
    }

    /** The caller's address. X-Forwarded-For is ignored: it is trivially faked. */
    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
