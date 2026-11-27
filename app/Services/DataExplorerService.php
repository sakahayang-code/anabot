<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Models\Dataset;
use AnaBot\Models\DatasetColumn;
use AnaBot\Models\DataRecord;
use Generator;
use RuntimeException;

/**
 * AnaBot Data Explorer Service
 * 
 * Handles memory-safe dataset queries, schema discovery, record filtering,
 * and streaming CSV exports for shared hosting environments.
 */
class DataExplorerService
{
    public function listDatasets(int $page = 1, int $perPage = 15): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $total = Dataset::count();
        $datasets = Dataset::all($perPage, $offset);

        return [
            'datasets' => $datasets,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    public function getDatasetDetails(int $id): ?array
    {
        $dataset = Dataset::find($id);
        if (!$dataset) {
            return null;
        }

        $columns = DatasetColumn::forDataset($id);
        $dataset['columns'] = $columns;
        return $dataset;
    }

    public function getRecords(int $datasetId, array $query = []): array
    {
        $dataset = Dataset::find($datasetId);
        if (!$dataset) {
            throw new RuntimeException("Dataset [{$datasetId}] not found.");
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($query['per_page'] ?? 20)));
        $search = trim((string) ($query['search'] ?? ''));
        $sortCol = trim((string) ($query['sort'] ?? 'id'));
        $sortDir = strtoupper((string) ($query['dir'] ?? 'ASC'));
        $filterCol = trim((string) ($query['filter_col'] ?? ''));
        $filterVal = trim((string) ($query['filter_val'] ?? ''));

        $columns = DatasetColumn::forDataset($datasetId);
        $total = DataRecord::countForDataset($datasetId, $search, $filterCol, $filterVal);
        $records = DataRecord::paginate(
            $datasetId,
            $page,
            $perPage,
            $search,
            $sortCol,
            $sortDir,
            $filterCol,
            $filterVal
        );

        return [
            'dataset' => $dataset,
            'columns' => $columns,
            'records' => $records,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
            'filters' => [
                'search' => $search,
                'sort' => $sortCol,
                'dir' => $sortDir,
                'filter_col' => $filterCol,
                'filter_val' => $filterVal,
            ],
        ];
    }

    public function getSchema(int $datasetId): array
    {
        $dataset = Dataset::find($datasetId);
        if (!$dataset) {
            throw new RuntimeException("Dataset [{$datasetId}] not found.");
        }

        $columns = DatasetColumn::forDataset($datasetId);

        return [
            'dataset' => $dataset,
            'columns' => $columns,
        ];
    }

    public function updateColumn(int $columnId, array $data): bool
    {
        return DatasetColumn::updateMetadata($columnId, $data);
    }

    public function deleteDataset(int $id): bool
    {
        return Dataset::delete($id);
    }

    /**
     * Memory-safe Streaming CSV Generator using PHP yield
     */
    public function streamCsvExport(int $datasetId): Generator
    {
        $dataset = Dataset::find($datasetId);
        if (!$dataset) {
            throw new RuntimeException("Dataset [{$datasetId}] not found.");
        }

        $columns = DatasetColumn::forDataset($datasetId);
        $colNames = array_column($columns, 'column_name');

        // 1. Yield CSV Header Row
        $headerRow = array_merge(['Record ID', 'External ID', 'Record Date'], $colNames);
        yield $this->arrayToCsvLine($headerRow);

        // 2. Fetch records line-by-line via SQLite cursor
        $cursor = DataRecord::cursorForExport($datasetId);
        while ($row = $cursor->fetch(\PDO::FETCH_ASSOC)) {
            $data = json_decode((string) $row['data_json'], true) ?: [];
            $line = [
                $row['id'],
                $row['external_id'] ?? '',
                $row['record_date'] ?? '',
            ];

            foreach ($colNames as $col) {
                $line[] = $data[$col] ?? '';
            }

            yield $this->arrayToCsvLine($line);
        }
    }

    private function arrayToCsvLine(array $fields): string
    {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $fields, ',', '"', "\\");
        rewind($fp);
        $line = stream_get_contents($fp);
        fclose($fp);
        return $line ?: '';
    }

    public function getOverview(): array
    {
        $db = Database::getInstance();
        $totalDatasets = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM datasets")['cnt'];
        $totalRecords = (int) $db->queryOne("SELECT COUNT(*) as cnt FROM data_records")['cnt'];
        $latestRecord = $db->queryOne("SELECT created_at FROM data_records ORDER BY id DESC LIMIT 1");

        return [
            'total_datasets' => $totalDatasets,
            'total_records' => $totalRecords,
            'latest_record_created_at' => $latestRecord['created_at'] ?? null,
            'storage_engine' => 'SQLite 3 (WAL)',
        ];
    }
}
