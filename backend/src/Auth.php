<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Email + password accounts with opaque bearer tokens (stored hashed, 30-day expiry).
 *
 * Brute-force protection: failed sign-ins are counted per email and per IP address, and
 * new accounts per IP address (see RateLimiter). Tokens can be revoked (sign out).
 */
final class Auth
{
    private const TOKEN_DAYS = 30;
    /** Signed-in devices per account; signing in on one more signs out the oldest. */
    private const MAX_TOKENS = 10;

    /** Failed sign-ins allowed for one email from one IP address in LOGIN_WINDOW seconds. */
    public const LOGIN_MAX_PER_EMAIL_IP = 10;
    /**
     * Failed sign-ins allowed for one email from anywhere. Looser than the per-IP limit, so a
     * stranger cannot lock the real owner out with a few wrong guesses, but still a hard
     * ceiling against a spread-out attack.
     */
    public const LOGIN_MAX_PER_EMAIL = 50;
    /** Failed sign-ins allowed per IP address in LOGIN_WINDOW seconds (covers many emails). */
    public const LOGIN_MAX_PER_IP = 50;
    public const LOGIN_WINDOW = 900;
    /** New accounts per IP address per hour. */
    public const REGISTER_MAX_PER_IP = 10;

    public const PASSWORD_MIN = 8;
    /** bcrypt only uses the first 72 bytes, so longer passwords would be silently cut. */
    public const PASSWORD_MAX_BYTES = 72;

    /** A short list of the passwords attackers try first. */
    private const COMMON_PASSWORDS = [
        'password', 'password1', 'password12', 'password123', 'passw0rd', 'p@ssw0rd', '12345678',
        '123456789', '1234567890', '0123456789', '11111111', '00000000', '87654321', 'qwerty123',
        'qwertyuiop', 'iloveyou', 'abc12345', 'abcd1234', 'letmein1', 'welcome1', 'sunshine',
        'princess', 'football', 'baseball', 'superman', 'trustno1', 'whatever', 'computer',
        'nutriplan', 'nutriplan1', 'changeme', 'admin123', 'qwerty12', 'asdfghjkl', 'zaq12wsx',
    ];

    public function __construct(private \PDO $db, private ?RateLimiter $limits = null)
    {
    }

    /** Created on first use, so ordinary requests never touch the rate_limits table. */
    private function limits(): RateLimiter
    {
        return $this->limits ??= new RateLimiter($this->db);
    }

