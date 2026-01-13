<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/* 🔒 Access Control */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("SELECT id, name, email FROM teachers WHERE remember_token=? LIMIT 1");
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
            exit;
        }
        $stmt->close();
    } else {
        header("Location: index.php"); 
        exit;
    }
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['name'] ?? '';

/* 📋 Fetch Sent Warnings with Filtering */
$filterStudent = $_GET['filter_student'] ?? '';
$filterGrade = $_GET['filter_grade'] ?? '';
$filterDateFrom = $_GET['filter_date_from'] ?? '';
$filterDateTo = $_GET['filter_date_to'] ?? '';

$sql = "SELECT sw.id, u.name AS student_name, u.grade, sw.reason, sw.issued_at, sw.issued_by
        FROM student_warnings sw
        JOIN users u ON sw.student_id = u.id
        WHERE sw.issued_by = ?";

$params = [$teacher_name];
$types = "s";

if (!empty($filterStudent)) {
    $sql .= " AND LOWER(u.name) LIKE ?";
    $params[] = '%' . strtolower($filterStudent) . '%';
    $types .= "s";
}
if (!empty($filterGrade)) {
    $sql .= " AND u.grade = ?";
    $params[] = $filterGrade;
    $types .= "s";
}
if (!empty($filterDateFrom)) {
    $sql .= " AND sw.issued_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
    $types .= "s";
}
if (!empty($filterDateTo)) {
    $sql .= " AND sw.issued_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
    $types .= "s";
}

$sql .= " ORDER BY sw.issued_at DESC LIMIT 100";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$warnings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get unique grades for filter dropdown
$gradesList = $conn->query("SELECT DISTINCT grade FROM users WHERE grade IS NOT NULL ORDER BY grade");
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>⚠️ Sent Warnings Log - Invento</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
}

body { 
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
    radial-gradient(circle at 20% 80%, rgba(26, 95, 122, 0.1) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(243, 156, 18, 0.1) 0%, transparent 50%);
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

.main-container {
  padding: 2rem;
  max-width: 1200px;
  margin: 0 auto;
}

.content-card {
  background: rgba(255,255,255,0.95);
  backdrop-filter: blur(10px);
  border-radius: 16px;
  padding: 2rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(255,255,255,0.2);
  margin-bottom: 2rem;
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

.warning-item {
  background: var(--white);
  border-radius: 12px;
  padding: 1.5rem;
  margin-bottom: 1rem;
  border: 1px solid var(--border-color);
  border-left: 5px solid var(--warning-color);
  transition: all 0.3s ease;
  position: relative;
}

.warning-item:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.warning-header {
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

.status-badge {
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.8rem;
  font-weight: 500;
}

.status-badge.sent {
  background: rgba(39, 174, 96, 0.1);
  color: var(--success-color);
}

.status-badge.failed {
  background: rgba(231, 76, 60, 0.1);
  color: var(--danger-color);
}

.warning-content {
  background: var(--light-bg);
  border-radius: 8px;
  padding: 1rem;
  margin-top: 1rem;
  border-left: 3px solid var(--warning-color);
}

.warning-text {
  color: var(--text-primary);
  line-height: 1.6;
  margin: 0;
}

.warning-time {
  font-size: 0.8rem;
  color: var(--text-secondary);
  margin-top: 0.5rem;
}

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

@media (max-width: 768px) {
  .main-container {
    padding: 1rem;
  }
  
  .filter-row {
    grid-template-columns: 1fr;
  }
  
  .warning-header {
    flex-direction: column;
    gap: 1rem;
  }
}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="teachers.php">
      <i class="fas fa-exclamation-triangle"></i>
      Sent Warnings Log
    </a>
    <div class="d-flex align-items-center">
      <a href="teachers.php" class="btn btn-light me-2">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
      <a href="logout.php" class="btn btn-outline-light">
        <i class="fas fa-sign-out-alt"></i> Logout
      </a>
    </div>
  </div>
</nav>

<div class="main-container">
  <div class="content-card">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h4 class="mb-0">
        <i class="fas fa-history"></i>
        Warning History
      </h4>
      <span class="badge bg-primary"><?= count($warnings) ?> warnings</span>
    </div>

    <!-- Filters -->
    <div class="filter-section">
      <form method="GET" class="filter-row" id="warningFiltersForm">
        <input type="text" name="filter_student" placeholder="Search by student name..." 
               class="form-control form-control-custom" value="<?= htmlspecialchars($filterStudent) ?>">
        
        <select name="filter_grade" class="form-select form-select-custom">
          <option value="">All Grades</option>
          <?php while ($grade = $gradesList->fetch_assoc()): ?>
            <option value="<?= $grade['grade'] ?>" <?= $filterGrade === $grade['grade'] ? 'selected' : '' ?>>
              Grade <?= $grade['grade'] ?>
            </option>
          <?php endwhile; ?>
        </select>
        
        <input type="date" name="filter_date_from" placeholder="From date" 
               class="form-control form-control-custom" value="<?= htmlspecialchars($filterDateFrom) ?>">
        
        <input type="date" name="filter_date_to" placeholder="To date" 
               class="form-control form-control-custom" value="<?= htmlspecialchars($filterDateTo) ?>">
        
        <button type="submit" class="btn btn-custom btn-primary-custom">
          <i class="fas fa-filter"></i> Apply Filters
        </button>
        
        <a href="teacher_warnings_log.php" class="btn btn-custom btn-secondary-custom">
          <i class="fas fa-times"></i> Clear
        </a>
      </form>
    </div>

    <!-- Warnings List -->
    <?php if (!empty($warnings)): ?>
      <?php foreach ($warnings as $w): ?>
        <div class="warning-item">
          <div class="warning-header">
            <div class="student-info">
              <div class="student-name"><?= htmlspecialchars($w['student_name']) ?></div>
              <div class="student-meta">
                <i class="fas fa-graduation-cap"></i> Grade <?= htmlspecialchars($w['grade']) ?>
              </div>
            </div>
            <span class="status-badge sent">
              Sent
            </span>
          </div>
          
          <div class="warning-content">
            <p class="warning-text"><?= nl2br(htmlspecialchars($w['reason'])) ?></p>
            <div class="warning-time">
              <i class="fas fa-clock"></i> 
              <?= date('M j, Y, g:i A', strtotime($w['issued_at'])) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-check-circle"></i>
        <h5>No Warnings Found</h5>
        <p>No warnings have been sent matching your current filters.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Warning Filters Form
document.getElementById('warningFiltersForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const params = new URLSearchParams(formData);
  
  try {
    const response = await fetch(`teacher_warnings_log.php?${params.toString()}`, {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const html = await response.text();
    
    // Parse response and update page content
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const newContent = doc.querySelector('.content-card');
    if (newContent) {
      document.querySelector('.content-card').innerHTML = newContent.innerHTML;
    }
  } catch (error) {
    console.error('Error:', error);
    // Fallback to normal form submission
    e.target.submit();
  }
});
</script>
</body>
</html>