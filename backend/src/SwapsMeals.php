<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Swapping one meal, one day, or one shared family dinner in a finished week.
 *
 * A swap is a background job like a full build: startSwap() records the request as a new
 * meal_plans row ('building', with job_json and base_plan_id) and the worker calls
 * runSwap(). The result is a complete new week (the old one with the change), so the app
 * shows it like any other plan, and the old week stays as the fallback until it is ready.
 *
 * What the person did not like can be saved: chosen foods become dislikes (so they are
 * filtered out of every future catalogue) and the rest becomes a short food note that the
 * planner reads as a taste preference.
 *
 * Part of PlanService (it reuses the Claude loop, scaling and child portions).
 */
trait SwapsMeals
{
    /** Swaps one account may ask for per 24 hours. */
    public static int $swapsPerDay = 25;

    public const SWAP_REASONS = ['ingredient', 'texture', 'taste', 'effort', 'portion', 'other'];

    private const SLOT_ORDER = ['breakfast', 'lunch', 'snack', 'dinner', 'drink'];

    /**
     * Checks the request, saves what the person did not like (if they want), and starts the
     * swap in the background. Returns the 'building' row, like start().
     *
     * @param array $in {member_id, day (0-based), scope 'meal'|'day', meal (index in that day),
     *                   feedback: {reasons[], ingredient_ids[], note, remember}}
     */
    public function startSwap(int $userId, int $planId, array $in): array
    {
        $lock = 'nutriplan_plan_start_' . $userId;
        $st = $this->db->prepare('SELECT GET_LOCK(?, 10)');
        $st->execute([$lock]);
        if ((int)$st->fetchColumn() !== 1) {
            throw new ApiException(409, 'busy', 'Your plan is being updated. Please wait a moment.');
        }
        try {
            $this->failStale($userId);
            $st = $this->db->prepare("SELECT id FROM meal_plans WHERE user_id = ? AND status = 'building' LIMIT 1");
            $st->execute([$userId]);
            if ($st->fetchColumn() !== false) {
                throw new ApiException(409, 'busy', 'Your plan is still being updated. Please wait until it is finished.');
            }

            $st = $this->db->prepare("SELECT * FROM meal_plans WHERE id = ? AND user_id = ?");
            $st->execute([$planId, $userId]);
            $base = $st->fetch() ?: throw new ApiException(404, 'not_found', 'Plan not found.');
            if ($base['status'] !== 'ready') {
                throw new ApiException(409, 'plan_not_ready', 'This plan is not finished yet.');
            }
            $st = $this->db->prepare("SELECT id FROM meal_plans WHERE user_id = ? AND status = 'ready' ORDER BY id DESC LIMIT 1");
            $st->execute([$userId]);
            if ((int)$st->fetchColumn() !== $planId) {
                throw new ApiException(409, 'plan_outdated', 'This is not your latest week any more. Please refresh and try again.');
            }
            $plan = json_decode((string)$base['plan_json'], true);
            if (!is_array($plan) || ($plan['version'] ?? 0) !== 2 || !is_array($plan['members'] ?? null)) {
                throw new ApiException(409, 'plan_too_old', 'This week was made with an older version of NutriPlan. Please build a new week.');
            }

            $job = $this->describeSwap($plan, $in, (string)$base['week_start']);
            $household = new Household($this->db);
            if ($household->find($userId, $job['member_id']) === null) {
                throw new ApiException(409, 'family_changed', 'This person is no longer in your family. Please build a new week.');
            }
            // The swap needs everyone's details (diets, allergies), so say now if something is missing.
            $household->forPlanning($userId);

            $st = $this->db->prepare('SELECT COUNT(*) FROM meal_plans WHERE user_id = ? AND job_json IS NOT NULL AND created_at >= (NOW() - INTERVAL 1 DAY)');
            $st->execute([$userId]);
            if ((int)$st->fetchColumn() >= self::$swapsPerDay) {
                throw new ApiException(429, 'too_many_swaps', 'You have swapped a lot of meals today. Please try again tomorrow.');
            }
            $this->assertServerCapacity();

            if ($job['feedback']['remember']) {
                $this->rememberFeedback($job);
            }

            $this->db->prepare(
                "INSERT INTO meal_plans (user_id, week_start, status, progress, target_json, job_json, base_plan_id)
                 VALUES (?, ?, 'building', ?, ?, ?, ?)"
            )->execute([
                $userId, $base['week_start'], mb_substr($job['progress'], 0, 150), $base['target_json'],
                json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $planId,
            ]);
            $id = (int)$this->db->lastInsertId();
        } finally {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
        $this->launch($id);
        return $this->get($userId, $id);
    }

    /**
     * Works out exactly what is being swapped from the request and the plan, and cleans the
     * feedback. Pure (no database), so it can be tested.
     */
    public function describeSwap(array $plan, array $in, string $weekStart): array
    {
        $memberId = (int)($in['member_id'] ?? 0);
        $ei = null;
        foreach ($plan['members'] as $i => $e) {
            if ((int)($e['member_id'] ?? 0) === $memberId) {
                $ei = $i;
                break;
            }
        }
        if ($ei === null) {
            throw new ApiException(404, 'not_found', 'That person is not in this plan.');
        }
        $entry = $plan['members'][$ei];
        $days = (array)($entry['days'] ?? []);
        $d = $in['day'] ?? null;
        if (!is_int($d) && !(is_string($d) && ctype_digit($d))) {
            throw new ApiException(422, 'validation', 'Please choose a day.');
        }
        $d = (int)$d;
        if (!isset($days[$d])) {
            throw new ApiException(422, 'validation', 'That day is not in this plan.');
        }
        $meals = array_values((array)($days[$d]['meals'] ?? []));
        $scope = ($in['scope'] ?? 'meal') === 'day' ? 'day' : 'meal';
        $shared = !empty($plan['shared_dinners']);
        $isChild = ($entry['kind'] ?? 'adult') === 'child';

        $mi = null;
        if ($isChild) {
            // Children only have the shared dinner.
            $scope = 'meal';
            foreach ($meals as $i => $m) {
                if (($m['slot'] ?? '') === 'dinner') {
                    $mi = $i;
                }
            }
        } elseif ($scope === 'meal') {
            $raw = $in['meal'] ?? null;
            $mi = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int)$raw : null;
        }
        if ($scope === 'meal' && ($mi === null || !isset($meals[$mi]))) {
            throw new ApiException(422, 'validation', 'Please choose a meal.');
        }

        $type = $scope === 'day' ? 'day'
            : (($isChild || ($shared && !empty($meals[$mi]['shared']))) ? 'dinner' : 'meal');

        // The meals being replaced, for feedback and the prompt.
        $replaced = match ($type) {
            'meal', 'dinner' => [$meals[$mi]],
            'day' => array_values(array_filter($meals, fn($m) => !($shared && !empty($m['shared'])))),
        };
        if ($replaced === []) {
            throw new ApiException(422, 'validation', 'There is nothing to swap on that day.');
        }

        // Feedback: only foods that are really in the meals being replaced.
        $fb = is_array($in['feedback'] ?? null) ? $in['feedback'] : [];
        $reasons = array_values(array_intersect(self::SWAP_REASONS, array_map('strval', array_filter((array)($fb['reasons'] ?? []), 'is_scalar'))));
        $inMeal = [];
        foreach ($replaced as $m) {
            foreach ((array)($m['items'] ?? []) as $it) {
                $inMeal[(int)$it['ingredient_id']] = (string)$it['name'];
            }
        }
        $foods = [];
        foreach ((array)($fb['ingredient_ids'] ?? []) as $id) {
            if (is_numeric($id) && isset($inMeal[(int)$id])) {
                $foods[(int)$id] = $inMeal[(int)$id];
            }
        }
        $note = mb_substr(Input::text($fb['note'] ?? ''), 0, 160);
        $remember = !array_key_exists('remember', $fb) || filter_var($fb['remember'], FILTER_VALIDATE_BOOLEAN);

        $weekday = self::weekdayName($weekStart, $d);
        $who = count($plan['members']) > 1 ? ' for ' . $entry['name'] : '';
        $slot = (string)($replaced[0]['slot'] ?? 'meal');
        $progress = match ($type) {
            'meal'   => "Finding a new $slot$who ($weekday)",
            'day'    => "Rebuilding $weekday$who",
            'dinner' => "Finding a new family dinner ($weekday)",
        };

        return [
            'type'      => $type,
            'scope'     => $scope,
            'member_id' => $memberId,
            'entry'     => $ei,
            'day'       => $d,
            'meal'      => $mi,
            'slot'      => $type === 'day' ? null : $slot,
            'weekday'   => $weekday,
            'old_names' => array_map(fn($m) => (string)$m['name'], $replaced),
            'progress'  => $progress,
            'feedback'  => [
                'reasons'  => $reasons,
                'foods'    => $foods,
                'note'     => $note,
                'remember' => $remember,
            ],
        ];
    }

