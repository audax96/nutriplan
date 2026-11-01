<?php
declare(strict_types=1);

namespace NutriPlan;

/**
 * The nutrition database in memory. Claude may only plan with items from here, which
 * is what makes nutrient verification exact.
 */
final class Catalogue
{
    public const NUTRIENTS = [
        'kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g',
        'iron_mg', 'calcium_mg', 'vit_c_mg', 'vit_a_ug', 'b12_ug',
        'folate_ug', 'vit_d_ug', 'zinc_mg', 'magnesium_mg', 'potassium_mg',
    ];

    public const LABELS = [
        'iron_mg' => 'Iron', 'calcium_mg' => 'Calcium', 'vit_c_mg' => 'Vitamin C',
        'vit_a_ug' => 'Vitamin A', 'b12_ug' => 'Vitamin B12', 'folate_ug' => 'Folate',
        'vit_d_ug' => 'Vitamin D', 'zinc_mg' => 'Zinc', 'magnesium_mg' => 'Magnesium',
        'potassium_mg' => 'Potassium', 'fibre_g' => 'Fibre',
    ];

    /** @var array<int,array> */
    private array $items = [];

    private function __construct()
    {
    }

    public static function fromDb(\PDO $pdo): self
    {
        $cat = new self();

        $allergens = [];
        foreach ($pdo->query('SELECT ingredient_id, allergen FROM ingredient_allergens') as $a) {
            $allergens[(int)$a['ingredient_id']][] = $a['allergen'];
        }
        foreach ($pdo->query('SELECT * FROM ingredients ORDER BY id') as $r) {
            $id = (int)$r['id'];
            $cat->items[$id] = self::build($id, $r, $allergens[$id] ?? []);
        }
        return $cat;
    }

    /** Used by the offline smoke test. */
    public static function fromCsv(string $path): self
    {
        $cat = new self();
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $id = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }
            $r = array_combine($header, $row);
            $id++;
            $cat->items[$id] = self::build(
                $id,
                $r,
                array_values(array_filter(array_map('trim', explode(';', $r['allergens']))))
            );
        }
        fclose($fh);
        return $cat;
    }

    private static function build(int $id, array $r, array $allergens): array
    {
        $per100 = [];
        foreach (self::NUTRIENTS as $n) {
            $per100[$n] = (float)$r[$n];
        }
        return [
            'id'        => $id,
            'name'      => $r['name'],
            'category'  => $r['category'],
            'unit'      => $r['unit'],
            'per100'    => $per100,
            'allergens' => $allergens,
        ];
    }

    public function get(int $id): ?array
    {
        return $this->items[$id] ?? null;
    }

    /** @return array<int,array> */
    public function all(): array
    {
        return $this->items;
    }
}
