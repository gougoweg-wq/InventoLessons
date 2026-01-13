<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
session_start();
include('db_connect.php');
header('Content-Type: application/json');

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher'){
  echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$id = intval($_POST['id'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if ($id <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid booking ID']); exit;
}
if (strlen($comment) > 2000) {
    echo json_encode(['ok'=>false,'msg'=>'Comment too long (max 2000 chars)']); exit;
}

// Verify booking exists and belongs to this teacher (or is unassigned)
$stmt = $conn->prepare("SELECT teacher_id FROM bookings WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$rs = $stmt->get_result();
$booking = $rs->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo json_encode(['ok'=>false,'msg'=>'Booking not found']); exit;
}
if (!is_null($booking['teacher_id']) && (int)$booking['teacher_id'] !== $teacher_id) {
    echo json_encode(['ok'=>false,'msg'=>'You are not authorized to edit this booking']); exit;
}

// Update booking with teacher comment
$stmt = $conn->prepare("UPDATE bookings SET teacher_comment = ?, updated_at = NOW() WHERE id = ?");
$stmt->bind_param("si", $comment, $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    // Log the action (limit stored comment length in log)
    $snippet = mb_substr($comment, 0, 200);
    $log = $conn->prepare("
        INSERT INTO logs (role, user_id, action, log_time)
        VALUES ('teacher', ?, CONCAT('Updated comment for booking #', ?, ': ', ?), NOW())");
    $log->bind_param("iis", $teacher_id, $id, $snippet);
    $log->execute();
    $log->close();
}

$conn->close();
echo json_encode(['ok'=>$ok, 'msg' => $ok ? 'Comment saved successfully' : 'Error saving comment']);
?>
