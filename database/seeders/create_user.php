<?php
declare(strict_types=1);

/**
 * AnaBot CLI User Seeder (Single User Setup)
 * 
 * Usage: php database/seeders/create_user.php [username] [email] [password]
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__, 2) . '/.env')) {
    load_env(dirname(__DIR__, 2) . '/.env');
}

use AnaBot\Core\App;
use AnaBot\Core\Database;

$app = App::getInstance()->boot();
$db = Database::getInstance();

$username = $argv[1] ?? 'admin';
$email = $argv[2] ?? 'admin@anabot.local';
$password = $argv[3] ?? 'admin12345';

echo "Setting up single user account: [{$username}] ({$email})..." . PHP_EOL;

// Check if user already exists
$existing = $db->queryOne("SELECT id FROM users WHERE username = :u OR email = :e", [
    ':u' => $username,
    ':e' => $email,
]);

if ($existing) {
    echo "User already exists with ID: {$existing['id']}." . PHP_EOL;
    exit(0);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$now = date('c');

$db->execute(
    "INSERT INTO users (username, email, password_hash, status, created_at, updated_at) 
     VALUES (:u, :e, :p, 'active', :now, :now)",
    [
        ':u' => $username,
        ':e' => $email,
        ':p' => $hash,
        ':now' => $now,
    ]
);

$id = $db->lastInsertId();
echo "User successfully created with ID: {$id}." . PHP_EOL;
