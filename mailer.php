<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('db_connect.php');

// Only teachers can access
if (!isset($_SESSION['teacher_email'])) {
    header("Location: index.php");
    exit();
}

// Load PHPMailer manually
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Teacher info
$teacherName   = $_SESSION['teacher_name'];
$teacherEmail  = $_SESSION['teacher_email'];
$teacherSubject= $_SESSION['teacher_subject'];
$teacherGrades = $_SESSION['teacher_grades'];

// Parse grade ranges for filtering
$allowedGrades = [];
foreach (explode(",", $teacherGrades) as $range) {
    $range = trim($range);
    if (strpos($range, "-") !== false) {
        list($start, $end) = explode("-", $range);
        for ($i = (int)$start; $i <= (int)$end; $i++) {
            $allowedGrades[] = (string)$i;
        }
    } else {
        $allowedGrades[] = (string)$range;
    }
}

// Function to send email
function sendEmail($to, $studentName, $subjectText, $status, $teacherName) {
    $mail = new PHPMailer(true);
    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'gougoweg@gmail.com';        // your Gmail
        $mail->Password   = 'luffrjveygiailup';          // your App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        //Recipients
        $mail->setFrom('gougoweg@gmail.com', 'School Booking System');
        $mail->addAddress($to, $studentName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = "Booking Status: " . ucfirst($status);
        $mail->Body    = "Hello $studentName,<br><br>"
                       . "Your booking for <b>$subjectText</b> has been <b>$status</b>.<br><br>"
                       . "Regards,<br>$teacherName";

        $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
    }
}

// Handle approve/reject/delete actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    // Get booking info
    $stmt = $conn->prepare("SELECT student_name, student_email, subject FROM bookings WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if ($_GET['action'] === 'approve') {
        $stmt = $conn->prepare("UPDATE bookings SET status='approved' WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        sendEmail($booking['student_email'], $booking['student_name'], $booking['subject'], 'approved', $teacherName);
    } elseif ($_GET['action'] === 'reject') {
        $stmt = $conn->prepare("UPDATE bookings SET status='rejected' WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        sendEmail($booking['student_email'], $booking['student_name'], $booking['subject'], 'rejected', $teacherName);
    } elseif ($_GET['action'] === 'delete') {
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: teachers.php");
    exit();
}