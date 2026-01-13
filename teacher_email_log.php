<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

/* DB */
include('db_connect.php');

/* Require teacher login */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("SELECT id, name, email FROM teachers WHERE remember_token=? LIMIT 1");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $t = $res->fetch_assoc();
            $_SESSION['user_id'] = $t['id'];
            $_SESSION['role']    = 'teacher';
            $_SESSION['name']    = $t['name'];
            $_SESSION['email']   = $t['email'];
        } else {
            header("Location: index.php"); exit;
        }
        $stmt->close();
    } else {
        header("Location: index.php"); exit;
    }
}

$teacher_id   = (int)$_SESSION['user_id'];
$teacher_name = $_SESSION['name'] ?? '';

/* Create teacher_email_logs table if it doesn't exist */
$conn->query("CREATE TABLE IF NOT EXISTS teacher_email_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  student_id INT NULL,
  student_name VARCHAR(255) NOT NULL,
  student_grade VARCHAR(20) NOT NULL,
  parent_email VARCHAR(255) NOT NULL,
  message TEXT,
  attachment_path VARCHAR(255),
  language VARCHAR(10) DEFAULT 'en',
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

/* Enhanced Filtering */
$filterStudent = $_GET['filter_student'] ?? '';
$filterGrade = $_GET['filter_grade'] ?? '';
$filterParent = $_GET['filter_parent'] ?? '';
$filterDateFrom = $_GET['filter_date_from'] ?? '';
$filterDateTo = $_GET['filter_date_to'] ?? '';

$sql = "SELECT l.*, u.name as student_name, u.grade as student_grade, u.email as student_email
        FROM teacher_email_logs l
        LEFT JOIN users u ON l.student_id = u.id
        WHERE l.teacher_id = ?";

$params = [$teacher_id];
$types = "i";

if (!empty($filterStudent)) {
    $sql .= " AND (LOWER(l.student_name) LIKE ? OR LOWER(u.email) LIKE ?)";
    $searchTerm = '%' . strtolower($filterStudent) . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}
if (!empty($filterGrade)) {
    $sql .= " AND (u.grade = ? OR l.student_grade LIKE ?)";
    $params[] = $filterGrade;
    $params[] = '%' . $filterGrade . '%';
    $types .= "ss";
}
if (!empty($filterParent)) {
    $sql .= " AND l.parent_email LIKE ?";
    $params[] = '%' . strtolower($filterParent) . '%';
    $types .= "s";
}
if (!empty($filterDateFrom)) {
    $sql .= " AND l.sent_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
    $types .= "s";
}
if (!empty($filterDateTo)) {
    $sql .= " AND l.sent_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
    $types .= "s";
}

$sql .= " ORDER BY l.sent_at DESC LIMIT 100";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get unique values for filters
$gradesList = $conn->query("SELECT DISTINCT u.grade FROM users u WHERE u.grade IS NOT NULL ORDER BY u.grade");
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>📧 Sent Email Log - Invento</title>
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
  max-width: 1400px;
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

.email-item {
  background: var(--white);
  border-radius: 12px;
  padding: 1.5rem;
  margin-bottom: 1rem;
  border: 1px solid var(--border-color);
  border-left: 5px solid var(--info-color);
  transition: all 0.3s ease;
  position: relative;
}

.email-item:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.email-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1rem;
}

.recipient-info {
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
  margin-bottom: 0.25rem;
}

.parent-email {
  color: var(--info-color);
  font-size: 0.85rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.email-time {
  font-size: 0.8rem;
  color: var(--text-secondary);
  white-space: nowrap;
}

.email-content {
  background: var(--light-bg);
  border-radius: 8px;
  padding: 1rem;
  margin-top: 1rem;
  border-left: 3px solid var(--info-color);
}

.email-preview {
  color: var(--text-primary);
  line-height: 1.6;
  margin: 0;
  max-height: 100px;
  overflow-y: auto;
}

.email-actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 1rem;
  align-items: center;
}

