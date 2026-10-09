<?php
declare(strict_types=1);

$basePath = '../../';

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/LguService.php';

use App\Service\LguService;

$lguService = new LguService($pdo);

if (!$isSuperAdmin && !$hasResourceAccess(['lgu', 'facility'])) {
    header('Location: ' . $basePath . 'pages/dashboard.php?error=access_denied');
    exit;
}

/* ---------------- Range for the graph ---------------- */
$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['today', 'week', 'month', 'year'], true)) {
    $range = 'month';
}

/* ---------------- Filters (shared by table + export) ---------------- */
$filters = [];
if (isset($_GET['facility_id']) && is_numeric($_GET['facility_id']) && (int)$_GET['facility_id'] > 0) {
    $filters['facility_id'] = (int)$_GET['facility_id'];
}
if (isset($_GET['status']) && in_array($_GET['status'], ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true)) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['payment_status']) && in_array($_GET['payment_status'], ['unpaid', 'paid', 'refunded'], true)) {
    $filters['payment_status'] = $_GET['payment_status'];
}
if (!empty($_GET['date_from'])) {
    $filters['start_date_from'] = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to'])) {
    $filters['start_date_to'] = $_GET['date_to'] . ' 23:59:59';
}

$facilityIdForChart = $filters['facility_id'] ?? null;

/* ---------------- Trend + breakdown data ---------------- */
$trend     = $lguService->getReservationsTrendByRange($range, $facilityIdForChart);
$breakdown = $lguService->getStatusBreakdown($range, $facilityIdForChart);

/* ============================================================
   EXPORT HANDLERS  (CSV / XLS / PDF)
   ============================================================ */
