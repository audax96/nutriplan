<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Takes the plan Claude proposes and checks it against the real nutrition
 * data. Claude is good at meal ideas and unreliable at arithmetic, so everything that
 * matters (allergens, diet, calories, macros, vitamins/minerals) is verified here.
 *
 * Portions are first scaled per day to hit the calorie target, so Claude only has to
 * get the food choices roughly right.
 */
final class PlanVerifier
{
    public const KCAL_TOLERANCE = 0.07;  // each day must land within +/-7% of target after scaling
    public const MICRO_WARN     = 0.90;  // weekly average below 90% of RDA -> warning
    public const MICRO_FAIL     = 0.60;  // below 60% -> plan is rejected and sent back to Claude
    public const MAX_AMOUNT     = 1500;  // sanity cap for a single ingredient in one meal (g or ml)
    private const MAX_ISSUES    = 12;

    /** Words people type for allergies mapped to the allergen tags in the database. */
    private const SYNONYMS = [
        'dairy' => ['milk'], 'lactose' => ['milk'], 'milk' => ['milk'],
        'egg' => ['egg'], 'eggs' => ['egg'],
        'wheat' => ['gluten'], 'gluten' => ['gluten'],
        'peanut' => ['peanut'], 'peanuts' => ['peanut'], 'groundnut' => ['peanut'], 'groundnuts' => ['peanut'],
        'nut' => ['tree_nut', 'peanut'], 'nuts' => ['tree_nut', 'peanut'],
        'tree nut' => ['tree_nut'], 'tree nuts' => ['tree_nut'], 'tree_nut' => ['tree_nut'],
        'fish' => ['fish'], 'seafood' => ['fish', 'shellfish'], 'shellfish' => ['shellfish'],
        'soy' => ['soy'], 'soya' => ['soy'], 'sesame' => ['sesame'],
    ];

    /** Food categories excluded by each diet. */
    private const DIET_BLOCK = [
        'omnivore'    => [],
        'pescatarian' => ['meat'],
        'vegetarian'  => ['meat', 'fish'],
        'vegan'       => ['meat', 'fish', 'dairy', 'egg'],
    ];

    public function __construct(private Catalogue $cat)
    {
    }

    /** Ingredients that survive the person's diet, allergies and dislikes. */
    public function allowedIngredients(array $constraints): array
    {
        $out = [];
        foreach ($this->cat->all() as $id => $ing) {
            if ($this->blockReason($ing, $constraints) === null) {
                $out[$id] = $ing;
            }
        }
        return $out;
    }

    private function blockReason(array $ing, array $c): ?string
    {
        $diet = (string)($c['diet_type'] ?? 'omnivore');
        if (in_array($ing['category'], self::DIET_BLOCK[$diet] ?? [], true)) {
            return "not $diet";
        }
        foreach ($c['exclusions'] ?? [] as $ex) {
            if ($this->matches($ing, (string)$ex['name'])) {
                return "matches the exclusion '{$ex['name']}'";
            }
        }
        return null;
    }

    /** Deliberately over-cautious: a false positive only removes an ingredient. */
    private function matches(array $ing, string $term): bool
    {
        $t = mb_strtolower(trim($term));
        if ($t === '') {
            return false;
        }
        $tags = self::SYNONYMS[$t] ?? [str_replace(' ', '_', $t)];
        foreach ($tags as $tag) {
            if (in_array($tag, $ing['allergens'], true)) {
                return true;
            }
        }
        $name = mb_strtolower($ing['name']);
        if (str_contains($name, $t)) {
            return true;
        }
        $singular = rtrim($t, 's');
        return strlen($singular) >= 3 && str_contains($name, $singular);
    }

