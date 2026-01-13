<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/**
 * -------------------------------------------
 *  AUTO LOGIN VIA COOKIE (your original logic)
 * -------------------------------------------
 */
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];

    // Try student
    $stmt = $conn->prepare("SELECT id FROM users WHERE remember_token=? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($uid);
    if ($stmt->fetch()) {
        $_SESSION['user_id'] = $uid;
        $_SESSION['role'] = 'student';
        $stmt->close();
        header("Location: book.php");
        exit();
    }
    $stmt->close();

    // Try teacher
    $stmt = $conn->prepare("SELECT id FROM teachers WHERE remember_token=? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($uid);
    if ($stmt->fetch()) {
        $_SESSION['user_id'] = $uid;
        $_SESSION['role'] = 'teacher';
        $stmt->close();
        header("Location: teachers.php");
        exit();
    }
    $stmt->close();
}

/**
 * -------------------------------------------
 *  FETCH UPCOMING LESSONS (ICS)
 * -------------------------------------------
 */
$ics_url = "https://calendar.google.com/calendar/ical/c_fa8beeecc764d2836e99bf057540e15f037c8a762be33c3a0a660a1f45862f90%40group.calendar.google.com/public/basic.ics";
$ch = curl_init($ics_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
$ics_content = curl_exec($ch);
curl_close($ch);

$upcomingLessons = [];
if ($ics_content) {
    $tz = new DateTimeZone('Asia/Tashkent');
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics_content, $matches);
    foreach ($matches[1] as $event) {
        preg_match('/DTSTART(?:;TZID=.*)?:([\dT]+Z?)/', $event, $start);
        preg_match('/SUMMARY:(.+)/', $event, $summary);
        if (!empty($start[1]) && !empty($summary[1])) {
            $dateStr = $start[1];
            $date = str_ends_with($dateStr, 'Z')
                ? new DateTime($dateStr, new DateTimeZone('UTC'))
                : DateTime::createFromFormat('Ymd\THis', $dateStr, $tz);
            if ($date && str_ends_with($dateStr, 'Z')) $date->setTimezone($tz);
            if ($date && $date > new DateTime("now", $tz)) {
                $upcomingLessons[] = ["date" => $date, "summary" => trim($summary[1])];
            }
        }
    }
    usort($upcomingLessons, fn($a, $b) => $a['date'] <=> $b['date']);
    $upcomingLessons = array_slice($upcomingLessons, 0, 5);
}

$error = "";

/**
 * -------------------------------------------
 *  LOGIN HANDLING (Admin → Teacher → Student)
 * -------------------------------------------
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);
    $remember = !empty($_POST["remember"]);

    /**
     * -------------------------
     * ADMIN LOGIN (updated)
     * -------------------------
     */
    if ($email === 'admin@invento.uz') {
        $stmtA = $conn->prepare("SELECT id, name, email, password, role FROM admins WHERE email=? LIMIT 1");
        $stmtA->bind_param("s", $email);
        $stmtA->execute();
        $resA = $stmtA->get_result();
        if ($resA->num_rows === 1) {
            $admin = $resA->fetch_assoc();
            if (password_verify($password, $admin['password']) || $password === $admin['password']) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = $admin['role'];

                // Log admin login
                $log = $conn->prepare("INSERT INTO logs (admin_email, action) VALUES (?, 'Admin login')");
                $log->bind_param("s", $admin['email']);
                $log->execute();
                $log->close();

                header("Location: admin_dashboard.php");
                exit();
            } else {
                $error = "❌ Invalid password.";
            }
        } else {
            $error = "❌ Admin account not found.";
        }
        $stmtA->close();

    } else {
        /**
         * -------------------------
         * TEACHER LOGIN
         * -------------------------
         */
        $stmt = $conn->prepare("SELECT id, name, email, password FROM teachers WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $teacherRes = $stmt->get_result();

        if ($teacherRes->num_rows === 1) {
            $teacher = $teacherRes->fetch_assoc();
            if (password_verify($password, $teacher['password']) || $password === $teacher['password']) {
                $_SESSION['user_id'] = $teacher['id'];
                $_SESSION['email'] = $teacher['email'];
                $_SESSION['name'] = $teacher['name'];
                $_SESSION['role'] = 'teacher';

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (86400 * 30), "/", "", false, true);
                    $upd = $conn->prepare("UPDATE teachers SET remember_token=? WHERE id=?");
                    $upd->bind_param("si", $token, $teacher['id']);
                    $upd->execute();
                    $upd->close();
                }

                header("Location: teachers.php");
                exit();
            } else {
                $error = "❌ Invalid password.";
            }
        } else {
            /**
             * -------------------------
             * STUDENT LOGIN
             * -------------------------
             */
            $stmt2 = $conn->prepare("SELECT id, name, email, grade, profile_pic, password FROM users WHERE email=?");
            $stmt2->bind_param("s", $email);
            $stmt2->execute();
            $userRes = $stmt2->get_result();

            if ($userRes->num_rows === 1) {
                $user = $userRes->fetch_assoc();
                if ($user['password'] === md5($password) || password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['student_name'] = $user['name'];
                    $_SESSION['grade'] = $user['grade'];
                    $_SESSION['profile_pic'] = $user['profile_pic'];
                    $_SESSION['role'] = 'student';

                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (86400 * 30), "/", "", false, true);
                        $upd = $conn->prepare("UPDATE users SET remember_token=? WHERE id=?");
                        $upd->bind_param("si", $token, $user['id']);
                        $upd->execute();
                        $upd->close();
                    }

                    header("Location: book.php");
                    exit();
                } else {
                    $error = "❌ Invalid password.";
                }
            } else {
                $error = "❌ Account not found.";
            }
            $stmt2->close();
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Correctional Lessons Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
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
  --light-bg: #f8f9fa;
  --card-shadow: 0 4px 6px rgba(0,0,0,0.07);
  --hover-shadow: 0 8px 15px rgba(0,0,0,0.1);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Inter', sans-serif;
  background: #ffffff;
  color: #2c3e50;
  line-height: 1.6;
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

