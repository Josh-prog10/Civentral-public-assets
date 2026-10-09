<?php
declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/ParkService.php';

use App\Service\ParkService;

$parkService = new ParkService($pdo);

if (!$isSuperAdmin && !$hasResourceAccess(['parks', 'recreation'])) {
    header('Location: ' . $basePath . 'pages/dashboard.php?error=access_denied');
    exit;
}

/* ------------------------------------------------------------------ */
/* Filters                                                             */
/* ------------------------------------------------------------------ */
$filters = [];
if (!empty($_GET['facility_id']) && is_numeric($_GET['facility_id'])) {
    $filters['facility_id'] = (int)$_GET['facility_id'];
}
if (!empty($_GET['status']) && in_array($_GET['status'], ['pending','approved','cancelled','rejected','completed'], true)) {
    $filters['status'] = $_GET['status'];
}
if (!empty($_GET['payment_status']) && in_array($_GET['payment_status'], ['unpaid','paid','refunded'], true)) {
    $filters['payment_status'] = $_GET['payment_status'];
}
if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'] . ' 23:59:59';
}

$range = $_GET['range'] ?? 'week';
if (!in_array($range, ['today','week','month','year'], true)) {
    $range = 'week';
}

$facilityIdForCharts = $filters['facility_id'] ?? null;

$reservations    = $parkService->getReservations($filters);
$trend           = $parkService->getReservationTrend($range, $facilityIdForCharts);
$statusBreakdown = $parkService->getReservationStatusBreakdown($facilityIdForCharts);

