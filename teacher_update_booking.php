<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit();
}

$teacher_id = $_SESSION['user_id'];
$booking_id = (int)($_POST['id'] ?? 0);
$attendance = $_POST['status'] ?? '';

if (!$booking_id || !in_array($attendance, ['booked','visited','not attended','canceled'])) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid input']);
    exit();
}

// Update booking attendance
$stmt = $conn->prepare("
    UPDATE bookings
    SET attendance=?, 
        attended_at = CASE WHEN ?='visited' THEN NOW() ELSE attended_at END,
        updated_at = NOW()
    WHERE id=?");
$stmt->bind_param("ssi", $attendance, $attendance, $booking_id);
$ok = $stmt->execute();
$stmt->close();

// Log the action
if ($ok) {
    $log = $conn->prepare("
        INSERT INTO logs (role, user_id, action, log_time)
        VALUES ('teacher', ?, CONCAT('Updated booking #', ?, ' attendance to: ', ?), NOW())");
    $log->bind_param("iis", $teacher_id, $booking_id, $attendance);
    $log->execute();
    $log->close();
}

echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Attendance updated successfully' : 'Error updating attendance']);
$conn->close();
?>