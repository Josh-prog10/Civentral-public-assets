<?php
declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AssetService.php';

use App\Service\AssetService;

$assetService = new AssetService($pdo);
$assetAccessKeywords = ['assets', 'asset', 'asset inventory', 'asset inventory tracker'];
$assetManageKeywords = ['assets_manage', 'asset', 'asset management', 'asset inventory', 'asset inventory tracker'];
$canManageAssets = $isSuperAdmin || $hasResourceAccess($assetManageKeywords);

if (!$isSuperAdmin && !$hasResourceAccess($assetAccessKeywords)) {
    header('Location: ' . $basePath . 'pages/dashboard.php?error=access_denied');
    exit;
}

$assets      = $assetService->getAssets();
$totalAssets = count($assets);
$stats       = $assetService->getStats();

// Trend data for the line chart (per period).
$assetTrend = [
    'today' => $assetService->getAssetTrend('today'),
    'week'  => $assetService->getAssetTrend('week'),
    'month' => $assetService->getAssetTrend('month'),
    'year'  => $assetService->getAssetTrend('year'),
];

// Rows for the status panel.
$statusRows = [
    ['label' => 'Active',   'key' => 'active',   'color' => '#10b981'],
    ['label' => 'Retired',  'key' => 'retired',  'color' => '#f59e0b'],
    ['label' => 'Disposed', 'key' => 'disposed', 'color' => '#f43f5e'],
];

$pageTitle = 'Asset Inventory Dashboard';
include $basePath . 'includes/header.php';
include $basePath . 'includes/sidebar.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>

