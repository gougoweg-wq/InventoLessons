<?php
session_start();
include('db_connect.php');

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'teacher') {
        $stmt = $conn->prepare("UPDATE teachers SET remember_token=NULL WHERE id=?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
    } elseif ($_SESSION['role'] === 'student') {
        $stmt = $conn->prepare("UPDATE users SET remember_token=NULL WHERE id=?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
    }
}

setcookie('remember_token', '', time() - 3600, '/');
session_destroy();
header("Location: index.php");
exit();
