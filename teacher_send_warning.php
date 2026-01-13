<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('db_connect.php');
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$teacher_id = $_SESSION['user_id'];
$student_id = (int)($_POST['student_id'] ?? 0);
$warning_text = trim($_POST['warning_text'] ?? '');

if (!$student_id || !$warning_text) {
    echo json_encode(['ok' => false, 'error' => 'Missing required data']);
    exit;
}

// Get student information for logging
$stmt = $conn->prepare("SELECT name, grade FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    echo json_encode(['ok' => false, 'error' => 'Student not found']);
    exit;
}

// Insert warning
$stmt = $conn->prepare("
    INSERT INTO student_warnings (student_id, reason, issued_by, issued_at) 
    VALUES (?, ?, ?, NOW())");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => $conn->error]);
    exit;
}
$stmt->bind_param("iss", $student_id, $warning_text, $teacherName);

if ($stmt->execute()) {
    // Log the action
    $log = $conn->prepare("
        INSERT INTO logs (role, user_id, action, log_time)
        VALUES ('teacher', ?, CONCAT('Sent warning to student: ', ?, ' (Grade ', ?, ')'), NOW())");
    $log->bind_param("iss", $teacher_id, $student['name'], $student['grade']);
    $log->execute();
    $log->close();
    
    echo json_encode(['ok' => true, 'msg' => 'Warning sent successfully']);
} else {
    echo json_encode(['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();
$conn->close();
?>