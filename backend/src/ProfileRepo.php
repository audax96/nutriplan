<?php
declare(strict_types=1);

namespace NutriPlan;

final class ProfileRepo
{
    /** Allowed range (g per day) and label for each optional macro target. */
    public const GRAM_LIMITS = [
        'target_protein_g' => [20, 400, 'Protein'],
        'target_carb_g'    => [20, 900, 'Carbs'],
        'target_fat_g'     => [20, 350, 'Fat'],
    ];

    public function __construct(private \PDO $db)
    {
    }

    /**
     * An adult member's profile: the profile row plus member_id, name, is_owner and the
     * `exclusions` and `usual_drinks` lists. Null if that member has no profile yet.
     */
    public function get(int $memberId): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.*, m.id AS member_id, m.user_id, m.name, m.kind, m.is_owner
             FROM profiles p JOIN members m ON m.id = p.member_id WHERE p.member_id = ?'
        );
        $st->execute([$memberId]);
        $p = $st->fetch();
        if (!$p) {
            return null;
        }
        $p['exclusions'] = $this->exclusions($memberId);
        $p['preferences'] = $this->preferences($memberId);

        $dr = $this->db->prepare(
            'SELECT drink_key, name, kcal_per_serving, is_alcohol, servings_per_week
             FROM usual_drinks WHERE member_id = ? ORDER BY id'
        );
        $dr->execute([$memberId]);
        $p['usual_drinks'] = $dr->fetchAll();
        return $p;
    }

    public function exclusions(int $memberId): array
    {
        $ex = $this->db->prepare('SELECT name, kind FROM dietary_exclusions WHERE member_id = ? ORDER BY id');
        $ex->execute([$memberId]);
        return $ex->fetchAll();
    }

    /** Most notes kept per person; the oldest go first. */
    public const MAX_NOTES = 15;
    public const NOTE_MAX_CHARS = 200;

    /** Free-text food notes ("Does not like mushy textures"), newest first: [{id, note}]. */
    public function preferences(int $memberId): array
    {
        $st = $this->db->prepare('SELECT id, note FROM member_preferences WHERE member_id = ? ORDER BY id DESC');
        $st->execute([$memberId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'note' => $r['note']], $st->fetchAll());
    }

    /** Just the note texts, for the planner. */
    public function notes(int $memberId): array
    {
        return array_column($this->preferences($memberId), 'note');
    }

    /** Adds a note (skipping an exact repeat) and keeps only the newest MAX_NOTES. */
    public function addNote(int $memberId, string $note): void
    {
        $note = mb_substr(Input::text($note), 0, self::NOTE_MAX_CHARS);
        if ($note === '' || in_array(mb_strtolower($note), array_map('mb_strtolower', $this->notes($memberId)), true)) {
            return;
        }
        $this->db->prepare('INSERT INTO member_preferences (member_id, note) VALUES (?, ?)')->execute([$memberId, $note]);
        $this->trimNotes($memberId);
    }

    /** Replaces all notes (the person edited the list). */
    public function saveNotes(int $memberId, array $notes): void
    {
        $this->db->prepare('DELETE FROM member_preferences WHERE member_id = ?')->execute([$memberId]);
        $ins = $this->db->prepare('INSERT INTO member_preferences (member_id, note) VALUES (?, ?)');
        // Oldest first, so the ids keep the order the person sees (newest first).
        foreach (array_reverse($notes) as $n) {
            $ins->execute([$memberId, $n]);
        }
    }

    private function trimNotes(int $memberId): void
    {
        $st = $this->db->prepare('SELECT id FROM member_preferences WHERE member_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . self::MAX_NOTES);
        $st->execute([$memberId]);
        if (($cut = $st->fetchColumn()) !== false) {
            $this->db->prepare('DELETE FROM member_preferences WHERE member_id = ? AND id <= ?')->execute([$memberId, $cut]);
        }
    }

    /**
     * Adds foods as dislikes unless they are already listed (as an allergy or dislike).
     * @return string[] the names that were added
     */
    public function addDislikes(int $memberId, array $names): array
    {
        $have = array_map(fn($e) => mb_strtolower($e['name']), $this->exclusions($memberId));
        $added = [];
        $ins = $this->db->prepare("INSERT INTO dietary_exclusions (member_id, name, kind) VALUES (?, ?, 'dislike')");
        foreach ($names as $n) {
            $n = mb_substr(Input::text($n), 0, 80);
            if ($n === '' || in_array(mb_strtolower($n), $have, true) || count($have) >= 30) {
                continue;
            }
            $ins->execute([$memberId, $n]);
            $have[] = mb_strtolower($n);
            $added[] = $n;
        }
        return $added;
    }

    /**
     * Notes as sent by the app when the person edits them: a list of strings (or {note}).
     * @return array{0: ?array, 1: ?string} the clean list (null = not sent) and an error
     */
    public static function cleanNotes(mixed $raw): array
    {
        if ($raw === null) {
            return [null, null];
        }
        if (!is_array($raw)) {
            return [null, 'Food notes must be a list.'];
        }
        $out = [];
        foreach ($raw as $n) {
            $t = Input::text(is_array($n) ? ($n['note'] ?? '') : $n);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > self::NOTE_MAX_CHARS) {
                return [null, 'Each food note can be at most ' . self::NOTE_MAX_CHARS . ' characters.'];
            }
            $out[] = $t;
        }
        if (count($out) > self::MAX_NOTES) {
            return [null, 'Please keep at most ' . self::MAX_NOTES . ' food notes.'];
        }
        return [$out, null];
    }

    /** Replaces a member's allergies and dislikes. */
    public function saveExclusions(int $memberId, array $exclusions): void
    {
        $this->db->prepare('DELETE FROM dietary_exclusions WHERE member_id = ?')->execute([$memberId]);
        $ins = $this->db->prepare('INSERT INTO dietary_exclusions (member_id, name, kind) VALUES (?, ?, ?)');
        foreach ($exclusions as $e) {
            $ins->execute([$memberId, $e['name'], $e['kind']]);
        }
    }

    /** Validates and stores an adult member's profile. Safe to call inside a transaction. */
    public function save(int $memberId, array $in): array
    {
        $d = self::validate($in);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                'INSERT INTO profiles (member_id, sex, birth_date, height_cm, weight_kg, activity_level, goal_type, goal_weight_kg,
                    weekly_rate_kg, calorie_override, target_protein_g, target_carb_g, target_fat_g, diet_type,
                    pregnant_or_breastfeeding, consent_at)
                 VALUES (:member_id, :sex, :birth_date, :height_cm, :weight_kg, :activity_level, :goal_type, :goal_weight_kg,
                    :weekly_rate_kg, :calorie_override, :target_protein_g, :target_carb_g, :target_fat_g, :diet_type,
                    :pregnant, NOW())
                 ON DUPLICATE KEY UPDATE
                    sex = VALUES(sex), birth_date = VALUES(birth_date), height_cm = VALUES(height_cm),
                    weight_kg = VALUES(weight_kg), activity_level = VALUES(activity_level), goal_type = VALUES(goal_type),
                    goal_weight_kg = VALUES(goal_weight_kg), weekly_rate_kg = VALUES(weekly_rate_kg),
                    calorie_override = VALUES(calorie_override), target_protein_g = VALUES(target_protein_g),
                    target_carb_g = VALUES(target_carb_g), target_fat_g = VALUES(target_fat_g),
                    diet_type = VALUES(diet_type),
                    pregnant_or_breastfeeding = VALUES(pregnant_or_breastfeeding),
                    consent_at = COALESCE(consent_at, VALUES(consent_at))'
            )->execute([
                'member_id' => $memberId, 'sex' => $d['sex'], 'birth_date' => $d['birth_date'],
                'height_cm' => $d['height_cm'], 'weight_kg' => $d['weight_kg'],
                'activity_level' => $d['activity_level'], 'goal_type' => $d['goal_type'],
                'goal_weight_kg' => $d['goal_weight_kg'], 'weekly_rate_kg' => $d['weekly_rate_kg'],
                'calorie_override' => $d['calorie_override'], 'target_protein_g' => $d['target_protein_g'],
                'target_carb_g' => $d['target_carb_g'], 'target_fat_g' => $d['target_fat_g'],
                'diet_type' => $d['diet_type'],
                'pregnant' => $d['pregnant_or_breastfeeding'] ? 1 : 0,
            ]);

            $this->saveExclusions($memberId, $d['exclusions']);
            if ($d['preferences'] !== null) {
                $this->saveNotes($memberId, $d['preferences']);
            }

            $this->db->prepare('DELETE FROM usual_drinks WHERE member_id = ?')->execute([$memberId]);
            $insDrink = $this->db->prepare(
                'INSERT INTO usual_drinks (member_id, drink_key, name, kcal_per_serving, is_alcohol, servings_per_week)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($d['usual_drinks'] as $u) {
                $insDrink->execute([
                    $memberId, $u['drink_key'], $u['name'], $u['kcal_per_serving'],
                    $u['is_alcohol'] ? 1 : 0, $u['servings_per_week'],
                ]);
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $t) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $t;
        }
        return $this->get($memberId);
    }

    public static function validate(array $in): array
    {
        $errors = [];
        $num = fn($k) => isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k]) ? (float)$in[$k] : null;

        $sex = $in['sex'] ?? null;
        $in['birth_date'] = Input::str($in['birth_date'] ?? '');
        if (!in_array($sex, ['male', 'female'], true)) {
            $errors['sex'] = 'Choose male or female (used for the calorie formula).';
        }

        $birth = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($in['birth_date'] ?? ''));
        if (!$birth || $birth > new \DateTimeImmutable('today') || $birth < new \DateTimeImmutable('1900-01-01')) {
            $errors['birth_date'] = 'Enter a valid date of birth.';
        }

        $height = $num('height_cm');
        if ($height === null || $height < 100 || $height > 250) {
            $errors['height_cm'] = 'Height must be between 100 and 250 cm.';
        }
        $weight = $num('weight_kg');
        if ($weight === null || $weight < 30 || $weight > 300) {
            $errors['weight_kg'] = 'Weight must be between 30 and 300 kg.';
        }

        $activity = Input::str($in['activity_level'] ?? '');
        if (!isset(TargetCalculator::ACTIVITY[$activity])) {
            $errors['activity_level'] = 'Choose an activity level.';
        }

        $goalWeight = $num('goal_weight_kg');
        if ($goalWeight !== null && ($goalWeight < 30 || $goalWeight > 300)) {
            $errors['goal_weight_kg'] = 'Goal weight must be between 30 and 300 kg.';
        }
        $goalType = $in['goal_type'] ?? null;
        if (!in_array($goalType, ['lose', 'maintain', 'gain'], true)) {
            $goalType = 'maintain';
            if ($goalWeight !== null && $weight !== null) {
                $goalType = $goalWeight < $weight - 0.5 ? 'lose' : ($goalWeight > $weight + 0.5 ? 'gain' : 'maintain');
            }
        }

        $rate = $num('weekly_rate_kg') ?? 0.5;
        if ($rate < 0 || $rate > 1.0) {
            $errors['weekly_rate_kg'] = 'Choose a rate between 0 and 1 kg per week.';
        }

        $override = $num('calorie_override');
        if ($override !== null && ($override < 800 || $override > 6000)) {
            $errors['calorie_override'] = 'Daily calories must be between 800 and 6000.';
        }

        // Optional macro targets in grams per day. Any can be left blank.
        $grams = [];
        foreach (self::GRAM_LIMITS as $key => [$lo, $hi, $label]) {
            $grams[$key] = $num($key);
            if ($grams[$key] !== null) {
                $grams[$key] = round($grams[$key]);
                if ($grams[$key] < $lo || $grams[$key] > $hi) {
                    $errors[$key] = "$label must be between $lo and $hi g a day.";
                }
            }
        }
        $chosenKcal = 4 * ($grams['target_protein_g'] ?? 0) + 4 * ($grams['target_carb_g'] ?? 0) + 9 * ($grams['target_fat_g'] ?? 0);
        if (in_array(null, $grams, true) && $chosenKcal > TargetCalculator::MAX_KCAL) {
            $errors['macros'] = 'These grams add up to more than ' . TargetCalculator::MAX_KCAL . ' kcal a day. Please lower them.';
        }
        if (!in_array(null, $grams, true) && !isset($errors['target_protein_g'], $errors['target_carb_g'], $errors['target_fat_g'])) {
            $total = TargetCalculator::kcalFromGrams($grams['target_protein_g'], $grams['target_carb_g'], $grams['target_fat_g']);
            $floor = is_string($sex) ? (TargetCalculator::MIN_KCAL[$sex] ?? 1200) : 1200;
            if ($total < $floor) {
                $errors['macros'] = "These add up to about $total kcal a day, below the safe minimum of $floor kcal. Please raise them.";
            } elseif ($total > TargetCalculator::MAX_KCAL) {
                $errors['macros'] = "These add up to about $total kcal a day, more than the maximum of " . TargetCalculator::MAX_KCAL . ' kcal.';
            } elseif ($override !== null && abs($total - $override) > max(50, 0.05 * $override)) {
                $errors['macros'] = "These add up to about $total kcal a day, but you also set " . (int)$override
                    . ' kcal. Leave daily calories blank (they will come from your grams) or change the grams.';
            }
        }

        $diet = $in['diet_type'] ?? 'omnivore';
        if (!in_array($diet, ['omnivore', 'pescatarian', 'vegetarian', 'vegan'], true)) {
            $errors['diet_type'] = 'Choose a diet type.';
        }

        if (empty($in['consent'])) {
            $errors['consent'] = 'Please accept the privacy and "not medical advice" notice to continue.';
        }

        [$exclusions, $exError] = Input::exclusions($in['exclusions'] ?? null);
        if ($exError !== null) {
            $errors['exclusions'] = $exError;
        }
        [$notes, $notesError] = self::cleanNotes($in['preferences'] ?? null);
        if ($notesError !== null) {
            $errors['preferences'] = $notesError;
        }

        // Usual drinks: either a key from Drinks::CATALOGUE or a custom drink with its own calories.
        $drinks = [];
        $rawDrinks = $in['usual_drinks'] ?? [];
        if (!is_array($rawDrinks)) {
            $errors['usual_drinks'] = 'Usual drinks must be a list.';
            $rawDrinks = [];
        }
        if (count($rawDrinks) > 15) {
            $errors['usual_drinks'] = 'Please list at most 15 usual drinks.';
            $rawDrinks = [];
        }
        foreach ($rawDrinks as $d) {
            if (!is_array($d)) {
                continue;
            }
            $servings = is_numeric($d['servings_per_week'] ?? null) ? (float)$d['servings_per_week'] : 0.0;
            if ($servings <= 0 || $servings > Drinks::MAX_SERVINGS_PER_WEEK) {
                $errors['usual_drinks'] = 'Servings per week must be more than 0 and at most ' . Drinks::MAX_SERVINGS_PER_WEEK . '.';
                break;
            }
            $key = Input::str($d['drink_key'] ?? '');
            if ($key !== '') {
                if (!isset(Drinks::CATALOGUE[$key])) {
                    $errors['usual_drinks'] = 'That drink is not in our list. Add it as a custom drink instead.';
                    break;
                }
                $drinks[] = [
                    'drink_key' => $key, 'name' => Drinks::CATALOGUE[$key][0], 'kcal_per_serving' => null,
                    'is_alcohol' => Drinks::CATALOGUE[$key][5] > 0, 'servings_per_week' => $servings,
                ];
                continue;
            }
            $name = Input::text($d['name'] ?? '');
            $kcal = is_numeric($d['kcal_per_serving'] ?? null) ? (float)$d['kcal_per_serving'] : 0.0;
            if ($name === '' || mb_strlen($name) > 80 || $kcal < 1 || $kcal > 1500) {
                $errors['usual_drinks'] = 'A custom drink needs a name and its calories per serving (1 to 1500).';
                break;
            }
            $drinks[] = [
                'drink_key' => null, 'name' => $name, 'kcal_per_serving' => $kcal,
                'is_alcohol' => filter_var($d['is_alcohol'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'servings_per_week' => $servings,
            ];
        }
        if (count($drinks) > 15) {
            $errors['usual_drinks'] = 'Please list at most 15 usual drinks.';
        }

        if ($errors) {
            throw new ApiException(422, 'validation', 'Please check the highlighted fields.', ['fields' => $errors]);
        }

        return [
            'sex' => $sex,
            'birth_date' => $birth->format('Y-m-d'),
            'height_cm' => $height,
            'weight_kg' => $weight,
            'activity_level' => $activity,
            'goal_type' => $goalType,
            'goal_weight_kg' => $goalWeight,
            'weekly_rate_kg' => $rate,
            'calorie_override' => $override !== null ? (int)$override : null,
            'target_protein_g' => $grams['target_protein_g'] === null ? null : (int)$grams['target_protein_g'],
            'target_carb_g' => $grams['target_carb_g'] === null ? null : (int)$grams['target_carb_g'],
            'target_fat_g' => $grams['target_fat_g'] === null ? null : (int)$grams['target_fat_g'],
            'diet_type' => $diet,
            'pregnant_or_breastfeeding' => !empty($in['pregnant_or_breastfeeding']),
            'exclusions' => $exclusions,
            'preferences' => $notes,
            'usual_drinks' => $drinks,
        ];
    }
}