    /** Saves chosen foods as dislikes and the rest as a food note, for the person who gave it. */
    private function rememberFeedback(array $job): void
    {
        $profiles = new ProfileRepo($this->db);
        $fb = $job['feedback'];
        if ($fb['foods'] !== []) {
            $profiles->addDislikes($job['member_id'], array_map([self::class, 'dislikeName'], array_values($fb['foods'])));
        }
        $note = self::feedbackNote($job);
        if ($note !== null) {
            $profiles->addNote($job['member_id'], $note);
        }
    }

    /**
     * The catalogue name without notes about how it is weighed ("Chicken breast (raw)" ->
     * "Chicken breast"), so the dislike also covers the same food cooked. Other notes stay,
     * because "Milk (low fat)" -> "Milk" would also block every dairy food.
     */
    public static function dislikeName(string $name): string
    {
        return trim(preg_replace('/\s*\((raw|dry|cooked|canned|frozen|uncooked)\)\s*$/i', '', $name) ?? $name);
    }

    /** "Creamy mushroom pasta: did not like the texture, too much work to make. Too mushy." */
    public static function feedbackNote(array $job): ?string
    {
        $fb = $job['feedback'];
        $labels = [
            'texture' => 'did not like the texture',
            'taste'   => 'did not like the taste',
            'effort'  => 'too much work to make',
            'portion' => 'the portion size was not right',
        ];
        $parts = [];
        foreach ($fb['reasons'] as $r) {
            if (isset($labels[$r])) {
                $parts[] = $labels[$r];
            }
        }
        if ($parts === [] && $fb['note'] === '') {
            return null;
        }
        $what = $job['type'] === 'day' ? 'Meals on a ' . $job['weekday'] : implode(', ', $job['old_names']);
        $text = $what . ': ' . ($parts === [] ? '' : implode(', ', $parts) . '.');
        if ($fb['note'] !== '') {
            $text .= ($parts === [] ? '' : ' ') . $fb['note'];
        }
        return mb_substr(trim($text), 0, ProfileRepo::NOTE_MAX_CHARS);
    }

