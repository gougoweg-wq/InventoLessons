<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/* 🔒 Access Gate */
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
    header("Location: index.php");
    exit();
}

/* 🧾 Log admin access */
$conn->query("CREATE TABLE IF NOT EXISTS logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_email VARCHAR(255),
  user_id INT,
  role VARCHAR(20),
  action TEXT,
  log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$log = $conn->prepare("INSERT INTO logs (admin_email, role, action) VALUES (?, 'admin', 'Viewed Admin Dashboard')");
$log->bind_param("s", $_SESSION['admin_email']);
$log->execute();
$log->close();

/* 📊 Fetch Data */
$order = $_GET['order'] ?? 'grade';
$dir = ($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$valid = ['grade','name','created_at','id'];
if (!in_array($order, $valid)) $order = 'grade';

$users = $conn->query("SELECT id, name, email, grade, created_at FROM users ORDER BY $order $dir");

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

/* Enhanced Bookings with Filters (prepared) */
$filterGrade = $_GET['grade'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterTeacher = $_GET['teacher'] ?? '';
$filterStudent = $_GET['student'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$_page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($pageSize * ($_page - 1));

$bookingBase = "SELECT b.id, b.subject, b.booking_date, b.status, u.name as student_name, u.grade, u.id as student_id
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        WHERE 1=1";
$conds = [];
$params = [];
$types = '';

if ($filterGrade !== '') { $conds[] = 'u.grade = ?'; $params[] = (string)intval($filterGrade); $types .= 's'; }
if ($filterStatus !== '') { $conds[] = 'b.status = ?'; $params[] = $filterStatus; $types .= 's'; }
if ($filterStudent !== '') { $conds[] = 'u.id = ?'; $params[] = (string)intval($filterStudent); $types .= 's'; }
if ($searchTerm !== '') { $conds[] = '(u.name LIKE ? OR b.subject LIKE ?)'; $like = '%'.$searchTerm.'%'; $params[] = $like; $params[] = $like; $types .= 'ss'; }

$bookingSqlBase = $bookingBase.(count($conds)? (' AND '.implode(' AND ',$conds)) : '')." ORDER BY b.booking_date DESC";

// CSV export (no pagination)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=bookings_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Student','Grade','Subject','Date','Status']);
    $stmtCsv = $conn->prepare($bookingSqlBase);
    if (!empty($params)) { $stmtCsv->bind_param($types, ...$params); }
    $stmtCsv->execute();
    $resCsv = $stmtCsv->get_result();
    while ($r = $resCsv->fetch_assoc()) {
        fputcsv($out, [$r['id'],$r['student_name'],$r['grade'],$r['subject'],$r['booking_date'],$r['status']]);
    }
    fclose($out);
    exit;
}

// Paginated fetch
$bookingSql = $bookingSqlBase." LIMIT ? OFFSET ?";
$stmtBk = $conn->prepare($bookingSql);
if (!empty($params)) { 
    $allParams = array_merge($params, [$pageSize, $offset]);
    $stmtBk->bind_param($types.'ii', ...$allParams); 
}
else { $stmtBk->bind_param('ii', $pageSize, $offset); }
$stmtBk->execute();
$bookings = $stmtBk->get_result();

// Total count for pagination
$countSql = "SELECT COUNT(*) AS cnt FROM (".$bookingSqlBase.") t";
$stmtCnt = $conn->prepare($countSql);
if (!empty($params)) { $stmtCnt->bind_param($types, ...$params); }
$stmtCnt->execute();
$totalRows = ($stmtCnt->get_result()->fetch_assoc()['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$stmtCnt->close();

// Build base query string for links (without page/export)
$baseParams = $_GET;
unset($baseParams['page'], $baseParams['export']);
$qs = http_build_query($baseParams);

// Fetch filter options
$grades = [];
for ($i = 6; $i <= 12; $i++) {
    $grades[] = $i;
}

$students = $conn->query("SELECT id, name, grade FROM users ORDER BY grade, name");
$teachers = $conn->query("SELECT id, name FROM teachers ORDER BY name");

/* Booking Analytics */
$statusCounts = ['booked'=>0, 'visited'=>0, 'canceled'=>0, 'not attended'=>0];
$statRes = $conn->query("SELECT status, COUNT(*) AS total FROM bookings GROUP BY status");
if ($statRes) {
  while ($r = $statRes->fetch_assoc()) {
    $s = trim(strtolower($r['status'] ?? ''));
    if ($s === 'cancelled') $s = 'canceled';
    if (isset($statusCounts[$s])) $statusCounts[$s] += (int)$r['total'];
  }
}
$totalBookings = array_sum($statusCounts);

/* Recent Logs */
$logs = $conn->query("SELECT role, admin_email, user_id, action, log_time FROM logs ORDER BY id DESC LIMIT 50");

/* Email Logs */
$conn->query("CREATE TABLE IF NOT EXISTS email_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  student_id INT NULL,
  student_name VARCHAR(255) NOT NULL,
  student_grade VARCHAR(20) NOT NULL,
  parent_email VARCHAR(255) NOT NULL,
  message TEXT,
  attachment_path VARCHAR(255),
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$emailLogs = $conn->query("
  SELECT l.*, t.name AS teacher_name
  FROM email_logs l
  LEFT JOIN teachers t ON t.id = l.teacher_id
  ORDER BY l.sent_at DESC
  LIMIT 100
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard - Invento</title>
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
  box-shadow: 0 8px 32px rgba(26, 95, 122, 0.3);
  backdrop-filter: blur(10px);
  border-bottom: 2px solid rgba(255, 255, 255, 0.1);
  padding: 1rem 0;
  transition: all 0.3s ease;
}

.navbar:hover {
  box-shadow: 0 12px 40px rgba(26, 95, 122, 0.4);
}

.navbar-brand { 
  color: #fff !important; 
  font-weight: 700;
  font-size: 1.5rem;
  display: flex;
  align-items: center;
  transition: transform 0.3s ease;
}

.navbar-brand:hover {
  transform: translateY(-2px);
}

.logo-img { 
  height: 60px;
  margin-right: 15px;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  transition: all 0.3s ease;
  background: white;
  padding: 8px;
}

.logo-img:hover {
  transform: scale(1.05) rotate(2deg);
  box-shadow: 0 8px 20px rgba(0,0,0,0.25);
}

.container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 2rem;
}

.nav-tabs {
  background: rgba(255, 255, 255, 0.95);
  border-radius: 16px;
  padding: 0.5rem;
  box-shadow: var(--shadow-md);
  border: none;
  margin-bottom: 2rem !important;
  backdrop-filter: blur(10px);
}

.nav-tabs .nav-link {
  border: none;
  border-radius: 12px;
  padding: 0.75rem 1.5rem;
  margin: 0 0.25rem;
  color: var(--text-primary);
  font-weight: 600;
  transition: all 0.3s ease;
  background: transparent;
  position: relative;
  overflow: hidden;
}

.nav-tabs .nav-link::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(26, 95, 122, 0.1), transparent);
  transition: left 0.5s ease;
}

.nav-tabs .nav-link:hover::before {
  left: 100%;
}

.nav-tabs .nav-link:hover {
  background: rgba(26, 95, 122, 0.1);
  transform: translateY(-2px);
}

.nav-tabs .nav-link.active {
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
  box-shadow: 0 4px 12px rgba(26, 95, 122, 0.3);
  transform: translateY(-2px);
}

.card { 
  border: none;
  box-shadow: var(--shadow-lg);
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  transition: all 0.3s ease;
  overflow: hidden;
  position: relative;
}

.card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--primary-color), var(--secondary-color), var(--primary-color));
  background-size: 200% 100%;
  animation: shimmer 3s linear infinite;
}

