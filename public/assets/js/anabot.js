/**
 * AnaBot Frontend JavaScript Framework
 * Handles Theme Toggling (Dark/Light), Mobile Navigation, and Interactive Charts
 */

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initMobileNav();
    initDashboardCharts();
});

/**
 * Theme Management (Dark / Light Mode)
 */
function initTheme() {
    const savedTheme = localStorage.getItem('anabot_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('anabot_theme', newTheme);
            updateThemeIcon(newTheme);

            // Re-render charts with updated theme palette if Chart is loaded
            if (window.anabotGrowthChart) {
                updateChartThemes(newTheme);
            }
        });
    }
}

function updateThemeIcon(theme) {
    const icon = document.getElementById('themeToggleIcon');
    if (icon) {
        icon.textContent = theme === 'dark' ? '☀️' : '🌙';
    }
}

/**
 * Mobile Navigation Drawer
 */
function initMobileNav() {
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const sidebar = document.querySelector('.app-sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (hamburgerBtn && sidebar && backdrop) {
        hamburgerBtn.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            backdrop.classList.toggle('active');
        });

        backdrop.addEventListener('click', () => {
            sidebar.classList.remove('open');
            backdrop.classList.remove('active');
        });
    }
}

/**
 * Initialize Interactive Charts using Local Chart.js
 */
function initDashboardCharts() {
    if (typeof Chart === 'undefined') {
        return;
    }

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#94a3b8' : '#475569';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.06)' : 'rgba(0, 0, 0, 0.06)';

    // 1. Data Record Growth Time-Series Chart
    const growthCanvas = document.getElementById('growthChartCanvas');
    if (growthCanvas) {
        const ctx = growthCanvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(56, 189, 248, 0.35)');
        gradient.addColorStop(1, 'rgba(56, 189, 248, 0.0)');

        // Retrieve data passed from view (actual or transparent preview demo)
        const labels = window.anabotChartData?.growthLabels || ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        const values = window.anabotChartData?.growthValues || [120, 240, 310, 450, 520, 680, 890];

        window.anabotGrowthChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Record Tersimpan',
                    data: values,
                    borderColor: '#38bdf8',
                    backgroundColor: gradient,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#0284c7',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#38bdf8',
                        borderColor: '#334155',
                        borderWidth: 1,
                        padding: 10,
                        displayColors: false,
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { size: 11 } }
                    },
                    y: {
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { size: 11 }, precision: 0 }
                    }
                }
            }
        });
    }

    // 2. AI Usage & Token Breakdown Donut Chart
    const donutCanvas = document.getElementById('aiUsageDonutCanvas');
    if (donutCanvas) {
        const dctx = donutCanvas.getContext('2d');
        const donutLabels = window.anabotChartData?.donutLabels || ['Gemini', 'OpenAI', 'Claude'];
        const donutValues = window.anabotChartData?.donutValues || [60, 25, 15];

        window.anabotDonutChart = new Chart(dctx, {
            type: 'doughnut',
            data: {
                labels: donutLabels,
                datasets: [{
                    data: donutValues,
                    backgroundColor: ['#38bdf8', '#10b981', '#a855f7'],
                    borderWidth: isDark ? 2 : 1,
                    borderColor: isDark ? '#131c31' : '#ffffff',
                    hoverOffset: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: textColor,
                            padding: 12,
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    }
}

function updateChartThemes(theme) {
    const isDark = theme === 'dark';
    const textColor = isDark ? '#94a3b8' : '#475569';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.06)' : 'rgba(0, 0, 0, 0.06)';

    if (window.anabotGrowthChart) {
        window.anabotGrowthChart.options.scales.x.ticks.color = textColor;
        window.anabotGrowthChart.options.scales.x.grid.color = gridColor;
        window.anabotGrowthChart.options.scales.y.ticks.color = textColor;
        window.anabotGrowthChart.options.scales.y.grid.color = gridColor;
        window.anabotGrowthChart.update();
    }

    if (window.anabotDonutChart) {
        window.anabotDonutChart.options.plugins.legend.labels.color = textColor;
        window.anabotDonutChart.update();
    }
}
