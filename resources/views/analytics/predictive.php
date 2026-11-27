<?php
/**
 * AnaBot Predictive Analytics & Anomaly Detection View
 * 
 * @var array $datasets List of available datasets
 * @var array $selectedDataset Active dataset
 * @var int $selectedDatasetId Active dataset ID
 * @var array $metrics Metric columns
 * @var string $selectedMetric Active metric
 * @var string $interval Aggregation interval
 * @var string $forecastMethod Model (ols, holt_winters, moving_avg)
 * @var int $horizon Forecast steps
 * @var float $zThreshold Z-score sensitivity
 * @var array|null $anomalyReport Output from AnomalyDetector
 * @var array|null $forecastReport Output from ForecastEngine
 * @var string|null $errorMessage Any error message
 */

$anomaliesList = $anomalyReport['anomalies'] ?? [];
$riskLevel = $anomalyReport['risk_level'] ?? 'LOW';
$riskBadge = $riskLevel === 'CRITICAL' ? 'danger' : ($riskLevel === 'MODERATE' ? 'warning' : 'success');
$riskIcon = $riskLevel === 'CRITICAL' ? 'fa-radiation' : ($riskLevel === 'MODERATE' ? 'fa-exclamation-triangle' : 'fa-check-circle');

$forecastPoints = $forecastReport['forecast_points'] ?? [];
$historicalPoints = $forecastReport['historical_time_series'] ?? [];
$endForecast = !empty($forecastPoints) ? end($forecastPoints) : null;
$projectedGrowth = $forecastReport['projected_growth_pct'] ?? 0;
?>

