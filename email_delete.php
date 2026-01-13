<?php
// Admin-only: delete teacher_email_logs entry
session_start();
include('db_connect.php');

if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_email'] ?? '') === '') {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: admin_email_log.php");
    exit();
}

$id = intval($_GET['id']);
if ($id <= 0) {
    header("Location: admin_email_log.php");
    exit();
}

$stmt = $conn->prepare("DELETE FROM teacher_email_logs WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

header("Location: admin_email_log.php");
exit();
?>
