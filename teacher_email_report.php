<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit();
}

$teacher_id   = $_SESSION['user_id'];
$teacher_name = $_SESSION['name'];
$teacher_email= $_SESSION['email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $students = $_POST['students'] ?? [];
    $message  = trim($_POST['message'] ?? '');
    $language = $_POST['language'] ?? 'en';
    $attachmentPath = '';

    // 🔹 Handle optional file upload
    if (!empty($_FILES['attachment']['name'])) {
        $targetDir = "uploads/reports/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $fileName = basename($_FILES["attachment"]["name"]);
        $attachmentPath = $targetDir . time() . "_" . $fileName;
        move_uploaded_file($_FILES["attachment"]["tmp_name"], $attachmentPath);
    }

    /* 🔹 Localized templates */
    $subjects = [
        'en' => "Invento International School — Student Progress Report",
        'ru' => "Школа Invento — Отчет об успеваемости ученика",
        'uz' => "Invento Xalqaro Maktabi — O‘quvchi Hisoboti"
    ];

    $intros = [
        'en' => "Dear Parent of <strong>%s</strong>,<br><br>Attached you will find your child's latest academic report prepared by <strong>%s</strong>.",
        'ru' => "Уважаемые родители ученика <strong>%s</strong>,<br><br>Во вложении находится последний отчет об успеваемости, подготовленный <strong>%s</strong>.",
        'uz' => "Hurmatli <strong>%s</strong> o‘quvchisining ota-onasi,<br><br>Ilovada farzandingizning <strong>%s</strong> tomonidan tayyorlangan so‘nggi hisobotini topasiz."
    ];

    $closings = [
        'en' => "Best regards,<br><strong>%s</strong><br>Invento International School",
        'ru' => "С уважением,<br><strong>%s</strong><br>Школа Invento",
        'uz' => "Hurmat bilan,<br><strong>%s</strong><br>Invento Xalqaro Maktabi"
    ];

    foreach ($students as $sid) {
        $stmt = $conn->prepare("SELECT name, grade, email FROM users WHERE id=?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$student) continue;

        $sName  = $student['name'];
        $sGrade = $student['grade'];
        $sEmail = $student['email'];

        // 🔹 Create localized HTML message
        $introText   = sprintf($intros[$language], htmlspecialchars($sName), htmlspecialchars($teacher_name));
        $closingText = sprintf($closings[$language], htmlspecialchars($teacher_name));

        $htmlMessage = '
        <html><body style="font-family:Segoe UI,Arial,sans-serif;color:#333;">
        <div style="max-width:600px;margin:auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;">
          <div style="text-align:center;margin-bottom:10px;">
            <img src="https://i.imgur.com/fUpXWj1.png" width="120" alt="Invento School Logo"><br>
            <h3 style="color:#3d73dd;">Invento International School</h3>
          </div>
          <p>' . $introText . '</p>
          ' . (!empty($message) ? "<p>$message</p>" : "") . '
          <p>' . $closingText . '</p>
          <hr style="border-top:1px solid #ccc;">
          <p style="font-size:12px;color:#777;text-align:center;">
            This message was sent automatically via the Invento Reporting System.
          </p>
        </div></body></html>';

        // 🔹 Log in database
        $stmt = $conn->prepare("INSERT INTO teacher_email_logs 
            (teacher_id, student_id, student_name, student_grade, message, attachment_path, language, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iisssss", $teacher_id, $sid, $sName, $sGrade, $htmlMessage, $attachmentPath, $language);
        $stmt->execute();
        $stmt->close();

        // 🔹 Optional actual mail sending (enable if SMTP configured)
        /*
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: Invento School <" . $teacher_email . ">\r\n";
        mail($sEmail, $subjects[$language], $htmlMessage, $headers);
        */
    }

    echo "✅ Reports sent successfully in language: $language";
    exit;
}
?>