@keyframes shimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}

.card:hover {
  transform: translateY(-5px);
  box-shadow: var(--shadow-xl);
}

.card-header {
  background: linear-gradient(135deg, rgba(26, 95, 122, 0.1) 0%, rgba(26, 95, 122, 0.05) 100%);
  border-bottom: 1px solid var(--border-color);
  border-radius: 20px 20px 0 0 !important;
  padding: 1.5rem;
}

.table { 
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 0;
}

.table thead th { 
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
  font-weight: 700;
  padding: 1rem;
  border: none;
  text-transform: uppercase;
  font-size: 0.85rem;
  letter-spacing: 0.5px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.table thead th:hover {
  background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
  transform: scale(1.02);
}

.table tbody tr {
  transition: all 0.3s ease;
  border-bottom: 1px solid var(--border-color);
}

.table-hover tbody tr:hover { 
  background: linear-gradient(90deg, rgba(26, 95, 122, 0.05) 0%, rgba(26, 95, 122, 0.02) 100%);
  transform: scale(1.01);
  box-shadow: 0 4px 12px rgba(26, 95, 122, 0.1);
}

.table tbody td {
  padding: 1rem;
  vertical-align: middle;
  border-top: 1px solid var(--border-color);
}

.btn {
  border-radius: 10px;
  font-weight: 600;
  padding: 0.5rem 1.5rem;
  transition: all 0.3s ease;
  border: none;
  position: relative;
  overflow: hidden;
}

.btn::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 0;
  height: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.3);
  transform: translate(-50%, -50%);
  transition: width 0.6s, height 0.6s;
}

