<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Response;

/**
 * AnaBot Module Roadmap & Status Controller
 * 
 * Provides honest, transparent placeholder pages for modules scheduled in subsequent phases.
 * (Strict Rule 1.12: Do not present unfinished features as already functional).
 */
class ModulePlaceholderController
{
    public function show(string $moduleTitle, int $scheduledPhase, string $description, array $plannedFeatures): void
    {
        $html = '<div class="notice-banner" style="border-left: 4px solid var(--brand-primary); margin-bottom: 2rem;">
            <div class="notice-icon">📋</div>
            <div>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem;">
                    <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0; color: var(--text-primary);">' . e($moduleTitle) . '</h2>
                    <span class="badge badge-demo">JADWAL: FASE ' . $scheduledPhase . '</span>
                </div>
                <p style="font-size: 0.9rem; color: var(--text-secondary); margin: 0; line-height: 1.5;">' . e($description) . '</p>
            </div>
        </div>

        <div class="chart-card" style="margin-bottom: 1.5rem;">
            <h3 class="card-title" style="margin-bottom: 1rem;">Rencana Fitur pada Fase ' . $scheduledPhase . ':</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">';

        foreach ($plannedFeatures as $feature) {
            $html .= '<div style="background: var(--bg-canvas); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem;">
                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span style="color: var(--brand-primary);">✓</span> ' . e($feature['title']) . '
                </div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">' . e($feature['desc']) . '</div>
            </div>';
        }

        $html .= '</div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <a href="/dashboard" class="btn btn-primary btn-sm">&larr; Kembali ke Dashboard</a>
            <a href="/settings" class="btn btn-outline btn-sm">Periksa Status Sistem</a>
        </div>';

        Response::view('layouts/main', [
            'pageTitle' => $moduleTitle,
            'content' => $html,
        ]);
    }
}
