<?php
// Offline smoke test: no database, no Claude API key needed.
// Run:  php tests/smoke.php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use NutriPlan\ApiException;
use NutriPlan\Auth;
use NutriPlan\Catalogue;
use NutriPlan\ClaudeClient;
use NutriPlan\Drinks;
use NutriPlan\GroceryService;
use NutriPlan\Household;
use NutriPlan\Input;
use NutriPlan\Pantry;
use NutriPlan\PlanService;
use NutriPlan\PlanVerifier;
use NutriPlan\ProfileRepo;
use NutriPlan\TargetCalculator;

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . ($cond || $detail === '' ? '' : "  -> $detail") . "\n";
    if (!$cond) {
        $failures++;
    }
}

$cat = Catalogue::fromCsv(__DIR__ . '/../../database/catalogue_seed.csv');
$ids = [];
foreach ($cat->all() as $id => $i) {
    $ids[$i['name']] = $id;
}
$id = fn(string $n) => $ids[$n] ?? throw new RuntimeException("Unknown ingredient: $n");
$it = fn(string $n, float $g) => ['ingredient_id' => $id($n), 'amount' => $g];

echo "Catalogue: " . count($cat->all()) . " ingredients\n\n";

// ---- Targets ---------------------------------------------------------------
echo "TargetCalculator\n";
$profile = [
    'sex' => 'female', 'birth_date' => '1996-03-10', 'height_cm' => 165, 'weight_kg' => 65,
    'activity_level' => 'light', 'goal_type' => 'lose', 'goal_weight_kg' => 60, 'weekly_rate_kg' => 0.5,
    'calorie_override' => null, 'protein_pct' => null, 'carb_pct' => null, 'fat_pct' => null,
];
$t = TargetCalculator::compute($profile, new DateTimeImmutable('2026-10-08'));
printf("  age %d, BMR %d, TDEE %d -> target %d kcal, P %dg C %dg F %dg, ~%s weeks to goal\n",
    $t['age'], $t['bmr'], $t['tdee'], $t['kcal'], $t['protein_g'], $t['carb_g'], $t['fat_g'], $t['weeks_to_goal']);
check('BMR matches Mifflin-St Jeor by hand (1370)', $t['bmr'] === 1370, (string)$t['bmr']);
check('deficit is applied', $t['kcal'] < $t['tdee']);
check('macros add up to about the calorie target',
    abs($t['protein_g'] * 4 + $t['carb_g'] * 4 + $t['fat_g'] * 9 - $t['kcal']) <= 25);

$low = TargetCalculator::compute(['calorie_override' => 900] + $profile, new DateTimeImmutable('2026-10-08'));
check('calorie floor enforced (1200 for women)', $low['kcal'] === 1200 && $low['warnings'] !== []);

try {
    TargetCalculator::compute(['birth_date' => '2012-01-01'] + $profile, new DateTimeImmutable('2026-10-08'));
    check('under-18 refused', false);
} catch (DomainException) {
    check('under-18 refused', true);
}

// ---- Profile validation ------------------------------------------------------
echo "\nProfileRepo::validate\n";
try {
    ProfileRepo::validate(['sex' => 'x']);
    check('bad input rejected', false);
} catch (ApiException $e) {
    check('bad input rejected with field errors', isset($e->extra['fields']['sex'], $e->extra['fields']['consent'], $e->extra['fields']['height_cm']));
}
$valid = ProfileRepo::validate([
    'sex' => 'female', 'birth_date' => '1996-03-10', 'height_cm' => '165', 'weight_kg' => '65',
    'activity_level' => 'light', 'goal_weight_kg' => '60', 'consent' => true,
    'exclusions' => [['name' => ' Peanuts ', 'kind' => 'allergy'], ['name' => '', 'kind' => 'dislike']],
]);
check('goal_type derived from goal weight', $valid['goal_type'] === 'lose');
check('blank exclusions dropped and names trimmed', $valid['exclusions'] === [['name' => 'Peanuts', 'kind' => 'allergy']]);

// ---- Verifier ------------------------------------------------------------------
// ---- Usual drinks --------------------------------------------------------------------------------------------------
echo "\nUsual drinks\n";
$today = new DateTimeImmutable('2026-10-08');
$plain = TargetCalculator::compute($profile, $today);
check('no usual drinks: meal targets equal the daily totals',
    $plain['drinks']['kcal'] === 0 && $plain['food']['kcal'] === $plain['kcal'] && $plain['food']['protein_g'] === $plain['protein_g']);

$wd = TargetCalculator::compute($profile + ['usual_drinks' => [
    ['drink_key' => 'soft_drink', 'servings_per_week' => 14],   // 2 cans a day = 280 kcal/day
    ['drink_key' => 'wine', 'servings_per_week' => 3],          // 3 glasses a week = about 51 kcal/day
]], $today);
printf("  drinks %d kcal/day (alcohol %d) -> meals plan for %d of %d kcal; meal macros P%d C%d F%d\n",
    $wd['drinks']['kcal'], $wd['drinks']['alcohol_kcal'], $wd['food']['kcal'], $wd['kcal'],
    $wd['food']['protein_g'], $wd['food']['carb_g'], $wd['food']['fat_g']);
check('drink calories are averaged per day (14 cans + 3 wines a week = about 331 kcal)',
    abs($wd['drinks']['kcal'] - 331) <= 1, (string)$wd['drinks']['kcal']);
check('meal calories = daily total minus drinks', $wd['food']['kcal'] === $wd['kcal'] - $wd['drinks']['kcal']);
check('the daily total itself is unchanged by drinks', $wd['kcal'] === $plain['kcal']);
check('meal macros plus drinks add back up to the daily calories',
    abs(4 * $wd['food']['protein_g'] + 4 * $wd['food']['carb_g'] + 9 * $wd['food']['fat_g'] + $wd['drinks']['kcal'] - $wd['kcal']) <= 20);
check('sugar and alcohol calories come out of the carbohydrate budget', $wd['food']['carb_g'] < $plain['carb_g'] - 70);

$heavy = TargetCalculator::compute($profile + ['usual_drinks' => [['drink_key' => 'beer', 'servings_per_week' => 56]]], $today);
check('drinks are capped at 30% of the day so meals keep room',
    $heavy['drinks']['capped'] && abs($heavy['drinks']['kcal'] - 0.30 * $heavy['kcal']) <= 1 && $heavy['food']['kcal'] >= 0.69 * $heavy['kcal']);
check('a capped allowance adds a warning', str_contains(implode(' ', $heavy['warnings']), 'usual drinks'));

