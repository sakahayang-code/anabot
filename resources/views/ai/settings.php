<?php
/**
 * Views: AI Provider Settings & Budgeting Dashboard
 * 
 * Variables:
 * - $providers (array)
 * - $monthlyUsage (array)
 * - $recentLogs (array)
 */
?>

<!-- Header -->
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0; color: var(--text-primary);">Pengaturan Multi-Provider AI</h1>
            <span class="badge badge-primary">MULTI-PROVIDER AI</span>
            <span class="badge badge-success">AES-256-GCM</span>
        </div>
        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">
            Kelola kunci API terenkripsi, model aktif, kuota token bulanan, dan failover otomatis antar-penyedia AI.
        </p>
    </div>
</div>

<!-- Bento KPI Cards (Monthly Token & Cost Overview) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <!-- Card 1: Total Requests -->
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Total Request AI (Bulan Ini)</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
            <?= number_format((int)($monthlyUsage['total_requests'] ?? 0)) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">Panggilan API sukses</div>
    </div>

    <!-- Card 2: Total Tokens -->
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Konsumsi Token (Bulan Ini)</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: var(--brand-primary); margin-bottom: 0.25rem;">
            <?= number_format((int)($monthlyUsage['total_tokens'] ?? 0)) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">Input + Output tokens</div>
    </div>

    <!-- Card 3: Estimated Cost -->
    <?php 
    $costUsd = round(((float)($monthlyUsage['total_cost_cents'] ?? 0)) / 100.0, 4);
    ?>
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Estimasi Biaya API (USD)</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: #10b981; margin-bottom: 0.25rem;">
            $<?= number_format($costUsd, 4) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">Biaya riil penyedia AI</div>
    </div>

    <!-- Card 4: Configured Providers -->
    <?php 
    $configuredCount = count(array_filter($providers, fn($p) => !empty($p['api_key_encrypted']) || $p['provider_code'] === 'compatible'));
    ?>
    <div class="chart-card" style="padding: 1.25rem;">
        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.25rem;">Provider Terkonfigurasi</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
            <?= $configuredCount ?> / <?= count($providers) ?>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-secondary);">Mendukung failover otomatis</div>
    </div>
</div>

<!-- Notice Banner: Anti-Hallucination Principle -->
<div class="notice-banner" style="border-left: 4px solid var(--brand-primary); margin-bottom: 1.5rem;">
    <div class="notice-icon">🛡️</div>
    <div>
        <div style="font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Prinsip Anti-Halusinasi (Section 2.3 & Aturan 1.14)</div>
        <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5;">
            Mesin analitik PHP menghitung seluruh angka statistik, korelasi, dan persentase secara deterministik. Mesin AI bertugas menjelaskan makna data dan merumuskan rekomendasi aksi. Seluruh prompt sistem dilindungi oleh guardrail ketat untuk mencegah manipulasi angka.
        </div>
    </div>
</div>

