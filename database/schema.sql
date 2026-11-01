-- NutriPlan schema (MySQL 8 / MariaDB 10.5+)
-- Run:  mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS nutriplan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nutriplan;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE api_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,          -- sha256 of the bearer token
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Everyone the account holder cooks for. The owner is created automatically ("Me").
-- Adults keep their body stats in `profiles`; children only need an age, diet and allergies,
-- so those live here. Children get no calorie target: they eat the shared family dinners.
CREATE TABLE members (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(40) NOT NULL,
  kind       ENUM('adult','child') NOT NULL DEFAULT 'adult',
  is_owner   TINYINT(1) NOT NULL DEFAULT 0,
  birth_date DATE NULL,                                   -- children only
  diet_type  ENUM('omnivore','pescatarian','vegetarian','vegan') NOT NULL DEFAULT 'omnivore',  -- children only
  consent_at DATETIME NULL,                               -- children only (parent/guardian permission)
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_members_user (user_id),
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One profile per adult member: body stats, goal, diet.
CREATE TABLE profiles (
  member_id                 INT UNSIGNED PRIMARY KEY,
  sex                       ENUM('male','female') NOT NULL,
  birth_date                DATE NOT NULL,
  height_cm                 DECIMAL(5,1) NOT NULL,
  weight_kg                 DECIMAL(5,1) NOT NULL,
  activity_level            ENUM('sedentary','light','moderate','active','very_active') NOT NULL DEFAULT 'light',
  goal_type                 ENUM('lose','maintain','gain') NOT NULL DEFAULT 'maintain',
  goal_weight_kg            DECIMAL(5,1) NULL,
  weekly_rate_kg            DECIMAL(3,2) NOT NULL DEFAULT 0.50,   -- kg per week toward the goal
  calorie_override          INT UNSIGNED NULL,                    -- user-chosen daily kcal (still floored)
  target_protein_g          SMALLINT UNSIGNED NULL,               -- optional grams per day; blanks are worked out
  target_carb_g             SMALLINT UNSIGNED NULL,               -- (all three set: calories = 4P + 4C + 9F)
  target_fat_g              SMALLINT UNSIGNED NULL,
  diet_type                ENUM('omnivore','pescatarian','vegetarian','vegan') NOT NULL DEFAULT 'omnivore',
  pregnant_or_breastfeeding TINYINT(1) NOT NULL DEFAULT 0,
  consent_at                DATETIME NULL,                        -- POPIA consent + "not medical advice" acknowledgement
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_profiles_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Allergies are HARD exclusions; dislikes are preferences. Both are enforced in code.
CREATE TABLE dietary_exclusions (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  name      VARCHAR(80) NOT NULL,                -- e.g. "peanuts", "dairy", "mushrooms"
  kind      ENUM('allergy','dislike') NOT NULL DEFAULT 'allergy',
  KEY idx_excl_member (member_id),
  CONSTRAINT fk_excl_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Drinks the person already has (cooldrinks, sweet coffee, wine ...). Their average daily calories
-- are deducted from the day's target before the meals are planned. drink_key points at
-- NutriPlan\Drinks::CATALOGUE; custom drinks have no key and carry their own calories.
CREATE TABLE usual_drinks (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id         INT UNSIGNED NOT NULL,
  drink_key         VARCHAR(40) NULL,
  name              VARCHAR(80) NOT NULL,
  kcal_per_serving  DECIMAL(6,1) NULL,
  is_alcohol        TINYINT(1) NOT NULL DEFAULT 0,
  servings_per_week DECIMAL(4,1) NOT NULL,
  KEY idx_drinks_member (member_id),
  CONSTRAINT fk_drinks_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Nutrient values are per 100 g (or per 100 ml when unit = 'ml').
CREATE TABLE ingredients (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL UNIQUE,
  category     ENUM('meat','fish','dairy','egg','grain','legume','vegetable','fruit','fat','nut_seed','beverage','other') NOT NULL,
  unit         ENUM('g','ml') NOT NULL DEFAULT 'g',
  kcal         DECIMAL(7,2) NOT NULL DEFAULT 0,
  protein_g    DECIMAL(7,2) NOT NULL DEFAULT 0,
  carb_g       DECIMAL(7,2) NOT NULL DEFAULT 0,
  fat_g        DECIMAL(7,2) NOT NULL DEFAULT 0,
  fibre_g      DECIMAL(7,2) NOT NULL DEFAULT 0,
  iron_mg      DECIMAL(7,2) NOT NULL DEFAULT 0,
  calcium_mg   DECIMAL(7,2) NOT NULL DEFAULT 0,
  vit_c_mg     DECIMAL(7,2) NOT NULL DEFAULT 0,
  vit_a_ug     DECIMAL(8,2) NOT NULL DEFAULT 0,   -- RAE
  b12_ug       DECIMAL(7,2) NOT NULL DEFAULT 0,
  folate_ug    DECIMAL(7,2) NOT NULL DEFAULT 0,
  vit_d_ug     DECIMAL(7,2) NOT NULL DEFAULT 0,
  zinc_mg      DECIMAL(7,2) NOT NULL DEFAULT 0,
  magnesium_mg DECIMAL(7,2) NOT NULL DEFAULT 0,
  potassium_mg DECIMAL(7,2) NOT NULL DEFAULT 0,
  source       VARCHAR(40) NOT NULL DEFAULT 'seed'  -- 'SAMRC', 'USDA', 'seed' ...
) ENGINE=InnoDB;

-- Allergen tags: milk, egg, fish, shellfish, peanut, tree_nut, gluten, soy, sesame
CREATE TABLE ingredient_allergens (
  ingredient_id INT UNSIGNED NOT NULL,
  allergen      VARCHAR(30) NOT NULL,
  PRIMARY KEY (ingredient_id, allergen),
  CONSTRAINT fk_ia_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- RESERVED FOR V2 (prices, budget and retailer basket-filling). Not read or written by v1.
-- Retailer products an ingredient maps to. pack_size is in the ingredient's unit (g or ml);
-- price_zar is the shelf price for the whole pack.
CREATE TABLE products (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingredient_id    INT UNSIGNED NOT NULL,
  retailer         VARCHAR(30) NOT NULL DEFAULT 'checkers',
  sku              VARCHAR(60) NULL,                 -- retailer product id, once you have one
  name             VARCHAR(160) NOT NULL,
  pack_size        DECIMAL(9,2) NOT NULL,
  price_zar        DECIMAL(8,2) NOT NULL,
  price_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_products_ing (ingredient_id, retailer),
  CONSTRAINT fk_products_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A household plan. It is built in the background (status 'building'), then stored as 'ready'
-- or 'failed'. plan_json holds every member's days and the merged household shopping list.
CREATE TABLE meal_plans (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  week_start    DATE NOT NULL,
  status        ENUM('building','ready','failed') NOT NULL DEFAULT 'building',
  progress      VARCHAR(160) NULL,                  -- what the builder is doing right now
  error_code    VARCHAR(40) NULL,
  error_message VARCHAR(600) NULL,
  target_json   JSON NULL,                          -- adult targets keyed by member id
  plan_json     JSON NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_plans_user (user_id, created_at),
  CONSTRAINT fk_plans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- What the person already has at home (from fridge or cupboard photos, checked by them).
-- Used for the first days of a plan and only for a few days (see NutriPlan\Pantry::TTL_DAYS).
-- Photos themselves are never stored. Existing databases get these tables automatically.
CREATE TABLE IF NOT EXISTS pantry_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  ingredient_id INT UNSIGNED NOT NULL,
  amount        DECIMAL(8,1) NOT NULL,              -- rough, in the ingredient's unit (g or ml)
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pantry (user_id, ingredient_id),
  CONSTRAINT fk_pantry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pantry_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A tiny log used only to limit how many photo scans one account can run per day.
CREATE TABLE IF NOT EXISTS pantry_scans (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_scans_user (user_id, created_at),
  CONSTRAINT fk_scans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Counters for brute-force and abuse limits (failed sign-ins, sign-ups). Keys are sha256
-- hashes, so no email or IP address is stored in clear. Rows older than a day are deleted.
-- NutriPlan\RateLimiter creates this table on older databases automatically.
CREATE TABLE IF NOT EXISTS rate_limits (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket     VARCHAR(30) NOT NULL,
  key_hash   CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rl (bucket, key_hash, created_at)
) ENGINE=InnoDB;
