<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

$student_id = $_SESSION['user_id'];

/* --- Fetch student info --- */
$stmt = $conn->prepare("SELECT name, grade, email, profile_pic FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($name, $grade, $email, $avatar);
$stmt->fetch();
$stmt->close();

// Default avatar if none exists
$defaultAvatar = 'uploads/basic.jpg';
if (empty($avatar) || !file_exists($avatar)) {
    $avatar = $defaultAvatar;
}

/* --- Get notification counts --- */
$warningsCount = 0;
$announcementsCount = 0;

// Count recent warnings (last 7 days)
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM student_warnings WHERE student_id=? AND issued_at > (NOW() - INTERVAL 7 DAY)");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $warningsCount = (int)$row['c'];
}
$stmt->close();

// Count recent announcements (last 7 days)
$stmt = $conn->query("SELECT COUNT(*) as c FROM announcements WHERE created_at > (NOW() - INTERVAL 7 DAY)");
if ($row = $stmt->fetch_assoc()) {
    $announcementsCount = (int)$row['c'];
}

$totalBadge = $warningsCount + $announcementsCount;
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📊 Student Reports - Invento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.navbar {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}
.navbar-brand {
    color: #667eea !important;
    font-weight: 700;
    font-size: 1.4rem;
}
.profile-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #667eea;
}
.badge-counter {
    background: #dc3545;
    color: white;
    border-radius: 50%;
    font-size: 10px;
    position: absolute;
    top: 5px;
    right: 5px;
    padding: 3px 6px;
    font-weight: bold;
}
.report-card {
    background: white;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    padding: 40px;
    margin-top: 40px;
    animation: fadeInUp 0.5s ease;
}
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
.report-card h4 {
    color: #667eea;
    font-weight: 700;
    margin-bottom: 30px;
}
.form-label {
    font-weight: 600;
    color: #333;
    margin-bottom: 8px;
}
.form-select, .form-control {
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    padding: 12px;
    transition: all 0.3s;
}
.form-select:focus, .form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}
.btn-generate {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    padding: 15px 40px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 1.1rem;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}
.btn-generate:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
}
.report-info {
    background: #f8f9fa;
    border-left: 4px solid #667eea;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 30px;
}
.report-info p {
    margin: 5px 0;
    color: #555;
}
.report-type-card {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    padding: 20px;
    border-radius: 15px;
    margin-bottom: 15px;
    cursor: pointer;
    transition: all 0.3s;
    border: 2px solid transparent;
}
.report-type-card:hover {
    border-color: #667eea;
    transform: scale(1.02);
}
.report-type-card.selected {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
}
footer {
    color: white;
    text-align: center;
    padding: 20px 0;
    margin-top: 50px;
}
</style>
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-light px-4">
    <div class="container-fluid">
        <span class="navbar-brand">📊 Reports Dashboard</span>
        <div class="dropdown position-relative">
            <button class="btn btn-light border-0 dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown">
                <img src="<?= htmlspecialchars($avatar) ?>" onerror="this.src='uploads/basic.jpg';" class="profile-avatar me-2">
                <span class="d-none d-md-inline"><?= htmlspecialchars($name) ?></span>
                
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="profile.php">👤 Profile</a></li>
                <li><a class="dropdown-item" href="book.php">🗓️ Book a Lesson</a></li>
                <li><a class="dropdown-item" href="history.php">📘 Your Bookings</a></li>
                <li><a class="dropdown-item" href="reports.php">📊 Reports</a></li>
                <li><button class="dropdown-item" id="openSupport" type="button">💬 Support</button></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="logout.php">🚪 Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- Main Content -->
