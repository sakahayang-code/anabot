<?php
declare(strict_types=1);

namespace AnaBot\AI;

/**
 * AnaBot AI Prompt Manager & Anti-Hallucination Guardrail
 * 
 * Enforces strict separation of concerns (Section 2.3 & Rule 1.14):
 * - PHP Analytics Engine computes verified numbers and statistics.
 * - AI explains insights, diagnoses causes, and recommends actions.
 * - AI is strictly forbidden from creating unverified numbers.
 */
class PromptManager
{
    /**
     * Build base system guardrail instruction for AnaBot AI.
     */
    public static function buildSystemInstruction(): string
    {
        return <<<PROMPT
Anda adalah AnaBot (AI Analytics Robot), asisten data intelligence profesional. Anda bertindak sebagai Senior Data Analyst & Business Intelligence Consultant.

ATURAN WAJIB (STRICT GUARDRAILS):
1. KEAKURATAN DATA: Anda HANYA boleh mengutip angka, persentase, statistik, korelasi, dan metrik yang telah dihitung secara matematis oleh Mesin Analitik PHP AnaBot yang disediakan di bawah.
2. LARANGAN HALUSINASI: DILARANG mengarang, memperkirakan, atau menciptakan angka baru yang tidak bersumber dari data komputasi terverifikasi. Jika data tidak tersedia, nyatakan secara eksplisit bahwa data belum tersedia.
3. FOKUS ANALISIS: Tugas utama Anda adalah menjelaskan makna di balik angka, menafsirkan tren bisnis, menganalisis faktor penyebab potensial, mendeteksi anomali, dan memberikan rekomendasi aksi konkret yang dapat dieksekusi.
4. GAYA KOMUNIKASI: Gunakan Bahasa Indonesia profesional, objektif, berbasis bukti data aktual, ringkas, dan terstruktur dengan rapi menggunakan format Markdown.
PROMPT;
    }

