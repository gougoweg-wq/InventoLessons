<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);
    $student_id = $_SESSION['user_id'];

    $stmt = $conn->prepare("DELETE FROM bookings WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $booking_id, $student_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        header("Location: history.php?canceled=1");
        exit();
    } else {
        echo "<div style='font-family:sans-serif;text-align:center;margin-top:40px;color:red;'>
        ❌ Invalid booking ID.<br><a href='history.php'>⬅ Go back</a></div>";
    }
} else {
    header("Location: history.php");
    exit();
}
?>