$custom = Drinks::allowance([
    ['name' => 'Protein coffee', 'kcal_per_serving' => 120, 'servings_per_week' => 7],
    ['name' => 'Gin', 'kcal_per_serving' => 70, 'is_alcohol' => true, 'servings_per_week' => 7],
], 2000);
check('custom drinks: sugary ones count as carbs, alcoholic ones as alcohol',
    $custom['kcal'] === 190 && $custom['alcohol_kcal'] === 70 && abs($custom['carb_g'] - 30) < 0.1);

$dbRows = Drinks::allowance([['drink_key' => 'beer', 'name' => 'Beer', 'kcal_per_serving' => null, 'is_alcohol' => '1', 'servings_per_week' => '7.0']], 2000);
check('rows as returned by the database (strings) are understood', $dbRows['kcal'] === 144);

$opts = Drinks::options();
$byKey = array_column($opts, null, 'key');
check('drink picker list: calories computed from macros, alcohol flagged',
    count($opts) === 18 && $byKey['soft_drink']['kcal'] === 140 && $byKey['wine']['kcal'] === 120
    && count(array_filter($opts, fn($o) => $o['alcohol'])) === 6);

$pt = PlanService::planTargets($wd);
check('planTargets uses the meal targets and keeps everything else',
    $pt['kcal'] === $wd['food']['kcal'] && $pt['micros'] === $wd['micros'] && $pt['fibre_g'] === $wd['fibre_g']);

$in = ['sex' => 'female', 'birth_date' => '1996-03-10', 'height_cm' => '165', 'weight_kg' => '65', 'activity_level' => 'light', 'consent' => true];
$vd = ProfileRepo::validate($in + ['usual_drinks' => [
    ['drink_key' => 'beer', 'servings_per_week' => '4'],
    ['name' => 'Gin', 'kcal_per_serving' => '70', 'is_alcohol' => true, 'servings_per_week' => 2],
]]);
check('usual drinks validate; catalogue names and alcohol flag come from the server',
    count($vd['usual_drinks']) === 2 && $vd['usual_drinks'][0]['name'] === 'Beer'
    && $vd['usual_drinks'][0]['is_alcohol'] === true && $vd['usual_drinks'][1]['drink_key'] === null);
foreach ([
    'an unknown drink'          => [['drink_key' => 'unicorn_tears', 'servings_per_week' => 1]],
    'zero servings'             => [['drink_key' => 'beer', 'servings_per_week' => 0]],
    'more than 70 servings'     => [['drink_key' => 'beer', 'servings_per_week' => 100]],
    'a custom drink without calories' => [['name' => 'Mystery', 'servings_per_week' => 3]],
] as $label => $drinksIn) {
    try {
        ProfileRepo::validate($in + ['usual_drinks' => $drinksIn]);
        check("rejects $label", false);
    } catch (ApiException $e) {
        check("rejects $label", isset($e->extra['fields']['usual_drinks']));
    }
}

echo "\nPlanVerifier\n";
// A fixture week built to be protein-forward and cheap-ish: eggs, chicken, pilchards, livers, lentils.
$dayA = [
    ['slot' => 'breakfast', 'name' => 'Eggs, avocado and toast', 'items' => [$it('Eggs (whole)', 165), $it('Whole-wheat bread', 40), $it('Avocado', 50), $it('Tomatoes', 80)]],
    ['slot' => 'lunch', 'name' => 'Chicken and spinach rice bowl', 'items' => [$it('Chicken breast (raw)', 150), $it('Spinach', 80), $it('Tomatoes', 80), $it('Brown rice (dry)', 30), $it('Olive oil', 8)]],
    ['slot' => 'dinner', 'name' => 'Pilchards, sweet potato and broccoli', 'items' => [$it('Pilchards in tomato sauce', 120), $it('Sweet potato (raw)', 120), $it('Broccoli', 150), $it('Olive oil', 8)]],
    ['slot' => 'snack', 'name' => 'Cottage cheese and apple', 'items' => [$it('Cottage cheese (low fat)', 150), $it('Apples', 100)]],
    ['slot' => 'drink', 'name' => 'Glass of orange juice', 'items' => [$it('Orange juice (100%)', 200)]],
];
$dayB = [
    ['slot' => 'breakfast', 'name' => 'Yoghurt oats with banana', 'items' => [$it('Yoghurt (plain low fat)', 150), $it('Rolled oats (dry)', 40), $it('Bananas', 80), $it('Almonds', 15)]],
    ['slot' => 'lunch', 'name' => 'Chicken wrap-style plate', 'items' => [$it('Chicken breast (raw)', 150), $it('Whole-wheat bread', 40), $it('Cabbage', 100), $it('Carrots', 80), $it('Olive oil', 8)]],
    ['slot' => 'dinner', 'name' => 'Lentil and butternut stew', 'items' => [$it('Lentils (dry)', 60), $it('Butternut', 150), $it('Chicken livers (raw)', 80), $it('Onions', 60), $it('Spinach', 80), $it('Sunflower oil', 8)]],
    ['slot' => 'snack', 'name' => 'Boiled eggs', 'items' => [$it('Eggs (whole)', 110)]],
];
$dayC = [
    ['slot' => 'breakfast', 'name' => 'Mushroom omelette', 'items' => [$it('Eggs (whole)', 165), $it('Whole-wheat bread', 40), $it('Mushrooms', 80), $it('Avocado', 40)]],
    ['slot' => 'lunch', 'name' => 'Chicken livers and sweet potato', 'items' => [$it('Chicken livers (raw)', 100), $it('Sweet potato (raw)', 150), $it('Frozen peas', 80), $it('Sunflower oil', 8)]],
    ['slot' => 'dinner', 'name' => 'Chicken and veg pasta', 'items' => [$it('Chicken breast (raw)', 150), $it('Whole-wheat pasta (dry)', 40), $it('Frozen mixed vegetables', 150), $it('Tomatoes', 100), $it('Cheddar cheese', 20)]],
    ['slot' => 'snack', 'name' => 'Cottage cheese and orange', 'items' => [$it('Cottage cheese (low fat)', 150), $it('Oranges', 150)]],
];
$week = ['days' => []];
foreach ([$dayA, $dayB, $dayC, $dayA, $dayB, $dayC, $dayA] as $i => $meals) {
    $week['days'][] = ['day' => $i + 1, 'meals' => $meals];
}
$constraints = ['diet_type' => 'omnivore', 'exclusions' => [], 'days' => 7];
$v = new PlanVerifier($cat);

$r = $v->check($week, $t, $constraints);
printf("  weekly avg: %s\n  shopping list: %d items, issues: %d, warnings: %d\n",
    json_encode($r['plan']['weekly_avg']), count($r['plan']['shopping_list']), $r['issue_count'], count($r['warnings']));
