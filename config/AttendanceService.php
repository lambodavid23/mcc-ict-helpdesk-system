<?php
/**
 * Technician Daily Attendance Service
 * Smart ICT Helpdesk System - Mutare City Council
 * Handles technician clock-in/clock-out and on-duty availability checks.
 */

require_once 'database.php';

class AttendanceService {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->ensureTable();
    }

    /**
     * Ensure the technician_attendance table exists (runs on every request).
     * Makes the feature work on existing installations without manual migration.
     */
    private function ensureTable() {
        $this->conn->query("CREATE TABLE IF NOT EXISTS technician_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            technician_id INT NOT NULL,
            work_date DATE NOT NULL,
            clock_in DATETIME NOT NULL,
            clock_out DATETIME NULL,
            UNIQUE KEY uq_tech_date (technician_id, work_date),
            CONSTRAINT fk_attendance_technician FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
        )");
    }

    /**
     * Get today's attendance record for a technician
     * @param int $tech_id
     * @return array|null
     */
    public function getToday($tech_id) {
        $stmt = $this->conn->prepare("SELECT * FROM technician_attendance 
                                      WHERE technician_id = ? AND work_date = CURDATE() LIMIT 1");
        $stmt->bind_param("i", $tech_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Check if a technician is currently on duty (clocked in, not clocked out)
     * @param int $tech_id
     * @return bool
     */
    public function isOnDuty($tech_id) {
        $row = $this->getToday($tech_id);
        if (!$row) return false;
        return $row['clock_out'] === null;
    }

    /**
     * Clock a technician in for the day.
     * Reuses the day's record if they clock out and back in.
     * @param int $tech_id
     * @return bool
     */
    public function clockIn($tech_id) {
        $existing = $this->getToday($tech_id);
        if ($existing) {
            if ($existing['clock_out'] === null) {
                return false; // already on duty
            }
            $stmt = $this->conn->prepare("UPDATE technician_attendance 
                                          SET clock_in = NOW(), clock_out = NULL 
                                          WHERE id = ?");
            $stmt->bind_param("i", $existing['id']);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected > 0;
        }

        $stmt = $this->conn->prepare("INSERT INTO technician_attendance (technician_id, work_date, clock_in) 
                                      VALUES (?, CURDATE(), NOW())");
        $stmt->bind_param("i", $tech_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

    /**
     * Clock a technician out for the day.
     * @param int $tech_id
     * @return bool
     */
    public function clockOut($tech_id) {
        $existing = $this->getToday($tech_id);
        if (!$existing || $existing['clock_out'] !== null) {
            return false; // not on duty
        }

        $stmt = $this->conn->prepare("UPDATE technician_attendance 
                                      SET clock_out = NOW() WHERE id = ? AND clock_out IS NULL");
        $stmt->bind_param("i", $existing['id']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

    /**
     * Get IDs of technicians currently on duty (available for assignment)
     * @param int|null $exclude_id Skip a specific technician
     * @return array
     */
    public function getOnDutyIds($exclude_id = null) {
        $exclude_id = $exclude_id === null ? null : (int)$exclude_id;
        $sql = "SELECT technician_id FROM technician_attendance 
                WHERE work_date = CURDATE() AND clock_out IS NULL";
        if ($exclude_id !== null) {
            $sql .= " AND technician_id != $exclude_id";
        }
        $result = $this->conn->query($sql);
        $ids = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int)$row['technician_id'];
            }
        }
        return $ids;
    }

    /**
     * Count technicians currently on duty
     * @return int
     */
    public function getOnDutyCount() {
        $result = $this->conn->query("SELECT COUNT(*) as c 
                                      FROM technician_attendance 
                                      WHERE work_date = CURDATE() AND clock_out IS NULL");
        $row = $result->fetch_assoc();
        return (int)$row['c'];
    }

    /**
     * Get attendance for all technicians for a specific date (admin reporting)
     * @param string $date Y-m-d
     * @return array
     */
    public function getAttendanceForDate($date) {
        $date = $this->conn->real_escape_string($date);
        $result = $this->conn->query("
            SELECT t.id, t.name, t.specialization, t.status,
                   ta.clock_in, ta.clock_out,
                   CASE WHEN ta.id IS NULL THEN 'absent'
                        WHEN ta.clock_out IS NULL THEN 'on_duty'
                        ELSE 'clocked_out' END as attendance_status
            FROM technicians t
            LEFT JOIN technician_attendance ta 
                ON ta.technician_id = t.id AND ta.work_date = '$date'
            ORDER BY attendance_status = 'on_duty' DESC, t.name ASC
        ");

        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Get a technician's recent attendance history
     * @param int $tech_id
     * @param int $limit
     * @return array
     */
    public function getRecentHistory($tech_id, $limit = 14) {
        $stmt = $this->conn->prepare(
            "SELECT work_date, clock_in, clock_out, 
                    TIMESTAMPDIFF(MINUTE, clock_in, COALESCE(clock_out, NOW())) as minutes_worked
             FROM technician_attendance 
             WHERE technician_id = ? 
             ORDER BY work_date DESC 
             LIMIT $limit"
        );
        $stmt->bind_param("i", $tech_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
?>