    /**
     * @param array $raw         the tool input from Claude: ['days' => [['meals' => [...]]]]
     * @param array $targets     output of TargetCalculator::compute()
     * @param array $constraints diet_type, exclusions[], days
     */
    public function check(array $raw, array $targets, array $constraints): array
    {
        $issues = [];
        $warnings = [];
        $expectedDays = (int)($constraints['days'] ?? 7);
        $rawDays = $raw['days'] ?? null;

        if (!is_array($rawDays) || $rawDays === []) {
            return $this->result(["The plan has no days. Submit all $expectedDays days."], [], null);
        }
        if (count($rawDays) !== $expectedDays) {
            $issues[] = "The plan must contain exactly $expectedDays days but has " . count($rawDays) . '.';
        }

        // 1. Structure, catalogue membership, diet and allergen checks
        $days = [];
        foreach (array_values($rawDays) as $di => $rawDay) {
            $label = 'Day ' . ($di + 1);
            $meals = [];
            foreach (($rawDay['meals'] ?? []) as $rawMeal) {
                // Claude's text is untrusted: plain single-line text with a sensible length.
                $mealName = mb_substr(Input::text($rawMeal['name'] ?? ''), 0, 80) ?: 'Meal';
                $items = [];
                foreach (($rawMeal['items'] ?? []) as $it) {
                    $id  = (int)($it['ingredient_id'] ?? 0);
                    $amt = (float)($it['amount'] ?? 0);
                    $ing = $this->cat->get($id);
                    if ($ing === null) {
                        $issues[] = "$label '$mealName': ingredient_id $id is not in the catalogue.";
                        continue;
                    }
                    $reason = $this->blockReason($ing, $constraints);
                    if ($reason !== null) {
                        $issues[] = "$label '$mealName' uses {$ing['name']}, which is not allowed ($reason).";
                        continue;
                    }
                    if ($amt <= 0 || $amt > self::MAX_AMOUNT) {
                        $issues[] = "$label '$mealName': the amount for {$ing['name']} must be between 1 and "
                            . self::MAX_AMOUNT . " {$ing['unit']}.";
                        continue;
                    }
                    $items[] = ['ingredient_id' => $id, 'amount' => $amt];
                }
                if ($items !== []) {
                    $meals[] = [
                        'slot'  => in_array($rawMeal['slot'] ?? '', ['breakfast', 'lunch', 'dinner', 'snack', 'drink'], true)
                            ? $rawMeal['slot'] : 'snack',
                        'name'  => $mealName,
                        'prep'  => mb_substr(Input::text($rawMeal['prep'] ?? ''), 0, 300),
                        'items' => $items,
                    ];
                }
            }
            if ($meals === []) {
                $issues[] = "$label has no valid meals.";
            }
            $days[] = ['day' => $di + 1, 'meals' => $meals];
        }

        // 2. Scale each day's portions toward the calorie target
        $targetKcal = (float)$targets['kcal'];
        foreach ($days as $i => $day) {
            $kcal = $this->dayTotals($day)['kcal'];
            if ($kcal <= 0) {
                continue;
            }
            $f = $targetKcal / $kcal;
            if (abs($f - 1) > 0.02) {
                $days[$i] = $this->scaleDay($day, max(0.75, min(1.35, $f)));
            }
        }

        // 3. Enrich with names and nutrient totals, then check calories and macros
        $enriched = [];
        $sum = array_fill_keys(Catalogue::NUTRIENTS, 0.0);
        $usage = [];
        foreach ($days as $day) {
            $totals = $this->dayTotals($day);
            foreach ($totals as $k => $v) {
                $sum[$k] += $v;
            }
            if ($day['meals'] !== [] && abs($totals['kcal'] - $targetKcal) / $targetKcal > self::KCAL_TOLERANCE) {
                $issues[] = sprintf(
                    'Day %d totals %d kcal after portion scaling but the target is %d kcal. %s',
                    $day['day'],
                    round($totals['kcal']),
                    $targetKcal,
                    $totals['kcal'] < $targetKcal
                        ? 'Add more food or more energy-dense items.'
                        : 'Reduce the amount of food or use lighter items.'
                );
            }
            $enrichedMeals = [];
            foreach ($day['meals'] as $meal) {
                $mealTotals = array_fill_keys(['kcal', 'protein_g', 'carb_g', 'fat_g'], 0.0);
                $items = [];
                foreach ($meal['items'] as $it) {
                    $ing = $this->cat->get($it['ingredient_id']);
                    $n = $this->nutrients($ing, $it['amount']);
                    foreach ($mealTotals as $k => $_) {
                        $mealTotals[$k] += $n[$k];
                    }
                    $usage[$it['ingredient_id']] = ($usage[$it['ingredient_id']] ?? 0.0) + $it['amount'];
                    $items[] = [
                        'ingredient_id' => $it['ingredient_id'],
                        'name'          => $ing['name'],
                        'amount'        => $it['amount'],
                        'unit'          => $ing['unit'],
                    ];
                }
                $enrichedMeals[] = [
                    'slot'      => $meal['slot'],
                    'name'      => $meal['name'],
                    'prep'      => $meal['prep'],
                    'kcal'      => (int)round($mealTotals['kcal']),
                    'protein_g' => (int)round($mealTotals['protein_g']),
                    'carb_g'    => (int)round($mealTotals['carb_g']),
                    'fat_g'     => (int)round($mealTotals['fat_g']),
                    'items'     => $items,
                ];
            }
            $enriched[] = [
                'day'    => $day['day'],
                'totals' => [
                    'kcal'      => (int)round($totals['kcal']),
                    'protein_g' => (int)round($totals['protein_g']),
                    'carb_g'    => (int)round($totals['carb_g']),
                    'fat_g'     => (int)round($totals['fat_g']),
                ],
                'meals'  => $enrichedMeals,
            ];
        }

        $n = max(1, count($days));
        $avg = array_map(fn($v) => $v / $n, $sum);

        // Protein is a floor; carbs and fat only need to be in a sensible band.
        $ratio = $avg['protein_g'] / max(1, $targets['protein_g']);
        if ($ratio < 0.90) {
            $issues[] = sprintf('Average protein is %dg/day against a target of %dg. Add lean protein (meat, fish, eggs, dairy, legumes, tofu).',
                round($avg['protein_g']), $targets['protein_g']);
        } elseif ($ratio > 1.35) {
            $warnings[] = 'Protein is well above the target.';
        }
        foreach (['carb_g' => 'carbohydrate', 'fat_g' => 'fat'] as $k => $label) {
            $r = $avg[$k] / max(1, $targets[$k]);
            if ($r < 0.70 || $r > 1.30) {
                $issues[] = sprintf('Average %s is %dg/day against a target of %dg (%s).',
                    $label, round($avg[$k]), $targets[$k], $r < 1 ? 'too low' : 'too high');
            } elseif ($r < 0.85 || $r > 1.15) {
                $warnings[] = sprintf('Average %s (%dg) is a little away from the %dg target.', $label, round($avg[$k]), $targets[$k]);
            }
        }

        // 4. Vitamins and minerals: weekly average against the RDA
        $allowed = null;
        $micros = [];
        foreach ($targets['micros'] as $key => $target) {
            $a = $avg[$key];
            $pct = $target > 0 ? $a / $target : 1.0;
            $micros[$key] = [
                'label'  => Catalogue::LABELS[$key] ?? $key,
                'avg'    => round($a, 1),
                'target' => $target,
                'pct'    => (int)round($pct * 100),
            ];
            $label = Catalogue::LABELS[$key] ?? $key;
            if ($key === 'vit_d_ug') {
                if ($pct < self::MICRO_WARN) {
                    $warnings[] = 'Vitamin D is hard to get from food alone. Sensible sun exposure or a supplement may help; ask your doctor or pharmacist.';
                }
            } elseif ($key === 'b12_ug' && ($constraints['diet_type'] ?? '') === 'vegan') {
                $warnings[] = 'A vegan diet needs a vitamin B12 supplement or fortified foods.';
            } elseif ($pct < self::MICRO_FAIL) {
                $allowed ??= $this->allowedIngredients($constraints);
                $issues[] = sprintf(
                    'Weekly average %s is %s against a target of %s (%d%%). Add rich sources such as %s.',
                    $label, round($a, 1), $target, round($pct * 100), $this->topSources($allowed, $key)
                );
            } elseif ($pct < self::MICRO_WARN) {
                $warnings[] = sprintf('%s averages %d%% of the recommended intake.', $label, round($pct * 100));
            }
        }
        $fibreTarget = (float)$targets['fibre_g'];
        if ($fibreTarget > 0 && $avg['fibre_g'] < 0.8 * $fibreTarget) {
            $warnings[] = sprintf('Fibre averages %dg/day (aim for about %dg).', round($avg['fibre_g']), $fibreTarget);
        }

        // 5. Weekly shopping list: the total amount of each ingredient across the plan
        $shoppingList = $this->buildShoppingList($usage);

        $plan = [
            'days'       => $enriched,
            'weekly_avg' => [
                'kcal'      => (int)round($avg['kcal']),
                'protein_g' => (int)round($avg['protein_g']),
                'carb_g'    => (int)round($avg['carb_g']),
                'fat_g'     => (int)round($avg['fat_g']),
                'fibre_g'   => (int)round($avg['fibre_g']),
            ],
            'micros'     => $micros,
            'shopping_list' => $shoppingList,
        ];
        return $this->result($issues, $warnings, $plan);
    }

