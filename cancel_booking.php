<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

// Allowed roles: student and teacher can cancel (set status), only admin may delete (handled elsewhere)
$role = $_SESSION['role'] ?? null;
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!$role || !in_array($role, ['student','teacher','admin'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['booking_id'])) {
    header("Location: history.php");
    exit();
}

$booking_id = intval($_POST['booking_id']);
if ($booking_id <= 0) {
    header("Location: history.php?error=invalid_id");
    exit();
}

// Fetch booking
$stmt = $conn->prepare("SELECT id, user_id FROM bookings WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$res = $stmt->get_result();
$booking = $res->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: history.php?error=notfound");
    exit();
}

// If admin requested a deletion via this endpoint deny: admin delete should be done in admin panel
if ($role === 'admin') {
    // Redirect admin to admin bookings management
    header("Location: admin_bookings_list.php?info=use_admin_panel");
    exit();
}

// Students can only cancel their own bookings
if ($role === 'student') {
    if ((int)$booking['user_id'] !== $user_id) {
        header("Location: history.php?error=forbidden");
        exit();
    }
    // Perform cancellation: set status + attendance if columns exist
    $hasStatus = $conn->query("SHOW COLUMNS FROM bookings LIKE 'status'")->num_rows > 0;
    $hasAttendance = $conn->query("SHOW COLUMNS FROM bookings LIKE 'attendance'")->num_rows > 0;

    if ($hasStatus) {
        $upd = $conn->prepare("UPDATE bookings SET status='canceled', updated_at=NOW() WHERE id=?");
        $upd->bind_param("i", $booking_id);
        $upd->execute();
        $upd->close();
    }
    if ($hasAttendance) {
        $upd = $conn->prepare("UPDATE bookings SET attendance='canceled', updated_at=NOW() WHERE id=?");
        $upd->bind_param("i", $booking_id);
        $upd->execute();
        $upd->close();
    }
    header("Location: history.php?canceled=1");
    exit();
}

// Teachers may cancel bookings; because bookings table doesn't have teacher_id in this schema,
// we allow teachers to cancel but log the action. If you later add teacher_id column restrict here.
if ($role === 'teacher') {
    $teacher_id = $user_id;

    $hasStatus = $conn->query("SHOW COLUMNS FROM bookings LIKE 'status'")->num_rows > 0;
    $hasAttendance = $conn->query("SHOW COLUMNS FROM bookings LIKE 'attendance'")->num_rows > 0;

    if ($hasStatus) {
        $upd = $conn->prepare("UPDATE bookings SET status='canceled', updated_at=NOW() WHERE id=?");
        $upd->bind_param("i", $booking_id);
        $upd->execute();
        $upd->close();
    }
    if ($hasAttendance) {
        $upd = $conn->prepare("UPDATE bookings SET attendance='canceled', updated_at=NOW() WHERE id=?");
        $upd->bind_param("i", $booking_id);
        $upd->execute();
        $upd->close();
    }

    // Log the teacher cancellation
    $log = $conn->prepare("INSERT INTO booking_history (booking_id, admin_email, action, changes) VALUES (?, ?, ?, ?)");
    $actor = $_SESSION['name'] ?? ('teacher#' . $teacher_id);
    $action = 'teacher_cancel';
    $changes = 'Teacher ' . $actor . ' canceled booking #' . $booking_id;
    $log->bind_param("isss", $booking_id, $actor, $action, $changes);
    // booking_history schema expects admin_email but we provide actor in that field to keep record
    $log->execute();
    $log->close();

    header("Location: history.php?canceled=1");
    exit();
}
