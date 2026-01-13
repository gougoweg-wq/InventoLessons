<?php
session_start();
include('db_connect.php');
include 'ical.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$icalUrl = "https://calendar.google.com/calendar/ical/c_fa8beeecc764d2836e99bf057540e15f037c8a762be33c3a0a660a1f45862f90%40group.calendar.google.com/private-c61ee269e22d68e2978fcaba1b6868e5/basic.ics";
$events = parseIcsEvents($icalUrl);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $index = intval($_POST['event']);
    $user_id = $_SESSION['user_id'];

    if (isset($events[$index])) {
        $chosen = $events[$index];
        $date = $chosen['start']->format('Y-m-d H:i:s');

        // prevent double booking
        $stmt = $conn->prepare("SELECT id FROM bookings WHERE booking_date=?");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            echo "This slot is already taken!";
            exit();
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO bookings (user_id, subject, booking_date) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $chosen['summary'], $date);
        $stmt->execute();
        $stmt->close();

        header("Location: profile.php?success=1");
        exit();
    } else {
        echo "Invalid slot!";
    }
}