    /** Why a new password is not acceptable, or null when it is fine. */
    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            return 'Your password must be at least ' . self::PASSWORD_MIN . ' characters.';
        }
        if (strlen($password) > self::PASSWORD_MAX_BYTES) {
            return 'Your password is too long. Please use a shorter one (at most 72 letters and numbers; accented letters and emoji count double or more).';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON_PASSWORDS, true) || preg_match('/^(.)\1+$/u', $password)) {
            return 'That password is too easy to guess. Please choose another one.';
        }
        $local = strstr(strtolower($email), '@', true);
        if ($email !== '' && ($lower === strtolower($email) || ($local !== false && strlen($local) >= 4 && $lower === $local))) {
            return 'Your password must not be your email address.';
        }
        return null;
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function register(string $email, string $password): array
    {
        $email = self::normaliseEmail($email);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(422, 'validation', 'Please enter a valid email address.');
        }
        if (($why = self::passwordProblem($password, $email)) !== null) {
            throw new ApiException(422, 'validation', $why);
        }
        $this->limits()->consume('register_ip', RateLimiter::clientIp(), self::REGISTER_MAX_PER_IP, 3600,
            'Too many new accounts from this network. Please try again later.');
        try {
            $this->db->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
                ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ApiException(409, 'email_taken', 'An account with that email already exists.');
            }
            throw $e;
        }
        return $this->issueToken((int)$this->db->lastInsertId());
    }

    public function login(string $email, string $password): array
    {
        $email = self::normaliseEmail($email);
        $ip = RateLimiter::clientIp();
        $pair = $email . '|' . $ip;
        $lim = $this->limits();
        $blocked = static fn(): ApiException => new ApiException(429, 'too_many_attempts',
            'Too many sign-in attempts. Please wait 15 minutes and try again.');
        if ($lim->tooMany('login_pair', $pair, self::LOGIN_MAX_PER_EMAIL_IP, self::LOGIN_WINDOW)
            || $lim->tooMany('login_email', $email, self::LOGIN_MAX_PER_EMAIL, self::LOGIN_WINDOW)
            || $lim->tooMany('login_ip', $ip, self::LOGIN_MAX_PER_IP, self::LOGIN_WINDOW)) {
            throw $blocked();
        }
        // Count the attempt before checking the password, so a burst of parallel guesses
        // cannot all slip in before the first failure is recorded. Cleared again on success.
        $lim->hit('login_pair', $pair);
        $lim->hit('login_email', $email);
        $lim->hit('login_ip', $ip);
        if ($lim->count('login_pair', $pair, self::LOGIN_WINDOW) > self::LOGIN_MAX_PER_EMAIL_IP) {
            throw $blocked();
        }

        $u = false;
        if ($email !== '' && strlen($email) <= 254 && strlen($password) <= 1024) {
            $st = $this->db->prepare('SELECT id, email, password_hash, twofa_email FROM users WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
        }
        // Always run one hash check, so the response time does not reveal whether the email exists.
        $ok = password_verify($password, $u ? $u['password_hash'] : self::dummyHash()) && $u;
        if (!$ok) {
            throw new ApiException(401, 'bad_credentials', 'Incorrect email or password.');
        }
        $lim->clear('login_pair', $pair);
        $lim->clear('login_email', $email);

        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        if ((int)$u['twofa_email'] === 1) {
            // Two-step sign-in: no session yet, just a challenge; the code goes to their inbox.
            return (new AccountSecurity($this->db, $this))->startLoginChallenge((int)$u['id'], (string)$u['email']);
        }
        return $this->issueToken((int)$u['id']);
    }

    /** Checks the signed-in person's password again before something drastic (deleting the account). */
    public function confirmPassword(int $userId, string $password): void
    {
        $key = 'user:' . $userId;
        if ($this->limits()->tooMany('confirm_pw', $key, self::LOGIN_MAX_PER_EMAIL_IP, self::LOGIN_WINDOW)) {
            throw new ApiException(429, 'too_many_attempts', 'Too many attempts. Please wait 15 minutes and try again.');
        }
        $st = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $hash = $st->fetchColumn();
        if (!is_string($hash) || $password === '' || strlen($password) > 1024 || !password_verify($password, $hash)) {
            $this->limits()->hit('confirm_pw', $key);
            throw new ApiException(403, 'wrong_password', 'That password is not right.',
                ['fields' => ['password' => 'That password is not right.']]);
        }
        $this->limits()->clear('confirm_pw', $key);
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    /** A new session token for this person (after a password, or a password plus an emailed code). */
    public function issueToken(int $userId): array
    {
        // Housekeeping: expired tokens are useless, and nobody needs more than a handful of
        // signed-in devices, so only the newest MAX_TOKENS - 1 are kept before adding this one.
        $this->db->prepare('DELETE FROM api_tokens WHERE user_id = ? AND expires_at <= NOW()')->execute([$userId]);
        $st = $this->db->prepare('SELECT id FROM api_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . (self::MAX_TOKENS - 1));
        $st->execute([$userId]);
        if (($oldest = $st->fetchColumn()) !== false) {
            $this->db->prepare('DELETE FROM api_tokens WHERE user_id = ? AND id <= ?')->execute([$userId, $oldest]);
        }

        $token = bin2hex(random_bytes(32));
        // The expiry is worked out by MySQL, so it uses the same clock that checks it.
        $this->db->prepare('INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . self::TOKEN_DAYS . ' DAY)')
            ->execute([$userId, hash('sha256', $token)]);
        return ['token' => $token];
    }

    private static function bearer(): ?string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/^Bearer\s+([a-f0-9]{64})$/', $header, $m) ? $m[1] : null;
    }

    /** Returns the user id for the request's bearer token or throws 401. */
    public function requireUser(): int
    {
        $token = self::bearer() ?? throw new ApiException(401, 'unauthorized', 'Please sign in.');
        $st = $this->db->prepare('SELECT user_id FROM api_tokens WHERE token_hash = ? AND expires_at > NOW()');
        $st->execute([hash('sha256', $token)]);
        $id = $st->fetchColumn();
        if (!$id) {
            throw new ApiException(401, 'unauthorized', 'Your session has expired. Please sign in again.');
        }
        return (int)$id;
    }

    /** Signs the person out everywhere, except (optionally) this device. */
    public function revokeAll(int $userId, bool $keepCurrent = false): void
    {
        $token = $keepCurrent ? self::bearer() : null;
        if ($token !== null) {
            $this->db->prepare('DELETE FROM api_tokens WHERE user_id = ? AND token_hash <> ?')->execute([$userId, hash('sha256', $token)]);
        } else {
            $this->db->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$userId]);
        }
    }

    /** Clears the failed sign-in counters for an email (after a successful password reset). */
    public function clearLoginLimits(string $email): void
    {
        $this->limits()->clear('login_email', self::normaliseEmail($email));
    }

    /** Signs this device out: the token stops working at once. */
    public function revokeCurrent(): void
    {
        $token = self::bearer();
        if ($token !== null) {
            $this->db->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }
    }
}
