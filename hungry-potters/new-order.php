<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(ROLE_POLICY_ORDER_ENTRY);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/order-queue.php';

$menuStatement = $pdo->query(
    "SELECT
        menu.id,
        menu.name,
        menu.description,
        menu.price,
        menu.image_url,
        categories.name AS category_name,
        COALESCE(
            MIN(
                CASE
                    WHEN recipe.inventory_item_id IS NOT NULL
                        AND inventory.is_active = 1
                    THEN FLOOR(inventory.current_stock / recipe.quantity_required)
                    ELSE NULL
                END
            ),
            0
        ) AS available_quantity,
        COUNT(recipe.inventory_item_id) AS recipe_item_count,
        SUM(
            CASE
                WHEN recipe.inventory_item_id IS NULL OR inventory.is_active = 0 THEN 1
                ELSE 0
            END
        ) AS missing_recipe_count
     FROM menu_items menu
     INNER JOIN categories ON categories.id = menu.category_id
     LEFT JOIN menu_recipes recipe ON recipe.menu_item_id = menu.id
     LEFT JOIN inventory_items inventory ON inventory.id = recipe.inventory_item_id
     WHERE menu.is_active = 1 AND menu.is_available = 1
     GROUP BY menu.id, menu.name, menu.description, menu.price, menu.image_url, categories.name
     ORDER BY categories.name, menu.name"
);
$menuItems = $menuStatement->fetchAll();

$categoryStatement = $pdo->query(
    "SELECT DISTINCT categories.id, categories.name
     FROM categories
     INNER JOIN menu_items ON menu_items.category_id = categories.id
     WHERE categories.category_type = 'menu'
       AND menu_items.is_active = 1 AND menu_items.is_available = 1
     ORDER BY categories.name"
);
$menuCategories = $categoryStatement->fetchAll();

$queuePreview = order_queue_preview($pdo);
$nextQueueNumber = $queuePreview['next_queue_number'];
$nextOrderNumber = format_order_number($queuePreview['business_date'], $nextQueueNumber);
$flashMessage = $_SESSION['pos_message'] ?? '';
unset($_SESSION['pos_message']);
$flashType = $_SESSION['pos_message_type'] ?? 'success';
unset($_SESSION['pos_message_type']);

$pageTitle = 'New Order';
$currentPage = 'new-order';
$pageScript = 'assets/js/pos.js';
require __DIR__ . '/includes/header.php';
?>
<div
    class="grid w-full grid-cols-1 items-start gap-6 lg:grid-cols-12"
    data-pos
    data-cashier="<?= h($_SESSION['user_id']) ?>"
    data-order-endpoint="actions/order-place.php"
    data-csrf-token="<?= h(csrf_token()) ?>"
    data-queue-number="<?= h($nextQueueNumber) ?>"
    data-order-number="<?= h($nextOrderNumber) ?>"