foreach ($r['issues'] as $x) { echo "    issue: $x\n"; }
foreach ($r['warnings'] as $x) { echo "    warn:  $x\n"; }
foreach ($r['plan']['days'] as $d) {
    check("day {$d['day']} within 7% of {$t['kcal']} kcal after scaling", abs($d['totals']['kcal'] - $t['kcal']) / $t['kcal'] <= 0.07, (string)$d['totals']['kcal']);
}
check('fixture week passes every hard check', $r['ok']);

// Shopping list: amounts must cover what the meals use, rounded up to 10 g/ml.
$used = [];
foreach ($r['plan']['days'] as $d) {
    foreach ($d['meals'] as $m) {
        foreach ($m['items'] as $i) {
            $used[$i['ingredient_id']] = ($used[$i['ingredient_id']] ?? 0) + $i['amount'];
        }
    }
}
$listOk = count($r['plan']['shopping_list']) === count($used);
foreach ($r['plan']['shopping_list'] as $l) {
    $u = $used[$l['ingredient_id']] ?? -1;
    $listOk = $listOk && $l['amount'] >= $u && $l['amount'] - $u < 10 + 1e-6 && $l['amount'] % 10 === 0;
}
check('shopping list covers every ingredient, rounded up to 10 g/ml', $listOk);

$stored = ['id' => 1, 'plan' => $r['plan']];
$grocery = (new GroceryService())->forPlan($stored);
check('grocery list is grouped by aisle with readable amounts',
    count($grocery['groups']) >= 4 && str_contains($grocery['share_text'], 'Eggs (whole): ') && !str_contains($grocery['share_text'], '(R'));
check('amount formatting', GroceryService::formatAmount(1050, 'g') === '1.05 kg'
    && GroceryService::formatAmount(2000, 'ml') === '2 L'
    && GroceryService::formatAmount(500, 'g') === '500 g'
    && GroceryService::formatAmount(1000, 'g') === '1 kg');

$peanutWeek = $week;
$peanutWeek['days'][0]['meals'][0]['items'][] = $it('Peanut butter', 20);
$p = $v->check($peanutWeek, $t, ['exclusions' => [['name' => 'peanuts', 'kind' => 'allergy']]] + $constraints);
check('peanut allergy blocks peanut butter', !$p['ok'] && str_contains(implode(' ', $p['issues']), 'Peanut butter'));

$d = $v->check($week, $t, ['exclusions' => [['name' => 'dairy', 'kind' => 'allergy']]] + $constraints);
check('dairy allergy blocks yoghurt, cottage cheese and cheddar',
    !$d['ok'] && str_contains(implode(' ', $d['issues']), 'Yoghurt') && str_contains(implode(' ', $d['issues']), 'Cottage cheese'));

$g = $v->check($week, $t, ['exclusions' => [['name' => 'mushrooms', 'kind' => 'dislike']]] + $constraints);
check('a dislike is also enforced', !$g['ok'] && str_contains(implode(' ', $g['issues']), 'Mushrooms'));

$veg = $v->check($week, $t, ['diet_type' => 'vegetarian'] + $constraints);
check('vegetarian diet blocks meat and fish', !$veg['ok'] && str_contains(implode(' ', $veg['issues']), 'not vegetarian'));

$allowedVegan = $v->allowedIngredients(['diet_type' => 'vegan', 'exclusions' => []]);
check('vegan catalogue has no animal products', count(array_filter($allowedVegan, fn($i) => in_array($i['category'], ['meat', 'fish', 'dairy', 'egg'], true))) === 0);

$veganNames = array_column($allowedVegan, 'name');
check('vegan can use soy milk but not whey', in_array('Soy milk (fortified)', $veganNames, true) && !in_array('Whey protein powder', $veganNames, true));
$noSoy = array_column($v->allowedIngredients(['diet_type' => 'omnivore', 'exclusions' => [['name' => 'soy', 'kind' => 'allergy']]]), 'name');
check('soy allergy removes soy milk and tofu', !in_array('Soy milk (fortified)', $noSoy, true) && !in_array('Firm tofu', $noSoy, true));
$noGluten = array_column($v->allowedIngredients(['diet_type' => 'omnivore', 'exclusions' => [['name' => 'gluten', 'kind' => 'allergy']]]), 'name');
check('gluten allergy removes oat milk', !in_array('Oat milk (fortified)', $noGluten, true));
check('a planned "drink" slot is accepted and counted', in_array('drink', array_column($r['plan']['days'][0]['meals'], 'slot'), true));

$bogus = $v->check(['days' => [['day' => 1, 'meals' => [['slot' => 'lunch', 'name' => 'X', 'items' => [['ingredient_id' => 9999, 'amount' => 100]]]]]]], $t, $constraints);
check('unknown ingredient ids are rejected', !$bogus['ok'] && str_contains(implode(' ', $bogus['issues']), '9999'));

// ---- The Claude loop with a fake client --------------------------------------------------
echo "\nPlanService::run with a fake Claude\n";
final class FakeClaude extends ClaudeClient
{
    public array $calls = [];
    public function __construct(private array $plans)
    {
    }
    public function messages(array $payload): array
    {
        $this->calls[] = $payload;
        if ($this->plans === []) {
            throw new RuntimeException('fake out of plans');
        }
        $plan = array_shift($this->plans);
        if ($plan === 'TEXT') {
            return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Here is your plan in words...']]];
        }
        return [
            'stop_reason' => 'tool_use',
            'content'     => [['type' => 'tool_use', 'id' => 'toolu_' . count($this->calls), 'name' => 'submit_meal_plan', 'input' => $plan]],
        ];
    }
}

$fake = new FakeClaude([$peanutWeek, $week]);
$svc = new PlanService(new PDO('sqlite::memory:'), $fake);
$out = $svc->run($t, ['exclusions' => [['name' => 'peanuts', 'kind' => 'allergy']], 'goal' => 'gain'] + $constraints, $cat);
check('bad first plan is retried and the second plan accepted', $out['attempts'] === 2);
$second = $fake->calls[1]['messages'] ?? [];
$feedback = end($second)['content'][0] ?? [];
check('retry carries tool_result error feedback', ($feedback['type'] ?? '') === 'tool_result' && ($feedback['is_error'] ?? false) === true);
check('prompt catalogue was pre-filtered (no peanut butter sent)', !str_contains($fake->calls[0]['messages'][0]['content'], 'Peanut butter'));
check('prompt contains no personal identifiers or allergy labels', !str_contains($fake->calls[0]['messages'][0]['content'], 'peanuts'));
check('prompt carries the goal so Claude can decide how to use drinks', str_contains($fake->calls[0]['messages'][0]['content'], '"goal":"gain"'));
$slotEnum = $fake->calls[0]['tools'][0]['input_schema']['properties']['days']['items']['properties']['meals']['items']['properties']['slot']['enum'];
check('the plan tool allows a "drink" slot', in_array('drink', $slotEnum, true));

