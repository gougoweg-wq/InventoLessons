<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');
require_once __DIR__ . '/status_helpers.php';

/* 🔒 Persistent Login & Access Control */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("SELECT id, name, email, grades, course, avatar FROM teachers WHERE remember_token=? LIMIT 1");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $t = $res->fetch_assoc();
            $_SESSION['user_id'] = $t['id'];
            $_SESSION['role'] = 'teacher';
            $_SESSION['name'] = $t['name'];
            $_SESSION['email'] = $t['email'];
        } else { 
            header("Location: index.php"); 
            exit(); 
        }
        $stmt->close();
    } else { 
        header("Location: index.php"); 
        exit(); 
    }
}

$teacher_id = $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* 🗓️ Timetable Maps */
$gradeMap = [
    '6A'=>'page_01.png','7'=>'page_02.png','8A'=>'page_03.png','9'=>'page_04.png',
    '10'=>'page_05.png','8B'=>'page_21.png','6B'=>'page_23.png'
];
$dpMapRaw = [
    'Afruza Yusufalieva'=>'page_06.png','Matvey Shabashov'=>'page_07.png',
    'Alisherkhoja Kattahadjaev'=>'page_08.png','Nigora Kudratillayeva'=>'page_09.png',
    'Nozimakhon Bakhtiyorova'=>'page_10.png','Ibrahim Khalimov'=>'page_11.png',
    'Laylokhon Valijonova'=>'page_12.png','Maftuna Erkinova'=>'page_13.png',
    'Jasmin Berdiyeva'=>'page_14.png','Muhammad Basel Hassan'=>'page_15.png',
    'Javokhir Rakhmadjonov'=>'page_16.png','Mokhinur Eshmukhamedova'=>'page_17.png',
    'Khonzodakhon Mamurova'=>'page_18.png','Said Al-Barr Akbarov'=>'page_19.png',
    'Odijon Mukhtorov'=>'page_20.png','Sayidbek Komiljonov'=>'page_22.png'
];
$dpMap = [];
foreach ($dpMapRaw as $k => $v) $dpMap[strtolower(trim($k))] = $v;

function extractEmailPreview($htmlMessage, $maxLength = 100) {
    // Remove HTML tags and decode entities
    $text = strip_tags($htmlMessage);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    // Clean up extra whitespace
    $text = preg_replace('/\s+/', ' ', trim($text));
    // Truncate if needed
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength) . '...';
    }
    return $text;
}

