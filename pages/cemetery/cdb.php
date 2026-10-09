<?php
declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/CemeteryService.php';

use App\Service\CemeteryService;

$cemeteryService = new CemeteryService($pdo);

if (!$isSuperAdmin && !$hasResourceAccess(['cemetery', 'burial', 'lot'])) {
    header('Location: ' . $basePath . 'pages/dashboard.php?error=access_denied');
    exit;
}

$summary         = $cemeteryService->getAvailabilitySummary();
$recent          = $cemeteryService->getRecentBurials(5);
$totalAvailable  = $cemeteryService->getAvailableLotsCount();
$totalCemeteries = count($cemeteryService->getCemeteries());
$timeSeries      = $cemeteryService->getTimeSeriesStats();

// -------- Status breakdown data --------
$statusCounts = ['available' => 0, 'reserved' => 0, 'occupied' => 0];
foreach ($summary as $row) {
    $statusCounts[strtolower($row['status'])] = (int)$row['count'];
}
$totalLots = array_sum($statusCounts);

$statusMeta = [
    'available' => ['label' => 'Available', 'bar' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-300'],
    'reserved'  => ['label' => 'Reserved',  'bar' => 'bg-amber-500',   'text' => 'text-amber-700 dark:text-amber-300'],
    'occupied'  => ['label' => 'Occupied',  'bar' => 'bg-rose-500',    'text' => 'text-rose-700 dark:text-rose-300'],
];

$periodLabels = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'];

// -------- CSV export --------
if (($_GET['export'] ?? '') === 'csv') {
    $period = $_GET['period'] ?? 'week';
    if (!isset($timeSeries[$period])) {
        $period = 'week';
    }
    $series = $timeSeries[$period];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cemetery_' . $period . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($out, ['Cemetery & Burial Statistics — ' . ($periodLabels[$period] ?? ucfirst($period))]);
    fputcsv($out, ['Range: ' . $series['range_label']]);
    fputcsv($out, []);
    fputcsv($out, ['Point', 'Burials', 'Deceased']);
    foreach ($series['labels'] as $i => $label) {
        fputcsv($out, [$label, $series['burials'][$i], $series['deceased'][$i]]);
    }
    fputcsv($out, ['TOTAL', $series['total_burials'], $series['total_deceased']]);

    fputcsv($out, []);
    fputcsv($out, ['Lot Status Breakdown']);
    fputcsv($out, ['Status', 'Count', 'Percentage']);
    foreach ($statusCounts as $status => $count) {
        $label = $statusMeta[$status]['label'] ?? ucfirst($status);
        $pct = $totalLots > 0 ? round(($count / $totalLots) * 100, 1) : 0;
        fputcsv($out, [$label, $count, $pct . '%']);
    }
    fputcsv($out, ['Total', $totalLots, '100%']);

    fclose($out);
    exit;
}

// -------- Excel export (HTML-table .xls, opens natively in Excel) --------
if (($_GET['export'] ?? '') === 'excel') {
    $period = $_GET['period'] ?? 'week';
    if (!isset($timeSeries[$period])) {
        $period = 'week';
    }
    $series = $timeSeries[$period];

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="cemetery_' . $period . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<table border="1" cellpadding="4" cellspacing="0" style="font-family:Arial,sans-serif;font-size:11pt;">';

    // Section 1: Time series
    echo '<tr><th colspan="3" style="background:#0f766e;color:#ffffff;font-size:14pt;text-align:left;">'
       . 'Cemetery &amp; Burial Statistics — ' . htmlspecialchars($periodLabels[$period] ?? ucfirst($period))
       . '</th></tr>';
    echo '<tr><td colspan="3">Range: ' . htmlspecialchars($series['range_label']) . '</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';
    echo '<tr style="background:#e2e8f0;font-weight:bold;"><th>Point</th><th>Burials</th><th>Deceased</th></tr>';
    foreach ($series['labels'] as $i => $label) {
        echo '<tr><td>' . htmlspecialchars($label) . '</td>'
           . '<td>' . (int)$series['burials'][$i] . '</td>'
           . '<td>' . (int)$series['deceased'][$i] . '</td></tr>';
    }
    echo '<tr style="background:#f1f5f9;font-weight:bold;">'
       . '<td>TOTAL</td><td>' . (int)$series['total_burials'] . '</td><td>' . (int)$series['total_deceased'] . '</td></tr>';

    // Section 2: Status breakdown
    echo '<tr><td colspan="3"></td></tr>';
    echo '<tr><th colspan="3" style="background:#4338ca;color:#ffffff;font-size:12pt;text-align:left;">Lot Status Breakdown</th></tr>';
    echo '<tr style="background:#e2e8f0;font-weight:bold;"><th>Status</th><th>Count</th><th>Percentage</th></tr>';
    foreach ($statusCounts as $status => $count) {
        $label = $statusMeta[$status]['label'] ?? ucfirst($status);
        $pct = $totalLots > 0 ? round(($count / $totalLots) * 100, 1) : 0;
        echo '<tr><td>' . htmlspecialchars($label) . '</td>'
           . '<td>' . (int)$count . '</td>'
           . '<td>' . $pct . '%</td></tr>';
    }
    echo '<tr style="background:#f1f5f9;font-weight:bold;">'
       . '<td>Total</td><td>' . (int)$totalLots . '</td><td>100%</td></tr>';

    echo '</table></body></html>';
    exit;
}

$pageTitle = 'Cemetery Dashboard';
include $basePath . 'includes/header.php';
include $basePath . 'includes/sidebar.php';
?>

<div class="flex-1 p-6 lg:p-8">
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight">
                <i class="fa-solid fa-cemetery text-brand-medium mr-3"></i>Cemetery & Burial Management
            </h1>
            <a href="<?= $basePath ?>pages/cemetery/cemeteries.php"
               class="text-sm font-bold text-brand-medium hover:text-brand-dark dark:hover:text-brand-light transition">
                <i class="fa-solid fa-map-location-dot mr-1"></i> Manage Cemeteries
            </a>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <?php foreach ($summary as $row): ?>
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500"><?= ucfirst($row['status']) ?></p>
                    <p class="text-2xl font-black text-slate-800 dark:text-white mt-1"><?= $row['count'] ?></p>
                </div>
            <?php endforeach; ?>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Available Lots</p>
                <p class="text-2xl font-black text-brand-dark dark:text-brand-medium mt-1"><?= $totalAvailable ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Cemeteries</p>
                <p class="text-2xl font-black text-indigo-600 dark:text-indigo-400 mt-1"><?= $totalCemeteries ?></p>
            </div>
        </div>

        <!-- Chart + Status Breakdown -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

            <!-- ============ CHART CARD (2 cols) ============ -->
            <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm" id="statsCard">

                <!-- Header -->
                <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
                    <div>
                        <h2 class="flex items-center gap-2 text-base font-bold text-slate-800 dark:text-white">
                            <i class="fa-solid fa-chart-line text-brand-medium"></i> Burials Submitted
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            Trend of burials &amp; deceased records over time
                        </p>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <!-- Segmented toggle -->
                        <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
                            <?php foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $key => $lbl): ?>
                                <button type="button"
                                        data-period="<?= $key ?>"
                                        class="period-btn px-3 py-1.5 text-xs font-bold border-r border-slate-200 dark:border-slate-700 last:border-r-0 transition
                                               <?= $key === 'week'
                                                    ? 'bg-brand-dark text-white'
                                                    : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800' ?>">
                                    <?= $lbl ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- CSV export -->
                        <a id="csvExportLink" href="?export=csv&period=week"
                           class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg
                                  bg-emerald-500 hover:bg-emerald-600 text-white transition shadow-sm"
                           title="Download the current period's data as CSV">
                            <i class="fa-solid fa-file-csv"></i> CSV
                        </a>

                        <!-- Excel export -->
                        <a id="excelExportLink" href="?export=excel&period=week"
                           class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg
                                  bg-blue-600 hover:bg-blue-700 text-white transition shadow-sm"
                           title="Download the current period's data as Excel">
                            <i class="fa-solid fa-file-excel"></i> Excel
                        </a>

                        <!-- PDF export (client-side) -->
                        <button type="button" id="exportPdfBtn"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg
                                       bg-rose-500 hover:bg-rose-600 text-white transition shadow-sm"
                                title="Download the chart + data as a PDF">
                            <i class="fa-solid fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>

                <!-- Chart -->
                <div style="height: 300px;">
                    <canvas id="statsChart"></canvas>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 text-xs">
                    <span class="text-slate-500 dark:text-slate-400">
                        Total for selected period:
                        <strong id="periodTotal" class="text-slate-800 dark:text-white ml-1">0</strong>
                    </span>
                    <span id="periodRange" class="text-slate-500 dark:text-slate-400 font-medium">—</span>
                </div>
            </div>

            <!-- ============ STATUS BREAKDOWN (1 col) ============ -->
            <div class="lg:col-span-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">

                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <i class="fa-solid fa-layer-group mr-1"></i> Lot Status
                    </h3>
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-500">
                        <?= $totalLots ?> total
                    </span>
                </div>

                <!-- Status rows -->
                <div class="space-y-4">
                    <?php foreach ($statusMeta as $status => $meta): ?>
                        <?php
                        $count = $statusCounts[$status] ?? 0;
                        $pct   = $totalLots > 0 ? round(($count / $totalLots) * 100, 1) : 0;
                        ?>
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="inline-flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-300">
                                    <span class="w-2.5 h-2.5 rounded-full <?= $meta['bar'] ?>"></span>
                                    <?= $meta['label'] ?>
                                </span>
                                <span class="text-sm font-black text-slate-800 dark:text-white">
                                    <?= $count ?>
                                    <span class="text-xs font-bold text-slate-400 dark:text-slate-500 ml-1">(<?= $pct ?>%)</span>
                                </span>
                            </div>
                            <div class="w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                <div class="<?= $meta['bar'] ?> h-full rounded-full transition-all duration-700"
                                     style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Donut -->
                <div class="mt-6 pt-6 border-t border-slate-100 dark:border-slate-800">
                    <div style="height: 170px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Burials -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <h2 class="font-bold text-slate-800 dark:text-white">Recent Burials</h2>
                <a href="<?= $basePath ?>pages/cemetery/burials.php"
                   class="text-xs font-bold text-brand-medium hover:text-brand-dark dark:hover:text-brand-light transition">
                    View All <i class="fa-solid fa-arrow-right ml-1"></i>
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3 text-left">Deceased</th>
                            <th class="px-6 py-3 text-left">Cemetery</th>
                            <th class="px-6 py-3 text-left">Lot</th>
                            <th class="px-6 py-3 text-left">Burial Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if ($recent): ?>
                            <?php foreach ($recent as $b): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition">
                                    <td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">
                                        <?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?>
                                    </td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400">
                                        <?= htmlspecialchars($b['cemetery_name']) ?>
                                    </td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400">
                                        <?= htmlspecialchars($b['section'] . '-' . $b['row_num'] . '-' . $b['lot_number']) ?>
                                    </td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400">
                                        <?= htmlspecialchars($b['burial_date']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="px-6 py-8 text-center text-slate-400 dark:text-slate-500">No burials recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- jsPDF + autoTable for PDF export -->
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>

<script>
(function () {
    const timeSeries   = <?= json_encode($timeSeries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const statusCounts = <?= json_encode($statusCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const periodLabels = <?= json_encode($periodLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const isDark    = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#cbd5e1' : '#64748b';
    const gridColor = isDark ? 'rgba(148,163,184,0.10)' : 'rgba(100,116,139,0.10)';

    // ------------------------------------------------------------
    // LINE CHART
    // ------------------------------------------------------------
    const canvas = document.getElementById('statsChart');
    let lineChart = null;

    if (canvas) {
        const ctx = canvas.getContext('2d');

        const burialGradient = ctx.createLinearGradient(0, 0, 0, 300);
        burialGradient.addColorStop(0, 'rgba(15, 118, 110, 0.28)');
        burialGradient.addColorStop(1, 'rgba(15, 118, 110, 0.00)');

        const deceasedGradient = ctx.createLinearGradient(0, 0, 0, 300);
        deceasedGradient.addColorStop(0, 'rgba(99, 102, 241, 0.28)');
        deceasedGradient.addColorStop(1, 'rgba(99, 102, 241, 0.00)');

        const initial = timeSeries.week;

        lineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: initial.labels,
                datasets: [
                    {
                        label: 'Burials',
                        data: initial.burials,
                        borderColor:     'rgba(15, 118, 110, 1)',
                        backgroundColor: burialGradient,
                        pointBackgroundColor: 'rgba(15, 118, 110, 1)',
                        pointBorderColor:     isDark ? '#0f172a' : '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35
                    },
                    {
                        label: 'Deceased',
                        data: initial.deceased,
                        borderColor:     'rgba(99, 102, 241, 1)',
                        backgroundColor: deceasedGradient,
                        pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                        pointBorderColor:     isDark ? '#0f172a' : '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 600, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'start',
                        labels: {
                            color: textColor,
                            font: { weight: 'bold', size: 11 },
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            boxWidth: 8,
                            boxHeight: 8
                        }
                    },
                    tooltip: {
                        backgroundColor: isDark ? '#0f172a' : '#1e293b',
                        titleColor: '#fff',
                        bodyColor:  '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: true,
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.dataset.label + ': ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: textColor,
                            font: { size: 11 },
                            autoSkip: true,
                            maxRotation: 0,
                            maxTicksLimit: 12
                        },
                        grid: { display: false, drawBorder: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: textColor, precision: 0, stepSize: 1, font: { size: 11 } },
                        grid: { color: gridColor, drawBorder: false }
                    }
                }
            }
        });
    }

    // ------------------------------------------------------------
    // PERIOD TOGGLE
    // ------------------------------------------------------------
    const periodTotal     = document.getElementById('periodTotal');
    const periodRange     = document.getElementById('periodRange');
    const csvExportLink   = document.getElementById('csvExportLink');
    const excelExportLink = document.getElementById('excelExportLink');

    function applyPeriod(period) {
        const data = timeSeries[period];
        if (!data || !lineChart) return;

        lineChart.data.labels = data.labels;
        lineChart.data.datasets[0].data = data.burials;
        lineChart.data.datasets[1].data = data.deceased;
        lineChart.update();

        if (periodTotal) {
            periodTotal.textContent = data.total_burials + ' burials · ' + data.total_deceased + ' deceased';
        }
        if (periodRange) {
            periodRange.textContent = data.range_label;
        }
        if (csvExportLink)   csvExportLink.href   = '?export=csv&period='   + period;
        if (excelExportLink) excelExportLink.href = '?export=excel&period=' + period;

        document.querySelectorAll('.period-btn').forEach(btn => {
            const activeClasses = ['bg-brand-dark', 'text-white'];
            const idleClasses   = ['bg-white', 'dark:bg-slate-900', 'text-slate-600', 'dark:text-slate-300',
                                   'hover:bg-slate-50', 'dark:hover:bg-slate-800'];
            if (btn.dataset.period === period) {
                btn.classList.add(...activeClasses);
                btn.classList.remove(...idleClasses);
            } else {
                btn.classList.remove(...activeClasses);
                btn.classList.add(...idleClasses);
            }
        });
    }

    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.addEventListener('click', () => applyPeriod(btn.dataset.period));
    });

    applyPeriod('week');

    // ------------------------------------------------------------
    // STATUS DONUT
    // ------------------------------------------------------------
    const statusCanvas = document.getElementById('statusChart');
    if (statusCanvas) {
        const values = [statusCounts.available, statusCounts.reserved, statusCounts.occupied];
        const total  = values.reduce((a, b) => a + b, 0);

        new Chart(statusCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Available', 'Reserved', 'Occupied'],
                datasets: [{
                    data: values,
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.85)',
                        'rgba(245, 158, 11, 0.85)',
                        'rgba(244, 63, 94, 0.85)'
                    ],
                    borderColor: isDark ? '#0f172a' : '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: { duration: 700, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#0f172a' : '#1e293b',
                        titleColor: '#fff',
                        bodyColor:  '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (context) {
                                const val = context.parsed;
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';
                                return ' ' + context.label + ': ' + val + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ------------------------------------------------------------
    // PDF EXPORT
    // ------------------------------------------------------------
    const pdfBtn = document.getElementById('exportPdfBtn');
    if (pdfBtn && lineChart && window.jspdf && window.jspdf.jsPDF) {
        pdfBtn.addEventListener('click', function () {
            const activeBtn = document.querySelector('.period-btn.bg-brand-dark');
            const period = activeBtn ? activeBtn.dataset.period : 'week';
            const series = timeSeries[period];
            if (!series) return;

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ orientation: 'p', unit: 'pt', format: 'a4' });

            const pageW = doc.internal.pageSize.getWidth();
            const marginX = 40;
            let y = 50;

            // Title
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(18);
            doc.setTextColor(15, 23, 42);
            doc.text('Cemetery & Burial Statistics', marginX, y);
            y += 20;

            // Subtitle
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10);
            doc.setTextColor(100, 116, 139);
            doc.text(
                'Period: ' + (periodLabels[period] || period) + '   (' + series.range_label + ')',
                marginX, y
            );
            y += 8;
            doc.text('Generated: ' + new Date().toLocaleString(), marginX, y);
            y += 20;

            // Chart image
            const chartImg = lineChart.toBase64Image('image/png', 2);
            const chartW = pageW - marginX * 2;
            const chartH = chartW * 0.55;
            doc.addImage(chartImg, 'PNG', marginX, y, chartW, chartH);
            y += chartH + 24;

            // Time series table
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(12);
            doc.setTextColor(15, 23, 42);
            doc.text('Data Points', marginX, y);
            y += 6;

            const bodyRows = series.labels.map((label, i) => [
                label,
                String(series.burials[i]),
                String(series.deceased[i])
            ]);
            bodyRows.push(['TOTAL', String(series.total_burials), String(series.total_deceased)]);

            doc.autoTable({
                startY: y,
                head: [['Point', 'Burials', 'Deceased']],
                body: bodyRows,
                theme: 'grid',
                styles: { fontSize: 9, cellPadding: 4 },
                headStyles: { fillColor: [15, 118, 110], textColor: 255, fontStyle: 'bold' },
                footStyles: { fillColor: [241, 245, 249], textColor: 15, fontStyle: 'bold' },
                didParseCell: function (data) {
                    if (data.section === 'body' && data.row.index === bodyRows.length - 1) {
                        data.cell.styles.fillColor = [241, 245, 249];
                        data.cell.styles.fontStyle = 'bold';
                    }
                },
                margin: { left: marginX, right: marginX }
            });

            y = doc.lastAutoTable.finalY + 24;

            // Status breakdown table
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(12);
            doc.setTextColor(15, 23, 42);
            doc.text('Lot Status Breakdown', marginX, y);
            y += 6;

            const totalLots = statusCounts.available + statusCounts.reserved + statusCounts.occupied;
            const statusRows = [
                ['Available', String(statusCounts.available), totalLots > 0 ? ((statusCounts.available / totalLots) * 100).toFixed(1) + '%' : '0%'],
                ['Reserved',  String(statusCounts.reserved),  totalLots > 0 ? ((statusCounts.reserved  / totalLots) * 100).toFixed(1) + '%' : '0%'],
                ['Occupied',  String(statusCounts.occupied),  totalLots > 0 ? ((statusCounts.occupied  / totalLots) * 100).toFixed(1) + '%' : '0%'],
                ['Total',     String(totalLots),              '100%']
            ];

            doc.autoTable({
                startY: y,
                head: [['Status', 'Count', 'Percentage']],
                body: statusRows,
                theme: 'grid',
                styles: { fontSize: 9, cellPadding: 4 },
                headStyles: { fillColor: [67, 56, 202], textColor: 255, fontStyle: 'bold' },
                didParseCell: function (data) {
                    if (data.section === 'body' && data.row.index === statusRows.length - 1) {
                        data.cell.styles.fillColor = [241, 245, 249];
                        data.cell.styles.fontStyle = 'bold';
                    }
                },
                margin: { left: marginX, right: marginX }
            });

            doc.save('cemetery_' + period + '_' + new Date().toISOString().slice(0, 10) + '.pdf');
        });
    }
})();
</script>

<?php include $basePath . 'includes/footer.php'; ?>