<!-- Provider Cards Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <?php foreach ($providers as $p): ?>
        <?php 
        $hasKey = !empty($p['api_key_encrypted']);
        $models = \AnaBot\Models\AIModel::getByProvider((int)$p['id']);
        ?>
        <div class="chart-card" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <!-- Provider Header -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                    <div>
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.25rem 0;">
                            <?= e($p['name']) ?>
                        </h3>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">Code: <code><?= e($p['provider_code']) ?></code></span>
                    </div>
                    <div style="display: flex; gap: 0.3rem; flex-wrap: wrap; justify-content: flex-end;">
                        <?php if (!empty($p['is_primary'])): ?>
                            <span class="badge badge-success" title="Provider Utama">PRIMARY</span>
                        <?php endif; ?>
                        <?php if (!empty($p['is_fallback'])): ?>
                            <span class="badge badge-info" title="Penyedia Cadangan Otomatis">FALLBACK</span>
                        <?php endif; ?>
                        <?php if (!empty($p['is_active'])): ?>
                            <span class="badge badge-primary">ACTIVE</span>
                        <?php else: ?>
                            <span class="badge badge-demo">INACTIVE</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status Key -->
                <div style="margin-bottom: 1rem; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span>Status API Key:</span>
                    <?php if ($hasKey): ?>
                        <span style="color: #10b981; font-weight: 600;">✓ Tersimpan Aman (AES-256-GCM)</span>
                    <?php else: ?>
                        <span style="color: var(--text-muted);">Belum dikonfigurasi</span>
                    <?php endif; ?>
                </div>

                <!-- Update Form -->
                <form id="form-<?= $p['id'] ?>" onsubmit="saveProvider(event, <?= $p['id'] ?>)">
                    <div style="margin-bottom: 0.75rem;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">API Key <?= $hasKey ? '(Ketik untuk mengganti)' : '' ?></label>
                        <input type="password" name="api_key" placeholder="<?= $hasKey ? '••••••••••••••••••••' : 'Masukkan API Key resmi...' ?>" class="form-control" style="font-size: 0.8rem; width: 100%;">
                    </div>

                    <?php if ($p['provider_code'] === 'compatible'): ?>
                        <div style="margin-bottom: 0.75rem;">
                            <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">API Endpoint</label>
                            <input type="text" name="api_endpoint" value="<?= e($p['api_endpoint'] ?? '') ?>" placeholder="https://api.groq.com/openai/v1" class="form-control" style="font-size: 0.8rem; width: 100%;">
                        </div>
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.75rem;">
                        <div>
                            <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Model Default</label>
                            <select name="default_model" class="form-control" style="font-size: 0.8rem; width: 100%;">
                                <?php foreach ($models as $m): ?>
                                    <option value="<?= e($m['model_name']) ?>" <?= !empty($m['is_default']) ? 'selected' : '' ?>>
                                        <?= e($m['model_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem;">Batas Token Bulanan</label>
                            <input type="number" name="monthly_token_budget" value="<?= (int)($p['monthly_token_budget'] ?? 0) ?>" placeholder="0 = Tanpa batas" class="form-control" style="font-size: 0.8rem; width: 100%;">
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-bottom: 1rem; font-size: 0.75rem;">
                        <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" <?= !empty($p['is_active']) ? 'checked' : '' ?>> Aktif
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                            <input type="radio" name="provider_role" value="primary" <?= !empty($p['is_primary']) ? 'checked' : '' ?>> Jadikan Utama
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                            <input type="radio" name="provider_role" value="fallback" <?= !empty($p['is_fallback']) ? 'checked' : '' ?>> Jadikan Cadangan
                        </label>
                    </div>

                    <div style="display: flex; gap: 0.5rem; justify-content: space-between; align-items: center;">
                        <button type="submit" class="btn btn-primary btn-sm" style="flex: 1;">Simpan Pengaturan</button>
                        <button type="button" onclick="testProvider('<?= e($p['provider_code']) ?>')" class="btn btn-outline btn-sm">
                            🔌 Uji Koneksi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Audit Log Table: AI Usage & Performance -->
<div class="chart-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h3 class="card-title" style="margin: 0;">Log Audit Panggilan API & Penggunaan Token</h3>
        <span style="font-size: 0.8rem; color: var(--text-muted);">Riwayat 10 transaksi terakhir</span>
    </div>

    <div class="table-container" style="max-height: 350px; overflow-y: auto;">
        <table class="table" style="font-size: 0.8rem;">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Provider</th>
                    <th style="text-align: right;">Prompt Tokens</th>
                    <th style="text-align: right;">Completion Tokens</th>
                    <th style="text-align: right;">Total Tokens</th>
                    <th style="text-align: right;">Estimasi Biaya</th>
                    <th style="text-align: right;">Latency</th>
                    <th style="text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            Belum ada riwayat panggilan AI yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                            <td><strong><?= e($log['provider_name']) ?></strong></td>
                            <td style="text-align: right;"><?= number_format($log['prompt_tokens']) ?></td>
                            <td style="text-align: right;"><?= number_format($log['completion_tokens']) ?></td>
                            <td style="text-align: right; font-weight: 600;"><?= number_format($log['total_tokens']) ?></td>
                            <td style="text-align: right; color: var(--text-muted);">
                                $<?= number_format($log['estimated_cost_cents'] / 100.0, 4) ?>
                            </td>
                            <td style="text-align: right;"><?= $log['execution_time_ms'] ?> ms</td>
                            <td style="text-align: center;">
                                <?php if ($log['status'] === 'success'): ?>
                                    <span class="badge badge-success">SUCCESS</span>
                                <?php else: ?>
                                    <span class="badge badge-danger" title="<?= e($log['error_message'] ?? '') ?>">FAILED</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function saveProvider(event, id) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    const payload = {
        api_key: formData.get('api_key'),
        api_endpoint: formData.get('api_endpoint') || undefined,
        default_model: formData.get('default_model'),
        monthly_token_budget: parseInt(formData.get('monthly_token_budget') || '0', 10),
        is_active: formData.get('is_active') === '1',
        is_primary: formData.get('provider_role') === 'primary',
        is_fallback: formData.get('provider_role') === 'fallback',
    };

    try {
        const res = await fetch(`/api/v1/ai/providers/${id}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert('Pengaturan provider berhasil disimpan!');
            window.location.reload();
        } else {
            alert('Gagal: ' + (json.error || 'Terjadi kesalahan'));
        }
    } catch (e) {
        alert('Kesalahan jaringan: ' + e.message);
    }
}

async function testProvider(code) {
    const btn = event.target;
    const origText = btn.innerText;
    btn.innerText = 'Menghubungkan...';
    btn.disabled = true;

    try {
        const res = await fetch(`/api/v1/ai/test/${code}`, { method: 'POST' });
        const json = await res.json();
        if (json.success) {
            alert(`Uji Koneksi Berhasil!\nLatency: ${json.latency_ms} ms\nBalasan: ${json.reply}`);
        } else {
            alert(`Uji Koneksi Gagal:\n${json.error}`);
        }
    } catch (e) {
        alert('Kesalahan jaringan: ' + e.message);
    } finally {
        btn.innerText = origText;
        btn.disabled = false;
    }
}
</script>
