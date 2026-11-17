<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(ROLE_POLICY_ADMIN);
require_once __DIR__ . '/config/db.php';

$flashMessage = $_SESSION['menu_message'] ?? '';
unset($_SESSION['menu_message']);
$flashType = $_SESSION['menu_message_type'] ?? 'success';
unset($_SESSION['menu_message_type']);
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : 'active';
if (!in_array($status, ['active', 'archived'], true)) {
    $status = 'active';
}
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$categoryFilter = isset($_GET['category_id']) && is_string($_GET['category_id'])
    ? filter_var($_GET['category_id'], FILTER_VALIDATE_INT)
    : null;
if ($categoryFilter === false || $categoryFilter < 1) {
    $categoryFilter = null;
}

$categoryStatement = $pdo->query(
    "SELECT id, name FROM categories WHERE category_type = 'menu' ORDER BY name"
);
$categories = $categoryStatement->fetchAll();
$inventoryStatement = $pdo->query(
    'SELECT id, name, unit, is_active FROM inventory_items ORDER BY is_active DESC, name'
);
$inventoryItems = $inventoryStatement->fetchAll();
$recipeUnits = ['g', 'kg', 'mg', 'mL', 'L', 'pcs', 'serving', 'servings', 'glasses', 'bottle', 'pack', 'box', 'can', 'bag'];
foreach ($inventoryItems as $inventoryItem) {
    if (!in_array($inventoryItem['unit'], $recipeUnits, true)) {
        $recipeUnits[] = (string) $inventoryItem['unit'];
    }
}

$where = ['menu.is_active = :is_active'];
$parameters = ['is_active' => $status === 'active' ? 1 : 0];
if ($search !== '') {
    $where[] = '(menu.name LIKE :name_search OR menu.description LIKE :description_search OR categories.name LIKE :category_search)';
    $term = '%' . $search . '%';
    $parameters['name_search'] = $term;
    $parameters['description_search'] = $term;
    $parameters['category_search'] = $term;
}
if ($categoryFilter !== null) {
    $where[] = 'menu.category_id = :category_id';
    $parameters['category_id'] = $categoryFilter;
}
$menuStatement = $pdo->prepare(
    'SELECT menu.id, menu.name, menu.description, menu.price, menu.image_url,
            menu.is_available, menu.category_id, categories.name AS category_name,
            (SELECT COUNT(*) FROM menu_recipes recipe WHERE recipe.menu_item_id = menu.id) AS recipe_count,
            (SELECT COUNT(*)
             FROM menu_recipes recipe
             INNER JOIN inventory_items inventory ON inventory.id = recipe.inventory_item_id
             WHERE recipe.menu_item_id = menu.id AND inventory.is_active = 0) AS inactive_recipe_count
     FROM menu_items menu
     INNER JOIN categories ON categories.id = menu.category_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY categories.name, menu.name'
);
$menuStatement->execute($parameters);
$menuItems = $menuStatement->fetchAll();

$recipesByMenuItem = [];
$recipeStatement = $pdo->query(
    'SELECT recipe.menu_item_id, recipe.inventory_item_id, recipe.quantity_required,
            inventory.name AS inventory_name, inventory.unit, inventory.is_active
     FROM menu_recipes recipe
     INNER JOIN inventory_items inventory ON inventory.id = recipe.inventory_item_id
     ORDER BY inventory.name'
);
foreach ($recipeStatement->fetchAll() as $recipe) {
    $recipesByMenuItem[(int) $recipe['menu_item_id']][] = [
        'inventoryId' => (int) $recipe['inventory_item_id'],
        'quantity' => number_format((float) $recipe['quantity_required'], 2, '.', ''),
        'inventoryName' => (string) $recipe['inventory_name'],
        'unit' => (string) $recipe['unit'],
        'active' => (int) $recipe['is_active'] === 1,
    ];
}
$menuItemData = static function (array $item) use ($recipesByMenuItem): array {
    return [
        'id' => (int) $item['id'],
        'name' => (string) $item['name'],
        'categoryId' => (int) $item['category_id'],
        'price' => number_format((float) $item['price'], 2, '.', ''),
        'image' => (string) ($item['image_url'] ?? ''),
        'available' => (int) $item['is_available'] === 1,
        'recipes' => $recipesByMenuItem[(int) $item['id']] ?? [],
    ];
};