>
    <section class="flex flex-col gap-5 lg:col-span-7">
        <?php if ($flashMessage !== ''): ?>
            <div class="rounded-xl border p-4 font-body-md text-body-md <?= $flashType === 'error' ? 'border-error/30 bg-error-container text-on-error-container' : 'border-primary-fixed bg-primary-fixed text-on-primary-fixed-variant' ?>" role="status">
                <?= h($flashMessage) ?>
            </div>
        <?php endif; ?>

        <div class="flex flex-col items-stretch justify-between gap-4 sm:flex-row sm:items-center">
            <nav aria-label="Menu categories" class="flex items-center gap-1.5 overflow-x-auto rounded-2xl bg-surface-container-low p-1.5 shadow-sm" data-menu-categories>
                <button class="min-h-11 shrink-0 rounded-xl bg-primary px-5 font-body-md-bold text-body-md-bold text-on-primary shadow-sm transition-colors" data-category-filter="all" type="button">All Items (<?= h(count($menuItems)) ?>)</button>
                <?php foreach ($menuCategories as $category): ?>
                    <?php
                    $categoryCount = 0;
                    foreach ($menuItems as $menuItem) {
                        if ($menuItem['category_name'] === $category['name']) {
                            $categoryCount++;
                        }
                    }
                    ?>
                    <button class="min-h-11 shrink-0 rounded-xl px-5 font-body-md text-body-md text-on-surface-variant transition-colors hover:bg-surface-container hover:text-on-surface" data-category-filter="<?= h($category['name']) ?>" type="button">
                        <?= h($category['name']) ?> (<?= h($categoryCount) ?>)
                    </button>
                <?php endforeach; ?>
            </nav>
            <div class="relative min-w-[220px]">
                <label class="sr-only" for="menu-search">Search menu</label>
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant">search</span>
                <input class="h-12 w-full rounded-xl bg-surface-container-lowest pl-10 pr-4 font-body-md text-body-md text-on-surface shadow-sm outline-none transition focus:ring-2 focus:ring-primary-container" data-menu-search id="menu-search" placeholder="Search menu..." type="search">
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2" data-menu-grid>
            <?php foreach ($menuItems as $item): ?>
                <?php
                $availableQuantity = max(0, (int) $item['available_quantity']);
                $hasRecipe = (int) $item['recipe_item_count'] > 0;
                $hasActiveRecipe = $hasRecipe && (int) $item['missing_recipe_count'] === 0;
                $isAvailable = $hasActiveRecipe
                    && $availableQuantity > 0;
                $unavailableMessage = !$hasRecipe
                    ? 'Recipe needed'
                    : (!$hasActiveRecipe ? 'Inactive recipe ingredient' : 'Out of stock');
                $availabilityIcon = $isAvailable
                    ? ($availableQuantity <= 6 ? 'warning' : 'check_circle')
                    : (!$hasRecipe ? 'restaurant_menu' : 'block');
                ?>
                <article
                    class="flex flex-col justify-between rounded-2xl <?= $isAvailable ? 'bg-surface-container-lowest shadow-sm hover:shadow-md' : 'relative overflow-hidden bg-surface-container-low opacity-80 shadow-none' ?> p-4 transition-shadow"
                    data-menu-card
                    data-category="<?= h($item['category_name']) ?>"
                    data-search="<?= h(strtolower($item['name'] . ' ' . $item['description'] . ' ' . $item['category_name'])) ?>"
                    data-available="<?= $isAvailable ? 'true' : 'false' ?>"
                    data-item-id="<?= h($item['id']) ?>"
                    data-item-name="<?= h($item['name']) ?>"
                    data-item-price="<?= h(number_format((float) $item['price'], 2, '.', '')) ?>"
                    data-item-available-quantity="<?= h($availableQuantity) ?>"
                >
                    <div>
                        <div class="relative mb-3 h-36 w-full overflow-hidden rounded-xl bg-surface-container">
                            <?php if ($item['image_url']): ?>
                                <img alt="<?= h($item['name']) ?>" class="h-full w-full object-cover" src="<?= h($item['image_url']) ?>">
                            <?php else: ?>
                                <div class="flex h-full w-full items-center justify-center text-primary-container">
                                    <span class="material-symbols-outlined text-[48px]">restaurant</span>
                                </div>
                            <?php endif; ?>
                            <div class="absolute right-2.5 top-2.5 flex items-center gap-1 rounded-full px-2.5 py-1 shadow-sm <?= $isAvailable ? ($availableQuantity <= 6 ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-tertiary-fixed text-on-tertiary-fixed') : 'bg-error-container text-on-error-container' ?>">
                                <span class="material-symbols-outlined text-[15px] <?= $isAvailable ? ($availableQuantity <= 6 ? 'text-secondary' : 'text-tertiary-container') : 'text-error' ?>"><?= h($availabilityIcon) ?></span>
                                <span class="font-label-sm text-label-sm font-semibold"><?= $isAvailable ? 'Available (' . h($availableQuantity) . ')' : h($unavailableMessage) ?></span>
                            </div>
                        </div>
                        <div class="mb-1 flex items-start justify-between gap-2">
                            <h2 class="font-headline-sm text-headline-sm font-semibold text-on-surface"><?= h($item['name']) ?></h2>
                            <span class="shrink-0 font-headline-sm text-headline-sm font-bold tracking-tight text-primary">₱<?= h(number_format((float) $item['price'], 2)) ?></span>
                        </div>
                        <p class="mb-4 font-body-md text-body-md leading-relaxed text-on-surface-variant"><?= h($item['description']) ?></p>
                    </div>
                    <button
                        class="flex min-h-12 w-full items-center justify-center gap-1 rounded-xl font-body-md-bold text-body-md-bold transition-all <?= $isAvailable ? 'bg-primary text-on-primary shadow-sm hover:bg-primary-container active:scale-[0.98]' : 'cursor-not-allowed bg-surface-container-highest text-outline' ?>"
                        data-add-item
                        <?= $isAvailable ? '' : 'disabled' ?>
                        type="button"
                    >
                        <span class="material-symbols-outlined text-[20px]"><?= $isAvailable ? 'add' : 'block' ?></span>
                        <span><?= $isAvailable ? '+ Add to Ticket' : h($unavailableMessage) ?></span>
                    </button>
                </article>
            <?php endforeach; ?>
            <p class="col-span-full hidden rounded-xl bg-surface-container-low p-6 text-center font-body-md text-body-md text-on-surface-variant" data-no-menu-results>No menu items match this search.</p>
        </div>

        <div class="flex items-center gap-4 rounded-2xl bg-surface-container p-4 shadow-sm">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-container text-on-primary">
                <span class="material-symbols-outlined text-[22px]">inventory_2</span>
            </div>
            <div>
                <span class="block font-body-md-bold text-body-md-bold text-on-surface">Automatic Stock Checks</span>
                <span class="font-body-md text-body-md text-on-surface-variant">Menu availability is calculated from current recipe ingredients.</span>
            </div>
        </div>
    </section>

    <aside class="flex flex-col gap-4 lg:sticky lg:top-24 lg:col-span-5">
        <section class="relative flex flex-col gap-4 overflow-hidden rounded-3xl bg-surface-container-lowest p-6 shadow-md">
            <div class="-mx-6 -mt-6 flex items-center justify-between bg-primary px-6 py-4 text-on-primary shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-secondary-container px-4 py-2 text-on-secondary-container shadow-inner">
                        <span class="font-headline-xl text-[32px] font-bold leading-none tracking-tight">Q-<?= h(str_pad((string) $nextQueueNumber, 3, '0', STR_PAD_LEFT)) ?></span>
                    </div>
                    <div>
                        <span class="block font-label-sm text-label-sm uppercase tracking-wider text-on-primary-container">Next in Queue</span>
                        <span class="font-body-md-bold text-body-md-bold"><?= h($nextOrderNumber) ?></span>
                    </div>
                </div>
                <div class="text-right">
                    <span class="font-mono-metric text-[18px] font-bold text-on-primary" data-live-clock></span>
                    <span class="block font-label-sm text-label-sm text-on-primary-container">POS-01 &bull; Front Counter</span>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <span class="font-label-md text-label-md font-semibold uppercase tracking-wider text-on-surface-variant">Itemized Ticket List</span>
                <span class="rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-label-sm font-body-md-bold text-primary"><span data-ticket-unit-count>0</span> units</span>
            </div>

            <div class="flex max-h-[300px] min-h-24 flex-col gap-2 overflow-y-auto pr-1" data-ticket-items>
                <div class="flex flex-col items-center justify-center gap-1 rounded-2xl bg-surface-container-low p-6 text-center text-on-surface-variant" data-empty-ticket>
                    <span class="material-symbols-outlined text-[32px] text-outline">shopping_bag</span>
                    <span class="font-body-md-bold text-body-md-bold">Ticket is empty</span>
                    <span class="font-label-sm text-label-sm">Select menu items to start an order.</span>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="flex items-center gap-1 font-label-md text-label-md font-semibold text-on-surface-variant" for="order-type">
                            <span class="material-symbols-outlined text-[16px]">local_dining</span>Order Type
                        </label>
                        <select class="h-12 rounded-xl bg-surface-container-low px-3 font-body-md text-body-md text-on-surface outline-none focus:ring-2 focus:ring-primary-container" data-order-type id="order-type" name="order_type">
                            <option value="dine_in">Dine-In</option>
                            <option value="takeout">Takeout</option>
                            <option value="delivery">Delivery</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1" data-order-location-field>
                        <label class="font-label-md text-label-md font-semibold text-on-surface-variant" for="order-location">Table / Pickup</label>
                        <input class="h-12 min-w-0 rounded-xl bg-surface-container-low px-3 font-body-md text-body-md text-on-surface outline-none focus:ring-2 focus:ring-primary-container" data-order-location id="order-location" maxlength="100" name="location" placeholder="Table 04">
                    </div>
                    <div class="flex flex-col gap-1" data-takeout-slip-hint hidden>
                        <label class="font-label-md text-label-md font-semibold text-on-surface-variant">Slip #</label>
                        <div class="flex h-12 min-w-0 items-center rounded-xl bg-surface-container-low px-3">
                            <span class="font-body-md text-body-md text-on-surface-variant">Generated automatically when the order is placed</span>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="flex items-center gap-1 font-label-md text-label-md font-semibold text-on-surface-variant" for="kitchen-note">
                        <span class="material-symbols-outlined text-[16px]">edit_note</span>Kitchen / Expedite Note
                    </label>
                    <input class="h-12 w-full rounded-xl bg-surface-container-low px-4 font-body-md text-body-md text-on-surface outline-none transition focus:ring-2 focus:ring-primary-container" data-kitchen-note id="kitchen-note" maxlength="500" placeholder="Add kitchen instruction...">
                </div>
            </div>

            <div class="flex flex-col gap-1.5 rounded-2xl bg-surface-container-low p-4">
                <div class="flex justify-between font-body-md text-body-md text-on-surface-variant">
                    <span>Subtotal</span>
                    <span class="font-mono-metric text-[16px] text-on-surface">₱<span data-ticket-subtotal>0.00</span></span>
                </div>
                <div class="flex justify-between font-body-md text-body-md text-on-surface-variant">
                    <span>VAT (12% Included)</span>
                    <span class="font-mono-metric text-[16px] text-on-surface">₱<span data-ticket-vat>0.00</span></span>
                </div>
                <div class="my-1 h-px bg-surface-container-highest"></div>
                <div class="flex items-center justify-between">
                    <span class="font-headline-sm text-headline-sm font-bold text-on-surface">Total Due</span>
                    <span class="font-mono-metric text-[28px] font-bold text-primary">₱<span data-ticket-total>0.00</span></span>
                </div>
            </div>

            <div class="flex flex-col gap-2 pt-1">
                <button class="group flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl bg-primary font-body-lg-bold text-body-lg-bold text-on-primary shadow-md transition-all hover:bg-primary-container hover:shadow-lg active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50" data-submit-order disabled type="button">
                    <span class="material-symbols-outlined text-[22px] text-primary-fixed">restaurant</span>
                    <span>Proceed to checkout</span>
                    <span class="material-symbols-outlined text-[20px]">arrow_forward</span>
                </button>
                <form data-checkout hidden class="rounded-xl bg-surface-container-low p-4">
                    <h2 class="font-headline-sm text-primary">Checkout · ₱<span data-checkout-total></span></h2>
                    <label class="block mt-3">Payment method
                        <select data-method class="block w-full rounded-lg p-3">
                            <option value="cash">Cash received now</option>
                            <option value="ewallet">GCash / e-wallet</option>
                            <option data-cod value="cod">Cash on delivery</option>
                        </select>
                    </label>
                    <label data-cash-field class="block mt-3">Cash tendered (₱)
                        <input data-tender type="number" step="0.01" min="0.01" max="99999999.99" inputmode="decimal" class="block w-full rounded-lg p-3">
                        Change · ₱<output data-change>0.00</output>
                    </label>
                    <label data-reference-field hidden class="block mt-3">Transaction reference
                        <input data-reference maxlength="100" class="block w-full rounded-lg p-3">
                    </label>
                    <label data-confirm-field class="block mt-3"><input type="checkbox" data-received> <span data-confirm-label></span></label>
                    <p data-checkout-status role="status" class="mt-3"></p>
                    <button data-pay class="min-h-12 rounded-lg bg-primary px-4 text-on-primary" type="submit">Confirm checkout</button>
                    <button data-back class="min-h-12 px-4" type="button">Back to ticket</button>
                </form>
                <section data-checkout-success hidden aria-labelledby="checkout-result-title" class="pos-confirmation">
                    <div class="pos-confirmation__header">
                        <div>
                            <h2 id="checkout-result-title" data-result-title tabindex="-1"></h2>
                            <p data-result-order></p>
                        </div>
                        <div class="pos-confirmation__queue">
                            <span>Queue</span>
                            <strong data-result-queue></strong>
                        </div>
                    </div>
                    <dl class="pos-confirmation__details">
                        <div><dt data-result-total-label>Order total</dt><dd data-result-total></dd></div>
                        <div data-result-tender-row hidden><dt>Cash tendered</dt><dd data-result-tender></dd></div>
                        <div data-result-change-row hidden class="pos-confirmation__change"><dt>Change to give</dt><dd data-result-change></dd></div>
                        <div data-result-reference-row hidden><dt>E-wallet reference</dt><dd data-result-reference></dd></div>
                    </dl>
                    <div class="pos-confirmation__actions">
                        <a data-print>View / print receipt</a>
                        <a href="new-order.php" class="pos-confirmation__primary">New order</a>
                    </div>
                </section>
                <button class="flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-outline-variant/30 bg-surface-container text-body-md-bold text-body-md-bold text-on-surface-variant transition-colors hover:bg-surface-container-high" data-clear-ticket type="button">
                    <span class="material-symbols-outlined text-[18px]">delete_sweep</span>
                    <span>Clear Order</span>
                </button>
            </div>
        </section>

        <div class="flex items-center justify-between rounded-2xl bg-surface-container-low p-4 font-label-sm text-label-sm text-on-surface-variant">
            <span>Order will appear in the kitchen queue.</span>
            <span>Stock is checked and deducted on placement.</span>
        </div>
    </aside>
    <div class="fixed bottom-6 right-6 z-50 hidden max-w-md rounded-xl bg-primary px-5 py-3 font-body-md-bold text-body-md-bold text-on-primary shadow-2xl" data-pos-message role="status"></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