    /**
     * Build messages array for analyzing deterministic analytics data.
     *
     * @param array $analyticsData Output from AnalyticsEngine
     * @param string $userQuery Optional specific question from user
     * @param array $context Additional context (e.g. dataset name, notes)
     * @return array Standard messages array for AI providers
     */
    public static function buildAnalyticsPrompt(array $analyticsData, string $userQuery = '', array $context = []): array
    {
        $system = self::buildSystemInstruction();

        // 1. Format deterministic metrics into Markdown factual data table
        $dataSummary = "### FAKTA DATA TERVERIFIKASI (HASIL ANALYTICS ENGINE PHP):\n";

        if (!empty($context['dataset_name'])) {
            $dataSummary .= "- **Dataset:** " . $context['dataset_name'] . "\n";
        }

        // Descriptive stats
        if (!empty($analyticsData['descriptive_stats'])) {
            $dataSummary .= "\n#### 1. Statistik Deskriptif Metrik:\n";
            $dataSummary .= "| Metrik | Observasi | Total | Rata-rata | Median | Modus | Min | Max | Std Dev |\n";
            $dataSummary .= "| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |\n";

            foreach ($analyticsData['descriptive_stats'] as $metric => $stats) {
                $dataSummary .= sprintf(
                    "| %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                    $metric,
                    number_format($stats['count'] ?? 0),
                    number_format($stats['sum'] ?? 0, 2),
                    number_format($stats['mean'] ?? 0, 2),
                    number_format($stats['median'] ?? 0, 2),
                    number_format($stats['mode'] ?? 0, 2),
                    number_format($stats['min'] ?? 0, 2),
                    number_format($stats['max'] ?? 0, 2),
                    number_format($stats['std_dev'] ?? 0, 2)
                );
            }
        }

        // Time series & growth
        if (!empty($analyticsData['time_series']['growth_summary'])) {
            $growth = $analyticsData['time_series']['growth_summary'];
            $trend = $analyticsData['time_series']['trend'] ?? [];
            $metricName = $analyticsData['time_series']['metric'] ?? 'Metrik';

            $dataSummary .= "\n#### 2. Pertumbuhan & Tren Waktu ({$metricName}):\n";
            $dataSummary .= "- **Periode Terhitung:** " . ($growth['total_periods'] ?? 0) . " periode\n";
            $dataSummary .= "- **Nilai Awal:** " . number_format($growth['first_value'] ?? 0, 2) . " | **Nilai Akhir:** " . number_format($growth['latest_value'] ?? 0, 2) . "\n";
            $dataSummary .= "- **Pertumbuhan Keseluruhan:** " . ($growth['overall_growth_pct'] ?? 0) . "%\n";
            $dataSummary .= "- **Rata-rata Pertumbuhan per Periode:** " . ($growth['average_growth_pct'] ?? 0) . "%\n";
            if (!empty($growth['compound_growth_rate'])) {
                $dataSummary .= "- **Laju Pertumbuhan Majemuk (CAGR/CMGR):** " . ($growth['compound_growth_rate']) . "%\n";
            }
            $dataSummary .= "- **Klasifikasi Tren OLS:** " . strtoupper($trend['direction'] ?? 'FLAT') . " (" . ($trend['description'] ?? '') . ")\n";
        }

        // Correlation
        if (!empty($analyticsData['correlation']['top_pairs'])) {
            $dataSummary .= "\n#### 3. Korelasi Antar-Variabel (Pearson r):\n";
            foreach ($analyticsData['correlation']['top_pairs'] as $pair) {
                $dataSummary .= sprintf(
                    "- **%s** dengan **%s**: r = %s (%s)\n",
                    $pair['var_a'],
                    $pair['var_b'],
                    number_format($pair['r'], 3),
                    $pair['strength']
                );
            }
        }

        // Pareto Breakdown
        if (!empty($analyticsData['breakdowns'][0]['items'])) {
            $bd = $analyticsData['breakdowns'][0];
            $dataSummary .= "\n#### 4. Kontribusi Kategori & Pareto 80/20 ({$bd['dimension']} vs {$bd['metric']}):\n";
            $dataSummary .= "| Peringkat | Kategori | Total | Porsi % | Akumulasi % | Status Pareto |\n";
            $dataSummary .= "| :---: | :--- | :---: | :---: | :---: | :---: |\n";

            foreach (array_slice($bd['items'], 0, 5) as $item) {
                $dataSummary .= sprintf(
                    "| %d | %s | %s | %s%% | %s%% | %s |\n",
                    $item['rank'],
                    $item['category'],
                    number_format($item['total'], 2),
                    $item['percentage'],
                    $item['cumulative_percentage'],
                    $item['is_pareto_80'] ? 'Top 80%' : 'Long Tail'
                );
            }
        }

        // Build User Prompt
        $userPrompt = $dataSummary . "\n---\n";
        if (!empty($userQuery)) {
            $userPrompt .= "### PERTANYAAN PENGGUNA:\n" . $userQuery . "\n\n";
            $userPrompt .= "Berikan analisis mendalam untuk menjawab pertanyaan di atas dengan mengacu pada fakta data terverifikasi di atas.";
        } else {
            $userPrompt .= "### TUGAS ANALISIS:\n";
            $userPrompt .= "1. Ringkas performa utama secara holistik.\n";
            $userPrompt .= "2. Jelaskan faktor penggerak utama pertumbuhan atau penurunan.\n";
            $userPrompt .= "3. Evaluasi hubungan korelasi yang signifikan.\n";
            $userPrompt .= "4. Berikan 3 rekomendasi aksi strategis berbasis data aktual.";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Build prompt for explaining a single metric change.
     */
    public static function buildExplanationPrompt(
        string $metric,
        float|int $change,
        string $direction,
        array $context = []
    ): array {
        $system = self::buildSystemInstruction();

        $userPrompt = "Metrik **{$metric}** mengalami pergerakan {$direction} sebesar {$change}%.\n";
        if (!empty($context['details'])) {
            $userPrompt .= "Konteks tambahan: " . json_encode($context['details'], JSON_UNESCAPED_SLASHES) . "\n";
        }
        $userPrompt .= "\nJelaskan kemungkinan penyebab perubahan ini dan implikasinya terhadap performa keseluruhan.";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Build multi-turn conversational prompt with verified context grounding.
     *
     * @param string $factualMarkdown Formatted facts from AIContextBuilder
     * @param array $conversationHistory Prior messages [{'role' => 'user'|'assistant', 'content' => ...}]
     * @param string $userQuery Current user query
     * @param array $options Optional settings
     * @return array Standard messages array for AI provider
     */
    public static function buildMultiTurnPrompt(
        string $factualMarkdown,
        array $conversationHistory,
        string $userQuery,
        array $options = []
    ): array {
        $system = self::buildSystemInstruction();

        // System message includes both core guardrails and the grounded factual data
        $systemWithFacts = $system . "\n\n" . $factualMarkdown;

        $messages = [
            ['role' => 'system', 'content' => $systemWithFacts],
        ];

        // Append recent conversation history
        foreach ($conversationHistory as $turn) {
            if (!empty($turn['role']) && !empty($turn['content'])) {
                $messages[] = [
                    'role' => $turn['role'] === 'user' ? 'user' : 'assistant',
                    'content' => (string) $turn['content'],
                ];
            }
        }

        // Append current user query
        $messages[] = [
            'role' => 'user',
            'content' => $userQuery,
        ];

        return $messages;
    }

    /**
     * Build specialized quick-action prompts (Executive Summary, Diagnosis, Recommendations, Anomaly).
     */
    public static function buildQuickActionPrompt(
        string $actionType,
        string $factualMarkdown,
        array $options = []
    ): array {
        $system = self::buildSystemInstruction() . "\n\n" . $factualMarkdown;

        switch ($actionType) {
            case 'executive_summary':
                $userPrompt = <<<QUERY
Mohon buatkan **Ringkasan Eksekutif (Executive Summary)** menyeluruh berdasarkan data faktual terverifikasi di atas:
1. **Highlight Utama:** Rangkum performa kunci (total volume, rata-rata, arah tren OLS, dan laju pertumbuhan).
2. **Kategori Penentu:** Jelaskan kontributor terbesar berdasarkan prinsip Pareto 80/20.
3. **Hubungan Antar-Metrik:** Paparkan korelasi Pearson terkuat yang ditemukan.
4. **Peluang & Risiko Utama:** Identifikasi potensi pertumbuhan dan risiko penurunan yang tampak dari fluktuasi data.

Gunakan format Markdown profesional dengan bullet points dan heading yang jelas.
QUERY;
                break;

            case 'root_cause_diagnosis':
                $userPrompt = <<<QUERY
Lakukan **Diagnosis Akar Masalah & Pola Kinerja (Root Cause Analysis)** mendalam:
1. Analisis periode fluktuasi tajam (penurunan tercuram atau lonjakan tertinggi) berdasarkan data sorotan.
2. Evaluasi hubungan sebab-akibat potensial menggunakan matriks korelasi Pearson (variabel mana yang saling menguatkan atau melemahkan).
3. Telaah apakah penurunan terkonsentrasi pada dimensi kategori tertentu atau menyebar ke seluruh segmen.
4. Simpulkan faktor pemicu dominan yang paling menjelaskan performa saat ini.
QUERY;
                break;

            case 'strategic_recommendations':
                $userPrompt = <<<QUERY
Berdasarkan bukti data matematis aktual di atas, rumuskan **3 hingga 5 Rekomendasi Aksi Strategis & Taktis**:
- Setiap rekomendasi HARUS merujuk langsung ke angka data terverifikasi (misalnya merujuk ke kategori Pareto 80%, laju pertumbuhan, atau koefisien korelasi).
- Tentukan tingkat prioritas (Tinggi / Sedang / Rendah).
- Jelaskan dampak bisnis yang diharapkan dan indikator keberhasilan (KPI) yang perlu dipantau.
QUERY;
                break;

            case 'anomaly_detection':
                $userPrompt = <<<QUERY
Lakukan **Investigasi Anomali & Fluktuasi Ekstrem**:
1. Sorot titik data tertinggi (peak) dan terendah (valley) serta periode terjadinya.
2. Analisis lonjakan persentase perubahan terbesar (+%) dan penurunan tercuram (-%).
3. Evaluasi apakah fluktuasi tersebut merupakan anomali sporadis atau bagian dari perubahan tren jangka panjang.
4. Berikan panduan pemantauan data ke depan.
QUERY;
                break;

            default:
                $userPrompt = "Berikan interpretasi komprehensif dan rekomendasi berbasis fakta data di atas.";
                break;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }
}