.btn:hover::before {
  width: 300px;
  height: 300px;
}

.btn-primary {
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  box-shadow: 0 4px 12px rgba(26, 95, 122, 0.3);
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(26, 95, 122, 0.4);
}

.btn-warning {
  background: linear-gradient(135deg, var(--warning-color) 0%, #e67e22 100%);
  box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
}

.btn-warning:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4);
}

.btn-outline-custom { 
  color: var(--primary-color);
  border: 2px solid var(--primary-color);
  background: transparent;
}

.btn-outline-custom:hover { 
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(26, 95, 122, 0.3);
}

.form-control, .form-select {
  border-radius: 10px;
  border: 2px solid var(--border-color);
  padding: 0.75rem 1rem;
  transition: all 0.3s ease;
  background: white;
}

.form-control:focus, .form-select:focus {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 0.2rem rgba(26, 95, 122, 0.25);
  transform: translateY(-1px);
}

.badge { 
  font-size: 0.75rem;
  padding: 0.5rem 0.75rem;
  border-radius: 20px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

h4 {
  color: var(--text-primary);
  font-weight: 700;
  margin-bottom: 1.5rem;
  position: relative;
  padding-left: 1rem;
}

h4::before {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 4px;
  height: 24px;
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
  border-radius: 2px;
}

.dropdown-menu {
  border-radius: 12px;
  box-shadow: var(--shadow-lg);
  border: none;
  backdrop-filter: blur(10px);
  background: rgba(255, 255, 255, 0.98);
}

.dropdown-item {
  border-radius: 8px;
  margin: 0.25rem 0.5rem;
  transition: all 0.3s ease;
}

.dropdown-item:hover {
  background: linear-gradient(135deg, rgba(26, 95, 122, 0.1) 0%, rgba(26, 95, 122, 0.05) 100%);
  transform: translateX(5px);
}

.modal-content {
  border-radius: 20px;
  border: none;
  box-shadow: var(--shadow-xl);
  backdrop-filter: blur(20px);
}

.modal-header {
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
  border-radius: 20px 20px 0 0;
  border: none;
}

/* Loading animations */
@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.tab-content .tab-pane {
  animation: fadeInUp 0.6s ease-out;
}

/* Responsive design */
@media (max-width: 768px) {
  .container {
    padding: 1rem;
  }
  
  .nav-tabs .nav-link {
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
  }
  
  .logo-img {
    height: 45px;
  }
  
  .navbar-brand {
    font-size: 1.2rem;
  }
}

/* Custom scrollbar */
::-webkit-scrollbar {
  width: 8px;
}

::-webkit-scrollbar-track {
  background: var(--light-bg);
}

::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
}

/* Logo enhancements */
.logo-container {
  position: relative;
  display: inline-block;
}

.logo-glow {
  position: absolute;
  top: -5px;
  left: -5px;
  right: -5px;
  bottom: -5px;
  background: linear-gradient(45deg, var(--primary-color), var(--secondary-color), var(--primary-color));
  border-radius: 16px;
  z-index: -1;
  opacity: 0.6;
  filter: blur(8px);
  animation: glow-pulse 2s ease-in-out infinite alternate;
}

@keyframes glow-pulse {
  0% { opacity: 0.4; transform: scale(0.95); }
  100% { opacity: 0.8; transform: scale(1.05); }
}

.brand-text {
  display: flex;
  flex-direction: column;
  margin-left: 10px;
}

.brand-title {
  font-size: 1.8rem;
  font-weight: 800;
  color: white;
  text-shadow: 0 2px 4px rgba(0,0,0,0.2);
  letter-spacing: -0.5px;
}

.brand-subtitle {
  font-size: 0.9rem;
  color: rgba(255, 255, 255, 0.8);
  font-weight: 400;
  margin-top: -2px;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(255, 255, 255, 0.1);
  padding: 8px 16px;
  border-radius: 12px;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
  transition: all 0.3s ease;
}

