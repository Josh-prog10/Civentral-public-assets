<?php
declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;
use RuntimeException;

class ParkService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // -------- Internal: auto-cancel expired pending reservations --------
    private function cancelExpiredPending(): void
    {
        $this->db->exec(
            "UPDATE park_reservations
            SET status = 'cancelled', cancelled_reason = 'expired'
            WHERE status = 'pending'
            AND end_datetime < NOW()"
        );
    }

    // -------- Internal: mark approved reservations as completed once their time ends --------
    private function completeExpiredApproved(): void
    {
        $this->db->exec(
            "UPDATE park_reservations
            SET status = 'completed'
            WHERE status = 'approved'
            AND end_datetime < NOW()"
        );
    }

    // -------- Facilities --------
    public function getFacilities(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM park_facilities";
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= " ORDER BY name";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFacility(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM park_facilities WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function addFacility(string $name, string $description, int $capacity, string $status = 'active'): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO park_facilities (name, description, capacity, status) VALUES (?, ?, ?, ?)"
        );
        return $stmt->execute([$name, $description, $capacity, $status]);
    }

    public function updateFacility(int $id, string $name, string $description, int $capacity, string $status): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE park_facilities SET name=?, description=?, capacity=?, status=? WHERE id=?"
        );
        return $stmt->execute([$name, $description, $capacity, $status, $id]);
    }

    public function deleteFacility(int $id): bool
    {
        $check = $this->db->prepare("SELECT COUNT(*) FROM park_reservations WHERE facility_id = ?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            return false;
        }
        return $this->db->prepare("DELETE FROM park_facilities WHERE id = ?")->execute([$id]);
    }

    // -------- Reservations --------
    public function getReservations(array $filters = []): array
    {
        $this->cancelExpiredPending();
        $this->completeExpiredApproved();

        $sql = "SELECT r.*, f.name as facility_name
                FROM park_reservations r
                JOIN park_facilities f ON r.facility_id = f.id";
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "r.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['facility_id'])) {
            $where[] = "r.facility_id = ?";
            $params[] = (int)$filters['facility_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['payment_status'])) {
            $where[] = "r.payment_status = ?";
            $params[] = $filters['payment_status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "r.start_datetime >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "r.end_datetime <= ?";
            $params[] = $filters['date_to'];
        }

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY r.start_datetime DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getReservation(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, f.name as facility_name
            FROM park_reservations r
            JOIN park_facilities f ON r.facility_id = f.id
            WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function addReservation(
        int $facilityId,
        ?int $userId,
        string $userName,
        string $eventName,
        string $description,
        string $start,
        string $end
    ): bool {
        $this->cancelExpiredPending();
        $this->completeExpiredApproved();

        $sql = "SELECT COUNT(*) FROM park_reservations
                WHERE facility_id = ?
                AND status NOT IN ('cancelled', 'rejected')
                AND (start_datetime < ? AND end_datetime > ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$facilityId, $end, $start]);
        if ($stmt->fetchColumn() > 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO park_reservations
                (facility_id, user_id, user_name, event_name, description, start_datetime, end_datetime, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        return $stmt->execute([$facilityId, $userId, $userName, $eventName, $description, $start, $end]);
    }

    public function updateReservationStatus(int $id, string $status): bool
    {
        $allowed = ['pending', 'approved', 'cancelled', 'rejected', 'completed'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        // Fetch the current row so we can validate the transition
        $check = $this->db->prepare(
            "SELECT status, end_datetime, payment_status
            FROM park_reservations
            WHERE id = ?"
        );
        $check->execute([$id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        // Terminal states: a cancelled (manual or auto-expired), rejected,
        // or completed reservation can no longer be modified.
        if (in_array($row['status'], ['cancelled', 'rejected', 'completed'], true)) {
            return false;
        }

        // Block approving a reservation whose event window has fully passed
        // or which has not been paid yet.
        if ($status === 'approved') {
            if (strtotime((string)$row['end_datetime']) < time()) {
                return false;
            }
            if (($row['payment_status'] ?? 'unpaid') !== 'paid') {
                return false;
            }
        }

        if ($status === 'cancelled') {
            $stmt = $this->db->prepare(
                "UPDATE park_reservations
                SET status = ?, cancelled_reason = 'manual'
                WHERE id = ?"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE park_reservations SET status = ? WHERE id = ?"
            );
        }
        return $stmt->execute([$status, $id]);
    }

    public function deleteReservation(int $id): bool
    {
        return $this->db->prepare("DELETE FROM park_reservations WHERE id = ?")->execute([$id]);
    }

    // -------- Payments & Receipts --------

    /**
     * Record a payment for a park reservation.
     *
     * On success, a still-pending reservation whose event window has not
     * yet passed is automatically flipped to 'approved'.
     *
     * @return array{success:bool, receipt_number:string, message:string}
     */
    public function recordPayment(int $reservationId, float $amount, string $method, string $reference, ?string $notes = null): array
    {
        $allowedMethods = ['cash', 'gcash', 'bank_transfer', 'check', 'other'];

        if ($amount <= 0) {
            return ['success' => false, 'receipt_number' => '', 'message' => 'Amount must be greater than zero.'];
        }
        if (!in_array($method, $allowedMethods, true)) {
            return ['success' => false, 'receipt_number' => '', 'message' => 'Invalid payment method.'];
        }

        $stmt = $this->db->prepare(
            "SELECT id, status, payment_status, end_datetime
             FROM park_reservations
             WHERE id = ?"
        );
        $stmt->execute([$reservationId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            return ['success' => false, 'receipt_number' => '', 'message' => 'Reservation not found.'];
        }
        if (in_array($res['status'], ['cancelled', 'rejected'], true)) {
            return ['success' => false, 'receipt_number' => '', 'message' => 'Cannot record payment for a cancelled or rejected reservation.'];
        }
        if (($res['payment_status'] ?? 'unpaid') === 'paid') {
            return ['success' => false, 'receipt_number' => '', 'message' => 'This reservation has already been paid.'];
        }

        // Block payment on an expired pending reservation that the
        // sweeper hasn't reached yet. Without this, a stale 'pending'
        // row could collect money and then be auto-cancelled on the
        // next page load, leaving a receipt with no valid reservation.
        if ($res['status'] === 'pending'
            && strtotime((string)$res['end_datetime']) < time()) {
            return ['success' => false, 'receipt_number' => '', 'message' => 'Cannot record payment: this reservation has already expired.'];
        }

        $receiptNumber = $this->generateReceiptNumber();

        $stmt = $this->db->prepare(
            "UPDATE park_reservations
            SET payment_status = 'paid',
                payment_amount = ?,
                payment_method = ?,
                payment_reference = ?,
                payment_notes = ?,
                receipt_number = ?,
                paid_at = NOW()
            WHERE id = ?"
        );

        $ok = $stmt->execute([$amount, $method, $reference, $notes, $receiptNumber, $reservationId]);
        if (!$ok) {
            return ['success' => false, 'receipt_number' => '', 'message' => 'Failed to record payment.'];
        }

        // Auto-approve a pending reservation as soon as it's paid,
        // provided the event window hasn't already passed.
        $approve = $this->db->prepare(
            "UPDATE park_reservations
             SET status = 'approved'
             WHERE id = ?
               AND status = 'pending'
               AND end_datetime >= NOW()"
        );
        $approve->execute([$reservationId]);

        $autoApproved = $approve->rowCount() > 0;

        return [
            'success'        => true,
            'receipt_number' => $receiptNumber,
            'message'        => $autoApproved
                ? 'Payment recorded successfully. Reservation auto-approved.'
                : 'Payment recorded successfully.',
        ];
    }

    public function generateReceiptNumber(): string
    {
        do {
            $candidate = 'PRCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM park_reservations WHERE receipt_number = ?");
            $stmt->execute([$candidate]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $candidate;
    }

    public function getReceipt(int $reservationId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, f.name AS facility_name, f.description AS facility_description
            FROM park_reservations r
            JOIN park_facilities f ON r.facility_id = f.id
            WHERE r.id = ?"
        );
        $stmt->execute([$reservationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['receipt_number'])) {
            return null;
        }
        return $row;
    }

    // -------- Availability --------
    public function getAvailability(int $facilityId, string $date): array
    {
        $this->cancelExpiredPending();
        $this->completeExpiredApproved();

        $start = $date . " 00:00:00";
        $end = $date . " 23:59:59";
        $stmt = $this->db->prepare(
            "SELECT r.*
            FROM park_reservations r
            WHERE r.facility_id = ?
            AND r.status NOT IN ('cancelled', 'rejected')
            AND (r.start_datetime <= ? AND r.end_datetime >= ?)
            ORDER BY r.start_datetime"
        );
        $stmt->execute([$facilityId, $end, $start]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------- Dashboard Stats --------
    public function getStats(): array
    {
        $this->cancelExpiredPending();
        $this->completeExpiredApproved();

        $stats = [];

        $stmt = $this->db->query("SELECT COUNT(*) FROM park_facilities WHERE status = 'active'");
        $stats['active_facilities'] = (int)$stmt->fetchColumn();

        $today = date('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM park_reservations WHERE DATE(start_datetime) = ? AND status NOT IN ('cancelled','rejected')"
        );
        $stmt->execute([$today]);
        $stats['today_reservations'] = (int)$stmt->fetchColumn();

        $stmt = $this->db->query("SELECT COUNT(*) FROM park_reservations WHERE status = 'pending'");
        $stats['pending_approvals'] = (int)$stmt->fetchColumn();

        $stmt = $this->db->query("SELECT COUNT(*) FROM park_reservations WHERE status = 'pending' AND payment_status <> 'paid'");
        $stats['pending_payments'] = (int)$stmt->fetchColumn();

        $nextWeek = date('Y-m-d', strtotime('+7 days'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM park_reservations WHERE DATE(start_datetime) BETWEEN ? AND ? AND status = 'approved'"
        );
        $stmt->execute([$today, $nextWeek]);
        $stats['upcoming_events'] = (int)$stmt->fetchColumn();

        return $stats;
    }

    // -------- Dashboard Charts --------

    /**
     * Reservation trend over a named range, bucketed appropriately.
     *
     * @param string   $range      one of: today | week | month | year
     * @param int|null $facilityId optional facility filter
     * @return array{
     *     range:string,
     *     labels:string[],
     *     series_total:int[],
     *     series_cancel:int[],
     *     total:int,
     *     total_cancel:int,
     *     period_label:string,
     *     period_date:string
     * }
     */
    public function getReservationTrend(string $range = 'week', ?int $facilityId = null): array
    {
        $this->cancelExpiredPending();
        $this->completeExpiredApproved();

        $range = in_array($range, ['today', 'week', 'month', 'year'], true) ? $range : 'week';

        $facilitySql    = '';
        $facilityParams = [];
        if ($facilityId !== null && $facilityId > 0) {
            $facilitySql      = ' AND facility_id = ?';
            $facilityParams[] = $facilityId;
        }

        $labels    = [];
        $totals    = [];
        $cancelled = [];

        if ($range === 'today') {
            for ($h = 0; $h < 24; $h++) {
                $key = sprintf('%02d', $h);
                $labels[$key]    = sprintf('%02d:00', $h);
                $totals[$key]    = 0;
                $cancelled[$key] = 0;
            }
            $start = date('Y-m-d 00:00:00');
            $end   = date('Y-m-d 23:59:59');
            $stmt  = $this->db->prepare(
                "SELECT HOUR(start_datetime) AS bucket,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
                 FROM park_reservations
                 WHERE start_datetime BETWEEN ? AND ?" . $facilitySql . "
                 GROUP BY HOUR(start_datetime)"
            );
            $stmt->execute(array_merge([$start, $end], $facilityParams));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = sprintf('%02d', (int)$row['bucket']);
                if (isset($totals[$key])) {
                    $totals[$key]    = (int)$row['total'];
                    $cancelled[$key] = (int)$row['cancelled'];
                }
            }
            $periodLabel = 'Today';
            $periodDate  = date('D, M j, Y');

        } elseif ($range === 'week') {
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $labels[$d]    = date('D', strtotime($d));
                $totals[$d]    = 0;
                $cancelled[$d] = 0;
            }
            $start = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
            $end   = date('Y-m-d 23:59:59');
            $stmt  = $this->db->prepare(
                "SELECT DATE(start_datetime) AS bucket,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
                 FROM park_reservations
                 WHERE start_datetime BETWEEN ? AND ?" . $facilitySql . "
                 GROUP BY DATE(start_datetime)"
            );
            $stmt->execute(array_merge([$start, $end], $facilityParams));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['bucket'];
                if (isset($totals[$key])) {
                    $totals[$key]    = (int)$row['total'];
                    $cancelled[$key] = (int)$row['cancelled'];
                }
            }
            $periodLabel = 'This Week';
            $periodDate  = date('M j', strtotime('-6 days')) . ' – ' . date('M j, Y');

        } elseif ($range === 'month') {
            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $labels[$d]    = date('j', strtotime($d));
                $totals[$d]    = 0;
                $cancelled[$d] = 0;
            }
            $start = date('Y-m-d', strtotime('-29 days')) . ' 00:00:00';
            $end   = date('Y-m-d 23:59:59');
            $stmt  = $this->db->prepare(
                "SELECT DATE(start_datetime) AS bucket,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
                 FROM park_reservations
                 WHERE start_datetime BETWEEN ? AND ?" . $facilitySql . "
                 GROUP BY DATE(start_datetime)"
            );
            $stmt->execute(array_merge([$start, $end], $facilityParams));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['bucket'];
                if (isset($totals[$key])) {
                    $totals[$key]    = (int)$row['total'];
                    $cancelled[$key] = (int)$row['cancelled'];
                }
            }
            $periodLabel = 'This Month';
            $periodDate  = date('M j', strtotime('-29 days')) . ' – ' . date('M j, Y');

        } else { // year
            for ($i = 11; $i >= 0; $i--) {
                $m = date('Y-m', strtotime("-$i months"));
                $labels[$m]    = date('M', strtotime($m . '-01'));
                $totals[$m]    = 0;
                $cancelled[$m] = 0;
            }
            $start = date('Y-m-01', strtotime('-11 months')) . ' 00:00:00';
            $end   = date('Y-m-t 23:59:59');
            $stmt  = $this->db->prepare(
                "SELECT DATE_FORMAT(start_datetime, '%Y-%m') AS bucket,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
                 FROM park_reservations
                 WHERE start_datetime BETWEEN ? AND ?" . $facilitySql . "
                 GROUP BY DATE_FORMAT(start_datetime, '%Y-%m')"
            );
            $stmt->execute(array_merge([$start, $end], $facilityParams));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['bucket'];
                if (isset($totals[$key])) {
                    $totals[$key]    = (int)$row['total'];
                    $cancelled[$key] = (int)$row['cancelled'];
                }
            }
            $periodLabel = 'This Year';
            $periodDate  = date('M Y', strtotime('-11 months')) . ' – ' . date('M Y');
        }

        return [
            'range'         => $range,
            'labels'        => array_values($labels),
            'series_total'  => array_values($totals),
            'series_cancel' => array_values($cancelled),
            'total'         => array_sum($totals),
            'total_cancel'  => array_sum($cancelled),
            'period_label'  => $periodLabel,
            'period_date'   => $periodDate,
        ];
    }

    /**
     * Reservation counts by status, with a total key for the breakdown panel.
     * Optional facility filter keeps the doughnut in sync with the chart.
     *
     * @return array<string,int>  ['pending'=>N, ..., '_total'=>N]
     */
    public function getReservationStatusBreakdown(?int $facilityId = null): array
    {
        $this->cancelExpiredPending();
        $this->completeExpiredApproved();

        $facilitySql = '';
        $params      = [];
        if ($facilityId !== null && $facilityId > 0) {
            $facilitySql = ' WHERE facility_id = ?';
            $params[]    = $facilityId;
        }

        $stmt = $this->db->prepare(
            "SELECT status, COUNT(*) AS c FROM park_reservations" . $facilitySql . " GROUP BY status"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $statuses = ['pending', 'approved', 'completed', 'cancelled', 'rejected'];
        $out      = ['_total' => 0];
        foreach ($statuses as $s) {
            $out[$s]  = (int)($rows[$s] ?? 0);
            $out['_total'] += $out[$s];
        }
        return $out;
    }
}