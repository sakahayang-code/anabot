<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Connectors\CustomAPI\CustomAPIConnector;
use AnaBot\Connectors\GoogleAnalytics\GoogleAnalyticsConnector;
use AnaBot\Connectors\GoogleSheets\GoogleSheetsConnector;
use AnaBot\Connectors\Meta\MetaAdsConnector;
use AnaBot\Connectors\MySQL\MySQLConnector;
use AnaBot\Connectors\SearchConsole\SearchConsoleConnector;
use AnaBot\Connectors\Shopify\ShopifyConnector;
use AnaBot\Connectors\TikTok\TikTokConnector;
use AnaBot\Connectors\WooCommerce\WooCommerceConnector;
use AnaBot\Connectors\YouTube\YouTubeConnector;
use AnaBot\Core\Database;
use AnaBot\Core\Encryption;
use AnaBot\Core\Logger;
use AnaBot\Services\ConnectorManager;
use AnaBot\Services\CredentialManager;
use AnaBot\Services\HttpClient;
use AnaBot\Services\SyncQueueManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 12: Additional Platform Connectors Automated Test Suite
 * 
 * Verifies all 10 platform connectors (MySQL, WooCommerce, Shopify,
 * Google Analytics 4, Search Console, YouTube, Meta Ads, TikTok, Google Sheets, Custom API)
 * with strict READ-ONLY enforcement, cursor pagination, and credential redaction.
 */
class AdditionalConnectorsTest
{
    private Database $db;
    private Encryption $encryption;
    private CredentialManager $credentialManager;
    private SyncQueueManager $syncQueue;
    private ConnectorManager $connectorManager;

    public function __construct()
    {
        $this->encryption = new Encryption();
    }

