<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Router;

class RouterTest
{
    public function run(): void
    {
        echo "Testing Router..." . PHP_EOL;

        $router = new Router();

        // Register sample routes
        $router->get('/health', fn() => 'health_ok');
        $router->get('/datasets/{id}', fn($params) => 'dataset_' . $params['id']);
        $router->group(['prefix' => '/api/v1'], function (Router $api) {
            $api->get('/users/{id}/details', fn($params) => 'user_' . $params['id']);
        });

        // Test 1: Basic Route Dispatch
        $res1 = $router->dispatch('GET', '/health');
        assert($res1 === 'health_ok', 'Basic GET route must match');
        echo "  [PASS] Static route matching" . PHP_EOL;

        // Test 2: Dynamic Parameters Extraction
        $res2 = $router->dispatch('GET', '/datasets/42');
        assert($res2 === 'dataset_42', 'Dynamic param {id} must extract 42');
        echo "  [PASS] Dynamic parameter extraction ({id})" . PHP_EOL;

        // Test 3: Route Grouping with Prefix
        $res3 = $router->dispatch('GET', '/api/v1/users/99/details');
        assert($res3 === 'user_99', 'Grouped route with prefix must match and extract param');
        echo "  [PASS] Route grouping and prefix" . PHP_EOL;
    }
}
