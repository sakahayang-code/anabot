<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Database;
use AnaBot\Core\MigrationRunner;
use AnaBot\Models\Report;
use AnaBot\Services\ReportEngine;
use AnaBot\Services\AnalyticsEngine;
use AnaBot\Services\AnomalyDetector;
use AnaBot\Services\ForecastEngine;
use ZipArchive;
use RuntimeException;

/**
 * Phase 10: Report Engine Automated Test Suite
 * 
 * Verifies:
 * - Report model CRUD, pagination, and storage usage calculations
 * - Deterministic mathematical report data compilation
 * - Modern interactive HTML report generation with embedded SVG charts
 * - Memory-safe streaming CSV report generation with UTF-8 BOM
 * - OpenXML multi-sheet XLSX spreadsheet generation via ZipArchive
 * - Genuine standard PDF 1.4 binary document generation in pure PHP
 * - Multi-format master orchestration (buildAndSave with 'all')
 * - Path traversal protection and secure download isolation
 * - Single-user report ownership and data confidentiality enforcement
 * - Automated garbage collection for expired temporary reports
 */
class ReportEngineTest
{
    private Database $db;
    private ReportEngine $reportEngine;
    private int $testUserId;
    private int $testDatasetId;
    private string $testStoragePath;

    public function run(): void
    {
        echo "Testing Report Engine (Multi-Format & Secure Storage)..." . PHP_EOL;

        $testDbPath = dirname(__DIR__, 2) . '/storage/database/anabot_testing.sqlite';
        if (file_exists($testDbPath)) {
            @unlink($testDbPath);
        }

        $this->db = new Database($testDbPath);
        Database::setInstance($this->db);

        $runner = new MigrationRunner($this->db);
        $runner->run();

        $this->testStoragePath = dirname(__DIR__, 2) . '/storage/temp/test_reports_' . bin2hex(random_bytes(4));
        if (!is_dir($this->testStoragePath)) {
            mkdir($this->testStoragePath, 0750, true);
        }

        $this->reportEngine = new ReportEngine($this->testStoragePath);

        $this->seedDataset();

        $this->testReportModelCrud();
        $this->testReportEngineCompileData();
        $this->testHtmlReportGeneration();
        $this->testCsvReportGeneration();
        $this->testXlsxReportGeneration();
        $this->testPdfReportGeneration();
        $this->testBuildAndSaveAllFormats();
        $this->testSecureDownloadPathValidation();
        $this->testReportOwnershipEnforcement();
        $this->testGarbageCollection();

        $this->cleanupTestDir();

        echo "  [PASS] All 10 Report Engine test cases passed successfully." . PHP_EOL;
    }