// A model that answers in text first (tool_choice is auto) is asked again, and the retry succeeds.
$fake3 = new FakeClaude(['TEXT', $week]);
$out3 = (new PlanService(new PDO('sqlite::memory:'), $fake3))->run($t, ['exclusions' => [], 'goal' => 'maintain'] + $constraints, $cat);
check('text-only reply is nudged and the next tool call accepted', $out3['attempts'] === 2);
check('requests use automatic tool choice (forced choice is rejected by some models)', ($fake3->calls[0]['tool_choice']['type'] ?? '') === 'auto');

// Claude keeps proposing peanut butter for a peanut-allergic user: it must never get through.
$fake2 = new FakeClaude([$peanutWeek, $peanutWeek, $peanutWeek]);
try {
    (new PlanService(new PDO('sqlite::memory:'), $fake2))
        ->run($t, ['exclusions' => [['name' => 'peanuts', 'kind' => 'allergy']]] + $constraints, $cat);
    check('a plan that keeps breaking an allergy is never returned', false);
} catch (ApiException $e) {
    check('allergen plan raises plan_unsatisfiable after 3 attempts',
        $e->errorCode === 'plan_unsatisfiable' && count($fake2->calls) === 3);
    echo "  user-facing message: {$e->getMessage()}\n";
}


// ---- Family / household ------------------------------------------------------------------------------
echo "\nHousehold planning (shared dinners)\n";
$tool = static fn(array $p): string => $p['tools'][0]['name'];
final class FakeHousehold extends ClaudeClient
{
    public array $calls = [];
    public function __construct(private array $queues)
    {
    }
    public function messages(array $payload): array
    {
        $name = $payload['tools'][0]['name'];
        $this->calls[] = $payload;
        $input = array_shift($this->queues[$name]) ?? throw new RuntimeException("fake out of $name");
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'toolu_h' . count($this->calls), 'name' => $name, 'input' => $input]]];
    }
}
$dinners = [
 ['day'=>1,'name'=>'Lentil and spinach dal with rice','prep'=>'Simmer.','items'=>[$it('Lentils (dry)',70),$it('Brown rice (dry)',60),$it('Spinach',100),$it('Onions',60),$it('Tomatoes',100),$it('Olive oil',10)]],
 ['day'=>2,'name'=>'Chickpea, tofu and butternut curry','prep'=>'Simmer.','items'=>[$it('Chickpeas (dry)',60),$it('Firm tofu',100),$it('Butternut',150),$it('Onions',60),$it('Tomatoes',100),$it('Brown rice (dry)',40),$it('Olive oil',8)]],
 ['day'=>3,'name'=>'Egg and veg pasta bake','prep'=>'Bake.','items'=>[$it('Eggs (whole)',150),$it('Whole-wheat pasta (dry)',60),$it('Frozen mixed vegetables',150),$it('Tomatoes',100),$it('Cheddar cheese',30),$it('Olive oil',8)]],
 ['day'=>4,'name'=>'Tofu and broccoli stir-fry','prep'=>'Stir-fry.','items'=>[$it('Firm tofu',150),$it('Broccoli',150),$it('Carrots',80),$it('Brown rice (dry)',70),$it('Cabbage',100),$it('Olive oil',10)]],
 ['day'=>5,'name'=>'Bean and potato bake','prep'=>'Bake.','items'=>[$it('Baked beans (canned)',250),$it('Potatoes (raw)',250),$it('Cheddar cheese',40),$it('Spinach',100),$it('Onions',60)]],
 ['day'=>6,'name'=>'Mushroom and pea omelette','prep'=>'Fry.','items'=>[$it('Eggs (whole)',200),$it('Mushrooms',100),$it('Frozen peas',100),$it('Sweet potato (raw)',250),$it('Cottage cheese (low fat)',100),$it('Olive oil',8)]],
 ['day'=>7,'name'=>'Lentil and sweet potato stew','prep'=>'Simmer.','items'=>[$it('Lentils (dry)',70),$it('Sweet potato (raw)',200),$it('Carrots',80),$it('Onions',60),$it('Spinach',100),$it('Yoghurt (plain low fat)',100),$it('Olive oil',10)]],
];
$omniDay = [
 [ 'slot'=>'breakfast','name'=>'Eggs, avocado and toast','items'=>[$it('Eggs (whole)',165),$it('Whole-wheat bread',40),$it('Avocado',50),$it('Tomatoes',80)] ],
 [ 'slot'=>'lunch','name'=>'Chicken and spinach rice bowl','items'=>[$it('Chicken breast (raw)',170),$it('Spinach',80),$it('Tomatoes',80),$it('Brown rice (dry)',40),$it('Olive oil',8)] ],
 [ 'slot'=>'snack','name'=>'Cottage cheese and apple','items'=>[$it('Cottage cheese (low fat)',150),$it('Apples',100)] ],
];
$omniDay2 = [
 [ 'slot'=>'breakfast','name'=>'Yoghurt oats with banana','items'=>[$it('Yoghurt (plain low fat)',150),$it('Rolled oats (dry)',40),$it('Bananas',80),$it('Almonds',15)] ],
 [ 'slot'=>'lunch','name'=>'Chicken livers and sweet potato','items'=>[$it('Chicken livers (raw)',100),$it('Sweet potato (raw)',150),$it('Frozen peas',80),$it('Chicken breast (raw)',100),$it('Sunflower oil',8)] ],
 [ 'slot'=>'snack','name'=>'Boiled eggs','items'=>[$it('Eggs (whole)',110)] ],
];
$vegDay = [
 [ 'slot'=>'breakfast','name'=>'Egg and cheese toast','items'=>[$it('Eggs (whole)',200),$it('Whole-wheat bread',90),$it('Cheddar cheese',20),$it('Tomatoes',100),$it('Avocado',50)] ],
 [ 'slot'=>'lunch','name'=>'Lentil and chickpea salad','items'=>[$it('Lentils (dry)',50),$it('Chickpeas (dry)',40),$it('Spinach',100),$it('Tomatoes',100),$it('Olive oil',12),$it('Brown rice (dry)',50)] ],
 [ 'slot'=>'snack','name'=>'Yoghurt, oats and almonds','items'=>[$it('Yoghurt (plain low fat)',250),$it('Rolled oats (dry)',50),$it('Almonds',25),$it('Bananas',100)] ],
];
$vegDay2 = [
 [ 'slot'=>'breakfast','name'=>'Oat porridge with milk and banana','items'=>[$it('Rolled oats (dry)',80),$it('Milk (low fat)',300),$it('Bananas',100),$it('Almonds',20)] ],
 [ 'slot'=>'lunch','name'=>'Tofu and veg bowl','items'=>[$it('Firm tofu',150),$it('Broccoli',150),$it('Brown rice (dry)',70),$it('Carrots',80),$it('Olive oil',10),$it('Frozen peas',80)] ],
 [ 'slot'=>'snack','name'=>'Cottage cheese and orange','items'=>[$it('Cottage cheese (low fat)',200),$it('Oranges',150),$it('Eggs (whole)',110)] ],
];
$mk = function(array $sets): array { $w=['days'=>[]]; foreach ($sets as $i=>$m) { $w['days'][]=['day'=>$i+1,'meals'=>$m]; } return $w; };
$weekA = $mk([$omniDay,$omniDay2,$omniDay,$omniDay2,$omniDay,$omniDay2,$omniDay]);
$weekB = $mk([$vegDay,$vegDay2,$vegDay,$vegDay2,$vegDay,$vegDay2,$vegDay]);

