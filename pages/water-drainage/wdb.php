<?php
declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/WaterDrainageService.php';

use App\Service\WaterDrainageService;

$wdService = new WaterDrainageService($pdo);

if (!$isSuperAdmin && !$hasResourceAccess(['water_drainage', 'water drainage', 'water supply', 'drainage'])) {
    header('Location: ' . $basePath . 'pages/dashboard.php?error=access_denied');
    exit;
}

$stats  = $wdService->getStats();
$recent = $wdService->getRequests(['limit' => 5]);

$trends = [
    'today' => $wdService->getTrendData('today'),
    'week'  => $wdService->getTrendData('week'),
    'month' => $wdService->getTrendData('month'),
    'year'  => $wdService->getTrendData('year'),
];

// Status breakdown data for the right-hand panel
$statusBreakdown = [
    ['key' => 'pending',     'label' => 'Pending',     'color' => '#f59e0b', 'count' => $stats['pending']],
    ['key' => 'assigned',    'label' => 'Assigned',    'color' => '#3b82f6', 'count' => $stats['assigned']],
    ['key' => 'in_progress', 'label' => 'In Progress', 'color' => '#6366f1', 'count' => $stats['in_progress']],
    ['key' => 'resolved',    'label' => 'Resolved',    'color' => '#10b981', 'count' => $stats['resolved']],
    ['key' => 'rejected',    'label' => 'Rejected',    'color' => '#f43f5e', 'count' => $stats['rejected']],
];

$pageTitle = 'Water & Drainage Dashboard';
include $basePath . 'includes/header.php';
include $basePath . 'includes/sidebar.php';
?>