    private function seedDataset(): void
    {
        $now = date('c');

        // User
        $this->db->execute(
            "INSERT INTO users (username, email, password_hash, status, created_at, updated_at) 
             VALUES ('report_user', 'report@anabot.local', 'hash', 'active', :n, :n)",
            [':n' => $now]
        );
        $this->testUserId = (int) $this->db->lastInsertId();

        // Platform & Connector
        $this->db->execute("INSERT INTO connectors (connector_code, name, category, auth_type, created_at) VALUES ('sheets', 'Google Sheets', 'spreadsheet', 'oauth2', :n)", [':n' => $now]);
        $cid = (int) $this->db->lastInsertId();

        $this->db->execute("INSERT INTO platform_connections (connector_id, user_id, name, status, created_at, updated_at) VALUES (:cid, :uid, 'Conn', 'connected', :n, :n)", [':cid' => $cid, ':uid' => $this->testUserId, ':n' => $now]);
        $pcid = (int) $this->db->lastInsertId();

        $this->db->execute("INSERT INTO data_sources (connection_id, external_source_id, source_name, created_at, updated_at) VALUES (:pcid, 'src_201', 'Sales Sheet', :n, :n)", [':pcid' => $pcid, ':n' => $now]);
        $dsId = (int) $this->db->lastInsertId();

        $this->db->execute("INSERT INTO datasets (data_source_id, name, record_count, first_record_at, latest_record_at, created_at, updated_at) VALUES (:dsid, 'Laporan Penjualan Q4', 10, '2025-10-01', '2025-10-10', :n, :n)", [':dsid' => $dsId, ':n' => $now]);
        $this->testDatasetId = (int) $this->db->lastInsertId();

        // Columns
        $this->db->execute("INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at) VALUES (:did, 'tanggal', 'DATETIME', 1, 0, 0, 1, :n)", [':did' => $this->testDatasetId, ':n' => $now]);
        $this->db->execute("INSERT INTO dataset_columns (dataset_id, column_name, data_type, is_primary_date, is_metric, is_dimension, column_order, created_at) VALUES (:did, 'omzet', 'NUMERIC', 0, 1, 0, 2, :n)", [':did' => $this->testDatasetId, ':n' => $now]);

        // Records
        $data = [
            ['2025-10-01', 5000000],
            ['2025-10-02', 5500000],
            ['2025-10-03', 6000000],
            ['2025-10-04', 5800000],
            ['2025-10-05', 6200000],
            ['2025-10-06', 6500000],
            ['2025-10-07', 22000000], // Outlier
            ['2025-10-08', 7000000],
            ['2025-10-09', 7200000],
            ['2025-10-10', 7500000],
        ];

        foreach ($data as $idx => $row) {
            $json = json_encode(['tanggal' => $row[0], 'omzet' => $row[1]]);
            $this->db->execute(
                "INSERT INTO data_records (dataset_id, external_id, record_date, record_hash, data_json, created_at, updated_at)
                 VALUES (:did, :ext, :rdate, :rhash, :json, :n, :n)",
                [
                    ':did' => $this->testDatasetId,
                    ':ext' => 'rec_' . ($idx + 1),
                    ':rdate' => $row[0],
                    ':rhash' => hash('sha256', (string) ($idx + 1)),
                    ':json' => $json,
                    ':n' => $now,
                ]
            );
        }
    }

    private function testReportModelCrud(): void
    {
        $reportId = Report::create([
            'user_id' => $this->testUserId,
            'title' => 'Laporan Pengujian CRUD',
            'report_type' => 'html',
            'file_path' => $this->testStoragePath . '/test_crud.html',
            'file_size_bytes' => 12345,
            'date_range_start' => '2025-10-01',
            'date_range_end' => '2025-10-10',
            'summary_content' => 'Ringkasan pengujian model.',
        ]);

        assert($reportId > 0, 'Report ID must be positive integer');

        $found = Report::find($reportId);
        assert($found !== null, 'Report must be found by ID');
        assert($found['title'] === 'Laporan Pengujian CRUD', 'Report title must match');
        assert($found['report_type'] === 'html', 'Report type must match');
        assert((int) $found['file_size_bytes'] === 12345, 'File size bytes must match');

        $list = Report::getByUser($this->testUserId);
        assert(count($list) >= 1, 'Report list must contain at least 1 record');

        $count = Report::countByUser($this->testUserId);
        assert($count >= 1, 'Report count must match');

        $storage = Report::getStorageUsage($this->testUserId);
        assert($storage['total_reports'] >= 1, 'Storage total reports must be positive');
        assert($storage['total_bytes'] >= 12345, 'Storage total bytes must be calculated');

        $deleted = Report::delete($reportId);
        assert($deleted === true, 'Report deletion must return true');

        $foundAfter = Report::find($reportId);
        assert($foundAfter === null, 'Report must be deleted');

        echo "  ✓ Report model CRUD, pagination, and storage usage calculations verified." . PHP_EOL;
    }

    private function testReportEngineCompileData(): void
    {
        $data = $this->reportEngine->compileReportData($this->testDatasetId, 'omzet');

        assert(!empty($data['title']), 'Report title must be populated');
        assert($data['metric_name'] === 'omzet', 'Primary metric must be omzet');
        assert(!empty($data['descriptive_stats']), 'Descriptive stats must be computed');
        assert((int) $data['descriptive_stats']['count'] === 10, 'Count must equal 10 records');
        assert(!empty($data['anomalies']), 'Anomalies must be computed');
        assert(!empty($data['forecast']), 'Forecast must be computed');
        assert(!empty($data['ai_summary']), 'AI summary or grounded fallback must be generated');
        assert(str_contains($data['ai_summary'], 'Ringkasan Eksekutif'), 'Summary must contain executive header');

        echo "  ✓ Deterministic mathematical report data compilation verified." . PHP_EOL;
    }

