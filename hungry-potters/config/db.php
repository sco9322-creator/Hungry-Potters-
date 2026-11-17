<?php
declare(strict_types=1);

$dbHost = getenv('HP_DB_HOST');
$dbName = getenv('HP_DB_NAME');
$dbUser = getenv('HP_DB_USER');
$dbPass = getenv('HP_DB_PASSWORD');
$dbPort = getenv('HP_DB_PORT');

if ($dbPort === false || $dbPort === '') {
    $dbPort = '3306';
}

if (
    $dbHost === false || $dbHost === ''
    || $dbName === false || $dbName === ''
    || $dbUser === false || $dbUser === ''
    || $dbPass === false || $dbPass === ''
    || !ctype_digit($dbPort)
    || (int) $dbPort < 1
    || (int) $dbPort > 65535
) {
    error_log('Database credentials are not fully configured in the PHP environment.');
    http_response_code(500);
    exit('Database configuration is incomplete or invalid. Set HP_DB_HOST, HP_DB_NAME, HP_DB_USER, and HP_DB_PASSWORD, and use a valid HP_DB_PORT if needed.');
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Check the database service and server environment configuration.');
}
