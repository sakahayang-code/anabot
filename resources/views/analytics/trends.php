<?php
/**
 * Views: Analytics — Trends & Growth Dashboard
 * 
 * Variables:
 * - $datasets (array)
 * - $currentDataset (array)
 * - $metrics (array)
 * - $selectedMetric (string)
 * - $interval (string)
 * - $stats (array)
 * - $timeSeries (array)
 * - $growth (array)
 * - $trend (array)
 */
$summary = $growth['summary'] ?? [];
$trendDir = $trend['direction'] ?? 'flat';
$trendBadgeClass = match($trendDir) {
    'uptrend' => 'badge-success',
    'downtrend' => 'badge-danger',
    default => 'badge-info',
};
$trendLabel = match($trendDir) {
    'uptrend' => '📈 Tren Naik (Uptrend)',
    'downtrend' => '📉 Tren Turun (Downtrend)',
    default => '➡️ Stabil / Mendatar',
};
?>

<!-- Header Toolbar -->
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0; color: var(--text-primary);">Analisis Tren & Pertumbuhan</h1>
            <span class="badge <?= $trendBadgeClass ?>"><?= $trendLabel ?></span>
            <span class="badge badge-actual">DATA AKTUAL</span>
        </div>
        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">
            Dataset: <strong><?= e($currentDataset['name']) ?></strong> &bull; Metrik: <code><?= e($selectedMetric) ?></code> &bull; Interval: <code><?= strtoupper($interval) ?></code>
        </p>
    </div>

    <!-- Controls Form -->
    <form method="GET" action="/analytics/trends" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <!-- Dataset Selector -->
        <select name="id" class="form-control" style="font-size: 0.85rem; padding: 0.4rem 0.75rem;" onchange="this.form.submit()">
            <?php foreach ($datasets as $ds): ?>
                <option value="<?= $ds['id'] ?>" <?= (int)$ds['id'] === (int)$currentDataset['id'] ? 'selected' : '' ?>>
                    <?= e($ds['name']) ?> (<?= number_format((int)$ds['record_count']) ?> baris)
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Metric Selector -->
        <?php if (!empty($metrics)): ?>
            <select name="metric" class="form-control" style="font-size: 0.85rem; padding: 0.4rem 0.75rem;" onchange="this.form.submit()">
                <?php foreach ($metrics as $m): ?>
                    <option value="<?= e($m) ?>" <?= $m === $selectedMetric ? 'selected' : '' ?>>
                        Metrik: <?= e($m) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <!-- Interval Selector -->
        <div style="display: flex; border: 1px solid var(--border-color); border-radius: var(--radius-sm); overflow: hidden;">
            <button type="submit" name="interval" value="day" class="btn btn-sm <?= $interval === 'day' ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 0; border: none; padding: 0.4rem 0.6rem; font-size: 0.75rem;">Harian</button>
            <button type="submit" name="interval" value="week" class="btn btn-sm <?= $interval === 'week' ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 0; border: none; padding: 0.4rem 0.6rem; font-size: 0.75rem;">Mingguan</button>
            <button type="submit" name="interval" value="month" class="btn btn-sm <?= $interval === 'month' ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 0; border: none; padding: 0.4rem 0.6rem; font-size: 0.75rem;">Bulanan</button>
            <button type="submit" name="interval" value="year" class="btn btn-sm <?= $interval === 'year' ? 'btn-primary' : 'btn-outline' ?>" style="border-radius: 0; border: none; padding: 0.4rem 0.6rem; font-size: 0.75rem;">Tahunan</button>
        </div>

        <button type="button" onclick="recalculateAnalytics(<?= (int)$currentDataset['id'] ?>)" class="btn btn-outline btn-sm" title="Hitung ulang statistik">
            🔄 Refresh
        </button>
    </form>
</div>

