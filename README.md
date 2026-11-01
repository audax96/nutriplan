# NutriPlan (v1)

Weekly meal plans that fit each person's calories, macros, vitamins and minerals, allergies, dislikes and diet, with one shared grocery list for the whole family.

**v1 has no prices, no budget and no retailer integration.** Those are planned for v2 (see below).

- **app/** Flutter app (Android/iOS)
- **backend/** PHP 8.1+ JSON API (plain PHP, PDO, cURL)
- **database/** MySQL schema and a starter ingredient catalogue

## How it works

```
Flutter app  ->  PHP API  ->  MySQL
                    |
                    +--> Claude API (server-side only; the key never reaches the app)
```

1. **PHP does the maths.** `TargetCalculator` works out BMR (Mifflin-St Jeor), TDEE, a safe deficit/surplus, macros, fibre and approximate vitamin/mineral targets for each adult. Claude never calculates these.
2. **Claude proposes the menu.** `PlanService` sends Claude the targets and a catalogue of allowed ingredients (already filtered for diet, allergies and dislikes) and gets a structured answer through a tool call. Only de-identified numbers are sent: no names, emails or allergy labels.
3. **PHP verifies everything.** `PlanVerifier` rescales each day's portions to the calorie target, then checks allergens, diet, calories, protein, carbs and fat, and the weekly vitamin and mineral averages.
4. **Retry with feedback.** If a hard check fails, the exact problems go back to Claude as a `tool_result` error (up to 3 attempts per step). A plan that still fails is never shown.
5. **Grocery list.** The verified plans produce one shopping list for everyone: total amounts for the week (rounded up to the next 10 g/ml, once, not per person), grouped by aisle, with a "copy list" button.
6. **Built in the background.** `POST /api/plans/generate` returns at once with status `building`; a separate PHP process (`bin/run_plan.php`) does the work and the app polls `GET /api/plans/latest` for progress. A build that stops reporting for 20 minutes is marked failed.

## Family

One account can cook for up to 6 people (`Household`, `members` table):

- **Adults** (18+) have a full profile and their own plan, built from their own targets.
- **Children** (2 to 17) have a name, birth date, eating style and allergies, and **no calorie target**. They eat the shared dinners, sized by age (`Household::childDinnerKcal`: about 350 / 450 / 600 / 700 kcal for 2-3, 4-8, 9-13, 14-17). These sizes are approximate; have a dietitian review them. Children under 2 are refused.
- **Shared dinners.** With more than one person, Claude first designs 7 dinners (about 650 kcal each) from foods that **everyone** may eat (the intersection of every member's diet, allergies and dislikes, so the dinners follow the strictest person). Each adult's dinner is then scaled to 35% of their own daily calories, and Claude plans only their breakfast, lunch, snacks and drinks around it. Children get the same dinners at their size.
- **Safety.** A pregnant or breastfeeding adult stops the whole plan (and is named). Children under 5 get a choking note about nuts. Adding someone else needs the account holder's confirmation of permission (adults) or parent/guardian status (children).
- **Cost and time.** A household plan makes one Claude call for dinners plus one per adult (more with retries), so it costs and takes roughly that many times a single-person plan. Check the Usage page in the Anthropic Console.

## Design

Dark only, built for readability and as few decisions per screen as possible. All colours and shared widgets live in `app/lib/ui.dart` (ink-blue background, soft saffron accent, green only for "on target" bars).

- Setup is six short steps, one question each, with big tappable choices instead of dropdowns.
- "My week" opens on today. Meals show name and calories; tap one for ingredients. Targets, vitamins and notes sit in collapsed sections.
- Grocery list items are large tap-to-tick rows.

## Run it with Docker (easiest)

Needs Docker only. This starts MySQL and the API together; the Flutter app still runs on your machine.

```bash
cp .env.docker.example .env      # then put your ANTHROPIC_API_KEY in .env
docker compose up --build
```

- The API is at http://localhost:8000 (check `curl http://localhost:8000/api/health`).
- On the first start MySQL loads `database/schema.sql` into a volume, and the API imports the food catalogue each time it starts.
- Stop with Ctrl+C. Your data stays in the `dbdata` volume; `docker compose down -v` wipes it. **The schema changed when family support was added** (profiles now belong to family members), so if you ran an earlier version, run `docker compose down -v` once before `docker compose up --build`. This deletes any test accounts.
- Look inside the database: `docker compose exec db mysql -unutriplan -pnutriplan nutriplan`
- Run the offline tests: `docker compose run --rm api php tests/smoke.php`
- This is a development setup (PHP built-in server, open CORS). Not for production.

Then start the app as described below. Use `http://10.0.2.2:8000` on the Android emulator, or `http://<your-computer-IP>:8000` on a real phone.

## Run it without Docker


**Backend**

```bash
mysql -u root -p < database/schema.sql
cd backend
cp .env.example .env            # add DB credentials and ANTHROPIC_API_KEY
php bin/import_catalogue.php ../database/catalogue_seed.csv
php -S 0.0.0.0:8000 -t public public/index.php
php tests/smoke.php             # offline logic tests, no DB or API key needed
```

**App** (only `lib/` and `pubspec.yaml` are included, so generate the platform folders first)

```bash
cd app
flutter create . --platform android,ios
flutter pub get
flutter run --dart-define=API_URL=http://10.0.2.2:8000     # Android emulator
```

For plain-HTTP development on Android add `android:usesCleartextTraffic="true"` to `AndroidManifest.xml`; use HTTPS in production. If the build complains, raise `minSdkVersion` to 23 for `flutter_secure_storage`.

## API

| Method | Path | Notes |
|---|---|---|
| POST | `/api/register`, `/api/login` | returns `{data: {token}}` |
| GET | `/api/drinks` | common drinks for the "usual drinks" picker (key, name, serving, kcal) |
| GET / PUT | `/api/profile` | the account holder's own profile (body stats, goal, diet, allergies/dislikes, usual drinks, consent) |
| GET | `/api/household` | everyone in the family with a short summary |
| POST | `/api/members` | add an adult or a child (`kind`: `adult` or `child`) |
| GET / PUT / DELETE | `/api/members/{id}` | read, edit or remove a family member (the owner cannot be removed) |
| POST | `/api/plans/generate` | starts building in the background (returns `202` with status `building`, or the build already running) |
| GET | `/api/plans/latest`, `/api/plans/{id}` | status `building`/`ready`/`failed`, progress text, the plan; `latest` also carries the last finished plan as `fallback` |
| GET | `/api/plans/{id}/grocery` | aisle-grouped weekly quantities and copyable text |
| DELETE | `/api/account` | deletes the user and all their data (POPIA) |

Errors look like `{error: {code, message, extra}}`.

## Drinks (liquid calories)

Two separate things:

**Usual drinks (the main one).** People say what they already drink (cooldrink, sweet coffee, wine, a custom drink) and how many servings per week. `Drinks::allowance()` turns that into an average daily amount and `TargetCalculator` takes it off the day's target before the meals are planned:

- Daily total stays the person's real target; **meals are planned for the total minus drinks**.
- Sugar and alcohol calories also come out of the carbohydrate budget, so the macros still add up.
- Drinks may use at most 30% of the day (`Drinks::MAX_SHARE`). Above that the allowance is capped and the person gets a warning, so meals always keep room for proper food.
- Claude is told the usual drinks are already subtracted, so it does not add more of them. It only receives the resulting numbers, never the drink names.
- Weekly servings handle things like "a glass of wine on weekends" (2 a week = about 0.3 a day).

**Planned drinks.** Claude may add a `drink` meal slot (milk, a smoothie made from catalogue foods, a protein shake, a glass of juice). Orange juice, fortified soy and oat milk, and whey protein powder were added to the catalogue. The prompt tells Claude to use drinks mainly for gain goals or extra protein, and to keep them low-calorie (no juice) for weight loss. The person's goal is sent along for this.

The per-serving drink values in `Drinks::CATALOGUE` are approximate for common South African serving sizes; have a dietitian check them. Water and unsweetened tea or coffee are not tracked (about 0 kcal).

## Nutrition data

`database/catalogue_seed.csv` holds 40 starter foods. **The nutrient values are approximate placeholders typed from memory.** Replace them before real users see a plan:

- **USDA FoodData Central** has a free API (key via data.gov, 1,000 requests/hour/IP) and public-domain (CC0) data. It is US food data, so some South African staples will be missing or a poor match.
- **SAMRC SAFOODS** holds South African food composition data. No public API or licensing terms were found; ask them directly (see the contact page at safoods.mrc.ac.za).

Import into `ingredients` with `bin/import_catalogue.php` (any source that can produce the CSV columns works). Vitamin D and B12 are warnings rather than hard checks, because food alone often cannot meet them.

## v2: prices, budget and Checkers Sixty60

Left out of v1 on purpose. When you add them:

- The `products` table (schema.sql) is already there for retailer products: sku, pack size, price.
- Price the plan in whole packs and re-introduce a budget as a hard check in `PlanVerifier`, with the overspend fed back to Claude like the other checks.
- `RetailerCart`, `ManualListCart` and `CheckersSixty60Cart` are stubs, not wired into the API or app. As far as we know Sixty60 has no public basket API (confirm with Shoprite); the legitimate route is a partner or affiliate agreement, which would also provide product SKUs and prices. Do not scrape or automate the consumer app.

## What is tested and what is not

Tested (offline, `php tests/smoke.php`): target maths, calorie floor, adults-only rule, profile validation, allergy/dislike/diet enforcement, usual-drink allowance maths (cap, alcohol, custom drinks), portion scaling, shopping-list totals and formatting, the Claude retry loop using a fake client (including that a plan which keeps breaking an allergy is never returned), and household planning: shared dinners drawn only from foods everyone can eat, per-person portions, vegetarian adult never gets meat, allergic child never gets the allergen, merged shopping list, pregnancy refusal naming the person, children's age limits.

**Tested against a real database (MariaDB 10.11):** `schema.sql`, the catalogue importer, and the register, login, profile, family (add, edit, remove adults and children, validation, other users cannot see your members), drinks and auth-check endpoints; the background plan build from start to finish against a stand-in for the Claude API (progress, saved plan, grocery list, double-tap protection, stale builds, failure messages, account deletion cascading to all family data); and a real call to Anthropic with an invalid key (reported as a clear failure).

**Not tested:** a successful real Claude call (no API key in the test environment; check that the verifier accepts what Claude actually returns, especially the shared dinners), the real Claude call (no API key or network here), and the Flutter app, including the dark redesign (never compiled or run on a device; expect a few small compile fixes). Expect to fix small things on first run.

## Before real users

- **Replace the seed nutrient data** (see above) and **have a registered dietitian review** the targets, thresholds (`PlanVerifier` constants) and wording.
- **Medical edge cases:** the app refuses under-18s and pregnant/breastfeeding users and asks them to see a professional. Consider the same for diabetes, kidney disease and eating-disorder history.
- **POPIA:** health data is special personal information. You have consent capture and account deletion; you also need a privacy policy, secure hosting, backups and an information officer.
- **Hardening:** HTTPS only, rate-limit `/login`, `/register` and `/plans/generate` (each plan costs money), move generation to a background job, restrict `CORS_ORIGIN`, set Anthropic spend limits, and log verifier failures to see where Claude struggles.
- **Catalogue size:** with thousands of foods, send Claude a relevant subset (by category) rather than the whole list.
