<?php
declare(strict_types=1);

/**
 * AnaBot Web Routes
 */

use AnaBot\Controllers\AuthController;
use AnaBot\Controllers\HomeController;
use AnaBot\Core\Router;

/** @var Router $router */

$router->get('/', [HomeController::class, 'index']);

// Guest Authentication Routes
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);

// Protected Web Routes
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);
$router->get('/dashboard', [HomeController::class, 'dashboard'], ['auth']);