$members = [
 ['id'=>1,'name'=>'Ana','kind'=>'adult','is_owner'=>true,'profile'=>['sex'=>'female','birth_date'=>'1996-03-10','height_cm'=>165,'weight_kg'=>65,'activity_level'=>'light','goal_type'=>'lose','goal_weight_kg'=>60,'weekly_rate_kg'=>0.5,'calorie_override'=>null,'protein_pct'=>null,'carb_pct'=>null,'fat_pct'=>null,'diet_type'=>'omnivore','pregnant_or_breastfeeding'=>0,'exclusions'=>[],'usual_drinks'=>[]]],
 ['id'=>2,'name'=>'Ben','kind'=>'adult','is_owner'=>false,'profile'=>['sex'=>'male','birth_date'=>'1990-05-05','height_cm'=>180,'weight_kg'=>85,'activity_level'=>'moderate','goal_type'=>'maintain','goal_weight_kg'=>null,'weekly_rate_kg'=>0.5,'calorie_override'=>null,'protein_pct'=>null,'carb_pct'=>null,'fat_pct'=>null,'diet_type'=>'vegetarian','pregnant_or_breastfeeding'=>0,'exclusions'=>[],'usual_drinks'=>[]]],
 ['id'=>3,'name'=>'Kai','kind'=>'child','is_owner'=>false,'age'=>6,'diet_type'=>'omnivore','exclusions'=>[['name'=>'peanuts','kind'=>'allergy']]],
];

$fh = new FakeHousehold(['submit_dinners' => [['days' => $dinners]], 'submit_meal_plan' => [$weekA, $weekB]]);
$progressLog = [];
$hh = (new PlanService(new PDO('sqlite::memory:'), $fh))->buildHousehold($members, $cat, function (string $m) use (&$progressLog) { $progressLog[] = $m; });

check('household: one dinner call, then one plan call per adult (3 calls)', count($fh->calls) === 3 && $tool($fh->calls[0]) === 'submit_dinners' && $tool($fh->calls[1]) === 'submit_meal_plan');
check('progress is reported (dinners first, then each adult)', count($progressLog) === 3 && str_contains($progressLog[1], 'Ana') && str_contains($progressLog[2], 'Ben'));
check('every member gets an entry, children flagged', array_column($hh['plan']['members'], 'kind') === ['adult', 'adult', 'child']);
check('the shared dinner pool was chosen from foods everyone may eat (no meat, no peanut)',
    !str_contains($fh->calls[0]['messages'][0]['content'], 'Chicken breast') && !str_contains($fh->calls[0]['messages'][0]['content'], 'Peanut butter'));
check('adult prompts carry the fixed dinners and say nothing identifying',
    str_contains($fh->calls[1]['messages'][0]['content'], 'fixed_dinners')
    && !str_contains($fh->calls[1]['messages'][0]['content'], 'Ana') && !str_contains($fh->calls[2]['messages'][0]['content'], 'Kai')
    && !str_contains($fh->calls[2]['messages'][0]['content'], 'peanut'));
check('Claude is told not to plan dinner when dinners are fixed', str_contains($fh->calls[1]['system'], 'Do NOT include a dinner'));

[$ana, $ben, $kai] = $hh['plan']['members'];
$dinnerOf = fn(array $m, int $d) => array_values(array_filter($m['days'][$d]['meals'], fn($x) => $x['slot'] === 'dinner'))[0] ?? null;
check('each adult has exactly one dinner per day and it is marked shared',
    $dinnerOf($ana, 0)['shared'] === true && $dinnerOf($ben, 6)['shared'] === true
    && count(array_filter($ana['days'][2]['meals'], fn($x) => $x['slot'] === 'dinner')) === 1);
check('everyone eats the same dish for dinner', $dinnerOf($ana, 1)['name'] === $dinnerOf($ben, 1)['name'] && $dinnerOf($ben, 1)['name'] === $dinnerOf($kai, 1)['name']);
check('portions differ per person (Ben, who needs the most energy, gets about double the others)',
    $dinnerOf($ben, 0)['kcal'] > 1.5 * $dinnerOf($ana, 0)['kcal'] && $dinnerOf($ben, 0)['kcal'] > 1.5 * $dinnerOf($kai, 0)['kcal']);
check('each adult day lands near their own calorie target',
    abs($ana['days'][0]['totals']['kcal'] - $hh['targets'][1]['kcal']) / $hh['targets'][1]['kcal'] <= 0.07
    && abs($ben['days'][0]['totals']['kcal'] - $hh['targets'][2]['kcal']) / $hh['targets'][2]['kcal'] <= 0.07);
check('child dinner is sized for a 6 year old (about 450 kcal) and the child gets dinner only',
    abs($dinnerOf($kai, 0)['kcal'] - 450) <= 40 && count($kai['days'][0]['meals']) === 1 && !isset($kai['targets']));
check('the vegetarian adult never gets meat even though the omnivore does',
    !str_contains(json_encode($ben['days']), 'Chicken') && str_contains(json_encode($ana['days']), 'Chicken'));
check('the peanut-allergic child never gets a peanut item', !str_contains(json_encode($kai['days']), 'Peanut'));
check('children under 5 get a choking note, a 6 year old does not', !str_contains(json_encode($kai['warnings']), 'choking'));

