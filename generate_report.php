<?php
session_start();
include('db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, grade, email FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($name, $grade, $email);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Generate Reports - Invento</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
  color: white !important;
  font-weight: 700;
  font-size: 1.5rem;
  text-decoration: none;
}

.container {
  max-width: 800px;
  margin-top: 3rem;
}

.report-card {
  background: white;
  border-radius: 16px;
  padding: 3rem;
  box-shadow: var(--shadow-xl);
  border: 1px solid var(--border-color);
  position: relative;
  overflow: hidden;
}

.report-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--primary-color), var(--secondary-color), var(--primary-color));
}

.report-header {
  text-align: center;
  margin-bottom: 2.5rem;
}

.report-icon {
  width: 80px;
  height: 80px;
  background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.5rem;
  box-shadow: var(--shadow-lg);
}

.report-icon i {
  font-size: 2rem;
  color: white;
}

.report-title {
  color: var(--text-primary);
  font-weight: 700;
  font-size: 2rem;
  margin-bottom: 0.5rem;
}

.report-subtitle {
  color: var(--text-secondary);
  font-size: 1.1rem;
}

.student-info {
  background: var(--light-bg);
  border-radius: 12px;
  padding: 1.5rem;
  margin-bottom: 2rem;
  border-left: 4px solid var(--primary-color);
}

.student-info h5 {
  color: var(--primary-color);
  font-weight: 600;
  margin-bottom: 1rem;
}

.info-item {
  display: flex;
  align-items: center;
  margin-bottom: 0.5rem;
}

.info-item i {
  color: var(--primary-color);
  width: 20px;
  margin-right: 0.75rem;
}

.form-floating {
  margin-bottom: 1.5rem;
}

.form-select, .form-control {
  border: 2px solid var(--border-color);
  border-radius: 8px;
  padding: 0.75rem;
  font-weight: 500;
  transition: all 0.3s ease;
  background: white;
}

.form-select:focus, .form-control:focus {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px rgba(26, 95, 122, 0.1);
  outline: none;
}

.btn-primary {
  background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
  border: none;
  border-radius: 8px;
  padding: 0.75rem 2rem;
  font-weight: 600;
  font-size: 1.1rem;
  transition: all 0.3s ease;
  box-shadow: var(--shadow-md);
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-lg);
}

.report-types {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  margin-bottom: 2rem;
}

.report-type-card {
  background: white;
  border: 2px solid var(--border-color);
  border-radius: 12px;
  padding: 1.5rem;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s ease;
  position: relative;
}

