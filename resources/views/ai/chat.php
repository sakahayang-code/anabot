<?php
/**
 * AnaBot AI Analyst Chat Interface View
 * 
 * @var array $sessions All user sessions
 * @var array|null $activeSession Current active session
 * @var array $messages Message history
 * @var array|null $factsPreview Grounded mathematical facts
 * @var array $availableDatasets All datasets in system
 * @var array $activeProviders Active AI providers
 */

$sessionId = $activeSession['id'] ?? 0;
$attachedDatasets = $activeSession['attached_datasets'] ?? [];
$attachedIds = array_map(fn($d) => (int) $d['id'], $attachedDatasets);
?>

<div class="ai-analyst-container">
    <!-- Top Action Bar -->
    <div class="page-header d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="page-title mb-1">
                <i class="fas fa-robot text-primary me-2"></i>AI Analyst
            </h1>
            <p class="text-muted mb-0">Asisten intelijen data personal dengan jaminan pembumian data matematis terverifikasi.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($activeSession): ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="openContextModal()">
                <i class="fas fa-database me-1 text-info"></i> Bukti Data Terverifikasi
            </button>
            <button class="btn btn-outline-danger btn-sm" onclick="clearCurrentChat()">
                <i class="fas fa-trash-alt me-1"></i> Bersihkan Obrolan
            </button>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm" onclick="openNewSessionModal()">
                <i class="fas fa-plus me-1"></i> Sesi Baru
            </button>
        </div>
    </div>

    <div class="row g-3">
        <!-- LEFT COLUMN: SESSIONS & DATASET SELECTOR -->
        <div class="col-lg-3 col-md-4">
            <!-- Sessions Panel -->
            <div class="card mb-3 shadow-sm border-0">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2">
                    <span class="fw-bold small text-uppercase tracking-wider">
                        <i class="fas fa-history me-1 text-muted"></i> Riwayat Sesi
                    </span>
                    <span class="badge bg-secondary rounded-pill"><?= count($sessions) ?></span>
                </div>
                <div class="list-group list-group-flush session-list-scroll" style="max-height: 380px; overflow-y: auto;">
                    <?php if (empty($sessions)): ?>
                        <div class="p-3 text-center text-muted small">
                            <i class="fas fa-comments fs-3 mb-2 d-block opacity-50"></i>
                            Belum ada sesi analisis.<br>Klik <strong>Sesi Baru</strong> untuk memulai.
                        </div>
                    <?php else: ?>
                        <?php foreach ($sessions as $s): ?>
                            <?php $isActive = ($s['id'] == $sessionId); ?>
                            <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-start <?= $isActive ? 'active' : '' ?> py-2 px-3">
                                <a href="/ai/chat/<?= $s['id'] ?>" class="text-decoration-none text-reset flex-grow-1 text-truncate pe-2">
                                    <div class="fw-semibold text-truncate small"><?= htmlspecialchars($s['title']) ?></div>
                                    <div class="text-xs text-muted d-flex align-items-center gap-2 mt-1">
                                        <span><i class="far fa-comment-dots me-1"></i><?= $s['message_count'] ?? 0 ?> pesan</span>
                                        <span>&bull;</span>
                                        <span><i class="fas fa-table me-1"></i><?= $s['dataset_count'] ?? 0 ?> data</span>
                                    </div>
                                </a>
                                <button class="btn btn-link btn-sm text-danger p-0 opacity-75 hover-opacity-100" 
                                        title="Hapus Sesi" 
                                        onclick="deleteSession(event, <?= $s['id'] ?>)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attached Datasets Selector -->
            <?php if ($activeSession): ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2">
                    <span class="fw-bold small text-uppercase tracking-wider">
                        <i class="fas fa-link me-1 text-muted"></i> Dataset Sesi Ini
                    </span>
                    <button class="btn btn-link btn-sm text-primary p-0" onclick="saveAttachedDatasets()">
                        Simpan
                    </button>
                </div>
                <div class="card-body p-2" style="max-height: 250px; overflow-y: auto;">
                    <?php if (empty($availableDatasets)): ?>
                        <div class="text-muted small text-center p-2">
                            Tidak ada dataset aktif.
                        </div>
                    <?php else: ?>
                        <form id="datasetSelectorForm">
                            <?php foreach ($availableDatasets as $ad): ?>
                                <?php $isAttached = in_array((int)$ad['id'], $attachedIds, true); ?>
                                <div class="form-check form-check-sm mb-2">
                                    <input class="form-check-input dataset-check" type="checkbox" 
                                           value="<?= $ad['id'] ?>" 
                                           id="ds_<?= $ad['id'] ?>" 
                                           <?= $isAttached ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-truncate d-block" for="ds_<?= $ad['id'] ?>" title="<?= htmlspecialchars($ad['name']) ?>">
                                        <?= htmlspecialchars($ad['name']) ?>
                                        <span class="text-xs text-muted d-block"><?= number_format((int)$ad['record_count']) ?> baris</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT / CENTER COLUMN: CHAT WORKSPACE -->
        <div class="col-lg-9 col-md-8">
            <?php if (!$activeSession): ?>
                <!-- No Session State -->
                <div class="card border-0 shadow-sm text-center py-5 px-3">
                    <div class="py-5">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-4 d-inline-block mb-3 text-primary">
                            <i class="fas fa-brain fs-1"></i>
                        </div>
                        <h3>Mulai Sesi Analisis Data Baru</h3>
                        <p class="text-muted col-lg-6 mx-auto mb-4">
                            AnaBot siap menganalisis angka statistik deskriptif, laju pertumbuhan, tren waktu, korelasi antar-variabel, dan prinsip Pareto 80/20 dari dataset Anda.
                        </p>
                        <button class="btn btn-primary px-4 py-2" onclick="openNewSessionModal()">
                            <i class="fas fa-plus me-1"></i> Buat Sesi Analisis Sekarang
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <!-- Active Chat Container -->
                <div class="card border-0 shadow-sm chat-card d-flex flex-column" style="height: calc(100vh - 190px); min-height: 600px;">
                    <!-- Chat Header -->
                    <div class="card-header bg-transparent py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2 text-truncate">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <i class="fas fa-comments me-1"></i> Sesi #<?= $activeSession['id'] ?>
                            </span>
                            <span class="fw-bold text-truncate" id="sessionTitleDisplay">
                                <?= htmlspecialchars($activeSession['title']) ?>
                            </span>
                            <button class="btn btn-link btn-sm text-muted p-0 ms-1" onclick="openEditSessionTitleModal()" title="Ubah Judul">
                                <i class="fas fa-pencil-alt text-xs"></i>
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($attachedDatasets)): ?>
                                <?php foreach ($attachedDatasets as $ad): ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle small d-none d-md-inline-block">
                                        <i class="fas fa-table me-1"></i><?= htmlspecialchars($ad['name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle small">
                                    <i class="fas fa-exclamation-triangle me-1"></i> Belum ada dataset tertaut
                                </span>
                            <?php endif; ?>
                            <span class="badge bg-dark-subtle text-secondary border small">
                                <i class="fas fa-shield-alt me-1 text-success"></i> Zero Hallucination
                            </span>
                        </div>
                    </div>

                    <!-- KPI Cards Bento Bar (if context available) -->
                    <?php if (!empty($factsPreview['summary_cards'])): ?>
                    <div class="kpi-quick-bar px-3 py-2 bg-light bg-opacity-50 border-bottom d-flex gap-2 overflow-x-auto">
                        <?php foreach ($factsPreview['summary_cards'] as $card): ?>
                            <div class="kpi-mini-pill border rounded px-2 py-1 bg-white shadow-2xs d-flex align-items-center gap-2 text-nowrap">
                                <span class="text-xs text-muted"><?= htmlspecialchars($card['title']) ?>:</span>
                                <span class="fw-bold text-xs"><?= htmlspecialchars($card['value']) ?></span>
                                <?php if (!empty($card['badge'])): ?>
                                    <span class="badge bg-<?= $card['type'] ?? 'secondary' ?> text-xs py-0"><?= htmlspecialchars($card['badge']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Message Stream Area -->
                    <div class="card-body p-3 overflow-y-auto flex-grow-1" id="chatMessageStream">
                        <?php if (empty($messages)): ?>
                            <!-- Welcome / Prompt Starter State -->
                            <div class="text-center py-4 my-auto">
                                <div class="avatar-lg rounded-circle bg-primary bg-opacity-10 text-primary mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
                                    <i class="fas fa-robot fs-2"></i>
                                </div>
                                <h5 class="fw-bold mb-1">Halo! Saya AnaBot, AI Analytics Robot Anda.</h5>
                                <p class="text-muted small col-lg-8 mx-auto mb-4">
                                    Saya telah membaca data faktual dari SQLite dan menghitung metrik statistik Anda secara deterministik. Apa yang ingin Anda ketahui dari data ini?
                                </p>
                                
                                <div class="row g-2 col-lg-10 mx-auto text-start">
                                    <div class="col-md-6">
                                        <button class="btn btn-outline-secondary w-100 text-start p-2 small h-100" onclick="sendQuickPrompt('Bagaimana perkembangan tren metrik utama dalam rentang periode data ini?')">
                                            <i class="fas fa-chart-line text-primary me-2"></i>
                                            <strong>Tren & Pertumbuhan</strong>
                                            <span class="d-block text-xs text-muted mt-1">Evaluasi slope OLS, arah pergerakan, dan CAGR.</span>
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <button class="btn btn-outline-secondary w-100 text-start p-2 small h-100" onclick="sendQuickPrompt('Kategori atau segmen mana yang menjadi kontributor terbesar berdasarkan prinsip Pareto 80/20?')">
                                            <i class="fas fa-pie-chart text-success me-2"></i>
                                            <strong>Analisis Pareto 80/20</strong>
                                            <span class="d-block text-xs text-muted mt-1">Identifikasi 20% segmen yang menghasilkan 80% volume.</span>
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <button class="btn btn-outline-secondary w-100 text-start p-2 small h-100" onclick="sendQuickPrompt('Apakah terdapat korelasi atau hubungan kuat antar-metrik dalam dataset ini?')">
                                            <i class="fas fa-project-diagram text-info me-2"></i>
                                            <strong>Korelasi Pearson</strong>
                                            <span class="d-block text-xs text-muted mt-1">Cari tahu variabel mana yang saling mempengaruhi.</span>
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <button class="btn btn-outline-secondary w-100 text-start p-2 small h-100" onclick="sendQuickPrompt('Apa rekomendasi aksi strategis dan taktis terbaik yang dapat saya lakukan berdasarkan data aktual ini?')">
                                            <i class="fas fa-lightbulb text-warning me-2"></i>
                                            <strong>Rekomendasi Aksi</strong>
                                            <span class="d-block text-xs text-muted mt-1">Langkah perbaikan berbasis data nyata terukur.</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <?php $isUser = ($msg['sender_type'] === 'user'); ?>
                                <div class="chat-message d-flex <?= $isUser ? 'justify-content-end' : 'justify-content-start' ?> mb-3">
                                    <?php if (!$isUser): ?>
                                        <div class="avatar flex-shrink-0 me-2">
                                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                                                <i class="fas fa-robot text-xs"></i>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="message-bubble <?= $isUser ? 'user-bubble bg-primary text-white' : 'assistant-bubble bg-white border text-dark' ?> rounded-3 p-3 shadow-2xs" style="max-width: 82%;">
                                        <div class="message-content small markdown-body">
                                            <?= nl2br(htmlspecialchars($msg['message_content'])) ?>
                                        </div>
                                        <div class="message-meta d-flex justify-content-between align-items-center mt-2 pt-1 border-top border-opacity-10 text-xs <?= $isUser ? 'text-white-50' : 'text-muted' ?>">
                                            <span><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                                            <?php if (!$isUser && ($msg['tokens_input'] > 0 || $msg['tokens_output'] > 0)): ?>
                                                <span><i class="fas fa-bolt text-warning me-1"></i><?= $msg['tokens_input'] + $msg['tokens_output'] ?> tokens</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($isUser): ?>
                                        <div class="avatar flex-shrink-0 ms-2">
                                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                                                <i class="fas fa-user text-xs"></i>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Typing / Waiting Indicator -->
                        <div id="aiTypingIndicator" class="chat-message d-flex justify-content-start mb-3 d-none">
                            <div class="avatar flex-shrink-0 me-2">
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                                    <i class="fas fa-robot text-xs"></i>
                                </div>
                            </div>
                            <div class="message-bubble bg-white border text-muted rounded-3 p-3 shadow-2xs d-flex align-items-center gap-2">
                                <div class="spinner-grow spinner-grow-sm text-primary" role="status"></div>
                                <span class="small">AnaBot sedang menganalisis data matematis...</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Action Chips Bar -->
                    <div class="quick-actions-bar px-3 py-2 border-top bg-light bg-opacity-25 d-flex gap-2 overflow-x-auto">
                        <button class="btn btn-outline-secondary btn-xs text-nowrap rounded-pill" onclick="executeQuickAction('executive_summary')">
                            <i class="fas fa-file-alt text-primary me-1"></i> Ringkasan Eksekutif
                        </button>
                        <button class="btn btn-outline-secondary btn-xs text-nowrap rounded-pill" onclick="executeQuickAction('root_cause_diagnosis')">
                            <i class="fas fa-stethoscope text-danger me-1"></i> Diagnosis Fluktuasi
                        </button>
                        <button class="btn btn-outline-secondary btn-xs text-nowrap rounded-pill" onclick="executeQuickAction('strategic_recommendations')">
                            <i class="fas fa-lightbulb text-warning me-1"></i> Rekomendasi Aksi
                        </button>
                        <button class="btn btn-outline-secondary btn-xs text-nowrap rounded-pill" onclick="executeQuickAction('anomaly_detection')">
                            <i class="fas fa-exclamation-triangle text-info me-1"></i> Deteksi Anomali
                        </button>
                    </div>

                    <!-- Input Box Area -->
                    <div class="card-footer bg-transparent p-3 border-top">
                        <form id="chatForm" onsubmit="handleChatSubmit(event)">
                            <div class="input-group">
                                <textarea id="chatInput" 
                                          class="form-control" 
                                          rows="1" 
                                          placeholder="Tanyakan analisis data Anda... (Tekan Enter untuk mengirim, Shift+Enter untuk baris baru)" 
                                          required></textarea>
                                <button type="submit" class="btn btn-primary px-3" id="sendBtn">
                                    <i class="fas fa-paper-plane me-1"></i> Kirim
                                </button>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 px-1">
                                <span class="text-xs text-muted">
                                    <i class="fas fa-lock text-success me-1"></i> Grounded by SQLite &amp; AnalyticsEngine PHP (Zero Hallucination)
                                </span>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="text-xs text-muted">Model:</span>
                                    <select class="form-select form-select-xs py-0 px-2" id="modelSelector" style="width: auto; font-size: 0.75rem;">
                                        <option value="">Otomatis (Default)</option>
                                        <?php foreach ($activeProviders as $p): ?>
                                            <option value="<?= $p['provider_code'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL: DATA CONTEXT GROUNDING INSPECTOR -->
<div class="modal fade" id="contextModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-database text-info me-2"></i>Bukti Data Terverifikasi (PHP Analytics Engine)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fas fa-info-circle me-1"></i> Seluruh angka, persentase pertumbuhan, korelasi Pearson, dan analisis Pareto di bawah ini dihitung langsung oleh PHP Analytics Engine sebelum dikirimkan ke model AI.
                </div>
                <div class="bg-light p-3 rounded border font-monospace small" style="white-space: pre-wrap;" id="contextFactsContent">
                    <?= !empty($factsPreview['markdown_facts']) ? htmlspecialchars($factsPreview['markdown_facts']) : 'Belum ada data kontekstual yang dimuat.' ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: NEW SESSION -->
<div class="modal fade" id="newSessionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="/ai/new" method="POST" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle text-primary me-2"></i>Buat Sesi Analisis Baru
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Judul Sesi</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Analisis Kinerja Penjualan Q4" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Pilih Dataset yang Ingin Dianalisis</label>
                    <?php if (empty($availableDatasets)): ?>
                        <div class="alert alert-warning py-2 small">
                            Belum ada dataset. Silakan impor data pada menu Connectors terlebih dahulu.
                        </div>
                    <?php else: ?>
                        <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($availableDatasets as $ad): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="dataset_ids[]" value="<?= $ad['id'] ?>" id="new_ds_<?= $ad['id'] ?>">
                                    <label class="form-check-label small" for="new_ds_<?= $ad['id'] ?>">
                                        <?= htmlspecialchars($ad['name']) ?> 
                                        <span class="text-muted text-xs">(<?= number_format((int)$ad['record_count']) ?> baris)</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm">Mulai Sesi</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: EDIT SESSION TITLE -->
<div class="modal fade" id="editTitleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Ubah Judul Sesi</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2">
                <input type="text" id="editSessionTitleInput" class="form-control form-control-sm" value="<?= htmlspecialchars($activeSession['title'] ?? '') ?>">
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-xs" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary btn-xs" onclick="saveSessionTitle()">Simpan</button>
            </div>
        </div>
    </div>
</div>

<script>
const currentSessionId = <?= (int) $sessionId ?>;

function scrollToBottom() {
    const el = document.getElementById('chatMessageStream');
    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}
window.addEventListener('load', scrollToBottom);

// Handle Chat Submission
async function handleChatSubmit(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    const query = input.value.trim();
    if (!query || !currentSessionId) return;

    // Append user message immediately to UI
    appendMessageToUI('user', query);
    input.value = '';
    scrollToBottom();

    // Show typing indicator
    const indicator = document.getElementById('aiTypingIndicator');
    if (indicator) indicator.classList.remove('d-none');
    scrollToBottom();

    const sendBtn = document.getElementById('sendBtn');
    if (sendBtn) sendBtn.disabled = true;

    try {
        const response = await fetch(`/api/v1/ai/sessions/${currentSessionId}/messages`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ query: query })
        });

        const data = await response.json();
        if (indicator) indicator.classList.add('d-none');
        if (sendBtn) sendBtn.disabled = false;

        if (data.success && data.data) {
            appendMessageToUI('assistant', data.data.response, data.data.tokens_input + data.data.tokens_output);
        } else {
            appendMessageToUI('assistant', '⚠️ Gagal mendapatkan respons: ' + (data.error || 'Terjadi kesalahan sistem.'));
        }
        scrollToBottom();
    } catch (err) {
        if (indicator) indicator.classList.add('d-none');
        if (sendBtn) sendBtn.disabled = false;
        appendMessageToUI('assistant', '⚠️ Kendala koneksi ke server: ' + err.message);
        scrollToBottom();
    }
}

