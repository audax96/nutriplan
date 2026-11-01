<?php
// Front controller. Dev server:  php -S 0.0.0.0:8000 -t public public/index.php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use NutriPlan\ApiException;
use NutriPlan\Auth;
use NutriPlan\Catalogue;
use NutriPlan\ClaudeClient;
use NutriPlan\Config;
use NutriPlan\Db;
use NutriPlan\Drinks;
use NutriPlan\GroceryService;
use NutriPlan\Household;
use NutriPlan\Input;
use NutriPlan\Pantry;
use NutriPlan\PlanService;
use NutriPlan\ProfileRepo;
use NutriPlan\TargetCalculator;

date_default_timezone_set('Africa/Johannesburg');
set_time_limit(60);

// Never print PHP warnings into a response (they leak file paths); they go to the log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
// Health data: never cached, never sniffed as another type, never framed, no referrer.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

// CORS is only for a browser client (Flutter web). The phone app does not need it, so it is
// off unless CORS_ORIGIN names the web app's origin.
$corsOrigin = trim((string)Config::get('CORS_ORIGIN', ''));
if ($corsOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $corsOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    http_response_code(405);
    echo json_encode(['error' => ['code' => 'method_not_allowed', 'message' => 'Method not allowed.', 'extra' => []]]);
    exit;
}

/** Largest request body: photo scans carry up to 4 photos; everything else is small JSON. */
const MAX_BODY_BYTES = 256 * 1024;
const MAX_SCAN_BODY_BYTES = 15 * 1024 * 1024;

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function ok(mixed $data, int $status = 200): never
{
    respond($status, ['data' => $data]);
}