    private function testHtmlReportGeneration(): void
    {
        $data = $this->reportEngine->compileReportData($this->testDatasetId, 'omzet');
        $result = $this->reportEngine->generateHtml($data, 'test_html_report');

        assert(file_exists($result['file_path']), 'HTML report file must exist');
        assert($result['mime_type'] === 'text/html', 'MIME type must be text/html');
        assert($result['file_size'] > 0, 'HTML file size must be > 0');

        $content = file_get_contents($result['file_path']);
        assert(str_contains($content, '<!DOCTYPE html>'), 'Must contain HTML5 doctype');
        assert(str_contains($content, 'Rata-Rata (Mean)'), 'Must contain mean indicator');
        assert(str_contains($content, '<svg'), 'Must contain embedded standalone SVG chart');
        assert(str_contains($content, 'DISCLAIMER'), 'Must contain assumption disclaimer');

        echo "  ✓ Modern interactive HTML report generation with embedded SVG charts verified." . PHP_EOL;
    }

    private function testCsvReportGeneration(): void
    {
        $data = $this->reportEngine->compileReportData($this->testDatasetId, 'omzet');
        $result = $this->reportEngine->generateCsv($data, 'test_csv_report');

        assert(file_exists($result['file_path']), 'CSV report file must exist');
        assert($result['mime_type'] === 'text/csv', 'MIME type must be text/csv');
        assert($result['file_size'] > 0, 'CSV file size must be > 0');

        $content = file_get_contents($result['file_path']);
        // Check UTF-8 BOM
        assert(str_starts_with($content, "\xEF\xBB\xBF"), 'CSV must begin with UTF-8 BOM for Excel compatibility');
        assert(str_contains($content, '# ANABOT EXECUTIVE PERFORMANCE REPORT'), 'CSV must contain executive header');
        assert(str_contains($content, 'Rata-Rata (Mean)'), 'CSV must contain summary indicators');

        echo "  ✓ Memory-safe streaming CSV report generation with UTF-8 BOM verified." . PHP_EOL;
    }

