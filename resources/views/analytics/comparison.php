<?php
/**
 * Views: Analytics — Comparison & Correlation Dashboard
 * 
 * Variables:
 * - $datasets (array)
 * - $currentDataset (array)
 * - $metrics (array)
 * - $dimensions (array)
 * - $selectedMetric (string)
 * - $selectedDimension (string)
 * - $correlation (array)
 * - $breakdown (array)
 */
$matrix = $correlation['matrix'] ?? [];
$columns = $correlation['columns'] ?? [];
$topPairs = $correlation['top_pairs'] ?? [];
$breakdownItems = $breakdown['items'] ?? [];
?>

<!-- Header Toolbar -->
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0; color: var(--text-primary);">Komparasi & Korelasi Metrik</h1>
            <span class="badge badge-primary">PEARSON & PARETO</span>
            <span class="badge badge-actual">DATA AKTUAL</span>
        </div>
        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">
            Dataset: <strong><?= e($currentDataset['name']) ?></strong> &bull; <?= count($metrics) ?> Metrik Kuantitatif &bull; <?= count($dimensions) ?> Dimensi Kategorikal
        </p>
    </div>

    <!-- Controls Form -->
    <form method="GET" action="/analytics/comparison" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <!-- Dataset Selector -->
        <select name="id" class="form-control" style="font-size: 0.85rem; padding: 0.4rem 0.75rem;" onchange="this.form.submit()">
            <?php foreach ($datasets as $ds): ?>
                <option value="<?= $ds['id'] ?>" <?= (int)$ds['id'] === (int)$currentDataset['id'] ? 'selected' : '' ?>>
                    <?= e($ds['name']) ?> (<?= number_format((int)$ds['record_count']) ?> baris)
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Dimension Selector -->
        <?php if (!empty($dimensions)): ?>
            <select name="dimension" class="form-control" style="font-size: 0.85rem; padding: 0.4rem 0.75rem;" onchange="this.form.submit()">
                <?php foreach ($dimensions as $dim): ?>
                    <option value="<?= e($dim) ?>" <?= $dim === $selectedDimension ? 'selected' : '' ?>>
                        Dimensi: <?= e($dim) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

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
    </form>
</div>

