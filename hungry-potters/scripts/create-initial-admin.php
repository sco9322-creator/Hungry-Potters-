<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$force = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--force') {
        $force = true;
        continue;
    }

    fwrite(STDERR, "Usage: php scripts/create-initial-admin.php [--force]\n");
    exit(1);
}

function required_environment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "Required environment setting is missing: {$name}.\n");
        exit(1);
    }

    return $value;
}

$username = trim(required_environment('HP_INITIAL_ADMIN_USERNAME'));
$fullName = trim(required_environment('HP_INITIAL_ADMIN_FULL_NAME'));
$password = required_environment('HP_INITIAL_ADMIN_PASSWORD');

if ($username === '' || strlen($username) > 50 || $fullName === '' || strlen($fullName) > 100) {
    fwrite(STDERR, "The initial admin username or full name is invalid.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/password-policy.php';
if (!password_meets_policy($password)) {
    fwrite(STDERR, "The initial admin password does not meet the password policy.\n");
    exit(1);
}

require_once __DIR__ . '/../config/db.php';

try {
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount > 0 && !$force) {
        fwrite(STDERR, "The users table already contains accounts. Run again with --force only after reviewing them.\n");
        exit(1);
    }

    $existing = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $existing->execute(['username' => $username]);
    if ($existing->fetchColumn() !== false) {
        fwrite(STDERR, "That username already exists. Choose a different username.\n");
        exit(1);
    }

    $insert = $pdo->prepare(
        "INSERT INTO users (username, full_name, password_hash, role, must_change_password)
         VALUES (:username, :full_name, :password_hash, 'admin', 1)"
    );
    $insert->execute([
        'username' => $username,
        'full_name' => $fullName,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
} catch (PDOException $exception) {
    error_log('Initial admin provisioning failed: ' . $exception->getMessage());
    fwrite(STDERR, "Initial admin provisioning failed. Check the database connection and schema.\n");
    exit(1);
}

fwrite(STDOUT, "Initial admin account created. Sign in and change the temporary password immediately.\n");