$exportType = $_GET['export'] ?? '';
if (in_array($exportType, ['csv', 'xls', 'pdf'], true)) {

    $rows = $lguService->getReservations($filters);

    /* Build a flat, consistent record set for all exporters */
    $records = [];
    foreach ($rows as $r) {
        $records[] = [
            'ID'             => (int)$r['id'],
            'Facility'       => (string)$r['facility_name'],
            'Reserved To'    => (string)$r['user_name'],
            'Purpose'        => (string)$r['purpose'],
            'Start'          => (string)$r['start_datetime'],
            'End'            => (string)$r['end_datetime'],
            'Status'         => (string)$r['status'],
            'Payment Status' => (string)($r['payment_status'] ?? 'unpaid'),
            'Payment Amount' => ($r['payment_amount'] !== null && $r['payment_amount'] !== '')
                ? (float)$r['payment_amount'] : '',
            'Receipt Number' => (string)($r['receipt_number'] ?? ''),
        ];
    }

    $stamp    = date('Ymd_His');
    $baseName = 'lgu_reservations_' . $stamp;

    /* ---------------- CSV ---------------- */
    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        $headers = ['ID','Facility','Reserved To','Purpose','Start','End','Status','Payment Status','Payment Amount','Receipt Number'];
        fputcsv($out, $headers);
        foreach ($records as $rec) {
            fputcsv($out, array_values($rec));
        }
        fclose($out);
        exit;
    }

    /* ---------------- Excel (.xls via HTML table) ---------------- */
    if ($exportType === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo '<html><head><meta charset="utf-8"></head><body>';
        echo '<table border="1" cellspacing="0" cellpadding="4">';
        echo '<thead><tr>';
        foreach (['ID','Facility','Reserved To','Purpose','Start','End','Status','Payment Status','Payment Amount','Receipt Number'] as $h) {
            echo '<th style="background:#0f5c73;color:#fff;font-weight:bold;">' . htmlspecialchars($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($records as $rec) {
            echo '<tr>';
            foreach ($rec as $v) {
                echo '<td>' . htmlspecialchars((string)$v) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    /* ---------------- PDF (print-ready view) ---------------- */
    if ($exportType === 'pdf') {

        /* Build the filter summary */
        $filterSummary = [];
        if (!empty($filters['facility_id'])) {
            $fac = $lguService->getFacility((int)$filters['facility_id']);
            if ($fac) {
                $filterSummary[] = 'Facility: ' . $fac['name'];
            }
        }
        if (!empty($filters['status'])) {
            $filterSummary[] = 'Status: ' . ucfirst($filters['status']);
        }
        if (!empty($filters['payment_status'])) {
            $filterSummary[] = 'Payment: ' . ucfirst($filters['payment_status']);
        }
        if (!empty($_GET['date_from'])) {
            $filterSummary[] = 'From: ' . $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $filterSummary[] = 'To: ' . $_GET['date_to'];
        }

        $siteName = $siteName ?? 'LGU';
        $docTitle = 'LGU Facility Reservations - ' . date('M d, Y');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($docTitle) ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
        color: #1e293b;
        background: #f1f5f9;
        margin: 0;
        padding: 24px;
        font-size: 12px;
    }
    .sheet {
        max-width: 1100px;
        margin: 0 auto;
        background: #fff;
        padding: 32px 28px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        border-radius: 8px;
    }
    .toolbar {
        max-width: 1100px;
        margin: 0 auto 16px;
        display: flex;
        justify-content: space-between;
        gap: 12px;
    }
    .toolbar button, .toolbar a {
        font: inherit;
        font-weight: 700;
        font-size: 13px;
        padding: 9px 16px;
        border-radius: 8px;
        border: none;
        text-decoration: none;
        cursor: pointer;
    }
    .btn-print { background: #0f5c73; color: #fff; }
    .btn-print:hover { background: #0e4f62; }
    .btn-close { background: #e2e8f0; color: #334155; }

    .head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid #0f5c73;
        padding-bottom: 16px;
        margin-bottom: 18px;
    }
    .head .brand {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    .head .mark {
        width: 44px; height: 44px;
        border-radius: 10px;
        background: #0f5c73;
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 900; font-size: 18px;
    }
    .head h1 {
        font-size: 17px;
        margin: 0 0 3px;
        color: #0f5c73;
    }
    .head p {
        margin: 0;
        font-size: 11px;
        color: #64748b;
    }
    .head .meta { text-align: right; font-size: 11px; color: #64748b; }
    .head .meta strong { color: #0f172a; }

    .filters {
        font-size: 11px;
        color: #475569;
        margin-bottom: 14px;
    }
    .filters span {
        display: inline-block;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 2px 10px;
        margin: 0 6px 6px 0;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10.5px;
    }
    thead th {
        background: #0f5c73;
        color: #fff;
        text-align: left;
        padding: 8px 8px;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .04em;
        border: 1px solid #0f5c73;
    }
    tbody td {
        padding: 6px 8px;
        border: 1px solid #e2e8f0;
        vertical-align: top;
    }
    tbody tr:nth-child(even) td { background: #f8fafc; }
    .num { font-family: 'Courier New', monospace; }
    .status-pending   { color: #b45309; font-weight: 700; }
    .status-approved  { color: #047857; font-weight: 700; }
    .status-rejected  { color: #be123c; font-weight: 700; }
    .status-cancelled { color: #475569; font-weight: 700; }
    .status-completed { color: #0369a1; font-weight: 700; }
    .empty { padding: 24px; text-align: center; color: #94a3b8; }

    .foot {
        margin-top: 20px;
        font-size: 10px;
        color: #94a3b8;
        text-align: center;
    }

    @media print {
        body { background: #fff; padding: 0; font-size: 10.5px; }
        .toolbar { display: none !important; }
        .sheet { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
        thead th { background: #0f5c73 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        tbody tr:nth-child(even) td { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @page { size: landscape; margin: 12mm; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a class="btn-close" href="<?= $basePath ?>pages/lgu-facilities/ldb.php">&larr; Back to Dashboard</a>
    <button class="btn-print" type="button" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<div class="sheet">
    <div class="head">
        <div class="brand">
            <div class="mark">LGU</div>
            <div>
                <h1><?= htmlspecialchars($siteName) ?></h1>
                <p>Facility Reservation Report</p>
            </div>
        </div>
        <div class="meta">
            <div>Generated: <strong><?= date('M d, Y H:i') ?></strong></div>
            <div>Total Records: <strong><?= count($records) ?></strong></div>
        </div>
    </div>

    <?php if ($filterSummary): ?>
        <div class="filters">
            <strong>Filters:</strong>
            <?php foreach ($filterSummary as $f): ?>
                <span><?= htmlspecialchars($f) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Facility</th>
                <th>Reserved To</th>
                <th>Purpose</th>
                <th>Start</th>
                <th>End</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Amount</th>
                <th>Receipt #</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($records): ?>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td class="num"><?= (int)$rec['ID'] ?></td>
                        <td><?= htmlspecialchars($rec['Facility']) ?></td>
                        <td><?= htmlspecialchars($rec['Reserved To']) ?></td>
                        <td><?= htmlspecialchars($rec['Purpose']) ?></td>
                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($rec['Start']))) ?></td>
                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($rec['End']))) ?></td>
                        <td class="status-<?= htmlspecialchars($rec['Status']) ?>">
                            <?= htmlspecialchars(ucfirst($rec['Status'])) ?>
                        </td>
                        <td><?= htmlspecialchars(ucfirst($rec['Payment Status'])) ?></td>
                        <td class="num">
                            <?= $rec['Payment Amount'] !== ''
                                ? '₱' . number_format((float)$rec['Payment Amount'], 2)
                                : '—' ?>
                        </td>
                        <td class="num"><?= htmlspecialchars($rec['Receipt Number'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="10" class="empty">No reservations found for the selected filters.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="foot">
        <?= htmlspecialchars($siteName) ?> &nbsp;•&nbsp; Facility Reservation Report &nbsp;•&nbsp; Generated <?= date('F d, Y H:i') ?>
    </div>
</div>

<script>
    // Auto-open the print dialog so the user can save it as a PDF
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 350);
    });
</script>

</body>
</html>
<?php
        exit;
    }
}

$stats        = $lguService->getStats();
$reservations = $lguService->getReservations($filters);
$facilities   = $lguService->getFacilities(true);

/* ---------------- Build helper URLs (keep filters across tabs) -- */
$baseQuery = $_GET;
unset($baseQuery['range'], $baseQuery['export']);

$rangeUrl = function (string $r) use ($baseQuery): string {
    $q = $baseQuery;
    $q['range'] = $r;
    return '?' . http_build_query($q);
};

/* Export URLs — preserve current filters */
$exportBase = $_GET;
unset($exportBase['export']);
$exportUrl = function (string $type) use ($exportBase): string {
    $q = $exportBase;
    $q['export'] = $type;
    return '?' . http_build_query($q);
};

/* ---------------- Range label for the footer ---------------- */
$rangeLabels = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'year' => 'Last 12 months'];

$pageTitle = 'LGU Facility Reservation Dashboard';
include $basePath . 'includes/header.php';
include $basePath . 'includes/sidebar.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="flex-1 p-6 lg:p-8">
    <div class="max-w-7xl mx-auto">
        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight mb-6">
            <i class="fa-solid fa-building-columns text-brand-medium mr-3"></i>LGU Facility Reservation
        </h1>

        <!-- ================= STATS ================= -->
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
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Upcoming (7 days)</p>
                <p class="text-2xl font-black text-brand-dark dark:text-brand-medium mt-1"><?= $stats['upcoming_events'] ?></p>
            </div>
        </div>

        <!-- ================= GRAPH + STATUS BREAKDOWN ================= -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

            <!-- Trend chart card -->
            <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm">
                <div class="px-5 pt-5 pb-3 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-chart-line text-brand-medium"></i>
                            Reservations Submitted
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Trend of reservations over time
                        </p>
                    </div>

                    <!-- Range tabs -->
                    <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden text-xs font-bold">
                        <?php foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $key => $label): ?>
                            <?php $active = ($range === $key); ?>
                            <a href="<?= htmlspecialchars($rangeUrl($key)) ?>"
                               class="px-3 py-1.5 transition <?= $active
                                    ? 'bg-brand-dark text-white'
                                    : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700' ?>">
                                <?= $label ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="px-4 pb-4">
                    <div class="relative" style="height: 260px;">
                        <canvas id="lguReservationsChart"></canvas>
                    </div>
                    <div class="flex items-center justify-between mt-3 text-[11px] text-slate-500 dark:text-slate-400">
                        <span>
                            Total for selected period:
                            <strong class="text-slate-700 dark:text-slate-200"><?= (int)$breakdown['total'] ?></strong> reservation<?= $breakdown['total'] === 1 ? '' : 's' ?>
                            &nbsp;•&nbsp; <?= htmlspecialchars($rangeLabels[$range]) ?>
                        </span>
                        <span><?= date('D, M j, Y') ?></span>
                    </div>
                </div>
            </div>

            <!-- Status breakdown card -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm">
                <div class="px-5 pt-5 pb-3 flex items-center justify-between">
                    <h2 class="text-base font-bold text-slate-800 dark:text-white flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-brand-medium"></i>
                        Status Breakdown
                    </h2>
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        <?= (int)$breakdown['total'] ?> total
                    </span>
                </div>

                <?php
                $statusMeta = [
                    'approved'  => ['label' => 'Approved',  'dot' => '#10b981', 'bar' => 'bg-emerald-500'],
                    'pending'   => ['label' => 'Pending',   'dot' => '#f59e0b', 'bar' => 'bg-amber-500'],
                    'completed' => ['label' => 'Completed', 'dot' => '#0ea5e9', 'bar' => 'bg-sky-500'],
                    'cancelled' => ['label' => 'Cancelled', 'dot' => '#94a3b8', 'bar' => 'bg-slate-400'],
                    'rejected'  => ['label' => 'Rejected',  'dot' => '#f43f5e', 'bar' => 'bg-rose-500'],
                ];
                $total = max(1, (int)$breakdown['total']);
                ?>

                <div class="px-5 space-y-3">
                    <?php foreach ($statusMeta as $key => $meta): ?>
                        <?php
                        $cnt = (int)($breakdown[$key] ?? 0);
                        $pct = $breakdown['total'] > 0 ? round(($cnt / $total) * 100) : 0;
                        ?>
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="flex items-center gap-2 font-semibold text-slate-700 dark:text-slate-300">
                                    <span class="inline-block w-2 h-2 rounded-full" style="background: <?= $meta['dot'] ?>"></span>
                                    <?= htmlspecialchars($meta['label']) ?>
                                </span>
                                <span class="text-slate-500 dark:text-slate-400">
                                    <strong class="text-slate-800 dark:text-white"><?= $cnt ?></strong>
                                    (<?= $pct ?>%)
                                </span>
                            </div>
                            <div class="h-1.5 w-full rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full rounded-full <?= $meta['bar'] ?>" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="px-5 py-5">
                    <div class="relative mx-auto" style="height: 200px; max-width: 220px;">
                        <canvas id="lguStatusDonut"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= FILTERS + EXPORT ================= -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm mb-6">
            <form method="get" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Facility</label>
                    <select name="facility_id" class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">All</option>
                        <?php foreach ($facilities as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= (($filters['facility_id'] ?? null) == $f['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Status</label>
                    <select name="status" class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">All</option>
                        <?php foreach (['pending','approved','rejected','cancelled','completed'] as $s): ?>
                            <option value="<?= $s ?>" <?= (($filters['status'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">Payment</label>
                    <select name="payment_status" class="px-3 py-1.5 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">All</option>
                        <option value="unpaid"   <?= (($filters['payment_status'] ?? '') === 'unpaid')   ? 'selected' : '' ?>>Unpaid</option>
                        <option value="paid"     <?= (($filters['payment_status'] ?? '') === 'paid')     ? 'selected' : '' ?>>Paid</option>
                        <option value="refunded" <?= (($filters['payment_status'] ?? '') === 'refunded') ? 'selected' : '' ?>>Refunded</option>
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
                    <i class="fa-solid fa-filter mr-1"></i> Filter
                </button>

                <a href="<?= $basePath ?>pages/lgu-facilities/ldb.php"
                   class="px-4 py-1.5 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-bold rounded-lg hover:bg-slate-300 dark:hover:bg-slate-600 transition">
                    Clear
                </a>

                <!-- ============ EXPORT DROPDOWN ============ -->
                <div class="relative ml-auto" id="exportWrap">
                    <button type="button" id="exportBtn"
                        class="inline-flex items-center gap-2 px-4 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-bold rounded-lg transition shadow-sm">
                        <i class="fa-solid fa-download"></i>
                        Export
                        <i class="fa-solid fa-chevron-down text-[10px] ml-0.5"></i>
                    </button>

                    <div id="exportMenu"
                         class="hidden absolute right-0 mt-2 w-48 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg z-30 overflow-hidden">
                        <a href="<?= htmlspecialchars($exportUrl('csv')) ?>"
                           class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                            <i class="fa-solid fa-file-csv text-emerald-600"></i>
                            CSV <span class="text-slate-400 text-xs">(.csv)</span>
                        </a>
                        <a href="<?= htmlspecialchars($exportUrl('xls')) ?>"
                           class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                            <i class="fa-solid fa-file-excel text-green-600"></i>
                            Excel <span class="text-slate-400 text-xs">(.xls)</span>
                        </a>
                        <a href="<?= htmlspecialchars($exportUrl('pdf')) ?>"
                           target="_blank" rel="noopener"
                           class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                            <i class="fa-solid fa-file-pdf text-rose-500"></i>
                            PDF <span class="text-slate-400 text-xs">(.pdf)</span>
                        </a>
                    </div>
                </div>
            </form>

            <p class="text-xs text-slate-400 dark:text-slate-500 mt-3">
                Showing <strong><?= count($reservations) ?></strong> reservation<?= count($reservations) === 1 ? '' : 's' ?>.
                The export respects the filters above.
            </p>
        </div>

        <!-- ================= TABLE ================= -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <h2 class="font-bold text-slate-800 dark:text-white">All Reservations</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3 text-left">#</th>
                            <th class="px-6 py-3 text-left">Purpose</th>
                            <th class="px-6 py-3 text-left">Facility</th>
                            <th class="px-6 py-3 text-left">Reserve To</th>
                            <th class="px-6 py-3 text-left">Start</th>
                            <th class="px-6 py-3 text-left">End</th>
                            <th class="px-6 py-3 text-left">Status</th>
                            <th class="px-6 py-3 text-left">Payment</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if ($reservations): ?>
                            <?php foreach ($reservations as $r): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition">
                                    <td class="px-6 py-3 font-mono text-slate-600 dark:text-slate-400"><?= $r['id'] ?></td>
                                    <td class="px-6 py-3 font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($r['purpose']) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($r['facility_name']) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= htmlspecialchars((string)$r['user_name']) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= date('M d, Y H:i', strtotime($r['start_datetime'])) ?></td>
                                    <td class="px-6 py-3 text-slate-600 dark:text-slate-400"><?= date('M d, Y H:i', strtotime($r['end_datetime'])) ?></td>
                                    <td class="px-6 py-3">
                                        <?php
                                        $isExpired  = ($r['status'] === 'cancelled' && ($r['cancelled_reason'] ?? '') === 'expired');
                                        $statusText = $isExpired ? 'Auto-cancelled' : ucfirst($r['status']);
                                        $statusClass = match ($r['status']) {
                                            'pending'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                            'approved'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                            'rejected'  => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                                            'cancelled' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
                                            'completed' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300',
                                            default     => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                        };
                                        ?>
                                        <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full <?= $statusClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <?php
                                        $pStatus = $r['payment_status'] ?? 'unpaid';
                                        $pClass  = match ($pStatus) {
                                            'paid'     => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                            'refunded' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
                                            default    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                        };
                                        ?>
                                        <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full <?= $pClass ?>">
                                            <?= ucfirst($pStatus) ?>
                                        </span>
                                        <?php if (!empty($r['payment_amount']) && (float)$r['payment_amount'] > 0): ?>
                                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">₱<?= number_format((float)$r['payment_amount'], 2) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-slate-400 dark:text-slate-500">
                                    No reservations found for the selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    /* ---------------- Export dropdown toggle ---------------- */
    const exportBtn  = document.getElementById('exportBtn');
    const exportMenu = document.getElementById('exportMenu');
    const exportWrap = document.getElementById('exportWrap');

    if (exportBtn && exportMenu) {
        exportBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            exportMenu.classList.toggle('hidden');
        });
        document.addEventListener('click', (e) => {
            if (!exportWrap.contains(e.target)) {
                exportMenu.classList.add('hidden');
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') exportMenu.classList.add('hidden');
        });
    }

    /* ---------------- Charts ---------------- */
    const isDark      = document.documentElement.classList.contains('dark');
    const gridColor   = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(148,163,184,0.25)';
    const tickColor   = isDark ? '#94a3b8' : '#64748b';
    const legendColor = isDark ? '#e2e8f0' : '#334155';

    const labels   = <?= json_encode($trend['labels']) ?>;
    const total    = <?= json_encode($trend['total']) ?>;
    const approved = <?= json_encode($trend['approved']) ?>;
    const pending  = <?= json_encode($trend['pending']) ?>;

    /* ---------------- Line chart ---------------- */
    const lineCtx = document.getElementById('lguReservationsChart');
    if (lineCtx) {
        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Total',
                        data: total,
                        borderColor: '#0f5c73',
                        backgroundColor: 'rgba(15,92,115,0.10)',
                        borderWidth: 2.5,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 2.5,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#0f5c73',
                        pointBorderColor: '#fff',
                    },
                    {
                        label: 'Approved',
                        data: approved,
                        borderColor: '#10b981',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.35,
                        pointRadius: 2.5,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#fff',
                    },
                    {
                        label: 'Pending',
                        data: pending,
                        borderColor: '#f59e0b',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.35,
                        pointRadius: 2.5,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#f59e0b',
                        pointBorderColor: '#fff',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'start',
                        labels: {
                            color: legendColor,
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            font: { size: 11, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: isDark ? '#0f172a' : '#ffffff',
                        titleColor: isDark ? '#e2e8f0' : '#0f172a',
                        bodyColor: isDark ? '#cbd5e1' : '#334155',
                        borderColor: gridColor,
                        borderWidth: 1,
                        padding: 10,
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor, drawBorder: false },
                        ticks: { color: tickColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 12, font: { size: 10 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor, drawBorder: false },
                        ticks: { color: tickColor, precision: 0, font: { size: 10 } }
                    }
                }
            }
        });
    }

    /* ---------------- Donut chart ---------------- */
    const donutCtx = document.getElementById('lguStatusDonut');
    if (donutCtx) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending', 'Completed', 'Cancelled', 'Rejected'],
                datasets: [{
                    data: [
                        <?= (int)$breakdown['approved'] ?>,
                        <?= (int)$breakdown['pending'] ?>,
                        <?= (int)$breakdown['completed'] ?>,
                        <?= (int)$breakdown['cancelled'] ?>,
                        <?= (int)$breakdown['rejected'] ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#0ea5e9', '#94a3b8', '#f43f5e'],
                    borderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#0f172a' : '#ffffff',
                        titleColor: isDark ? '#e2e8f0' : '#0f172a',
                        bodyColor: isDark ? '#cbd5e1' : '#334155',
                        borderColor: gridColor,
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: (ctx) => {
                                const v   = ctx.parsed || 0;
                                const sum = ctx.dataset.data.reduce((a, b) => a + b, 0) || 1;
                                return ' ' + ctx.label + ': ' + v + ' (' + Math.round((v / sum) * 100) + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
})();
</script>

<?php include $basePath . 'includes/footer.php'; ?>