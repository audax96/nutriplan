<?php
// Background job: builds one household plan. Started by PlanService::start(); not meant to be run by hand.
// Usage: php bin/run_plan.php <plan_id>
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use NutriPlan\ApiException;
use NutriPlan\ClaudeClient;
use NutriPlan\Db;
use NutriPlan\PlanService;

date_default_timezone_set('Africa/Johannesburg');
set_time_limit(0);

$planId = (int)($argv[1] ?? 0);
if ($planId <= 0) {
    fwrite(STDERR, "Usage: php bin/run_plan.php <plan_id>\n");
    exit(1);
}

$db = Db::pdo();
try {
    $client = ClaudeClient::fromConfig();
} catch (ApiException $e) {
    // Missing API key: record it so the app can say so instead of waiting forever.
    $db->prepare("UPDATE meal_plans SET status = 'failed', progress = NULL, error_code = ?, error_message = ? WHERE id = ? AND status = 'building'")
        ->execute([$e->errorCode, $e->getMessage(), $planId]);
    exit(1);
}
(new PlanService($db, $client))->build($planId);