.user-info:hover {
  background: rgba(255, 255, 255, 0.15);
  transform: translateY(-2px);
}

.user-avatar {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, var(--secondary-color) 0%, #e67e22 100%);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 1.2rem;
  box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
}

.user-details {
  display: flex;
  flex-direction: column;
}

.user-email {
  color: white;
  font-weight: 600;
  font-size: 0.9rem;
}

.user-role {
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.75rem;
  font-weight: 400;
}

.settings-btn {
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.2);
  backdrop-filter: blur(10px);
  transition: all 0.3s ease;
}

.settings-btn:hover {
  background: rgba(255, 255, 255, 0.2);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

/* Font Awesome icons fallback */
.fas::before {
  font-family: 'Font Awesome 5 Free';
  font-weight: 900;
}

.fa-user-shield::before { content: '\f508'; }
.fa-cog::before { content: '\f013'; }
.fa-lock::before { content: '\f023'; }
.fa-sign-out-alt::before { content: '\f2f5'; }
.fa-users::before { content: '\f0c0'; }
.fa-search::before { content: '\f002'; }
.fa-calendar-check::before { content: '\f2da'; }
.fa-list::before { content: '\f0ca'; }
.fa-history::before { content: '\f1da'; }
.fa-envelope::before { content: '\f0e0'; }
.fa-chart-pie::before { content: '\f200'; }

/* Search enhancements */
.search-container {
  position: relative;
  display: flex;
  align-items: center;
}

.search-icon {
  position: absolute;
  left: 15px;
  color: var(--text-secondary);
  z-index: 2;
  transition: color 0.3s ease;
}

.search-input {
  padding-left: 45px !important;
  min-width: 250px;
}

.search-input:focus + .search-icon {
  color: var(--primary-color);
}

.email-preview-admin { 
  max-width: 300px; 
}

.email-preview-text { 
  font-size: 0.9rem; 
  color: #6c757d; 
  background: #f8f9fa; 
  padding: 8px; 
  border-radius: 6px; 
  border-left: 3px solid #007bff;
  margin-bottom: 8px;
  line-height: 1.4;
}

/* Fix table header links - WHITE BUTTONS */
.table th a {
  color: var(--text-primary);
  text-decoration: none;
  font-weight: 600;
  padding: 0.5rem 0.75rem;
  border-radius: 6px;
  transition: all 0.3s ease;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  background: white;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.table th a:hover {
  background: #f8fafc;
  color: var(--primary-color);
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  border-color: var(--primary-color);
}

.table th a::after {
  content: '↕';
  font-size: 0.8rem;
  opacity: 0.6;
}

.table th a:hover::after {
  opacity: 1;
}

/* Better button styles - WHITE THEME */
.btn-primary {
  background: white;
  border: 2px solid var(--primary-color);
  color: var(--primary-color);
  border-radius: 8px;
  padding: 0.5rem 1rem;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-primary:hover {
  background: var(--primary-color);
  color: white;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(26, 95, 122, 0.4);
}

.btn-secondary {
  background: white;
  border: 2px solid #6c757d;
  color: #6c757d;
}

.btn-secondary:hover {
  background: #6c757d;
  color: white;
}

.btn-success {
  background: white;
  border: 2px solid var(--success-color);
  color: var(--success-color);
}

.btn-success:hover {
  background: var(--success-color);
  color: white;
}

.btn-outline-primary {
  border: 2px solid var(--primary-color);
  color: var(--primary-color);
  background: white;
}

.btn-outline-primary:hover {
  background: var(--primary-color);
  color: white;
}

/* White card design */
.card {
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  transition: all 0.3s ease;
}

.card:hover {
  box-shadow: 0 4px 16px rgba(0,0,0,0.12);
  transform: translateY(-2px);
}

/* White nav tabs */
.nav-tabs {
  background: white;
  border-radius: 12px;
  padding: 0.5rem;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  border: 1px solid #e2e8f0;
  margin-bottom: 2rem !important;
}

.nav-tabs .nav-link {
  border: none;
  border-radius: 8px;
  padding: 0.75rem 1.5rem;
  margin: 0 0.25rem;
  color: var(--text-primary);
  font-weight: 600;
  transition: all 0.3s ease;
  background: transparent;
}

.nav-tabs .nav-link:hover {
  background: #f8fafc;
  color: var(--primary-color);
}

.nav-tabs .nav-link.active {
  background: var(--primary-color);
  color: white;
  box-shadow: 0 2px 8px rgba(26, 95, 122, 0.3);
}

/* White table styling */
.table {
  background: white;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.table th {
  background: #f8fafc;
  border-bottom: 2px solid #e2e8f0;
  font-weight: 600;
  color: var(--text-primary);
}

.table td {
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}

.table tbody tr:hover {
  background: #f8fafc;
}
</style>
</head>
<body>

<!-- Enhanced Navbar -->
<nav class="navbar navbar-dark px-4 sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php">
      <div class="logo-container">
        <img src="invento.png" class="logo-img" alt="Invento Logo">
        <div class="logo-glow"></div>
      </div>
      <div class="brand-text">
        <div class="brand-title">Invento</div>
        <div class="brand-subtitle">Admin Dashboard</div>
      </div>
    </a>
    <div class="d-flex align-items-center gap-3">
      <div class="user-info">
        <div class="user-avatar">
          <i class="fas fa-user-shield"></i>
        </div>
        <div class="user-details">
          <div class="user-email"><?= htmlspecialchars($_SESSION['admin_email']) ?></div>
          <div class="user-role">Administrator</div>
        </div>
      </div>
      <div class="dropdown">
        <button class="btn btn-outline-light btn-sm dropdown-toggle settings-btn" data-bs-toggle="dropdown">
          <i class="fas fa-cog"></i> Settings
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#changePassModal">
            <i class="fas fa-lock"></i> Change Password
          </a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="admin_logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout
          </a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="container mt-4">
  <!-- Tabs -->
  <ul class="nav nav-tabs mb-4" id="adminTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-target="#users" data-bs-toggle="tab">👥 Users</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-target="#bookings" data-bs-toggle="tab">📚 Bookings</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-target="#logs" data-bs-toggle="tab">📝 Logs</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-target="#emailLogs" data-bs-toggle="tab">📧 Email Logs</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-target="#analytics" data-bs-toggle="tab">📊 Analytics</button></li>
  </ul>

  <div class="tab-content">
    
    <!-- USERS TAB -->
    <div class="tab-pane fade show active" id="users">
      <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <h4><i class="fas fa-users"></i> All Users</h4>
          <div class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input id="userSearch" class="form-control search-input" placeholder="Search users...">
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="usersTable">
            <thead>
              <tr>
                <th><a href="?order=id&dir=<?= $dir==='ASC'?'DESC':'ASC' ?>">ID</a></th>
                <th><a href="?order=name&dir=<?= $dir==='ASC'?'DESC':'ASC' ?>">Name</a></th>
                <th>Email</th>
                <th><a href="?order=grade&dir=<?= $dir==='ASC'?'DESC':'ASC' ?>">Grade</a></th>
                <th><a href="?order=created_at&dir=<?= $dir==='ASC'?'DESC':'ASC' ?>">Joined</a></th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while($u = $users->fetch_assoc()): ?>
              <tr>
                <td><?= $u['id'] ?></td>
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['grade']) ?></td>
                <td><?= $u['created_at'] ?></td>
                <td>
                  <a class="btn btn-sm btn-primary" href="admin_edit_student_profile.php?id=<?= $u['id'] ?>">Edit</a>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ENHANCED BOOKINGS TAB -->
    <div class="tab-pane fade" id="bookings">
      <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <h4><i class="fas fa-calendar-check"></i> Bookings Management</h4>
          <div class="d-flex gap-2">
            <a href="admin_dashboard.php?<?= htmlspecialchars($qs) ?>&export=csv" class="btn btn-outline-success">
              <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <a href="admin_bookings_list.php" class="btn btn-outline-custom">
              <i class="fas fa-list"></i> View Full List
            </a>
          </div>
        </div>

        <!-- Enhanced Filters with Dropdowns -->
        <form method="GET" class="row g-2 mb-4">
            <div class="col-md-2">
                <select name="grade" class="form-select">
                    <option value="">Grade (All)</option>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?= $grade ?>" <?= $filterGrade==$grade?'selected':'' ?>><?= $grade ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">Status (All)</option>
                    <option value="booked" <?= $filterStatus=='booked'?'selected':'' ?>>Booked</option>
                    <option value="visited" <?= $filterStatus=='visited'?'selected':'' ?>>Visited</option>
                    <option value="not attended" <?= $filterStatus=='not attended'?'selected':'' ?>>Not Attended</option>
                    <option value="canceled" <?= $filterStatus=='canceled'?'selected':'' ?>>Canceled</option>
                </select>
            </div>

            <div class="col-md-2">
                <select name="student" class="form-select">
                    <option value="">Student (All)</option>
                    <?php while($s = $students->fetch_assoc()): ?>
                        <option value="<?= $s['id'] ?>" <?= $filterStudent==$s['id']?'selected':'' ?>>
                            <?= htmlspecialchars($s['name']) ?> (Grade <?= $s['grade'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-3">
                <input type="text" name="search" class="form-control" placeholder="Search student or subject..." value="<?= htmlspecialchars($searchTerm) ?>">
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">🔍 Filter</button>
            </div>
            <div class="col-md-1">
                <a href="admin_dashboard.php#bookings" class="btn btn-secondary w-100">Reset</a>
            </div>
        </form>

        <!-- Enhanced Table -->
        <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Grade</th>
                    <th>Subject</th>
                    <th>Date & Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($bookings->num_rows == 0): ?>
                <tr><td colspan="7" class="text-center">No bookings found.</td></tr>
            <?php else: ?>
                <?php while($b = $bookings->fetch_assoc()): ?>
                <tr>
                    <td><?= $b['id'] ?></td>
                    <td><?= htmlspecialchars($b['student_name']) ?></td>
                    <td><?= htmlspecialchars($b['grade']) ?></td>
                    <td><?= htmlspecialchars($b['subject']) ?></td>
                    <td><?= htmlspecialchars($b['booking_date']) ?></td>
                    <td>
                        <?php
                        $badge = match($b['status']??'booked') {
                            'visited' => 'success',
                            'not attended' => 'danger',
                            'canceled' => 'secondary',
                            default => 'primary',
                        };
                        ?>
                        <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($b['status'] ?? 'booked') ?></span>
                    </td>
                    <td>
                        <a href="admin_edit_booking.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- LOGS TAB -->
    <div class="tab-pane fade" id="logs">
      <div class="card p-4">
        <h4 class="mb-3"><i class="fas fa-history"></i> Recent Activity Logs</h4>
        <div class="table-responsive">
          <table class="table table-sm" id="logs">
            <thead>
              <tr><th>Time</th><th>Admin</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php while($l = $logs->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($l['log_time']) ?></td>
                <td><?= htmlspecialchars($l['admin_email']) ?></td>
                <td><?= htmlspecialchars($l['action']) ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- EMAIL LOGS TAB -->
    <div class="tab-pane fade" id="emailLogs">
      <div class="card p-4">
        <h4 class="mb-3"><i class="fas fa-envelope"></i> Teacher Email Logs</h4>
        <div class="table-responsive">
          <table class="table table-bordered" id="emailLogs">
            <thead>
              <tr><th>Date</th><th>Teacher</th><th>Student</th><th>Grade</th><th>Parent Email</th><th>Message</th></tr>
            </thead>
            <tbody>
              <?php while($e = $emailLogs->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($e['sent_at']) ?></td>
                <td><?= htmlspecialchars($e['teacher_name']??'—') ?></td>
                <td><?= htmlspecialchars($e['student_name']) ?></td>
                <td><?= htmlspecialchars($e['student_grade']) ?></td>
                <td><?= htmlspecialchars($e['parent_email']) ?></td>
                <td>
                  <div class="email-preview-admin">
                    <div class="email-preview-text">
                      <?= htmlspecialchars(extractEmailPreview($e['message'], 80)) ?>
                    </div>
                    <button class="btn btn-sm btn-outline-primary mt-1" 
                      onclick="viewEmailContent('<?= htmlspecialchars($e['id']) ?>')">
                      <i class="fas fa-eye"></i> View Full
                    </button>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ANALYTICS TAB -->
    <div class="tab-pane fade" id="analytics">
      <div class="card p-4">
        <h4 class="mb-4"><i class="fas fa-chart-pie"></i> Booking Statistics</h4>
        <div class="row">
          <div class="col-md-6">
            <canvas id="statusChart"></canvas>
          </div>
          <div class="col-md-6">
            <table class="table">
              <tr><th>Total Bookings</th><td><?= $totalBookings ?></td></tr>
              <tr><th>Booked</th><td><?= $statusCounts['booked'] ?></td></tr>
              <tr><th>Visited</th><td><?= $statusCounts['visited'] ?></td></tr>
              <tr><th>Not Attended</th><td><?= $statusCounts['not attended'] ?></td></tr>
              <tr><th>Canceled</th><td><?= $statusCounts['canceled'] ?></td></tr>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePassModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" action="admin_change_password.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Change Password</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label>Current Password</label>
          <input type="password" name="current" class="form-control" required>
        </div>
        <div class="mb-3">
          <label>New Password</label>
          <input type="password" name="new" class="form-control" required>
        </div>
        <div class="mb-3">
          <label>Confirm Password</label>
          <input type="password" name="confirm" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Update</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// User Search
document.getElementById('userSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});

// Cascading Filters - Auto-apply grade when student is selected
document.querySelector('select[name="student"]')?.addEventListener('change', function() {
  const selectedOption = this.options[this.selectedIndex];
  const match = selectedOption.text.match(/\(Grade (\d+)\)/);
  if (match) {
    const grade = match[1];
    document.querySelector('select[name="grade"]').value = grade;
  }
});

// Live search with debounce
let searchTimeout;
document.querySelector('input[name="search"]')?.addEventListener('input', function() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    const form = this.closest('form');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    
    // Update URL without page reload
    const newUrl = window.location.pathname + '?' + params.toString();
    window.history.pushState({}, '', newUrl);
    
    // Load filtered results via AJAX
    loadFilteredBookings(params);
  }, 500);
});

// Handle filter form submission without page reload
document.querySelector('form[method="GET"]')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  const params = new URLSearchParams(formData);
  
  // Update URL
  const newUrl = window.location.pathname + '?' + params.toString();
  window.history.pushState({}, '', newUrl);
  
  // Load filtered results
  loadFilteredBookings(params);
});

// Function to load filtered bookings via AJAX
async function loadFilteredBookings(params) {
  try {
    const response = await fetch(`admin_dashboard.php?${params.toString()}`, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    
    if (response.ok) {
      const html = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      
      // Update bookings table
      const newTable = doc.querySelector('#bookings .table-responsive');
      const currentTable = document.querySelector('#bookings .table-responsive');
      if (newTable && currentTable) {
        currentTable.innerHTML = newTable.innerHTML;
        // Reapply sorting to new table
        makeTableSortable(currentTable.querySelector('.table'));
      }
      
      // Update pagination
      const newPagination = doc.querySelector('.pagination');
      const currentPagination = document.querySelector('.pagination');
      if (newPagination && currentPagination) {
        currentPagination.innerHTML = newPagination.innerHTML;
      }
    }
  } catch (error) {
    console.error('Error loading filtered results:', error);
  }
}

// Handle pagination clicks without page reload
document.addEventListener('click', function(e) {
  if (e.target.matches('.page-link')) {
    e.preventDefault();
    const url = new URL(e.target.href);
    const params = url.searchParams;
    
    // Update URL
    window.history.pushState({}, '', url.toString());
    
    // Load page
    loadFilteredBookings(params);
  }
});

// Chart
const ctx = document.getElementById('statusChart').getContext('2d');
new Chart(ctx, {
  type: 'doughnut',
  data: {
    labels: ['Booked', 'Visited', 'Not Attended', 'Canceled'],
    datasets: [{
      data: [<?= $statusCounts['booked'] ?>, <?= $statusCounts['visited'] ?>, <?= $statusCounts['not attended'] ?>, <?= $statusCounts['canceled'] ?>],
      backgroundColor: ['#3d90d7', '#28a745', '#ff9800', '#dc3545']
    }]
  }
});

// Message popup
function showMessage(msg) {
  alert(msg);
}

// View email content function
function viewEmailContent(emailId) {
  fetch(`get_email_content.php?id=${emailId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
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
                        <div class="email-content-display" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; border-radius: 8px; background: #f8f9fa;">
                          ${data.message}
                        </div>
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

// Enhanced table sorting with visual indicators
function makeTableSortable(table){
  const ths = table.querySelectorAll('thead th');
  ths.forEach((th, idx) => {
    // Skip columns with no sortable content (like action buttons)
    if (th.textContent.toLowerCase().includes('action') || 
        th.textContent.toLowerCase().includes('edit') ||
        th.textContent.toLowerCase().includes('delete') ||
        th.textContent.toLowerCase().includes('view')) {
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
</body>
</html>