<div class="predictive-analytics-container">
    <!-- Page Header & Filter Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h1 class="page-title mb-1">
                        <i class="fas fa-magic text-primary me-2"></i>Predictive Analytics &amp; Anomaly Detection
                    </h1>
                    <p class="text-muted small mb-0">Deteksi outlier statistik (IQR &amp; Z-score) serta proyeksi masa depan deterministik bergaransi transparansi.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-<?= $riskBadge ?>-subtle text-<?= $riskBadge ?> border border-<?= $riskBadge ?>-subtle px-3 py-2 fs-6">
                        <i class="fas <?= $riskIcon ?> me-1"></i> Risiko: <?= $riskLevel ?>
                    </span>
                </div>
            </div>

            <!-- Filter Controls Form -->
            <form method="GET" action="/analytics/predictive" class="row g-2 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label small fw-bold text-muted mb-1">Pilih Dataset</label>
                    <select name="dataset_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($datasets as $ds): ?>
                            <option value="<?= $ds['id'] ?>" <?= $ds['id'] == $selectedDatasetId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ds['name']) ?> (<?= number_format((int)$ds['record_count']) ?> baris)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label small fw-bold text-muted mb-1">Kolom Metrik</label>
                    <select name="metric" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($metrics as $m): ?>
                            <option value="<?= $m ?>" <?= $m === $selectedMetric ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $m))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2 col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">Model Peramalan</label>
                    <select name="method" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="ols" <?= $forecastMethod === 'ols' ? 'selected' : '' ?>>Linear Trend (OLS)</option>
                        <option value="holt_winters" <?= $forecastMethod === 'holt_winters' ? 'selected' : '' ?>>Holt-Winters Smoothing</option>
                        <option value="moving_avg" <?= $forecastMethod === 'moving_avg' ? 'selected' : '' ?>>Moving Average</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">Horizon Proyeksi</label>
                    <select name="horizon" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="3" <?= $horizon === 3 ? 'selected' : '' ?>>+3 Periode</option>
                        <option value="6" <?= $horizon === 6 ? 'selected' : '' ?>>+6 Periode (Standar)</option>
                        <option value="12" <?= $horizon === 12 ? 'selected' : '' ?>>+12 Periode</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">Sensitivitas Anomali</label>
                    <select name="sensitivity" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="normal" <?= $zThreshold >= 2.5 ? 'selected' : '' ?>>Normal (z = 2.5)</option>
                        <option value="strict" <?= $zThreshold < 2.5 ? 'selected' : '' ?>>Ketat (z = 2.0)</option>
                    </select>
                </div>

                <div class="col-lg-1 col-md-12">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-sync-alt"></i> Hitung
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-danger shadow-sm mb-4">
            <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <!-- Bento Metric KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Anomaly Status Card -->
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-xs text-muted text-uppercase fw-bold">Deteksi Anomali</span>
                        <span class="badge bg-<?= $riskBadge ?>-subtle text-<?= $riskBadge ?> text-xs">
                            <?= $riskLevel ?>
                        </span>
                    </div>
                    <div class="fs-4 fw-bold text-<?= $riskBadge ?> mb-1">
                        <?= count($anomaliesList) ?> Titik
                    </div>
                    <div class="text-xs text-muted">
                        Kritis: <strong><?= $anomalyReport['critical_count'] ?? 0 ?></strong> &bull; Moderat: <strong><?= count($anomaliesList) - ($anomalyReport['critical_count'] ?? 0) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Target Forecast Card -->
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-xs text-muted text-uppercase fw-bold">Target Akhir Horizon</span>
                        <span class="badge bg-<?= $projectedGrowth >= 0 ? 'success' : 'danger' ?>-subtle text-<?= $projectedGrowth >= 0 ? 'success' : 'danger' ?> text-xs">
                            <?= ($projectedGrowth >= 0 ? '+' : '') . $projectedGrowth ?>%
                        </span>
                    </div>
                    <div class="fs-4 fw-bold text-primary mb-1">
                        <?= $endForecast ? number_format($endForecast['forecast'], 2) : '0.00' ?>
                    </div>
                    <div class="text-xs text-muted">
                        Basis terakhir: <strong><?= number_format($forecastReport['latest_actual_value'] ?? 0, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Confidence Interval Card -->
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-xs text-muted text-uppercase fw-bold">Rentang Keyakinan 95%</span>
                        <span class="badge bg-info-subtle text-info text-xs">CI 95%</span>
                    </div>
                    <div class="fs-5 fw-bold text-dark mb-1">
                        <?= $endForecast ? number_format($endForecast['lower_bound_95'], 2) : '0' ?> &ndash; <?= $endForecast ? number_format($endForecast['upper_bound_95'], 2) : '0' ?>
                    </div>
                    <div class="text-xs text-muted">
                        Batas bawah s/d batas atas proyeksi
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Model Fit Card -->
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-xs text-muted text-uppercase fw-bold">Kualitas Model</span>
                        <span class="badge bg-secondary-subtle text-secondary text-xs">
                            <?= htmlspecialchars($forecastReport['method_name'] ?? 'Model') ?>
                        </span>
                    </div>
                    <div class="fs-5 fw-bold text-dark mb-1">
                        R² = <?= $forecastReport['parameters']['r_squared'] ?? 'N/A' ?>
                    </div>
                    <div class="text-xs text-muted">
                        MAPE: <strong><?= $forecastReport['accuracy_evaluation']['mape_pct'] ?? '0' ?>%</strong> &bull; <?= $forecastReport['accuracy_evaluation']['fit_quality'] ?? '' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart Visualization Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-area text-primary me-2"></i>Grafik Historis &amp; Proyeksi Masa Depan (<?= htmlspecialchars(ucwords(str_replace('_', ' ', $selectedMetric))) ?>)
                </h5>
                <span class="text-muted text-xs">Menampilkan data aktual historis, garis proyeksi <?= $horizon ?> periode ke depan, rentang keyakinan 95%, dan titik anomali.</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary text-xs"><i class="fas fa-circle text-xs me-1"></i> Aktual</span>
                <span class="badge bg-info text-xs"><i class="fas fa-ellipsis-h text-xs me-1"></i> Proyeksi</span>
                <span class="badge bg-danger text-xs"><i class="fas fa-exclamation-triangle text-xs me-1"></i> Anomali</span>
            </div>
        </div>
        <div class="card-body p-3">
            <div style="height: 380px; position: relative;">
                <canvas id="predictiveForecastChart"></canvas>
            </div>
            <div class="alert alert-secondary py-2 px-3 small mt-3 mb-0 text-muted d-flex align-items-center gap-2">
                <i class="fas fa-shield-alt text-primary fs-5"></i>
                <div>
                    <strong>Klausul Transparansi Asumsi:</strong> <?= htmlspecialchars($forecastReport['assumption_disclaimer'] ?? '') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Dual Split Content Row -->
    <div class="row g-4">
        <!-- LEFT: Anomaly Points Table -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-exclamation-circle text-danger me-2"></i>Daftar Titik Anomali Terdeteksi
                    </h5>
                    <span class="badge bg-danger rounded-pill"><?= count($anomaliesList) ?> Ditemukan</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($anomaliesList)): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fas fa-check-circle text-success fs-2 mb-2 d-block"></i>
                            Tidak ditemukan titik anomali yang mencurigakan.<br>Semua observasi berada dalam rentang wajar (Tukey IQR &amp; Z-score normal).
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Periode</th>
                                        <th class="text-end">Nilai Aktual</th>
                                        <th class="text-center">Z-Score</th>
                                        <th class="text-center">Tingkat</th>
                                        <th>Keterangan / Alasan</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php foreach ($anomaliesList as $anom): ?>
                                        <tr>
                                            <td class="fw-bold text-nowrap"><?= htmlspecialchars($anom['period']) ?></td>
                                            <td class="text-end fw-semibold"><?= number_format($anom['value'], 2) ?></td>
                                            <td class="text-center">
                                                <?php if ($anom['z_score'] !== null): ?>
                                                    <span class="badge bg-<?= abs($anom['z_score']) >= 3.0 ? 'danger' : 'warning' ?>-subtle text-<?= abs($anom['z_score']) >= 3.0 ? 'danger' : 'warning' ?> font-monospace">
                                                        <?= $anom['z_score'] > 0 ? '+' : '' ?><?= $anom['z_score'] ?>σ
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">&ndash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-<?= $anom['severity'] === 'critical' ? 'danger' : 'warning' ?> text-xs">
                                                    <?= strtoupper($anom['severity']) ?>
                                                </span>
                                            </td>
                                            <td class="text-muted text-xs">
                                                <?= htmlspecialchars(implode('; ', $anom['reasons'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: AI Predictive Briefing & Actionable Advisory -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-brain text-info me-2"></i>AI Predictive &amp; Risk Advisory
                    </h5>
                    <button class="btn btn-outline-primary btn-xs" onclick="requestAiInterpretation()" id="interpretBtn">
                        <i class="fas fa-sync-alt me-1"></i> Analisis AI
                    </button>
                </div>
                <div class="card-body p-3">
                    <div id="aiBriefingContent">
                        <div class="alert alert-info py-2 small mb-3">
                            <i class="fas fa-info-circle me-1"></i> Tekan tombol <strong>Analisis AI</strong> di atas untuk memperoleh diagnosis akar masalah anomali dan panduan antisipasi tren masa depan dari model AI yang terikat data terverifikasi.
                        </div>
                        <div class="p-3 bg-light rounded border text-muted small" style="min-height: 250px;">
                            <div class="fw-bold mb-2 text-dark">
                                <i class="fas fa-robot text-primary me-1"></i> Ringkasan Otomatis PHP Engine:
                            </div>
                            <ul class="mb-3 ps-3">
                                <li>Tren data historis terdeteksi <strong><?= $projectedGrowth >= 0 ? 'Meningkat (Uptrend)' : 'Menurun (Downtrend)' ?></strong> dengan ekspektasi kumulatif <strong><?= ($projectedGrowth >= 0 ? '+' : '') . $projectedGrowth ?>%</strong> dalam <?= $horizon ?> periode ke depan.</li>
                                <li>Ditemukan <strong><?= count($anomaliesList) ?> titik anomali</strong> dengan status risiko dataset <strong><?= $riskLevel ?></strong>.</li>
                                <li>Model peramalan <strong><?= htmlspecialchars($forecastReport['method_name'] ?? '') ?></strong> memiliki skor akurasi R² = <strong><?= $forecastReport['parameters']['r_squared'] ?? 'N/A' ?></strong>.</li>
                            </ul>
                            <div class="text-xs text-muted fst-italic">
                                Siap diinterpretasikan secara mendalam oleh AI Analyst.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Setup Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const historical = <?= json_encode($historicalPoints) ?>;
    const forecast = <?= json_encode($forecastPoints) ?>;
    const anomalies = <?= json_encode($anomaliesList) ?>;

    const labels = [];
    const actualData = [];
    const forecastData = [];
    const lowerBounds = [];
    const upperBounds = [];
    const pointRadii = [];
    const pointColors = [];

    // Map historical points
    historical.forEach((pt, idx) => {
        labels.push(pt.period);
        actualData.push(pt.total);
        forecastData.push(null);
        lowerBounds.push(null);
        upperBounds.push(null);

        // Check if point is an anomaly
        const isAnom = anomalies.find(a => a.period === pt.period);
        if (isAnom) {
            pointRadii.push(isAnom.severity === 'critical' ? 7 : 5);
            pointColors.push(isAnom.severity === 'critical' ? '#ef4444' : '#f59e0b');
        } else {
            pointRadii.push(3);
            pointColors.push('#3b82f6');
        }
    });

    // Bridge last actual point into forecast line for continuity
    if (historical.length > 0 && forecast.length > 0) {
        const lastHist = historical[historical.length - 1];
        const lastIdx = historical.length - 1;
        forecastData[lastIdx] = lastHist.total;
        lowerBounds[lastIdx] = lastHist.total;
        upperBounds[lastIdx] = lastHist.total;
    }

    // Map forecast points
    forecast.forEach(pt => {
        labels.push(pt.period);
        actualData.push(null);
        forecastData.push(pt.forecast);
        lowerBounds.push(pt.lower_bound_95);
        upperBounds.push(pt.upper_bound_95);
        pointRadii.push(4);
        pointColors.push('#06b6d4');
    });

    const ctx = document.getElementById('predictiveForecastChart')?.getContext('2d');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Nilai Aktual Historis',
                        data: actualData,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.05)',
                        borderWidth: 2,
                        pointRadius: pointRadii,
                        pointBackgroundColor: pointColors,
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1.5,
                        tension: 0.2,
                        fill: false
                    },
                    {
                        label: 'Proyeksi Masa Depan',
                        data: forecastData,
                        borderColor: '#06b6d4',
                        borderDash: [6, 4],
                        borderWidth: 2.5,
                        pointRadius: 4,
                        pointBackgroundColor: '#06b6d4',
                        tension: 0.2,
                        fill: false
                    },
                    {
                        label: 'Batas Bawah 95%',
                        data: lowerBounds,
                        borderColor: 'transparent',
                        backgroundColor: 'rgba(6, 182, 212, 0.12)',
                        pointRadius: 0,
                        fill: '+1' // Fill between lower and upper bounds
                    },
                    {
                        label: 'Batas Atas 95%',
                        data: upperBounds,
                        borderColor: 'transparent',
                        backgroundColor: 'transparent',
                        pointRadius: 0,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                if (context.raw === null) return null;
                                return context.dataset.label + ': ' + Number(context.raw).toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    }
                }
            }
        });
    }
});