/* 👤 Teacher Data */
$stmt = $conn->prepare("SELECT name, email, grades, course, avatar FROM teachers WHERE id=?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$stmt->bind_result($teacherName, $teacherEmail, $teacherGrades, $teacherCourses, $teacherAvatar);
$stmt->fetch();
$stmt->close();
$teacherAvatar = !empty($teacherAvatar) && file_exists($teacherAvatar) ? $teacherAvatar : 'uploads/basic.jpg';


$allowedGrades = [];
if (!empty($teacherGrades)) {
    foreach (preg_split('/[,\s]+/', $teacherGrades) as $part) {
        if (str_contains($part, '-')) {
            [$s,$e] = explode('-', $part);
            for ($i=$s; $i<=$e; $i++) $allowedGrades[]=(string)$i;
        } else $allowedGrades[] = trim($part);
    }
}
$allowedGrades = array_unique(array_filter($allowedGrades));


$teacherSubjects = preg_split('/[\n,]+/', (string)$teacherCourses);
$teacherSubjects = array_values(array_filter(array_map('trim', $teacherSubjects)));
$lowerSubjects = array_map('strtolower', $teacherSubjects);


$filterGrade = $_GET['filter_grade'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';
$filterSubject = $_GET['filter_subject'] ?? '';
$filterStudent = $_GET['filter_student'] ?? '';
$filterDateFrom = $_GET['filter_date_from'] ?? '';
$filterDateTo = $_GET['filter_date_to'] ?? '';

// Fetch filter options
$allStudents = $conn->query("SELECT DISTINCT id, name, grade FROM users WHERE grade IN ('" . implode("','", $allowedGrades) . "') ORDER BY grade, name");
$allSubjects = $conn->query("SELECT DISTINCT subject FROM bookings WHERE subject IN ('" . implode("','", $teacherSubjects) . "') ORDER BY subject");

$bookings = [];
if (!empty($lowerSubjects)) {
    $marks = implode(',', array_fill(0, count($lowerSubjects), '?'));
    $sql = "SELECT b.id, b.user_id AS student_id, b.student_name, b.student_grade, b.subject, b.booking_date, b.attendance, b.teacher_comment, b.status
            FROM bookings b 
            WHERE LOWER(b.subject) IN ($marks)";
    
    $params = $lowerSubjects;
    
    if (!empty($filterGrade)) {
        $sql .= " AND b.student_grade = ?";
        $params[] = $filterGrade;
    }
    if (!empty($filterStatus)) {
        $sql .= " AND b.status = ?";
        $params[] = $filterStatus;
    }
    if (!empty($filterSubject)) {
        $sql .= " AND LOWER(b.subject) LIKE ?";
        $params[] = '%' . strtolower($filterSubject) . '%';
    }
    if (!empty($filterStudent)) {
        $sql .= " AND LOWER(b.student_name) LIKE ?";
        $params[] = '%' . strtolower($filterStudent) . '%';
    }
    if (!empty($filterDateFrom)) {
        $sql .= " AND b.booking_date >= ?";
        $params[] = $filterDateFrom;
    }
    if (!empty($filterDateTo)) {
        $sql .= " AND b.booking_date <= ?";
        $params[] = $filterDateTo;
    }
    
    $sql .= " ORDER BY b.booking_date DESC";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* 👨‍🎓 Students with Advanced Filtering */
$students = [];
if (!empty($allowedGrades)) {
    $p = implode(',', array_fill(0, count($allowedGrades), '?'));
    $sql = "SELECT u.id, u.name, u.grade, u.email, COUNT(b.id) as booking_count
            FROM users u 
            LEFT JOIN bookings b ON u.name = b.student_name 
            WHERE u.grade IN ($p)";
    
    $params = $allowedGrades;
    
    if (!empty($_GET['student_search'])) {
        $sql .= " AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)";
        $searchTerm = '%' . strtolower($_GET['student_search']) . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " GROUP BY u.id ORDER BY u.grade ASC, u.name ASC";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* 📊 Analytics Data */
$bookingStats = [
    'total' => 0,
    'attended' => 0,
    'missed' => 0,
    'canceled' => 0,
    'pending' => 0
];

foreach ($bookings as $booking) {
    $bookingStats['total']++;
    $ns = normalize_status($booking['status'] ?? '', $booking['attendance'] ?? '');
    if ($ns === 'visited') $bookingStats['attended']++;
    elseif ($ns === 'not attended') $bookingStats['missed']++;
    elseif ($ns === 'canceled') $bookingStats['canceled']++;
    else $bookingStats['pending']++;
}

/* 📧 Email Logs */
$emailLogs = [];
$stmt = $conn->prepare("
    SELECT l.*, u.name as student_name, u.grade as student_grade 
    FROM teacher_email_logs l
    LEFT JOIN users u ON l.student_id = u.id
    WHERE l.teacher_id = ?
    ORDER BY l.sent_at DESC LIMIT 20
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$emailLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ⚠️ Recent Warnings */
$recentWarnings = [];
$stmt = $conn->prepare("
    SELECT sw.*, u.name as student_name, u.grade as student_grade
    FROM student_warnings sw
    LEFT JOIN users u ON sw.student_id = u.id
    WHERE sw.issued_by = ?
    ORDER BY sw.issued_at DESC LIMIT 10
");
$stmt->bind_param("s", $teacherName);
$stmt->execute();
$recentWarnings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

// Handle AJAX request for students list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_search'])) {
    header('Content-Type: text/html');
    
    $searchTerm = $_POST['student_search'] ?? '';
    
    // Get filtered students
    $sql = "SELECT u.id, u.name, u.grade, u.email, COUNT(b.id) as booking_count
            FROM users u 
            LEFT JOIN bookings b ON u.name = b.student_name 
            WHERE u.grade IN ($p)";
    
    $params = $allowedGrades;
    $types = str_repeat('s', count($allowedGrades));
    
    if (!empty($searchTerm)) {
        $sql .= " AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)";
        $search = '%' . strtolower($searchTerm) . '%';
        $params[] = $search;
        $params[] = $search;
        $types .= "ss";
    }
    
    $sql .= " GROUP BY u.id ORDER BY u.grade ASC, u.name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Output HTML for students
    ob_start();
    if (!empty($students)) {
        foreach ($students as $s) {
            echo '<div class="student-item">';
            echo '<div class="booking-header">';
            echo '<div class="student-info">';
            echo '<div class="student-name">' . htmlspecialchars($s['name']) . '</div>';
            echo '<div class="student-meta">';
            echo 'Grade ' . htmlspecialchars($s['grade']) . ' • ';
            echo htmlspecialchars($s['email']) . ' • ';
            echo $s['booking_count'] . ' bookings';
            echo '</div>';
            echo '</div>';
            echo '<div class="booking-actions">';
            echo '<button class="btn btn-custom btn-warning-custom btn-sm send-warning" data-id="' . $s['id'] . '" data-name="' . htmlspecialchars($s['name']) . '">';
            echo '<i class="fas fa-exclamation-triangle"></i> Warning';
            echo '</button>';
            echo '<a href="teacher_report_pdf.php?student_id=' . $s['id'] . '" target="_blank" class="btn btn-custom btn-success-custom btn-sm">';
            echo '<i class="fas fa-file-pdf"></i> Report';
            echo '</a>';
            echo '<button class="btn btn-custom btn-info-custom btn-sm send-email" data-id="' . $s['id'] . '" data-name="' . htmlspecialchars($s['name']) . '" data-email="' . htmlspecialchars($s['email']) . '">';
            echo '<i class="fas fa-envelope"></i> Email';
            echo '</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
    } else {
        echo '<div class="empty-state">';
        echo '<i class="fas fa-user-slash"></i>';
        echo '<h5>No Students Found</h5>';
        echo '<p>There are no students matching your search.</p>';
        echo '</div>';
    }
    
    $html = ob_get_clean();
    echo $html;
    exit;
}

// Handle AJAX request for recent activity data
if (isset($_GET['load_recent'])) {
    header('Content-Type: text/html');
    
    // Get fresh recent warnings
    $stmt = $conn->prepare("
        SELECT sw.*, u.name as student_name, u.grade as student_grade
        FROM student_warnings sw
        LEFT JOIN users u ON sw.student_id = u.id
        WHERE sw.issued_by = ?
        ORDER BY sw.issued_at DESC LIMIT 10");
    $stmt->bind_param("s", $teacherName);
    $stmt->execute();
    $freshWarnings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get fresh email logs
    $stmt = $conn->prepare("
        SELECT l.*, u.name as student_name, u.grade as student_grade, u.email as student_email
        FROM teacher_email_logs l
        LEFT JOIN users u ON l.student_id = u.id
        WHERE l.teacher_id = ?
        ORDER BY l.sent_at DESC LIMIT 10");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $freshEmails = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Output HTML for warnings
    ob_start();
    if (!empty($freshWarnings)) {
        foreach ($freshWarnings as $w) {
            echo '<div class="recent-item">';
            echo '<div class="recent-time">' . htmlspecialchars($w['issued_at']) . '</div>';
            echo '<div class="recent-content">';
            echo '<strong>' . htmlspecialchars($w['student_name']) . '</strong> (Grade ' . htmlspecialchars($w['student_grade']) . ')';
            echo '<br><small>' . nl2br(htmlspecialchars($w['reason'])) . '</small>';
            echo '</div>';
            echo '</div>';
        }
    } else {
        echo '<div class="empty-state">';
        echo '<i class="fas fa-check-circle"></i>';
        echo '<p>No warnings sent recently.</p>';
        echo '</div>';
    }
    $warningsHtml = ob_get_clean();
    
    // Output HTML for emails
    ob_start();
    if (!empty($freshEmails)) {
        foreach ($freshEmails as $log) {
            echo '<div class="recent-item">';
            echo '<div class="recent-time">' . htmlspecialchars($log['sent_at']) . '</div>';
            echo '<div class="recent-content">';
            echo '<strong>' . htmlspecialchars($log['student_name']) . '</strong> (Grade ' . htmlspecialchars($log['student_grade']) . ')';
            echo '<br><small>' . htmlspecialchars(substr($log['message'], 0, 100)) . '...</small>';
            echo '</div>';
            echo '</div>';
        }
    } else {
        echo '<div class="empty-state">';
        echo '<i class="fas fa-inbox"></i>';
        echo '<p>No emails sent recently.</p>';
        echo '</div>';
    }
    $emailsHtml = ob_get_clean();
    
    // Return JSON with both HTML sections
    echo json_encode([
        'warnings' => $warningsHtml,
        'emails' => $emailsHtml
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Teacher Dashboard - Invento</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/chart.js"></link>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

:root {
  --primary-color: #1a5f7a;
  --primary-dark: #144a5e;
  --primary-light: #2a7a94;
  --secondary-color: #f39c12;
  --success-color: #27ae60;
  --danger-color: #e74c3c;
  --warning-color: #f39c12;
  --info-color: #3498db;
  --light-bg: #f8fafc;
  --white: #ffffff;
  --text-primary: #2d3748;
  --text-secondary: #718096;
  --border-color: #e2e8f0;
  --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
  --shadow-md: 0 4px 6px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.06);
  --shadow-lg: 0 10px 25px rgba(0,0,0,0.1), 0 6px 10px rgba(0,0,0,0.08);
  --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body { 
  background: #ffffff;
  font-family: 'Inter', sans-serif;
  min-height: 100vh;
  position: relative;
}

body::before {
  content: '';
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: 
    radial-gradient(circle at 20% 80%, rgba(26, 95, 122, 0.03) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(243, 156, 18, 0.03) 0%, transparent 50%);
  pointer-events: none;
  z-index: -1;
}

.navbar { 
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
  box-shadow: var(--shadow-md);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(255,255,255,0.1);
}

.navbar-brand {
  font-weight: 700;
  font-size: 1.5rem;
  color: var(--white) !important;
  display: flex;
  align-items: center;
  gap: 10px;
}

.navbar-brand i {
  font-size: 1.8rem;
  color: var(--secondary-color);
}

.nav-link {
  color: rgba(255,255,255,0.8) !important;
  font-weight: 500;
  border-radius: 8px;
  transition: all 0.3s ease;
  margin: 0 2px;
}

.nav-link:hover, .nav-link.active {
  background: rgba(255,255,255,0.1);
  color: var(--white) !important;
  transform: translateY(-1px);
}

.main-container {
  padding: 2rem;
  max-width: 1400px;
  margin: 0 auto;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.stat-card {
  background: rgba(255,255,255,0.95);
  backdrop-filter: blur(10px);
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(255,255,255,0.2);
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: var(--shadow-xl);
}

.stat-icon {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  margin-bottom: 1rem;
  color: var(--white);
}

.stat-icon.primary { background: linear-gradient(135deg, var(--primary-color), var(--primary-light)); }
.stat-icon.success { background: linear-gradient(135deg, var(--success-color), #2ecc71); }
.stat-icon.warning { background: linear-gradient(135deg, var(--warning-color), #e67e22); }
.stat-icon.danger { background: linear-gradient(135deg, var(--danger-color), #c0392b); }
.stat-icon.info { background: linear-gradient(135deg, var(--info-color), #2980b9); }

.stat-value {
  font-size: 2rem;
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 0.5rem;
}

.stat-label {
  color: var(--text-secondary);
  font-size: 0.9rem;
  font-weight: 500;
}

.profile-card {
  background: rgba(255,255,255,0.95);
  backdrop-filter: blur(10px);
  border-radius: 16px;
  padding: 2rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(255,255,255,0.2);
  margin-bottom: 2rem;
  text-align: center;
}

.avatar {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  object-fit: cover;
  margin-bottom: 1rem;
  border: 4px solid var(--primary-color);
  box-shadow: var(--shadow-lg);
}

.content-card {
  background: rgba(255,255,255,0.95);
  backdrop-filter: blur(10px);
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(255,255,255,0.2);
  margin-bottom: 2rem;
}

.card-header-custom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 2px solid var(--border-color);
}

.card-title {
  font-size: 1.3rem;
  font-weight: 600;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 10px;
}

.card-title i {
  color: var(--primary-color);
}

.filter-section {
  background: var(--light-bg);
  border-radius: 12px;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
  border: 1px solid var(--border-color);
}

.filter-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.booking-item, .student-item {
  background: var(--white);
  border-radius: 12px;
  padding: 1.5rem;
  margin-bottom: 1rem;
  border: 1px solid var(--border-color);
  transition: all 0.3s ease;
  position: relative;
}

.booking-item:hover, .student-item:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
  border-color: var(--primary-color);
}

.booking-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1rem;
}

.student-info {
  flex: 1;
}

.student-name {
  font-weight: 600;
  font-size: 1.1rem;
  color: var(--text-primary);
  margin-bottom: 0.25rem;
}

.student-meta {
  color: var(--text-secondary);
  font-size: 0.9rem;
}

.booking-actions {
  display: flex;
  gap: 0.5rem;
  align-items: flex-start;
}

.btn-custom {
  padding: 0.5rem 1rem;
  border-radius: 8px;
  font-weight: 500;
  border: none;
  transition: all 0.3s ease;
  font-size: 0.9rem;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.btn-custom:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.btn-primary-custom {
  background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
  color: var(--white);
}

.btn-success-custom {
  background: linear-gradient(135deg, var(--success-color), #2ecc71);
  color: var(--white);
}

.btn-warning-custom {
  background: linear-gradient(135deg, var(--warning-color), #e67e22);
  color: var(--white);
}

.btn-danger-custom {
  background: linear-gradient(135deg, var(--danger-color), #c0392b);
  color: var(--white);
}

.btn-info-custom {
  background: linear-gradient(135deg, var(--info-color), #2980b9);
  color: var(--white);
}

.btn-secondary-custom {
  background: linear-gradient(135deg, #6c757d, #5a6268);
  color: var(--white);
}

.form-control-custom, .form-select-custom {
  border: 2px solid var(--border-color);
  border-radius: 8px;
  padding: 0.75rem;
  font-weight: 500;
  transition: all 0.3s ease;
}

.form-control-custom:focus, .form-select-custom:focus {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px rgba(26, 95, 122, 0.1);
  outline: none;
}

.note-box {
  width: 100%;
  min-height: 80px;
  padding: 0.75rem;
  border: 2px solid var(--border-color);
  border-radius: 8px;
  resize: vertical;
  font-family: inherit;
  transition: all 0.3s ease;
}

.note-box:focus {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px rgba(26, 95, 122, 0.1);
  outline: none;
}

.save-msg {
  font-size: 0.8rem;
  color: var(--success-color);
  display: none;
  margin-top: 0.5rem;
  font-weight: 500;
}

.save-msg.show {
  display: block;
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.tab-navigation {
  display: flex;
  background: var(--white);
  border-radius: 12px;
  padding: 0.5rem;
  margin-bottom: 2rem;
  box-shadow: var(--shadow-sm);
  border: 1px solid var(--border-color);
  overflow-x: auto;
}

.tab-btn {
  padding: 0.75rem 1.5rem;
  border: none;
  background: transparent;
  border-radius: 8px;
  font-weight: 500;
  color: var(--text-secondary);
  cursor: pointer;
  transition: all 0.3s ease;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.tab-btn:hover {
  background: var(--light-bg);
  color: var(--text-primary);
}

.tab-btn.active {
  background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
  color: var(--white);
  box-shadow: var(--shadow-sm);
}

.tab-content {
  display: none;
}

.tab-content.active {
  display: block;
  animation: fadeIn 0.3s ease;
}

.recent-item {
  background: var(--white);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 0.75rem;
  border-left: 4px solid var(--primary-color);
  border: 1px solid var(--border-color);
  transition: all 0.3s ease;
}

.recent-item:hover {
  box-shadow: var(--shadow-sm);
  transform: translateX(5px);
}

.recent-time {
  font-size: 0.8rem;
  color: var(--text-secondary);
  margin-bottom: 0.25rem;
}

.recent-content {
  font-size: 0.9rem;
  color: var(--text-primary);
}

.badge-custom {
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.8rem;
  font-weight: 500;
}

.badge-success { background: rgba(39, 174, 96, 0.1); color: var(--success-color); }
.badge-warning { background: rgba(243, 156, 18, 0.1); color: var(--warning-color); }
.badge-danger { background: rgba(231, 76, 60, 0.1); color: var(--danger-color); }
.badge-info { background: rgba(52, 152, 219, 0.1); color: var(--info-color); }

.empty-state {
  text-align: center;
  padding: 3rem;
  color: var(--text-secondary);
}

.empty-state i {
  font-size: 3rem;
  color: var(--border-color);
  margin-bottom: 1rem;
}

@media (max-width: 768px) {
  .main-container {
    padding: 1rem;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  
  .filter-row {
    grid-template-columns: 1fr;
  }
  
  .booking-header {
    flex-direction: column;
    gap: 1rem;
  }
  
  .booking-actions {
    width: 100%;
    justify-content: flex-start;
  }
  
  .tab-navigation {
    display: flex;
    background: var(--white);
    border-radius: 12px;
    padding: 0.5rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
    overflow-x: auto;
  }

  .tab-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    background: transparent;
    border-radius: 8px;
    font-weight: 500;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.3s ease;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .tab-btn:hover {
    background: var(--light-bg);
    color: var(--text-primary);
  }

  .tab-btn.active {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
    color: var(--white);
    box-shadow: var(--shadow-sm);
  }

  .tab-content {
    display: none;
  }

  .tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease;
  }

  .recent-item {
    background: var(--white);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    border-left: 4px solid var(--primary-color);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
  }

  .recent-item:hover {
    box-shadow: var(--shadow-sm);
    transform: translateX(5px);
  }

  .recent-time {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
  }

  .recent-content {
    font-size: 0.9rem;
    color: var(--text-primary);
  }

  .badge-custom {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
  }

  .badge-success { background: rgba(39, 174, 96, 0.1); color: var(--success-color); }
  .badge-warning { background: rgba(243, 156, 18, 0.1); color: var(--warning-color); }
  .badge-danger { background: rgba(231, 76, 60, 0.1); color: var(--danger-color); }
  .badge-info { background: rgba(52, 152, 219, 0.1); color: var(--info-color); }

  .empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--text-secondary);
  }

  .empty-state i {
    font-size: 3rem;
    color: var(--border-color);
    margin-bottom: 1rem;
  }

  .email-item {
    background: var(--white);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
  }

  .email-item:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
    border-color: var(--primary-color);
  }

  .email-header {
    margin-bottom: 0.75rem;
  }

  .email-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
    color: var(--text-secondary);
  }

  .email-time {
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }

  .email-status {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-weight: 500;
  }

  .email-content {
    margin-bottom: 0.5rem;
  }

  .email-recipient {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
  }

  .grade-badge {
    background: rgba(26, 95, 122, 0.1);
    color: var(--primary-color);
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
  }

  .email-preview {
    color: var(--text-secondary);
    font-size: 0.9rem;
    line-height: 1.4;
    margin-bottom: 0.75rem;
    background: var(--light-bg);
    padding: 0.5rem;
    border-radius: 6px;
    border-left: 3px solid var(--primary-color);
  }

  .email-actions {
    display: flex;
    justify-content: flex-end;
  }

  @media (max-width: 768px) {
    .main-container {
      padding: 1rem;
    }
    
    .stats-grid {
      grid-template-columns: 1fr;
      gap: 1rem;
    }
    
    .filter-row {
      grid-template-columns: 1fr;
    }
    
    .booking-header {
      flex-direction: column;
      gap: 1rem;
    }
    
    .booking-actions {
      width: 100%;
      justify-content: flex-start;
    }
    
    .tab-navigation {
      flex-wrap: nowrap;
      overflow-x: auto;
    }
  }
</style>
</head>
<body>
<nav class="navbar navbar-dark px-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">
      <i class="fas fa-graduation-cap"></i>
      Teacher Dashboard
    </a>
    <div class="d-flex align-items-center">
      <div class="dropdown">
        <button class="btn btn-light border-0 dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
          <img src="<?= htmlspecialchars($teacherAvatar) ?>" class="rounded-circle" width="40" height="40" alt="Profile">
          <span class="d-none d-md-block"><?= htmlspecialchars($teacherName) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li><h6 class="dropdown-header">Quick Actions</h6></li>
          <li><a class="dropdown-item" href="teacher_warnings_log.php"><i class="fas fa-exclamation-triangle"></i> Sent Warnings</a></li>
          <li><a class="dropdown-item" href="teacher_email_log.php"><i class="fas fa-envelope"></i> Email Log</a></li>
          
          <li><a class="dropdown-item" href="support.php"><i class="fas fa-headset"></i> Contact Support</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="main-container">
  <!-- Profile Section -->
  <div class="profile-card">
    <img src="<?= htmlspecialchars($teacherAvatar) ?>" class="avatar" alt="Profile">
    <h2><?= htmlspecialchars($teacherName) ?></h2>
    <p class="text-muted mb-2"><i class="fas fa-envelope"></i> <?= htmlspecialchars($teacherEmail) ?></p>
    <div class="row justify-content-center">
      <div class="col-md-6">
        <p class="mb-2"><strong><i class="fas fa-book"></i> Courses:</strong> <?= htmlspecialchars(implode(", ", $teacherSubjects)) ?></p>
        <p class="mb-0"><strong><i class="fas fa-users"></i> Grades:</strong> <?= htmlspecialchars($teacherGrades) ?></p>
      </div>
    </div>
  </div>

  <!-- Statistics -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon primary">
        <i class="fas fa-calendar-check"></i>
      </div>
      <div class="stat-value"><?= $bookingStats['total'] ?></div>
      <div class="stat-label">Total Bookings</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon success">
        <i class="fas fa-user-check"></i>
      </div>
      <div class="stat-value"><?= $bookingStats['attended'] ?></div>
      <div class="stat-label">Attended</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon warning">
        <i class="fas fa-user-times"></i>
      </div>
      <div class="stat-value"><?= $bookingStats['missed'] ?></div>
      <div class="stat-label">Missed</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon info">
        <i class="fas fa-users"></i>
      </div>
      <div class="stat-value"><?= count($students) ?></div>
      <div class="stat-label">Total Students</div>
    </div>
  </div>

  <!-- Tab Navigation -->
  <div class="tab-navigation">
    <button class="tab-btn active" data-tab="bookings">
      <i class="fas fa-calendar"></i> Bookings
    </button>
    <button class="tab-btn" data-tab="students">
      <i class="fas fa-users"></i> Students
    </button>
    <button class="tab-btn" data-tab="recent-activity">
      <i class="fas fa-history"></i> Recent Activity
    </button>
  </div>

  <!-- Bookings Tab -->
  <div class="tab-content active" id="bookings-tab">
    <div class="content-card">
      <div class="card-header-custom">
        <h5 class="card-title">
          <i class="fas fa-calendar-alt"></i>
          Bookings Management
        </h5>
        <div class="d-flex gap-2">
          <button class="btn btn-custom btn-primary-custom btn-sm" onclick="toggleFilters()">
            <i class="fas fa-filter"></i> Filters
          </button>
          <div class="dropdown">
            <button class="btn btn-custom btn-secondary-custom btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="fas fa-sort"></i> Sort
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="#" onclick="sortBookings('student', 'asc')">Student A-Z</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortBookings('student', 'desc')">Student Z-A</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortBookings('grade', 'asc')">Grade Low-High</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortBookings('grade', 'desc')">Grade High-Low</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortBookings('subject', 'asc')">Subject A-Z</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortBookings('date', 'desc')">Newest First</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortBookings('date', 'asc')">Oldest First</a></li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Filters Section -->
      <div class="filter-section" id="filters-section" style="display: none;">
        <form method="GET" class="filter-row" id="bookingFiltersForm">
          <select name="filter_grade" class="form-select form-select-custom">
            <option value="">All Grades</option>
            <?php foreach ($allowedGrades as $grade): ?>
              <option value="<?= $grade ?>" <?= $filterGrade === $grade ? 'selected' : '' ?>>Grade <?= $grade ?></option>
            <?php endforeach; ?>
          </select>
          <select name="filter_status" class="form-select form-select-custom">
            <option value="">All Status</option>
            <option value="visited" <?= $filterStatus === 'visited' ? 'selected' : '' ?>>Visited</option>
            <option value="not attended" <?= $filterStatus === 'not attended' ? 'selected' : '' ?>>Not Attended</option>
            <option value="canceled" <?= $filterStatus === 'canceled' ? 'selected' : '' ?>>Canceled</option>
            <option value="booked" <?= $filterStatus === 'booked' ? 'selected' : '' ?>>Booked</option>
          </select>
          <select name="filter_subject" class="form-select form-select-custom">
            <option value="">All Subjects</option>
            <?php while($subj = $allSubjects->fetch_assoc()): ?>
              <option value="<?= htmlspecialchars($subj['subject']) ?>" <?= $filterSubject === $subj['subject'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($subj['subject']) ?>
              </option>
            <?php endwhile; ?>
          </select>
          <select name="filter_student" class="form-select form-select-custom">
            <option value="">All Students</option>
            <?php while($stu = $allStudents->fetch_assoc()): ?>
              <option value="<?= $stu['id'] ?>" <?= $filterStudent == $stu['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($stu['name']) ?> (Grade <?= $stu['grade'] ?>)
              </option>
            <?php endwhile; ?>
          </select>
          <input type="date" name="filter_date_from" class="form-control form-control-custom" value="<?= htmlspecialchars($filterDateFrom) ?>">
          <input type="date" name="filter_date_to" class="form-control form-control-custom" value="<?= htmlspecialchars($filterDateTo) ?>">
          <button type="submit" class="btn btn-custom btn-primary-custom">Apply</button>
          <a href="teachers.php" class="btn btn-custom btn-secondary-custom">Clear</a>
        </form>
      </div>

      <?php if (!empty($bookings)): ?>
        <?php foreach ($bookings as $b): ?>
          <div class="booking-item">
            <div class="booking-header">
              <div class="student-info">
                <div class="student-name"><?= htmlspecialchars($b['student_name']) ?></div>
                <div class="student-meta">
                  Grade <?= htmlspecialchars($b['student_grade']) ?> • 
                  <?= htmlspecialchars($b['subject']) ?> • 
                  <?= htmlspecialchars($b['booking_date']) ?>
                </div>
              </div>
              <div class="booking-actions">
                <select class="form-select form-select-sm" 
                        data-booking-id="<?= (int)$b['id'] ?>"
                        data-student-id="<?= (int)($b['student_id'] ?? 0) ?>"
                        data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                        style="min-width:160px" id="status-<?= (int)$b['id'] ?>">
                  <?php $ns = normalize_status($b['status'] ?? '', $b['attendance'] ?? ''); ?>
                  <option value="booked" <?= $ns==='booked'?'selected':'' ?>>Booked</option>
                  <option value="visited" <?= $ns==='visited'?'selected':'' ?>>Visited</option>
                  <option value="not attended" <?= $ns==='not attended'?'selected':'' ?>>Not Attended</option>
                  <option value="canceled" <?= $ns==='canceled'?'selected':'' ?>>Canceled</option>
                </select>
                <a class="btn btn-custom btn-success-custom btn-sm" target="_blank" href="teacher_report_pdf.php?student_id=<?= (int)($b['student_id'] ?? 0) ?>"><i class="fas fa-file-pdf"></i> Report</a>
                <a class="btn btn-custom btn-info-custom btn-sm" href="teacher_email_report.php?student_id=<?= (int)($b['student_id'] ?? 0) ?>"><i class="fas fa-envelope"></i> Email</a>
                <a class="btn btn-custom btn-warning-custom btn-sm" href="teacher_warnings_log.php"><i class="fas fa-exclamation-triangle"></i> Warning</a>
              </div>
            </div>
            <div>
              <textarea class="note-box" placeholder="Teacher comment..." data-booking-id="<?= (int)$b['id'] ?>" data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><?= htmlspecialchars($b['teacher_comment'] ?? '') ?></textarea>
              <div class="save-msg" id="savemsg-<?= (int)$b['id'] ?>">Saved</div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-calendar-times"></i>
          <h5>No Bookings Found</h5>
          <p>There are no bookings matching your current filters.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Students Tab -->
  <div class="tab-content" id="students-tab">
    <div class="content-card">
      <div class="card-header-custom">
        <h5 class="card-title">
          <i class="fas fa-users"></i>
          Students Management
        </h5>
        <div class="d-flex gap-2 align-items-center">
          <form method="GET" class="d-flex gap-2" id="studentSearchForm">
            <input type="hidden" name="tab" value="students">
            <input type="text" name="student_search" placeholder="Search students..." class="form-control form-control-custom" value="<?= htmlspecialchars($_GET['student_search'] ?? '') ?>">
            <button type="submit" class="btn btn-custom btn-primary-custom">
              <i class="fas fa-search"></i> Search
            </button>
          </form>
          <div class="dropdown">
            <button class="btn btn-custom btn-secondary-custom dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="fas fa-sort"></i> Sort
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="#" onclick="sortStudents('name', 'asc')">Name A-Z</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortStudents('name', 'desc')">Name Z-A</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortStudents('grade', 'asc')">Grade Low-High</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortStudents('grade', 'desc')">Grade High-Low</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortStudents('bookings', 'desc')">Most Bookings</a></li>
              <li><a class="dropdown-item" href="#" onclick="sortStudents('bookings', 'asc')">Least Bookings</a></li>
            </ul>
          </div>
        </div>
      </div>

      <div id="students-container"><?php if (!empty($students)): ?>
        <?php foreach ($students as $s): ?>
          <div class="student-item">
            <div class="booking-header">
              <div class="student-info">
                <div class="student-name"><?= htmlspecialchars($s['name']) ?></div>
                <div class="student-meta">
                  Grade <?= htmlspecialchars($s['grade']) ?> • 
                  <?= htmlspecialchars($s['email']) ?> • 
                  <?= $s['booking_count'] ?> bookings
                </div>
              </div>
              <div class="booking-actions">
                <button class="btn btn-custom btn-warning-custom btn-sm send-warning" 
                        data-id="<?= $s['id'] ?>" 
                        data-name="<?= htmlspecialchars($s['name']) ?>">
                  <i class="fas fa-exclamation-triangle"></i> Warning
                </button>
                <a href="teacher_report_pdf.php?student_id=<?= $s['id'] ?>" 
                   target="_blank" 
                   class="btn btn-custom btn-success-custom btn-sm">
                  <i class="fas fa-file-pdf"></i> Report
                </a>
                <button class="btn btn-custom btn-info-custom btn-sm send-email" 
                        data-id="<?= $s['id'] ?>" 
                        data-name="<?= htmlspecialchars($s['name']) ?>"
                        data-email="<?= htmlspecialchars($s['email']) ?>">
                  <i class="fas fa-envelope"></i> Email
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-user-slash"></i>
          <h5>No Students Found</h5>
          <p>There are no students assigned to your grades.</p>
        </div>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Activity Tab -->
  <div class="tab-content" id="recent-activity-tab">
    <div class="row">
      <div class="col-md-6">
        <div class="content-card">
          <h5 class="card-title">
            <i class="fas fa-exclamation-triangle"></i>
            Recent Warnings
          </h5>
          <div id="recent-warnings-container">
            <?php if (!empty($recentWarnings)): ?>
              <?php foreach ($recentWarnings as $w): ?>
                <div class="recent-item">
                  <div class="recent-time"><?= htmlspecialchars($w['issued_at']) ?></div>
                  <div class="recent-content">
                    <strong><?= htmlspecialchars($w['student_name']) ?></strong> (Grade <?= htmlspecialchars($w['student_grade']) ?>)
                    <br><small><?= nl2br(htmlspecialchars($w['reason'])) ?></small>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>No warnings sent recently.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="content-card">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title mb-0">
              <i class="fas fa-envelope"></i>
              Recent Emails
            </h5>
            <a href="teacher_email_log.php" class="btn btn-custom btn-info-custom btn-sm">
              <i class="fas fa-list"></i> View All
            </a>
          </div>
          <div id="recent-emails-container">
            <?php if (!empty($emailLogs)): ?>
              <?php foreach ($emailLogs as $log): ?>
                <div class="email-item">
                  <div class="email-header">
                    <div class="email-meta">
                      <span class="email-time">
                        <i class="fas fa-clock"></i>
                        <?= date('M j, Y H:i', strtotime($log['sent_at'])) ?>
                      </span>
                      <span class="email-status">
                        <i class="fas fa-check-circle text-success"></i>
                        Sent
                      </span>
                    </div>
                  </div>
                  <div class="email-content">
                    <div class="email-recipient">
                      <i class="fas fa-user"></i>
                      <strong><?= htmlspecialchars($log['student_name']) ?></strong>
                      <span class="grade-badge">Grade <?= htmlspecialchars($log['student_grade']) ?></span>
                    </div>
                    <div class="email-preview">
                      <?= htmlspecialchars(extractEmailPreview($log['message'], 120)) ?>
                    </div>
                    <div class="email-actions">
                      <button class="btn btn-outline-primary btn-sm" 
                              onclick="viewEmailContent('<?= htmlspecialchars($log['id']) ?>')">
                        <i class="fas fa-eye"></i> View Full
                      </button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h6>No Recent Emails</h6>
                <p>No emails have been sent recently.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Warning Modal -->
<div class="modal fade" id="warningModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title">
          <i class="fas fa-exclamation-triangle"></i>
          Send Warning
        </h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="warningForm">
          <input type="hidden" name="student_id" id="warnStudentId">
          <div class="mb-3">
            <label class="form-label">Student</label>
            <input type="text" class="form-control form-control-custom" id="warnStudentName" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Warning Message</label>
            <textarea name="warning_text" class="form-control form-control-custom" rows="4" 
                      placeholder="Write the reason for warning..."></textarea>
          </div>
          <button type="submit" class="btn btn-custom btn-warning-custom w-100">
            <i class="fas fa-paper-plane"></i> Send Warning
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">
          <i class="fas fa-envelope"></i>
          Send Email Report
        </h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="emailForm">
          <input type="hidden" name="student_id" id="emailStudentId">
          <div class="mb-3">
            <label class="form-label">Student</label>
            <input type="text" class="form-control form-control-custom" id="emailStudentName" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Parent Email</label>
            <input type="email" name="parent_email" class="form-control form-control-custom" 
                   placeholder="parent@example.com" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Message</label>
            <textarea name="message" class="form-control form-control-custom" rows="4" 
                      placeholder="Add a personal message to the parent..."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Language</label>
            <select name="language" class="form-select form-select-custom">
              <option value="en">English</option>
              <option value="ru">Russian</option>
              <option value="uz">Uzbek</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Attachment (Optional)</label>
            <input type="file" name="attachment" class="form-control form-control-custom" accept=".pdf,.doc,.docx">
          </div>
          <button type="submit" class="btn btn-custom btn-info-custom w-100">
            <i class="fas fa-paper-plane"></i> Send Email
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Enhanced table sorting with visual indicators
function makeTableSortable(table){
  const ths = table.querySelectorAll('thead th');
  ths.forEach((th, idx) => {
    // Skip columns with no sortable content (like action buttons)
    if (th.textContent.toLowerCase().includes('action') || 
        th.textContent.toLowerCase().includes('edit') ||
        th.textContent.toLowerCase().includes('delete')) {
      return;
    }
    
    th.style.cursor = 'pointer';
    th.style.position = 'relative';
    th.title = 'Click to sort';
    
    // Add sort indicators
    const indicator = document.createElement('span');
    indicator.style.marginLeft = '8px';
    indicator.style.opacity = '0.3';
    indicator.innerHTML = '↕';
    th.appendChild(indicator);
    
    th.addEventListener('click', () => {
      const tbody = table.querySelector('tbody');
      const rows = Array.from(tbody.querySelectorAll('tr'));
      const asc = !(th.dataset.sortDir === 'asc');
      
      // Reset all indicators
      ths.forEach(header => {
        const ind = header.querySelector('span');
        if (ind) {
          ind.style.opacity = '0.3';
          ind.innerHTML = '↕';
        }
        header.dataset.sortDir = '';
      });
      
      // Set current indicator
      th.dataset.sortDir = asc ? 'asc' : 'desc';
      indicator.style.opacity = '1';
      indicator.innerHTML = asc ? '↑' : '↓';
      
      const getVal = (tr) => {
        const cell = tr.children[idx];
        if (!cell) return '';
        // Remove HTML tags and get clean text
        return (cell.innerText || cell.textContent || '').trim();
      };
      
      const isNum = rows.every(tr => {
        const val = getVal(tr);
        return val === '' || /^[-+]?\d+(\.\d+)?$/.test(val);
      });
      
      const isDate = rows.every(tr => {
        const val = getVal(tr);
        return val === '' || !isNaN(Date.parse(val));
      });
      
      rows.sort((a,b)=>{
        let va = getVal(a), vb = getVal(b);
        
        if(isNum){
          va = parseFloat(va)||0; 
          vb = parseFloat(vb)||0;
        } else if(isDate){
          va = new Date(va).getTime(); 
          vb = new Date(vb).getTime();
        } else {
          va = va.toLowerCase(); 
          vb = vb.toLowerCase();
        }
        
        if(va < vb) return asc ? -1 : 1; 
        if(va > vb) return asc ? 1 : -1; 
        return 0;
      });
      
      // Re-append sorted rows with animation
      rows.forEach((r, i) => {
        r.style.opacity = '0';
        tbody.appendChild(r);
        setTimeout(() => {
          r.style.transition = 'opacity 0.3s';
          r.style.opacity = '1';
        }, i * 50);
      });
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  // Apply enhanced sorting to all tables
  document.querySelectorAll('.table').forEach(table => {
    makeTableSortable(table);
  });
  
  // Add hover effect to sortable headers
  document.querySelectorAll('thead th').forEach(th => {
    if (th.style.cursor === 'pointer') {
      th.addEventListener('mouseenter', () => {
        th.style.backgroundColor = 'rgba(26, 95, 122, 0.1)';
      });
      th.addEventListener('mouseleave', () => {
        th.style.backgroundColor = '';
      });
    }
  });
});
</script>
<script>
// Tab functionality
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const tabName = btn.dataset.tab;
    
    // Update active states
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    btn.classList.add('active');
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Refresh recent activity data when switching to that tab
    if (tabName === 'recent-activity') {
      loadRecentActivity();
    }
  });
});

// Toggle filters
function toggleFilters() {
  const filters = document.getElementById('filters-section');
  filters.style.display = filters.style.display === 'none' ? 'block' : 'none';
}

// Attendance change autosave
document.querySelectorAll('.attendance-select').forEach(sel => {
  sel.addEventListener('change', async () => {
    const formData = new FormData();
    formData.append('id', sel.dataset.id);
    formData.append('status', sel.value);
    
    try {
      const response = await fetch('student_update_status.php', {
        method: 'POST',
        body: formData
      });
      const result = await response.json();
      if (result.ok) {
        // Update visual feedback
        const bookingItem = sel.closest('.booking-item');
        const badge = bookingItem.querySelector('.badge-custom');
        badge.className = 'badge-custom badge-' + 
          (sel.value === 'visited' ? 'success' : 
           sel.value === 'not attended' ? 'danger' : 
           sel.value === 'canceled' ? 'warning' : 'info');
        badge.textContent = sel.charAt(0).toUpperCase() + sel.slice(1);
      }
    } catch (error) {
      console.error('Error updating status:', error);
    }
  });
});

// Load recent activity via AJAX
async function loadRecentActivity() {
  try {
    const response = await fetch('teachers.php?load_recent=1');
    const data = await response.json();
    
    if (data.warnings) {
      document.querySelector('#recent-warnings').innerHTML = data.warnings;
    }
    if (data.emails) {
      document.querySelector('#recent-emails').innerHTML = data.emails;
    }
  } catch (error) {
    console.error('Error loading recent activity:', error);
  }
}

// Comment autosave
document.querySelectorAll('.note-box').forEach(el => {
  let timeout;
  el.addEventListener('input', () => {
    clearTimeout(timeout);
    timeout = setTimeout(async () => {
      const id = el.dataset.id;
      const val = el.value;
      
      const formData = new FormData();
      formData.append('id', id);
      formData.append('comment', val);
      
      try {
        const response = await fetch('teacher_update_note.php', {
          method: 'POST',
          body: formData
        });
        const result = await response.json();
        
        const msg = document.getElementById('saveMsg' + id);
        if (result.ok) {
          msg.classList.add('show');
          setTimeout(() => msg.classList.remove('show'), 2000);
        }
      } catch (error) {
        console.error('Error saving note:', error);
      }
    }, 1000);
  });
});

// Warning Modal
const warnModal = new bootstrap.Modal(document.getElementById('warningModal'));
document.querySelectorAll('.send-warning').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('warnStudentId').value = btn.dataset.id;
    document.getElementById('warnStudentName').value = btn.dataset.name;
    warnModal.show();
  });
});

document.getElementById('warningForm').addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  
  try {
    const response = await fetch('teacher_send_warning.php', {
      method: 'POST',
      body: formData
    });
    const result = await response.json();
    
    if (result.ok) {
      alert('Warning sent successfully!');
      warnModal.hide();
      e.target.reset();
      // Refresh the recent activity tab if it's active
      const recentTab = document.getElementById('recent-activity-tab');
      if (recentTab.classList.contains('active')) {
        loadRecentActivity();
      }
    } else {
      alert('Error sending warning: ' + (result.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Error:', error);
    alert('Error sending warning');
  }
});

// Booking Filters Form
document.getElementById('bookingFiltersForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const params = new URLSearchParams(formData);
  
  try {
    const response = await fetch(`teachers.php?${params.toString()}`, {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const html = await response.text();
    
    // Parse the response and update the bookings container
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const newBookingsContent = doc.querySelector('#bookings-tab .content-card');
    if (newBookingsContent) {
      document.querySelector('#bookings-tab .content-card').innerHTML = newBookingsContent.innerHTML;
    }
  } catch (error) {
    console.error('Error:', error);
    // Fallback to normal form submission
    e.target.submit();
  }
});

// Cascading Filters - Auto-apply grade when student is selected
document.querySelector('select[name="filter_student"]')?.addEventListener('change', function() {
  const selectedOption = this.options[this.selectedIndex];
  const match = selectedOption.text.match(/\(Grade (\d+)\)/);
  if (match) {
    const grade = match[1];
    document.querySelector('select[name="filter_grade"]').value = grade;
  }
});

// Card-based sorting for students
function sortStudents(field, direction) {
  const container = document.getElementById('students-container');
  const items = Array.from(container.querySelectorAll('.student-item'));
  
  items.sort((a, b) => {
    let valueA, valueB;
    
    if (field === 'name') {
      valueA = a.querySelector('.student-name').textContent.trim().toLowerCase();
      valueB = b.querySelector('.student-name').textContent.trim().toLowerCase();
    } else if (field === 'grade') {
      const gradeA = a.querySelector('.student-meta').textContent.match(/Grade (\d+)/);
      const gradeB = b.querySelector('.student-meta').textContent.match(/Grade (\d+)/);
      valueA = gradeA ? parseInt(gradeA[1]) : 0;
      valueB = gradeB ? parseInt(gradeB[1]) : 0;
    } else if (field === 'bookings') {
      const bookingA = a.querySelector('.student-meta').textContent.match(/(\d+) bookings/);
      const bookingB = b.querySelector('.student-meta').textContent.match(/(\d+) bookings/);
      valueA = bookingA ? parseInt(bookingA[1]) : 0;
      valueB = bookingB ? parseInt(bookingB[1]) : 0;
    }
    
    if (direction === 'asc') {
      return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
    } else {
      return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
    }
  });
  
  // Re-append sorted items with animation
  items.forEach((item, index) => {
    item.style.opacity = '0';
    container.appendChild(item);
    setTimeout(() => {
      item.style.transition = 'opacity 0.3s ease';
      item.style.opacity = '1';
    }, index * 50);
  });
}

// Card-based sorting for bookings
function sortBookings(field, direction) {
  const container = document.querySelector('#bookings-tab .content-card');
  const items = Array.from(container.querySelectorAll('.booking-item'));
  
  items.sort((a, b) => {
    let valueA, valueB;
    
    if (field === 'student') {
      valueA = a.querySelector('.student-name').textContent.trim().toLowerCase();
      valueB = b.querySelector('.student-name').textContent.trim().toLowerCase();
    } else if (field === 'grade') {
      const gradeA = a.querySelector('.student-meta').textContent.match(/Grade (\d+)/);
      const gradeB = b.querySelector('.student-meta').textContent.match(/Grade (\d+)/);
      valueA = gradeA ? parseInt(gradeA[1]) : 0;
      valueB = gradeB ? parseInt(gradeB[1]) : 0;
    } else if (field === 'subject') {
      const subjectA = a.querySelector('.student-meta').textContent.split('•')[1];
      const subjectB = b.querySelector('.student-meta').textContent.split('•')[1];
      valueA = subjectA ? subjectA.trim().toLowerCase() : '';
      valueB = subjectB ? subjectB.trim().toLowerCase() : '';
    } else if (field === 'date') {
      const dateA = a.querySelector('.student-meta').textContent.split('•')[2];
      const dateB = b.querySelector('.student-meta').textContent.split('•')[2];
      valueA = dateA ? new Date(dateA.trim()).getTime() : 0;
      valueB = dateB ? new Date(dateB.trim()).getTime() : 0;
    }
    
    if (direction === 'asc') {
      return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
    } else {
      return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
    }
  });
  
  // Re-append sorted items
  const header = container.querySelector('.card-header-custom');
  const filtersSection = container.querySelector('.filter-section');
  
  // Clear container but keep header and filters
  container.innerHTML = '';
  container.appendChild(header);
  if (filtersSection) container.appendChild(filtersSection);
  
  // Add sorted items with animation
  items.forEach((item, index) => {
    item.style.opacity = '0';
    container.appendChild(item);
    setTimeout(() => {
      item.style.transition = 'opacity 0.3s ease';
      item.style.opacity = '1';
    }, index * 50);
  });
}

// Student Search Form
document.getElementById('studentSearchForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const params = new URLSearchParams(formData);
  
  try {
    const response = await fetch(`teachers.php?${params.toString()}`, {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const html = await response.text();
    
    // Parse response and update students container
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const newStudentsContent = doc.querySelector('#students-tab .content-card');
    if (newStudentsContent) {
      document.querySelector('#students-tab .content-card').innerHTML = newStudentsContent.innerHTML;
    }
  } catch (error) {
    console.error('Error:', error);
    // Fallback to normal form submission
    e.target.submit();
  }
});

// Email Modal
const emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
document.querySelectorAll('.send-email').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('emailStudentId').value = btn.dataset.id;
    document.getElementById('emailStudentName').value = btn.dataset.name;
    emailModal.show();
  });
});

// View email content function
function viewEmailContent(emailId) {
  fetch(`get_email_content.php?id=${emailId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const modal = new bootstrap.Modal(document.createElement('div'));
        const modalHtml = `
          <div class="modal fade" tabindex="-1">
            <div class="modal-dialog modal-lg">
              <div class="modal-content">
                <div class="modal-header bg-info text-white">
                  <h5 class="modal-title">
                    <i class="fas fa-envelope"></i> Email Details
                  </h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="email-details">
                    <div class="row mb-3">
                      <div class="col-sm-3"><strong>To:</strong></div>
                      <div class="col-sm-9">${data.student_name} (Grade ${data.grade})</div>
                    </div>
                    <div class="row mb-3">
                      <div class="col-sm-3"><strong>Sent:</strong></div>
                      <div class="col-sm-9">${new Date(data.sent_at).toLocaleString()}</div>
                    </div>
                    <div class="row mb-3">
                      <div class="col-sm-3"><strong>Message:</strong></div>
                      <div class="col-sm-9">
                        <div class="email-content-display">${data.message}</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
            </div>
          </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modalElement = document.body.lastElementChild;
        const modalInstance = new bootstrap.Modal(modalElement);
        modalInstance.show();
        
        modalElement.addEventListener('hidden.bs.modal', () => {
          modalElement.remove();
        });
      } else {
        alert('Error loading email content');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Error loading email content');
    });
}

document.getElementById('emailForm').addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const fileInput = document.querySelector('input[name="attachment"]');
  if (fileInput.files.length > 0) {
    formData.append('attachment', fileInput.files[0]);
  }
  try {
    const response = await fetch('teacher_email_report.php', {
      method: 'POST',
      body: formData
    });
    
    if (response.ok) {
      alert('Email sent successfully!');
      emailModal.hide();
      e.target.reset();
    } else {
      alert('Error sending email');
    }
  } catch (error) {
    console.error('Error:', error);
    alert('Error sending email');
  }
});
</script>
</body>
</html>