<div class="flex-1 p-6 lg:p-8">
    <div class="max-w-7xl mx-auto">
        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight mb-6">
            <i class="fa-solid fa-water text-brand-medium mr-3"></i>Water Supply & Drainage Requests
        </h1>

        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Total</p>
                <p class="text-2xl font-black text-slate-800 dark:text-white mt-1"><?= $stats['total'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Pending</p>
                <p class="text-2xl font-black text-amber-600 dark:text-amber-400 mt-1"><?= $stats['pending'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Assigned</p>
                <p class="text-2xl font-black text-blue-600 dark:text-blue-400 mt-1"><?= $stats['assigned'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">In Progress</p>
                <p class="text-2xl font-black text-indigo-600 dark:text-indigo-400 mt-1"><?= $stats['in_progress'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Resolved</p>
                <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-1"><?= $stats['resolved'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Today New</p>
                <p class="text-2xl font-black text-brand-dark dark:text-brand-medium mt-1"><?= $stats['today_new'] ?></p>
            </div>
        </div>

        <!-- ===================== Trend + Status Breakdown ===================== -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

            <!-- Left: Line chart (2/3) -->
            <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
                    <div>
                        <h2 class="font-bold text-slate-800 dark:text-white">
                            <i class="fa-solid fa-chart-line text-brand-medium mr-2"></i>Requests Submitted
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Trend of water &amp; drainage requests over time</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div id="trendPeriodGroup" class="inline-flex rounded-lg border border-slate-300 dark:border-slate-700 overflow-hidden">
                            <button type="button" data-period="today"
                                    class="trend-btn px-3 py-1.5 text-xs font-bold transition">Today</button>
                            <button type="button" data-period="week"
                                    class="trend-btn px-3 py-1.5 text-xs font-bold transition border-l border-slate-300 dark:border-slate-700">Week</button>
                            <button type="button" data-period="month"
                                    class="trend-btn px-3 py-1.5 text-xs font-bold transition border-l border-slate-300 dark:border-slate-700">Month</button>
                            <button type="button" data-period="year"
                                    class="trend-btn px-3 py-1.5 text-xs font-bold transition border-l border-slate-300 dark:border-slate-700">Year</button>
                        </div>

                        <button type="button" id="exportCsvBtn"
                                class="px-3 py-1.5 text-xs font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white transition shadow-sm">
                            <i class="fa-solid fa-file-csv mr-1"></i> CSV
                        </button>
                        <button type="button" id="exportExcelBtn"
                                class="px-3 py-1.5 text-xs font-bold rounded-lg bg-green-700 hover:bg-green-800 text-white transition shadow-sm">
                            <i class="fa-solid fa-file-excel mr-1"></i> Excel
                        </button>
                        <button type="button" id="exportPdfBtn"
                                class="px-3 py-1.5 text-xs font-bold rounded-lg bg-rose-600 hover:bg-rose-700 text-white transition shadow-sm">
                            <i class="fa-solid fa-file-pdf mr-1"></i> PDF
                        </button>
                    </div>
                </div>

                <div class="relative" style="height: 280px;">
                    <canvas id="requestsTrendChart"></canvas>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span>
                        Total for selected period:
                        <strong id="trendTotal" class="text-slate-800 dark:text-white">0 submitted</strong>
                        <span class="mx-1">·</span>
                        <strong id="trendResolved" class="text-slate-800 dark:text-white">0 resolved</strong>
                    </span>
                    <span id="trendRangeLabel"></span>
                </div>
            </div>

            <!-- Right: Status breakdown (1/3) -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-slate-800 dark:text-white">
                        <i class="fa-solid fa-layer-group text-brand-medium mr-2"></i>REQUEST STATUS
                    </h2>
                    <span class="text-xs font-bold text-slate-400 dark:text-slate-500"><?= $stats['total'] ?> total</span>
                </div>

                <!-- Progress bars -->
                <div class="space-y-3">
                    <?php foreach ($statusBreakdown as $s):
                        $pct = $stats['total'] > 0 ? round(($s['count'] / $stats['total']) * 100) : 0;
                    ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-300">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full" style="background:<?= $s['color'] ?>"></span>
                                    <?= htmlspecialchars($s['label']) ?>
                                </span>
                                <span class="text-xs font-bold text-slate-700 dark:text-slate-300">
                                    <?= $s['count'] ?> <span class="text-slate-400 dark:text-slate-500">(<?= $pct ?>%)</span>
                                </span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500"
                                     style="width: <?= $pct ?>%; background: <?= $s['color'] ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Donut -->
                <div class="mt-6 flex-1 flex items-center justify-center" style="min-height: 200px;">
                    <div class="relative" style="width: 190px; height: 190px;">
                        <canvas id="statusDonutChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
                <h2 class="font-bold text-slate-800 dark:text-white">Recent Requests</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3 text-left">#</th>
                            <th class="px-6 py-3 text-left">Name</th>
                            <th class="px-6 py-3 text-left">Category</th>
                            <th class="px-6 py-3 text-left">Status</th>
                            <th class="px-6 py-3 text-left">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if ($recent): ?>
                            <?php foreach ($recent as $r): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition">
                                    <td class="px-6 py-3 font-mono text-slate-600 dark:text-slate-400"><?= $r['id'] ?></td>
                                    <td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300">
                                        <!-- Changed from <a> to <span> with hover color change -->
                                        <span class="transition-colors duration-200 hover:text-brand-medium dark:hover:text-brand-light cursor-default">
                                            <?= htmlspecialchars($r['title']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($r['category_name']) ?></td>
                                    <td class="px-6 py-3">
                                        <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full
                                            <?php
                                            $statusClass = match ($r['status']) {
                                                'pending'     => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                                'assigned'    => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                                'in_progress' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
                                                'resolved'    => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                                'rejected'    => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                                                default       => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                            };
                                            echo $statusClass;
                                            ?>">
                                            <?= ucfirst(str_replace('_', ' ', $r['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400 dark:text-slate-500">No requests yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- SheetJS (Excel export) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<!-- jsPDF + AutoTable (PDF export) -->
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>

<script>
(function () {
    const TREND_DATA  = <?= json_encode($trends, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const STATUS_DATA = <?= json_encode($statusBreakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const ctx = document.getElementById('requestsTrendChart').getContext('2d');
    let currentPeriod = 'week';
    let chart = null;

    const isDark = () =>
        document.documentElement.classList.contains('dark') ||
        document.body.classList.contains('dark');

    // ---------- Line chart ----------
    function buildChart(period) {
        const payload = TREND_DATA[period];
        if (!payload) return;

        if (chart) chart.destroy();

        const axisColor  = isDark() ? '#94a3b8' : '#64748b';
        const gridColor  = isDark() ? 'rgba(148, 163, 184, 0.10)' : 'rgba(100, 116, 139, 0.10)';
        const submittedC = isDark() ? '#34d399' : '#0d6e8a';
        const resolvedC  = isDark() ? '#a78bfa' : '#7c3aed';

        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: payload.labels,
                datasets: [
                    {
                        label: 'Submitted',
                        data: payload.submitted,
                        borderColor: submittedC,
                        backgroundColor: submittedC,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: submittedC,
                        pointBorderColor: isDark() ? '#0f172a' : '#ffffff',
                        pointBorderWidth: 2,
                    },
                    {
                        label: 'Resolved',
                        data: payload.resolved,
                        borderColor: resolvedC,
                        backgroundColor: resolvedC,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: resolvedC,
                        pointBorderColor: isDark() ? '#0f172a' : '#ffffff',
                        pointBorderWidth: 2,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: axisColor,
                            font: { size: 11, weight: '600' },
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14
                        }
                    },
                    tooltip: {
                        backgroundColor: isDark() ? '#0f172a' : '#1e293b',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: true,
                        boxWidth: 8,
                        boxHeight: 8,
                        usePointStyle: true,
                        callbacks: {
                            label: (c) => ' ' + c.dataset.label + ': ' + c.parsed.y
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: axisColor, font: { size: 10, weight: '600' }, maxRotation: 0, autoSkipPadding: 10 },
                        grid: { color: gridColor, drawBorder: false }
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: 1,
                        ticks: { color: axisColor, font: { size: 10, weight: '600' }, precision: 0, stepSize: 1 },
                        grid: { color: gridColor, drawBorder: false }
                    }
                }
            }
        });

        document.getElementById('trendTotal').textContent    = payload.total + ' submitted';
        document.getElementById('trendResolved').textContent = payload.total_resolved + ' resolved';

        const now = new Date();
        const opts = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
        document.getElementById('trendRangeLabel').textContent = now.toLocaleDateString('en-US', opts);
    }

    function setActiveButton(period) {
        document.querySelectorAll('.trend-btn').forEach(btn => {
            const active = btn.dataset.period === period;
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            if (active) {
                btn.classList.add('bg-brand-dark', 'text-white');
                btn.classList.remove('bg-white', 'dark:bg-slate-800', 'text-slate-600', 'dark:text-slate-300');
            } else {
                btn.classList.remove('bg-brand-dark', 'text-white');
                btn.classList.add('bg-white', 'dark:bg-slate-800', 'text-slate-600', 'dark:text-slate-300');
            }
        });
    }

    function selectPeriod(period) {
        if (!TREND_DATA[period]) return;
        currentPeriod = period;
        setActiveButton(period);
        buildChart(period);
    }

    document.querySelectorAll('.trend-btn').forEach(btn => {
        btn.addEventListener('click', () => selectPeriod(btn.dataset.period));
    });

    const themeObserver = new MutationObserver(() => buildChart(currentPeriod));
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

    // ---------- Status donut ----------
    const donutCtx = document.getElementById('statusDonutChart').getContext('2d');
    const nonZero = STATUS_DATA.filter(s => s.count > 0);
    const donutLabels = nonZero.map(s => s.label);
    const donutValues = nonZero.map(s => s.count);
    const donutColors = nonZero.map(s => s.color);

    const hasData = donutValues.length > 0;
    if (!hasData) {
        donutLabels.push('No data');
        donutValues.push(1);
        donutColors.push('#e2e8f0');
    }

    new Chart(donutCtx, {
        type: 'doughnut',
        data: {
            labels: donutLabels,
            datasets: [{
                data: donutValues,
                backgroundColor: donutColors,
                borderWidth: 0,
                hoverOffset: hasData ? 6 : 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: hasData,
                    backgroundColor: isDark() ? '#0f172a' : '#1e293b',
                    titleColor: '#f8fafc',
                    bodyColor: '#e2e8f0',
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    usePointStyle: true
                }
            }
        }
    });

    // ---------- Helpers ----------
    function fileStamp() {
        return new Date().toISOString().slice(0, 10);
    }

    function buildTrendRows() {
        const payload = TREND_DATA[currentPeriod];
        const rows = [['Period', 'Label', 'Submitted', 'Resolved']];
        payload.labels.forEach((label, i) => {
            rows.push([
                payload.period,
                label,
                payload.submitted[i] ?? 0,
                payload.resolved[i] ?? 0
            ]);
        });
        rows.push(['', 'TOTAL', payload.total, payload.total_resolved]);
        return rows;
    }

    // ---------- Export CSV ----------
    document.getElementById('exportCsvBtn').addEventListener('click', () => {
        const rows = buildTrendRows();
        const csv = rows.map(r =>
            r.map(v => {
                const s = String(v ?? '');
                return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
            }).join(',')
        ).join('\n');

        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `water_drainage_requests_${currentPeriod}_${fileStamp()}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

    // ---------- Export Excel (XLSX) ----------
    document.getElementById('exportExcelBtn').addEventListener('click', () => {
        const payload = TREND_DATA[currentPeriod];
        const rows = buildTrendRows();

        // Sheet 1: Trend data
        const ws = XLSX.utils.aoa_to_sheet(rows);
        ws['!cols'] = [
            { wch: 10 }, { wch: 14 }, { wch: 12 }, { wch: 12 }
        ];

        // Sheet 2: Status breakdown
        const statusRows = [['Status', 'Count', 'Percentage']];
        const totalForPct = STATUS_DATA.reduce((sum, s) => sum + s.count, 0) || 1;
        STATUS_DATA.forEach(s => {
            statusRows.push([
                s.label,
                s.count,
                Math.round((s.count / totalForPct) * 100) + '%'
            ]);
        });
        statusRows.push(['TOTAL', totalForPct, '100%']);
        const ws2 = XLSX.utils.aoa_to_sheet(statusRows);
        ws2['!cols'] = [{ wch: 14 }, { wch: 10 }, { wch: 12 }];

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, `Trend ${payload.period}`);
        XLSX.utils.book_append_sheet(wb, ws2, 'Status');

        XLSX.writeFile(wb, `water_drainage_requests_${currentPeriod}_${fileStamp()}.xlsx`);
    });

    // ---------- Export PDF ----------
    document.getElementById('exportPdfBtn').addEventListener('click', () => {
        const payload = TREND_DATA[currentPeriod];
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });

        const pageW = doc.internal.pageSize.getWidth();
        const marginX = 40;
        let cursorY = 50;

        // Title
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(16);
        doc.setTextColor(15, 23, 42);
        doc.text('Water & Drainage Requests Report', marginX, cursorY);
        cursorY += 18;

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(100, 116, 139);
        doc.text(`Period: ${payload.period.toUpperCase()}   |   Generated: ${new Date().toLocaleString()}`, marginX, cursorY);
        cursorY += 22;

        // Chart image
        if (chart) {
            try {
                const imgData = chart.toBase64Image('image/png', 1);
                const imgW = pageW - marginX * 2;
                const imgH = 240;
                doc.addImage(imgData, 'PNG', marginX, cursorY, imgW, imgH);
                cursorY += imgH + 20;
            } catch (e) { /* ignore image failure */ }
        }

        // Trend table
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.setTextColor(15, 23, 42);
        doc.text('Trend Data', marginX, cursorY);
        cursorY += 8;

        doc.autoTable({
            startY: cursorY,
            head: [['Label', 'Submitted', 'Resolved']],
            body: payload.labels.map((label, i) => [
                label,
                payload.submitted[i] ?? 0,
                payload.resolved[i] ?? 0
            ]),
            foot: [['TOTAL', payload.total, payload.total_resolved]],
            theme: 'grid',
            headStyles: { fillColor: [13, 110, 138], textColor: 255, fontStyle: 'bold' },
            footStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' },
            styles: { fontSize: 9, cellPadding: 5 },
            margin: { left: marginX, right: marginX }
        });

        cursorY = doc.lastAutoTable.finalY + 24;

        // Status table
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.setTextColor(15, 23, 42);
        doc.text('Request Status Breakdown', marginX, cursorY);
        cursorY += 8;

        const statusTotal = STATUS_DATA.reduce((sum, s) => sum + s.count, 0) || 1;
        doc.autoTable({
            startY: cursorY,
            head: [['Status', 'Count', 'Percentage']],
            body: STATUS_DATA.map(s => [
                s.label,
                s.count,
                Math.round((s.count / statusTotal) * 100) + '%'
            ]),
            foot: [['TOTAL', statusTotal, '100%']],
            theme: 'grid',
            headStyles: { fillColor: [13, 110, 138], textColor: 255, fontStyle: 'bold' },
            footStyles: { fillColor: [241, 245, 249], textColor: [15, 23, 42], fontStyle: 'bold' },
            styles: { fontSize: 9, cellPadding: 5 },
            margin: { left: marginX, right: marginX }
        });

        doc.save(`water_drainage_requests_${currentPeriod}_${fileStamp()}.pdf`);
    });

    // Init
    selectPeriod('week');
})();
</script>

<?php include $basePath . 'includes/footer.php'; ?>