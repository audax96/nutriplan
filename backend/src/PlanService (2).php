<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Orchestrates: profile -> targets -> Claude proposes -> code verifies -> retry with
 * concrete feedback -> store. A plan that fails any hard check (allergen, calories,
 * protein, vitamins/minerals) is never shown to the user.
 */
final class PlanService
{
    public const MAX_ATTEMPTS = 3;
    public const DAYS = 7;
    /** Plans one account may start per 24 hours. Each costs real Claude credit. */
    public const PLANS_PER_DAY = 10;
    /** Plans the whole server may start per 24 hours (all accounts). Override with DAILY_PLAN_LIMIT. */
    public const DEFAULT_DAILY_PLAN_LIMIT = 100;
    private const TOOL_NAME = 'submit_meal_plan';

    public function __construct(private \PDO $db, private ClaudeClient $claude)
    {
    }

    /**
     * Starts building a household plan in the background and returns straight away with a
     * 'building' plan the app can poll. If one is already being built it is returned instead
     * (so a double tap never costs twice).
     */
    public function start(int $userId): array
    {
        // One start at a time per account, so two quick taps cannot both launch a (paid) build.
        $lock = 'nutriplan_plan_start_' . $userId;
        $st = $this->db->prepare('SELECT GET_LOCK(?, 10)');
        $st->execute([$lock]);
        if ((int)$st->fetchColumn() !== 1) {
            throw new ApiException(409, 'busy', 'Your plan is already being started. Please wait a moment.');
        }
        try {
            $this->failStale($userId);
            $st = $this->db->prepare("SELECT * FROM meal_plans WHERE user_id = ? AND status = 'building' ORDER BY id DESC LIMIT 1");
            $st->execute([$userId]);
            if ($row = $st->fetch()) {
                return $this->present($row);
            }

            $st = $this->db->prepare('SELECT COUNT(*) FROM meal_plans WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)');
            $st->execute([$userId]);
            if ((int)$st->fetchColumn() >= self::PLANS_PER_DAY) {
                throw new ApiException(429, 'too_many_plans',
                    'You have made a lot of plans today. Please try again tomorrow.');
            }

            // A ceiling for the whole server, so many throwaway accounts cannot drain the Claude credit.
            $global = (int)(Config::get('DAILY_PLAN_LIMIT') ?? self::DEFAULT_DAILY_PLAN_LIMIT);
            $total = (int)$this->db->query('SELECT COUNT(*) FROM meal_plans WHERE created_at >= (NOW() - INTERVAL 1 DAY)')->fetchColumn();
            if ($total >= $global) {
                error_log("Daily plan limit ($global) reached for the whole server.");
                throw new ApiException(503, 'busy_today', 'NutriPlan is very busy today. Please try again tomorrow.');
            }

            // Cheap checks first, so mistakes (missing details, pregnancy, ...) come back instantly.
            $members = (new Household($this->db))->forPlanning($userId);
            $this->adultTargets($members);

            $this->db->prepare("INSERT INTO meal_plans (user_id, week_start, status, progress) VALUES (?, ?, 'building', 'Getting started')")
                ->execute([$userId, date('Y-m-d')]);
            $id = (int)$this->db->lastInsertId();
        } finally {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
        $this->launch($id);

        return $this->get($userId, $id);
    }

    /** Runs the build in a separate PHP process so the request can return immediately. */
    private function launch(int $planId): void
    {
        $script = dirname(__DIR__) . '/bin/run_plan.php';
        if (function_exists('exec') && DIRECTORY_SEPARATOR === '/' && PHP_BINARY !== '' && is_file($script)) {
            $log = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : '/dev/null';
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $planId
                . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
            return;
        }
        set_time_limit(0);
        $this->build($planId); // no way to spawn a process: build inline (slow, but it works)
    }

    /** The body of the background job: builds plan $planId and stores the outcome. */
    public function build(int $planId): void
    {
        $st = $this->db->prepare("SELECT * FROM meal_plans WHERE id = ? AND status = 'building'");
        $st->execute([$planId]);
        $row = $st->fetch();
        if (!$row) {
            return;
        }
        try {
            $members = (new Household($this->db))->forPlanning((int)$row['user_id']);
            $pantry = [];
            try {
                $pantry = (new Pantry($this->db))->amounts((int)$row['user_id']);
            } catch (\Throwable $e) {
                // The pantry is a bonus: if it cannot be read, plan without it.
                error_log('Pantry unavailable: ' . $e->getMessage());
            }
            $result = $this->buildHousehold(
                $members,
                Catalogue::fromDb($this->db),
                fn(string $msg) => $this->setProgress($planId, $msg),
                $pantry
            );
            $this->db->prepare(
                "UPDATE meal_plans SET status = 'ready', progress = NULL, target_json = ?, plan_json = ?, attempts = ? WHERE id = ?"
            )->execute([
                json_encode($result['targets'], JSON_THROW_ON_ERROR),
                json_encode($result['plan'], JSON_THROW_ON_ERROR),
                min(255, $result['attempts']),
                $planId,
            ]);
        } catch (ApiException $e) {
            $this->fail($planId, $e->errorCode, $e->getMessage());
        } catch (\Throwable $t) {
            error_log($t::class . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
            $this->fail($planId, 'server_error', 'Something went wrong while building the plan. Please try again.');
        }
    }

    private function setProgress(int $planId, string $message): void
    {
        $this->db->prepare('UPDATE meal_plans SET progress = ? WHERE id = ?')->execute([mb_substr($message, 0, 150), $planId]);
    }

    private function fail(int $planId, string $code, string $message): void
    {
        $this->db->prepare("UPDATE meal_plans SET status = 'failed', progress = NULL, error_code = ?, error_message = ? WHERE id = ?")
            ->execute([mb_substr($code, 0, 40), mb_substr($message, 0, 590), $planId]);
    }

    /** A build that stopped reporting (server restarted mid-way, say) is marked failed. */
    private function failStale(int $userId): void
    {
        $this->db->prepare(
            "UPDATE meal_plans SET status = 'failed', progress = NULL, error_code = 'timeout',
                error_message = 'Building the plan took too long and was stopped. Please try again.'
             WHERE user_id = ? AND status = 'building' AND updated_at < (NOW() - INTERVAL 20 MINUTE)"
        )->execute([$userId]);
    }

    /**
     * Targets for every adult, keyed by member id. Refuses (naming the person) when someone
     * needs a professional instead of an app.
     *
     * @param array[] $members as returned by Household::forPlanning()
     */
    public function adultTargets(array $members): array
    {
        $out = [];
        foreach ($members as $m) {
            if ($m['kind'] !== 'adult') {
                continue;
            }
            $who = $m['is_owner'] ? '' : $m['name'] . ': ';
            $p = $m['profile'];
            if ((int)$p['pregnant_or_breastfeeding'] === 1) {
                throw new ApiException(422, 'needs_professional', $who
                    . 'Pregnancy and breastfeeding change nutrition needs. Please speak to a doctor or registered dietitian for a plan.');
            }
            try {
                $out[$m['id']] = TargetCalculator::compute($p);
            } catch (\DomainException $e) {
                throw new ApiException(422, 'needs_professional', $who . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * The targets the planned meals themselves must hit: the daily totals with the person's
     * usual drinks (cooldrinks, sweet coffee, wine ...) already taken out.
     */
    public static function planTargets(array $targets): array
    {
        return array_merge($targets, $targets['food'] ?? []);
    }

    /**
     * The newest plan, whatever its state. If it is still building or failed, the newest
     * finished plan comes along as `fallback` so the person keeps their old week meanwhile.
     */
    public function latest(int $userId): ?array
    {
        $this->failStale($userId);
        $st = $this->db->prepare('SELECT * FROM meal_plans WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $out = $this->present($row);
        if ($row['status'] !== 'ready') {
            $st = $this->db->prepare("SELECT * FROM meal_plans WHERE user_id = ? AND status = 'ready' ORDER BY id DESC LIMIT 1");
            $st->execute([$userId]);
            $ready = $st->fetch();
            $out['fallback'] = $ready ? $this->present($ready) : null;
        }
        return $out;
    }

    public function get(int $userId, int $planId): ?array
    {
        $this->failStale($userId);
        $st = $this->db->prepare('SELECT * FROM meal_plans WHERE id = ? AND user_id = ?');
        $st->execute([$planId, $userId]);
        $row = $st->fetch();
        return $row ? $this->present($row) : null;
    }

    private function present(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'week_start' => $row['week_start'],
            'status'     => $row['status'],
            'progress'   => $row['progress'],
            'error'      => $row['status'] === 'failed'
                ? ['code' => $row['error_code'], 'message' => $row['error_message']]
                : null,
            'targets'    => $row['target_json'] ? json_decode($row['target_json'], true) : null,
            'plan'       => $row['plan_json'] ? json_decode($row['plan_json'], true) : null,
        ];
    }

    // =========================================================================================
    // Household planning
    // =========================================================================================

    /** Share of an adult's daily food calories that the shared family dinner provides. */
    public const DINNER_SHARE = 0.35;
    /** The dinner Claude designs is one portion of about this size; it is scaled per person. */
    public const DINNER_REF_KCAL = 650;

    /** Added to the system prompts when the person has told us what is already at home. */
    private const PANTRY_RULE = '- The brief lists "pantry" foods the person already has at home, with rough amounts. They come first: build the first "pantry_days" days around them and use EVERY pantry food in at least one meal during those days. Use a sensible amount of each (a little oil or spread, a few eggs), not necessarily all of it, and spread them across the days rather than putting them all in one meal. The calorie, protein, nutrient and food-safety rules still come first, so never bend them to use a pantry food, and use only ids from the catalogue. Do not mention the pantry in meal names.';

    /** The shared dinners cover only some meals, so they take as many pantry foods as suit; the adults\' other meals use the rest. */
    private const DINNER_PANTRY_RULE = '- The brief lists "pantry" foods the family already has at home, with rough amounts. In the dinners for the first "pantry_days" days, work in as many of these foods as make good meals together: they are the priority, and what is left over will go into other meals. Use a sensible amount of each, not necessarily all of it. The calorie, protein and food-safety rules still come first, and use only ids from the catalogue. Do not mention the pantry in meal names.';

    /**
     * Builds the whole household's week. Pure logic (no database), so it can be tested with a
     * fake Claude.
     *
     * One person: a normal personal plan. Several people: Claude first designs one shared dinner
     * per day from foods everybody can eat, then plans each adult's remaining meals around it.
     * Children eat the dinners, scaled to their age.
     *
     * @param array[] $members as returned by Household::forPlanning()
     * @param callable|null $progress receives a short status sentence
     * @param array<int,float> $pantry foods already at home (ingredient id => rough amount); the first
     *        Pantry::PLAN_DAYS days lean on them and they come off the shopping list
     * @return array{plan: array, targets: array, attempts: int}
     */
    public function buildHousehold(array $members, Catalogue $cat, ?callable $progress = null, array $pantry = []): array
    {
        $say = $progress ?? static function (string $m): void {
        };
        $verifier = new PlanVerifier($cat);
        $targets = $this->adultTargets($members);
        $shared = count($members) > 1;

        $constraintsOf = static fn(array $m): array => $m['kind'] === 'adult'
            ? ['diet_type' => $m['profile']['diet_type'], 'exclusions' => $m['profile']['exclusions'],
               'goal' => $m['profile']['goal_type'], 'days' => self::DAYS]
            : ['diet_type' => $m['diet_type'], 'exclusions' => $m['exclusions'], 'goal' => 'maintain', 'days' => self::DAYS];

        $attempts = 0;
        $dinners = null;
        if ($shared) {
            $say('Choosing dinners everyone can eat');
            $found = $this->planDinners($members, $constraintsOf, $cat, $verifier, $pantry);
            $dinners = $found['dinners'];
            $attempts += $found['attempts'];
        }

        $adults = array_values(array_filter($members, fn($m) => $m['kind'] === 'adult'));
        // Each adult's own meals share the pantry, so each is offered an equal part of it.
        $pantryEach = array_map(fn($a) => $a / max(1, count($adults)), $pantry);
        $out = [];
        $adultNo = 0;
        foreach ($members as $m) {
            if ($m['kind'] === 'child') {
                $out[] = $this->childEntry($m, $dinners, $cat, $constraintsOf($m), $verifier);
                continue;
            }
            $adultNo++;
            $say($shared
                ? sprintf('Planning meals for %s (%d of %d)', $m['name'], $adultNo, count($adults))
                : 'Building your week');
            $t = self::planTargets($targets[$m['id']]);
            $fixed = $dinners === null ? [] : $this->scaleDinners($dinners, self::DINNER_SHARE * $t['kcal']);
            try {
                $r = $this->run($t, $constraintsOf($m), $cat, $fixed, $pantryEach);
            } catch (ApiException $e) {
                if ($e->errorCode === 'plan_unsatisfiable' && $shared) {
                    throw new ApiException(422, 'plan_unsatisfiable',
                        "We could not build a week for {$m['name']} that meets all of their targets and exclusions this time. Please try again, or relax a dislike or calorie target.",
                        $e->extra);
                }
                throw $e;
            }
            $attempts += $r['attempts'];
            $plan = $r['plan'];
            foreach ($plan['days'] as $di => $day) {
                foreach ($day['meals'] as $mi => $meal) {
                    if ($fixed !== [] && $meal['slot'] === 'dinner') {
                        $plan['days'][$di]['meals'][$mi]['shared'] = true;
                    }
                }
            }
            $out[] = [
                'member_id'  => $m['id'],
                'name'       => $m['name'],
                'kind'       => 'adult',
                'is_owner'   => $m['is_owner'],
                'targets'    => $targets[$m['id']],
                'days'       => $plan['days'],
                'weekly_avg' => $plan['weekly_avg'],
                'micros'     => $plan['micros'],
                'warnings'   => array_values(array_unique(array_merge($targets[$m['id']]['warnings'], $plan['warnings']))),
            ];
        }

        // Mark the foods the person already has, in the days built around the pantry.
        $pantryDays = Pantry::planDays(count($pantry));
        $early = [];
        if ($pantry !== []) {
            foreach ($out as $ei => $entry) {
                foreach ($entry['days'] as $di => $day) {
                    if ($di >= $pantryDays) {
                        break;
                    }
                    foreach ($day['meals'] as $mi => $meal) {
                        foreach ($meal['items'] as $ii => $it) {
                            $iid = (int)$it['ingredient_id'];
                            if (isset($pantry[$iid])) {
                                $out[$ei]['days'][$di]['meals'][$mi]['items'][$ii]['at_home'] = true;
                                $early[$iid] = ($early[$iid] ?? 0.0) + (float)$it['amount'];
                            }
                        }
                    }
                }
            }
        }

        // One shopping list for the whole household, rounded up once (not per person).
        $usage = [];
        foreach ($out as $entry) {
            foreach ($entry['days'] as $day) {
                foreach ($day['meals'] as $meal) {
                    foreach ($meal['items'] as $it) {
                        $usage[$it['ingredient_id']] = ($usage[$it['ingredient_id']] ?? 0.0) + $it['amount'];
                    }
                }
            }
        }

        // What the person already has comes off the list (never more than they have).
        $pantryUsed = [];
        if ($pantry !== []) {
            $applied = Pantry::applyToUsage($usage, $early, $pantry);
            $usage = $applied['usage'];
            foreach ($applied['covered'] as $iid => $amount) {
                $ing = $cat->get((int)$iid);
                $pantryUsed[] = [
                    'ingredient_id' => (int)$iid, 'ingredient' => $ing['name'], 'category' => $ing['category'],
                    'unit' => $ing['unit'], 'amount' => (int)round($amount),
                ];
            }
            usort($pantryUsed, fn($a, $b) => [$a['category'], $a['ingredient']] <=> [$b['category'], $b['ingredient']]);
        }

        $notes = [];
        if ($shared) {
            $anyLimits = array_filter($members, fn($m) => ($m['kind'] === 'adult' ? $m['profile']['diet_type'] : $m['diet_type']) !== 'omnivore'
                || ($m['kind'] === 'adult' ? $m['profile']['exclusions'] : $m['exclusions']) !== []);
            if ($anyLimits) {
                $notes[] = 'Shared dinners follow the strictest eating style and every allergy or dislike in the family.';
            }
        }

        return [
            'plan' => [
                'version'        => 2,
                'shared_dinners' => $shared,
                'members'        => $out,
                'shopping_list'  => $verifier->buildShoppingList($usage),
                'pantry_used'    => $pantryUsed,
                'pantry_days'    => $pantry === [] ? 0 : $pantryDays,
                'warnings'       => $notes,
            ],
            'targets'  => $targets,
            'attempts' => $attempts,
        ];
    }

    /**
     * Claude designs seven dinners (one reference portion each) from foods every member may eat.
     *
     * @return array{dinners: array[], attempts: int}
     *   each dinner: name, prep, items[['ingredient_id','amount']], kcal (of the reference portion)
     */
    private function planDinners(array $members, callable $constraintsOf, Catalogue $cat, PlanVerifier $verifier, array $pantry = []): array
    {
        $allowed = null;
        foreach ($members as $m) {
            $a = $verifier->allowedIngredients($constraintsOf($m));
            $allowed = $allowed === null ? $a : array_intersect_key($allowed, $a);
        }
        if (count($allowed) < 8) {
            throw new ApiException(422, 'too_restrictive',
                'The eating styles and allergies in your family leave too few shared foods in our catalogue to build dinners everyone can eat. We are adding more foods all the time.');
        }

        $adults = count(array_filter($members, fn($m) => $m['kind'] === 'adult'));
        $brief = [
            'days'                 => self::DAYS,
            'reference_portion'    => ['kcal' => self::DINNER_REF_KCAL, 'min_protein_g' => 25],
            'people'               => ['adults' => $adults, 'children' => count($members) - $adults],
        ];
        $pantryRows = Pantry::forPrompt($pantry, $allowed);
        if ($pantryRows !== []) {
            $brief['pantry'] = $pantryRows;
            $brief['pantry_days'] = Pantry::planDays(count($pantry));
        }
        $prompt = "Plan the shared family dinners.\n\nBRIEF:\n" . json_encode($brief)
            . "\n\nINGREDIENT CATALOGUE (per100 = nutrients per 100 g or 100 ml):\n" . json_encode($this->catalogueForPrompt($allowed));

        $check = function (array $input) use ($allowed, $cat): array {
            $issues = [];
            $dinners = [];
            $days = $input['days'] ?? null;
            if (!is_array($days) || count($days) !== self::DAYS) {
                $issues[] = 'Submit exactly ' . self::DAYS . ' dinners, one per day.';
                $days = is_array($days) ? $days : [];
            }
            foreach (array_values($days) as $i => $d) {
                $label = 'Day ' . ($i + 1);
                $name = mb_substr(Input::text($d['name'] ?? ''), 0, 80);
                $items = [];
                foreach ((array)($d['items'] ?? []) as $it) {
                    $iid = (int)($it['ingredient_id'] ?? 0);
                    $amt = (float)($it['amount'] ?? 0);
                    if (!isset($allowed[$iid])) {
                        $issues[] = "$label '$name': ingredient_id $iid is not in the shared catalogue.";
                    } elseif ($amt <= 0 || $amt > PlanVerifier::MAX_AMOUNT) {
                        $issues[] = "$label '$name': the amount for {$allowed[$iid]['name']} must be between 1 and " . PlanVerifier::MAX_AMOUNT . '.';
                    } else {
                        $items[] = ['ingredient_id' => $iid, 'amount' => $amt];
                    }
                }
                if ($name === '' || $items === []) {
                    $issues[] = "$label needs a name and at least one ingredient.";
                    continue;
                }
                $n = $this->mealNutrients($cat, $items);
                if ($n['kcal'] < 500 || $n['kcal'] > 800) {
                    $issues[] = sprintf("%s '%s' is %d kcal for one portion; it must be between 500 and 800 kcal (aim for about %d).",
                        $label, $name, round($n['kcal']), self::DINNER_REF_KCAL);
                }
                if ($n['protein_g'] < 25) {
                    $issues[] = sprintf("%s '%s' has only %dg protein per portion; it needs at least 25g.", $label, $name, round($n['protein_g']));
                }
                $dinners[] = ['name' => $name, 'prep' => mb_substr(Input::text($d['prep'] ?? ''), 0, 300), 'items' => $items, 'kcal' => $n['kcal']];
            }
            if (count(array_unique(array_column($dinners, 'name'))) < 5) {
                $issues[] = 'Use at least 5 different dinners across the week.';
            }
            $issues = array_values(array_unique($issues));
            return [
                'ok'       => $issues === [],
                'value'    => $dinners,
                'issues'   => array_slice($issues, 0, 12),
                'feedback' => "The dinners were rejected. Fix every issue and call submit_dinners again with all seven dinners:\n- "
                    . implode("\n- ", array_slice($issues, 0, 12)),
            ];
        };

        $r = $this->converse(
            $this->dinnerSystemPrompt($pantryRows !== []), $this->dinnerTool(), $prompt, $check,
            'plan_unsatisfiable', 'We could not design dinners that suit everyone in your family this time. Please try again.'
        );
        return ['dinners' => $r['value'], 'attempts' => $r['attempts']];
    }

    /** The reference dinners scaled so each provides $targetKcal. One meal per day, in day order. */
    private function scaleDinners(array $dinners, float $targetKcal): array
    {
        $out = [];
        foreach ($dinners as $d) {
            $f = $targetKcal / max(1.0, (float)$d['kcal']);
            $items = [];
            foreach ($d['items'] as $it) {
                $a = $it['amount'] * $f;
                $a = $a >= 25 ? round($a / 5) * 5 : round($a);
                $items[] = ['ingredient_id' => $it['ingredient_id'], 'amount' => (float)min(PlanVerifier::MAX_AMOUNT, max(1.0, $a))];
            }
            $out[] = ['slot' => 'dinner', 'name' => $d['name'], 'prep' => $d['prep'], 'items' => $items];
        }
        return $out;
    }

    /** A child's week: the shared dinners at an age-appropriate size, plus a few care notes. */
    private function childEntry(array $m, ?array $dinners, Catalogue $cat, array $constraints, PlanVerifier $verifier): array
    {
        $allowed = $verifier->allowedIngredients($constraints);
        $kcal = Household::childDinnerKcal((int)$m['age']);
        $days = [];
        foreach ($this->scaleDinners($dinners ?? [], $kcal) as $i => $meal) {
            $items = [];
            foreach ($meal['items'] as $it) {
                $ing = $cat->get($it['ingredient_id']);
                // Defence in depth: the shared catalogue already excludes anything this child cannot eat.
                if ($ing === null || !isset($allowed[$it['ingredient_id']])) {
                    throw new \LogicException('A shared dinner contained a food that is not allowed for ' . $m['name']);
                }
                $items[] = ['ingredient_id' => $it['ingredient_id'], 'name' => $ing['name'], 'amount' => $it['amount'], 'unit' => $ing['unit']];
            }
            $n = $this->mealNutrients($cat, $meal['items']);
            $totals = [
                'kcal' => (int)round($n['kcal']), 'protein_g' => (int)round($n['protein_g']),
                'carb_g' => (int)round($n['carb_g']), 'fat_g' => (int)round($n['fat_g']),
            ];
            $days[] = [
                'day'    => $i + 1,
                'totals' => $totals,
                'meals'  => [['slot' => 'dinner', 'name' => $meal['name'], 'prep' => $meal['prep'], 'shared' => true] + $totals + ['items' => $items]],
            ];
        }
        $notes = ['Portions are a guide for the shared dinner. For growth or feeding concerns, ask a doctor or dietitian.'];
        if ((int)$m['age'] < 5) {
            $notes[] = 'Whole nuts and peanuts are a choking risk under 5. Chop nuts finely or use a thin spread of nut butter.';
        }
        return [
            'member_id' => $m['id'],
            'name'      => $m['name'],
            'kind'      => 'child',
            'is_owner'  => false,
            'age'       => (int)$m['age'],
            'days'      => $days,
            'warnings'  => $notes,
        ];
    }

    private function mealNutrients(Catalogue $cat, array $items): array
    {
        $t = array_fill_keys(Catalogue::NUTRIENTS, 0.0);
        foreach ($items as $it) {
            $ing = $cat->get((int)$it['ingredient_id']);
            if ($ing === null) {
                continue;
            }
            foreach (Catalogue::NUTRIENTS as $k) {
                $t[$k] += $ing['per100'][$k] * $it['amount'] / 100;
            }
        }
        return $t;
    }

    private function catalogueForPrompt(array $allowed): array
    {
        $catalogue = [];
        foreach ($allowed as $ing) {
            $catalogue[] = [
                'id'       => $ing['id'],
                'name'     => $ing['name'],
                'category' => $ing['category'],
                'unit'     => $ing['unit'],
                'per100'   => array_map(fn($v) => round($v, 1), $ing['per100']),
            ];
        }
        return $catalogue;
    }

    // =========================================================================================
    // Claude conversation
    // =========================================================================================

    /**
     * One person's week (or, with $fixedDinners, everything except dinner). Public so it can be
     * tested without a database.
     *
     * @param array[] $fixedDinners one dinner meal per day, already scaled for this person
     * @return array{plan: array, attempts: int}
     */
    public function run(array $targets, array $constraints, Catalogue $catalogue, array $fixedDinners = [], array $pantry = []): array
    {
        $verifier = new PlanVerifier($catalogue);
        $allowed = $verifier->allowedIngredients($constraints);
        if (count($allowed) < 8) {
            throw new ApiException(422, 'too_restrictive',
                'Your diet and exclusions leave too few foods in our catalogue to build a balanced week. We are adding more foods all the time.');
        }

        $pantryRows = Pantry::forPrompt($pantry, $allowed);
        $pantryDays = Pantry::planDays(count($pantry));

        $check = function (array $input, int $attempt = 1) use ($verifier, $targets, $constraints, $fixedDinners, $pantryRows, $pantryDays): array {
            if ($fixedDinners !== []) {
                $input = $this->withDinners($input, $fixedDinners);
            }
            $result = $verifier->check($input, $targets, $constraints);

            // Every food the person already has must be used in the first days. This never
            // beats the nutrition and safety rules: if only the pantry rule is still unmet
            // on the very last attempt, the plan is accepted with a note instead of failing.
            $left = $pantryRows === [] ? [] : Pantry::unused($input, $pantryRows, $pantryDays);
            if ($left !== []) {
                if ($result['ok'] && $attempt >= self::MAX_ATTEMPTS) {
                    $result['plan']['warnings'][] = 'We could not fit these foods you already have into your first '
                        . $pantryDays . ' days: ' . implode(', ', $left) . '. They will keep for later.';
                } else {
                    $result['ok'] = false;
                    $result['issues'][] = 'Pantry: the person already has ' . implode(', ', $left)
                        . ' and each one must appear in at least one meal on days 1 to ' . $pantryDays
                        . '. Use a sensible amount of each (a little oil, a few eggs), not necessarily all of it.';
                    $result['issue_count']++;
                }
            }
            return ['ok' => $result['ok'], 'value' => $result, 'issues' => $result['issues'], 'feedback' => $this->feedback($result)];
        };

        $r = $this->converse(
            $this->systemPrompt($fixedDinners !== [], $pantryRows !== []),
            $this->tool(),
            $this->userPrompt($targets, $constraints, $allowed, $fixedDinners, $catalogue, $pantryRows, $pantryDays),
            $check,
            'plan_unsatisfiable',
            'We could not build a week that meets all your nutrition targets and exclusions this time. Please try again, or relax a dislike or calorie target.'
        );
        return ['plan' => $r['value']['plan'], 'attempts' => $r['attempts']];
    }

    /** Drops any dinner Claude added and inserts the fixed dinner for each day. */
    private function withDinners(array $input, array $fixedDinners): array
    {
        $days = array_values((array)($input['days'] ?? []));
        foreach ($days as $i => $day) {
            $meals = array_values(array_filter((array)($day['meals'] ?? []), fn($m) => ($m['slot'] ?? '') !== 'dinner'));
            if (isset($fixedDinners[$i])) {
                $meals[] = $fixedDinners[$i];
            }
            $days[$i]['meals'] = $meals;
        }
        $input['days'] = $days;
        return $input;
    }

    /**
     * The shared loop: ask Claude to call $tool, run $check on what it submits, and on failure
     * send the exact problems back as a tool_result error (up to MAX_ATTEMPTS).
     *
     * @param callable $check fn(array $toolInput): array{ok: bool, value: mixed, issues: array, feedback: string}
     * @return array{value: mixed, attempts: int}
     */
    private function converse(string $system, array $tool, string $userPrompt, callable $check, string $failCode, string $failMessage): array
    {
        $messages = [['role' => 'user', 'content' => $userPrompt]];
        $last = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $resp = $this->claude->messages([
                'max_tokens'  => 16000,
                'system'      => $system,
                'tools'       => [$tool],
                // Forced tool use ("tool"/"any") is rejected by some models, so let the model
                // choose; the prompt says to answer only via the tool, and a text-only reply is
                // handled below by asking again.
                'tool_choice' => ['type' => 'auto'],
                'messages'    => $messages,
            ]);

            if (($resp['stop_reason'] ?? '') === 'max_tokens') {
                throw new ApiException(502, 'llm_truncated', 'The plan was too long to finish. Please try again.');
            }
            $call = null;
            foreach ($resp['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $tool['name']) {
                    $call = $block;
                    break;
                }
            }
            if ($call === null) {
                if ($attempt < self::MAX_ATTEMPTS && !empty($resp['content'])) {
                    $messages[] = ['role' => 'assistant', 'content' => $resp['content']];
                    $messages[] = ['role' => 'user', 'content' => 'Do not reply with text. Call the ' . $tool['name'] . ' tool now with the complete answer.'];
                    continue;
                }
                throw new ApiException(502, 'llm_no_plan', 'The meal-planning service did not return a plan. Please try again.');
            }

            $res = $check($call['input'] ?? [], $attempt);
            $last = $res;
            if ($res['ok']) {
                return ['value' => $res['value'], 'attempts' => $attempt];
            }

            $messages[] = ['role' => 'assistant', 'content' => $resp['content']];
            $messages[] = ['role' => 'user', 'content' => [[
                'type'        => 'tool_result',
                'tool_use_id' => $call['id'],
                'is_error'    => true,
                'content'     => $res['feedback'],
            ]]];
        }

        throw new ApiException(422, $failCode, $failMessage, ['issues' => $last['issues'] ?? []]);
    }

    private function feedback(array $result): string
    {
        $lines = array_map(fn($i) => '- ' . $i, $result['issues']);
        $more = $result['issue_count'] - count($result['issues']);
        $text = "The plan was rejected by the verifier. Fix every issue and call submit_meal_plan again with the full corrected plan:\n"
            . implode("\n", $lines);
        if ($more > 0) {
            $text .= "\n(+$more more issues of the same kind)";
        }
        if ($result['plan'] !== null) {
            $p = $result['plan'];
            $text .= sprintf(
                "\nFor reference, after scaling portions to the calorie target the plan averaged %d kcal, %dg protein, %dg carbs and %dg fat per day.",
                $p['weekly_avg']['kcal'], $p['weekly_avg']['protein_g'], $p['weekly_avg']['carb_g'],
                $p['weekly_avg']['fat_g']
            );
        }
        return $text;
    }

    private function dinnerSystemPrompt(bool $pantry = false): string
    {
        $base = <<<TXT
You are the meal-planning engine inside a family nutrition app. You respond ONLY by calling the submit_dinners tool.

Design one dinner for each day. The whole family eats the same dinner, so each is ONE reference portion of about 650 kcal with at least 25 g of protein; portions are scaled up or down for each person afterwards.

Rules:
- Use only ingredient ids from the supplied catalogue. It has already been filtered so every food is safe for everyone in the family.
- Amounts are in the ingredient's own unit (g or ml) for one reference portion.
- Vary the dinners across the week (at least 5 different dishes) and use plenty of vegetables. Include legumes, fish or liver and dairy or alternatives where available, so the week gives a good spread of iron, calcium, vitamin A, folate and zinc.
- Family-friendly, realistic home cooking with a South African feel where it fits. Keep each "prep" to one or two short sentences.
TXT;
        if ($pantry) {
            $base .= "\n" . self::DINNER_PANTRY_RULE;
        }
        return $base;
    }

    private function dinnerTool(): array
    {
        return [
            'name'         => 'submit_dinners',
            'description'  => 'Submit the shared family dinners, one per day.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'days' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'day'   => ['type' => 'integer'],
                                'name'  => ['type' => 'string'],
                                'prep'  => ['type' => 'string', 'description' => 'One or two short sentences.'],
                                'items' => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'ingredient_id' => ['type' => 'integer'],
                                            'amount'        => ['type' => 'number', 'description' => 'In the ingredient\'s unit (g or ml).'],
                                        ],
                                        'required'   => ['ingredient_id', 'amount'],
                                    ],
                                ],
                            ],
                            'required'   => ['day', 'name', 'items'],
                        ],
                    ],
                ],
                'required'   => ['days'],
            ],
        ];
    }

    private function systemPrompt(bool $dinnerFixed = false, bool $pantry = false): string
    {
        $base = <<<TXT
You are the meal-planning engine inside a nutrition app. You respond ONLY by calling the submit_meal_plan tool.

Rules:
- Use only ingredient ids from the supplied catalogue. It has already been filtered for this person's diet, allergies and dislikes.
- Plan exactly the requested number of days. Each day has breakfast, lunch, dinner and optionally one or two snacks.
- Amounts are in the ingredient's own unit (g or ml), for ONE person.
- Aim for the daily calorie and protein targets. Portions will be scaled automatically to the calorie target, so focus on choosing the right foods and sensible proportions.
- Cover the micronutrient targets over the week by varying vegetables, legumes, dairy or alternatives, fish and offal where allowed. Spread iron, calcium, vitamin A, vitamin C, folate, zinc, magnesium and potassium across the week.
- The daily targets are for the meals and drinks you plan. The person's own usual drinks (cooldrinks, tea and coffee, alcohol) have already been subtracted, so never add those.
- You may add a "drink" slot (milk, a smoothie, a protein shake, a glass of juice) when it helps. It counts toward the daily calories like any other meal. Drinks help most when the goal is "gain" or the person needs more protein; when the goal is "lose", keep drinks low in calories and avoid juice.
- Keep the weekly shopping list practical: reuse the same ingredients across several days and batch-cook, rather than using dozens of different items once.
- Meals should be realistic, tasty home cooking with a South African feel where it fits. Keep each "prep" to one or two short sentences.
TXT;
        if ($dinnerFixed) {
            $base .= "\n- The family's dinners are already decided (see fixed_dinners in the brief) and will be added to each day automatically. Do NOT include a dinner. Plan breakfast, lunch, snacks and optionally a drink so that each day, with that day's fixed dinner added, meets the daily targets.";
        }
        if ($pantry) {
            $base .= "\n" . self::PANTRY_RULE;
        }
        return $base;
    }

    private function userPrompt(array $targets, array $constraints, array $allowed, array $fixedDinners = [], ?Catalogue $cat = null, array $pantryRows = [], int $pantryDays = Pantry::PLAN_DAYS): string
    {
        $catalogue = $this->catalogueForPrompt($allowed);
        // Only de-identified numbers are sent to the API: no name, email or allergy labels.
        $brief = [
            'days'                          => $constraints['days'],
            'goal'                          => $constraints['goal'] ?? 'maintain',
            'daily_targets'                 => [
                'kcal' => $targets['kcal'], 'protein_g' => $targets['protein_g'], 'carb_g' => $targets['carb_g'],
                'fat_g' => $targets['fat_g'], 'fibre_g' => $targets['fibre_g'],
            ],
            'micronutrient_targets_per_day' => $targets['micros'],
        ];
        if ($pantryRows !== []) {
            $brief['pantry'] = $pantryRows;
            $brief['pantry_days'] = $pantryDays;
        }
        if ($fixedDinners !== [] && $cat !== null) {
            foreach ($fixedDinners as $i => $meal) {
                $n = $this->mealNutrients($cat, $meal['items']);
                $brief['fixed_dinners'][] = [
                    'day' => $i + 1, 'name' => $meal['name'], 'kcal' => (int)round($n['kcal']),
                    'protein_g' => (int)round($n['protein_g']), 'carb_g' => (int)round($n['carb_g']), 'fat_g' => (int)round($n['fat_g']),
                ];
            }
        }
        return "Plan the week for this person.\n\nBRIEF:\n" . json_encode($brief)
            . "\n\nINGREDIENT CATALOGUE (per100 = nutrients per 100 g or 100 ml; micronutrient keys end in _mg or _ug):\n"
            . json_encode($catalogue);
    }

    private function tool(): array
    {
        return [
            'name'         => self::TOOL_NAME,
            'description'  => 'Submit the complete weekly meal plan.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'days' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'day'   => ['type' => 'integer'],
                                'meals' => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'slot'  => ['type' => 'string', 'enum' => ['breakfast', 'lunch', 'dinner', 'snack', 'drink']],
                                            'name'  => ['type' => 'string'],
                                            'prep'  => ['type' => 'string', 'description' => 'One or two short sentences.'],
                                            'items' => [
                                                'type'  => 'array',
                                                'items' => [
                                                    'type'       => 'object',
                                                    'properties' => [
                                                        'ingredient_id' => ['type' => 'integer'],
                                                        'amount'        => ['type' => 'number', 'description' => 'In the ingredient\'s unit (g or ml).'],
                                                    ],
                                                    'required'   => ['ingredient_id', 'amount'],
                                                ],
                                            ],
                                        ],
                                        'required'   => ['slot', 'name', 'items'],
                                    ],
                                ],
                            ],
                            'required'   => ['day', 'meals'],
                        ],
                    ],
                ],
                'required'   => ['days'],
            ],
        ];
    }
}
