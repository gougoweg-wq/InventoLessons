<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
include('db_connect.php');

// --- AUTH GUARD ---
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'student')) {
    header('Location: index.php');
    exit();
}

// CSRF token for cancel action
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$student_id = (int)$_SESSION['user_id'];

// --- HELPERS ---
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function isPathInDir(?string $path, string $dir): bool {
    if (!$path) return false;
    $real = @realpath($path);
    $realDir = @realpath($dir);
    return $real && $realDir && strncmp($real, $realDir, strlen($realDir)) === 0 && is_file($real);
}

// --- FETCH STUDENT DATA ---
$stmt = $conn->prepare('SELECT name, email, profile_pic FROM users WHERE id=? LIMIT 1');
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($student_name, $student_email, $student_avatar);
$stmt->fetch();
$stmt->close();

$defaultAvatar = 'uploads/basic.jpg';
$student_avatar = isPathInDir($student_avatar, __DIR__ . '/uploads') ? $student_avatar : $defaultAvatar;

// --- FETCH BOOKINGS ---
$stmt = $conn->prepare('SELECT id, subject, booking_date FROM bookings WHERE user_id=? ORDER BY booking_date DESC');
$stmt->bind_param('i', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your Bookings</title>
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

* { margin: 0; padding: 0; box-sizing: border-box; }

body { 
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  font-family: 'Inter', sans-serif;
  min-height: 100vh;
  position: relative;
}

body::before {
  content: '';
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: 
    radial-gradient(circle at 20% 80%, rgba(26, 95, 122, 0.1) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(243, 156, 18, 0.1) 0%, transparent 50%);
  pointer-events: none;
  z-index: -1;
}

.navbar { 
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
  box-shadow: 0 8px 32px rgba(26, 95, 122, 0.3);
  backdrop-filter: blur(10px);
  border-bottom: 2px solid rgba(255, 255, 255, 0.1);
  padding: 1rem 0;
}

.navbar-brand { color: #fff !important; font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; }

.profile-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.2); transition: all 0.3s ease; }
.profile-avatar:hover { transform: scale(1.1); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }

.dropdown-menu { border-radius: 12px; box-shadow: var(--shadow-lg); border: none; backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.98); }
.dropdown-item { border-radius: 8px; margin: 0.25rem 0.5rem; transition: all 0.3s ease; padding: 0.5rem 1rem; }
.dropdown-item:hover { background: linear-gradient(135deg, rgba(26, 95, 122, 0.1) 0%, rgba(26, 95, 122, 0.05) 100%); transform: translateX(5px); }

.container { max-width: 1200px; margin: 0 auto; padding: 2rem; }

.card { border: none; box-shadow: var(--shadow-xl); border-radius: 20px; background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(20px); transition: all 0.3s ease; overflow: hidden; position: relative; }
.card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--primary-color), var(--secondary-color), var(--primary-color)); background-size: 200% 100%; animation: shimmer 3s linear infinite; }
@keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
.card:hover { transform: translateY(-5px); box-shadow: 0 25px 50px rgba(0,0,0,0.2); }

h3 { color: var(--text-primary); font-weight: 700; margin-bottom: 1.5rem; position: relative; padding-left: 1rem; }
h3::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); border-radius: 2px; }

.table { background: white; border-radius: 12px; overflow: hidden; }
.table thead th { background: rgba(26, 95, 122, 0.06); color: var(--text-primary); }

.btn { border-radius: 10px; font-weight: 600; padding: 0.5rem 1rem; transition: all 0.3s ease; border: none; position: relative; overflow: hidden; }
.btn::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; border-radius: 50%; background: rgba(255, 255, 255, 0.3); transform: translate(-50%, -50%); transition: width 0.6s, height 0.6s; }
.btn:hover::before { width: 300px; height: 300px; }
.btn-primary { background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); box-shadow: 0 4px 12px rgba(26, 95, 122, 0.3); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(26, 95, 122, 0.4); }
.btn-danger { background: linear-gradient(135deg, #d9534f 0%, var(--danger-color) 100%); }
.btn-danger:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(231, 76, 60, 0.4); }

.alert { border-radius: 12px; border: none; padding: 1rem 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-md); animation: slideDown 0.3s ease-out; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
.alert-success { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); color: #155724; }
.fade-out { opacity: 0; transition: opacity 1s ease-out; }

@media (max-width: 768px) { .container { padding: 1rem; } .navbar-brand { font-size: 1.2rem; } }
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 sticky-top">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    <span class="navbar-brand"><i class="fas fa-book"></i>&nbsp; Your Bookings</span>
    <div class="dropdown">
      <button class="btn btn-light border-0 dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown">
        <img src="<?= h($student_avatar) ?>" onerror="this.src='uploads/basic.jpg';" class="profile-avatar me-2" alt="Avatar">
        <span class="d-none d-md-inline"><?= h(explode(' ', (string)$student_name)[0] ?? '') ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
        <li><a class="dropdown-item" href="book.php"><i class="fas fa-calendar-alt"></i> Book a Lesson</a></li>
        <li><a class="dropdown-item" href="history.php"><i class="fas fa-history"></i> Your Bookings</a></li>
        <li><a class="dropdown-item" href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <li><button class="dropdown-item" id="openSupport" type="button"><i class="fas fa-headset"></i> Support</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container mt-4">
  <?php if (isset($_GET['canceled'])): ?>
    <div id="alertBox" class="alert alert-success text-center fw-bold"><i class="fas fa-check-circle me-2"></i>Booking canceled successfully.</div>
  <?php elseif (isset($_GET['booked'])): ?>
    <div id="alertBox" class="alert alert-success text-center fw-bold"><i class="fas fa-check-circle me-2"></i>Booking completed successfully!</div>
  <?php endif; ?>

  <div class="card p-4 p-md-5">
    <h3><i class="fas fa-folder-open"></i> My Lessons</h3>
    <?php if (!empty($bookings)): ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle text-center">
          <thead>
            <tr>
              <th>Subject</th>
              <th>Date &amp; Time</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($bookings as $b): ?>
            <tr>
              <td><?= h($b['subject']) ?></td>
              <td><?= h($b['booking_date']) ?></td>
              <td>
                <form method="POST" action="cancel_booking.php" style="display:inline;" onsubmit="return confirmCancel();">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="btn btn-danger"><i class="fas fa-times me-1"></i>Cancel</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted text-center mt-3">You have no bookings yet.</p>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Alert auto-hide
const alertBox = document.getElementById('alertBox');
if (alertBox) {
  setTimeout(() => {
    alertBox.classList.add('fade-out');
    setTimeout(() => alertBox.remove(), 1000);
  }, 4000);
}

// Support navigation
document.getElementById('openSupport')?.addEventListener('click', () => {
  window.location.href = 'support.php';
});

// Confirm cancel
function confirmCancel() {
  return confirm('Are you sure you want to cancel this booking?');
}
</script>
</body>
</html>