.attachment-badge {
  background: rgba(243, 156, 18, 0.1);
  color: var(--warning-color);
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.8rem;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
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

.btn-info-custom {
  background: linear-gradient(135deg, var(--info-color), #2980b9);
  color: var(--white);
}

.btn-sm-custom {
  padding: 0.25rem 0.75rem;
  font-size: 0.8rem;
}

@media (max-width: 768px) {
  .main-container {
    padding: 1rem;
  }
  
  .filter-row {
    grid-template-columns: 1fr;
  }
  
  .email-header {
    flex-direction: column;
    gap: 1rem;
  }
  
  .email-actions {
    flex-wrap: wrap;
  }
}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="teachers.php">
      <i class="fas fa-envelope"></i>
      Sent Email Log
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
        Email History
      </h4>
      <span class="badge bg-primary"><?= count($logs) ?> emails sent</span>
    </div>

    <!-- Filters -->
    <div class="filter-section">
      <form method="GET" class="filter-row" id="emailFiltersForm">
        <input type="text" name="filter_student" placeholder="Search by student name or email..." 
               class="form-control form-control-custom" value="<?= htmlspecialchars($filterStudent) ?>">
        
        <select name="filter_grade" class="form-select form-select-custom">
          <option value="">All Grades</option>
          <?php while ($grade = $gradesList->fetch_assoc()): ?>
            <option value="<?= $grade['grade'] ?>" <?= $filterGrade === $grade['grade'] ? 'selected' : '' ?>>
              Grade <?= $grade['grade'] ?>
            </option>
          <?php endwhile; ?>
        </select>
        
        <input type="email" name="filter_parent" placeholder="Filter by parent email..." 
               class="form-control form-control-custom" value="<?= htmlspecialchars($filterParent) ?>">
        
        <input type="date" name="filter_date_from" placeholder="From date" 
               class="form-control form-control-custom" value="<?= htmlspecialchars($filterDateFrom) ?>">
        
        <input type="date" name="filter_date_to" placeholder="To date" 
               class="form-control form-control-custom" value="<?= htmlspecialchars($filterDateTo) ?>">
        
        <button type="submit" class="btn btn-custom btn-primary-custom">
          <i class="fas fa-filter"></i> Apply Filters
        </button>
        
        <a href="teacher_email_log.php" class="btn btn-custom btn-secondary-custom">
          <i class="fas fa-times"></i> Clear
        </a>
      </form>
    </div>

    <!-- Email Logs -->
    <?php if (!empty($logs)): ?>
      <?php foreach ($logs as $log): ?>
        <div class="email-item">
          <div class="email-header">
            <div class="recipient-info">
              <div class="student-name"><?= htmlspecialchars($log['student_name']) ?></div>
              <div class="student-meta">
                <i class="fas fa-graduation-cap"></i> Grade <?= htmlspecialchars($log['student_grade']) ?>
                <?php if ($log['student_email']): ?>
                  • <i class="fas fa-envelope"></i> <?= htmlspecialchars($log['student_email']) ?>
                <?php endif; ?>
              </div>
              <?php if ($log['parent_email']): ?>
                <div class="parent-email">
                  <i class="fas fa-user-friends"></i>
                  <a href="mailto:<?= htmlspecialchars($log['parent_email']) ?>">
                    <?= htmlspecialchars($log['parent_email']) ?>
                  </a>
                </div>
              <?php endif; ?>
            </div>
            <div class="email-time">
              <i class="fas fa-clock"></i>
              <?= date('M j, Y, g:i A', strtotime($log['sent_at'])) ?>
            </div>
          </div>
          
          <?php if ($log['message']): ?>
            <div class="email-content">
              <div class="email-preview">
                <?= strip_tags(substr($log['message'], 0, 300)) ?>...
              </div>
            </div>
          <?php endif; ?>
          
          <div class="email-actions">
            <?php if ($log['attachment_path']): ?>
              <span class="attachment-badge">
                <i class="fas fa-paperclip"></i>
                Attachment
              </span>
              <a href="<?= htmlspecialchars($log['attachment_path']) ?>" 
                 class="btn btn-custom btn-info-custom btn-sm-custom" 
                 download>
                <i class="fas fa-download"></i> Download
              </a>
            <?php endif; ?>
            
            <button class="btn btn-custom btn-primary-custom btn-sm-custom" 
                    onclick="viewEmailContent(<?= $log['id'] ?>)">
              <i class="fas fa-eye"></i> View Full
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <h5>No Emails Found</h5>
        <p>No emails have been sent matching your current filters.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Email Content Modal -->
<div class="modal fade" id="emailModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">
          <i class="fas fa-envelope"></i>
          Email Content
        </h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="emailContent"></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Email Filters Form
document.getElementById('emailFiltersForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const params = new URLSearchParams(formData);
  
  try {
    const response = await fetch(`teacher_email_log.php?${params.toString()}`, {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const html = await response.text();
    
    // Parse response and update the page content
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

// Store email data for modal
const emailData = <?= json_encode($logs) ?>;

function viewEmailContent(emailId) {
  const email = emailData.find(e => e.id == emailId);
  if (!email) return;
  
  const content = `
    <div class="mb-3">
      <strong>To:</strong> ${email.student_name} (${email.student_email})
    </div>
    ${email.parent_email ? `
      <div class="mb-3">
        <strong>Parent Email:</strong> <a href="mailto:${email.parent_email}">${email.parent_email}</a>
      </div>
    ` : ''}
    <div class="mb-3">
      <strong>Sent:</strong> ${new Date(email.sent_at).toLocaleString()}
    </div>
    ${email.attachment_path ? `
      <div class="mb-3">
        <strong>Attachment:</strong> <a href="${email.attachment_path}" download>Download</a>
      </div>
    ` : ''}
    <hr>
    <div class="email-full-content">
      ${email.message || 'No content available'}
    </div>
  `;
  
  document.getElementById('emailContent').innerHTML = content;
  new bootstrap.Modal(document.getElementById('emailModal')).show();
}
</script>
</body>
</html>