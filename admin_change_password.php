<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
    header("Location: index.php");
    exit();
}

$email = $_SESSION['admin_email'];
$current = trim($_POST['current'] ?? '');
$new = trim($_POST['new'] ?? '');
$confirm = trim($_POST['confirm'] ?? '');

if ($new !== $confirm) {
    die("<script>alert('❌ New passwords do not match');history.back();</script>");
}

$stmt = $conn->prepare("SELECT password FROM admins WHERE email=? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($hash);
$stmt->fetch();
$stmt->close();

if (!password_verify($current, $hash)) {
    die("<script>alert('❌ Current password is incorrect');history.back();</script>");
}

$newHash = password_hash($new, PASSWORD_BCRYPT);
$upd = $conn->prepare("UPDATE admins SET password=? WHERE email=?");
$upd->bind_param("ss", $newHash, $email);
$upd->execute();
$upd->close();

echo "<script>alert('✅ Password changed successfully');window.location.href='admin_dashboard.php';</script>";
?>