try {
    $path = rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') ?: '/';

    $limit = $path === '/api/pantry/scan' ? MAX_SCAN_BODY_BYTES : MAX_BODY_BYTES;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        throw new ApiException(413, 'too_large', 'That request is too large.');
    }
    $raw = '';
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        // Read at most one byte over the limit, so a body without Content-Length cannot exhaust memory.
        $raw = (string)file_get_contents('php://input', false, null, 0, $limit + 1);
        if (strlen($raw) > $limit) {
            throw new ApiException(413, 'too_large', 'That request is too large.');
        }
    }
    $input = $raw === '' ? [] : json_decode($raw, true, 64);
    if (!is_array($input)) {
        throw new ApiException(400, 'bad_request', 'The request could not be read. Please update the app and try again.');
    }

    $db = Db::pdo();
    $auth = new Auth($db);
    $profiles = new ProfileRepo($db);

    // ---- public ----------------------------------------------------------
    if ($method === 'GET' && $path === '/api/health') {
        ok(['status' => 'ok']);
    }
    if ($method === 'GET' && $path === '/api/drinks') {
        ok(Drinks::options());
    }
    if ($method === 'POST' && $path === '/api/register') {
        ok($auth->register(Input::str($input['email'] ?? ''), Input::str($input['password'] ?? '')), 201);
    }
    if ($method === 'POST' && $path === '/api/login') {
        ok($auth->login(Input::str($input['email'] ?? ''), Input::str($input['password'] ?? '')));
    }

    // ---- authenticated ---------------------------------------------------
    $userId = $auth->requireUser();

    // Sign out: this device's token stops working straight away.
    if ($method === 'POST' && $path === '/api/logout') {
        $auth->revokeCurrent();
        ok(['signed_out' => true]);
    }

    $household = new Household($db);

    /** Targets for an adult profile, or an error note when they should see a professional. */
    $targetsFor = static function (array $p): array {
        try {
            return TargetCalculator::compute($p);
        } catch (\DomainException $e) {
            return ['error' => $e->getMessage()];
        }
    };

    // The account holder's own profile (the first person in the family).
    if ($method === 'GET' && $path === '/api/profile') {
        $ownerId = $household->ownerId($userId);
        $p = $ownerId === null ? null : $profiles->get($ownerId);
        ok(['profile' => $p, 'targets' => $p === null ? null : $targetsFor($p)]);
    }

    if ($method === 'PUT' && $path === '/api/profile') {
        $p = $household->saveAdult($userId, $household->ownerId($userId), $input, true);
        $targets = $targetsFor($p);
        if (isset($targets['error'])) {
            throw new ApiException(422, 'needs_professional', $targets['error']);
        }
        ok(['profile' => $p, 'targets' => $targets]);
    }

    // The whole family.
    if ($method === 'GET' && $path === '/api/household') {
        ok(['members' => $household->summary($userId), 'max_members' => Household::MAX_MEMBERS]);
    }

    if ($method === 'POST' && $path === '/api/members') {
        if (($input['kind'] ?? 'adult') === 'child') {
            ok(['kind' => 'child', 'profile' => $household->saveChild($userId, null, $input), 'targets' => null], 201);
        }
        $p = $household->saveAdult($userId, null, $input);
        ok(['kind' => 'adult', 'profile' => $p, 'targets' => $targetsFor($p)], 201);
    }

    if (preg_match('#^/api/members/(\d+)$#', $path, $m)) {
        $memberId = (int)$m[1];
        $member = $household->find($userId, $memberId) ?? throw new ApiException(404, 'not_found', 'Person not found.');
        $isChild = $member['kind'] === 'child';

        if ($method === 'GET') {
            if ($isChild) {
                ok(['kind' => 'child', 'profile' => $household->child($userId, $memberId), 'targets' => null]);
            }
            $p = $profiles->get($memberId);
            ok(['kind' => 'adult', 'profile' => $p, 'targets' => $p === null ? null : $targetsFor($p)]);
        }
        if ($method === 'PUT') {
            if ($isChild) {
                ok(['kind' => 'child', 'profile' => $household->saveChild($userId, $memberId, $input), 'targets' => null]);
            }
            $p = $household->saveAdult($userId, $memberId, $input, (int)$member['is_owner'] === 1);
            ok(['kind' => 'adult', 'profile' => $p, 'targets' => $targetsFor($p)]);
        }
        if ($method === 'DELETE') {
            $household->delete($userId, $memberId);
            ok(['deleted' => true]);
        }
    }

    // The foods the app knows about (for adding something to the pantry by hand).
    if ($method === 'GET' && $path === '/api/ingredients') {
        ok(array_values(array_map(
            static fn(array $i): array => ['id' => $i['id'], 'name' => $i['name'], 'unit' => $i['unit'], 'category' => $i['category']],
            Catalogue::fromDb($db)->all()
        )));
    }

    // What is at home. A photo scan only proposes a list; nothing is saved until the person
    // has checked it and the app sends it back with PUT. Photos are never stored.
    if (str_starts_with($path, '/api/pantry')) {
        $pantry = new Pantry($db);

        $pantryView = static function (array $list): array {
            return $list + ['valid_days' => Pantry::TTL_DAYS, 'plan_days' => Pantry::planDays(count($list['items']))];
        };
        if ($method === 'GET' && $path === '/api/pantry') {
            ok($pantryView($pantry->items($userId)));
        }
        if ($method === 'PUT' && $path === '/api/pantry') {
            $pantry->replace($userId, $input['items'] ?? null);
            ok($pantryView($pantry->items($userId)));
        }
        if ($method === 'DELETE' && $path === '/api/pantry') {
            $pantry->clear($userId);
            ok(['deleted' => true]);
        }
        if ($method === 'POST' && $path === '/api/pantry/scan') {
            set_time_limit(180);
            $images = Pantry::checkImages($input['images'] ?? null);
            $claude = ClaudeClient::fromConfig();
            $pantry->useScan($userId);
            ok(Pantry::scan($claude, Catalogue::fromDb($db), $images));
        }
    }

    // Plans are built in the background: generate returns at once with status "building".
    $plans = new PlanService($db, new ClaudeClient('unused', 'unused'));

    if ($method === 'POST' && $path === '/api/plans/generate') {
        ok($plans->start($userId), 202);
    }

    if ($method === 'GET' && $path === '/api/plans/latest') {
        ok($plans->latest($userId));
    }

    if (preg_match('#^/api/plans/(\d+)$#', $path, $m) && $method === 'GET') {
        $plan = $plans->get($userId, (int)$m[1]) ?? throw new ApiException(404, 'not_found', 'Plan not found.');
        ok($plan);
    }

    if (preg_match('#^/api/plans/(\d+)/grocery$#', $path, $m) && $method === 'GET') {
        $plan = $plans->get($userId, (int)$m[1]) ?? throw new ApiException(404, 'not_found', 'Plan not found.');
        if ($plan['status'] !== 'ready') {
            throw new ApiException(409, 'plan_not_ready', 'This plan is still being built.');
        }
        ok((new GroceryService())->forPlan($plan));
    }

    // POPIA: let people delete their account and all of their data. The password is asked
    // again, so a lost or stolen phone (or a leaked token) cannot wipe the account.
    if ($method === 'DELETE' && $path === '/api/account') {
        $auth->confirmPassword($userId, Input::str($input['password'] ?? ''));
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        ok(['deleted' => true]);
    }

    throw new ApiException(404, 'not_found', 'No such endpoint.');
} catch (ApiException $e) {
    respond($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'extra' => $e->extra]]);
} catch (\Throwable $e) {
    error_log($e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(500, ['error' => ['code' => 'server_error', 'message' => 'Something went wrong on our side. Please try again.', 'extra' => []]]);
}