$pageTitle = 'Menu Management';
$currentPage = 'menu-management';
$hidePageHeading = true;
$pageScript = 'assets/js/menu-management.js';
require __DIR__ . '/includes/header.php';
?>
<?php
$returnQuery = http_build_query(array_filter([
    'status' => $status,
    'search' => $search,
    'category_id' => $categoryFilter,
], static fn (mixed $value): bool => $value !== null && $value !== ''));
?>
<div class="flex w-full flex-col gap-6">
    <section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
            <p class="mb-1 font-label-md uppercase tracking-wider text-on-surface-variant">Administration</p>
            <h2 class="font-body-md-bold text-2xl text-primary sm:text-3xl">Menu Management</h2>
            <p class="mt-1 font-body-md text-on-surface-variant">Manage dishes, menu categories, and ordering availability.</p>
        </div>
        <button class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary shadow-sm transition-colors hover:bg-primary-container" data-open-add type="button">
            <span aria-hidden="true" class="text-xl leading-none">+</span>
            <span>Add Dish</span>
        </button>
    </section>

    <?php if ($flashMessage !== ''): ?>
        <div class="rounded-xl border p-4 font-body-md <?= $flashType === 'error' ? 'border-error/30 bg-error-container text-on-error-container' : 'border-primary-fixed bg-primary-fixed text-on-primary-fixed-variant' ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>"><?= h($flashMessage) ?></div>
    <?php endif; ?>

    <section class="overflow-hidden rounded-2xl bg-surface-container-lowest shadow-sm">
        <div class="flex flex-col gap-4 bg-surface-container-low p-4 lg:flex-row lg:items-center lg:justify-between">
            <nav aria-label="Dish status" class="flex gap-2">
                <?php foreach (['active' => 'Active dishes', 'archived' => 'Archived'] as $key => $label): ?>
                    <?php $tabQuery = http_build_query(array_filter([
                        'status' => $key,
                        'search' => $search,
                        'category_id' => $categoryFilter,
                    ], static fn (mixed $value): bool => $value !== null && $value !== '')); ?>
                    <a aria-current="<?= $status === $key ? 'page' : 'false' ?>" class="rounded-lg px-4 py-2 font-label-md <?= $status === $key ? 'bg-primary-container text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>" href="menu-management.php?<?= h($tabQuery) ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <form action="menu-management.php" class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto] lg:min-w-[36rem]" method="get">
                <input name="status" type="hidden" value="<?= h($status) ?>">
                <label class="sr-only" for="menu-admin-search">Search dishes</label>
                <input class="h-10 min-w-0 rounded-lg bg-surface px-3 text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container" id="menu-admin-search" name="search" placeholder="Search dishes..." type="search" value="<?= h($search) ?>">
                <label class="sr-only" for="menu-admin-category">Filter by category</label>
                <select class="h-10 min-w-0 rounded-lg bg-surface px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" id="menu-admin-category" name="category_id">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= h($category['id']) ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= h($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="min-h-10 rounded-lg bg-primary px-4 font-body-md-bold text-on-primary hover:bg-primary-container" type="submit">Filter</button>
            </form>
        </div>

        <?php if ($menuItems === []): ?>
            <div class="px-6 py-14 text-center">
                <p class="font-body-md-bold text-xl text-primary">No dishes found</p>
                <p class="mt-2 font-body-md text-on-surface-variant"><?= $search !== '' || $categoryFilter !== null ? 'Try another search or category.' : 'Add a dish to get started.' ?></p>
            </div>
        <?php else: ?>
            <div class="space-y-3 p-4 md:hidden">
                <?php foreach ($menuItems as $item): ?>
                    <?php
                    $recipeCount = (int) $item['recipe_count'];
                    $inactiveRecipeCount = (int) $item['inactive_recipe_count'];
                    $recipeReady = $recipeCount > 0 && $inactiveRecipeCount === 0;
                    $isAvailable = (int) $item['is_available'] === 1 && $recipeReady;
                    $itemData = $menuItemData($item);
                    ?>
                    <article class="rounded-xl border border-surface-container-high p-4">
                        <div class="flex items-start gap-3">
                            <?php if (is_string($item['image_url']) && $item['image_url'] !== ''): ?>
                                <img alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover" src="<?= h($item['image_url']) ?>">
                            <?php else: ?>
                                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-surface-container text-primary-container">
                                    <span aria-hidden="true" class="material-symbols-outlined text-2xl">restaurant</span>
                                </div>
                            <?php endif; ?>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate font-body-md-bold text-body-md-bold text-primary"><?= h($item['name']) ?></h3>
                                <p class="mt-0.5 font-label-md text-on-surface-variant"><?= h($item['category_name']) ?></p>
                                <p class="mt-1 font-body-md-bold text-primary">₱<?= h(number_format((float) $item['price'], 2)) ?></p>
                                <?php if ($recipeCount === 0): ?>
                                    <span class="mt-2 inline-flex rounded-full bg-secondary-container px-2.5 py-1 font-label-sm text-on-secondary-container">No recipe</span>
                                <?php elseif ($inactiveRecipeCount > 0): ?>
                                    <span class="mt-2 inline-flex rounded-full bg-error-container px-2.5 py-1 font-label-sm text-on-error-container">Inactive ingredient</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-surface-container-high pt-3">
                            <form action="actions/menu-save.php" method="post">
                                <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                                <input name="action" type="hidden" value="toggle_availability">
                                <input name="item_id" type="hidden" value="<?= h($item['id']) ?>">
                                <input name="is_available" type="hidden" value="<?= $isAvailable ? '0' : '1' ?>">
                                <input name="return_query" type="hidden" value="<?= h($returnQuery) ?>">
                                <button aria-pressed="<?= $isAvailable ? 'true' : 'false' ?>" class="rounded-full px-3 py-1 font-label-sm <?= $isAvailable ? 'bg-primary-fixed text-primary' : 'bg-surface-container text-on-surface-variant' ?> disabled:cursor-not-allowed disabled:opacity-60" <?= $status === 'archived' || !$recipeReady ? 'disabled title="' . ($status === 'archived' ? 'Restore this dish to change ordering availability.' : 'Add active recipe ingredients before making this dish available.') . '"' : '' ?> type="submit"><?= $isAvailable ? 'Available' : 'Unavailable' ?></button>
                            </form>
                            <div class="flex gap-2">
                                <button class="min-h-9 rounded-lg bg-surface-container px-3 font-label-md text-primary hover:bg-surface-container-high" data-edit-dish="<?= h(json_encode($itemData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>" type="button">Edit</button>
                                <button class="min-h-9 rounded-lg px-3 font-label-md <?= $status === 'active' ? 'bg-error-container text-on-error-container' : 'bg-surface-container text-primary' ?>" data-confirm-action="<?= $status === 'active' ? 'archive' : 'restore' ?>" data-item-id="<?= h($item['id']) ?>" data-item-name="<?= h($item['name']) ?>" type="button"><?= $status === 'active' ? 'Archive' : 'Restore' ?></button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[760px] text-left">
                    <thead class="bg-surface-container-low text-label-sm uppercase tracking-wider text-on-surface-variant">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Image</th>
                            <th class="px-5 py-3 font-semibold">Dish name</th>
                            <th class="px-5 py-3 font-semibold">Category</th>
                            <th class="px-5 py-3 font-semibold">Price</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container-high">
                        <?php foreach ($menuItems as $item): ?>
                            <?php
                            $recipeCount = (int) $item['recipe_count'];
                            $inactiveRecipeCount = (int) $item['inactive_recipe_count'];
                            $recipeReady = $recipeCount > 0 && $inactiveRecipeCount === 0;
                            $isAvailable = (int) $item['is_available'] === 1 && $recipeReady;
                            $itemData = $menuItemData($item);
                            ?>
                            <tr class="hover:bg-surface-container-low/60">
                                <td class="px-5 py-3">
                                    <?php if (is_string($item['image_url']) && $item['image_url'] !== ''): ?>
                                        <img alt="" class="h-12 w-12 rounded-lg object-cover" src="<?= h($item['image_url']) ?>">
                                    <?php else: ?>
                                        <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-surface-container text-primary-container">
                                            <span aria-hidden="true" class="material-symbols-outlined">restaurant</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 font-body-md-bold text-primary"><?= h($item['name']) ?></td>
                                <td class="px-5 py-3 font-body-md text-on-surface-variant"><?= h($item['category_name']) ?></td>
                                <td class="px-5 py-3 font-body-md text-on-surface">₱<?= h(number_format((float) $item['price'], 2)) ?></td>
                                <td class="px-5 py-3">
                                    <form action="actions/menu-save.php" method="post">
                                        <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                                        <input name="action" type="hidden" value="toggle_availability">
                                        <input name="item_id" type="hidden" value="<?= h($item['id']) ?>">
                                        <input name="is_available" type="hidden" value="<?= $isAvailable ? '0' : '1' ?>">
                                        <input name="return_query" type="hidden" value="<?= h($returnQuery) ?>">
                                        <div class="flex flex-col items-start gap-1">
                                            <?php if ($recipeCount === 0): ?>
                                                <span class="rounded-full bg-secondary-container px-2.5 py-1 font-label-sm text-on-secondary-container">No recipe</span>
                                            <?php elseif ($inactiveRecipeCount > 0): ?>
                                                <span class="rounded-full bg-error-container px-2.5 py-1 font-label-sm text-on-error-container">Inactive ingredient</span>
                                            <?php endif; ?>
                                            <button aria-pressed="<?= $isAvailable ? 'true' : 'false' ?>" class="rounded-full px-3 py-1 font-label-sm <?= $isAvailable ? 'bg-primary-fixed text-primary' : 'bg-surface-container text-on-surface-variant' ?> disabled:cursor-not-allowed disabled:opacity-60" <?= $status === 'archived' || !$recipeReady ? 'disabled title="' . ($status === 'archived' ? 'Restore this dish to change ordering availability.' : 'Add active recipe ingredients before making this dish available.') . '"' : '' ?> type="submit"><?= $isAvailable ? 'Available' : 'Unavailable' ?></button>
                                        </div>
                                    </form>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button class="min-h-9 rounded-lg bg-surface-container px-3 font-label-md text-primary hover:bg-surface-container-high" data-edit-dish="<?= h(json_encode($itemData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>" type="button">Edit</button>
                                        <button class="min-h-9 rounded-lg px-3 font-label-md <?= $status === 'active' ? 'bg-error-container text-on-error-container' : 'bg-surface-container text-primary' ?>" data-confirm-action="<?= $status === 'active' ? 'archive' : 'restore' ?>" data-item-id="<?= h($item['id']) ?>" data-item-name="<?= h($item['name']) ?>" type="button"><?= $status === 'active' ? 'Archive' : 'Restore' ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<dialog aria-labelledby="menu-modal-title" class="m-auto w-[calc(100%-2rem)] max-w-2xl rounded-2xl bg-surface-container-lowest p-0 text-on-surface shadow-2xl backdrop:bg-primary/40" data-menu-dialog>
    <form action="actions/menu-save.php" class="flex max-h-[90vh] flex-col" enctype="multipart/form-data" id="menu-dish-form" method="post">
        <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
        <input name="action" type="hidden" value="save">
        <input data-menu-item-id name="item_id" type="hidden" value="">
        <input name="return_query" type="hidden" value="<?= h($returnQuery) ?>">
        <div class="flex items-start justify-between gap-4 border-b border-surface-container-high px-5 py-4 sm:px-6">
            <div>
                <h2 class="font-body-md-bold text-xl text-primary" data-menu-modal-title id="menu-modal-title">Add Dish</h2>
                <p class="mt-1 font-body-md text-on-surface-variant">Dish details and ordering availability.</p>
            </div>
            <button aria-label="Close dialog" class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container" data-close-menu-dialog type="button">
                <span aria-hidden="true" class="text-xl">×</span>
            </button>
        </div>
        <div class="grid gap-4 overflow-y-auto p-5 sm:grid-cols-2 sm:p-6">
            <label class="flex flex-col gap-1 font-label-md text-on-surface-variant sm:col-span-2">Dish name
                <input class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" maxlength="100" name="name" required>
            </label>
            <label class="flex flex-col gap-1 font-label-md text-on-surface-variant">Category
                <select class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" name="category_id" required>
                    <option value="">Choose category</option>
                    <?php foreach ($categories as $category): ?><option value="<?= h($category['id']) ?>"><?= h($category['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="flex flex-col gap-1 font-label-md text-on-surface-variant">Price (₱)
                <input class="h-11 rounded-lg bg-surface-container-low px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" min="0" name="price" required step="0.01" type="number">
            </label>
            <div class="flex flex-col gap-2 font-label-md text-on-surface-variant sm:col-span-2" data-image-upload-control>
                <input name="MAX_FILE_SIZE" type="hidden" value="2097152">
                <label class="flex flex-col gap-1">Dish image (JPG, PNG, WEBP; max 2 MB)
                    <input accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="min-h-11 rounded-lg bg-surface-container-low px-3 py-2 text-on-surface file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1 file:font-body-md-bold file:text-on-primary" data-image-upload name="image" type="file">
                </label>
                <div class="flex items-center gap-3">
                    <img alt="Dish image preview" class="hidden h-16 w-16 rounded-lg object-cover" data-current-image="false" data-image-preview>
                    <div class="flex h-16 w-16 items-center justify-center rounded-lg bg-surface-container text-primary-container" data-image-placeholder>
                        <span aria-hidden="true" class="material-symbols-outlined text-2xl">restaurant</span>
                    </div>
                    <span class="font-label-sm text-on-surface-variant" data-image-hint>No image yet</span>
                </div>
                <p class="hidden font-label-sm text-error" data-image-error role="alert"></p>
            </div>
            <section class="rounded-xl border border-surface-container-high p-4 sm:col-span-2" data-recipe-editor>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="font-body-md-bold text-primary">Recipe</h3>
                        <p class="mt-1 font-label-sm text-on-surface-variant">Recipe units must match the ingredient's inventory unit. Unit conversion is not supported.</p>
                    </div>
                    <button class="min-h-10 rounded-lg bg-surface-container px-4 font-label-md text-primary hover:bg-surface-container-high" data-add-recipe-row type="button">Add ingredient</button>
                </div>
                <p class="mt-3 rounded-lg bg-secondary-container px-3 py-2 font-label-sm text-on-secondary-container" data-recipe-empty>No recipe added yet. This dish will stay unavailable until ingredients are configured.</p>
                <div class="mt-3 space-y-3" data-recipe-rows></div>
                <template data-recipe-row-template>
                    <div class="grid gap-2 rounded-lg bg-surface-container-low p-3 sm:grid-cols-[minmax(7rem,1fr)_7rem_6rem_auto] sm:items-end" data-recipe-row>
                        <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant">Ingredient
                            <select class="h-10 min-w-0 rounded-lg bg-surface-container-lowest px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" data-recipe-ingredient name="recipes[__INDEX__][inventory_item_id]" required>
                                <option value="">Choose ingredient</option>
                                <?php foreach ($inventoryItems as $inventoryItem): ?>
                                    <option data-unit="<?= h($inventoryItem['unit']) ?>" value="<?= h($inventoryItem['id']) ?>"><?= h($inventoryItem['name'] . ' (' . $inventoryItem['unit'] . ')' . ((int) $inventoryItem['is_active'] === 1 ? '' : ' — inactive')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant">Qty per serving
                            <input class="h-10 rounded-lg bg-surface-container-lowest px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" data-recipe-quantity max="99999999.99" min="0.01" name="recipes[__INDEX__][quantity_required]" placeholder="e.g. 1" required step="0.01" type="number">
                        </label>
                        <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant">Unit
                            <select class="h-10 min-w-0 rounded-lg bg-surface-container-lowest px-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" data-recipe-unit name="recipes[__INDEX__][unit]" required>
                                <option value="">Choose unit</option>
                                <?php foreach ($recipeUnits as $recipeUnit): ?>
                                    <option value="<?= h($recipeUnit) ?>"><?= h($recipeUnit) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button aria-label="Remove ingredient" class="min-h-10 rounded-lg bg-error-container px-3 font-label-md text-on-error-container hover:opacity-80" data-remove-recipe-row type="button">Remove</button>
                    </div>
                </template>
                <p class="mt-2 hidden font-label-sm text-error" data-recipe-error role="alert"></p>
            </section>
            <label class="flex items-center gap-3 rounded-xl bg-surface-container-low p-3 font-body-md text-on-surface sm:col-span-2">
                <input class="h-4 w-4 accent-primary" data-menu-available name="is_available" type="checkbox" value="1">
                <span>Available for ordering</span>
            </label>
        </div>
        <div class="flex flex-col-reverse gap-2 border-t border-surface-container-high px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
            <button class="min-h-11 rounded-lg bg-surface-container px-5 font-body-md-bold text-primary hover:bg-surface-container-high" data-close-menu-dialog type="button">Cancel</button>
            <button class="min-h-11 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary hover:bg-primary-container" data-menu-save type="submit">Add Dish</button>
        </div>
    </form>
</dialog>

<dialog aria-labelledby="menu-confirm-title" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl bg-surface-container-lowest p-0 text-on-surface shadow-2xl backdrop:bg-primary/40" data-menu-confirm-dialog>
    <div class="p-6">
        <h2 class="font-body-md-bold text-xl text-primary" id="menu-confirm-title" data-menu-confirm-title>Confirm action</h2>
        <p class="mt-2 font-body-md text-on-surface-variant" data-menu-confirm-message></p>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button class="min-h-11 rounded-lg bg-surface-container px-5 font-body-md-bold text-primary hover:bg-surface-container-high" data-close-menu-confirm type="button">Cancel</button>
            <button class="min-h-11 rounded-lg bg-error-container px-5 font-body-md-bold text-on-error-container" data-submit-menu-action type="button">Confirm</button>
        </div>
    </div>
</dialog>
<form action="actions/menu-save.php" class="hidden" id="menu-action-form" method="post">
    <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
    <input data-menu-action name="action" type="hidden" value="">
    <input data-menu-action-item name="item_id" type="hidden" value="">
    <input name="return_query" type="hidden" value="<?= h($returnQuery) ?>">
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
