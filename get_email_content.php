<?php
session_start();
include('db_connect.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get email ID
$emailId = $_GET['id'] ?? '';
if (empty($emailId)) {
    echo json_encode(['success' => false, 'error' => 'Email ID required']);
    exit();
}

try {
    // Check if email_logs table exists, if not use teacher_email_logs
    $tableCheck = $conn->query("SHOW TABLES LIKE 'email_logs'");
    $tableName = ($tableCheck->num_rows > 0) ? 'email_logs' : 'teacher_email_logs';
    
    // Fetch email content
    $stmt = $conn->prepare("SELECT id, student_name, student_grade as grade, message, sent_at FROM $tableName WHERE id = ?");
    $stmt->bind_param("i", $emailId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $email = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'id' => $email['id'],
            'student_name' => $email['student_name'],
            'grade' => $email['grade'],
            'message' => $email['message'],
            'sent_at' => $email['sent_at']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email not found']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

$conn->close();
?>
