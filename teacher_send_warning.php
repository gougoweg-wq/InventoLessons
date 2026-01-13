<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('db_connect.php');
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$teacher_id = (int)$_SESSION['user_id'];
$student_id = (int)($_POST['student_id'] ?? 0);
$warning_text = trim($_POST['warning_text'] ?? '');

if (!$student_id || $warning_text === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing required data']);
    exit;
}
if (strlen($warning_text) > 2000) {
    echo json_encode(['ok' => false, 'error' => 'Warning text too long (max 2000 chars)']);
    exit;
}

// Get teacher name (for issued_by)
$stmt = $conn->prepare("SELECT name FROM teachers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$stmt->bind_result($teacherName);
$foundTeacher = $stmt->fetch();
$stmt->close();
if (!$foundTeacher) $teacherName = 'Teacher #'.$teacher_id;

// Get student information for logging & validation
$stmt = $conn->prepare("SELECT name, grade FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$rs = $stmt->get_result();
$student = $rs->fetch_assoc();
$stmt->close();

if (!$student) {
    echo json_encode(['ok' => false, 'error' => 'Student not found']);
    exit;
}

// Insert warning
$stmt = $conn->prepare("
    INSERT INTO student_warnings (student_id, reason, issued_by, issued_by_id, issued_at) 
    VALUES (?, ?, ?, ?, NOW())");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => $conn->error]); exit;
}
$stmt->bind_param("issi", $student_id, $warning_text, $teacherName, $teacher_id);

if ($stmt->execute()) {
    $stmt->close();
    // Log the action
    $log = $conn->prepare("
        INSERT INTO logs (role, user_id, action, log_time)
        VALUES ('teacher', ?, CONCAT('Sent warning to student: ', ?, ' (Grade ', ?, ')'), NOW())");
    $log->bind_param("iss", $teacher_id, $student['name'], $student['grade']);
    $log->execute();
    $log->close();

    echo json_encode(['ok' => true, 'msg' => 'Warning sent successfully']);
} else {
    $err = $stmt->error;
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => $err]);
}
$conn->close();
?>
