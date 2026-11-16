USE hungry_potters;
SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_update_menu;
CREATE TEMPORARY TABLE tmp_update_menu (
    name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    category_name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB;

INSERT INTO tmp_update_menu (name, category_name, price, description) VALUES
    ('Tapsilog', 'Silog Meals', 100.00, 'Served with garlic rice & egg'),
    ('Porksilog', 'Silog Meals', 100.00, 'Served with garlic rice & egg'),
    ('Chicksilog', 'Silog Meals', 100.00, 'Served with garlic rice & egg'),
    ('Sisigsilog', 'Silog Meals', 100.00, 'Served with garlic rice & egg'),
    ('Hungariansilog', 'Silog Meals', 100.00, 'Served with garlic rice & egg'),
    ('Bangsilog', 'Silog Meals', 100.00, 'Served with garlic rice & egg'),
    ('Wingsilog', 'Silog Meals', 100.00, 'Served with garlic rice & egg'),
    ('Spamsilog', 'Silog Meals', 90.00, 'Served with garlic rice & egg'),
    ('Embosilog', 'Silog Meals', 80.00, 'Served with garlic rice & egg'),
    ('Baconsilog', 'Silog Meals', 80.00, 'Served with garlic rice & egg'),
    ('Tosilog', 'Silog Meals', 80.00, 'Served with garlic rice & egg'),
    ('Cornsilog', 'Silog Meals', 80.00, 'Served with garlic rice & egg'),
    ('Hotsilog', 'Silog Meals', 75.00, 'Served with garlic rice & egg'),
    ('Longsilog', 'Silog Meals', 75.00, 'Served with garlic rice & egg'),
    ('Siomai Silog', 'Silog Meals', 65.00, 'Served with garlic rice & egg'),
    ('Chicken Skin', 'Silog Meals', 65.00, 'Served with garlic rice & egg'),
    ('Chicken Pastil w/Egg', 'Silog Meals', 75.00, 'Served with garlic rice & egg'),
    ('Kim''s Overload', 'Hungry Meals', 235.00, 'Chicken, Porkchop, Tapa with Garlic Rice & Egg'),
    ('Set A', 'Set Meals', 235.00, '3 pcs Chicken Wings, Bagnet, Bangus'),
    ('Set B', 'Set Meals', 235.00, '3 pcs Chicken Wings, Chicken Fillet, Porkchop'),
    ('Beshy Sisig', 'Hungry Hooray', 145.00, 'For sharing'),
    ('Barkada Sisig', 'Hungry Hooray', 175.00, 'For sharing'),
    ('Wings 6 pcs (1 flavor)', 'Wings & Dips', 160.00, 'Flavors: Salted Egg, Potter-Mayo, Garlic, Sweet & Spicy, Honey Glaze. Enter the flavor choice in the kitchen note.'),
    ('Wings 12 pcs (2 flavors)', 'Wings & Dips', 270.00, 'Flavors: Salted Egg, Potter-Mayo, Garlic, Sweet & Spicy, Honey Glaze. Enter the flavor choices in the kitchen note.'),
    ('Plain Rice', 'Extra', 15.00, NULL),
    ('Garlic Rice', 'Extra', 20.00, NULL),
    ('Egg', 'Extra', 15.00, NULL),
    ('Extra Chicken', 'Extra', 80.00, NULL),
    ('Extra Pork', 'Extra', 80.00, NULL),
    ('Extra Tapa', 'Extra', 80.00, NULL),
    ('Extra Sisig', 'Extra', 80.00, NULL),
    ('Extra Spam', 'Extra', 80.00, NULL),
    ('Extra Bangus', 'Extra', 80.00, NULL),
    ('Extra Hotdog', 'Extra', 50.00, NULL),
    ('Extra Longganisa', 'Extra', 50.00, NULL),
    ('Extra Embotido', 'Extra', 50.00, NULL),
    ('Extra Tocino', 'Extra', 50.00, NULL),
    ('Extra Bacon', 'Extra', 50.00, NULL),
    ('Coke', 'Drinks', 80.00, 'Sizes: 1.5, Kasalo, Mismo'),
    ('Sprite', 'Drinks', 80.00, 'Sizes: 1.5, Kasalo, Mismo'),
    ('Royal', 'Drinks', 80.00, 'Sizes: 1.5, Kasalo, Mismo');

START TRANSACTION;

INSERT INTO categories (name, category_type)
SELECT desired.name, 'menu'
FROM (
    SELECT 'Silog Meals' AS name
    UNION ALL SELECT 'Drinks'
    UNION ALL SELECT 'Hungry Meals'
    UNION ALL SELECT 'Set Meals'
    UNION ALL SELECT 'Hungry Hooray'
    UNION ALL SELECT 'Wings & Dips'
    UNION ALL SELECT 'Extra'
) AS desired
WHERE NOT EXISTS (
    SELECT 1
    FROM categories existing
    WHERE existing.category_type = 'menu'
      AND LOWER(existing.name) = LOWER(desired.name)
);

UPDATE menu_items AS existing
INNER JOIN tmp_update_menu AS desired
    ON LOWER(existing.name) = LOWER(desired.name)
INNER JOIN categories AS category
    ON category.category_type = 'menu'
   AND LOWER(category.name) = LOWER(desired.category_name)
SET existing.category_id = category.id,
    existing.price = desired.price,
    existing.description = COALESCE(desired.description, existing.description),
    existing.is_available = 1,
    existing.is_active = 1;

INSERT INTO menu_items
    (category_id, name, description, price, is_available, is_active)
SELECT category.id,
       desired.name,
       COALESCE(desired.description, ''),
       desired.price,
       1,
       1
FROM tmp_update_menu AS desired
INNER JOIN categories AS category
    ON category.category_type = 'menu'
   AND LOWER(category.name) = LOWER(desired.category_name)
WHERE NOT EXISTS (
    SELECT 1
    FROM menu_items existing
    WHERE LOWER(existing.name) = LOWER(desired.name)
);

UPDATE menu_items AS existing
LEFT JOIN tmp_update_menu AS desired
    ON LOWER(existing.name) = LOWER(desired.name)
SET existing.is_active = 0
WHERE existing.is_active = 1
  AND desired.name IS NULL;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_update_menu;
