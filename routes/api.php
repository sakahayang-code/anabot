<?php
declare(strict_types=1);

/**
 * AnaBot REST API Routes (/api/v1/)
 */

use AnaBot\Controllers\AuthController;
use AnaBot\Controllers\HomeController;
use AnaBot\Core\Router;

/** @var Router $router */

$router->group(['prefix' => '/api/v1'], function (Router $api) {
    // Public Health Check
    $api->get('/health', [HomeController::class, 'health']);

    // Authentication Endpoints
    $api->post('/auth/login', [AuthController::class, 'apiLogin']);

    // Protected API Endpoints
    $api->group(['middleware' => ['auth']], function (Router $authApi) {
        $authApi->post('/auth/logout', [AuthController::class, 'apiLogout']);
        $authApi->get('/auth/me', [AuthController::class, 'me']);
        $authApi->post('/auth/change-password', [AuthController::class, 'changePassword']);
    });
});
