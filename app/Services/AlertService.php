<?php
declare(strict_types=1);

namespace AnaBot\Services;

use AnaBot\Core\Database;
use AnaBot\Core\Logger;
use AnaBot\Models\Dataset;
use AnaBot\Models\Notification;
use Throwable;

/**
 * AnaBot Alert & Anomaly Notification Engine
 * 
 * Evaluates dataset anomalies post-sync or on schedule, monitors repeated
 * sync failures, and creates actionable in-app notifications.
 */
class AlertService
{
    private Database $db;
    private AnomalyDetector $anomalyDetector;
    private Logger $logger;

    public function __construct(
        ?Database $db = null,
        ?AnomalyDetector $anomalyDetector = null,
        ?Logger $logger = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->anomalyDetector = $anomalyDetector ?? new AnomalyDetector($this->db);
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Evaluate dataset anomalies and trigger notifications if risk threshold is exceeded.
     */
    public function evaluateDatasetAnomalies(int $datasetId, ?string $metricName = null): array
    {
        $dataset = Dataset::find($datasetId);
        if (!$dataset) {
            return ['dataset_id' => $datasetId, 'alert_triggered' => false, 'reason' => 'Dataset tidak ditemukan.'];
        }

        try {
            $analysis = $this->anomalyDetector->detectDatasetAnomalies($datasetId, $metricName);
            $riskLevel = $analysis['risk_level'] ?? 'LOW';
            $anomalyCount = (int) ($analysis['anomaly_count'] ?? 0);
            $metric = $analysis['metric_analyzed'] ?? 'data';
            $datasetName = $dataset['name'];

            if ($anomalyCount === 0 || $riskLevel === 'LOW') {
                return [
                    'dataset_id'      => $datasetId,
                    'dataset_name'    => $datasetName,
                    'alert_triggered' => false,
                    'risk_level'      => $riskLevel,
                    'anomaly_count'   => 0,
                ];
            }

            // Anti-Fatigue Guard: Do not send duplicate alerts if one was sent in the last 6 hours
            if ($this->hasRecentAnomalyNotification($datasetId, 6)) {
                return [
                    'dataset_id'      => $datasetId,
                    'dataset_name'    => $datasetName,
                    'alert_triggered' => false,
                    'risk_level'      => $riskLevel,
                    'anomaly_count'   => $anomalyCount,
                    'suppressed'      => true,
                    'reason'          => 'Notifikasi serupa telah dikirim dalam 6 jam terakhir.',
                ];
            }

            $severityType = match ($riskLevel) {
                'CRITICAL' => 'danger',
                'MODERATE' => 'warning',
                default    => 'info',
            };

            $title = "[Peringatan {$riskLevel}] Anomali Terdeteksi pada {$datasetName}";
            $message = "Terdeteksi {$anomalyCount} titik anomali signifikan pada metrik '{$metric}' dengan tingkat risiko {$riskLevel}. Evaluasi segera tren metrik untuk memitigasi fluktuasi tidak wajar.";
            $actionUrl = "/analytics/predictive?dataset_id={$datasetId}";

            // Broadcast to active users
            $recipientsCount = Notification::broadcastToAllUsers($title, $message, $severityType, $actionUrl);

            // Record in audit logs
            $now = date('c');
            $this->db->execute(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, created_at)
                 VALUES (NULL, 'ANOMALY_ALERT_TRIGGERED', 'dataset', :did, :vals, :now)",
                [
                    ':did'  => (string) $datasetId,
                    ':vals' => json_encode([
                        'dataset_name'  => $datasetName,
                        'risk_level'    => $riskLevel,
                        'anomaly_count' => $anomalyCount,
                        'recipients'    => $recipientsCount,
                    ], JSON_UNESCAPED_SLASHES),
                    ':now'  => $now,
                ]
            );

            $this->logger->warning("Anomaly alert triggered for dataset #{$datasetId} ({$datasetName}): {$riskLevel} with {$anomalyCount} anomalies.");

            return [
                'dataset_id'       => $datasetId,
                'dataset_name'     => $datasetName,
                'alert_triggered'  => true,
                'risk_level'       => $riskLevel,
                'anomaly_count'    => $anomalyCount,
                'recipients_count' => $recipientsCount,
            ];
        } catch (Throwable $e) {
            $this->logger->error("Failed evaluating dataset anomalies for #{$datasetId}: " . $e->getMessage());
            return [
                'dataset_id'      => $datasetId,
                'alert_triggered' => false,
                'error'           => $e->getMessage(),
            ];
        }
    }

    /**
     * Evaluate anomalies across all active datasets in the platform.
     */
    public function evaluateAllDatasetsAnomalies(): array
    {
        $datasets = $this->db->queryAll("SELECT id, name FROM datasets ORDER BY id ASC");
        $results = [];
        $totalTriggered = 0;

        foreach ($datasets as $ds) {
            $eval = $this->evaluateDatasetAnomalies((int) $ds['id']);
            $results[] = $eval;
            if (!empty($eval['alert_triggered'])) {
                $totalTriggered++;
            }
        }

        return [
            'total_datasets_evaluated' => count($datasets),
            'total_alerts_triggered'   => $totalTriggered,
            'details'                  => $results,
        ];
    }

    /**
     * Check for repeated sync job failures on a connection and notify administrators.
     */
    public function checkRepeatedSyncFailures(int $connectionId): ?array
    {
        $recentJobs = $this->db->queryAll(
            "SELECT id, status, error_message, finished_at 
             FROM sync_jobs 
             WHERE connection_id = :cid 
             ORDER BY id DESC LIMIT 3",
            [':cid' => $connectionId]
        );

        if (count($recentJobs) < 3) {
            return null;
        }

        $allFailed = true;
        foreach ($recentJobs as $job) {
            if ($job['status'] !== 'failed') {
                $allFailed = false;
                break;
            }
        }

        if (!$allFailed) {
            return null;
        }

        $conn = $this->db->queryOne("SELECT name FROM platform_connections WHERE id = :id LIMIT 1", [':id' => $connectionId]);
        $connName = $conn['name'] ?? "Koneksi #{$connectionId}";

        $title = "[Kegagalan Sinkronisasi] {$connName}";
        $message = "Koneksi '{$connName}' gagal melakukan sinkronisasi data 3 kali berturut-turut. Kesalahan terakhir: " . ($recentJobs[0]['error_message'] ?? 'Tidak diketahui');
        $actionUrl = "/connectors/{$connectionId}";

        $recipients = Notification::broadcastToAllUsers($title, $message, 'danger', $actionUrl);
        $this->logger->error("Repeated sync failures detected for connection #{$connectionId} ({$connName}). Alert sent to {$recipients} users.");

        return [
            'connection_id' => $connectionId,
            'connection_name' => $connName,
            'consecutive_failures' => 3,
            'alert_sent' => true,
        ];
    }

    /**
     * Prevent notification spam for the same dataset within a given timeframe.
     */
    private function hasRecentAnomalyNotification(int $datasetId, int $hoursWindow = 6): bool
    {
        $cutoff = date('c', strtotime("-{$hoursWindow} hours"));
        $row = $this->db->queryOne(
            "SELECT id FROM notifications 
             WHERE action_url LIKE :urlPattern 
             AND created_at >= :cutoff LIMIT 1",
            [
                ':urlPattern' => "%dataset_id={$datasetId}%",
                ':cutoff'     => $cutoff,
            ]
        );

        return !empty($row);
    }
}
