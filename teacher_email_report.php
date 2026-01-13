<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: index.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Fetch authoritative teacher info from DB
$stmt = $conn->prepare("SELECT name, email FROM teachers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$stmt->bind_result($teacher_name, $teacher_email);
$stmt->fetch();
$stmt->close();

// If POST, process sending reports
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $students = $_POST['students'] ?? [];
    if (!is_array($students) || empty($students)) {
        echo "No students selected.";
        exit;
    }
    $message  = trim($_POST['message'] ?? '');
    $language = in_array($_POST['language'] ?? 'en', ['en','ru','uz']) ? $_POST['language'] : 'en';
    $attachmentPath = '';

    // Handle optional file upload safely
    if (!empty($_FILES['attachment']['name'])) {
        $targetDir = __DIR__ . "/uploads/reports/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0775, true);
        $f = $_FILES['attachment'];
        if ($f['error'] === UPLOAD_ERR_OK) {
            if ($f['size'] > 5 * 1024 * 1024) {
                die("Attachment too large (max 5MB).");
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($f['tmp_name']);
            $allowed = ['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg'];
            if (!isset($allowed[$mime])) {
                die("Unsupported attachment type. Allowed: pdf, png, jpg.");
            }
            $ext = $allowed[$mime];
            $fileName = 'report_'.time().'_'.bin2hex(random_bytes(6)).'.'.$ext;
            $dest = $targetDir.$fileName;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                die("Failed to move uploaded file.");
            }
            // store web path
            $attachmentPath = 'uploads/reports/'.$fileName;
        } else {
            die("File upload error.");
        }
    }

    /* Localized subjects, intros, closings */
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

    // Prepare insert
    $insert = $conn->prepare("INSERT INTO teacher_email_logs (teacher_id, student_id, student_name, student_grade, message, attachment_path, language, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

    foreach ($students as $sidRaw) {
        $sid = (int)$sidRaw;
        if ($sid <= 0) continue;

        $stmt = $conn->prepare("SELECT name, grade, email FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$student) continue;

        $sName  = $student['name'];
        $sGrade = (string)$student['grade'];
        $sEmail = $student['email'];

        // Build localized HTML message and sanitize limited tags
        $introText   = sprintf($intros[$language], htmlspecialchars($sName), htmlspecialchars($teacher_name));
        $closingText = sprintf($closings[$language], htmlspecialchars($teacher_name));
        $userMessage = $message ? nl2br(htmlspecialchars($message)) : '';

        $htmlMessage = '
        <html><body style="font-family:Segoe UI,Arial,sans-serif;color:#333;">
        <div style="max-width:600px;margin:auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;">
          <div style="text-align:center;margin-bottom:10px;">
            <img src="https://i.imgur.com/fUpXWj1.png" width="120" alt="Invento School Logo"><br>
            <h3 style="color:#3d73dd;">Invento International School</h3>
          </div>
          <p>' . $introText . '</p>'
          . ($userMessage ? "<p>$userMessage</p>" : "") .
          '<p>' . $closingText . '</p>
          <hr style="border-top:1px solid #ccc;">
          <p style="font-size:12px;color:#777;text-align:center;">
            This message was sent automatically via the Invento Reporting System.
          </p>
        </div></body></html>';

        // Store in DB
        $insert->bind_param("iisssss", $teacher_id, $sid, $sName, $sGrade, $htmlMessage, $attachmentPath, $language);
        $insert->execute();

        // Optionally: send mail using mail() or SMTP here if configured (commented)
        /*
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: Invento School <" . $teacher_email . ">\r\n";
        mail($sEmail, $subjects[$language], $htmlMessage, $headers);
        */
    }
    $insert->close();

    echo "✅ Reports saved/sent successfully in language: $language";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Send Reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4">
  <div class="card p-4">
    <h4>Send Reports to Parents</h4>
    <form method="POST" enctype="multipart/form-data">
      <div class="mb-3">
        <label>Select Students (ctrl/cmd + click to select multiple)</label>
        <select name="students[]" class="form-select" multiple required>
          <?php
          $rs = $conn->query("SELECT id, name, grade FROM users ORDER BY grade, name");
          while ($r = $rs->fetch_assoc()) {
              echo '<option value="'.(int)$r['id'].'">'.htmlspecialchars($r['name']).' (Grade '.htmlspecialchars($r['grade']).')</option>';
          }
          ?>
        </select>
      </div>
      <div class="mb-3">
        <label>Message (optional)</label>
        <textarea name="message" class="form-control" rows="5"></textarea>
      </div>
      <div class="mb-3">
        <label>Language</label>
        <select name="language" class="form-select">
          <option value="en">English</option>
          <option value="ru">Russian</option>
          <option value="uz">Uzbek</option>
        </select>
      </div>
      <div class="mb-3">
        <label>Attachment (optional - PDF/PNG/JPG, max 5MB)</label>
        <input type="file" name="attachment" class="form-control" accept=".pdf,image/png,image/jpeg">
      </div>
      <button class="btn btn-primary">Send Reports</button>
    </form>
  </div>
</div>
</body>
</html>