/* Header/Navbar */
.navbar {
  background: var(--primary-color);
  padding: 1rem 0;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.navbar-brand {
  color: white !important;
  font-weight: 700;
  font-size: 1.5rem;
  text-decoration: none;
}

.navbar-brand:hover {
  color: var(--secondary-color) !important;
}

.btn-login {
  background: transparent;
  color: white;
  border: 2px solid white;
  padding: 0.5rem 1.5rem;
  border-radius: 50px;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-login:hover {
  background: white;
  color: var(--primary-color);
  transform: translateY(-2px);
}

/* Hero Section */
.hero {
  text-align: center;
  padding: 120px 20px 80px;
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
  position: relative;
  overflow: hidden;
}

.hero::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff22" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,133.3C960,128,1056,96,1152,96C1248,96,1344,128,1392,144L1440,160L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
  background-size: cover;
}

.hero h1 {
  font-size: 3.5rem;
  font-weight: 800;
  margin-bottom: 1.5rem;
  position: relative;
  z-index: 1;
}

.hero p {
  font-size: 1.3rem;
  max-width: 800px;
  margin: 0 auto 1.5rem;
  opacity: 0.95;
  position: relative;
  z-index: 1;
}

.hero strong {
  color: var(--secondary-color);
  font-weight: 600;
}

/* Sections */
.section {
  padding: 80px 20px;
}

.section h2 {
  text-align: center;
  font-weight: 700;
  font-size: 2.5rem;
  margin-bottom: 3rem;
  color: var(--primary-color);
}

/* Feature Cards */
.feature-card {
  background: white;
  border-radius: 15px;
  padding: 2rem;
  margin-bottom: 2rem;
  box-shadow: var(--card-shadow);
  transition: all 0.3s ease;
  border: none;
  text-align: center;
}

.feature-card:hover {
  transform: translateY(-5px);
  box-shadow: var(--hover-shadow);
}

.feature-card h5 {
  font-size: 2.5rem;
  margin-bottom: 1rem;
  color: var(--primary-color);
}

.feature-card p {
  color: #6c757d;
  font-size: 1.1rem;
}

/* Lesson Cards */
.lesson-card {
  background: white;
  border-radius: 12px;
  padding: 1.5rem;
  margin-bottom: 1rem;
  box-shadow: var(--card-shadow);
  transition: all 0.3s ease;
  border-left: 4px solid var(--primary-color);
}

.lesson-card:hover {
  transform: translateX(5px);
  box-shadow: var(--hover-shadow);
}

.lesson-card strong {
  color: var(--primary-color);
  font-weight: 600;
  font-size: 1.1rem;
}

/* Buttons */
.btn-primary {
  background: var(--primary-color);
  border: none;
  padding: 0.75rem 2rem;
  border-radius: 50px;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-primary:hover {
  background: var(--primary-dark);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(26, 95, 122, 0.3);
}

/* Footer */
footer {
  background: var(--primary-color);
  color: white;
  text-align: center;
  padding: 2rem;
  margin-top: 0;
}

/* Modal */
.modal-content {
  border: none;
  border-radius: 15px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.15);
}

.modal-header {
  background: var(--primary-color);
  color: white;
  border-radius: 15px 15px 0 0;
  border: none;
}

.modal-title {
  font-weight: 600;
}

.form-control {
  border-radius: 10px;
  border: 2px solid #e9ecef;
  padding: 0.75rem 1rem;
  transition: all 0.3s ease;
}

.form-control:focus {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 0.2rem rgba(26, 95, 122, 0.25);
}

.form-check-input:checked {
  background-color: var(--primary-color);
  border-color: var(--primary-color);
}

/* Responsive */
@media (max-width: 768px) {
  .hero h1 {
    font-size: 2.5rem;
  }
  .hero p {
    font-size: 1.1rem;
  }
  .section {
    padding: 60px 15px;
  }
  .section h2 {
    font-size: 2rem;
  }
}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark px-4">
  <span class="navbar-brand">Correctional Lessons Portal</span>
  <div class="ms-auto">
    <button class="btn btn-login" data-bs-toggle="modal" data-bs-target="#loginModal">Login</button>
  </div>
</nav>

<section class="hero">
  <div class="container">
    <h1>Welcome to the Correctional Lessons Portal</h1>
    <p>Correctional lessons are after-school sessions that provide students with focused academic support and opportunities to strengthen their understanding of key topics.</p>
    <p><strong>Sessions run from 3:45 PM to 7:00 PM</strong> — helping students grow with personalized attention.</p>
  </div>
</section>

<section class="section" style="background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);">
  <div class="container">
    <h2>Why Are Correctional Lessons Important?</h2>
    <div class="row justify-content-center">
      <div class="col-md-4 mb-4">
        <div class="feature-card">
          <h5>🎯</h5>
          <h4 class="mb-3">Individual Focus</h4>
          <p>Students receive personalized support tailored to their learning needs.</p>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="feature-card">
          <h5>📘</h5>
          <h4 class="mb-3">Academic Reinforcement</h4>
          <p>Lessons strengthen knowledge and close performance gaps.</p>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="feature-card">
          <h5>💪</h5>
          <h4 class="mb-3">Confidence and Growth</h4>
          <p>Regular small-group learning boosts confidence and motivation.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>Nearest Correctional Lessons</h2>
    <?php if(!empty($upcomingLessons)): ?>
      <?php foreach($upcomingLessons as $lesson): ?>
        <div class="lesson-card">
          <strong><?= htmlspecialchars($lesson['summary']) ?></strong><br>
          <?= $lesson['date']->format('D, d M Y H:i') ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-center">No upcoming lessons scheduled.</p>
    <?php endif; ?>
    <div class="text-center mt-3">
      <a href="<?= htmlspecialchars($ics_url) ?>" target="_blank" class="btn btn-primary">View Full Calendar</a>
    </div>
  </div>
</section>

<!-- LOGIN MODAL -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content p-3">
      <div class="modal-header">
        <h5 class="modal-title">Login to Your Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if(!empty($error)): ?>
          <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
          <div class="mb-3">
            <label>Email</label>
            <input type="email" name="email" class="form-control" required />
          </div>
          <div class="mb-3">
            <label>Password</label>
            <input type="password" name="password" class="form-control" required />
          </div>
          <div class="form-check mb-3">
            <input type="checkbox" name="remember" class="form-check-input" id="remember" />
            <label for="remember" class="form-check-label">Remember me</label>
          </div>
          <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
      </div>
    </div>
  </div>
</div>

<footer>
  <p>© <?= date("Y") ?> Correctional Lessons Portal — Empowering Students Through Guidance</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($error)): ?>
<script>
document.addEventListener("DOMContentLoaded", () => {
  new bootstrap.Modal(document.getElementById('loginModal')).show();
});
</script>
<?php endif; ?>
</body>
</html>