    /** What the app needs to know about a swap row (null for a full build). */
    private static function jobSummary(array $row): ?array
    {
        if (empty($row['job_json'])) {
            return null;
        }
        $j = json_decode((string)$row['job_json'], true);
        if (!is_array($j)) {
            return null;
        }
        return [
            'type' => $j['type'], 'scope' => $j['scope'], 'member_id' => $j['member_id'],
            'day' => $j['day'], 'meal' => $j['meal'], 'slot' => $j['slot'],
            'base_plan_id' => isset($row['base_plan_id']) ? (int)$row['base_plan_id'] : null,
        ];
    }

    private static function weekdayName(string $weekStart, int $dayIndex): string
    {
        $t = strtotime($weekStart . " +$dayIndex day");
        return $t === false ? 'day ' . ($dayIndex + 1) : date('l', $t);
    }

    // ---------------------------------------------------------------------------------------
    // The background part
    // ---------------------------------------------------------------------------------------

    /** Loads everything a swap needs and runs it. @return array{plan: array, targets: ?array, attempts: int} */
    private function runSwap(array $row): array
    {
        $job = json_decode((string)$row['job_json'], true);
        $st = $this->db->prepare('SELECT plan_json, target_json FROM meal_plans WHERE id = ? AND user_id = ?');
        $st->execute([(int)$row['base_plan_id'], (int)$row['user_id']]);
        $base = $st->fetch() ?: throw new ApiException(409, 'plan_changed', 'That week is no longer available. Please build a new week.');
        $plan = json_decode((string)$base['plan_json'], true);

        $members = (new Household($this->db))->forPlanning((int)$row['user_id']);
        $result = $this->swapInPlan($plan, $job, $members, Catalogue::fromDb($this->db), (string)$row['week_start']);
        return ['plan' => $result['plan'], 'targets' => json_decode((string)$base['target_json'], true), 'attempts' => $result['attempts']];
    }

