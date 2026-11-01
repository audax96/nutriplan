<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * Drinks people already have (cooldrinks, sweet coffee, wine ...) and the daily calorie
 * allowance they take out of the day's target, so the meals are planned around them.
 *
 * Values are approximate per-serving figures for common South African serving sizes.
 * Have a dietitian review them, and let people enter their own drink if theirs differs.
 */
final class Drinks
{
    /** Usual drinks may use at most this share of the daily calories, so there is room for real food. */
    public const MAX_SHARE = 0.30;
    public const MAX_SERVINGS_PER_WEEK = 70;

    /**
     * key => [name, serving, carb_g, protein_g, fat_g, alcohol_kcal]
     * kcal per serving = 4 x carbs + 4 x protein + 9 x fat + alcohol_kcal
     */
    public const CATALOGUE = [
        'soft_drink'      => ['Cooldrink / soda', '330 ml can', 35, 0, 0, 0],
        'fruit_juice'     => ['Fruit juice', '250 ml glass', 26, 1, 0, 0],
        'shop_smoothie'   => ['Shop-bought smoothie', '350 ml', 55, 4, 1, 0],
        'energy_drink'    => ['Energy drink', '250 ml can', 27, 0, 0, 0],
        'sports_drink'    => ['Sports drink', '500 ml bottle', 30, 0, 0, 0],
        'tea_milk'        => ['Tea or coffee with a splash of milk', '1 cup', 1, 0.5, 0.5, 0],
        'tea_milk_sugar'  => ['Tea or coffee with milk and 1 tsp sugar', '1 cup', 6, 0.5, 0.5, 0],
        'tea_milk_sugar2' => ['Tea or coffee with milk and 2 tsp sugar', '1 cup', 10, 0.5, 0.5, 0],
        'latte'           => ['Latte or cappuccino (full-cream milk)', 'medium, about 350 ml', 15, 9, 8, 0],
        'flavoured_milk'  => ['Flavoured milk', '250 ml', 28, 8, 5, 0],
        'hot_chocolate'   => ['Hot chocolate made with milk', '1 cup', 24, 8, 6, 0],
        'milkshake'       => ['Milkshake', '300 ml', 45, 9, 10, 0],
        'beer'            => ['Beer', '340 ml', 11, 1.5, 0, 94],
        'light_beer'      => ['Light beer', '340 ml', 5, 0.7, 0, 75],
        'cider_rtd'       => ['Cider or ready-to-drink', '330 ml', 28, 0, 0, 91],
        'wine'            => ['Wine', '150 ml glass', 4, 0, 0, 104],
        'spirit_diet'     => ['Spirit with soda or a diet mixer', '25 ml tot', 0, 0, 0, 55],
        'spirit_regular'  => ['Spirit with a regular cooldrink mixer', '25 ml tot + 200 ml mixer', 21, 0, 0, 55],
    ];

    /** The list the app shows in its picker. */
    public static function options(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => [$name, $serving, $c, $p, $f, $alc]) {
            $out[] = [
                'key'     => $key,
                'name'    => $name,
                'serving' => $serving,
                'kcal'    => (int)round(4 * $c + 4 * $p + 9 * $f + $alc),
                'alcohol' => $alc > 0,
            ];
        }
        return $out;
    }

    /**
     * Turns saved or submitted drinks into per-serving nutrient lines.
     * A custom drink only has calories: non-alcoholic ones are treated as sugary (carbohydrate),
     * alcoholic ones as pure alcohol calories.
     *
     * @param array $usual rows with drink_key | name + kcal_per_serving + is_alcohol, and servings_per_week
     */
    public static function resolve(array $usual): array
    {
        $lines = [];
        foreach ($usual as $u) {
            $servings = (float)($u['servings_per_week'] ?? 0);
            if ($servings <= 0) {
                continue;
            }
            $key = (string)($u['drink_key'] ?? '');
            if ($key !== '' && isset(self::CATALOGUE[$key])) {
                [$name, , $c, $p, $f, $alc] = self::CATALOGUE[$key];
            } else {
                $kcal = (float)($u['kcal_per_serving'] ?? 0);
                if ($kcal <= 0) {
                    continue;
                }
                $name = (string)($u['name'] ?? 'Other drink');
                if (filter_var($u['is_alcohol'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    [$c, $p, $f, $alc] = [0.0, 0.0, 0.0, $kcal];
                } else {
                    [$c, $p, $f, $alc] = [$kcal / 4, 0.0, 0.0, 0.0];
                }
            }
            $lines[] = [
                'name'              => $name,
                'servings_per_week' => $servings,
                'carb_g'            => (float)$c,
                'protein_g'         => (float)$p,
                'fat_g'             => (float)$f,
                'alcohol_kcal'      => (float)$alc,
                'kcal'              => 4 * $c + 4 * $p + 9 * $f + $alc,
            ];
        }
        return $lines;
    }

    /**
     * Average daily nutrients from the person's usual drinks. If they exceed MAX_SHARE of the
     * daily calories the allowance is scaled down to that cap (and `capped` is set), so the
     * meals always keep at least 70% of the day's calories.
     */
    public static function allowance(array $usual, float $totalKcal): array
    {
        $day = ['kcal' => 0.0, 'carb_g' => 0.0, 'protein_g' => 0.0, 'fat_g' => 0.0, 'alcohol_kcal' => 0.0];
        $items = [];
        foreach (self::resolve($usual) as $l) {
            $perDay = $l['servings_per_week'] / 7;
            foreach ($day as $k => $_) {
                $day[$k] += $l[$k] * $perDay;
            }
            $items[] = [
                'name'              => $l['name'],
                'servings_per_week' => $l['servings_per_week'],
                'kcal_per_serving'  => (int)round($l['kcal']),
            ];
        }

        $uncapped = $day['kcal'];
        $cap = self::MAX_SHARE * $totalKcal;
        $capped = $uncapped > $cap;
        if ($capped) {
            $f = $cap / $uncapped;
            foreach ($day as $k => $v) {
                $day[$k] = $v * $f;
            }
        }

        return [
            'kcal'          => (int)round($day['kcal']),
            'carb_g'        => round($day['carb_g'], 1),
            'protein_g'     => round($day['protein_g'], 1),
            'fat_g'         => round($day['fat_g'], 1),
            'alcohol_kcal'  => (int)round($day['alcohol_kcal']),
            'uncapped_kcal' => (int)round($uncapped),
            'capped'        => $capped,
            'items'         => $items,
        ];
    }
}
