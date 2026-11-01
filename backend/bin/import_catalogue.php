<?php
// Imports ingredients and allergen tags from a CSV.
// Usage: php bin/import_catalogue.php ../database/catalogue_seed.csv
//
// Re-running is safe: rows are matched on ingredient name and updated in place.
// Replace the seed CSV with SAMRC/USDA-derived nutrient data before real users see any plan.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use NutriPlan\Catalogue;
use NutriPlan\Db;

$path = $argv[1] ?? '';
if (!is_file($path)) {
    fwrite(STDERR, "Usage: php bin/import_catalogue.php <file.csv>\n");
    exit(1);
}

$pdo = Db::pdo();
$pdo->beginTransaction();

$fh = fopen($path, 'r');
$header = fgetcsv($fh);
$count = 0;

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($header)) {
        continue;
    }
    $r = array_combine($header, $row);

    $cols = array_merge(['name', 'category', 'unit'], Catalogue::NUTRIENTS);
    $vals = array_map(fn($c) => $r[$c], $cols);

    $existing = $pdo->prepare('SELECT id FROM ingredients WHERE name = ?');
    $existing->execute([$r['name']]);
    $id = $existing->fetchColumn();

    if ($id) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $pdo->prepare("UPDATE ingredients SET $set WHERE id = ?")->execute([...$vals, $id]);
    } else {
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO ingredients (' . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
        $id = (int)$pdo->lastInsertId();
    }

    $pdo->prepare('DELETE FROM ingredient_allergens WHERE ingredient_id = ?')->execute([$id]);
    foreach (array_filter(array_map('trim', explode(';', $r['allergens']))) as $a) {
        $pdo->prepare('INSERT INTO ingredient_allergens (ingredient_id, allergen) VALUES (?, ?)')->execute([$id, $a]);
    }
    $count++;
}
fclose($fh);
$pdo->commit();
echo "Imported $count ingredients.\n";