    /**
     * Does the swap on a decoded plan. Pure logic (no database), so it can be tested with a
     * fake Claude.
     *
     * @param array[] $members the family now, as returned by Household::forPlanning()
     * @return array{plan: array, attempts: int}
     */
    public function swapInPlan(array $plan, array $job, array $members, Catalogue $cat, string $weekStart): array
    {
        $verifier = new PlanVerifier($cat);
        $byId = [];
        foreach ($members as $m) {
            $byId[$m['id']] = $m;
        }
        if (!isset($byId[$job['member_id']])) {
            throw new ApiException(409, 'family_changed', 'This person is no longer in your family. Please build a new week.');
        }
        $foods = $job['feedback']['foods'] ?? [];
        // The foods the person just turned down stay out of the new meal even if they did
        // not ask us to remember them.
        $temp = array_map(fn($n) => ['name' => $n, 'kind' => 'dislike'], array_values($foods));

        if ($job['type'] === 'dinner') {
            foreach ($plan['members'] as $e) {
                if (!isset($byId[(int)$e['member_id']])) {
                    throw new ApiException(409, 'family_changed',
                        'Your family has changed since this week was built, so its shared dinners cannot be changed. Please build a new week.');
                }
            }
            $r = $this->swapDinner($plan, $job, $byId, $temp, $cat, $verifier);
        } else {
            $r = $this->swapAdultMeals($plan, $job, $byId[$job['member_id']], $temp, $cat, $verifier);
        }
        $plan = $r['plan'];

        // Point out where a food they just said they dislike is still in the week.
        if ($foods !== []) {
            $ei = $job['entry'];
            $left = [];
            foreach ($plan['members'][$ei]['days'] as $di => $day) {
                foreach ($day['meals'] as $meal) {
                    foreach ($meal['items'] as $it) {
                        if (isset($foods[(int)$it['ingredient_id']])) {
                            $left[$foods[(int)$it['ingredient_id']]][] = self::weekdayName($weekStart, $di) . ' ' . $meal['slot'];
                        }
                    }
                }
            }
            // Earlier "still in" notes are out of date now.
            $plan['members'][$ei]['warnings'] = array_values(array_filter(
                (array)($plan['members'][$ei]['warnings'] ?? []),
                fn($w) => !str_contains((string)$w, ' is still in ')
            ));
            foreach ($left as $name => $where) {
                array_unshift($plan['members'][$ei]['warnings'], sprintf(
                    '%s is still in %s. Tap that meal and choose "Swap" to change it.',
                    $name, implode(', ', array_unique($where))
                ));
            }
        }

        $have = $plan['pantry_have'] ?? null;
        if (!is_array($have)) {
            // Weeks built before pantry_have was stored: what was used is the best we know.
            $have = [];
            foreach ($plan['pantry_used'] ?? [] as $p) {
                $have[(int)$p['ingredient_id']] = (float)$p['amount'];
            }
        }
        $pantryDays = (int)($plan['pantry_days'] ?? 0);
        $lists = $this->pantryAndShopping($plan['members'], $have, $pantryDays, $cat, $verifier);
        $plan['shopping_list'] = $lists['shopping_list'];
        $plan['pantry_used'] = $lists['pantry_used'];
        return ['plan' => $plan, 'attempts' => $r['attempts']];
    }

    /** Diet and allergies only: what every day of the week must still respect. */
    private static function safetyConstraints(array $c): array
    {
        $c['exclusions'] = array_values(array_filter($c['exclusions'] ?? [], fn($e) => ($e['kind'] ?? 'allergy') === 'allergy'));
        return $c;
    }

    private static function memberConstraints(array $m): array
    {
        return $m['kind'] === 'adult'
            ? ['diet_type' => $m['profile']['diet_type'], 'exclusions' => $m['profile']['exclusions'],
               'goal' => $m['profile']['goal_type'], 'days' => self::DAYS, 'preferences' => $m['notes'] ?? []]
            : ['diet_type' => $m['diet_type'], 'exclusions' => $m['exclusions'], 'goal' => 'maintain',
               'days' => self::DAYS, 'preferences' => $m['notes'] ?? []];
    }

    /**
     * Checks one meal Claude proposed: known, allowed foods with sensible amounts, a name.
     * @return array{0: ?array, 1: string[]}
     */
    private static function cleanMeal(array $raw, array $allowed, string $label, ?string $slot = null): array
    {
        $issues = [];
        $name = mb_substr(Input::text($raw['name'] ?? ''), 0, 80);
        $slot ??= in_array($raw['slot'] ?? '', self::SLOT_ORDER, true) ? $raw['slot'] : null;
        if ($slot === null) {
            $issues[] = "$label: choose a slot (breakfast, lunch, snack, dinner or drink).";
        }
        $items = [];
        foreach ((array)($raw['items'] ?? []) as $it) {
            $id = (int)($it['ingredient_id'] ?? 0);
            $amt = (float)($it['amount'] ?? 0);
            if (!isset($allowed[$id])) {
                $issues[] = "$label: ingredient_id $id is not in the catalogue you were given.";
            } elseif ($amt <= 0 || $amt > PlanVerifier::MAX_AMOUNT) {
                $issues[] = "$label: the amount for {$allowed[$id]['name']} must be between 1 and " . PlanVerifier::MAX_AMOUNT . '.';
            } else {
                $items[] = ['ingredient_id' => $id, 'amount' => $amt];
            }
        }
        if ($name === '' || $items === []) {
            $issues[] = "$label needs a name and at least one ingredient.";
        }
        $meal = $issues === [] ? [
            'slot' => $slot, 'name' => $name, 'prep' => mb_substr(Input::text($raw['prep'] ?? ''), 0, 300), 'items' => $items,
        ] : null;
        return [$meal, $issues];
    }

