<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];
$booking_id = (int)($_POST['id'] ?? 0);
$attendance = strtolower(trim($_POST['status'] ?? ''));

// Accept common spellings
$map = [
    'visited' => 'visited',
    'booked' => 'booked',
    'not attended' => 'not attended',
    'not_attended' => 'not attended',
    'canceled' => 'canceled',
    'cancelled' => 'canceled'
];

if (!$booking_id || !isset($map[$attendance])) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid input']);
    exit();
}
$attendance = $map[$attendance];

// Verify booking belongs to this teacher (or is unassigned)
$stmt = $conn->prepare("SELECT teacher_id, user_id FROM bookings WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$res = $stmt->get_result();
$booking = $res->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo json_encode(['ok' => false, 'msg' => 'Booking not found']);
    exit();
}
if (!is_null($booking['teacher_id']) && (int)$booking['teacher_id'] !== $teacher_id) {
    echo json_encode(['ok' => false, 'msg' => 'You are not authorized to update this booking']);
    exit();
}

// Update bookings: try to update both 'attendance' and 'status' columns safely
$ok = true;
$errors = [];

// Update attendance if column exists
$hasAttendance = $conn->query("SHOW COLUMNS FROM bookings LIKE 'attendance'")->num_rows > 0;
$hasStatus = $conn->query("SHOW COLUMNS FROM bookings LIKE 'status'")->num_rows > 0;

if ($hasAttendance) {
    $stmt = $conn->prepare("UPDATE bookings SET attendance = ?, attended_at = CASE WHEN ? = 'visited' THEN NOW() ELSE attended_at END, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $attendance, $attendance, $booking_id);
    $ok = $stmt->execute() && $ok;
    if (!$ok) $errors[] = 'Failed to update attendance';
    $stmt->close();
}

// Also update status column (if different)
if ($hasStatus) {
    $stmt = $conn->prepare("UPDATE bookings SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $attendance, $booking_id);
    $ok = $stmt->execute() && $ok;
    if (!$ok) $errors[] = 'Failed to update status';
    $stmt->close();
}

// If neither column exists, fall back to a generic update (best-effort)
if (!$hasAttendance && !$hasStatus) {
    $stmt = $conn->prepare("UPDATE bookings SET updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $booking_id);
    $ok = $stmt->execute() && $ok;
    if (!$ok) $errors[] = 'No attendance/status column found; no change applied';
    $stmt->close();
}

// Log the action
if ($ok) {
    $log = $conn->prepare("
        INSERT INTO logs (role, user_id, action, log_time)
        VALUES ('teacher', ?, CONCAT('Updated booking #', ?, ' attendance to: ', ?), NOW())");
    $log->bind_param("iis", $teacher_id, $booking_id, $attendance);
    $log->execute();
    $log->close();
}

$conn->close();
echo json_encode(['ok' => (bool)$ok, 'msg' => $ok ? 'Attendance updated successfully' : 'Error updating attendance', 'errors' => $errors]);
?>