// Merged shopping list: covers the combined need, rounded up once.
$need = [];
foreach ($hh['plan']['members'] as $m) {
    foreach ($m['days'] as $day) {
        foreach ($day['meals'] as $meal) {
            foreach ($meal['items'] as $i) {
                $need[$i['ingredient_id']] = ($need[$i['ingredient_id']] ?? 0) + $i['amount'];
            }
        }
    }
}
$listOk = count($hh['plan']['shopping_list']) === count($need);
foreach ($hh['plan']['shopping_list'] as $l) {
    $listOk = $listOk && $l['amount'] >= $need[$l['ingredient_id']] && $l['amount'] - $need[$l['ingredient_id']] < 10 + 1e-6;
}
check('one merged shopping list covers every person, rounded up once', $listOk);
check('household note explains that dinners follow the strictest diet', $hh['plan']['warnings'] !== [] && str_contains($hh['plan']['warnings'][0], 'strictest'));
$hhGrocery = (new GroceryService())->forPlan(['id' => 9, 'plan' => $hh['plan']]);
check('grocery list reads the merged list and mentions the household size', count($hhGrocery['groups']) >= 4 && str_contains($hhGrocery['share_text'], '3 people'));

// A dinner that breaks the child's allergy is rejected and Claude is asked again.
$badDinners = $dinners;
$badDinners[0]['items'][] = $it('Peanut butter', 20);
$fh2 = new FakeHousehold(['submit_dinners' => [['days' => $badDinners], ['days' => $dinners]], 'submit_meal_plan' => [$weekA, $weekB]]);
$hh2 = (new PlanService(new PDO('sqlite::memory:'), $fh2))->buildHousehold($members, $cat);
$retry = end($fh2->calls[1]['messages'])['content'][0] ?? [];
check('a shared dinner with peanut butter is rejected, then fixed on retry',
    $hh2['attempts'] === 4 && ($retry['is_error'] ?? false) === true && str_contains($retry['content'], 'ingredient_id'));

// One person only: no shared-dinner step.
$fh3 = new FakeHousehold(['submit_dinners' => [], 'submit_meal_plan' => [$week]]);
$solo = (new PlanService(new PDO('sqlite::memory:'), $fh3))->buildHousehold([$members[0]], $cat);
check('a one-person household skips the dinner step and keeps its own dinners',
    count($fh3->calls) === 1 && $solo['plan']['shared_dinners'] === false && count($solo['plan']['members']) === 1
    && !str_contains(json_encode($solo['plan']['members'][0]['days']), '"shared"'));

// Safety refusals name the person.
$pregnant = $members;
$pregnant[1]['profile']['pregnant_or_breastfeeding'] = 1;
try {
    (new PlanService(new PDO('sqlite::memory:'), new FakeHousehold([])))->buildHousehold($pregnant, $cat);
    check('a pregnant adult stops the plan', false);
} catch (ApiException $e) {
    check('a pregnant adult stops the plan and is named', $e->errorCode === 'needs_professional' && str_starts_with($e->getMessage(), 'Ben: '));
}

// Children's validation and sizes.
echo "\nChildren\n";
$kid = fn(string $birth, array $extra = []) => (function () use ($birth, $extra) {
    $fields = [];
    Household::validateChild(['birth_date' => $birth, 'consent' => true] + $extra, $fields);
    return $fields;
})();
check('a 9 year old is accepted', $kid(date('Y-m-d', strtotime('-9 years -30 days'))) === []);
check('under 2 is refused with advice to follow a clinic or dietitian', isset($kid(date('Y-m-d', strtotime('-1 year')))['birth_date']));
check('18 or older is sent to the adult form', str_contains($kid(date('Y-m-d', strtotime('-20 years')))['birth_date'] ?? '', 'adult'));
$noConsent = [];
Household::validateChild(['birth_date' => date('Y-m-d', strtotime('-9 years')), 'consent' => false], $noConsent);
check('parent or guardian confirmation is required', isset($noConsent['consent']));
check('dinner sizes grow with age', Household::childDinnerKcal(3) < Household::childDinnerKcal(6) && Household::childDinnerKcal(6) < Household::childDinnerKcal(10) && Household::childDinnerKcal(10) < Household::childDinnerKcal(16));
$nutKid = $members[2];
$nutKid['age'] = 3;
$fh4 = new FakeHousehold(['submit_dinners' => [['days' => $dinners]], 'submit_meal_plan' => [$weekA, $weekB]]);
$young = (new PlanService(new PDO('sqlite::memory:'), $fh4))->buildHousehold([$members[0], $members[1], $nutKid], $cat);
check('a 3 year old gets the choking note', str_contains(json_encode($young['plan']['members'][2]['warnings']), 'choking'));


// ---- Pantry (photos of the fridge / cupboard) ---------------------------------------------
echo "\nPantry\n";
$eggs = $id('Eggs (whole)');
$spinach = $id('Spinach');
$tofu = $id('Firm tofu');
$known = array_fill_keys(array_keys($cat->all()), true);

// Photos: only real pictures, a sensible number, and the file's own bytes decide the type.
$jpegBytes = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 40);
$pngBytes = "\x89PNG\r\n\x1A\n" . str_repeat("\x00", 40);
$checked = Pantry::checkImages([['data' => base64_encode($jpegBytes), 'media_type' => 'image/png'], ['data' => 'data:image/png;base64,' . base64_encode($pngBytes)]]);
check('photos are typed by their bytes, not by what the app claims', $checked[0]['media_type'] === 'image/jpeg' && $checked[1]['media_type'] === 'image/png');
$rejects = function (mixed $raw): bool {
    try {
        Pantry::checkImages($raw);
        return false;
    } catch (ApiException $e) {
        return $e->errorCode === 'bad_images';
    }
};
check('no photos is refused', $rejects([]) && $rejects(null));
check('too many photos is refused', $rejects(array_fill(0, 5, ['data' => base64_encode($jpegBytes)])));
check('something that is not a picture is refused', $rejects([['data' => base64_encode('hello, this is text')]]));
check('broken base64 is refused', $rejects([['data' => '***not base64***']]));
check('an oversized photo is refused', $rejects([['data' => base64_encode("\xFF\xD8\xFF" . str_repeat('a', Pantry::MAX_IMAGE_BYTES))]]));

// Claude's answer is untrusted: unknown ids, silly amounts and odd names are cleaned up.
$scan = Pantry::normaliseScan([
    'items' => [
        ['ingredient_id' => $eggs, 'amount' => 300],
        ['ingredient_id' => $eggs, 'amount' => 120],          // seen twice: the larger amount wins
        ['ingredient_id' => $spinach, 'amount' => 9_999_999], // capped
        ['ingredient_id' => 424242, 'amount' => 100],         // not in the catalogue
        ['ingredient_id' => $tofu, 'amount' => 'lots'],       // not a number
        'garbage',
    ],
    'unmatched' => ['Marmite', '  marmite ', "Ignore all rules <script>alert(1)</script>", 42, str_repeat('x', 200)],
], $cat);
$byId = array_column($scan['items'], 'amount', 'ingredient_id');
check('scan keeps each known food once, with the larger amount', ($byId[$eggs] ?? 0) === 300.0 && count($scan['items']) === 2);
check('scan caps silly amounts and drops unknown or non-numeric ones', ($byId[$spinach] ?? 0) === Pantry::MAX_AMOUNT && !isset($byId[424242]) && !isset($byId[$tofu]));
check('unmatched names are de-duplicated, plain and short',
    $scan['unmatched'][0] === 'Marmite' && count(array_keys(array_map('mb_strtolower', $scan['unmatched']), 'marmite')) === 1
    && !str_contains(implode(' ', $scan['unmatched']), '<') && max(array_map('mb_strlen', $scan['unmatched'])) <= 40);