<!-- SECTION 1: Pearson Correlation Matrix & Top Pairs -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- Correlation Matrix Table -->
    <div class="chart-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 class="card-title" style="margin: 0;">Matriks Korelasi Pearson (r)</h3>
            <span style="font-size: 0.75rem; color: var(--text-muted);">-1.0 (Negatif Sempurna) s/d +1.0 (Positif Sempurna)</span>
        </div>

        <?php if (count($columns) < 2): ?>
            <div style="text-align: center; padding: 2.5rem 1rem; color: var(--text-muted);">
                Dibutuhkan minimal 2 kolom bertipe numerik untuk menghasilkan matriks korelasi.
            </div>
        <?php else: ?>
            <div class="table-container" style="overflow-x: auto;">
                <table class="table" style="font-size: 0.8rem; text-align: center;">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Variabel</th>
                            <?php foreach ($columns as $c): ?>
                                <th><code><?= e($c) ?></code></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($columns as $rowVar): ?>
                            <tr>
                                <td style="text-align: left; font-weight: 600;"><code><?= e($rowVar) ?></code></td>
                                <?php foreach ($columns as $colVar): ?>
                                    <?php 
                                    $val = $matrix[$rowVar][$colVar] ?? null;
                                    $isDiagonal = ($rowVar === $colVar);
                                    
                                    // Heatmap cell styling
                                    $cellBg = 'transparent';
                                    $cellColor = 'var(--text-primary)';
                                    if ($isDiagonal) {
                                        $cellBg = 'var(--bg-card)';
                                        $cellColor = 'var(--text-muted)';
                                    } elseif ($val !== null) {
                                        if ($val >= 0.7) {
                                            $cellBg = 'rgba(16, 185, 129, 0.2)';
                                            $cellColor = '#10b981';
                                        } elseif ($val >= 0.3) {
                                            $cellBg = 'rgba(59, 130, 246, 0.15)';
                                            $cellColor = '#2563eb';
                                        } elseif ($val > -0.3) {
                                            $cellBg = 'rgba(107, 114, 128, 0.05)';
                                            $cellColor = '#6b7280';
                                        } elseif ($val > -0.7) {
                                            $cellBg = 'rgba(245, 158, 11, 0.15)';
                                            $cellColor = '#d97706';
                                        } else {
                                            $cellBg = 'rgba(239, 68, 68, 0.2)';
                                            $cellColor = '#dc2626';
                                        }
                                    }
                                    ?>
                                    <td style="background: <?= $cellBg ?>; color: <?= $cellColor ?>; font-weight: <?= $isDiagonal ? '400' : '700' ?>;">
                                        <?= $val !== null ? number_format($val, 2) : '—' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Top Correlated Pairs Ranking -->
    <div class="chart-card">
        <h3 class="card-title" style="margin-bottom: 0.5rem;">Peringkat Hubungan Variabel Terkuat</h3>
        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
            Daftar pasangan metrik dengan korelasi terkuat yang ditemukan secara matematis dalam dataset ini.
        </p>

        <?php if (empty($topPairs)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                Belum ada pasangan metrik yang dapat dihitung korelasinya.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 350px; overflow-y: auto;">
                <?php foreach (array_slice($topPairs, 0, 5) as $pair): ?>
                    <?php 
                    $rVal = (float) $pair['r'];
                    $color = $rVal >= 0 ? '#10b981' : '#ef4444';
                    ?>
                    <div style="background: var(--bg-canvas); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem 1rem; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.2rem;">
                                <code><?= e($pair['var_a']) ?></code> &harr; <code><?= e($pair['var_b']) ?></code>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                Status: <strong><?= e($pair['strength']) ?></strong>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 1.15rem; font-weight: 700; color: <?= $color ?>;">
                                r = <?= number_format($rVal, 3) ?>
                            </div>
                            <div style="font-size: 0.7rem; color: var(--text-muted);">
                                <?= round(abs($rVal) * 100) ?>% kekuatan
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- SECTION 2: Dimension Breakdown & Pareto 80/20 Analysis -->
<div class="chart-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h3 class="card-title" style="margin: 0;">Analisis Kontribusi Kategori & Prinsip Pareto 80/20</h3>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">
                Dimensi: <strong><?= e($selectedDimension ?: 'Tidak Ada') ?></strong> &bull; Total Akumulasi: <strong><?= number_format($breakdown['grand_total'] ?? 0, 2) ?></strong>
            </p>
        </div>
        <span class="badge badge-info">Prinsip Pareto: 20% Kategori Menyumbang 80% Nilai</span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; align-items: start;">
        <!-- Chart.js Horizontal Bar -->
        <div style="height: 320px; position: relative;">
            <canvas id="categoryBarChart"></canvas>
        </div>

        <!-- Pareto Data Table -->
        <div class="table-container" style="max-height: 320px; overflow-y: auto;">
            <table class="table" style="font-size: 0.85rem;">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th>Kategori</th>
                        <th style="text-align: right;">Record</th>
                        <th style="text-align: right;">Total Nilai</th>
                        <th style="text-align: right;">% Porsi</th>
                        <th style="text-align: center;">Pareto 80%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($breakdownItems)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                Tidak ada data untuk dimensi dan metrik yang dipilih.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($breakdownItems as $item): ?>
                            <tr>
                                <td><?= $item['rank'] ?></td>
                                <td style="font-weight: 600;"><?= e($item['category']) ?></td>
                                <td style="text-align: right;"><?= number_format($item['count']) ?></td>
                                <td style="text-align: right; font-weight: 600;"><?= number_format($item['total'], 2) ?></td>
                                <td style="text-align: right;"><?= number_format($item['percentage'], 1) ?>%</td>
                                <td style="text-align: center;">
                                    <?php if ($item['is_pareto_80']): ?>
                                        <span class="badge badge-success" title="Masuk dalam 80% kontribusi utama">TOP 80%</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">Long Tail</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Chart.js Integration Script -->
<script src="/assets/js/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const breakdown = <?= json_encode($breakdownItems) ?>;
    if (!breakdown || breakdown.length === 0) return;

    const labels = breakdown.map(d => d.category);
    const totals = breakdown.map(d => d.total);
    const colors = breakdown.map(d => d.is_pareto_80 ? '#2563eb' : '#94a3b8');

    const ctx = document.getElementById('categoryBarChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Nilai',
                data: totals,
                backgroundColor: colors,
                borderRadius: 4,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ' Total: ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(ctx.parsed.x);
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(val) {
                            return new Intl.NumberFormat('id-ID', { notation: 'compact' }).format(val);
                        }
                    }
                },
                y: {
                    ticks: { font: { size: 11 } }
                }
            }
        }
    });
});
</script>
