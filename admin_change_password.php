<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: admin_dashboard.php");
    exit();
}

require_once 'db_connect.php';

// Basic admin guard
if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_email'] ?? '') !== 'admin@invento.uz') {
    header("Location: index.php");
    exit();
}

// CSRF
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    die("<script>alert('Invalid CSRF token. Please reload and try again.');history.back();</script>");
}

$email = $_SESSION['admin_email'];
$current = trim($_POST['current'] ?? '');
$new = trim($_POST['new'] ?? '');
$confirm = trim($_POST['confirm'] ?? '');

// Basic validation
if ($new === '' || $current === '' || $confirm === '') {
    die("<script>alert('All fields are required.');history.back();</script>");
}
if ($new !== $confirm) {
    die("<script>alert('New passwords do not match');history.back();</script>");
}
if (strlen($new) < 8) {
    die("<script>alert('New password must be at least 8 characters');history.back();</script>");
}

// Fetch current hash
$stmt = $conn->prepare("SELECT password FROM admins WHERE email=? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($hash);
$found = $stmt->fetch();
$stmt->close();

if (!$found || empty($hash)) {
    die("<script>alert('Admin account not found');history.back();</script>");
}

// Verify current password
if (!password_verify($current, $hash)) {
    die("<script>alert('Current password is incorrect');history.back();</script>");
}

// Update with new hash
$newHash = password_hash($new, PASSWORD_BCRYPT);
$upd = $conn->prepare("UPDATE admins SET password=? WHERE email=?");
$upd->bind_param("ss", $newHash, $email);
$ok = $upd->execute();
$upd->close();

if ($ok) {
    // Optional: log the change
    $log = $conn->prepare("INSERT INTO logs (admin_email, action) VALUES (?, 'Admin changed password')");
    $log->bind_param("s", $email);
    $log->execute();
    $log->close();

    echo "<script>alert('Password changed successfully');window.location.href='admin_dashboard.php';</script>";
    exit();
}

die("<script>alert('Error updating password');history.back();</script>");
?>