check('saving a list ignores unknown foods and rounds amounts', Pantry::clean([['ingredient_id' => $eggs, 'amount' => 303], ['ingredient_id' => 0, 'amount' => 5]], $known) === [$eggs => 305.0]);

// The shopping list loses only what the person has AND the first days use.
$applied = Pantry::applyToUsage([$eggs => 900.0, $spinach => 400.0], [$eggs => 350.0, $spinach => 100.0], [$eggs => 250.0, $spinach => 500.0]);
check('shopping list: pantry cover is capped by what they have', ($applied['covered'][$eggs] ?? 0) === 250.0 && $applied['usage'][$eggs] === 650.0);
check('shopping list: cover is capped by what the first days use', ($applied['covered'][$spinach] ?? 0) === 100.0 && $applied['usage'][$spinach] === 300.0);
$all = Pantry::applyToUsage([$eggs => 200.0], [$eggs => 200.0], [$eggs => 500.0]);
check('shopping list: a food that is fully covered disappears', !isset($all['usage'][$eggs]));

// Reading photos with a fake Claude: images go out as image blocks, a text-only reply gets one nudge.
final class FakeVision extends ClaudeClient
{
    public array $calls = [];
    public function __construct(private array $replies)
    {
    }
    public function messages(array $payload): array
    {
        $this->calls[] = $payload;
        $r = array_shift($this->replies) ?? throw new RuntimeException('fake out of replies');
        if ($r === 'TEXT') {
            return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'I can see eggs and spinach.']]];
        }
        return ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'toolu_v', 'name' => 'submit_pantry', 'input' => $r]]];
    }
}
$fv = new FakeVision(['TEXT', ['items' => [['ingredient_id' => $eggs, 'amount' => 240]], 'unmatched' => ['Marmite']]]);
$seen = Pantry::scan($fv, $cat, $checked);
$blocks = $fv->calls[0]['messages'][0]['content'];
check('photos are sent as image blocks followed by the catalogue text',
    count($blocks) === 3 && $blocks[0]['type'] === 'image' && $blocks[0]['source']['type'] === 'base64' && $blocks[2]['type'] === 'text' && str_contains($blocks[2]['text'], 'Eggs (whole)'));
check('a text-only reply is asked once more, then the answer is cleaned', count($fv->calls) === 2 && $seen['items'][0]['ingredient_id'] === $eggs && $seen['unmatched'] === ['Marmite']);
try {
    Pantry::scan(new FakeVision(['TEXT', 'TEXT']), $cat, $checked);
    check('two text-only replies give a friendly error', false);
} catch (ApiException $e) {
    check('two text-only replies give a friendly error', $e->errorCode === 'llm_no_pantry');
}

// The pantry is required, not just a hint: a plan that leaves a pantry food out is sent back.
$butter = $id('Butter');
$yeast = $id('Yeast extract spread (e.g. Marmite)');
$withPantry = $week;
$withPantry['days'][0]['meals'][0]['items'][] = $it('Butter', 10);
$withPantry['days'][1]['meals'][0]['items'][] = $it('Yeast extract spread (e.g. Marmite)', 5);
$pantryWanted = [$butter => 250.0, $yeast => 100.0];
$goal = ['exclusions' => [], 'goal' => 'maintain'] + $constraints;

$fp = new FakeClaude([$week, $withPantry]);
$pr = (new PlanService(new PDO('sqlite::memory:'), $fp))->run($t, $goal, $cat, [], $pantryWanted);
$fbp = end($fp->calls[1]['messages'])['content'][0]['content'] ?? '';
check('a plan that leaves pantry foods out is sent back, naming them', $pr['attempts'] === 2 && str_contains($fbp, 'Pantry:') && str_contains($fbp, 'Butter') && str_contains($fbp, 'Marmite'));
check('the plan that uses every pantry food is accepted without a note', $pr['plan']['warnings'] === [] || !str_contains(json_encode($pr['plan']['warnings']), 'could not fit'));
check('the brief tells the planner every pantry food is a must-use', str_contains($fp->calls[0]['system'], 'EVERY pantry food') && str_contains($fp->calls[0]['messages'][0]['content'], '"pantry_days":3'));

// Nutrition and safety come first: if only the pantry rule is still unmet on the last try, the plan is kept with a note.
$fl = new FakeClaude([$week, $week, $week]);
$last = (new PlanService(new PDO('sqlite::memory:'), $fl))->run($t, $goal, $cat, [], $pantryWanted);
check('on the last attempt a plan that is otherwise valid is accepted, with a note', $last['attempts'] === 3 && str_contains(json_encode($last['plan']['warnings']), 'could not fit') && str_contains(json_encode($last['plan']['warnings']), 'Butter'));

// Without a pantry nothing is asked or checked.
$fn = new FakeClaude([$week]);
$none = (new PlanService(new PDO('sqlite::memory:'), $fn))->run($t, $goal, $cat);
check('no pantry means no pantry rule and no extra attempts', $none['attempts'] === 1 && !str_contains($fn->calls[0]['system'], 'pantry'));

// A longer list gets more days to be used up, and only the first days count.
check('the window grows with the list: 8 foods 3 days, 9 foods 4, 15 foods 5', Pantry::planDays(8) === 3 && Pantry::planDays(9) === 4 && Pantry::planDays(14) === 4 && Pantry::planDays(15) === 5);
$lateOnly = ['days' => array_fill(0, 5, ['meals' => [['items' => []]]])];
$lateOnly['days'][4]['meals'][0]['items'][] = ['ingredient_id' => $butter, 'amount' => 10];
check('a pantry food used only on day 5 still counts as unused for the first 3 days',
    Pantry::unused($lateOnly, [['id' => $butter, 'name' => 'Butter']], 3) === ['Butter'] && Pantry::unused($lateOnly, [['id' => $butter, 'name' => 'Butter']], 5) === []);
$cheeseRule = new ReflectionMethod(Pantry::class, 'systemPrompt');
$cheeseRule->setAccessible(true);
check('scanning matches the same kind of food (sliced cheese is Cheddar)', str_contains($cheeseRule->invoke(null), 'sliced cheese') && str_contains($cheeseRule->invoke(null), 'Cheddar'));

