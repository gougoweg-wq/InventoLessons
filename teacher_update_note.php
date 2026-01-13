<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
session_start();
include('db_connect.php');
header('Content-Type: application/json');

if(!isset($_SESSION['user_id']) || $_SESSION['role']!=='teacher'){
  echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

$id=intval($_POST['id']??0);
$comment=trim($_POST['comment']??'');

if($id<=0){ 
    echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); 
    exit; 
}

// Update booking with teacher comment
$stmt=$conn->prepare("UPDATE bookings SET teacher_comment=?, updated_at=NOW() WHERE id=?");
$stmt->bind_param("si",$comment,$id);
$ok=$stmt->execute();
$stmt->close();

// Log the action if successful
if($ok){
    $teacher_id = $_SESSION['user_id'];
    $log = $conn->prepare("
        INSERT INTO logs (role, user_id, action, log_time)
        VALUES ('teacher', ?, CONCAT('Updated comment for booking #', ?, ': ', SUBSTRING(?, 1, 100)), NOW())");
    $log->bind_param("iis", $teacher_id, $id, $comment);
    $log->execute();
    $log->close();
}

$conn->close();
echo json_encode(['ok'=>$ok, 'msg' => $ok ? 'Comment saved successfully' : 'Error saving comment']);
?>