    private function result(array $issues, array $warnings, ?array $plan): array
    {
        $issues = array_values(array_unique($issues));
        $warnings = array_values(array_unique($warnings));
        if ($plan !== null) {
            $plan['warnings'] = $warnings;
        }
        return [
            'ok'          => $issues === [],
            'issues'      => array_slice($issues, 0, self::MAX_ISSUES),
            'issue_count' => count($issues),
            'warnings'    => $warnings,
            'plan'        => $plan,
        ];
    }

    private function nutrients(array $ing, float $amount): array
    {
        $out = [];
        foreach (Catalogue::NUTRIENTS as $k) {
            $out[$k] = $ing['per100'][$k] * $amount / 100;
        }
        return $out;
    }

    private function dayTotals(array $day): array
    {
        $t = array_fill_keys(Catalogue::NUTRIENTS, 0.0);
        foreach ($day['meals'] as $meal) {
            foreach ($meal['items'] as $it) {
                foreach ($this->nutrients($this->cat->get($it['ingredient_id']), $it['amount']) as $k => $v) {
                    $t[$k] += $v;
                }
            }
        }
        return $t;
    }

    private function scaleDay(array $day, float $f): array
    {
        foreach ($day['meals'] as $mi => $meal) {
            foreach ($meal['items'] as $ii => $it) {
                $a = $it['amount'] * $f;
                $day['meals'][$mi]['items'][$ii]['amount'] = max(1.0, $a >= 25 ? round($a / 5) * 5 : round($a));
            }
        }
        return $day;
    }

    /**
     * Total amount of each ingredient for the week, rounded up to the next 10 g/ml so the
     * list never comes up short. Sorted by category then name.
     */
    public function buildShoppingList(array $usage): array
    {
        $lines = [];
        foreach ($usage as $id => $needed) {
            $ing = $this->cat->get($id);
            $lines[] = [
                'ingredient_id' => $id,
                'ingredient'    => $ing['name'],
                'category'      => $ing['category'],
                'unit'          => $ing['unit'],
                'amount'        => (int)(ceil($needed / 10 - 1e-9) * 10),
            ];
        }
        usort($lines, fn($a, $b) => [$a['category'], $a['ingredient']] <=> [$b['category'], $b['ingredient']]);
        return $lines;
    }

    private function topSources(array $allowed, string $key): string
    {
        uasort($allowed, fn($a, $b) => $b['per100'][$key] <=> $a['per100'][$key]);
        return implode(', ', array_map(fn($i) => $i['name'], array_slice($allowed, 0, 4)));
    }
}
