<?php
declare(strict_types=1);

/**
 * AnaBot Web Front Controller
 */

// Define application start time
define('ANABOT_START', microtime(true));

// Composer Autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env configuration
// Check separate secrets directory first if specified, then local .env
$secretsEnv = env('ANABOT_SECRETS_PATH', '') ? rtrim(env('ANABOT_SECRETS_PATH', ''), '/') . '/.env' : null;
if ($secretsEnv && file_exists($secretsEnv)) {
    load_env($secretsEnv);
} elseif (file_exists(dirname(__DIR__) . '/.env')) {
    load_env(dirname(__DIR__) . '/.env');
}

use AnaBot\Core\App;

$app = App::getInstance();
$router = $app->getRouter();

// Load Web and API Route Definitions
require_once dirname(__DIR__) . '/routes/web.php';
require_once dirname(__DIR__) . '/routes/api.php';

// Dispatch Application
$app->run();