    /** Issues that the change caused: anything about the changed day, and weekly problems that are new. */
    private static function newIssues(array $after, array $before, int $dayNumber): array
    {
        $day = array_diff($after['by_day'][$dayNumber] ?? [], $before['by_day'][$dayNumber] ?? []);
        $weekly = array_diff_key($after['weekly'], $before['weekly']);
        return array_values(array_merge($day, array_values($weekly)));
    }

    /**
     * The adult's entry rebuilt from a checked week. A dinner is marked as the family's shared
     * dinner only on days that had one before (or $alsoSharedDay, for a new family dinner).
     */
    private static function refreshAdult(array $entry, array $checked, bool $shared, ?int $alsoSharedDay = null): array
    {
        $p = $checked['plan'];
        foreach ($p['days'] as $di => $day) {
            $hadShared = $di === $alsoSharedDay;
            foreach ($entry['days'][$di]['meals'] ?? [] as $old) {
                $hadShared = $hadShared || (($old['slot'] ?? '') === 'dinner' && !empty($old['shared']));
            }
            foreach ($day['meals'] as $mi => $meal) {
                if ($shared && $hadShared && $meal['slot'] === 'dinner') {
                    $p['days'][$di]['meals'][$mi]['shared'] = true;
                }
            }
        }
        $entry['days'] = $p['days'];
        $entry['weekly_avg'] = $p['weekly_avg'];
        $entry['micros'] = $p['micros'];
        $entry['warnings'] = array_values(array_unique(array_merge($entry['targets']['warnings'] ?? [], $p['warnings'])));
        return $entry;
    }

