<?php
declare(strict_types=1);

/**
 * AnaBot Automated Test Runner (Isolated Testing Environment)
 * 
 * Strict Rule 5.6: Automated tests NEVER touch production database!
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Force testing environment
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

$_ENV['APP_KEY'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$_SERVER['APP_KEY'] = $_ENV['APP_KEY'];
putenv("APP_KEY={$_ENV['APP_KEY']}");

use AnaBot\Core\App;
use AnaBot\Tests\Unit\DatabaseTest;
use AnaBot\Tests\Unit\EncryptionTest;
use AnaBot\Tests\Unit\ValidatorTest;
use AnaBot\Tests\Unit\RouterTest;
use AnaBot\Tests\Unit\MigrationTest;
use AnaBot\Tests\Unit\AuthTest;
use AnaBot\Tests\Unit\DashboardTest;
use AnaBot\Tests\Unit\DataExplorerTest;
use AnaBot\Tests\Unit\ConnectorFrameworkTest;
use AnaBot\Tests\Unit\GoogleSheetsIntegrationTest;
use AnaBot\Tests\Unit\AnalyticsEngineTest;
use AnaBot\Tests\Unit\AIFrameworkTest;
use AnaBot\Tests\Unit\AIAnalystTest;
use AnaBot\Tests\Unit\PredictiveAnalyticsTest;
use AnaBot\Tests\Unit\ReportEngineTest;

$app = App::getInstance()->boot();

echo "========================================================" . PHP_EOL;
echo "  ANABOT AUTOMATED TEST SUITE (FASE 1 - FASE 10)" . PHP_EOL;
echo "  PHP: " . PHP_VERSION . " | Environment: TESTING" . PHP_EOL;
echo "========================================================" . PHP_EOL . PHP_EOL;

$suites = [
    'Encryption Service' => new EncryptionTest(),
    'Database Engine' => new DatabaseTest(),
    'Input Validator' => new ValidatorTest(),
    'Router & Dispatcher' => new RouterTest(),
    'Migration Runner & Schema' => new MigrationTest(),
    'Authentication & Security' => new AuthTest(),
    'Dashboard Engine & UI Assets' => new DashboardTest(),
    'Data Explorer & Memory-Safe Exporter' => new DataExplorerTest(),
    'Connector Framework & Sync Queue' => new ConnectorFrameworkTest(),
    'Google Sheets First Integration & Auto-Schema' => new GoogleSheetsIntegrationTest(),
    'Analytics Engine & Statistical Calculation' => new AnalyticsEngineTest(),
    'Multi-Provider AI Framework & Failover' => new AIFrameworkTest(),
    'AI Analyst & Conversational Grounding Engine' => new AIAnalystTest(),
    'Predictive Analytics & Anomaly Detection' => new PredictiveAnalyticsTest(),
    'Report Engine (Multi-Format & Secure Storage)' => new ReportEngineTest(),
];

$passedSuites = 0;
$totalSuites = count($suites);

foreach ($suites as $name => $suite) {
    try {
        $suite->run();
        $passedSuites++;
        echo PHP_EOL;
    } catch (\Throwable $e) {
        echo "  [FAIL] Test suite failed: " . $e->getMessage() . PHP_EOL;
        echo "  In " . $e->getFile() . " line " . $e->getLine() . PHP_EOL;
        echo PHP_EOL;
    }
}

echo "========================================================" . PHP_EOL;
echo "  FINAL TEST RESULT: {$passedSuites}/{$totalSuites} TEST SUITES PASSED" . PHP_EOL;
echo "========================================================" . PHP_EOL;

if ($passedSuites === $totalSuites) {
    exit(0);
} else {
    exit(1);
}