// Safety: a pantry food one of the people cannot eat never reaches the planner.
$verifierP = new PlanVerifier($cat);
$noEggs = $verifierP->allowedIngredients(['diet_type' => 'omnivore', 'exclusions' => [['name' => 'egg', 'kind' => 'allergy']]]);
$rows = Pantry::forPrompt([$eggs => 300.0, $spinach => 200.0], $noEggs);
check('an allergen in the fridge is not offered to the planner', count($rows) === 1 && $rows[0]['id'] === $spinach);

// The pantry reaches the prompts, the first days use it, and it comes off the shopping list.
$pantryHave = [$spinach => 400.0, $eggs => 600.0];
$fhp = new FakeHousehold(['submit_dinners' => [['days' => $dinners]], 'submit_meal_plan' => [$weekA, $weekB]]);
$pp = (new PlanService(new PDO('sqlite::memory:'), $fhp))->buildHousehold($members, $cat, null, $pantryHave);
$briefOf = fn(array $call): array => json_decode(substr($call['messages'][0]['content'], strpos($call['messages'][0]['content'], '{')), true, 512, JSON_OBJECT_AS_ARRAY | JSON_INVALID_UTF8_IGNORE) ?? [];
check('the dinner prompt carries the pantry and the number of days', str_contains($fhp->calls[0]['messages'][0]['content'], '"pantry"') && str_contains($fhp->calls[0]['messages'][0]['content'], '"pantry_days":3'));
check('the pantry rule is added to the system prompts', str_contains($fhp->calls[0]['system'], 'pantry') && str_contains($fhp->calls[1]['system'], 'pantry'));
check('the plan lists what came from home and for how many days', $pp['plan']['pantry_days'] === 3 && $pp['plan']['pantry_used'] !== []);
$homeIds = array_column($pp['plan']['pantry_used'], 'ingredient_id');
check('only pantry foods are marked as at home, and only in the first 3 days', (function () use ($pp, $pantryHave) {
    foreach ($pp['plan']['members'] as $mem) {
        foreach ($mem['days'] as $di => $day) {
            foreach ($day['meals'] as $meal) {
                foreach ($meal['items'] as $item) {
                    $flag = ($item['at_home'] ?? false) === true;
                    if ($flag && ($di >= 3 || !isset($pantryHave[$item['ingredient_id']]))) {
                        return false;
                    }
                    if (!$flag && $di < 3 && isset($pantryHave[$item['ingredient_id']])) {
                        return false;
                    }
                }
            }
        }
    }
    return true;
})());
$baseline = (new PlanService(new PDO('sqlite::memory:'), new FakeHousehold(['submit_dinners' => [['days' => $dinners]], 'submit_meal_plan' => [$weekA, $weekB]])))->buildHousehold($members, $cat);
$listOf = fn(array $plan): array => array_column($plan['shopping_list'], 'amount', 'ingredient_id');
$withP = $listOf($pp['plan']);
$without = $listOf($baseline['plan']);
$ok = $pp['plan']['pantry_used'] !== [];
foreach ($pp['plan']['pantry_used'] as $u) {
    $drop = ($without[$u['ingredient_id']] ?? 0) - ($withP[$u['ingredient_id']] ?? 0);
    $ok = $ok && $drop > 0 && $drop <= $u['amount'] + 10 && $u['amount'] <= $pantryHave[$u['ingredient_id']];
}
check('the shopping list shrinks by what is at home, never by more', $ok);
check('without a pantry nothing changes and nothing is marked', $baseline['plan']['pantry_used'] === [] && $baseline['plan']['pantry_days'] === 0
    && !str_contains(json_encode($baseline['plan']['members']), 'at_home')
    && !str_contains($baseline['plan']['shopping_list'] === [] ? '' : $fh->calls[0]['messages'][0]['content'], '"pantry"'));
$gro = (new GroceryService())->forPlan(['id' => 1, 'plan' => $pp['plan']]);
check('the shopping list names what is already at home and keeps it off the list', $gro['at_home'] !== [] && str_contains($gro['share_text'], 'ALREADY AT HOME') && str_contains($gro['share_text'], 'shopping list'));

// ---- Security: input handling and passwords ---------------------------------------------
echo "\nSecurity\n";
check('passwords: too short, too long, common, all one character and the email are refused',
    Auth::passwordProblem('short') !== null
    && Auth::passwordProblem(str_repeat('ab', 40)) !== null
    && Auth::passwordProblem('Password123') !== null
    && Auth::passwordProblem('aaaaaaaaaa') !== null
    && Auth::passwordProblem('thandi.m@example.com', 'thandi.m@example.com') !== null
    && Auth::passwordProblem('thandi.m', 'thandi.m@example.com') !== null);
check('passwords: a reasonable password is accepted', Auth::passwordProblem('green-kettle-42', 'thandi.m@example.com') === null);
check('Input::str turns arrays and null into empty text', Input::str(['x']) === '' && Input::str(null) === '' && Input::str(12) === '12');
check('Input::text strips control characters', Input::text("Ann\u{0000}a\nB") === 'Ann a B');
[$ex, $err] = Input::exclusions(['peanuts', ['name' => 'Mushrooms', 'kind' => 'dislike']]);
check('an allergy sent as plain text is kept as an allergy, never dropped',
    $err === null && $ex === [['name' => 'peanuts', 'kind' => 'allergy'], ['name' => 'Mushrooms', 'kind' => 'dislike']]);
[, $err] = Input::exclusions([['name' => ['nested']]]);
check('a malformed allergy name is a field error, not a crash', $err !== null);
[, $err] = Input::exclusions('peanuts');
check('allergies that are not a list are refused', $err !== null);
try {
    ProfileRepo::validate([
        'sex' => 'female', 'birth_date' => ['1996-03-10'], 'height_cm' => '165', 'weight_kg' => '65',
        'activity_level' => ['light'], 'consent' => true, 'usual_drinks' => 'lots', 'name' => ['x'],
    ]);
    check('malformed profile fields give field errors', false);
} catch (ApiException $e) {
    check('malformed profile fields give field errors (no PHP error)',
        isset($e->extra['fields']['activity_level'], $e->extra['fields']['birth_date'], $e->extra['fields']['usual_drinks']));
} catch (\Throwable $e) {
    check('malformed profile fields give field errors (no PHP error)', false, $e::class . ': ' . $e->getMessage());
}
$childFields = [];
Household::validateChild(['birth_date' => ['x'], 'consent' => true, 'exclusions' => [7]], $childFields);
check('malformed child fields give field errors (no PHP error)', isset($childFields['birth_date'], $childFields['exclusions']));

echo "\n" . ($failures === 0 ? 'All checks passed.' : "$failures check(s) FAILED.") . "\n";
exit($failures === 0 ? 0 : 1);
