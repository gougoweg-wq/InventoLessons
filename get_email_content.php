<?php
session_start();
include('db_connect.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$emailId = intval($_GET['id'] ?? 0);
if ($emailId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Email ID required']);
    exit();
}

// Choose the appropriate table
$tableCheck = $conn->query("SHOW TABLES LIKE 'email_logs'");
$tableName = ($tableCheck && $tableCheck->num_rows > 0) ? 'email_logs' : 'teacher_email_logs';

$stmt = $conn->prepare("SELECT id, student_name, student_grade as grade, message, sent_at FROM {$tableName} WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $emailId);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $email = $result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'id' => (int)$email['id'],
        'student_name' => $email['student_name'],
        'grade' => $email['grade'],
        'message' => $email['message'],
        'sent_at' => $email['sent_at']
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Email not found']);
}

$stmt->close();
$conn->close();
?>