.report-type-card:hover {
  border-color: var(--primary-color);
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.report-type-card.selected {
  border-color: var(--primary-color);
  background: rgba(26, 95, 122, 0.05);
}

.report-type-icon {
  font-size: 2rem;
  color: var(--primary-color);
  margin-bottom: 0.75rem;
}

.report-type-title {
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 0.5rem;
}

.report-type-desc {
  font-size: 0.9rem;
  color: var(--text-secondary);
}

@media (max-width: 768px) {
  .container {
    margin-top: 1rem;
    padding: 1rem;
  }
  
  .report-card {
    padding: 2rem 1.5rem;
  }
  
  .report-title {
    font-size: 1.5rem;
  }
}
</style>
</head>
<body>

<!-- Enhanced Navbar -->
<nav class="navbar navbar-dark px-4">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="student_dashboard.php">
      <i class="fas fa-arrow-left me-2"></i>
      <span>Student Dashboard</span>
    </a>
    <div class="d-flex align-items-center">
      <span class="text-white-50 small"><?= htmlspecialchars($email) ?></span>
    </div>
  </div>
</nav>

<div class="container">
  <div class="report-card">
    <div class="report-header">
      <div class="report-icon">
        <i class="fas fa-file-alt"></i>
      </div>
      <h1 class="report-title">Generate Report</h1>
      <p class="report-subtitle">Create detailed academic and attendance reports</p>
    </div>

    <div class="student-info">
      <h5><i class="fas fa-user me-2"></i>Student Information</h5>
      <div class="info-item">
        <i class="fas fa-user"></i>
        <strong>Name:</strong> <?= htmlspecialchars($name) ?>
      </div>
      <div class="info-item">
        <i class="fas fa-graduation-cap"></i>
        <strong>Grade:</strong> <?= htmlspecialchars($grade) ?>
      </div>
      <div class="info-item">
        <i class="fas fa-envelope"></i>
        <strong>Email:</strong> <?= htmlspecialchars($email) ?>
      </div>
    </div>

    <form method="GET" action="report_pdf.php" target="_blank">
      <div class="mb-4">
        <label class="form-label fw-bold">
          <i class="fas fa-clipboard-list me-2"></i>Select Report Type
        </label>
        <div class="report-types">
          <div class="report-type-card" data-type="attendance">
            <div class="report-type-icon">
              <i class="fas fa-calendar-check"></i>
            </div>
            <div class="report-type-title">Attendance Report</div>
            <div class="report-type-desc">Detailed attendance statistics and charts</div>
          </div>
          <div class="report-type-card" data-type="subject">
            <div class="report-type-icon">
              <i class="fas fa-book"></i>
            </div>
            <div class="report-type-title">Subject Performance</div>
            <div class="report-type-desc">Performance analysis by subject</div>
          </div>
          <div class="report-type-card" data-type="monthly">
            <div class="report-type-icon">
              <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="report-type-title">Monthly Summary</div>
            <div class="report-type-desc">Comprehensive monthly overview</div>
          </div>
          <div class="report-type-card" data-type="feedback">
            <div class="report-type-icon">
              <i class="fas fa-comments"></i>
            </div>
            <div class="report-type-title">Teacher Feedback</div>
            <div class="report-type-desc">Compiled teacher comments and feedback</div>
          </div>
        </div>
        <select name="type" class="form-select d-none" required>
          <option value="attendance">Attendance Report</option>
          <option value="subject">Subject Performance Report</option>
          <option value="monthly">Monthly Summary Report</option>
          <option value="feedback">Teacher Feedback Report</option>
        </select>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <div class="form-floating">
            <input type="date" name="from" class="form-control" id="from" required>
            <label for="from"><i class="fas fa-calendar me-2"></i>From Date</label>
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-floating">
            <input type="date" name="to" class="form-control" id="to" required>
            <label for="to"><i class="fas fa-calendar me-2"></i>To Date</label>
          </div>
        </div>
      </div>

      <div class="text-center">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-file-pdf me-2"></i>Generate PDF Report
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Report type selection
document.querySelectorAll('.report-type-card').forEach(card => {
  card.addEventListener('click', function() {
    // Remove selected class from all cards
    document.querySelectorAll('.report-type-card').forEach(c => c.classList.remove('selected'));
    
    // Add selected class to clicked card
    this.classList.add('selected');
    
    // Update hidden select
    const type = this.dataset.type;
    document.querySelector('select[name="type"]').value = type;
  });
});

// Set default dates
document.addEventListener('DOMContentLoaded', function() {
  const today = new Date();
  const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
  const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
  
  document.getElementById('from').value = firstDay.toISOString().split('T')[0];
  document.getElementById('to').value = lastDay.toISOString().split('T')[0];
  
  // Select first report type by default
  document.querySelector('.report-type-card').click();
});

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
  const fromDate = new Date(document.getElementById('from').value);
  const toDate = new Date(document.getElementById('to').value);
  
  if (fromDate > toDate) {
    e.preventDefault();
    alert('From date cannot be later than To date.');
    return;
  }
  
  if (!document.querySelector('.report-type-card.selected')) {
    e.preventDefault();
    alert('Please select a report type.');
    return;
  }
});

// Smooth animations
document.querySelectorAll('.report-type-card').forEach((card, index) => {
  card.style.opacity = '0';
  card.style.transform = 'translateY(20px)';
  setTimeout(() => {
    card.style.transition = 'all 0.6s ease';
    card.style.opacity = '1';
    card.style.transform = 'translateY(0)';
  }, index * 100);
});
</script>
</body>
</html>
