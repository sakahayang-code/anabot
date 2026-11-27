<?php
declare(strict_types=1);

/**
 * AnaBot Data Explorer - Records Browser View
 * Fast SQLite Paginated Table with Text Search, Column Filters & CSV Export
 */

$colNames = !empty($columns) ? array_column($columns, 'column_name') : [];
$datasetId = $dataset['id'] ?? 0;
?>

<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
    <div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Penelusuran Records: <?= e($dataset['name'] ?? 'Pilih Dataset') ?>
            </h2>
            <?php if (!empty($dataset)): ?>
                <span class="badge badge-actual"><?= number_format((int) ($pagination['total_records'] ?? 0)) ?> Records</span>
            <?php endif; ?>
        </div>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0 0;">
            Data disimpan secara terindeks di SQLite dan dibaca bertahap menggunakan paginasi aman memori.
        </p>
    </div>

    <!-- Actions & Dataset Switcher -->
    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
        <?php if (!empty($allDatasets)): ?>
            <select onchange="window.location.href='/explorer/datasets/' + this.value + '/records'" style="padding: 0.45rem 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-primary); font-size: 0.85rem;">
                <?php foreach ($allDatasets as $ds): ?>
                    <option value="<?= (int) $ds['id'] ?>" <?= (int) $ds['id'] === (int) $datasetId ? 'selected' : '' ?>>
                        Dataset: <?= e($ds['name']) ?> (<?= number_format((int) $ds['record_count']) ?> baris)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if (!empty($dataset)): ?>
            <a href="/explorer/datasets/<?= (int) $datasetId ?>/schema" class="btn btn-outline btn-sm">
                <span>🔍</span> Skema Kolom
            </a>
            <a href="/explorer/datasets/<?= (int) $datasetId ?>/export" class="btn btn-primary btn-sm" title="Streaming Download CSV">
                <span>📥</span> Ekspor CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($dataset)): ?>
    <div class="data-table-wrapper" style="text-align: center; padding: 3rem; color: var(--text-muted);">
        <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📁</div>
        <div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 0.25rem;">Tidak ada dataset yang dipilih</div>
        <p style="font-size: 0.85rem; margin-bottom: 1rem;">Silakan buat koneksi atau pilih dataset dari daftar untuk menelusuri data.</p>
        <a href="/explorer/datasets" class="btn btn-primary btn-sm">Lihat Semua Dataset</a>
    </div>
