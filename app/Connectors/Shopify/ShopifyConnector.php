<?php
declare(strict_types=1);

namespace AnaBot\Connectors\Shopify;

use AnaBot\Connectors\BaseConnector;
use RuntimeException;
use Throwable;

/**
 * Shopify Admin REST API Connector
 * 
 * Strict Constraint: All operations are strictly READ-ONLY (HTTP GET only).
 * Synchronizes Shopify orders, revenue metrics, products, and customer retention.
 */
class ShopifyConnector extends BaseConnector
{
    protected string $connectorCode = 'shopify';
    protected string $name = 'Shopify Admin API';
    protected string $category = 'ecommerce';
    protected string $authType = 'oauth2';

    private string $apiVersion = '2024-01';

    public function testConnection(): array
    {
        $shopDomain = $this->getShopDomain();
        if (empty($shopDomain)) {
            return [
                'success' => false,
                'message' => 'Domain toko Shopify (shop_domain) wajib diisi (contoh: toko-saya.myshopify.com).',
                'details' => ['error' => 'MISSING_SHOP_DOMAIN'],
            ];
        }

        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/shop.json";
        $headers = $this->buildAuthHeaders();

        try {
            $response = $this->httpClient->get($url, $headers);
            $isSuccess = $response['status'] >= 200 && $response['status'] < 300;
            $body = json_decode((string) $response['body'], true);
            $shopName = $body['shop']['name'] ?? $shopDomain;

            return [
                'success' => $isSuccess,
                'message' => $isSuccess 
                    ? "Koneksi ke toko Shopify '{$shopName}' berhasil terhubung." 
                    : "Shopify mengembalikan status HTTP {$response['status']}.",
                'details' => [
                    'status_code' => $response['status'],
                    'duration_ms' => $response['duration_ms'],
                    'shop_name'   => $shopName,
                    'currency'    => $body['shop']['currency'] ?? 'IDR',
                    'read_only'   => true,
                ],
            ];
        } catch (Throwable $e) {
            $this->log('error', "Shopify connection test failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => 'Gagal terhubung ke Shopify: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    public function getAvailableDatasets(): array
    {
        return [
            [
                'id'          => 'orders',
                'name'        => 'Pesanan & Transaksi Shopify',
                'description' => 'Data riwayat transaksi pesanan, status finansial, total pembayaran, dan diskon.',
            ],
            [
                'id'          => 'products',
                'name'        => 'Katalog Produk & Varian',
                'description' => 'Daftar produk Shopify, harga jual varian, inventaris stok, dan kategori produk.',
            ],
            [
                'id'          => 'customers',
                'name'        => 'Pelanggan & Riwayat Belanja',
                'description' => 'Statistik basis pelanggan terdaftar, jumlah pesanan, dan rata-rata nilai pesanan.',
            ],
        ];
    }

    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array
    {
        $shopDomain = $this->getShopDomain();
        $limit = max(1, min(250, $limit));

        $endpoint = match ($resourceId) {
            'products'  => "products.json?limit={$limit}",
            'customers' => "customers.json?limit={$limit}",
            default     => "orders.json?status=any&limit={$limit}",
        };

        if (!empty($cursor)) {
            $endpoint .= "&since_id=" . urlencode($cursor);
        }

        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/{$endpoint}";
        $headers = $this->buildAuthHeaders();

        try {
            $response = $this->httpClient->get($url, $headers);
            if ($response['status'] >= 400) {
                throw new RuntimeException("Shopify API error (HTTP {$response['status']}): " . substr((string)$response['body'], 0, 200));
            }

            $body = json_decode((string) $response['body'], true) ?: [];
            $rawKey = match ($resourceId) {
                'products'  => 'products',
                'customers' => 'customers',
                default     => 'orders',
            };
            $items = $body[$rawKey] ?? [];
            $records = [];
            $nextCursor = null;

            foreach ($items as $item) {
                $records[] = $this->transformRecord($resourceId, $item);
                $nextCursor = (string) ($item['id'] ?? $nextCursor);
            }

            $hasMore = count($items) === $limit;
            $this->nextCursor = $hasMore ? $nextCursor : null;

            return [
                'records'     => $records,
                'next_cursor' => $this->nextCursor,
                'has_more'    => $hasMore,
                'total'       => count($records),
            ];
        } catch (Throwable $e) {
            $this->log('error', "Failed fetching Shopify data for {$resourceId}: {$e->getMessage()}");
            throw new RuntimeException("Gagal mengambil data Shopify [{$resourceId}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function validatePermissions(): array
    {
        $token = $this->credentials['access_token'] ?? $this->config['access_token'] ?? '';
        $valid = !empty($token);

        return [
            'valid'          => $valid,
            'granted_scopes' => ['read_orders', 'read_products', 'read_customers'],
            'missing_scopes' => $valid ? [] : ['access_token'],
        ];
    }

    private function transformRecord(string $resourceId, array $item): array
    {
        return match ($resourceId) {
            'products' => [
                'id'            => $item['id'] ?? null,
                'title'         => $item['title'] ?? '',
                'vendor'        => $item['vendor'] ?? '',
                'product_type'  => $item['product_type'] ?? '',
                'status'        => $item['status'] ?? 'active',
                'variants_count'=> count($item['variants'] ?? []),
                'date_created'  => $item['created_at'] ?? date('c'),
            ],
            'customers' => [
                'id'            => $item['id'] ?? null,
                'email'         => $item['email'] ?? '',
                'first_name'    => $item['first_name'] ?? '',
                'last_name'     => $item['last_name'] ?? '',
                'orders_count'  => (int) ($item['orders_count'] ?? 0),
                'total_spent'   => (float) ($item['total_spent'] ?? 0),
                'currency'      => $item['currency'] ?? 'IDR',
                'date_created'  => $item['created_at'] ?? date('c'),
            ],
            default => [
                'id'              => $item['id'] ?? null,
                'order_name'      => $item['name'] ?? (string) ($item['id'] ?? ''),
                'total_price'     => (float) ($item['total_price'] ?? 0),
                'subtotal_price'  => (float) ($item['subtotal_price'] ?? 0),
                'total_discounts' => (float) ($item['total_discounts'] ?? 0),
                'financial_status'=> $item['financial_status'] ?? 'paid',
                'fulfillment_status' => $item['fulfillment_status'] ?? 'unfulfilled',
                'currency'        => $item['currency'] ?? 'IDR',
                'date_created'    => $item['created_at'] ?? date('c'),
                'items_count'     => count($item['line_items'] ?? []),
            ],
        };
    }

    private function getShopDomain(): string
    {
        $domain = trim((string) ($this->config['shop_domain'] ?? ''));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim((string) $domain, '/');
        if (!empty($domain) && !str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }
        return $domain;
    }

    private function buildAuthHeaders(): array
    {
        $token = (string) ($this->credentials['access_token'] ?? $this->config['access_token'] ?? '');
        return [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
            'User-Agent'             => 'AnaBot-Analytics/1.0',
        ];
    }
}