<!-- Bento KPI Cards (Descriptive & Growth Summary) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <!-- Card 1: Total Sum -->
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Total Akumulasi (Sum)</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
            <?= number_format($stats['sum'] ?? 0, 2) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">
            Dari <?= number_format($stats['count'] ?? 0) ?> observasi valid
        </div>
    </div>

    <!-- Card 2: Mean & Median -->
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Rata-rata & Median</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: var(--brand-primary); margin-bottom: 0.25rem;">
            <?= number_format($stats['mean'] ?? 0, 2) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">
            Median: <strong><?= number_format($stats['median'] ?? 0, 2) ?></strong> | Modus: <?= number_format($stats['mode'] ?? 0, 2) ?>
        </div>
    </div>

    <!-- Card 3: Growth Rate -->
    <?php 
    $growthPct = (float) ($summary['overall_growth_pct'] ?? 0);
    $growthColor = $growthPct >= 0 ? '#10b981' : '#ef4444';
    $growthArrow = $growthPct >= 0 ? '↑' : '↓';
    ?>
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Pertumbuhan Periode</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: <?= $growthColor ?>; margin-bottom: 0.25rem;">
            <?= $growthArrow ?> <?= abs($growthPct) ?>%
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">
            Rata-rata/periode: <strong><?= number_format($summary['average_growth_pct'] ?? 0, 1) ?>%</strong>
        </div>
    </div>

    <!-- Card 4: Dispersion (Std Dev & Range) -->
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Variabilitas (Std Dev)</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
            <?= number_format($stats['std_dev'] ?? 0, 2) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">
            Min: <?= number_format($stats['min'] ?? 0, 1) ?> &bull; Max: <?= number_format($stats['max'] ?? 0, 1) ?>
        </div>
    </div>
</div>

<!-- Time Series Chart Card -->
<div class="chart-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h3 class="card-title" style="margin: 0;">Grafik Tren Rangkaian Waktu & Moving Average</h3>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">
                <?= e($trend['description'] ?? 'Analisis pergerakan data aktual vs Simple Moving Average (SMA) dan Exponential Moving Average (EMA).') ?>
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; font-size: 0.75rem;">
            <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                <input type="checkbox" id="toggleActual" checked onchange="updateChartVisibility()"> Aktual
            </label>
            <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                <input type="checkbox" id="toggleSma" checked onchange="updateChartVisibility()"> SMA (7)
            </label>
            <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                <input type="checkbox" id="toggleEma" checked onchange="updateChartVisibility()"> EMA (7)
            </label>
        </div>
    </div>

    <div style="height: 320px; position: relative;">
        <canvas id="trendChart"></canvas>
    </div>
</div>