// Quick action runner
async function executeQuickAction(actionType) {
    if (!currentSessionId) return;
    const indicator = document.getElementById('aiTypingIndicator');
    if (indicator) indicator.classList.remove('d-none');
    scrollToBottom();

    try {
        const response = await fetch(`/api/v1/ai/sessions/${currentSessionId}/quick-action`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ action: actionType })
        });

        const data = await response.json();
        if (indicator) indicator.classList.add('d-none');

        if (data.success && data.data) {
            appendMessageToUI('user', '⚡ Analisis Cepat: ' + data.data.action_title);
            appendMessageToUI('assistant', data.data.response, data.data.tokens_input + data.data.tokens_output);
        } else {
            appendMessageToUI('assistant', '⚠️ ' + (data.error || 'Gagal menjalankan analisis cepat.'));
        }
        scrollToBottom();
    } catch (err) {
        if (indicator) indicator.classList.add('d-none');
        appendMessageToUI('assistant', '⚠️ Kendala koneksi: ' + err.message);
        scrollToBottom();
    }
}

function sendQuickPrompt(promptText) {
    const input = document.getElementById('chatInput');
    if (input) {
        input.value = promptText;
        document.getElementById('chatForm').dispatchEvent(new Event('submit'));
    }
}

function appendMessageToUI(sender, text, tokens = 0) {
    const stream = document.getElementById('chatMessageStream');
    if (!stream) return;

    const isUser = (sender === 'user');
    const bubbleClass = isUser ? 'user-bubble bg-primary text-white' : 'assistant-bubble bg-white border text-dark';
    const alignClass = isUser ? 'justify-content-end' : 'justify-content-start';
    const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    let avatarHtml = '';
    if (!isUser) {
        avatarHtml = `
            <div class="avatar flex-shrink-0 me-2">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                    <i class="fas fa-robot text-xs"></i>
                </div>
            </div>`;
    }

    let userAvatarHtml = '';
    if (isUser) {
        userAvatarHtml = `
            <div class="avatar flex-shrink-0 ms-2">
                <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                    <i class="fas fa-user text-xs"></i>
                </div>
            </div>`;
    }

    let tokenHtml = '';
    if (!isUser && tokens > 0) {
        tokenHtml = `<span><i class="fas fa-bolt text-warning me-1"></i>${tokens} tokens</span>`;
    }

    const escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');

    const item = document.createElement('div');
    item.className = `chat-message d-flex ${alignClass} mb-3`;
    item.innerHTML = `
        ${avatarHtml}
        <div class="message-bubble ${bubbleClass} rounded-3 p-3 shadow-2xs" style="max-width: 82%;">
            <div class="message-content small">${escaped}</div>
            <div class="message-meta d-flex justify-content-between align-items-center mt-2 pt-1 border-top border-opacity-10 text-xs ${isUser ? 'text-white-50' : 'text-muted'}">
                <span>${now}</span>
                ${tokenHtml}
            </div>
        </div>
        ${userAvatarHtml}
    `;

    // Insert before typing indicator
    const indicator = document.getElementById('aiTypingIndicator');
    if (indicator) {
        stream.insertBefore(item, indicator);
    } else {
        stream.appendChild(item);
    }
}

