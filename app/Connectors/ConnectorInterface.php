<?php
declare(strict_types=1);

namespace AnaBot\Connectors;

/**
 * Standard Contract for All AnaBot Platform Connectors
 * 
 * Strict Constraint: All external platform access MUST be strictly READ-ONLY.
 * No connector may ever modify or delete data on external platforms.
 */
interface ConnectorInterface
{
    /**
     * Unique identifier code for the connector (e.g. 'google_sheets', 'custom_api').
     */
    public function getConnectorCode(): string;

    /**
     * Human-readable display name.
     */
    public function getName(): string;

    /**
     * Category (e.g. 'spreadsheet', 'analytics', 'seo', 'ecommerce', 'database', 'custom').
     */
    public function getCategory(): string;

    /**
     * Authentication type ('oauth2', 'api_key', 'basic', 'database', 'webhook').
     */
    public function getAuthType(): string;

    /**
     * Configure connector with connection settings and credentials.
     *
     * @param array $config Connection settings (endpoint, parameters, options)
     * @param array $credentials Decrypted credentials (tokens, keys, secrets)
     * @return bool
     */
    public function configure(array $config, array $credentials = []): bool;

    /**
     * Test live connectivity and credentials validity.
     *
     * @return array ['success' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection(): array;

    /**
     * List available datasets or resources from the external platform.
     *
     * @return array List of datasets [['id' => string, 'name' => string, 'description' => string], ...]
     */
    public function getAvailableDatasets(): array;

    /**
     * Fetch a paginated batch of data records (READ-ONLY).
     *
     * @param string $resourceId Dataset or resource identifier
     * @param string|null $cursor Pagination cursor (offset, page token, or timestamp)
     * @param int $limit Maximum records to fetch in this batch
     * @return array ['records' => array, 'next_cursor' => ?string, 'has_more' => bool, 'total' => ?int]
     */
    public function fetchData(string $resourceId, ?string $cursor = null, int $limit = 100): array;

    /**
     * Validate permissions and granted OAuth scopes.
     *
     * @return array ['valid' => bool, 'granted_scopes' => array, 'missing_scopes' => array]
     */
    public function validatePermissions(): array;

    /**
     * Get the next pagination cursor from the most recent fetch operation.
     */
    public function getNextCursor(): ?string;

    /**
     * Safely disconnect and clean up any local connector state.
     *
     * @return bool
     */
    public function disconnect(): bool;
}
