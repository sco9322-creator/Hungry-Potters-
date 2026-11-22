<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password-policy.php';
require_role(ROLE_POLICY_ADMIN);
require_once __DIR__ . '/../config/db.php';

function user_management_redirect(string $message, string $type): never
{
    $_SESSION['users_message'] = $message;
    $_SESSION['users_message_type'] = $type;
    $search = $_POST['return_search'] ?? '';
    $query = is_string($search) && trim($search) !== ''
        ? '?' . http_build_query(['search' => trim($search)])
        : '';
    header('Location: ../user-management.php' . $query);
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
$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$userId = $userId === false ? null : $userId;

if (in_array($action, ['deactivate', 'activate'], true)) {
    if ($userId === null || $userId < 1) {
        user_management_redirect('Choose a valid account.', 'error');
    }
    if ($action === 'deactivate' && $userId === (int) $_SESSION['user_id']) {
        user_management_redirect('You cannot deactivate your own account.', 'error');
    }

    $pdo->beginTransaction();
    try {
        $activeAdminStatement = $pdo->query(
            "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id FOR UPDATE"
        );
        $activeAdminIds = array_map('intval', $activeAdminStatement->fetchAll(PDO::FETCH_COLUMN));
        $targetStatement = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id FOR UPDATE');
        $targetStatement->execute(['id' => $userId]);
        $target = $targetStatement->fetch();
        if (!$target) {
            $pdo->rollBack();
            user_management_redirect('The selected account could not be found.', 'error');
        }
        if ($action === 'deactivate' && $target['role'] === 'admin' && (int) $target['is_active'] === 1) {
            if (count($activeAdminIds) <= 1) {
                $pdo->rollBack();
                user_management_redirect('The last active admin account cannot be deactivated.', 'error');
            }
        }
        $newActiveStatus = $action === 'activate' ? 1 : 0;
        if ((int) $target['is_active'] !== $newActiveStatus) {
            $updateStatement = $pdo->prepare(
                'UPDATE users
                 SET is_active = :is_active, session_version = session_version + 1
                 WHERE id = :id'
            );
            $updateStatement->execute(['is_active' => $newActiveStatus, 'id' => $userId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('User account status update failed: ' . $exception->getMessage());
        user_management_redirect('The account status could not be updated.', 'error');
    }
    user_management_redirect($action === 'activate' ? 'Account reactivated.' : 'Account deactivated.', 'success');
}

if ($action !== 'save') {
    user_management_redirect('Choose a valid account action.', 'error');
}

$username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
$fullName = isset($_POST['full_name']) && is_string($_POST['full_name']) ? trim($_POST['full_name']) : '';
$role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : '';
$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
if (
    $username === '' || strlen($username) > 50
    || $fullName === '' || strlen($fullName) > 100
    || !in_array($role, ['admin', 'cashier', 'kitchen'], true)
    || ($password !== '' && !password_meets_policy($password))
    || ($userId === null && $password === '')
) {
    user_management_redirect('Enter a valid name, username, role, and password (at least 12 characters, maximum 72 UTF-8 bytes).', 'error');
}

try {
    if ($userId !== null && $userId > 0) {
        $pdo->beginTransaction();
        $activeAdminStatement = $pdo->query(
            "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id FOR UPDATE"
        );
        $activeAdminIds = array_map('intval', $activeAdminStatement->fetchAll(PDO::FETCH_COLUMN));
        $existingStatement = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id FOR UPDATE');
        $existingStatement->execute(['id' => $userId]);
        $existing = $existingStatement->fetch();
        if (!$existing) {
            user_management_redirect('The selected account could not be found.', 'error');
        }
        if ($role === 'kitchen' && in_array($existing['role'], ['admin', 'cashier'], true)) {
            $openShiftStatement = $pdo->prepare(
                'SELECT id
                 FROM cashier_shifts
                 WHERE cashier_id = :cashier_id AND closed_at IS NULL
                 LIMIT 1'
            );
            $openShiftStatement->execute(['cashier_id' => $userId]);
            if ($openShiftStatement->fetchColumn()) {
                $pdo->rollBack();
                user_management_redirect('Close all open cashier shifts before changing this account to kitchen.', 'error');
            }
        }
        if (
            $existing['role'] === 'admin'
            && $role !== 'admin'
            && (int) $existing['is_active'] === 1
        ) {
            if (count(array_diff($activeAdminIds, [$userId])) === 0) {
                $pdo->rollBack();
                user_management_redirect('The last active admin cannot be changed to another role.', 'error');
            }
        }
        if ($password !== '') {
            $updateStatement = $pdo->prepare(
                'UPDATE users
                 SET username = :username, full_name = :full_name, role = :role,
                     password_hash = :password_hash,
                     session_version = session_version + :session_version_increment
                 WHERE id = :id'
            );
            $updateStatement->execute([
                'username' => $username,
                'full_name' => $fullName,
                'role' => $role,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'session_version_increment' => 1,
                'id' => $userId,
            ]);
        } else {
            $updateStatement = $pdo->prepare(
                'UPDATE users
                 SET username = :username, full_name = :full_name, role = :role,
                     session_version = session_version + :session_version_increment
                 WHERE id = :id'
            );
            $updateStatement->execute([
                'username' => $username,
                'full_name' => $fullName,
                'role' => $role,
                'session_version_increment' => $existing['role'] !== $role ? 1 : 0,
                'id' => $userId,
            ]);
        }
        $pdo->commit();
        if ($userId === (int) $_SESSION['user_id']) {
            $_SESSION['username'] = $username;
            $_SESSION['full_name'] = $fullName;
            $_SESSION['role'] = $role;
            if ($role !== 'admin') {
                $_SESSION['dashboard_message'] = 'Account updated successfully.';
                $_SESSION['dashboard_message_type'] = 'success';
                header('Location: ../dashboard.php');
                exit;
            }
        }
        user_management_redirect('Account updated successfully.', 'success');
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO users (username, full_name, password_hash, role)
         VALUES (:username, :full_name, :password_hash, :role)'
    );
    $insertStatement->execute([
        'username' => $username,
        'full_name' => $fullName,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception->getCode() === '23000') {
        user_management_redirect('That username is already in use.', 'error');
    }
    error_log('User account save failed: ' . $exception->getMessage());
    user_management_redirect('The account could not be saved.', 'error');
}

user_management_redirect('Account created successfully.', 'success');
