<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Deterministic calorie / macro / micronutrient targets.
 * Claude never does this arithmetic; it only receives the result.
 */
final class TargetCalculator
{
    public const ACTIVITY = [
        'sedentary'   => 1.2,
        'light'       => 1.375,
        'moderate'    => 1.55,
        'active'      => 1.725,
        'very_active' => 1.9,
    ];
    public const MIN_KCAL = ['male' => 1500, 'female' => 1200];
    public const MAX_KCAL = 4500;
    private const KCAL_PER_KG = 7700;

    public static function age(string $birthDate, ?\DateTimeImmutable $today = null): int
    {
        $today ??= new \DateTimeImmutable('today');
        return (new \DateTimeImmutable($birthDate))->diff($today)->y;
    }

    /**
     * @param array $p profile row: sex, birth_date, height_cm, weight_kg, activity_level,
     *                 goal_type, goal_weight_kg, weekly_rate_kg, calorie_override,
     *                 protein_pct, carb_pct, fat_pct, usual_drinks (optional list, see Drinks::resolve)
     * @throws \DomainException when the person should speak to a professional instead
     */
    public static function compute(array $p, ?\DateTimeImmutable $today = null): array
    {
        $age = self::age((string)$p['birth_date'], $today);
        if ($age < 18) {
            throw new \DomainException(
                'NutriPlan is for adults (18+). Please speak to a doctor or registered dietitian about nutrition for children and teenagers.'
            );
        }

        $sex  = (string)$p['sex'];
        $w    = (float)$p['weight_kg'];
        $h    = (float)$p['height_cm'];
        $goal = (string)($p['goal_type'] ?? 'maintain');

        // Mifflin-St Jeor
        $bmr  = 10 * $w + 6.25 * $h - 5 * $age + ($sex === 'male' ? 5 : -161);
        $tdee = $bmr * self::ACTIVITY[$p['activity_level']];

        $warnings = [];
        $rate     = max(0.0, min(1.0, (float)($p['weekly_rate_kg'] ?? 0.5)));
        $delta    = $rate * self::KCAL_PER_KG / 7;

        if ($goal === 'lose') {
            $kcal = $tdee - min($delta, 0.25 * $tdee);          // never more than a 25% deficit
        } elseif ($goal === 'gain') {
            $kcal = $tdee + min($delta, 0.15 * $tdee);          // gentle surplus
        } else {
            $kcal = $tdee;
        }

        if (!empty($p['calorie_override'])) {
            $kcal = (float)$p['calorie_override'];
            $warnings[] = 'Using the daily calorie target you chose.';
        }

        $floor = self::MIN_KCAL[$sex];
        if ($kcal < $floor) {
            $kcal = $floor;
            $warnings[] = "Your target was raised to the safe minimum of {$floor} kcal/day. Eating less than this should only be done under medical supervision.";
        }
        if ($kcal > self::MAX_KCAL) {
            $kcal = self::MAX_KCAL;
            $warnings[] = 'Your target was capped at ' . self::MAX_KCAL . ' kcal/day.';
        }
        $kcal = (int)(round($kcal / 10) * 10);

        // Macros: user split if it adds up to 100, otherwise protein by body weight.
        $pcts = [$p['protein_pct'] ?? null, $p['carb_pct'] ?? null, $p['fat_pct'] ?? null];
        if (!in_array(null, $pcts, true) && abs(array_sum($pcts) - 100) <= 1) {
            $protein = $kcal * $pcts[0] / 100 / 4;
            $carb    = $kcal * $pcts[1] / 100 / 4;
            $fat     = $kcal * $pcts[2] / 100 / 9;
        } else {
            $perKg   = $goal === 'maintain' ? 1.2 : 1.6;
            $protein = min($perKg * $w, 0.35 * $kcal / 4);
            $fat     = 0.28 * $kcal / 9;
            $carb    = max(0.0, ($kcal - $protein * 4 - $fat * 9) / 4);
        }

        $weeks = null;
        if ($goal !== 'maintain' && !empty($p['goal_weight_kg']) && $rate > 0) {
            $weeks = (int)ceil(abs($w - (float)$p['goal_weight_kg']) / $rate);
        }

        // Usual drinks (cooldrinks, sweet coffee, wine ...) come out of the day's target first.
        // `food` is what the planned meals themselves must hit. Alcohol calories displace carbohydrate.
        $drinks = Drinks::allowance((array)($p['usual_drinks'] ?? []), $kcal);
        if ($drinks['kcal'] > 0) {
            $food = [
                'kcal'      => $kcal - $drinks['kcal'],
                'protein_g' => (int)max(0, round($protein - $drinks['protein_g'])),
                'carb_g'    => (int)max(20, round($carb - $drinks['carb_g'] - $drinks['alcohol_kcal'] / 4)),
                'fat_g'     => (int)max(0, round($fat - $drinks['fat_g'])),
            ];
            if ($drinks['capped']) {
                $warnings[] = sprintf(
                    'Your usual drinks add up to about %d kcal a day, which is a lot of your target. We set aside %d kcal for them so there is room for proper meals. Cutting back on sugary drinks or alcohol would make your goal easier.',
                    $drinks['uncapped_kcal'], $drinks['kcal']
                );
            }
        } else {
            $food = [
                'kcal'      => $kcal,
                'protein_g' => (int)round($protein),
                'carb_g'    => (int)round($carb),
                'fat_g'     => (int)round($fat),
            ];
        }

        return [
            'kcal'          => $kcal,
            'protein_g'     => (int)round($protein),
            'carb_g'        => (int)round($carb),
            'fat_g'         => (int)round($fat),
            'fibre_g'       => (int)round(14 * $kcal / 1000),
            'bmr'           => (int)round($bmr),
            'tdee'          => (int)round($tdee),
            'age'           => $age,
            'weeks_to_goal' => $weeks,
            'drinks'        => $drinks,
            'food'          => $food,
            'micros'        => self::microTargets($sex, $age),
            'warnings'      => $warnings,
        ];
    }

    /** Approximate adult RDA/AI values. Have a dietitian review before launch. */
    public static function microTargets(string $sex, int $age): array
    {
        $m = $sex === 'male';
        return [
            'iron_mg'      => $m ? 8 : ($age > 50 ? 8 : 18),
            'calcium_mg'   => ($age > 70 || (!$m && $age > 50)) ? 1200 : 1000,
            'vit_c_mg'     => $m ? 90 : 75,
            'vit_a_ug'     => $m ? 900 : 700,
            'b12_ug'       => 2.4,
            'folate_ug'    => 400,
            'vit_d_ug'     => $age > 70 ? 20 : 15,
            'zinc_mg'      => $m ? 11 : 8,
            'magnesium_mg' => $m ? ($age > 30 ? 420 : 400) : ($age > 30 ? 320 : 310),
            'potassium_mg' => $m ? 3400 : 2600,
        ];
    }
}
