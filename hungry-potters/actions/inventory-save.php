<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../config/db.php';

function inventory_redirect(string $message, string $type, ?int $itemId = null): never
{
    $_SESSION['inventory_message'] = $message;
    $_SESSION['inventory_message_type'] = $type;
    $destination = '../inventory.php';
    if ($itemId !== null && $itemId > 0) {
        $destination .= '?id=' . $itemId;
    }

    header('Location: ' . $destination);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;
if (!verify_csrf_token($submittedToken)) {
    http_response_code(400);
    exit('Invalid request token. Refresh the page and try again.');
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
$itemId = $itemId === false ? null : $itemId;

if ($action === 'restock') {
    $quantityInput = $_POST['quantity'] ?? null;
    $note = isset($_POST['note']) && is_string($_POST['note']) ? trim($_POST['note']) : '';

    if (
        $itemId === null
        || $itemId < 1
        || (!is_string($quantityInput) && !is_numeric($quantityInput))
        || !is_numeric($quantityInput)
        || (float) $quantityInput <= 0
        || (float) $quantityInput > 99999999
        || strlen($note) > 255
    ) {
        inventory_redirect('Enter a valid restock quantity and note.', 'error', $itemId);
    }

    $pdo->beginTransaction();
    try {
        $selectStatement = $pdo->prepare(
            'SELECT current_stock FROM inventory_items WHERE id = :id AND is_active = 1 FOR UPDATE'
        );
        $selectStatement->execute(['id' => $itemId]);
        $item = $selectStatement->fetch();
        if (!$item) {
            $pdo->rollBack();
            inventory_redirect('The selected stock item could not be found.', 'error');
        }

        $before = (float) $item['current_stock'];
        $change = round((float) $quantityInput, 2);
        $after = round($before + $change, 2);
        $updateStatement = $pdo->prepare(
            'UPDATE inventory_items SET current_stock = :stock WHERE id = :id'
        );
        $updateStatement->execute(['stock' => $after, 'id' => $itemId]);

        $movementStatement = $pdo->prepare(
            'INSERT INTO inventory_movements
                (inventory_item_id, changed_by, movement_type, quantity_change,
                 quantity_before, quantity_after, note)
             VALUES (:item_id, :user_id, \'restock\', :change, :before, :after, :note)'
        );
        $movementStatement->execute([
            'item_id' => $itemId,
            'user_id' => $_SESSION['user_id'],
            'change' => $change,
            'before' => $before,
            'after' => $after,
            'note' => $note !== '' ? $note : null,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    inventory_redirect('Restock saved and inventory activity recorded.', 'success', $itemId);
}

if (in_array($action, ['remove', 'restore'], true)) {
    if ($itemId === null || $itemId < 1) {
        inventory_redirect('Choose a valid inventory item.', 'error');
    }

    if ($action === 'restore') {
        $restoreStatement = $pdo->prepare(
            'UPDATE inventory_items SET is_active = 1 WHERE id = :id AND is_active = 0'
        );
        $restoreStatement->execute(['id' => $itemId]);
        inventory_redirect(
            $restoreStatement->rowCount() === 1 ? 'Ingredient restored successfully.' : 'The archived ingredient could not be found.',
            $restoreStatement->rowCount() === 1 ? 'success' : 'error'
        );
    }

    $pdo->beginTransaction();
    try {
        $itemStatement = $pdo->prepare(
            'SELECT id, current_stock FROM inventory_items WHERE id = :id AND is_active = 1 FOR UPDATE'
        );
        $itemStatement->execute(['id' => $itemId]);
        $inventoryItem = $itemStatement->fetch();
        if (!$inventoryItem) {
            $pdo->rollBack();
            inventory_redirect('The selected ingredient could not be found.', 'error');
        }
        $recipeStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM menu_recipes WHERE inventory_item_id = :id'
        );
        $recipeStatement->execute(['id' => $itemId]);
        $movementStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM inventory_movements WHERE inventory_item_id = :id'
        );
        $movementStatement->execute(['id' => $itemId]);
        $hasHistory = (int) $recipeStatement->fetchColumn() > 0
            || (int) $movementStatement->fetchColumn() > 0
            || (float) $inventoryItem['current_stock'] > 0;

        if ($hasHistory) {
            $removeStatement = $pdo->prepare('UPDATE inventory_items SET is_active = 0 WHERE id = :id');
            $removeStatement->execute(['id' => $itemId]);
            $message = 'Ingredient archived because it is used by a dish or has stock history.';
        } else {
            $removeStatement = $pdo->prepare('DELETE FROM inventory_items WHERE id = :id');
            $removeStatement->execute(['id' => $itemId]);
            $message = 'Ingredient removed.';
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Inventory removal failed: ' . $exception->getMessage());
        inventory_redirect('The ingredient could not be removed.', 'error');
    }
    inventory_redirect($message, 'success');
}

if ($action !== 'save') {
    inventory_redirect('Choose a valid inventory action.', 'error', $itemId);
}

$name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
$description = isset($_POST['description']) && is_string($_POST['description'])
    ? trim($_POST['description'])
    : '';
$unitChoice = isset($_POST['unit_choice']) && is_string($_POST['unit_choice'])
    ? trim($_POST['unit_choice'])
    : '';
$customUnit = isset($_POST['unit_custom']) && is_string($_POST['unit_custom'])
    ? trim($_POST['unit_custom'])
    : '';
$unit = $unitChoice === '__custom__' ? $customUnit : $unitChoice;
$categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
$categoryId = $categoryId === false ? null : $categoryId;
$stockInput = $_POST['current_stock'] ?? null;
$thresholdInput = $_POST['low_stock_level'] ?? null;

if (
    $name === ''
    || strlen($name) > 100
    || strlen($description) > 255
    || $unit === ''
    || strlen($unit) > 30
    || $categoryId === null
    || $categoryId < 1
    || (!is_string($stockInput) && !is_numeric($stockInput))
    || !is_numeric($stockInput)
    || (float) $stockInput < 0
    || (float) $stockInput > 99999999
    || (!is_string($thresholdInput) && !is_numeric($thresholdInput))
    || !is_numeric($thresholdInput)
    || (float) $thresholdInput < 0
    || (float) $thresholdInput > 99999999
) {
    inventory_redirect('Enter valid item details, stock and low-stock level.', 'error', $itemId);
}

$categoryStatement = $pdo->prepare(
    "SELECT id FROM categories WHERE id = :id AND category_type = 'inventory'"
);
$categoryStatement->execute(['id' => $categoryId]);
if (!$categoryStatement->fetchColumn()) {
    inventory_redirect('Choose a valid inventory category.', 'error', $itemId);
}

$stock = round((float) $stockInput, 2);
$threshold = round((float) $thresholdInput, 2);

if ($itemId !== null && $itemId > 0) {
    $pdo->beginTransaction();
    try {
        $menuLockStatement = $pdo->prepare(
            'SELECT menu.id
             FROM menu_items menu
             INNER JOIN menu_recipes recipe ON recipe.menu_item_id = menu.id
             WHERE recipe.inventory_item_id = :inventory_item_id
             ORDER BY menu.id
             FOR UPDATE'
        );
        $menuLockStatement->execute(['inventory_item_id' => $itemId]);
        $menuLockStatement->fetchAll(PDO::FETCH_COLUMN);

        $selectStatement = $pdo->prepare(
            'SELECT current_stock, unit FROM inventory_items WHERE id = :id AND is_active = 1 FOR UPDATE'
        );
        $selectStatement->execute(['id' => $itemId]);
        $existingItem = $selectStatement->fetch();
        if (!$existingItem) {
            $pdo->rollBack();
            inventory_redirect('The selected stock item could not be found.', 'error');
        }

        $before = (float) $existingItem['current_stock'];
        $unitChanged = $unit !== (string) $existingItem['unit'];
        $change = round($stock - $before, 2);
        $updateStatement = $pdo->prepare(
            'UPDATE inventory_items
             SET category_id = :category_id, name = :name, description = :description,
                 unit = :unit, current_stock = :stock, low_stock_level = :threshold
             WHERE id = :id'
        );
        $updateStatement->execute([
            'category_id' => $categoryId,
            'name' => $name,
            'description' => $description,
            'unit' => $unit,
            'stock' => $stock,
            'threshold' => $threshold,
            'id' => $itemId,
        ]);

        $disabledDishCount = 0;
        if ($unitChanged) {
            $disableDishesStatement = $pdo->prepare(
                'UPDATE menu_items menu
                 INNER JOIN menu_recipes recipe ON recipe.menu_item_id = menu.id
                 SET menu.is_available = 0
                 WHERE recipe.inventory_item_id = :inventory_item_id
                   AND menu.is_available = 1'
            );
            $disableDishesStatement->execute(['inventory_item_id' => $itemId]);
            $disabledDishCount = $disableDishesStatement->rowCount();
        }

        if ($change !== 0.0) {
            $movementStatement = $pdo->prepare(
                'INSERT INTO inventory_movements
                    (inventory_item_id, changed_by, movement_type, quantity_change,
                     quantity_before, quantity_after, note)
                 VALUES (:item_id, :user_id, \'manual_adjustment\', :change, :before, :after, :note)'
            );
            $movementStatement->execute([
                'item_id' => $itemId,
                'user_id' => $_SESSION['user_id'],
                'change' => $change,
                'before' => $before,
                'after' => $stock,
                'note' => 'Stock changed while editing item',
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $successMessage = 'Inventory item updated successfully.';
    if ($unitChanged) {
        $successMessage = 'Unit changed. Stock and recipe quantities were not converted.';
        if ($disabledDishCount > 0) {
            $successMessage .= ' ' . $disabledDishCount
                . ' dish(es) using this ingredient were disabled until their recipes are reviewed.';
        }
    }
    inventory_redirect($successMessage, 'success', $itemId);
}

$pdo->beginTransaction();
try {
    $insertStatement = $pdo->prepare(
        'INSERT INTO inventory_items
            (category_id, name, description, unit, current_stock, low_stock_level)
         VALUES (:category_id, :name, :description, :unit, :stock, :threshold)'
    );
    $insertStatement->execute([
        'category_id' => $categoryId,
        'name' => $name,
        'description' => $description,
        'unit' => $unit,
        'stock' => $stock,
        'threshold' => $threshold,
    ]);
    $newItemId = (int) $pdo->lastInsertId();

    if ($stock > 0) {
        $movementStatement = $pdo->prepare(
            'INSERT INTO inventory_movements
                (inventory_item_id, changed_by, movement_type, quantity_change,
                 quantity_before, quantity_after, note)
             VALUES (:item_id, :user_id, \'manual_adjustment\', :change, 0, :after, :note)'
        );
        $movementStatement->execute([
            'item_id' => $newItemId,
            'user_id' => $_SESSION['user_id'],
            'change' => $stock,
            'after' => $stock,
            'note' => 'Initial stock',
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

inventory_redirect('Inventory item added successfully.', 'success', $newItemId);
