<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

// ✅ Admin access check
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
    exit("<div class='alert alert-danger m-3'>Access denied.</div>");
}

// ---------------------------
// CSRF token (for delete)
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

// ✅ Delete booking (Soft delete if status exists, otherwise remove)
if (isset($_POST['delete_booking'])) {
    // CSRF validation
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die("<script>alert('Invalid CSRF token. Please reload and try again.');history.back();</script>");
    }

    $bookingId = intval($_POST['delete_booking']);
    $hasStatus = $conn->query("SHOW COLUMNS FROM bookings LIKE 'status'")->num_rows > 0;

    if ($hasStatus) {
        $stmt = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE id=?");
        $stmt->bind_param("i", $bookingId);
    } else {
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id=?");
        $stmt->bind_param("i", $bookingId);
    }
    $stmt->execute();
    $stmt->close();
}

// ✅ Filters
$filterGrade = $_GET['grade'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterTeacher = $_GET['teacher'] ?? '';
$filterStudent = $_GET['student'] ?? '';
$filterTime = $_GET['time'] ?? 'all';
$searchTerm = trim($_GET['search'] ?? '');

// ✅ Dynamic conditions
$conditions = [];
if ($filterGrade !== '') $conditions[] = "u.grade = '".$conn->real_escape_string($filterGrade)."'";
if ($filterStatus !== '') $conditions[] = "b.status = '".$conn->real_escape_string($filterStatus)."'";
if ($filterTeacher !== '') $conditions[] = "b.teacher_id = '".intval($filterTeacher)."'";
if ($filterStudent !== '') $conditions[] = "u.id = '".intval($filterStudent)."'";

if ($filterTime === 'upcoming') $conditions[] = "b.booking_date >= NOW()";
elseif ($filterTime === 'past') $conditions[] = "b.booking_date < NOW()";

if ($searchTerm !== '') {
    $safe = $conn->real_escape_string($searchTerm);
    $conditions[] = "(u.name LIKE '%$safe%' OR b.subject LIKE '%$safe%')";
}

$whereSQL = count($conditions) ? ("WHERE ".implode(" AND ", $conditions)) : "";

// ✅ Teacher column exists?
$hasTeacherId = $conn->query("SHOW COLUMNS FROM bookings LIKE 'teacher_id'")->num_rows > 0;
$teacherJoin = $hasTeacherId ? "LEFT JOIN teachers t ON t.id=b.teacher_id" : "";

// ✅ Fetch bookings
$sql = "
 SELECT b.id, b.subject, b.booking_date, b.status,
        u.name AS student_name, u.grade,
        ".($hasTeacherId ? "t.name AS teacher_name," : "'-' AS teacher_name,")."
        u.id AS student_id
 FROM bookings b
 JOIN users u ON b.user_id=u.id
 $teacherJoin
 $whereSQL
 ORDER BY b.booking_date DESC
";
$bookings = $conn->query($sql);

// ✅ Fetch teacher list (for filters)
$teachers = [];
if ($hasTeacherId) {
    $tr = $conn->query("SELECT id, name FROM teachers ORDER BY name ASC");
    while ($t = $tr->fetch_assoc()) $teachers[] = $t;
}

// ✅ Fetch students list (for filters)
$students = $conn->query("SELECT id, name, grade FROM users ORDER BY grade, name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bookings List</title>
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
  padding: 2rem;
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

.container {
  background: white;
  border-radius: 16px;
  padding: 2rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid var(--border-color);
}

h3 {
  color: var(--text-primary);
  font-weight: 700;
  margin-bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* White button theme */
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
  border-radius: 8px;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-secondary:hover {
  background: #6c757d;
  color: white;
  transform: translateY(-2px);
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

.table {
  background: white;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: var(--shadow-md);
  border: 1px solid var(--border-color);
}

.table th {
  background: #f8fafc;
  border-bottom: 2px solid var(--border-color);
  font-weight: 600;
  color: var(--text-primary);
  padding: 1rem;
}

.table td {
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  padding: 1rem;
}

.table tbody tr:hover {
  background: #f8fafc;
}

.badge {
  padding: 0.5rem 0.75rem;
  border-radius: 20px;
  font-weight: 500;
  font-size: 0.85rem;
}

.badge-success {
  background: rgba(39, 174, 96, 0.1);
  color: var(--success-color);
  border: 1px solid rgba(39, 174, 96, 0.2);
}

.badge-warning {
  background: rgba(243, 156, 18, 0.1);
  color: var(--warning-color);
  border: 1px solid rgba(243, 156, 18, 0.2);
}

.badge-danger {
  background: rgba(231, 76, 60, 0.1);
  color: var(--danger-color);
  border: 1px solid rgba(231, 76, 60, 0.2);
}

.badge-info {
  background: rgba(52, 152, 219, 0.1);
  color: var(--info-color);
  border: 1px solid rgba(52, 152, 219, 0.2);
}
</style>
</head>
<body>

<div class="container">
    <h3 class="mb-3">📚 Bookings Management</h3>
    <a href="admin_dashboard.php#bookings" class="btn btn-secondary mb-3">← Back to Dashboard</a>

    <!-- ✅ Filters -->
    <form method="GET" class="row g-2 mb-4">
        <div class="col-md-2">
            <select name="grade" class="form-select">
                <option value="">Grade (All)</option>
                <?php for ($i=6; $i<=12; $i++): ?>
                    <option value="<?= $i ?>" <?= $filterGrade==$i?'selected':'' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">Status (All)</option>
                <option value="booked" <?= $filterStatus=='booked'?'selected':'' ?>>Booked</option>
                <option value="visited" <?= $filterStatus=='visited'?'selected':'' ?>>Visited</option>
                <option value="not attended" <?= $filterStatus=='not attended'?'selected':'' ?>>Not Attended</option>
                <option value="cancelled" <?= $filterStatus=='cancelled'?'selected':'' ?>>Cancelled</option>
            </select>
        </div>

        <?php if ($hasTeacherId): ?>
        <div class="col-md-2">
            <select name="teacher" class="form-select">
                <option value="">Teacher (All)</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $filterTeacher==$t['id']?'selected':'' ?>>
                        <?= htmlspecialchars($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="col-md-2">
            <select name="time" class="form-select">
                <option value="all" <?= $filterTime=='all'?'selected':'' ?>>All Time</option>
                <option value="upcoming" <?= $filterTime=='upcoming'?'selected':'' ?>>Upcoming</option>
                <option value="past" <?= $filterTime=='past'?'selected':'' ?>>Past</option>
            </select>
        </div>

        <div class="col-md-2">
            <select name="student" class="form-select">
                <option value="">Student (All)</option>
                <?php while($s = $students->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>" <?= ($filterStudent ?? '') == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (Grade <?= $s['grade'] ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-2">
            <input type="text" name="search" class="form-control" placeholder="Search student or subject..." value="<?= htmlspecialchars($searchTerm) ?>">
        </div>

        <div class="col-md-1">
            <button class="btn btn-primary w-100">Filter</button>
        </div>
    </form>

    <!-- ✅ Bookings Table -->
    <div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Student</th>
                <th>Grade</th>
                <?php if ($hasTeacherId): ?><th>Teacher</th><?php endif; ?>
                <th>Subject</th>
                <th>Date & Time</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php
            // compute correct colspan for the "no bookings" row
            $colCount = 7 + ($hasTeacherId ? 1 : 0);
        ?>
        <?php if ($bookings->num_rows == 0): ?>
            <tr><td colspan="<?= $colCount ?>" class="text-center">No bookings found.</td></tr>
        <?php else: ?>
            <?php while($b = $bookings->fetch_assoc()): ?>
            <tr>
                <td><?= $b['id'] ?></td>
                <td><?= htmlspecialchars($b['student_name']) ?></td>
                <td><?= htmlspecialchars($b['grade']) ?></td>
                <?php if ($hasTeacherId): ?>
                <td><?= htmlspecialchars($b['teacher_name'] ?? '-') ?></td>
                <?php endif; ?>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= htmlspecialchars($b['booking_date']) ?></td>
                <td>
                    <?php
                    // map statuses to bootstrap color classes
                    $badge = match($b['status']) {
                        'visited' => 'success',
                        'not attended' => 'danger',
                        'cancelled' => 'secondary',
                        default => 'primary',
                    };
                    ?>
                    <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($b['status'] ?? 'booked') ?></span>
                </td>
                <td>
                    <a href="admin_edit_booking.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
                        <button name="delete_booking" value="<?= $b['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this booking?')">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
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
  
  // Cascading Filters - Auto-apply grade when student is selected
  document.querySelector('select[name="student"]')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const match = selectedOption.text.match(/\(Grade (\d+)\)/);
    if (match) {
      const grade = match[1];
      document.querySelector('select[name="grade"]').value = grade;
    }
  });
});
</script>
</body>
</html>