    /** One adult's meal, or their whole day (without the shared dinner). */
    private function swapAdultMeals(array $plan, array $job, array $member, array $temp, Catalogue $cat, PlanVerifier $verifier): array
    {
        $ei = $job['entry'];
        $d = $job['day'];
        $entry = $plan['members'][$ei];
        $shared = !empty($plan['shared_dinners']);
        $t = self::planTargets($entry['targets']);
        $c = self::memberConstraints($member);
        $jobC = $c;
        $jobC['exclusions'] = array_merge($c['exclusions'], $temp);
        $safety = self::safetyConstraints($c);
        $allowed = $verifier->allowedIngredients($jobC);
        if (count($allowed) < 8) {
            throw new ApiException(422, 'too_restrictive', 'Your diet and exclusions leave too few foods to choose a new meal from.');
        }

        $rawDays = $entry['days'];
        $before = $verifier->check(['days' => $rawDays], $t, $safety);
        $dayMeals = array_values($rawDays[$d]['meals']);
        $fb = $job['feedback'];
        $feedback = array_filter([
            'did_not_like' => array_values(array_diff($fb['reasons'], ['other', 'ingredient'])),
            'foods_they_turned_down' => array_values($fb['foods']),
            'their_words' => $fb['note'],
        ]);

        if ($job['type'] === 'meal') {
            $old = $dayMeals[$job['meal']];
            $slot = (string)$old['slot'];
            $brief = [
                'replace'         => ['slot' => $slot, 'name' => $old['name']],
                'target_for_meal' => ['kcal' => $old['kcal'] ?? null, 'protein_g' => $old['protein_g'] ?? null,
                                      'carb_g' => $old['carb_g'] ?? null, 'fat_g' => $old['fat_g'] ?? null],
                'other_meals_that_day' => array_values(array_map(fn($m) => ['slot' => $m['slot'], 'name' => $m['name']],
                    array_filter($dayMeals, fn($m, $i) => $i !== $job['meal'], ARRAY_FILTER_USE_BOTH))),
                'goal'            => $c['goal'],
            ];
            $tool = self::swapMealTool();
            $check = function (array $input) use ($allowed, $slot, $old, $rawDays, $d, $job, $verifier, $t, $safety, $before): array {
                [$meal, $issues] = self::cleanMeal($input, $allowed, 'The meal', $slot);
                if ($meal !== null && mb_strtolower($meal['name']) === mb_strtolower((string)$old['name'])) {
                    $issues[] = 'That is the same meal. Suggest a clearly different one.';
                }
                $checked = null;
                if ($issues === []) {
                    $days = $rawDays;
                    $days[$d]['meals'][$job['meal']] = $meal;
                    $checked = $verifier->check(['days' => $days], $t, $safety);
                    $issues = self::newIssues($checked, $before, $d + 1);
                }
                return self::swapResult($issues, $checked, 'submit_meal');
            };
            $system = self::swapSystemPrompt('meal');
            $intro = "Replace one meal in this person's week.";
            $failMsg = "We could not find a new $slot that fits your targets this time. Your week has not changed. Please try again.";
        } else {
            $fixed = array_values(array_filter($dayMeals, fn($m) => $shared && !empty($m['shared'])));
            $replaced = array_values(array_filter($dayMeals, fn($m) => !($shared && !empty($m['shared']))));
            $need = array_values(array_unique(array_intersect(self::SLOT_ORDER, array_map(fn($m) => (string)$m['slot'], $replaced))));
            $mustHave = array_values(array_intersect($need, ['breakfast', 'lunch', 'dinner']));
            $sum = fn(string $k) => array_sum(array_map(fn($m) => (float)($m[$k] ?? 0), $replaced));
            $brief = [
                'replace_meals'    => array_map(fn($m) => ['slot' => $m['slot'], 'name' => $m['name']], $replaced),
                'slots_to_plan'    => $mustHave,
                'optional_slots'   => array_values(array_diff(['snack', 'drink'], $mustHave)),
                'target_for_these_meals' => ['kcal' => (int)$sum('kcal'), 'protein_g' => (int)$sum('protein_g'),
                                             'carb_g' => (int)$sum('carb_g'), 'fat_g' => (int)$sum('fat_g')],
                'goal'             => $c['goal'],
            ];
            if ($fixed !== []) {
                $brief['fixed_family_dinner'] = ['name' => $fixed[0]['name'], 'kcal' => $fixed[0]['kcal'] ?? null];
            }
            $oldNames = array_map(fn($m) => mb_strtolower((string)$m['name']), $replaced);
            $tool = self::swapDayTool($fixed !== []);
            $check = function (array $input) use ($allowed, $mustHave, $fixed, $oldNames, $rawDays, $d, $verifier, $t, $safety, $before): array {
                $issues = [];
                $meals = [];
                foreach (array_values((array)($input['meals'] ?? [])) as $i => $raw) {
                    [$meal, $mIssues] = self::cleanMeal(is_array($raw) ? $raw : [], $allowed, 'Meal ' . ($i + 1));
                    $issues = array_merge($issues, $mIssues);
                    if ($meal !== null && $fixed !== [] && $meal['slot'] === 'dinner') {
                        $issues[] = 'Do not plan dinner: the family dinner is already decided.';
                    } elseif ($meal !== null) {
                        $meals[] = $meal;
                    }
                }
                $slots = array_column($meals, 'slot');
                foreach ($mustHave as $s) {
                    if (!in_array($s, $slots, true)) {
                        $issues[] = "Include a $s.";
                    }
                }
                $newNames = array_map(fn($m) => mb_strtolower($m['name']), $meals);
                if ($meals !== [] && array_diff($newNames, $oldNames) === []) {
                    $issues[] = 'These are the same meals as before. Suggest clearly different ones.';
                }
                $checked = null;
                if ($issues === []) {
                    $all = array_merge($meals, $fixed);
                    usort($all, fn($a, $b) => array_search($a['slot'], self::SLOT_ORDER, true) <=> array_search($b['slot'], self::SLOT_ORDER, true));
                    $days = $rawDays;
                    $days[$d]['meals'] = $all;
                    $checked = $verifier->check(['days' => $days], $t, $safety);
                    $issues = self::newIssues($checked, $before, $d + 1);
                }
                return self::swapResult($issues, $checked, 'submit_day');
            };
            $system = self::swapSystemPrompt('day');
            $intro = "Replace this person's meals for one day of their week.";
            $failMsg = 'We could not rebuild that day to fit your targets this time. Your week has not changed. Please try again.';
        }

        if ($feedback !== []) {
            $brief['feedback'] = $feedback;
        }
        $notes = self::mergeNotes([$c['preferences']]);
        if ($notes !== []) {
            $brief['preferences'] = $notes;
        }
        $prompt = "$intro\n\nBRIEF:\n" . json_encode($brief, JSON_UNESCAPED_UNICODE)
            . "\n\nINGREDIENT CATALOGUE (per100 = nutrients per 100 g or 100 ml):\n" . json_encode($this->catalogueForPrompt($allowed));

        $r = $this->converse($system, $tool, $prompt, $check, 'swap_failed', $failMsg);
        $plan['members'][$ei] = self::refreshAdult($entry, $r['value'], $shared);
        return ['plan' => $plan, 'attempts' => $r['attempts']];
    }

