<?php
include('db_connect.php');
session_start();
if(!isset($_SESSION['user_id']) || $_SESSION['role']!=='teacher') die('Access denied');

$id = $_POST['id'] ?? null;
$status = $_POST['status'] ?? null;

if(!$id || !$status) die('Invalid input');

$stmt = $conn->prepare("UPDATE bookings SET attendance=? WHERE id=?");
$stmt->bind_param("si", $status, $id);
$ok = $stmt->execute();
$stmt->close();
echo json_encode(['ok'=>$ok]);
?>