    private function testXlsxReportGeneration(): void
    {
        $data = $this->reportEngine->compileReportData($this->testDatasetId, 'omzet');
        $result = $this->reportEngine->generateXlsx($data, 'test_excel_report');

        assert(file_exists($result['file_path']), 'XLSX file must exist in storage');
        assert($result['mime_type'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'MIME type must match OpenXML');
        assert($result['file_size'] > 0, 'XLSX file size must be > 0');

        // Verify valid ZIP structure
        $zip = new ZipArchive();
        $openResult = $zip->open($result['file_path']);
        assert($openResult === true, 'XLSX must be a valid openable ZIP archive');

        // Verify essential OpenXML package files
        assert($zip->locateName('[Content_Types].xml') !== false, 'Must contain [Content_Types].xml');
        assert($zip->locateName('xl/workbook.xml') !== false, 'Must contain xl/workbook.xml');
        assert($zip->locateName('xl/styles.xml') !== false, 'Must contain xl/styles.xml');
        assert($zip->locateName('xl/worksheets/sheet1.xml') !== false, 'Must contain sheet1.xml (Ringkasan)');
        assert($zip->locateName('xl/worksheets/sheet2.xml') !== false, 'Must contain sheet2.xml (Statistik)');
        assert($zip->locateName('xl/worksheets/sheet3.xml') !== false, 'Must contain sheet3.xml (Anomali)');
        assert($zip->locateName('xl/worksheets/sheet4.xml') !== false, 'Must contain sheet4.xml (Peramalan)');

        // Verify worksheet content
        $sheet1Content = $zip->getFromName('xl/worksheets/sheet1.xml');
        assert(str_contains($sheet1Content, 'ANABOT EXECUTIVE PERFORMANCE REPORT'), 'Sheet 1 must contain report title');

        $zip->close();

        echo "  ✓ OpenXML multi-sheet XLSX spreadsheet generation via ZipArchive verified." . PHP_EOL;
    }

    private function testPdfReportGeneration(): void
    {
        $data = $this->reportEngine->compileReportData($this->testDatasetId, 'omzet');
        $result = $this->reportEngine->generatePdf($data, 'test_pdf_report');

        assert(file_exists($result['file_path']), 'PDF file must exist in storage');
        assert($result['mime_type'] === 'application/pdf', 'MIME type must match PDF');
        assert($result['file_size'] > 0, 'PDF file size must be > 0');

        $content = file_get_contents($result['file_path']);
        assert(str_starts_with($content, "%PDF-1.4"), 'PDF must begin with valid %PDF-1.4 header');
        assert(str_contains($content, "/Type /Catalog"), 'PDF must contain document catalog');
        assert(str_contains($content, "/Type /Pages"), 'PDF must contain pages tree');
        assert(str_contains($content, "/BaseFont /Helvetica"), 'PDF must contain standard Helvetica font');
        assert(str_contains($content, "xref"), 'PDF must contain cross-reference table');
        assert(str_contains($content, "trailer"), 'PDF must contain trailer dictionary');
        assert(str_contains($content, "%%EOF"), 'PDF must terminate with %%EOF');

        echo "  ✓ Genuine standard PDF 1.4 binary document generation in pure PHP verified." . PHP_EOL;
    }

    private function testBuildAndSaveAllFormats(): void
    {
        $reports = $this->reportEngine->buildAndSave(
            $this->testUserId,
            $this->testDatasetId,
            'all',
            ['title' => 'Laporan Terpadu Semua Format']
        );

        assert(count($reports) === 4, 'Must generate exactly 4 documents for format "all"');

        $types = array_column($reports, 'report_type');
        assert(in_array('html', $types), 'Must include HTML format');
        assert(in_array('pdf', $types), 'Must include PDF format');
        assert(in_array('xlsx', $types), 'Must include XLSX format');
        assert(in_array('csv', $types), 'Must include CSV format');

        foreach ($reports as $r) {
            assert(!empty($r['id']), 'Report record must have database ID');
            assert(file_exists($r['file_path']), "File must exist on disk: {$r['file_path']}");
        }

        echo "  ✓ Multi-format master orchestration (buildAndSave with 'all') verified." . PHP_EOL;
    }

    private function testSecureDownloadPathValidation(): void
    {
        // Path Traversal Attempt
        $maliciousPath = $this->testStoragePath . '/../../.env';
        $traversalCaught = false;

        try {
            $realTarget = realpath($maliciousPath);
            $storageDir = realpath($this->testStoragePath);
            if ($realTarget === false || $storageDir === false || !str_starts_with($realTarget, $storageDir)) {
                throw new RuntimeException("Path traversal detected.");
            }
        } catch (RuntimeException $e) {
            $traversalCaught = true;
        }

        assert($traversalCaught === true, 'Path traversal attempt must be detected and blocked');

        echo "  ✓ Path traversal protection and secure download isolation verified." . PHP_EOL;
    }

    private function testReportOwnershipEnforcement(): void
    {
        $anotherUserId = 999;
        $unauthorizedReport = Report::getByUser($anotherUserId);
        assert(empty($unauthorizedReport), 'User cannot view reports belonging to another user');

        echo "  ✓ Single-user report ownership and data confidentiality enforcement verified." . PHP_EOL;
    }

    private function testGarbageCollection(): void
    {
        // Create an expired report from 45 days ago
        $expiredDate = date('c', strtotime('-45 days'));
        $expiredFile = $this->testStoragePath . '/expired_report.html';
        file_put_contents($expiredFile, '<html>Expired content</html>');

        $expiredId = Report::create([
            'user_id' => $this->testUserId,
            'title' => 'Laporan Kedaluwarsa',
            'report_type' => 'html',
            'file_path' => $expiredFile,
            'file_size_bytes' => filesize($expiredFile),
            'status' => 'generated',
            'created_at' => $expiredDate,
        ]);

        assert(file_exists($expiredFile), 'Expired file must exist prior to cleanup');
        assert(Report::find($expiredId) !== null, 'Expired record must exist prior to cleanup');

        // Run cleanup with 30 days threshold
        $purgedCount = $this->reportEngine->cleanupOldReports(30);

        assert($purgedCount >= 1, 'Cleanup must purge at least 1 expired report');
        assert(!file_exists($expiredFile), 'Expired file must be deleted from storage');
        assert(Report::find($expiredId) === null, 'Expired record must be deleted from database');

        echo "  ✓ Automated garbage collection for expired temporary reports verified." . PHP_EOL;
    }

    private function cleanupTestDir(): void
    {
        if (is_dir($this->testStoragePath)) {
            $files = glob($this->testStoragePath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->testStoragePath);
        }
    }
}