    /** The shared family dinner for one day: changes for everyone, sized for each person. */
    private function swapDinner(array $plan, array $job, array $byId, array $temp, Catalogue $cat, PlanVerifier $verifier): array
    {
        $d = $job['day'];
        $allowed = null;
        foreach ($plan['members'] as $e) {
            $c = self::memberConstraints($byId[(int)$e['member_id']]);
            $c['exclusions'] = array_merge($c['exclusions'], $temp);
            $a = $verifier->allowedIngredients($c);
            $allowed = $allowed === null ? $a : array_intersect_key($allowed, $a);
        }
        if (count($allowed) < 8) {
            throw new ApiException(422, 'too_restrictive', 'Your family\'s diets and exclusions leave too few shared foods to choose a new dinner from.');
        }

        // Each adult's week as it is now, to compare against.
        $adults = [];
        foreach ($plan['members'] as $ei => $e) {
            if ($e['kind'] !== 'adult') {
                continue;
            }
            $t = self::planTargets($e['targets']);
            $safety = self::safetyConstraints(self::memberConstraints($byId[(int)$e['member_id']]));
            $adults[$ei] = ['t' => $t, 'safety' => $safety, 'before' => $verifier->check(['days' => $e['days']], $t, $safety)];
        }

        $old = null;
        foreach ($plan['members'][$job['entry']]['days'][$d]['meals'] as $m) {
            if ($m['slot'] === 'dinner') {
                $old = $m;
            }
        }
        $oldName = (string)($old['name'] ?? '');
        $otherDinners = [];
        foreach ($plan['members'][array_key_first($adults) ?? $job['entry']]['days'] as $di => $day) {
            foreach ($day['meals'] as $m) {
                if ($m['slot'] === 'dinner' && $di !== $d) {
                    $otherDinners[] = $m['name'];
                }
            }
        }

        $check = function (array $input) use ($allowed, $cat, $oldName, $adults, $plan, $d, $verifier): array {
            [$meal, $issues] = self::cleanMeal($input, $allowed, 'The dinner', 'dinner');
            if ($meal !== null && mb_strtolower($meal['name']) === mb_strtolower($oldName)) {
                $issues[] = 'That is the same dinner. Suggest a clearly different one.';
            }
            $results = [];
            $dinner = null;
            if ($issues === []) {
                $n = $this->mealNutrients($cat, $meal['items']);
                if ($n['kcal'] < 500 || $n['kcal'] > 800) {
                    $issues[] = sprintf('The dinner is %d kcal for one portion; it must be between 500 and 800 kcal (aim for about %d).',
                        round($n['kcal']), self::DINNER_REF_KCAL);
                }
                if ($n['protein_g'] < 25) {
                    $issues[] = sprintf('The dinner has only %dg protein per portion; it needs at least 25g.', round($n['protein_g']));
                }
                $dinner = $meal + ['kcal' => $n['kcal']];
            }
            if ($issues === []) {
                $no = 0;
                foreach ($adults as $ei => $a) {
                    $no++;
                    $scaled = $this->scaleDinners([$dinner], self::DINNER_SHARE * $a['t']['kcal'])[0];
                    $days = $plan['members'][$ei]['days'];
                    $meals = array_values(array_filter($days[$d]['meals'], fn($m) => $m['slot'] !== 'dinner'));
                    $meals[] = $scaled;
                    $days[$d]['meals'] = $meals;
                    $results[$ei] = $verifier->check(['days' => $days], $a['t'], $a['safety']);
                    foreach (self::newIssues($results[$ei], $a['before'], $d + 1) as $i) {
                        $issues[] = "With this dinner, adult $no: $i";
                    }
                }
            }
            return self::swapResult($issues, $issues === [] ? ['dinner' => $dinner, 'results' => $results] : null, 'submit_dinner');
        };

        $fb = $job['feedback'];
        $brief = [
            'replace'           => ['name' => $oldName],
            'reference_portion' => ['kcal' => self::DINNER_REF_KCAL, 'min_protein_g' => 25],
            'other_dinners_this_week' => $otherDinners,
            'people'            => ['adults' => count($adults), 'children' => count($plan['members']) - count($adults)],
        ];
        $feedback = array_filter([
            'did_not_like' => array_values(array_diff($fb['reasons'], ['other', 'ingredient'])),
            'foods_they_turned_down' => array_values($fb['foods']),
            'their_words' => $fb['note'],
        ]);
        if ($feedback !== []) {
            $brief['feedback'] = $feedback;
        }
        $notes = self::mergeNotes(array_map(fn($e) => $byId[(int)$e['member_id']]['notes'] ?? [], $plan['members']));
        if ($notes !== []) {
            $brief['preferences'] = $notes;
        }
        $prompt = "Replace one shared family dinner in this week.\n\nBRIEF:\n" . json_encode($brief, JSON_UNESCAPED_UNICODE)
            . "\n\nINGREDIENT CATALOGUE (per100 = nutrients per 100 g or 100 ml):\n" . json_encode($this->catalogueForPrompt($allowed));

        $r = $this->converse(self::swapSystemPrompt('dinner'), self::swapMealTool('submit_dinner', 'Submit the new family dinner (one reference portion).'),
            $prompt, $check, 'swap_failed',
            'We could not find a new dinner that suits everyone this time. Your week has not changed. Please try again.');

        $dinner = $r['value']['dinner'];
        foreach ($plan['members'] as $ei => $e) {
            if ($e['kind'] === 'adult') {
                $plan['members'][$ei] = self::refreshAdult($e, $r['value']['results'][$ei], true, $d);
                continue;
            }
            // A child: the new dinner at their size, built the same way as for a new week.
            $m = $byId[(int)$e['member_id']];
            $child = $this->childEntry($m, [$dinner], $cat, self::memberConstraints($m), $verifier);
            $day = $child['days'][0];
            $day['day'] = $d + 1;
            $plan['members'][$ei]['days'][$d] = $day;
        }
        return ['plan' => $plan, 'attempts' => $r['attempts']];
    }