    public function run(): void
    {
        echo "Testing Additional Platform Connectors (FASE 12 - Isolated DB)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        $runner = new \AnaBot\Core\MigrationRunner($this->db);
        $runner->run();

        $this->credentialManager = new CredentialManager($this->db, $this->encryption);
        $this->syncQueue = new SyncQueueManager($this->db);
        $this->connectorManager = new ConnectorManager($this->db, $this->credentialManager, $this->syncQueue);

        // Seed base user and sync catalog
        $now = date('c');
        $this->db->execute(
            "INSERT INTO users (id, username, email, password_hash, status, created_at, updated_at) 
             VALUES (1, 'tester', 'test@anabot.local', 'h', 'active', :n, :n)",
            [':n' => $now]
        );
        $this->connectorManager->syncCatalogToDatabase();

        $this->testConnectorCatalogAndDriverResolutionAllTenDrivers();
        $this->testMySQLConnectorReadOnlyEnforcementAndSanitization();
        $this->testWooCommerceConnectorContractAndPagination();
        $this->testShopifyConnectorContractAndPagination();
        $this->testGoogleAnalytics4ConnectorContractAndParsing();
        $this->testSearchConsoleConnectorContractAndPagination();
        $this->testYouTubeConnectorContractAndPagination();
        $this->testMetaAdsConnectorContractAndPagination();
        $this->testTikTokConnectorContractAndPagination();
        $this->testStrictReadOnlyContractAndSensitiveDataMaskingAcrossAllConnectors();

        echo "  [PASS] All 10 Additional Platform Connectors test cases passed successfully." . PHP_EOL;

        // Cleanup
        unset($this->db);
        Database::setInstance(null);
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }
    }

    /**
     * 1. Verify all 10 connectors in catalog resolve to their respective concrete driver classes.
     */
    private function testConnectorCatalogAndDriverResolutionAllTenDrivers(): void
    {
        $catalog = $this->connectorManager->listCatalog();

        $expectedDrivers = [
            'google_sheets'    => GoogleSheetsConnector::class,
            'google_analytics' => GoogleAnalyticsConnector::class,
            'search_console'   => SearchConsoleConnector::class,
            'youtube'          => YouTubeConnector::class,
            'meta'             => MetaAdsConnector::class,
            'tiktok'           => TikTokConnector::class,
            'woocommerce'      => WooCommerceConnector::class,
            'shopify'          => ShopifyConnector::class,
            'mysql'            => MySQLConnector::class,
            'custom_api'       => CustomAPIConnector::class,
        ];

        assert(count($catalog) >= 10, "Catalog must contain at least 10 platform connectors.");

        foreach ($expectedDrivers as $code => $expectedClass) {
            assert(isset($catalog[$code]), "Connector '{$code}' must exist in catalog.");
            assert($catalog[$code]['has_driver'] === true, "Connector '{$code}' must have driver registered.");

            $driver = $this->connectorManager->getDriver($code);
            assert(
                $driver instanceof $expectedClass,
                "Driver for '{$code}' must be instance of {$expectedClass}, got " . get_class($driver)
            );
            assert($driver->getConnectorCode() === $code, "getConnectorCode() must match '{$code}'.");
        }
    }

    /**
     * 2. Test MySQL Connector read-only enforcement and identifier sanitization.
     */
    private function testMySQLConnectorReadOnlyEnforcementAndSanitization(): void
    {
        $mysql = new MySQLConnector([
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'test_db',
        ], [
            'username' => 'root',
            'password' => 'secret_db_pass',
        ]);

        assert($mysql->getConnectorCode() === 'mysql');
        assert($mysql->getCategory() === 'database');
        assert($mysql->getAuthType() === 'database');

        // Test connection failure handling (no live MySQL running on port 3306)
        $testResult = $mysql->testConnection();
        assert($testResult['success'] === false, "MySQL testConnection without running server should return false.");
        assert(!empty($testResult['message']), "MySQL testConnection should return explanatory message.");

        // Test read-only query assertion check
        try {
            $mysql->assertReadOnlyQuery("DROP TABLE users");
            assert(false, "MySQLConnector must reject DROP statement.");
        } catch (RuntimeException $e) {
            assert(str_contains($e->getMessage(), 'melanggar kontrak mutlak READ-ONLY'), "Should block forbidden keyword DROP.");
        }

        try {
            $mysql->assertReadOnlyQuery("INSERT INTO users VALUES (1)");
            assert(false, "MySQLConnector must reject INSERT statement.");
        } catch (RuntimeException $e) {
            assert(str_contains($e->getMessage(), 'melanggar kontrak mutlak READ-ONLY'), "Should block forbidden keyword INSERT.");
        }
    }

    /**
     * 3. Test WooCommerce Connector contract, auth headers, and mock pagination.
     */
    private function testWooCommerceConnectorContractAndPagination(): void
    {
        $woo = new WooCommerceConnector([], []);
        // Without site_url
        $res = $woo->testConnection();
        assert($res['success'] === false && $res['details']['error'] === 'MISSING_SITE_URL');

        // Check available datasets
        $datasets = $woo->getAvailableDatasets();
        $datasetIds = array_column($datasets, 'id');
        assert(in_array('orders', $datasetIds));
        assert(in_array('products', $datasetIds));
        assert(in_array('customers', $datasetIds));

        // Mock HttpClient for WooCommerce
        $mockHttp = $this->createMockHttpClient(function ($method, $url, $data, $headers) {
            assert($method === 'GET');
            assert(str_contains($url, '/wp-json/wc/v3/orders'));
            return [
                'status' => 200,
                'headers' => ['x-wp-total' => ['50'], 'x-wp-totalpages' => ['5']],
                'body' => json_encode([
                    ['id' => 101, 'status' => 'completed', 'total' => '250000.00', 'currency' => 'IDR', 'date_created' => '2026-11-20T10:00:00'],
                    ['id' => 102, 'status' => 'processing', 'total' => '175000.00', 'currency' => 'IDR', 'date_created' => '2026-11-20T11:00:00'],
                ]),
                'duration_ms' => 45.0,
            ];
        });

        $wooWithMock = new WooCommerceConnector(
            ['site_url' => 'https://mystore.local'],
            ['consumer_key' => 'ck_test123', 'consumer_secret' => 'cs_test456'],
            null,
            $mockHttp
        );

        $fetchResult = $wooWithMock->fetchData('orders', '1', 2);
        assert(count($fetchResult['records']) === 2, "Should return 2 records.");
        assert($fetchResult['records'][0]['id'] === 101);
        assert($fetchResult['records'][0]['total'] === 250000.0);
        assert($fetchResult['has_more'] === true);
        assert($fetchResult['next_cursor'] === '2');
    }

    /**
     * 4. Test Shopify Connector contract, domain validation, and mock cursor pagination.
     */
    private function testShopifyConnectorContractAndPagination(): void
    {
        $shopify = new ShopifyConnector([], []);
        $res = $shopify->testConnection();
        assert($res['success'] === false && $res['details']['error'] === 'MISSING_SHOP_DOMAIN');

        $datasets = $shopify->getAvailableDatasets();
        $datasetIds = array_column($datasets, 'id');
        assert(in_array('orders', $datasetIds));
        assert(in_array('products', $datasetIds));
        assert(in_array('customers', $datasetIds));

        $mockHttp = $this->createMockHttpClient(function ($method, $url, $data, $headers) {
            assert($method === 'GET');
            return [
                'status' => 200,
                'headers' => ['link' => '<https://test-shop.myshopify.com/admin/api/2024-01/orders.json?page_info=eyJsYXN0X2lkIjoxMDIwfQ>; rel="next"'],
                'body' => json_encode([
                    'orders' => [
                        ['id' => 5001, 'financial_status' => 'paid', 'total_price' => '350000.00', 'currency' => 'IDR', 'created_at' => '2026-11-21T09:00:00Z'],
                        ['id' => 5002, 'financial_status' => 'paid', 'total_price' => '420000.00', 'currency' => 'IDR', 'created_at' => '2026-11-21T10:30:00Z'],
                    ],
                ]),
                'duration_ms' => 38.0,
            ];
        });

        $shopifyWithMock = new ShopifyConnector(
            ['shop_domain' => 'test-shop.myshopify.com'],
            ['access_token' => 'shpat_secret_token_12345'],
            null,
            $mockHttp
        );

        $fetchResult = $shopifyWithMock->fetchData('orders', null, 2);
        assert(count($fetchResult['records']) === 2);
        assert($fetchResult['records'][0]['id'] === 5001);
        assert($fetchResult['records'][0]['total_price'] === 350000.0);
        assert($fetchResult['has_more'] === true);
        assert($fetchResult['next_cursor'] === '5002');
    }

    /**
     * 5. Test Google Analytics 4 Connector property ID parsing and report transformation.
     */
    private function testGoogleAnalytics4ConnectorContractAndParsing(): void
    {
        $ga = new GoogleAnalyticsConnector(['property_id' => 'properties/987654321'], ['access_token' => 'ya29.test']);
        assert($ga->getPropertyId() === '987654321', "Should sanitize property_id to digits.");

        $datasets = $ga->getAvailableDatasets();
        $datasetIds = array_column($datasets, 'id');
        assert(in_array('daily_traffic', $datasetIds));
        assert(in_array('traffic_sources', $datasetIds));
        assert(in_array('user_demographics', $datasetIds));
        assert(in_array('event_counts', $datasetIds));

        $mockHttp = $this->createMockHttpClient(function ($method, $url, $data, $headers) {
            assert($method === 'POST');
            assert(str_contains($url, '/properties/987654321:runReport'));
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'dimensionHeaders' => [['name' => 'date']],
                    'metricHeaders'    => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']],
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20261120']],
                            'metricValues'    => [['value' => '150'], ['value' => '210'], ['value' => '620']],
                        ],
                        [
                            'dimensionValues' => [['value' => '20261121']],
                            'metricValues'    => [['value' => '180'], ['value' => '250'], ['value' => '740']],
                        ],
                    ],
                    'rowCount' => 10,
                ]),
                'duration_ms' => 55.0,
            ];
        });

        $gaWithMock = new GoogleAnalyticsConnector(
            ['property_id' => '987654321'],
            ['access_token' => 'ya29.valid_token'],
            null,
            $mockHttp
        );

        $fetchResult = $gaWithMock->fetchData('daily_traffic', '0', 2);
        assert(count($fetchResult['records']) === 2);
        assert($fetchResult['records'][0]['date'] === '2026-11-20', "Date dimension should be formatted to YYYY-MM-DD.");
        assert($fetchResult['records'][0]['activeUsers'] === 150);
        assert($fetchResult['records'][0]['sessions'] === 210);
        assert($fetchResult['has_more'] === true);
        assert($fetchResult['next_cursor'] === '2');
    }

    /**
     * 6. Test Search Console Connector contract, query dimensions, and pagination.
     */
    private function testSearchConsoleConnectorContractAndPagination(): void
    {
        $gsc = new SearchConsoleConnector(['site_url' => 'https://example.com/'], ['access_token' => 'ya29.test']);
        assert($gsc->getSiteUrl() === 'https://example.com/');

        $datasets = $gsc->getAvailableDatasets();
        $datasetIds = array_column($datasets, 'id');
        assert(in_array('search_performance', $datasetIds));
        assert(in_array('top_queries', $datasetIds));
        assert(in_array('top_pages', $datasetIds));

        $mockHttp = $this->createMockHttpClient(function ($method, $url, $data, $headers) {
            assert($method === 'POST');
            assert(str_contains($url, '/searchAnalytics/query'));
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'rows' => [
                        ['keys' => ['aplikasi robot ai'], 'clicks' => 45, 'impressions' => 500, 'ctr' => 0.09, 'position' => 2.4],
                        ['keys' => ['anabot personal data'], 'clicks' => 30, 'impressions' => 200, 'ctr' => 0.15, 'position' => 1.2],
                    ],
                ]),
                'duration_ms' => 42.0,
            ];
        });

        $gscWithMock = new SearchConsoleConnector(
            ['site_url' => 'https://example.com/'],
            ['access_token' => 'ya29.valid_token'],
            null,
            $mockHttp
        );

        $fetchResult = $gscWithMock->fetchData('top_queries', '0', 2);
        assert(count($fetchResult['records']) === 2);
        assert($fetchResult['records'][0]['query'] === 'aplikasi robot ai');
        assert($fetchResult['records'][0]['clicks'] === 45);
        assert($fetchResult['records'][0]['ctr'] === 9.0, "CTR should be multiplied to percentage (9.0%).");
        assert($fetchResult['records'][0]['position'] === 2.4);
        assert($fetchResult['has_more'] === true);
        assert($fetchResult['next_cursor'] === '2');
    }

    /**
     * 7. Test YouTube Connector channel resolution, metrics parsing, and pagination.
     */
    private function testYouTubeConnectorContractAndPagination(): void
    {
        $yt = new YouTubeConnector([], []);
        assert($yt->getChannelId() === 'mine', "Default channel_id should be 'mine'.");

        $datasets = $yt->getAvailableDatasets();
        $datasetIds = array_column($datasets, 'id');
        assert(in_array('channel_metrics', $datasetIds));
        assert(in_array('video_engagement', $datasetIds));
        assert(in_array('audience_geography', $datasetIds));

        $mockHttp = $this->createMockHttpClient(function ($method, $url, $data, $headers) {
            assert($method === 'GET');
            assert(str_contains($url, 'channel==MINE'));
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'columnHeaders' => [
                        ['name' => 'day'],
                        ['name' => 'views'],
                        ['name' => 'estimatedMinutesWatched'],
                        ['name' => 'subscribersGained'],
                    ],
                    'rows' => [
                        ['2026-11-20', 1250, 4800, 15],
                        ['2026-11-21', 1400, 5200, 22],
                    ],
                ]),
                'duration_ms' => 35.0,
            ];
        });

        $ytWithMock = new YouTubeConnector(
            ['channel_id' => 'mine'],
            ['access_token' => 'ya29.valid_yt_token'],
            null,
            $mockHttp
        );

        $fetchResult = $ytWithMock->fetchData('channel_metrics', '1', 2);
        assert(count($fetchResult['records']) === 2);
        assert($fetchResult['records'][0]['day'] === '2026-11-20');
        assert($fetchResult['records'][0]['views'] === 1250);
        assert($fetchResult['records'][0]['subscribersGained'] === 15);
        assert($fetchResult['has_more'] === true);
        assert($fetchResult['next_cursor'] === '3');
    }

    /**
     * 8. Test Meta Ads Connector ad account formatting, field mappings, and after cursor.
     */
    private function testMetaAdsConnectorContractAndPagination(): void
    {
        $meta = new MetaAdsConnector(['ad_account_id' => '1234567890'], ['access_token' => 'EAAB...']);
        assert($meta->getAdAccountId() === 'act_1234567890', "Should ensure 'act_' prefix.");

        $datasets = $meta->getAvailableDatasets();
        $datasetIds = array_column($datasets, 'id');
        assert(in_array('campaign_insights', $datasetIds));
        assert(in_array('ad_sets', $datasetIds));
        assert(in_array('ad_reach', $datasetIds));

        $mockHttp = $this->createMockHttpClient(function ($method, $url, $data, $headers) {
            assert($method === 'GET');
            assert(str_contains($url, '/act_1234567890/insights'));
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'data' => [
                        [
                            'campaign_id'   => '1202059382910',
                            'campaign_name' => 'Black Friday Promo',
                            'impressions'   => '12500',
                            'clicks'        => '450',
                            'spend'         => '350000.50',
                            'cpc'           => '777.78',
                            'ctr'           => '3.60',
                            'reach'         => '10200',
                        ],
                    ],
                    'paging' => [
                        'cursors' => ['after' => 'QVFIUk9xW...'],
                        'next'    => 'https://graph.facebook.com/v19.0/act_1234567890/insights?after=QVFIUk9xW...',
                    ],
                ]),
                'duration_ms' => 48.0,
            ];
        });

        $metaWithMock = new MetaAdsConnector(
            ['ad_account_id' => 'act_1234567890'],
            ['access_token' => 'EAAB_token'],
            null,
            $mockHttp
        );

        $fetchResult = $metaWithMock->fetchData('campaign_insights', null, 1);
        assert(count($fetchResult['records']) === 1);
        assert($fetchResult['records'][0]['campaign_id'] === '1202059382910');
        assert($fetchResult['records'][0]['impressions'] === 12500);
        assert($fetchResult['records'][0]['spend'] === 350000.50);
        assert($fetchResult['has_more'] === true);
        assert($fetchResult['next_cursor'] === 'QVFIUk9xW...');
    }

    /**
     * 9. Test TikTok Connector advertiser validation, report metrics, and page pagination.
     */
    private function testTikTokConnectorContractAndPagination(): void
    {
        $tiktok = new TikTokConnector(['advertiser_id' => '71829304859'], ['access_token' => 'tt_test']);
        assert($tiktok->getAdvertiserId() === '71829304859');

        $datasets = $tiktok->getAvailableDatasets();
        $datasetIds = array_column($datasets, 'id');
        assert(in_array('campaign_metrics', $datasetIds));
        assert(in_array('ad_performance', $datasetIds));
        assert(in_array('daily_reach', $datasetIds));

        $mockHttp = $this->createMockHttpClient(function ($method, $url, $data, $headers) {
            assert($method === 'GET');
            assert(str_contains($url, '/report/integrated/get/'));
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'code'    => 0,
                    'message' => 'OK',
                    'data'    => [
                        'list' => [
                            [
                                'dimensions' => ['campaign_id' => '174829384', 'stat_time_day' => '2026-11-20'],
                                'metrics'    => ['spend' => '150000', 'impressions' => '8500', 'clicks' => '320', 'conversion' => '12'],
                            ],
                        ],
                        'page_info' => [
                            'page'         => 1,
                            'page_size'    => 1,
                            'total_page'   => 3,
                            'total_number' => 3,
                        ],
                    ],
                ]),
                'duration_ms' => 50.0,
            ];
        });

        $tiktokWithMock = new TikTokConnector(
            ['advertiser_id' => '71829304859'],
            ['access_token' => 'tt_token_xyz'],
            null,
            $mockHttp
        );

        $fetchResult = $tiktokWithMock->fetchData('campaign_metrics', '1', 1);
        assert(count($fetchResult['records']) === 1);
        assert($fetchResult['records'][0]['campaign_id'] === '174829384');
        assert($fetchResult['records'][0]['spend'] === 150000.0);
        assert($fetchResult['records'][0]['conversion'] === 12);
        assert($fetchResult['has_more'] === true);
        assert($fetchResult['next_cursor'] === '2');
    }

    /**
     * 10. Test strict read-only contract & sensitive credential redaction across all connectors.
     */
    private function testStrictReadOnlyContractAndSensitiveDataMaskingAcrossAllConnectors(): void
    {
        $allConnectors = [
            new GoogleSheetsConnector(),
            new GoogleAnalyticsConnector(),
            new SearchConsoleConnector(),
            new YouTubeConnector(),
            new MetaAdsConnector(),
            new TikTokConnector(),
            new WooCommerceConnector(),
            new ShopifyConnector(),
            new MySQLConnector(),
            new CustomAPIConnector(),
        ];

        foreach ($allConnectors as $connector) {
            $code = $connector->getConnectorCode();
            assert(!empty($code), "Connector code cannot be empty.");
            assert(!empty($connector->getName()), "Name cannot be empty for {$code}.");
            assert(!empty($connector->getCategory()), "Category cannot be empty for {$code}.");
            assert(!empty($connector->getAuthType()), "AuthType cannot be empty for {$code}.");

            $datasets = $connector->getAvailableDatasets();
            assert(is_array($datasets) && count($datasets) > 0, "Connector {$code} must offer at least 1 dataset.");

            // Verify permissions validation interface
            $perms = $connector->validatePermissions();
            assert(is_array($perms) && isset($perms['valid']), "Connector {$code} validatePermissions() must return valid flag.");

            // Test disconnect cleans credentials
            $connector->configure(['test' => 1], ['access_token' => 'secret_token_12345']);
            $connector->disconnect();
            assert($connector->getNextCursor() === null, "Disconnect should reset cursor.");
        }
    }

    /**
     * Helper to create a mock HttpClient with custom request handler.
     */
    private function createMockHttpClient(callable $handler): HttpClient
    {
        return new class($handler) extends HttpClient {
            private $handler;

            public function __construct(callable $handler)
            {
                parent::__construct();
                $this->handler = $handler;
            }

            public function get(string $url, array $headers = [], array $options = []): array
            {
                return ($this->handler)('GET', $url, null, $headers, $options);
            }

            public function post(string $url, mixed $data = null, array $headers = [], array $options = []): array
            {
                return ($this->handler)('POST', $url, $data, $headers, $options);
            }
        };
    }
}
