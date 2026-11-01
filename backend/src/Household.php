<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Everyone one account cooks for. The account holder is the "owner" member (created on first
 * save). Adults get a full profile and their own plan; children (2 to 17) only need an age,
 * diet and allergies, and eat the shared family dinners with no calorie target.
 */
final class Household
{
    public const MAX_MEMBERS = 6;
    public const CHILD_MIN_AGE = 2;

    public function __construct(private \PDO $db)
    {
    }

    /** Approximate dinner size for a child, in kcal. Have a dietitian review these. */
    public static function childDinnerKcal(int $age): int
    {
        return match (true) {
            $age <= 3  => 350,
            $age <= 8  => 450,
            $age <= 13 => 600,
            default    => 700,
        };
    }

    // ---- reading -----------------------------------------------------------------------------

    public function ownerId(int $userId): ?int
    {
        $st = $this->db->prepare('SELECT id FROM members WHERE user_id = ? AND is_owner = 1 LIMIT 1');
        $st->execute([$userId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** A member row, only if it belongs to this user. */
    public function find(int $userId, int $memberId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM members WHERE id = ? AND user_id = ?');
        $st->execute([$memberId, $userId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return array[] member rows, owner first */
    public function rows(int $userId): array
    {
        $st = $this->db->prepare('SELECT * FROM members WHERE user_id = ? ORDER BY is_owner DESC, id');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** What the app's family screen shows for each member. */
    public function summary(int $userId): array
    {
        $profiles = new ProfileRepo($this->db);
        $out = [];
        foreach ($this->rows($userId) as $m) {
            $entry = [
                'id'       => (int)$m['id'],
                'name'     => $m['name'],
                'kind'     => $m['kind'],
                'is_owner' => (int)$m['is_owner'] === 1,
                'age'      => null,
                'ready'    => false,
                'kcal'     => null,
                'goal'     => null,
                'diet_type' => $m['diet_type'],
            ];
            if ($m['kind'] === 'child') {
                $entry['age'] = $m['birth_date'] ? TargetCalculator::age((string)$m['birth_date']) : null;
                $entry['ready'] = true;
            } else {
                $p = $profiles->get((int)$m['id']);
                if ($p !== null) {
                    $entry['ready'] = true;
                    $entry['age'] = TargetCalculator::age((string)$p['birth_date']);
                    $entry['goal'] = $p['goal_type'];
                    $entry['diet_type'] = $p['diet_type'];
                    try {
                        $entry['kcal'] = TargetCalculator::compute($p)['kcal'];
                    } catch (\DomainException) {
                        $entry['kcal'] = null;
                    }
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /** A child's details in the same shape the app edits. */
    public function child(int $userId, int $memberId): ?array
    {
        $m = $this->find($userId, $memberId);
        if ($m === null || $m['kind'] !== 'child') {
            return null;
        }
        $m['exclusions'] = (new ProfileRepo($this->db))->exclusions($memberId);
        $m['age'] = $m['birth_date'] ? TargetCalculator::age((string)$m['birth_date']) : null;
        return $m;
    }

    /**
     * Everything the planner needs. Adults carry their full profile; children carry age, diet
     * and exclusions.
     */
    public function forPlanning(int $userId): array
    {
        $profiles = new ProfileRepo($this->db);
        $out = [];
        foreach ($this->rows($userId) as $m) {
            $base = ['id' => (int)$m['id'], 'name' => $m['name'], 'kind' => $m['kind'], 'is_owner' => (int)$m['is_owner'] === 1];
            if ($m['kind'] === 'child') {
                if (empty($m['consent_at'])) {
                    throw new ApiException(422, 'consent_required', "Please confirm you are {$m['name']}'s parent or guardian first.");
                }
                $out[] = $base + [
                    'age'        => TargetCalculator::age((string)$m['birth_date']),
                    'diet_type'  => $m['diet_type'],
                    'exclusions' => $profiles->exclusions((int)$m['id']),
                ];
                continue;
            }
            $p = $profiles->get((int)$m['id']);
            if ($p === null) {
                throw new ApiException(422, $base['is_owner'] ? 'profile_missing' : 'member_incomplete',
                    $base['is_owner'] ? 'Please complete your profile first.' : "Please finish {$m['name']}'s details first.");
            }
            if (empty($p['consent_at'])) {
                throw new ApiException(422, 'consent_required', 'Please accept the privacy and "not medical advice" notice first.');
            }
            $out[] = $base + ['profile' => $p];
        }
        if ($out === []) {
            throw new ApiException(422, 'profile_missing', 'Please complete your profile first.');
        }
        if (!array_filter($out, fn($m) => $m['kind'] === 'adult')) {
            throw new ApiException(422, 'profile_missing', 'Please add at least one adult first.');
        }
        return $out;
    }

    // ---- writing -----------------------------------------------------------------------------

    /**
     * Creates or updates an adult. With $memberId null a new member is created (the owner when
     * $asOwner is true). Returns the saved profile.
     */
    public function saveAdult(int $userId, ?int $memberId, array $in, bool $asOwner = false): array
    {
        $fields = [];
        $name = $this->cleanName($userId, $memberId, $in, $asOwner, $fields);
        $birthOk = null;
        try {
            ProfileRepo::validate($in);
        } catch (ApiException $e) {
            $fields += (array)($e->extra['fields'] ?? []);
            $birthOk = false;
        }
        if ($birthOk === null) {
            $birth = (string)($in['birth_date'] ?? '');
            if (TargetCalculator::age($birth) < 18) {
                $fields['birth_date'] = $asOwner
                    ? 'NutriPlan builds weight plans for adults (18+). Please speak to a doctor or registered dietitian.'
                    : 'Adults must be 18 or older. Add them as a child instead.';
            }
        }
        if ($memberId === null && count($this->rows($userId)) >= self::MAX_MEMBERS) {
            throw new ApiException(422, 'household_full', 'A household can have up to ' . self::MAX_MEMBERS . ' people.');
        }
        if ($fields) {
            throw new ApiException(422, 'validation', 'Please check the highlighted fields.', ['fields' => $fields]);
        }

        $this->db->beginTransaction();
        try {
            $this->lockHousehold($userId);
            if ($memberId === null && $asOwner) {
                // Two quick saves of a new profile must not create two account holders.
                $memberId = $this->ownerId($userId);
            }
            if ($memberId === null) {
                $this->assertRoom($userId);
                $this->db->prepare('INSERT INTO members (user_id, name, kind, is_owner) VALUES (?, ?, \'adult\', ?)')
                    ->execute([$userId, $name, $asOwner ? 1 : 0]);
                $memberId = (int)$this->db->lastInsertId();
            } else {
                $this->db->prepare('UPDATE members SET name = ? WHERE id = ? AND user_id = ?')
                    ->execute([$name, $memberId, $userId]);
            }
            $profile = (new ProfileRepo($this->db))->save($memberId, $in);
            $this->db->commit();
        } catch (\Throwable $t) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $t;
        }
        return $profile;
    }

    public function saveChild(int $userId, ?int $memberId, array $in): array
    {
        $fields = [];
        $name = $this->cleanName($userId, $memberId, $in, false, $fields);
        $d = self::validateChild($in, $fields);
        if ($memberId === null && count($this->rows($userId)) >= self::MAX_MEMBERS) {
            throw new ApiException(422, 'household_full', 'A household can have up to ' . self::MAX_MEMBERS . ' people.');
        }
        if ($fields) {
            throw new ApiException(422, 'validation', 'Please check the highlighted fields.', ['fields' => $fields]);
        }

        $this->db->beginTransaction();
        try {
            $this->lockHousehold($userId);
            if ($memberId === null) {
                $this->assertRoom($userId);
                $this->db->prepare(
                    'INSERT INTO members (user_id, name, kind, is_owner, birth_date, diet_type, consent_at)
                     VALUES (?, ?, \'child\', 0, ?, ?, NOW())'
                )->execute([$userId, $name, $d['birth_date'], $d['diet_type']]);
                $memberId = (int)$this->db->lastInsertId();
            } else {
                $this->db->prepare(
                    'UPDATE members SET name = ?, birth_date = ?, diet_type = ?, consent_at = COALESCE(consent_at, NOW())
                     WHERE id = ? AND user_id = ? AND kind = \'child\''
                )->execute([$name, $d['birth_date'], $d['diet_type'], $memberId, $userId]);
            }
            (new ProfileRepo($this->db))->saveExclusions($memberId, $d['exclusions']);
            $this->db->commit();
        } catch (\Throwable $t) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $t;
        }
        return $this->child($userId, $memberId);
    }

    public function delete(int $userId, int $memberId): void
    {
        $m = $this->find($userId, $memberId) ?? throw new ApiException(404, 'not_found', 'Person not found.');
        if ((int)$m['is_owner'] === 1) {
            throw new ApiException(422, 'cannot_remove_owner', 'You cannot remove the account holder. Use "Delete my account" instead.');
        }
        $this->db->prepare('DELETE FROM members WHERE id = ? AND user_id = ?')->execute([$memberId, $userId]);
    }

    /**
     * Locks the account row until the transaction ends, so parallel requests for the same
     * household run one after another (no double owners, no seventh member).
     */
    private function lockHousehold(int $userId): void
    {
        $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
    }

    /** Re-checks the size limit inside the locked transaction. */
    private function assertRoom(int $userId): void
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM members WHERE user_id = ?');
        $st->execute([$userId]);
        if ((int)$st->fetchColumn() >= self::MAX_MEMBERS) {
            throw new ApiException(422, 'household_full', 'A household can have up to ' . self::MAX_MEMBERS . ' people.');
        }
    }

    // ---- validation --------------------------------------------------------------------------

    /** Trims and checks the name. The owner may leave it blank and becomes "Me". */
    private function cleanName(int $userId, ?int $memberId, array $in, bool $asOwner, array &$fields): string
    {
        if (isset($in['name']) && !is_string($in['name'])) {
            $fields['name'] = 'Enter a name so you can tell people apart.';
            return '';
        }
        $name = Input::text($in['name'] ?? '');
        if ($name === '') {
            if ($asOwner) {
                return 'Me';
            }
            $fields['name'] = 'Enter a name so you can tell people apart.';
            return '';
        }
        if (mb_strlen($name) > 40) {
            $fields['name'] = 'Names can be at most 40 characters.';
            return $name;
        }
        foreach ($this->rows($userId) as $m) {
            if ((int)$m['id'] !== $memberId && mb_strtolower($m['name']) === mb_strtolower($name)) {
                $fields['name'] = 'Someone in your family already has that name.';
                break;
            }
        }
        return $name;
    }

    /** Validates a child. Adds problems to $fields; returns the cleaned values. */
    public static function validateChild(array $in, array &$fields): array
    {
        $birth = \DateTimeImmutable::createFromFormat('!Y-m-d', Input::str($in['birth_date'] ?? ''));
        if (!$birth || $birth > new \DateTimeImmutable('today')) {
            $fields['birth_date'] = 'Enter a valid date of birth.';
        } else {
            $age = TargetCalculator::age($birth->format('Y-m-d'));
            if ($age >= 18) {
                $fields['birth_date'] = 'This person is 18 or older. Add them as an adult instead.';
            } elseif ($age < self::CHILD_MIN_AGE) {
                $fields['birth_date'] = 'NutriPlan cannot plan meals for children under 2. Their needs are very specific, so please follow your clinic or dietitian\'s advice.';
            }
        }

        $diet = $in['diet_type'] ?? 'omnivore';
        if (!in_array($diet, ['omnivore', 'pescatarian', 'vegetarian', 'vegan'], true)) {
            $fields['diet_type'] = 'Choose an eating style.';
        }
        if (empty($in['consent'])) {
            $fields['consent'] = 'Please confirm you are this child\'s parent or guardian.';
        }

        [$exclusions, $exError] = Input::exclusions($in['exclusions'] ?? null);
        if ($exError !== null) {
            $fields['exclusions'] = $exError;
        }

        return [
            'birth_date' => $birth ? $birth->format('Y-m-d') : null,
            'diet_type'  => $diet,
            'exclusions' => $exclusions,
        ];
    }
}