// AI Interpretation Request
async function requestAiInterpretation() {
    const btn = document.getElementById('interpretBtn');
    const container = document.getElementById('aiBriefingContent');
    if (!btn || !container) return;

    btn.disabled = true;
    container.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <div class="fw-bold">AnaBot sedang menganalisis anomali & proyeksi matematis...</div>
            <div class="text-muted text-xs mt-1">Menyusun diagnosis risiko dan rekomendasi aksi tanpa halusinasi.</div>
        </div>
    `;

    try {
        const res = await fetch('/api/v1/analytics/datasets/<?= $selectedDatasetId ?>/interpret-predictive', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                metric: '<?= $selectedMetric ?>',
                interval: '<?= $interval ?>'
            })
        });

        const data = await res.json();
        btn.disabled = false;

        if (data.success && data.data) {
            const formatted = data.data.interpretation.replace(/\n/g, '<br>');
            container.innerHTML = `
                <div class="p-3 bg-white rounded border shadow-2xs">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <span class="badge bg-primary-subtle text-primary">
                            <i class="fas fa-robot me-1"></i> ${data.data.provider} (${data.data.model})
                        </span>
                        <span class="text-xs text-muted">
                            <i class="fas fa-bolt text-warning me-1"></i> ${data.data.tokens} tokens
                        </span>
                    </div>
                    <div class="small markdown-body lh-base">
                        ${formatted}
                    </div>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="alert alert-warning py-3">
                    <i class="fas fa-exclamation-triangle me-1"></i> ${data.error || 'Gagal memperoleh interpretasi AI.'}
                </div>
            `;
        }
    } catch (err) {
        btn.disabled = false;
        container.innerHTML = `
            <div class="alert alert-danger py-3">
                <i class="fas fa-times-circle me-1"></i> Kendala koneksi: ${err.message}
            </div>
        `;
    }
}
</script>