<?php else: ?>
    <!-- Search & Filter Controls Toolbar -->
    <div class="chart-card" style="padding: 1rem; margin-bottom: 1.25rem;">
        <form method="GET" action="/explorer/datasets/<?= (int) $datasetId ?>/records" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
            <!-- Text Search -->
            <div style="flex: 2; min-width: 200px;">
                <input type="text" name="search" value="<?= e($filters['search'] ?? '') ?>" placeholder="Cari teks dalam baris data..." style="width: 100%; padding: 0.5rem 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-canvas); color: var(--text-primary); font-size: 0.85rem;">
            </div>

            <!-- Column Filter -->
            <?php if (!empty($colNames)): ?>
                <div style="flex: 1; min-width: 140px;">
                    <select name="filter_col" style="width: 100%; padding: 0.5rem 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-canvas); color: var(--text-primary); font-size: 0.85rem;">
                        <option value="">-- Filter Kolom --</option>
                        <?php foreach ($colNames as $cn): ?>
                            <option value="<?= e($cn) ?>" <?= ($filters['filter_col'] ?? '') === $cn ? 'selected' : '' ?>>
                                <?= e($cn) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 140px;">
                    <input type="text" name="filter_val" value="<?= e($filters['filter_val'] ?? '') ?>" placeholder="Nilai yang dicari..." style="width: 100%; padding: 0.5rem 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-canvas); color: var(--text-primary); font-size: 0.85rem;">
                </div>
            <?php endif; ?>

            <!-- Sort Column & Direction -->
            <div style="display: flex; gap: 0.25rem;">
                <select name="sort" style="padding: 0.5rem 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-canvas); color: var(--text-primary); font-size: 0.85rem;">
                    <option value="id" <?= ($filters['sort'] ?? '') === 'id' ? 'selected' : '' ?>>Urut: ID</option>
                    <option value="record_date" <?= ($filters['sort'] ?? '') === 'record_date' ? 'selected' : '' ?>>Urut: Tanggal Record</option>
                    <?php foreach ($colNames as $cn): ?>
                        <option value="<?= e($cn) ?>" <?= ($filters['sort'] ?? '') === $cn ? 'selected' : '' ?>>
                            Urut: <?= e($cn) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="dir" style="padding: 0.5rem 0.5rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); background: var(--bg-canvas); color: var(--text-primary); font-size: 0.85rem;">
                    <option value="ASC" <?= ($filters['dir'] ?? '') === 'ASC' ? 'selected' : '' ?>>↑ Naik</option>
                    <option value="DESC" <?= ($filters['dir'] ?? '') === 'DESC' ? 'selected' : '' ?>>↓ Turun</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Terapkan Filter</button>
            <?php if (!empty($filters['search']) || !empty($filters['filter_val'])): ?>
                <a href="/explorer/datasets/<?= (int) $datasetId ?>/records" class="btn btn-outline btn-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Paginated Records Table -->
    <div class="data-table-wrapper">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th style="width: 140px;">Tanggal Record</th>
                        <?php if (empty($colNames)): ?>
                            <th>Data JSON</th>
                        <?php else: ?>
                            <?php foreach ($columns as $c): ?>
                                <th>
                                    <div><?= e($c['column_name']) ?></div>
                                    <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: normal;"><?= e($c['data_type']) ?></div>
                                </th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="<?= max(3, count($colNames) + 2) ?>" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                <div>Tidak ada baris data yang cocok dengan kriteria pencarian / filter.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td><span style="color: var(--text-muted);">#<?= (int) $r['id'] ?></span></td>
                                <td style="font-size: 0.8rem; color: var(--text-secondary);">
                                    <?= e($r['record_date'] ?? '-') ?>
                                </td>
                                <?php if (empty($colNames)): ?>
                                    <td><code><?= e($r['data_json']) ?></code></td>
                                <?php else: ?>
                                    <?php foreach ($colNames as $col): ?>
                                        <td class="<?= is_numeric($r['data'][$col] ?? null) ? 'num' : '' ?>">
                                            <?php
                                            $val = $r['data'][$col] ?? null;
                                            if ($val === null || $val === '') {
                                                echo '<span style="color: var(--text-muted); font-style: italic;">null</span>';
                                            } elseif (is_bool($val)) {
                                                echo $val ? 'TRUE' : 'FALSE';
                                            } elseif (is_array($val)) {
                                                echo e(json_encode($val));
                                            } else {
                                                echo e((string) $val);
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if (!empty($pagination) && $pagination['total_pages'] > 1): ?>
            <?php
            // Build base pagination query string
            $qParams = $_GET;
            unset($qParams['page']);
            $baseQuery = http_build_query($qParams);
            $queryPrefix = !empty($baseQuery) ? '?' . $baseQuery . '&page=' : '?page=';
            ?>
            <div style="padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border-color); font-size: 0.85rem; flex-wrap: wrap; gap: 0.5rem;">
                <div style="color: var(--text-muted);">
                    Halaman <?= (int) $pagination['current_page'] ?> dari <?= (int) $pagination['total_pages'] ?> (Menampilkan <?= count($records) ?> dari <?= number_format($pagination['total_records']) ?> total records)
                </div>
                <div style="display: flex; gap: 0.4rem;">
                    <?php if ($pagination['current_page'] > 1): ?>
                        <a href="<?= $queryPrefix . ($pagination['current_page'] - 1) ?>" class="btn btn-outline btn-sm">&larr; Sebelumnya</a>
                    <?php endif; ?>
                    <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                        <a href="<?= $queryPrefix . ($pagination['current_page'] + 1) ?>" class="btn btn-outline btn-sm">Berikutnya &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
