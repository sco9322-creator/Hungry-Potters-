USE hungry_potters;
SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_recipe_ingredients;
DROP TEMPORARY TABLE IF EXISTS tmp_recipe_plan;
DROP TEMPORARY TABLE IF EXISTS tmp_recipe_skips;
DROP TEMPORARY TABLE IF EXISTS tmp_added_ingredients;

CREATE TEMPORARY TABLE tmp_recipe_ingredients (
    name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    category_name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    unit VARCHAR(30) NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB;

INSERT INTO tmp_recipe_ingredients (name, category_name, unit) VALUES
    ('Pork', 'Proteins', 'serving'),
    ('Chicken', 'Proteins', 'serving'),
    ('Sisig', 'Proteins', 'serving'),
    ('Hungarian Sausage', 'Proteins', 'serving'),
    ('Chicken Wings', 'Proteins', 'pcs'),
    ('Spam', 'Proteins', 'serving'),
    ('Embotido', 'Proteins', 'serving'),
    ('Bacon', 'Proteins', 'serving'),
    ('Corned Beef', 'Proteins', 'serving'),
    ('Siomai', 'Proteins', 'serving'),
    ('Chicken Skin', 'Proteins', 'serving'),
    ('Porkchop', 'Proteins', 'serving'),
    ('Bagnet', 'Proteins', 'serving'),
    ('Chicken Fillet', 'Proteins', 'serving'),
    ('Plain Rice', 'Pantry', 'serving'),
    ('Coke', 'Beverages', 'pcs'),
    ('Sprite', 'Beverages', 'pcs'),
    ('Royal', 'Beverages', 'pcs');

CREATE TEMPORARY TABLE tmp_recipe_plan (
    dish_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    ingredient_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    quantity_required DECIMAL(10, 2) NOT NULL,
    KEY idx_tmp_recipe_plan_dish (dish_name)
) ENGINE=InnoDB;

-- Reuse the current inventory names: menu "Egg" uses inventory "Eggs",
-- and recipe "Tapa" uses the existing inventory item "Beef Tapa".
INSERT INTO tmp_recipe_plan (dish_name, ingredient_name, quantity_required) VALUES
    ('Tapsilog', 'Garlic Rice', 1), ('Tapsilog', 'Eggs', 1), ('Tapsilog', 'Beef Tapa', 1),
    ('Porksilog', 'Garlic Rice', 1), ('Porksilog', 'Eggs', 1), ('Porksilog', 'Pork', 1),
    ('Chicksilog', 'Garlic Rice', 1), ('Chicksilog', 'Eggs', 1), ('Chicksilog', 'Chicken', 1),
    ('Sisigsilog', 'Garlic Rice', 1), ('Sisigsilog', 'Eggs', 1), ('Sisigsilog', 'Sisig', 1),
    ('Hungariansilog', 'Garlic Rice', 1), ('Hungariansilog', 'Eggs', 1), ('Hungariansilog', 'Hungarian Sausage', 1),
    ('Bangsilog', 'Garlic Rice', 1), ('Bangsilog', 'Eggs', 1), ('Bangsilog', 'Bangus', 1),
    ('Wingsilog', 'Garlic Rice', 1), ('Wingsilog', 'Eggs', 1), ('Wingsilog', 'Chicken Wings', 1),
    ('Spamsilog', 'Garlic Rice', 1), ('Spamsilog', 'Eggs', 1), ('Spamsilog', 'Spam', 1),
    ('Embosilog', 'Garlic Rice', 1), ('Embosilog', 'Eggs', 1), ('Embosilog', 'Embotido', 1),
    ('Baconsilog', 'Garlic Rice', 1), ('Baconsilog', 'Eggs', 1), ('Baconsilog', 'Bacon', 1),
    ('Tosilog', 'Garlic Rice', 1), ('Tosilog', 'Eggs', 1), ('Tosilog', 'Tocino', 1),
    ('Cornsilog', 'Garlic Rice', 1), ('Cornsilog', 'Eggs', 1), ('Cornsilog', 'Corned Beef', 1),
    ('Hotsilog', 'Garlic Rice', 1), ('Hotsilog', 'Eggs', 1), ('Hotsilog', 'Hotdog', 1),
    ('Longsilog', 'Garlic Rice', 1), ('Longsilog', 'Eggs', 1), ('Longsilog', 'Longganisa', 1),
    ('Siomai Silog', 'Garlic Rice', 1), ('Siomai Silog', 'Eggs', 1), ('Siomai Silog', 'Siomai', 1),
    ('Chicken Skin', 'Garlic Rice', 1), ('Chicken Skin', 'Eggs', 1), ('Chicken Skin', 'Chicken Skin', 1),
    ('Kim''s Overload', 'Garlic Rice', 1), ('Kim''s Overload', 'Eggs', 1), ('Kim''s Overload', 'Chicken', 1),
    ('Kim''s Overload', 'Porkchop', 1), ('Kim''s Overload', 'Beef Tapa', 1),
    ('Set A', 'Chicken Wings', 3), ('Set A', 'Bagnet', 1), ('Set A', 'Bangus', 1),
    ('Set B', 'Chicken Wings', 3), ('Set B', 'Chicken Fillet', 1), ('Set B', 'Porkchop', 1),
    ('Wings 6 pcs (1 flavor)', 'Chicken Wings', 6),
    ('Wings 12 pcs (2 flavors)', 'Chicken Wings', 12),
    ('Plain Rice', 'Plain Rice', 1),
    ('Garlic Rice', 'Garlic Rice', 1),
    ('Egg', 'Eggs', 1),
    ('Extra Chicken', 'Chicken', 1),
    ('Extra Pork', 'Pork', 1),
    ('Extra Tapa', 'Beef Tapa', 1),
    ('Extra Sisig', 'Sisig', 1),
    ('Extra Spam', 'Spam', 1),
    ('Extra Bangus', 'Bangus', 1),
    ('Extra Hotdog', 'Hotdog', 1),
    ('Extra Longganisa', 'Longganisa', 1),
    ('Extra Embotido', 'Embotido', 1),
    ('Extra Tocino', 'Tocino', 1),
    ('Extra Bacon', 'Bacon', 1),
    ('Coke', 'Coke', 1),
    ('Sprite', 'Sprite', 1),
    ('Royal', 'Royal', 1);

CREATE TEMPORARY TABLE tmp_recipe_skips (
    dish_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (dish_name)
) ENGINE=InnoDB;

CREATE TEMPORARY TABLE tmp_added_ingredients (
    name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB;

START TRANSACTION;

INSERT INTO tmp_added_ingredients (name)
SELECT desired.name
FROM tmp_recipe_ingredients AS desired
WHERE NOT EXISTS (
    SELECT 1
    FROM inventory_items AS existing
    WHERE LOWER(existing.name) = LOWER(desired.name)
);

INSERT INTO inventory_items
    (category_id, name, description, unit, current_stock, low_stock_level, is_active)
SELECT category.id, desired.name, '', desired.unit, 0, 0, 1
FROM tmp_recipe_ingredients AS desired
INNER JOIN categories AS category
    ON category.category_type = 'inventory'
   AND LOWER(category.name) = LOWER(desired.category_name)
WHERE NOT EXISTS (
    SELECT 1
    FROM inventory_items AS existing
    WHERE LOWER(existing.name) = LOWER(desired.name)
);

INSERT INTO tmp_recipe_skips (dish_name)
SELECT DISTINCT menu.name
FROM tmp_recipe_plan AS planned
INNER JOIN menu_items AS menu
    ON LOWER(menu.name) = LOWER(planned.dish_name)
WHERE EXISTS (
    SELECT 1
    FROM menu_recipes AS existing_recipe
    WHERE existing_recipe.menu_item_id = menu.id
);

INSERT INTO menu_recipes (menu_item_id, inventory_item_id, quantity_required)
SELECT menu.id, ingredient.id, planned.quantity_required
FROM tmp_recipe_plan AS planned
INNER JOIN menu_items AS menu
    ON LOWER(menu.name) = LOWER(planned.dish_name)
INNER JOIN inventory_items AS ingredient
    ON LOWER(ingredient.name) = LOWER(planned.ingredient_name)
LEFT JOIN (
    SELECT DISTINCT menu_item_id
    FROM menu_recipes
) AS existing_recipe
    ON existing_recipe.menu_item_id = menu.id
WHERE existing_recipe.menu_item_id IS NULL;

COMMIT;

SELECT dish_name AS recipe_skipped_existing
FROM tmp_recipe_skips
ORDER BY dish_name;

SELECT added.name AS ingredient_added, inventory.unit
FROM tmp_added_ingredients AS added
INNER JOIN inventory_items AS inventory
    ON LOWER(inventory.name) = LOWER(added.name)
ORDER BY added.name;

DROP TEMPORARY TABLE IF EXISTS tmp_recipe_ingredients;
DROP TEMPORARY TABLE IF EXISTS tmp_recipe_plan;
DROP TEMPORARY TABLE IF EXISTS tmp_recipe_skips;
DROP TEMPORARY TABLE IF EXISTS tmp_added_ingredients;
