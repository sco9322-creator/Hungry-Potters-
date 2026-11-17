<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(ROLE_POLICY_ADMIN);
require_once __DIR__ . '/../config/db.php';

function menu_management_redirect(string $message, string $type): never
{
    $_SESSION['menu_message'] = $message;
    $_SESSION['menu_message_type'] = $type;
    $query = '';
    $returnQuery = $_POST['return_query'] ?? '';
    if (is_string($returnQuery) && $returnQuery !== '') {
        parse_str($returnQuery, $returnParameters);
        $allowedParameters = [];
        foreach (['status', 'search', 'category_id'] as $key) {
            if (isset($returnParameters[$key]) && is_scalar($returnParameters[$key])) {
                $allowedParameters[$key] = (string) $returnParameters[$key];
            }
        }
        if (isset($allowedParameters['status']) && in_array($allowedParameters['status'], ['active', 'archived'], true)) {
            $query = '?' . http_build_query($allowedParameters);
        }
    }
    header('Location: ../menu-management.php' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
$submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
if (!verify_csrf_token($submittedToken)) {
    http_response_code(400);
    exit('Invalid request token. Refresh the page and try again.');
}
$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
$itemId = $itemId === false ? null : $itemId;

if (in_array($action, ['archive', 'restore'], true)) {
    if ($itemId === null || $itemId < 1) {
        menu_management_redirect('Choose a valid dish.', 'error');
    }
    try {
        $statement = $pdo->prepare('UPDATE menu_items SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $action === 'restore' ? 1 : 0, 'id' => $itemId]);
    } catch (Throwable $exception) {
        error_log('Menu item archive status update failed: ' . $exception->getMessage());
        menu_management_redirect('The dish status could not be updated.', 'error');
    }
    if ($statement->rowCount() !== 1) {
        menu_management_redirect('The dish could not be found or was already changed.', 'error');
    }
    menu_management_redirect($action === 'archive' ? 'Dish archived. Past orders remain unchanged.' : 'Dish restored.', 'success');
}
if ($action === 'toggle_availability') {
    $nextAvailability = $_POST['is_available'] ?? null;
    if (
        $itemId === null
        || $itemId < 1
        || !is_string($nextAvailability)
        || !in_array($nextAvailability, ['0', '1'], true)
    ) {
        menu_management_redirect('Choose a valid dish availability.', 'error');
    }
    try {
        $pdo->beginTransaction();
        $menuLock = $pdo->prepare(
            'SELECT id FROM menu_items WHERE id = :id AND is_active = 1 FOR UPDATE'
        );
        $menuLock->execute(['id' => $itemId]);
        if (!$menuLock->fetchColumn()) {
            $pdo->rollBack();
            menu_management_redirect('The dish could not be found.', 'error');
        }
        if ($nextAvailability === '1') {
            $recipeCheck = $pdo->prepare(
                'SELECT COUNT(*) AS recipe_count,
                        SUM(CASE WHEN inventory.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
                 FROM menu_recipes recipe
                 INNER JOIN inventory_items inventory ON inventory.id = recipe.inventory_item_id
                 WHERE recipe.menu_item_id = :menu_item_id'
            );
            $recipeCheck->execute(['menu_item_id' => $itemId]);
            $recipeStatus = $recipeCheck->fetch();
            if ((int) ($recipeStatus['recipe_count'] ?? 0) === 0) {
                $pdo->rollBack();
                menu_management_redirect('Add a recipe before making this dish available for ordering.', 'error');
            }
            if ((int) ($recipeStatus['inactive_count'] ?? 0) > 0) {
                $pdo->rollBack();
                menu_management_redirect('Replace or activate the inactive recipe ingredients before making this dish available.', 'error');
            }
        }
        $statement = $pdo->prepare(
            'UPDATE menu_items SET is_available = :is_available WHERE id = :id AND is_active = 1'
        );
        $statement->execute(['is_available' => (int) $nextAvailability, 'id' => $itemId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Menu item availability update failed: ' . $exception->getMessage());
        menu_management_redirect('Dish availability could not be updated.', 'error');
    }
    if ($statement->rowCount() !== 1) {
        menu_management_redirect('The dish could not be found or its availability was unchanged.', 'error');
    }
    menu_management_redirect(
        (int) $nextAvailability === 1 ? 'Dish is now available for ordering.' : 'Dish is now unavailable for ordering.',
        'success'
    );
}
if ($action !== 'save') {
    menu_management_redirect('Choose a valid menu action.', 'error');
}

$name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
$categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
$categoryId = $categoryId === false ? null : $categoryId;
$priceInput = $_POST['price'] ?? null;
$isAvailable = isset($_POST['is_available']) && $_POST['is_available'] === '1' ? 1 : 0;
if (
    $name === '' || strlen($name) > 100
    || $categoryId === null || $categoryId < 1
    || (!is_string($priceInput) && !is_numeric($priceInput))
    || !is_numeric($priceInput) || (float) $priceInput < 0 || (float) $priceInput > 99999999
) {
    menu_management_redirect('Enter a valid dish name, category, and price.', 'error');
}

$submittedRecipes = $_POST['recipes'] ?? [];
if (!is_array($submittedRecipes) || count($submittedRecipes) > 100) {
    menu_management_redirect('Enter a valid recipe with no more than 100 ingredients.', 'error');
}
$recipeRows = [];
$seenInventoryIds = [];
foreach ($submittedRecipes as $submittedRecipe) {
    if (!is_array($submittedRecipe)) {
        menu_management_redirect('The recipe contains an invalid ingredient row.', 'error');
    }
    $inventoryId = filter_var($submittedRecipe['inventory_item_id'] ?? null, FILTER_VALIDATE_INT);
    $quantityInput = $submittedRecipe['quantity_required'] ?? null;
    $unitInput = $submittedRecipe['unit'] ?? null;
    $recipeUnit = is_string($unitInput) ? trim($unitInput) : '';
    if (
        $inventoryId === false
        || $inventoryId === null
        || $inventoryId < 1
        || $recipeUnit === ''
        || strlen($recipeUnit) > 30
        || (!is_string($quantityInput) && !is_numeric($quantityInput))
        || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $quantityInput)
        || (float) $quantityInput <= 0
        || (float) $quantityInput > 99999999.99
    ) {
        menu_management_redirect('Each recipe ingredient needs a valid inventory item, matching unit, and positive quantity with up to 2 decimal places.', 'error');
    }
    if (isset($seenInventoryIds[$inventoryId])) {
        menu_management_redirect('Each inventory ingredient can only appear once in a recipe.', 'error');
    }
    $seenInventoryIds[$inventoryId] = true;
    $recipeRows[] = [
        'inventory_item_id' => $inventoryId,
        'quantity_required' => number_format((float) $quantityInput, 2, '.', ''),
        'unit' => $recipeUnit,
    ];
}
$recipeInventoryIds = array_keys($seenInventoryIds);

$categoryStatement = $pdo->prepare("SELECT id FROM categories WHERE id = :id AND category_type = 'menu'");
$categoryStatement->execute(['id' => $categoryId]);
if (!$categoryStatement->fetchColumn()) {
    menu_management_redirect('Choose a valid menu category.', 'error');
}

$existingImageUrl = null;
if ($itemId !== null && $itemId > 0) {
    $existingStatement = $pdo->prepare(
        'SELECT image_url FROM menu_items WHERE id = :id AND is_active = 1'
    );
    $existingStatement->execute(['id' => $itemId]);
    $existingImageUrl = $existingStatement->fetchColumn();
    if ($existingImageUrl === false) {
        menu_management_redirect('The selected dish could not be found.', 'error');
    }
}

$imageUrl = $existingImageUrl;
$uploadedImageFile = null;
$upload = $_FILES['image'] ?? null;
if ($upload !== null && !is_array($upload)) {
    menu_management_redirect('The image upload was invalid. Please choose a JPG, PNG, or WEBP image.', 'error');
}

$uploadError = is_array($upload) ? ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
if (!is_int($uploadError)) {
    menu_management_redirect('The image upload was invalid. Please try again.', 'error');
}
if ($uploadError !== UPLOAD_ERR_NO_FILE) {
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        menu_management_redirect('The image must be no larger than 2 MB.', 'error');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        menu_management_redirect('The image upload did not complete. Please try again.', 'error');
    }

    $temporaryFile = $upload['tmp_name'] ?? null;
    if (!is_string($temporaryFile) || !is_uploaded_file($temporaryFile)) {
        menu_management_redirect('The uploaded image could not be verified. Please try again.', 'error');
    }
    $fileSize = filesize($temporaryFile);
    if ($fileSize === false || $fileSize > 2 * 1024 * 1024) {
        menu_management_redirect('The image must be no larger than 2 MB.', 'error');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryFile);
    $extensionsByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mimeType) || !isset($extensionsByMime[$mimeType])) {
        menu_management_redirect('Unsupported image type. Upload a JPG, PNG, or WEBP image.', 'error');
    }

    $uploadDirectory = __DIR__ . '/../assets/images/menu';
    if (
        !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true)
        && !is_dir($uploadDirectory)
    ) {
        error_log('Menu image directory could not be created: ' . $uploadDirectory);
        menu_management_redirect('The image could not be saved right now. Please try again later.', 'error');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensionsByMime[$mimeType];
    $uploadedImageFile = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file($temporaryFile, $uploadedImageFile)) {
        $uploadedImageFile = null;
        error_log('Uploaded menu image could not be moved into the menu image directory.');
        menu_management_redirect('The image could not be saved right now. Please try again later.', 'error');
    }
    $imageUrl = 'assets/images/menu/' . $filename;
}

try {
    $pdo->beginTransaction();
    $lockedImageUrl = null;
    if ($itemId !== null && $itemId > 0) {
        $lockMenuStatement = $pdo->prepare(
            'SELECT image_url FROM menu_items WHERE id = :id AND is_active = 1 FOR UPDATE'
        );
        $lockMenuStatement->execute(['id' => $itemId]);
        $lockedImageUrl = $lockMenuStatement->fetchColumn();
        if ($lockedImageUrl === false) {
            throw new DomainException('The selected dish could not be found.');
        }
    }

    $inventoryById = [];
    if ($recipeInventoryIds !== []) {
        $placeholders = implode(',', array_fill(0, count($recipeInventoryIds), '?'));
        $lockInventoryStatement = $pdo->prepare(
            'SELECT id, is_active, unit FROM inventory_items
             WHERE id IN (' . $placeholders . ')
             ORDER BY id FOR UPDATE'
        );
        $lockInventoryStatement->execute($recipeInventoryIds);
        foreach ($lockInventoryStatement->fetchAll() as $inventoryItem) {
            $inventoryById[(int) $inventoryItem['id']] = [
                'is_active' => (int) $inventoryItem['is_active'] === 1,
                'unit' => (string) $inventoryItem['unit'],
            ];
        }
        if (count($inventoryById) !== count($recipeInventoryIds)) {
            throw new InvalidArgumentException('Choose valid inventory ingredients for the recipe.');
        }
    }

    foreach ($recipeRows as $recipe) {
        $inventoryItem = $inventoryById[$recipe['inventory_item_id']];
        if ($recipe['unit'] !== $inventoryItem['unit']) {
            throw new InvalidArgumentException(
                'Each recipe unit must exactly match its inventory item unit. Unit conversion is not supported.'
            );
        }
    }
    $hasInactiveIngredient = false;
    foreach ($inventoryById as $inventoryItem) {
        if (!$inventoryItem['is_active']) {
            $hasInactiveIngredient = true;
            break;
        }
    }
    $effectiveAvailability = $isAvailable === 1
        && $recipeRows !== []
        && !$hasInactiveIngredient
        ? 1
        : 0;
    if ($uploadedImageFile === null) {
        $imageUrl = $lockedImageUrl;
    }

    if ($itemId !== null && $itemId > 0) {
        $statement = $pdo->prepare(
            'UPDATE menu_items
             SET category_id = :category_id, name = :name, price = :price,
                 image_url = :image_url, is_available = :is_available
             WHERE id = :id AND is_active = 1'
        );
        $statement->execute([
            'category_id' => $categoryId,
            'name' => $name,
            'price' => round((float) $priceInput, 2),
            'image_url' => $imageUrl,
            'is_available' => $effectiveAvailability,
            'id' => $itemId,
        ]);
        $menuItemId = $itemId;
    } else {
        $statement = $pdo->prepare(
            'INSERT INTO menu_items (category_id, name, price, image_url, is_available)
             VALUES (:category_id, :name, :price, :image_url, :is_available)'
        );
        $statement->execute([
            'category_id' => $categoryId,
            'name' => $name,
            'price' => round((float) $priceInput, 2),
            'image_url' => $imageUrl,
            'is_available' => $effectiveAvailability,
        ]);
        $menuItemId = (int) $pdo->lastInsertId();
    }

    $deleteRecipeStatement = $pdo->prepare('DELETE FROM menu_recipes WHERE menu_item_id = :menu_item_id');
    $deleteRecipeStatement->execute(['menu_item_id' => $menuItemId]);
    $insertRecipeStatement = $pdo->prepare(
        'INSERT INTO menu_recipes (menu_item_id, inventory_item_id, quantity_required)
         VALUES (:menu_item_id, :inventory_item_id, :quantity_required)'
    );
    foreach ($recipeRows as $recipe) {
        $insertRecipeStatement->execute([
            'menu_item_id' => $menuItemId,
            'inventory_item_id' => $recipe['inventory_item_id'],
            'quantity_required' => $recipe['quantity_required'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($uploadedImageFile !== null && is_file($uploadedImageFile) && !unlink($uploadedImageFile)) {
        error_log('Failed to remove an unreferenced uploaded menu image: ' . $uploadedImageFile);
    }
    if ($exception instanceof DomainException || $exception instanceof InvalidArgumentException) {
        menu_management_redirect($exception->getMessage(), 'error');
    }
    if ($exception instanceof PDOException && $exception->getCode() === '23000') {
        menu_management_redirect('A dish with that name already exists, including archived dishes.', 'error');
    }
    error_log('Menu item save failed: ' . $exception->getMessage());
    menu_management_redirect('The dish could not be saved.', 'error');
}

$successMessage = $itemId !== null && $itemId > 0 ? 'Dish updated successfully.' : 'Dish added successfully.';
if ($isAvailable === 1 && $effectiveAvailability === 0) {
    $successMessage = $recipeRows === []
        ? 'Dish saved. Add a recipe before making it available for ordering.'
        : 'Dish saved as unavailable because its recipe includes an inactive inventory item.';
}
menu_management_redirect($successMessage, 'success');
