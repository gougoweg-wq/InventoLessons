<?php
include('db_connect.php');
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $conn->query("DELETE FROM teacher_email_logs WHERE id = $id");
}
header("Location: admin_email_log.php");
exit;