// Auto-expand textarea & Enter to submit
document.getElementById('chatInput')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('chatForm').dispatchEvent(new Event('submit'));
    }
});

// Modals & UI helpers
function openNewSessionModal() {
    new bootstrap.Modal(document.getElementById('newSessionModal')).show();
}

function openContextModal() {
    new bootstrap.Modal(document.getElementById('contextModal')).show();
}

function openEditSessionTitleModal() {
    new bootstrap.Modal(document.getElementById('editTitleModal')).show();
}

async function saveSessionTitle() {
    const input = document.getElementById('editSessionTitleInput');
    const newTitle = input.value.trim();
    if (!newTitle || !currentSessionId) return;

    try {
        const res = await fetch(`/api/v1/ai/sessions/${currentSessionId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: newTitle })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('sessionTitleDisplay').innerText = newTitle;
            bootstrap.Modal.getInstance(document.getElementById('editTitleModal')).hide();
        }
    } catch (err) {
        alert('Gagal mengubah judul sesi: ' + err.message);
    }
}

async function saveAttachedDatasets() {
    if (!currentSessionId) return;
    const checks = document.querySelectorAll('.dataset-check:checked');
    const ids = Array.from(checks).map(c => parseInt(c.value));

    try {
        const res = await fetch(`/api/v1/ai/sessions/${currentSessionId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dataset_ids: ids })
        });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        }
    } catch (err) {
        alert('Gagal memperbarui dataset sesi: ' + err.message);
    }
}

async function clearCurrentChat() {
    if (!currentSessionId) return;
    if (!confirm('Apakah Anda yakin ingin membersihkan riwayat obrolan dalam sesi ini?')) return;

    try {
        const res = await fetch(`/api/v1/ai/sessions/${currentSessionId}/clear`, { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        }
    } catch (err) {
        alert('Gagal membersihkan riwayat pesan: ' + err.message);
    }
}

async function deleteSession(e, sid) {
    e.stopPropagation();
    if (!confirm('Hapus sesi analisis ini beserta seluruh riwayat obrolannya?')) return;

    try {
        const res = await fetch(`/api/v1/ai/sessions/${sid}`, { method: 'DELETE' });
        const data = await res.json();
        if (data.success) {
            window.location.href = '/ai/chat';
        }
    } catch (err) {
        alert('Gagal menghapus sesi: ' + err.message);
    }
}
</script>
