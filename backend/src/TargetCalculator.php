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
     *                 target_protein_g, target_carb_g, target_fat_g (optional grams per day),
     *                 usual_drinks (optional list, see Drinks::resolve)
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

        // Grams the person chose (any of the three). With all three, they decide the calories.
        $given = self::gramTargets($p);
        if (count($given) === 3) {
            $kcal = self::kcalFromGrams($given['protein'], $given['carb'], $given['fat']);
            $warnings[] = 'Your daily calories come from the protein, carbs and fat you chose.';
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

        // Macros in grams per day. Chosen grams are kept exactly. Of the blanks, carbs (or else
        // fat, or else protein) take whatever calories are left; any other blank gets the usual
        // default (protein by body weight, fat about 28% of calories).
        $perKg = $goal === 'maintain' ? 1.2 : 1.6;
        $g = $given + [
            'protein' => min($perKg * $w, 0.35 * $kcal / 4),
            'fat'     => 0.28 * $kcal / 9,
            'carb'    => 0.0,
        ];
        $filler = null;
        foreach (['carb', 'fat', 'protein'] as $k) {
            if (!isset($given[$k])) {
                $filler = $k;
                break;
            }
        }
        if ($filler !== null) {
            $min = ['carb' => 20.0, 'fat' => 20.0, 'protein' => 0.8 * $w][$filler];
            $per = $filler === 'fat' ? 9 : 4;
            $others = self::kcalFromGrams($g['protein'], $g['carb'], $g['fat'], false) - $g[$filler] * $per;
            $g[$filler] = ($kcal - $others) / $per;
            if ($g[$filler] < $min) {
                // The chosen grams leave too little room: keep them and raise the calories instead.
                $g[$filler] = $min;
                $kcal = self::kcalFromGrams($g['protein'], $g['carb'], $g['fat']);
                $warnings[] = "The protein, carbs and fat you chose need more energy than your calorie target, so your daily target is {$kcal} kcal.";
            }
        } elseif ($kcal !== self::kcalFromGrams($g['protein'], $g['carb'], $g['fat'])) {
            // All three chosen but the safety floor or cap moved the calories: carbs absorb the difference.
            $g['carb'] = max(20.0, $g['carb'] + ($kcal - self::kcalFromGrams($g['protein'], $g['carb'], $g['fat'], false)) / 4);
        }
        ['protein' => $protein, 'carb' => $carb, 'fat' => $fat] = $g;

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

    /** Energy of a day's macros: 4 kcal per gram of protein and carbs, 9 per gram of fat. */
    public static function kcalFromGrams(float $protein, float $carb, float $fat, bool $round = true): int|float
    {
        $k = 4 * $protein + 4 * $carb + 9 * $fat;
        return $round ? (int)(round($k / 10) * 10) : $k;
    }

    /** @return array<string,float> the macro grams the person chose: any of protein, carb, fat */
    public static function gramTargets(array $p): array
    {
        $out = [];
        foreach (['protein' => 'target_protein_g', 'carb' => 'target_carb_g', 'fat' => 'target_fat_g'] as $k => $col) {
            if (isset($p[$col]) && $p[$col] !== '' && is_numeric($p[$col])) {
                $out[$k] = (float)$p[$col];
            }
        }
        return $out;
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