/* ------------------------------------------------------------------ */
/* Exports (must run before any output)                                */
/* ------------------------------------------------------------------ */
if (isset($_GET['export'])) {
    $format = strtolower($_GET['export']);
    $stamp  = date('Ymd-His');

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="park-reservations-' . $stamp . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'ID','Event','Facility','Reserved To','Start','End',
            'Status','Cancelled Reason','Payment Status','Amount',
            'Method','Reference','Receipt No.','Paid At',
        ]);
        foreach ($reservations as $r) {
            fputcsv($out, [
                $r['id'], $r['event_name'], $r['facility_name'], $r['user_name'],
                $r['start_datetime'], $r['end_datetime'], $r['status'],
                $r['cancelled_reason'] ?? '',
                $r['payment_status'] ?? 'unpaid',
                $r['payment_amount'] ?? '',
                $r['payment_method'] ?? '',
                $r['payment_reference'] ?? '',
                $r['receipt_number'] ?? '',
                $r['paid_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="park-reservations-' . $stamp . '.xls"');
        header('Cache-Control: no-store');

        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        echo '<Worksheet ss:Name="Reservations"><Table>' . "\n";

        $headers = ['ID','Event','Facility','Reserved To','Start','End','Status',
                    'Cancelled Reason','Payment Status','Amount','Method',
                    'Reference','Receipt No.','Paid At'];
        echo '<Row>';
        foreach ($headers as $h) {
            echo '<Cell><Data ss:Type="String">' . $esc($h) . '</Data></Cell>';
        }
        echo '</Row>' . "\n";

        foreach ($reservations as $r) {
            $row = [
                $r['id'], $r['event_name'], $r['facility_name'], $r['user_name'],
                $r['start_datetime'], $r['end_datetime'], $r['status'],
                $r['cancelled_reason'] ?? '',
                $r['payment_status'] ?? 'unpaid',
                $r['payment_amount'] ?? '',
                $r['payment_method'] ?? '',
                $r['payment_reference'] ?? '',
                $r['receipt_number'] ?? '',
                $r['paid_at'] ?? '',
            ];
            echo '<Row>';
            foreach ($row as $i => $v) {
                $type = ($i === 9 && is_numeric($v) && $v !== '') ? 'Number' : 'String';
                echo '<Cell><Data ss:Type="' . $type . '">' . $esc($v) . '</Data></Cell>';
            }
            echo '</Row>' . "\n";
        }
        echo '</Table></Worksheet></Workbook>';
        exit;
    }

    if ($format === 'pdf') {
        // Render a print-optimized HTML page. The browser's print dialog
        // lets the user "Save as PDF" — no external library required.
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        // Human-readable filter summary
        $facilityLabel = 'All Facilities';
        if (!empty($filters['facility_id'])) {
            $f = $parkService->getFacility((int)$filters['facility_id']);
            if ($f) {
                $facilityLabel = $f['name'];
            }
        }

        $activeFilters = [];
        $activeFilters[] = 'Facility: ' . $facilityLabel;
        if (!empty($filters['status'])) {
            $activeFilters[] = 'Status: ' . ucfirst($filters['status']);
        }
        if (!empty($filters['payment_status'])) {
            $activeFilters[] = 'Payment: ' . ucfirst($filters['payment_status']);
        }
        if (!empty($filters['date_from'])) {
            $activeFilters[] = 'From: ' . date('M j, Y', strtotime($filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $activeFilters[] = 'To: ' . date('M j, Y', strtotime($filters['date_to']));
        }

        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Parks & Recreation';
        $totalAmount = 0.0;
        foreach ($reservations as $r) {
            if (($r['payment_status'] ?? '') === 'paid' && !empty($r['payment_amount'])) {
                $totalAmount += (float)$r['payment_amount'];
            }
        }
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Park Reservations Report — <?= htmlspecialchars(date('M j, Y')) ?></title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            color: #1e293b;
            margin: 0;
            font-size: 10.5px;
            line-height: 1.4;
        }
        header {
            border-bottom: 2px solid #0f5c73;
            padding-bottom: 10px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        header h1 {
            color: #0f5c73;
            margin: 0;
            font-size: 18px;
            font-weight: 800;
        }
        header .meta {
            font-size: 10px;
            color: #64748b;
            text-align: right;
        }
        header .meta strong { color: #1e293b; }

        .filters {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: 10px;
            color: #475569;
        }
        .filters strong { color: #0f5c73; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }
        thead {
            background: #0f5c73;
            color: #ffffff;
        }
        thead th {
            padding: 7px 8px;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            font-size: 8.5px;
        }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        tbody tr:last-child td { border-bottom: 1px solid #94a3b8; }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 8.5px;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge-pending   { background: #fef3c7; color: #92400e; }
        .badge-approved  { background: #d1fae5; color: #065f46; }
        .badge-completed { background: #dbeafe; color: #1e40af; }
        .badge-cancelled { background: #e2e8f0; color: #475569; }
        .badge-rejected  { background: #ffe4e6; color: #9f1239; }

        .amount { font-weight: 700; color: #0f5c73; white-space: nowrap; }

        .totals {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid #cbd5e1;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #475569;
        }
        .totals strong { color: #0f5c73; font-size: 12px; }

        footer {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px dashed #cbd5e1;
            font-size: 8.5px;
            color: #94a3b8;
            text-align: center;
        }

        @media print {
            .no-print { display: none !important; }
            body { font-size: 9.5px; }
        }

        .toolbar {
            position: fixed;
            top: 12px;
            right: 12px;
            display: flex;
            gap: 8px;
            z-index: 100;
        }
        .toolbar button, .toolbar a {
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
        }
        .toolbar .primary { background: #0f5c73; color: #fff; }
        .toolbar .secondary { background: #e2e8f0; color: #334155; }
    </style>
</head>
<body>

    <div class="toolbar no-print">
        <button class="primary" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <a class="secondary" href="pdb.php">Close</a>
    </div>

    <header>
        <div>
            <h1><?= htmlspecialchars($siteName) ?></h1>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Park Reservations Report</div>
        </div>
        <div class="meta">
            Generated: <strong><?= date('F j, Y \a\t g:i A') ?></strong><br>
            Total records: <strong><?= count($reservations) ?></strong>
        </div>
    </header>

    <div class="filters">
        <strong>Filters applied:</strong> <?= htmlspecialchars(implode(' • ', $activeFilters)) ?>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:5%">#</th>
                <th style="width:16%">Event</th>
                <th style="width:12%">Facility</th>
                <th style="width:13%">Reserved To</th>
                <th style="width:13%">Start</th>
                <th style="width:13%">End</th>
                <th style="width:9%">Status</th>
                <th style="width:9%">Payment</th>
                <th style="width:10%">Receipt / Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($reservations): ?>
                <?php foreach ($reservations as $r): ?>
                    <?php
                    $isExpired  = ($r['status'] === 'cancelled' && ($r['cancelled_reason'] ?? '') === 'expired');
                    $statusText = $isExpired ? 'Auto-cancelled' : ucfirst($r['status']);
                    $statusKey  = $r['status'] ?: 'pending';
                    $pStatus    = $r['payment_status'] ?? 'unpaid';
                    $pBadge     = $pStatus === 'paid' ? 'approved' : ($pStatus === 'refunded' ? 'cancelled' : 'pending');
                    ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars($r['event_name']) ?></td>
                        <td><?= htmlspecialchars($r['facility_name']) ?></td>
                        <td><?= htmlspecialchars((string)$r['user_name']) ?></td>
                        <td><?= date('M d, Y H:i', strtotime($r['start_datetime'])) ?></td>
                        <td><?= date('M d, Y H:i', strtotime($r['end_datetime'])) ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars($statusKey) ?>"><?= $statusText ?></span></td>
                        <td><span class="badge badge-<?= $pBadge ?>"><?= ucfirst($pStatus) ?></span></td>
                        <td>
                            <?php if (!empty($r['receipt_number'])): ?>
                                <div style="font-family:monospace;font-size:8.5px;color:#64748b;"><?= htmlspecialchars($r['receipt_number']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($r['payment_amount']) && (float)$r['payment_amount'] > 0): ?>
                                <div class="amount">₱<?= number_format((float)$r['payment_amount'], 2) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:24px;color:#94a3b8;">
                        No reservations match the selected filters.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals">
        <span>
            Total reservations: <strong><?= count($reservations) ?></strong>
        </span>
        <span>
            Total amount collected: <strong>₱<?= number_format($totalAmount, 2) ?></strong>
        </span>
    </div>

    <footer>
        This report is system-generated. Amounts reflect only reservations marked as <em>Paid</em>.
    </footer>

    <script>
        // Auto-open the print dialog once the page finishes rendering.
        // The user can then choose "Save as PDF" from their browser's print dialog.
        window.addEventListener('load', () => {
            setTimeout(() => window.print(), 300);
        });
    </script>
</body>
</html>
        <?php
        exit;
    }
}

/* ------------------------------------------------------------------ */
/* Dashboard data                                                      */
/* ------------------------------------------------------------------ */
$stats      = $parkService->getStats();
$facilities = $parkService->getFacilities();

/* Base query string for links, preserving active filters + range */
$baseQuery = $_GET;
unset($baseQuery['export']);

$buildUrl = function (array $overrides) use ($baseQuery) {
    return '?' . http_build_query(array_merge($baseQuery, $overrides));
};
$csvUrl   = $buildUrl(['export' => 'csv']);
$excelUrl = $buildUrl(['export' => 'excel']);
$pdfUrl   = $buildUrl(['export' => 'pdf']);

$statusMeta = [
    'pending'   => ['label' => 'Pending',   'color' => '#f59e0b'],
    'approved'  => ['label' => 'Approved',  'color' => '#10b981'],
    'completed' => ['label' => 'Completed', 'color' => '#3b82f6'],
    'cancelled' => ['label' => 'Cancelled', 'color' => '#94a3b8'],
    'rejected'  => ['label' => 'Rejected',  'color' => '#f43f5e'],
];
$statusTotal = max(1, (int)$statusBreakdown['_total']); // avoid /0

$pageTitle = 'Parks & Recreation Dashboard';
include $basePath . 'includes/header.php';
include $basePath . 'includes/sidebar.php';
?>

<div class="flex-1 p-6 lg:p-8">
    <div class="max-w-7xl mx-auto">
        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight mb-6">
            <i class="fa-solid fa-tree text-brand-medium mr-3"></i>Parks & Recreation
        </h1>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Active Facilities</p>
                <p class="text-2xl font-black text-slate-800 dark:text-white mt-1"><?= $stats['active_facilities'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Today's Reservations</p>
                <p class="text-2xl font-black text-slate-800 dark:text-white mt-1"><?= $stats['today_reservations'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Pending Approvals</p>
                <p class="text-2xl font-black text-amber-600 dark:text-amber-400 mt-1"><?= $stats['pending_approvals'] ?></p>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Upcoming Events (7 days)</p>
                <p class="text-2xl font-black text-brand-dark dark:text-brand-medium mt-1"><?= $stats['upcoming_events'] ?></p>
            </div>
        </div>

        <!-- ============ CHART + BREAKDOWN ROW ============ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

            <!-- Trend card -->
            <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm flex flex-col">
                <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                            <i class="fa-solid fa-chart-line text-brand-medium text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-800 dark:text-white leading-tight">Reservations Submitted</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Trend of reservations over time</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Range tabs -->
                        <div class="inline-flex bg-slate-100 dark:bg-slate-800 rounded-lg p-0.5">
                            <?php foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $key => $label): ?>
                                <a href="<?= htmlspecialchars($buildUrl(['range' => $key])) ?>"
                                   class="px-3 py-1 text-xs font-bold rounded-md transition
                                   <?= $range === $key
                                       ? 'bg-brand-dark text-white shadow-sm'
                                       : 'text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700' ?>">
                                    <?= $label ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="p-5 flex-1">
                    <div class="h-64"><canvas id="trendChart"></canvas></div>
                </div>

                <!-- Legend -->
                <div class="px-5 pb-3 flex flex-wrap items-center gap-5 text-xs">
                    <span class="inline-flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full" style="background:#0f5c73"></span>
                        <span class="font-bold text-slate-700 dark:text-slate-300">Reservations</span>
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full" style="background:#8b5cf6"></span>
                        <span class="font-bold text-slate-700 dark:text-slate-300">Cancelled</span>
                    </span>
                </div>

                <!-- Footer totals -->
                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                    <span>
                        Total for selected period:
                        <strong class="text-slate-800 dark:text-white"><?= (int)$trend['total'] ?></strong> reservations
                        &bull;
                        <strong class="text-slate-800 dark:text-white"><?= (int)$trend['total_cancel'] ?></strong> cancelled
                    </span>
                    <span class="font-medium"><?= htmlspecialchars($trend['period_date']) ?></span>
                </div>
            </div>

            <!-- Status breakdown card -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm flex flex-col">
                <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-start justify-between">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                            <i class="fa-solid fa-layer-group text-brand-medium text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold tracking-wider text-slate-700 dark:text-slate-200 uppercase">Status Breakdown</h2>
                        </div>
                    </div>
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400 whitespace-nowrap">
                        <?= (int)$statusBreakdown['_total'] ?> total
                    </span>
                </div>

                <div class="p-5 flex-1 flex flex-col">
                    <!-- Status rows -->
                    <div class="space-y-3.5">
                        <?php foreach ($statusMeta as $key => $meta):
                            $count = (int)($statusBreakdown[$key] ?? 0);
                            $pct   = (int)round(($count / $statusTotal) * 100);
                        ?>
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-300">
                                        <span class="w-2.5 h-2.5 rounded-full" style="background:<?= $meta['color'] ?>"></span>
                                        <?= htmlspecialchars($meta['label']) ?>
                                    </span>
                                    <span class="text-sm font-bold text-slate-800 dark:text-white">
                                        <?= $count ?> <span class="text-slate-400 dark:text-slate-500 font-medium">(<?= $pct ?>%)</span>
                                    </span>
                                </div>
                                <div class="h-1.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                    <div class="h-full rounded-full transition-all" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Doughnut -->
                    <div class="mt-6 flex-1 flex items-center justify-center min-h-[180px]">
                        <canvas id="statusChart" class="max-h-[220px]"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ FILTER + TABLE ============ -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm mb-6">
            <form method="get" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Facility</label>
                    <select name="facility_id" class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">All</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= (int)$f['id'] ?>" <?= (isset($_GET['facility_id']) && (int)$_GET['facility_id'] === (int)$f['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Status</label>
                    <select name="status" class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">All</option>
                        <?php foreach (['pending','approved','completed','cancelled','rejected'] as $s): ?>
                            <option value="<?= $s ?>" <?= (($_GET['status'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Payment</label>
                    <select name="payment_status" class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">All</option>
                        <option value="unpaid"   <?= (($_GET['payment_status'] ?? '') === 'unpaid')   ? 'selected' : '' ?>>Unpaid</option>
                        <option value="paid"     <?= (($_GET['payment_status'] ?? '') === 'paid')     ? 'selected' : '' ?>>Paid</option>
                        <option value="refunded" <?= (($_GET['payment_status'] ?? '') === 'refunded') ? 'selected' : '' ?>>Refunded</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>"
                           class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>"
                           class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                </div>
                <button type="submit" class="px-4 py-1.5 bg-brand-medium text-white text-sm font-bold rounded-lg hover:bg-brand-dark transition">
                    <i class="fa-solid fa-filter mr-1"></i>Filter
                </button>
                <a href="<?= $basePath ?>pages/parks/pdb.php" class="px-4 py-1.5 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-bold rounded-lg hover:bg-slate-300 dark:hover:bg-slate-600 transition">
                    Clear
                </a>

                <div class="ml-auto relative" id="exportMenu">
                    <button type="button" id="exportToggle"
                            class="px-4 py-1.5 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 transition">
                        <i class="fa-solid fa-download mr-1"></i>Export <i class="fa-solid fa-caret-down ml-1"></i>
                    </button>
                    <div id="exportDropdown"
                         class="hidden absolute right-0 mt-2 w-44 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg z-20 overflow-hidden">
                        <a href="<?= htmlspecialchars($csvUrl) ?>" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                            <i class="fa-solid fa-file-csv text-emerald-600 w-4"></i> CSV (.csv)
                        </a>
                        <a href="<?= htmlspecialchars($excelUrl) ?>" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                            <i class="fa-solid fa-file-excel text-emerald-700 w-4"></i> Excel (.xls)
                        </a>
                        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                            <i class="fa-solid fa-file-pdf text-rose-600 w-4"></i> PDF (.pdf)
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- All reservations -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <h2 class="font-bold text-slate-800 dark:text-white">All Reservations</h2>
                <span class="text-xs font-bold text-slate-400 dark:text-slate-500">
                    <?= count($reservations) ?> record<?= count($reservations) === 1 ? '' : 's' ?>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3 text-left">#</th>
                            <th class="px-6 py-3 text-left">Event</th>
                            <th class="px-6 py-3 text-left">Facility</th>
                            <th class="px-6 py-3 text-left">Reserved To</th>
                            <th class="px-6 py-3 text-left">Start</th>
                            <th class="px-6 py-3 text-left">End</th>
                            <th class="px-6 py-3 text-left">Status</th>
                            <th class="px-6 py-3 text-left">Payment</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if ($reservations): ?>
                            <?php foreach ($reservations as $r): ?>
                                <?php
                                $isExpired   = ($r['status'] === 'cancelled' && ($r['cancelled_reason'] ?? '') === 'expired');
                                $statusText  = $isExpired ? 'Auto-cancelled' : ucfirst($r['status']);
                                $statusClass = match ($r['status']) {
                                    'pending'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                    'approved'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                    'cancelled' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
                                    'rejected'  => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                                    'completed' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                    default     => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                };
                                $pStatus = $r['payment_status'] ?? 'unpaid';
                                $pClass  = match ($pStatus) {
                                    'paid'     => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                    'refunded' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
                                    default    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                };
                                ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition">
                                    <td class="px-6 py-3 font-mono text-slate-600 dark:text-slate-400"><?= (int)$r['id'] ?></td>
                                    <td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($r['event_name']) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($r['facility_name']) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= htmlspecialchars((string)$r['user_name']) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= date('M d, Y H:i', strtotime($r['start_datetime'])) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= date('M d, Y H:i', strtotime($r['end_datetime'])) ?></td>
                                    <td class="px-6 py-3">
                                        <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full <?= $statusClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full <?= $pClass ?>">
                                            <?= ucfirst($pStatus) ?>
                                        </span>
                                        <?php if (!empty($r['payment_amount']) && (float)$r['payment_amount'] > 0): ?>
                                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">₱<?= number_format((float)$r['payment_amount'], 2) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['receipt_number'])): ?>
                                            <div class="text-[10px] font-mono text-slate-400 dark:text-slate-500 mt-0.5"><?= htmlspecialchars($r['receipt_number']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-slate-400 dark:text-slate-500">
                                    No reservations match the selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    /* ---------- Export dropdown ---------- */
    const toggle   = document.getElementById('exportToggle');
    const dropdown = document.getElementById('exportDropdown');
    toggle.addEventListener('click', (e) => { e.stopPropagation(); dropdown.classList.toggle('hidden'); });
    document.addEventListener('click', () => dropdown.classList.add('hidden'));
    dropdown.addEventListener('click', (e) => e.stopPropagation());

    /* ---------- Chart data injected from PHP ---------- */
    const trend = <?= json_encode($trend, JSON_UNESCAPED_UNICODE) ?>;
    const statusData = <?= json_encode($statusBreakdown, JSON_UNESCAPED_UNICODE) ?>;

    const isDark    = document.documentElement.classList.contains('dark')
                   || document.body.classList.contains('dark');
    const gridColor = isDark ? 'rgba(148,163,184,.12)' : 'rgba(148,163,184,.20)';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    Chart.defaults.font.family = "'Segoe UI', Tahoma, Arial, sans-serif";
    Chart.defaults.color = textColor;

    /* ============ Trend line chart ============ */
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendGrad = trendCtx.createLinearGradient(0, 0, 0, 260);
    trendGrad.addColorStop(0, 'rgba(15,92,115,.18)');
    trendGrad.addColorStop(1, 'rgba(15,92,115,0)');

    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trend.labels,
            datasets: [
                {
                    label: 'Reservations',
                    data: trend.series_total,
                    borderColor: '#0f5c73',
                    backgroundColor: trendGrad,
                    fill: true,
                    tension: .35,
                    borderWidth: 1.8,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#0f5c73',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1.5,
                },
                {
                    label: 'Cancelled',
                    data: trend.series_cancel,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'transparent',
                    fill: false,
                    tension: .35,
                    borderWidth: 1.8,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#8b5cf6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1.5,
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: isDark ? '#0f172a' : '#fff',
                    titleColor: isDark ? '#e2e8f0' : '#0f172a',
                    bodyColor: isDark ? '#cbd5e1' : '#334155',
                    borderColor: isDark ? '#334155' : '#e2e8f0',
                    borderWidth: 1,
                    padding: 10,
                    titleFont: { weight: 'bold' }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: textColor,
                        font: { size: 10 },
                        maxRotation: 0,
                        autoSkip: true,
                        autoSkipPadding: 14,
                    },
                    border: { display: false }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor, drawTicks: false },
                    ticks: { color: textColor, font: { size: 10 }, precision: 0, stepSize: 1, padding: 8 },
                    border: { display: false }
                }
            }
        }
    });

    /* ============ Status doughnut ============ */
    const keys   = ['pending', 'approved', 'completed', 'cancelled', 'rejected'];
    const labels = ['Pending', 'Approved', 'Completed', 'Cancelled', 'Rejected'];
    const colors = ['#f59e0b', '#10b981', '#3b82f6', '#94a3b8', '#f43f5e'];
    const values = keys.map(k => statusData[k] || 0);
    const hasData = values.some(v => v > 0);

    new Chart(document.getElementById('statusChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: hasData ? labels : ['No data'],
            datasets: [{
                data: hasData ? values : [1],
                backgroundColor: hasData ? colors : ['#e2e8f0'],
                borderColor: isDark ? '#0f172a' : '#fff',
                borderWidth: 2,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: hasData,
                    backgroundColor: isDark ? '#0f172a' : '#fff',
                    titleColor: isDark ? '#e2e8f0' : '#0f172a',
                    bodyColor: isDark ? '#cbd5e1' : '#334155',
                    borderColor: isDark ? '#334155' : '#e2e8f0',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: (item) => ' ' + item.label + ': ' + item.parsed + ' (' +
                            Math.round((item.parsed / Math.max(1, item.dataset.data.reduce((a, b) => a + b, 0))) * 100) + '%)'
                    }
                }
            }
        }
    });
})();
</script>

<?php include $basePath . 'includes/footer.php'; ?>