    /** The converse() result shape, with feedback Claude can act on. */
    private static function swapResult(array $issues, mixed $value, string $toolName): array
    {
        $issues = array_values(array_unique($issues));
        return [
            'ok'       => $issues === [],
            'value'    => $value,
            'issues'   => array_slice($issues, 0, 12),
            'feedback' => "That was rejected. Fix every issue and call $toolName again:\n- " . implode("\n- ", array_slice($issues, 0, 12)),
        ];
    }

    private static function swapSystemPrompt(string $kind): string
    {
        $what = match ($kind) {
            'meal'   => "replace ONE meal in a person's weekly plan. You respond ONLY by calling the submit_meal tool.",
            'day'    => "replace the meals for ONE day of a person's weekly plan. You respond ONLY by calling the submit_day tool.",
            'dinner' => "replace ONE shared family dinner in a weekly plan. The whole family eats it, so the catalogue is already limited to foods everyone may eat. Plan ONE reference portion of about 650 kcal with at least 25 g of protein; it is scaled for each person afterwards. You respond ONLY by calling the submit_dinner tool.",
        };
        $base = <<<TXT
You are the meal-planning engine inside a nutrition app. Your job: $what

Rules:
- Use only ingredient ids from the supplied catalogue. It has already been filtered for diet, allergies and dislikes.
- Amounts are in the ingredient's own unit (g or ml), for ONE person (or one reference portion for a family dinner).
- Aim for the target in the brief. Portions are fine-tuned automatically afterwards, so focus on good food choices and sensible proportions.
- Make it clearly different from what is being replaced and from the other meals listed in the brief.
- If the brief has "feedback", it says what the person did not like about the old meal: fix exactly that (for example a different texture, a simpler recipe, or a different main ingredient).
- Realistic, tasty home cooking with a South African feel where it fits. Keep each "prep" to one or two short sentences.
TXT;
        return $base . "\n" . self::PREFERENCES_RULE;
    }

    private static function itemsSchema(): array
    {
        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'ingredient_id' => ['type' => 'integer'],
                    'amount'        => ['type' => 'number', 'description' => 'In the ingredient\'s unit (g or ml).'],
                ],
                'required'   => ['ingredient_id', 'amount'],
            ],
        ];
    }

    private static function swapMealTool(string $name = 'submit_meal', string $description = 'Submit the new meal.'): array
    {
        return [
            'name'         => $name,
            'description'  => $description,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'name'  => ['type' => 'string'],
                    'prep'  => ['type' => 'string', 'description' => 'One or two short sentences.'],
                    'items' => self::itemsSchema(),
                ],
                'required'   => ['name', 'items'],
            ],
        ];
    }

    private static function swapDayTool(bool $dinnerFixed): array
    {
        $slots = $dinnerFixed ? ['breakfast', 'lunch', 'snack', 'drink'] : self::SLOT_ORDER;
        return [
            'name'         => 'submit_day',
            'description'  => 'Submit the new meals for the day.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'meals' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slot'  => ['type' => 'string', 'enum' => $slots],
                                'name'  => ['type' => 'string'],
                                'prep'  => ['type' => 'string', 'description' => 'One or two short sentences.'],
                                'items' => self::itemsSchema(),
                            ],
                            'required'   => ['slot', 'name', 'items'],
                        ],
                    ],
                ],
                'required'   => ['meals'],
            ],
        ];
    }
}