<div class="container">
    <div class="report-card">
        <h4 class="text-center">📄 Generate Your Report</h4>
        
        <!-- Student Info -->
        <div class="report-info">
            <p><strong>Student:</strong> <?= htmlspecialchars($name) ?></p>
            <p><strong>Grade:</strong> <?= htmlspecialchars($grade) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
        </div>

        <!-- Report Generation Form -->
        <form id="reportForm" method="GET" action="report_pdf.php" target="_blank">
            <div class="row g-3">
                <!-- Report Type -->
                <div class="col-12">
                    <label class="form-label">Select Report Type</label>
                    <select class="form-select" name="type" id="reportType" required>
                        <option value="">-- Choose Report Type --</option>
                        <option value="attendance">📊 Attendance Summary</option>
                        <option value="subject">📚 Subjects Overview</option>
                        <option value="monthly">📅 Monthly Progress</option>
                        <option value="feedback">💬 Teacher Feedback</option>
                    </select>
                    <small class="text-muted">Choose the type of report you want to generate</small>
                </div>

                <!-- From/To dates (non-monthly) -->
                <div class="col-md-6" id="groupFrom" style="display:none;">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="from" id="fromDate"
                           value="<?= date('Y-m-01') ?>" 
                           max="<?= date('Y-m-d') ?>" disabled>
                </div>
                <div class="col-md-6" id="groupTo" style="display:none;">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="to" id="toDate"
                           value="<?= date('Y-m-d') ?>" 
                           max="<?= date('Y-m-d') ?>" disabled>
                </div>

                <!-- Month/Year (monthly only) -->
                <div class="col-md-6" id="groupMonth" style="display:none;">
                    <label class="form-label">Month</label>
                    <select class="form-select" name="month" id="reportMonth" disabled>
                        <?php for($m=1;$m<=12;$m++): $sel = ($m==(int)date('n'))?'selected':''; ?>
                            <option value="<?= $m ?>" <?= $sel ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6" id="groupYear" style="display:none;">
                    <label class="form-label">Year</label>
                    <select class="form-select" name="year" id="reportYear" disabled>
                        <?php $cy=(int)date('Y'); for($y=$cy-2;$y<=$cy+1;$y++): $sel=($y==$cy)?'selected':''; ?>
                            <option value="<?= $y ?>" <?= $sel ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-generate">
                    📥 Generate & Download PDF
                </button>
            </div>
        </form>

        <!-- Report Type Descriptions -->
        <div class="mt-5">
            <h6 class="text-muted mb-3">Report Type Descriptions:</h6>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">📊 Attendance Summary</h6>
                            <p class="card-text small">Overview of your attendance status including visited, booked, canceled, and missed lessons with charts.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">📚 Subjects Overview</h6>
                            <p class="card-text small">Performance breakdown by subject showing attendance rates for each subject you're enrolled in.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">📅 Monthly Progress</h6>
                            <p class="card-text small">Monthly summary of your overall progress including total lessons and attendance rate.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">💬 Teacher Feedback</h6>
                            <p class="card-text small">Collection of all teacher comments and feedback for your lessons during the selected period.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Footer -->
<footer>
    <p>© <?= date('Y') ?> Invento – The Uzbek International School</p>
    <p class="small">Correctional Lessons Portal</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Support button handler
document.getElementById('openSupport').addEventListener('click', function() {
    window.location.href = 'support.php';
});

// Toggle inputs by report type
(function(){
  const typeSel = document.getElementById('reportType');
  const gFrom = document.getElementById('groupFrom');
  const gTo = document.getElementById('groupTo');
  const gMonth = document.getElementById('groupMonth');
  const gYear = document.getElementById('groupYear');
  const fromI = document.getElementById('fromDate');
  const toI = document.getElementById('toDate');
  const monthI = document.getElementById('reportMonth');
  const yearI = document.getElementById('reportYear');

  function toggle() {
    const isMonthly = typeSel.value === 'monthly';
    // Monthly: show month/year only
    gMonth.style.display = gYear.style.display = isMonthly ? '' : 'none';
    monthI.disabled = yearI.disabled = !isMonthly;
    // Others: show from/to only
    const showRange = !isMonthly && !!typeSel.value;
    gFrom.style.display = gTo.style.display = showRange ? '' : 'none';
    fromI.disabled = toI.disabled = !showRange;
  }
  typeSel.addEventListener('change', toggle);
  toggle();
})();

// Form validation + loading
document.getElementById('reportForm').addEventListener('submit', function(e) {
    const typeSel = document.getElementById('reportType');
    if (typeSel.value !== 'monthly') {
        const fromI = document.getElementById('fromDate');
        const toI = document.getElementById('toDate');
        if (!fromI.disabled && !toI.disabled) {
            const fromDate = new Date(fromI.value);
            const toDate = new Date(toI.value);
            if (fromDate > toDate) {
                e.preventDefault();
                alert('⚠️ "From Date" cannot be later than "To Date"!');
                return false;
            }
        }
    }
    const btn = this.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Generating PDF...';
    btn.disabled = true;
    setTimeout(() => { btn.innerHTML = originalText; btn.disabled = false; }, 3000);
});
</script>
</body>
</html>