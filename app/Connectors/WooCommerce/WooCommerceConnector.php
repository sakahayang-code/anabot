<?php
declare(strict_types=1);

namespace AnaBot\Connectors\WooCommerce;

use AnaBot\Connectors\BaseConnector;
use RuntimeException;
use Throwable;

/**
 * WooCommerce REST API Connector
 * 
 * Strict Constraint: All operations are strictly READ-ONLY (HTTP GET only).
 * Synchronizes e-commerce orders, product inventory, and customer metrics.
 */
class WooCommerceConnector extends BaseConnector
{
    protected string $connectorCode = 'woocommerce';
    protected string $name = 'WooCommerce REST API';
    protected string $category = 'ecommerce';
    protected string $authType = 'api_key';

    public function testConnection(): array
    {
        $siteUrl = rtrim((string) ($this->config['site_url'] ?? ''), '/');
        if (empty($siteUrl)) {
            return [
                'success' => false,
                'message' => 'URL situs WooCommerce (site_url) wajib diisi.',
                'details' => ['error' => 'MISSING_SITE_URL'],
            ];
        }

        $endpoint = $siteUrl . '/wp-json/wc/v3/orders?per_page=1';
        $headers = $this->buildAuthHeaders();

        try {
            $response = $this->httpClient->get($endpoint, $headers);
            $isSuccess = $response['status'] >= 200 && $response['status'] < 300;

            return [
                'success' => $isSuccess,
                'message' => $isSuccess 
                    ? 'Koneksi ke WooCommerce REST API berhasil terhubung.' 
                    : "Server WooCommerce mengembalikan status HTTP {$response['status']}.",
                'details' => [
                    'status_code' => $response['status'],
                    'duration_ms' => $response['duration_ms'],
                    'site_url'    => $siteUrl,
                    'read_only'   => true,
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "WooCommerce connection test failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke WooCommerce: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    public function getAvailableDatasets(): array
    {
        return [
            [
                'id'          => 'orders',
                'name'        => 'Pesanan & Transaksi Penjualan',
                'description' => 'Data riwayat transaksi pesanan, status pembayaran, pendapatan total, dan pelanggan.',
            ],
            [
                'id'          => 'products',
                'name'        => 'Katalog Produk & Stok',
                'description' => 'Daftar produk e-commerce, harga jual, kuantitas stok, dan kategori produk.',
            ],
            [
                'id'          => 'customers',
                'name'        => 'Data Pelanggan & Pembeli',
                'description' => 'Statistik pelanggan terdaftar, jumlah pesanan historis, dan total pengeluaran.',
            ],
        ];
    }

    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $siteUrl = rtrim((string) ($this->config['site_url'] ?? ''), '/');
        $limit = max(1, min(100, $limit));
        $page = $cursor ? (int) $cursor : 1;

        $endpoint = match ($resourceId) {
            'products'  => "/wp-json/wc/v3/products?per_page={$limit}&page={$page}",
            'customers' => "/wp-json/wc/v3/customers?per_page={$limit}&page={$page}",
            default     => "/wp-json/wc/v3/orders?per_page={$limit}&page={$page}&status=any",
        };

        $url = $siteUrl . $endpoint;
        $headers = $this->buildAuthHeaders();

        try {
            $response = $this->httpClient->get($url, $headers);
            if ($response['status'] >= 400) {
                throw new RuntimeException("WooCommerce API error (HTTP {$response['status']}): " . substr((string)$response['body'], 0, 200));
            }

            $rawItems = json_decode((string) $response['body'], true) ?: [];
            $records = [];

            foreach ($rawItems as $item) {
                $records[] = $this->transformRecord($resourceId, $item);
            }

            $totalPages = isset($response['headers']['x-wp-totalpages']) 
                ? (int) $response['headers']['x-wp-totalpages'] 
                : null;
            $hasMore = count($rawItems) === $limit && ($totalPages === null || $page < $totalPages);
            $nextCursor = $hasMore ? (string) ($page + 1) : null;
            $this->nextCursor = $nextCursor;

            return [
                'records'     => $records,
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
                'total'       => count($records),
            ];
        } catch (Throwable $e) {
            $this->log('error', "Failed fetching WooCommerce data for {$resourceId}: {$e->getMessage()}");
            throw new RuntimeException("Gagal mengambil data WooCommerce [{$resourceId}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function validatePermissions(): array
    {
        $ck = $this->credentials['consumer_key'] ?? $this->config['consumer_key'] ?? '';
        $cs = $this->credentials['consumer_secret'] ?? $this->config['consumer_secret'] ?? '';

        $hasKeys = !empty($ck) && !empty($cs);
        return [
            'valid'          => $hasKeys,
            'granted_scopes' => ['read'],
            'missing_scopes' => $hasKeys ? [] : ['consumer_key', 'consumer_secret'],
        ];
    }

    private function transformRecord(string $resourceId, array $item): array
    {
        return match ($resourceId) {
            'products' => [
                'id'            => $item['id'] ?? null,
                'name'          => $item['name'] ?? '',
                'price'         => (float) ($item['price'] ?? 0),
                'regular_price' => (float) ($item['regular_price'] ?? 0),
                'sale_price'    => (float) ($item['sale_price'] ?? 0),
                'stock_status'  => $item['stock_status'] ?? 'instock',
                'stock_quantity'=> (int) ($item['stock_quantity'] ?? 0),
                'total_sales'   => (int) ($item['total_sales'] ?? 0),
                'date_created'  => $item['date_created'] ?? date('c'),
            ],
            'customers' => [
                'id'            => $item['id'] ?? null,
                'email'         => $item['email'] ?? '',
                'first_name'    => $item['first_name'] ?? '',
                'last_name'     => $item['last_name'] ?? '',
                'orders_count'  => (int) ($item['orders_count'] ?? 0),
                'total_spent'   => (float) ($item['total_spent'] ?? 0),
                'date_created'  => $item['date_created'] ?? date('c'),
            ],
            default => [
                'id'            => $item['id'] ?? null,
                'order_number'  => $item['number'] ?? (string) ($item['id'] ?? ''),
                'status'        => $item['status'] ?? 'completed',
                'currency'      => $item['currency'] ?? 'IDR',
                'total'         => (float) ($item['total'] ?? 0),
                'total_tax'     => (float) ($item['total_tax'] ?? 0),
                'shipping_total'=> (float) ($item['shipping_total'] ?? 0),
                'customer_id'   => $item['customer_id'] ?? 0,
                'date_created'  => $item['date_created'] ?? date('c'),
                'items_count'   => count($item['line_items'] ?? []),
            ],
        };
    }

    private function buildAuthHeaders(): array
    {
        $ck = (string) ($this->credentials['consumer_key'] ?? $this->config['consumer_key'] ?? '');
        $cs = (string) ($this->credentials['consumer_secret'] ?? $this->config['consumer_secret'] ?? '');

        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => 'AnaBot-Analytics/1.0',
        ];

        if (!empty($ck) && !empty($cs)) {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$ck}:{$cs}");
        }

        return $headers;
    }
}
