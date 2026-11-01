<?php
declare(strict_types=1);

namespace NutriPlan;

/** Turns the plan's shopping list into an aisle-grouped list of weekly quantities. */
final class GroceryService
{
    private const AISLES = [
        'meat'      => 'Meat & poultry',
        'fish'      => 'Fish & seafood',
        'dairy'     => 'Dairy',
        'egg'       => 'Dairy',
        'vegetable' => 'Fresh produce & frozen veg',
        'fruit'     => 'Fresh produce & frozen veg',
        'grain'     => 'Grains, bread & pasta',
        'legume'    => 'Tins, legumes & tofu',
        'nut_seed'  => 'Nuts & spreads',
        'fat'       => 'Oils & fats',
        'beverage'  => 'Drinks',
        'other'     => 'Other',
    ];

    public function forPlan(array $stored): array
    {
        $groups = [];
        foreach ($stored['plan']['shopping_list'] as $line) {
            $line['display'] = self::formatAmount((float)$line['amount'], (string)$line['unit']);
            $groups[self::AISLES[$line['category']] ?? 'Other'][] = $line;
        }
        ksort($groups);

        $out = [];
        $people = count($stored['plan']['members'] ?? []);
        $text = ['NutriPlan shopping list (7 days' . ($people > 1 ? ", $people people" : '') . ')'];
        foreach ($groups as $aisle => $lines) {
            $out[] = ['aisle' => $aisle, 'items' => $lines];
            $text[] = '';
            $text[] = strtoupper($aisle);
            foreach ($lines as $l) {
                $text[] = sprintf('- %s: %s', $l['ingredient'], $l['display']);
            }
        }

        // Foods the person already has, used in the first days. They are not on the list above.
        $home = [];
        foreach ($stored['plan']['pantry_used'] ?? [] as $p) {
            $p['display'] = self::formatAmount((float)$p['amount'], (string)$p['unit']);
            $home[] = $p;
        }
        if ($home !== []) {
            $text[] = '';
            $text[] = 'ALREADY AT HOME (not on the list)';
            foreach ($home as $h) {
                $text[] = sprintf('- %s: %s', $h['ingredient'], $h['display']);
            }
        }

        return [
            'plan_id'    => $stored['id'],
            'groups'     => $out,
            'at_home'    => $home,
            'share_text' => implode("\n", $text),
        ];
    }

    /** 1050 g -> "1.05 kg", 500 ml -> "500 ml", 2000 ml -> "2 L". */
    public static function formatAmount(float $amount, string $unit): string
    {
        if ($amount >= 1000) {
            $big = rtrim(rtrim(number_format($amount / 1000, 2, '.', ''), '0'), '.');
            return $big . ($unit === 'ml' ? ' L' : ' kg');
        }
        return (int)round($amount) . ' ' . $unit;
    }
}