<!-- Periodic Growth Analysis Table -->
<div class="chart-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3 class="card-title" style="margin: 0;">Tabel Rincian Pertumbuhan Periode ke Periode</h3>
        <span style="font-size: 0.8rem; color: var(--text-muted);"><?= count($timeSeries) ?> periode terhitung</span>
    </div>

    <div class="table-container" style="max-height: 400px; overflow-y: auto;">
        <table class="table" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Periode</th>
                    <th style="text-align: right;">Jumlah Record</th>
                    <th style="text-align: right;">Total Nilai</th>
                    <th style="text-align: right;">Rata-rata</th>
                    <th style="text-align: right;">Nilai Sebelumnya</th>
                    <th style="text-align: right;">Perubahan</th>
                    <th style="text-align: center;">% Pertumbuhan</th>
                    <th style="text-align: center;">Arah</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($timeSeries)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            Tidak ada data untuk rentang dan metrik yang dipilih.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($timeSeries as $row): ?>
                        <?php 
                        $pct = (float) ($row['percentage_change'] ?? 0);
                        $badgeStyle = 'background: rgba(107, 114, 128, 0.15); color: #6b7280;';
                        $arrow = '•';
                        if ($row['direction'] === 'up') {
                            $badgeStyle = 'background: rgba(16, 185, 129, 0.15); color: #10b981; font-weight: 600;';
                            $arrow = '↑';
                        } elseif ($row['direction'] === 'down') {
                            $badgeStyle = 'background: rgba(239, 68, 68, 0.15); color: #ef4444; font-weight: 600;';
                            $arrow = '↓';
                        }
                        ?>
                        <tr>
                            <td><code><?= e($row['period']) ?></code></td>
                            <td style="text-align: right;"><?= number_format($row['record_count']) ?></td>
                            <td style="text-align: right; font-weight: 600;"><?= number_format($row['total'], 2) ?></td>
                            <td style="text-align: right; color: var(--text-secondary);"><?= number_format($row['average'], 2) ?></td>
                            <td style="text-align: right; color: var(--text-muted);">
                                <?= $row['previous_value'] !== null ? number_format($row['previous_value'], 2) : '—' ?>
                            </td>
                            <td style="text-align: right; color: <?= $row['absolute_change'] >= 0 ? 'var(--text-primary)' : '#ef4444' ?>;">
                                <?= $row['absolute_change'] > 0 ? '+' : '' ?><?= number_format($row['absolute_change'], 2) ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="display: inline-block; padding: 0.15rem 0.5rem; border-radius: var(--radius-sm); font-size: 0.75rem; <?= $badgeStyle ?>">
                                    <?= $arrow ?> <?= number_format($pct, 2) ?>%
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($row['direction'] === 'up'): ?>
                                    <span style="color: #10b981;" title="Naik">📈</span>
                                <?php elseif ($row['direction'] === 'down'): ?>
                                    <span style="color: #ef4444;" title="Turun">📉</span>
                                <?php else: ?>
                                    <span style="color: #6b7280;" title="Mendatar">➖</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Chart.js Integration Script -->
<script src="<?= e(url('assets/js/chart.min.js')) ?>"></script>
<script>
let trendChartInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    const rawData = <?= json_encode($timeSeries) ?>;
    if (!rawData || rawData.length === 0) return;

    const labels = rawData.map(d => d.period);
    const actualData = rawData.map(d => d.total);
    const smaData = rawData.map(d => d.sma ?? null);
    const emaData = rawData.map(d => d.ema ?? null);

    const ctx = document.getElementById('trendChart').getContext('2d');
    trendChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Aktual',
                    data: actualData,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: true,
                    tension: 0.2,
                    borderWidth: 2,
                    pointRadius: rawData.length > 30 ? 1 : 3,
                },
                {
                    label: 'SMA (7)',
                    data: smaData,
                    borderColor: '#10b981',
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.2,
                    borderWidth: 2,
                    pointRadius: 0,
                },
                {
                    label: 'EMA (7)',
                    data: emaData,
                    borderColor: '#f59e0b',
                    borderDash: [2, 2],
                    fill: false,
                    tension: 0.2,
                    borderWidth: 2,
                    pointRadius: 0,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxTicksLimit: 12, font: { size: 10 } }
                },
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(val) {
                            return new Intl.NumberFormat('id-ID', { notation: 'compact' }).format(val);
                        }
                    }
                }
            }
        }
    });
});

function updateChartVisibility() {
    if (!trendChartInstance) return;
    trendChartInstance.isDatasetVisible(0);
    trendChartInstance.setDatasetVisibility(0, document.getElementById('toggleActual').checked);
    trendChartInstance.setDatasetVisibility(1, document.getElementById('toggleSma').checked);
    trendChartInstance.setDatasetVisibility(2, document.getElementById('toggleEma').checked);
    trendChartInstance.update();
}

async function recalculateAnalytics(datasetId) {
    if (!confirm('Hitung ulang metrik analitik lengkap untuk dataset ini?')) return;
    try {
        const base = window.ANABOT_BASE_URL || '';
        const res = await fetch(`${base}/api/v1/analytics/datasets/${datasetId}/recalculate`, { method: 'POST' });
        const json = await res.json();
        if (json.success) {
            window.location.reload();
        } else {
            alert('Gagal: ' + (json.error || 'Terjadi kesalahan'));
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan: ' + e.message);
    }
}
</script>