<div class="flex-1 p-6 lg:p-8">
    <div class="max-w-7xl mx-auto">

        <!-- ===== Chart + Status row ===== -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Trend chart card -->
            <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm flex flex-col">
                <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-chart-line text-brand-medium"></i>Assets Registered
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Trend of assets registered &amp; retired/disposed over time
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden" role="group">
                            <button type="button" data-period="today" class="period-btn px-3 py-1.5 text-xs font-bold transition">Today</button>
                            <button type="button" data-period="week"  class="period-btn px-3 py-1.5 text-xs font-bold transition border-l border-r border-slate-200 dark:border-slate-700">Week</button>
                            <button type="button" data-period="month" class="period-btn px-3 py-1.5 text-xs font-bold transition border-r border-slate-200 dark:border-slate-700">Month</button>
                            <button type="button" data-period="year"  class="period-btn px-3 py-1.5 text-xs font-bold transition">Year</button>
                        </div>
                        <button type="button" id="exportCsv"
                                class="text-xs font-bold bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-1.5 rounded-lg transition shadow-sm">
                            <i class="fa-solid fa-file-csv mr-1"></i> CSV
                        </button>
                        <button type="button" id="exportExcel"
                                class="text-xs font-bold bg-green-700 hover:bg-green-800 text-white px-3 py-1.5 rounded-lg transition shadow-sm">
                            <i class="fa-solid fa-file-excel mr-1"></i> Excel
                        </button>
                        <button type="button" id="exportPdf"
                                class="text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 rounded-lg transition shadow-sm">
                            <i class="fa-solid fa-file-pdf mr-1"></i> PDF
                        </button>
                    </div>
                </div>

                <div class="p-5 flex-1 flex flex-col">
                    <div style="position: relative; height: 340px;" class="flex-1">
                        <canvas id="trendChart"></canvas>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 mt-4 text-xs">
                        <span class="text-slate-500 dark:text-slate-400">
                            Total for selected period:
                            <strong id="total-registered" class="text-slate-800 dark:text-white">0</strong> assets
                            &nbsp;•&nbsp;
                            <strong id="total-closed" class="text-slate-800 dark:text-white">0</strong> retired/disposed
                        </span>
                        <span id="trend-date" class="text-slate-500 dark:text-slate-400"></span>
                    </div>
                </div>
            </div>

            <!-- Status breakdown card -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm flex flex-col">
                <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-brand-medium"></i>Asset Status
                    </h2>
                    <span class="text-xs font-bold text-slate-400"><?= $totalAssets ?> total</span>
                </div>

                <div class="p-5 flex-1 flex flex-col">
                    <!-- Rows -->
                    <div class="space-y-4">
                        <?php foreach ($statusRows as $row):
                            $val = (int)($stats[$row['key']] ?? 0);
                            $pct = $totalAssets > 0 ? ($val / $totalAssets) * 100 : 0.0;
                        ?>
                            <div>
                                <div class="flex items-center justify-between text-sm mb-1.5">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background: <?= $row['color'] ?>"></span>
                                        <span class="font-medium text-slate-700 dark:text-slate-300"><?= $row['label'] ?></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-slate-800 dark:text-white"><?= $val ?></span>
                                        <span class="text-xs text-slate-400">(<?= number_format($pct, 0) ?>%)</span>
                                    </div>
                                </div>
                                <div class="w-full h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                    <div class="h-full rounded-full transition-all"
                                         style="width: <?= number_format($pct, 2) ?>%; background: <?= $row['color'] ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Donut -->
                    <div class="mt-6 flex-1 flex items-center justify-center" style="position: relative; min-height: 240px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

        </div>
        <!-- ===== /Chart + Status row ===== -->

        <!-- Registered asset records -->
        <section class="mt-6 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                        <i class="fa-solid fa-boxes-stacked text-brand-medium"></i>Registered Assets
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        <?= $totalAssets ?> asset<?= $totalAssets === 1 ? '' : 's' ?> in the inventory
                    </p>
                </div>
                <?php if ($canManageAssets): ?>
                    <a href="<?= $basePath ?>pages/assets/add.php" class="inline-flex items-center gap-2 px-4 py-2 bg-brand-dark hover:bg-[#0e4f62] text-white text-sm font-bold rounded-lg transition shadow-sm">
                        <i class="fa-solid fa-plus"></i> Add Asset
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($assets): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/70 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3 font-bold">Asset</th>
                                <th class="px-5 py-3 font-bold">Category</th>
                                <th class="px-5 py-3 font-bold">Location</th>
                                <th class="px-5 py-3 font-bold">Condition</th>
                                <th class="px-5 py-3 font-bold">Status</th>
                                <th class="px-5 py-3 font-bold">Registered</th>
                                <?php if ($canManageAssets): ?><th class="px-5 py-3 text-right font-bold">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($assets as $asset):
                                $lifecycleStatus = (string)($asset['lifecycle_status'] ?? 'active');
                                $statusClasses = match ($lifecycleStatus) {
                                    'retired' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                    'disposed' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                                    default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                };
                            ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                                    <td class="px-5 py-3.5">
                                        <div class="font-semibold text-slate-800 dark:text-white"><?= htmlspecialchars((string)$asset['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($asset['description'])): ?>
                                            <div class="mt-0.5 max-w-md truncate text-xs text-slate-500 dark:text-slate-400"><?= htmlspecialchars((string)$asset['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-600 dark:text-slate-300"><?= htmlspecialchars((string)($asset['category_name'] ?? 'Uncategorized'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3.5 text-slate-600 dark:text-slate-300"><?= htmlspecialchars((string)$asset['location_text'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3.5 text-slate-600 dark:text-slate-300"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($asset['condition'] ?? 'unknown'))), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3.5">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= $statusClasses ?>">
                                            <?= htmlspecialchars(ucfirst($lifecycleStatus), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap text-slate-500 dark:text-slate-400">
                                        <?= !empty($asset['created_at']) ? htmlspecialchars(date('M j, Y', strtotime((string)$asset['created_at'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                                    </td>
                                    <?php if ($canManageAssets): ?>
                                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                            <a href="<?= $basePath ?>pages/assets/views.php?id=<?= (int)$asset['id'] ?>" class="font-bold text-brand-medium hover:text-brand-dark dark:hover:text-brand-light">
                                                View <i class="fa-solid fa-arrow-right ml-1 text-xs"></i>
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="px-5 py-12 text-center">
                    <i class="fa-solid fa-box-open text-3xl text-slate-300 dark:text-slate-600"></i>
                    <p class="mt-3 font-semibold text-slate-700 dark:text-slate-300">No assets registered yet</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Newly registered assets will appear here.</p>
                </div>
            <?php endif; ?>
        </section>

    </div>
</div>

<script>
    // ---- Data injected from PHP ----
    var TREND = <?= json_encode($assetTrend, JSON_UNESCAPED_SLASHES) ?>;
    var STATUS_DATA = {
        labels: <?= json_encode(array_column($statusRows, 'label')) ?>,
        values: <?= json_encode(array_map(fn($r) => (int)($stats[$r['key']] ?? 0), $statusRows)) ?>,
        colors: <?= json_encode(array_column($statusRows, 'color')) ?>
    };
    var TOTAL_ASSETS = <?= (int)$totalAssets ?>;
    var PERIODS = ['today', 'week', 'month', 'year'];
    var PERIOD_LABELS = {
        today: 'Today (hourly)',
        week:  'This Week (last 7 days)',
        month: 'This Month (last 30 days)',
        year:  'This Year (last 12 months)'
    };

    var trendChart = null;
    var statusChart = null;
    var currentPeriod = 'today';

    function sum(arr) {
        return (arr || []).reduce(function(a, b) { return a + Number(b || 0); }, 0);
    }

    function buildDatasets(d) {
        return [
            {
                label: 'Assets',
                data: d.registered || [],
                borderColor: '#0e7490',
                backgroundColor: 'transparent',
                borderWidth: 2,
                tension: 0.35,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#0e7490',
                pointBorderColor: '#0e7490',
                fill: false
            },
            {
                label: 'Retired / Disposed',
                data: d.closed || [],
                borderColor: '#8b5cf6',
                backgroundColor: 'transparent',
                borderWidth: 2,
                tension: 0.35,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#8b5cf6',
                pointBorderColor: '#8b5cf6',
                fill: false
            }
        ];
    }

    function renderTrend(period) {
        currentPeriod = period;
        var d = TREND[period] || TREND.today;

        document.querySelectorAll('.period-btn').forEach(function(btn) {
            var active = btn.getAttribute('data-period') === period;
            btn.classList.toggle('bg-brand-dark', active);
            btn.classList.toggle('text-white', active);
            btn.classList.toggle('text-slate-600', !active);
            btn.classList.toggle('dark:text-slate-300', !active);
        });

        document.getElementById('total-registered').textContent = sum(d.registered);
        document.getElementById('total-closed').textContent     = sum(d.closed);

        if (trendChart) {
            trendChart.data.labels = d.labels;
            trendChart.data.datasets = buildDatasets(d);
            trendChart.update();
            return;
        }

        var ctx = document.getElementById('trendChart').getContext('2d');
        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: d.labels,
                datasets: buildDatasets(d)
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'start',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 6,
                            boxHeight: 6,
                            padding: 16,
                            color: '#64748b',
                            font: { size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(c) {
                                return c.dataset.label + ': ' + c.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        ticks: { precision: 0, color: '#94a3b8', font: { size: 11 } },
                        grid: { color: 'rgba(148, 163, 184, 0.15)' }
                    }
                }
            }
        });
    }

    function renderStatusDonut() {
        var ctx = document.getElementById('statusChart').getContext('2d');
        statusChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: STATUS_DATA.labels,
                datasets: [{
                    data: STATUS_DATA.values,
                    backgroundColor: STATUS_DATA.colors,
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(c) {
                                var total = c.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total > 0 ? ((c.parsed / total) * 100).toFixed(1) : '0.0';
                                return c.label + ': ' + c.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ---- Shared helpers ----
    function csvCell(v) {
        var s = String(v === null || v === undefined ? '' : v);
        return '"' + s.replace(/"/g, '""') + '"';
    }

    function buildExportRows(period) {
        var d = TREND[period] || {labels: [], registered: [], closed: []};
        var rows = [];
        for (var i = 0; i < d.labels.length; i++) {
            rows.push([d.labels[i], d.registered[i] || 0, d.closed[i] || 0]);
        }
        return rows;
    }

    function todayStamp() {
        return new Date().toISOString().slice(0, 10);
    }

    // ---- CSV export ----
    function exportCsv() {
        var rows = [];
        rows.push(['Asset Trend Export']);
        rows.push(['Generated', new Date().toISOString()]);
        rows.push([]);
        rows.push(['Period', 'Total Assets Registered', 'Total Retired/Disposed']);
        PERIODS.forEach(function(p) {
            var d = TREND[p] || {registered: [], closed: []};
            rows.push([PERIOD_LABELS[p], sum(d.registered), sum(d.closed)]);
        });
        rows.push([]);
        PERIODS.forEach(function(p) {
            var d = TREND[p] || {labels: [], registered: [], closed: []};
            rows.push(['=== ' + (PERIOD_LABELS[p] || p) + ' ===']);
            rows.push(['Bucket', 'Assets Registered', 'Retired/Disposed']);
            buildExportRows(p).forEach(function(r) { rows.push(r); });
            rows.push([]);
        });

        var csv = rows.map(function(r) { return r.map(csvCell).join(','); }).join('\r\n');
        var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'asset-trend-' + todayStamp() + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // ---- Excel export (multi-sheet workbook) ----
    function exportExcel() {
        if (typeof XLSX === 'undefined') {
            alert('Excel export library failed to load. Please check your internet connection.');
            return;
        }

        var wb = XLSX.utils.book_new();

        // Sheet 1: Summary
        var summary = [
            ['Asset Trend Export'],
            ['Generated', new Date().toISOString()],
            [],
            ['Period', 'Total Assets Registered', 'Total Retired/Disposed']
        ];
        PERIODS.forEach(function(p) {
            var d = TREND[p] || {registered: [], closed: []};
            summary.push([PERIOD_LABELS[p], sum(d.registered), sum(d.closed)]);
        });
        var wsSummary = XLSX.utils.aoa_to_sheet(summary);
        wsSummary['!cols'] = [{ wch: 28 }, { wch: 22 }, { wch: 22 }];
        XLSX.utils.book_append_sheet(wb, wsSummary, 'Summary');

        // Sheet 2-5: one per period
        PERIODS.forEach(function(p) {
            var aoa = [
                [(PERIOD_LABELS[p] || p)],
                ['Bucket', 'Assets Registered', 'Retired/Disposed']
            ];
            buildExportRows(p).forEach(function(r) { aoa.push(r); });
            var totals = TREND[p] || {registered: [], closed: []};
            aoa.push(['TOTAL', sum(totals.registered), sum(totals.closed)]);

            var ws = XLSX.utils.aoa_to_sheet(aoa);
            ws['!cols'] = [{ wch: 22 }, { wch: 20 }, { wch: 20 }];
            var sheetName = p.charAt(0).toUpperCase() + p.slice(1);
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        });

        XLSX.writeFile(wb, 'asset-trend-' + todayStamp() + '.xlsx');
    }

    // ---- PDF export (chart image + summary) ----
    function exportPdf() {
        if (typeof window.jspdf === 'undefined' || !trendChart) {
            alert('PDF export library failed to load. Please check your internet connection.');
            return;
        }

        var jsPDF = window.jspdf.jsPDF;
        var doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        var pageW = doc.internal.pageSize.getWidth();
        var pageH = doc.internal.pageSize.getHeight();
        var margin = 40;

        // Header
        doc.setFontSize(18);
        doc.setTextColor(15, 23, 42);
        doc.text('Asset Trend Report', margin, margin + 8);

        doc.setFontSize(10);
        doc.setTextColor(100, 116, 139);
        doc.text('Period: ' + (PERIOD_LABELS[currentPeriod] || currentPeriod), margin, margin + 26);
        doc.text('Generated: ' + new Date().toLocaleString(), margin, margin + 40);

        // Divider
        doc.setDrawColor(226, 232, 240);
        doc.setLineWidth(0.7);
        doc.line(margin, margin + 50, pageW - margin, margin + 50);

        // Totals row
        var d = TREND[currentPeriod] || {registered: [], closed: []};
        doc.setFontSize(11);
        doc.setTextColor(15, 23, 42);
        doc.text('Total Assets Registered: ' + sum(d.registered), margin, margin + 72);
        doc.text('Total Retired / Disposed: ' + sum(d.closed), margin + 220, margin + 72);

        // Chart image
        var imgData = trendChart.toBase64Image('image/png', 1);
        var imgW = pageW - margin * 2;
        var imgH = imgW * 0.42;
        var imgY = margin + 90;
        doc.addImage(imgData, 'PNG', margin, imgY, imgW, imgH, undefined, 'FAST');

        // Summary table below chart
        var tableY = imgY + imgH + 24;
        doc.setFontSize(12);
        doc.setTextColor(15, 23, 42);
        doc.text('Summary by Period', margin, tableY);
        tableY += 12;

        doc.setFontSize(10);
        doc.setDrawColor(226, 232, 240);
        doc.setLineWidth(0.5);
        doc.line(margin, tableY, pageW - margin, tableY);
        tableY += 14;

        // Header row
        doc.setTextColor(100, 116, 139);
        doc.text('Period', margin + 4, tableY);
        doc.text('Assets Registered', margin + 260, tableY, { align: 'left' });
        doc.text('Retired / Disposed', margin + 440, tableY, { align: 'left' });
        tableY += 6;
        doc.setDrawColor(226, 232, 240);
        doc.line(margin, tableY, pageW - margin, tableY);
        tableY += 14;

        doc.setTextColor(15, 23, 42);
        PERIODS.forEach(function(p, idx) {
            var pd = TREND[p] || {registered: [], closed: []};
            doc.text(PERIOD_LABELS[p] || p, margin + 4, tableY);
            doc.text(String(sum(pd.registered)), margin + 260, tableY, { align: 'left' });
            doc.text(String(sum(pd.closed)),     margin + 440, tableY, { align: 'left' });
            tableY += 16;

            if (idx < PERIODS.length - 1) {
                doc.setDrawColor(241, 245, 249);
                doc.line(margin, tableY - 6, pageW - margin, tableY - 6);
            }
        });

        // Footer page number
        doc.setFontSize(9);
        doc.setTextColor(148, 163, 184);
        doc.text('Page 1 of 1', pageW - margin, pageH - 16, { align: 'right' });

        doc.save('asset-trend-' + todayStamp() + '.pdf');
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('trend-date').textContent =
            new Date().toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });

        renderTrend('today');
        renderStatusDonut();

        document.querySelectorAll('.period-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                renderTrend(btn.getAttribute('data-period'));
            });
        });

        document.getElementById('exportCsv').addEventListener('click', exportCsv);
        document.getElementById('exportExcel').addEventListener('click', exportExcel);
        document.getElementById('exportPdf').addEventListener('click', exportPdf);
    });
</script>

<?php include $basePath . 'includes/footer.php'; ?>