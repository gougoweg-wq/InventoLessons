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
$student_id = intval($_GET['student_id'] ?? 0);

if ($student_id <= 0) {
    die("Invalid student.");
}

/* --- Fetch teacher info --- */
$stmt = $conn->prepare("SELECT name, email FROM teachers WHERE id=? LIMIT 1");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$stmt->bind_result($teacherName, $teacherEmail);
$stmt->fetch();
$stmt->close();

/* --- Fetch student info --- */
$stmt = $conn->prepare("SELECT name, grade, email FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($studentName, $studentGrade, $studentEmail);
$stmt->fetch();
$stmt->close();

/* --- Fetch bookings using user id (safer than using names) --- */
$stmt = $conn->prepare("
  SELECT subject, booking_date,
         COALESCE(NULLIF(status,''), NULLIF(attendance,''), 'booked') AS status,
         teacher_comment
  FROM bookings WHERE user_id=? ORDER BY booking_date ASC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* --- Logo --- */
$logo = file_exists('image-removebg-preview.png') ? 'image-removebg-preview.png' : null;

/* --- Build HTML --- */
$html = '
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>
body { font-family: DejaVu Sans, sans-serif; }
.header { text-align:center; margin-bottom:20px; }
.logo { width:80px; }
h2 { color:#2a4d9c; margin:10px 0; }
table { width:100%; border-collapse:collapse; margin-top:10px; }
th, td { border:1px solid #ccc; padding:8px; font-size:12px; }
th { background:#3d73dd; color:white; }
td.comment { font-style:italic; color:#444; }
.footer { margin-top:30px; font-size:12px; text-align:right; }
</style>
</head>
<body>
<div class="header">';
if($logo) $html .= '<img src="'.$logo.'" class="logo"><br>';
$html .= '
<h2>Teacher Summary Report</h2>
<p><strong>Teacher:</strong> '.htmlspecialchars($teacherName).' ('.htmlspecialchars($teacherEmail).')</p>
<p><strong>Student:</strong> '.htmlspecialchars($studentName).' | Grade '.htmlspecialchars($studentGrade).' | '.htmlspecialchars($studentEmail).'</p>
<p><strong>Date:</strong> '.date("d M Y, H:i").'</p>
</div>
<table>
<tr><th>Date</th><th>Subject</th><th>Status</th><th>Teacher Note</th></tr>';
if (!empty($bookings)) {
    foreach ($bookings as $b) {
        $html .= '<tr>
          <td>'.htmlspecialchars($b['booking_date']).'</td>
          <td>'.htmlspecialchars($b['subject']).'</td>
          <td>'.htmlspecialchars(ucfirst($b['status'])).'</td>
          <td class="comment">'.nl2br(htmlspecialchars($b['teacher_comment'])).'</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="4" style="text-align:center;">No bookings found.</td></tr>';
}
$html .= '</table>
<div class="footer">
Signature: _____________________________
</div>
</body>
</html>
';

/* --- Generate PDF with Dompdf if available --- */
try {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        // Dompdf not installed - return HTML fallback (or instruct admin)
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
    require __DIR__ . '/vendor/autoload.php';
    use Dompdf\Dompdf;
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $filename = "Teacher_Report_".preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentName).".pdf";
    $dompdf->stream($filename, ["Attachment" => true]);
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error generating PDF: " . $e->getMessage();
}
exit;
?>
