<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

// --- AUTH GUARD ---
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';

// --- FETCH USER AVATAR & NAME ---
if ($role === 'student') {
    $stmt = $conn->prepare("SELECT name, profile_pic FROM users WHERE id=? LIMIT 1");
} else {
    $stmt = $conn->prepare("SELECT name, avatar AS profile_pic FROM teachers WHERE id=? LIMIT 1");
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rs = $stmt->get_result();
$user = $rs->fetch_assoc() ?: [];
$stmt->close();

$user_name = $user['name'] ?? 'User';
$profile_pic = $user['profile_pic'] ?? 'uploads/basic.jpg';
if (empty($profile_pic) || !file_exists($profile_pic)) {
    $profile_pic = 'uploads/basic.jpg';
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process support form server-side: save to DB and optionally send email
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash = ['type'=>'danger','msg'=>'Please provide a valid email address.'];
    } elseif ($message === '') {
        $flash = ['type'=>'danger','msg'=>'Please describe your issue.'];
    } else {
        // Save to support_messages table (create if missing)
        $stmt = $conn->prepare("INSERT INTO support_messages (user_id, role, email, message, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isss", $user_id, $role, $email, $message);
        if ($stmt->execute()) {
            $stmt->close();
            // Optional: send email notification to admin
            $adminEmail = 'gougoweg@gmail.com';
            $subject = "Support request from {$user_name}";
            $body = "From: {$user_name} ({$email})\n\nMessage:\n{$message}";
            @mail($adminEmail, $subject, $body, "From: {$email}\r\n"); // best-effort

            $flash = ['type'=>'success','msg'=>'Your message was received. Support will contact you soon.'];
        } else {
            $flash = ['type'=>'danger','msg'=>'Failed to submit. Try again later.'];
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Support</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{font-family:'Montserrat',sans-serif;background:#e6f4fa;}
.navbar{background:#8cbce6;border-radius:12px;margin-bottom:20px;}
.card{background:#d0e8f9;border-radius:15px;box-shadow:0 8px 25px rgba(0,0,0,0.1);}
.profile-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:none;background:none;}
textarea{resize:none;}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-4 d-flex justify-content-between align-items-center">
  <span class="navbar-brand">💬 Support</span>
  <div class="dropdown">
    <button class="btn btn-light border-0 dropdown-toggle" data-bs-toggle="dropdown">
      <img src="<?= htmlspecialchars($profile_pic) ?>" onerror="this.src='uploads/basic.jpg';" class="profile-avatar" alt="Profile Picture">
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
</nav>

<div class="container mt-4">
  <div class="card p-4">
    <h4>📨 Contact Support Team</h4>
    <p>Hello <b><?= htmlspecialchars($user_name) ?></b>!  
    If you’re having issues with bookings, timetables, or your account, please describe them below and we’ll respond soon.</p>

    <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="mb-3">
        <label>Your Email</label>
        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" placeholder="example@school.com" required>
      </div>
      <div class="mb-3">
        <label>Your Message</label>
        <textarea class="form-control" name="message" rows="5" placeholder="Describe your issue here..." required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary w-100">Send Message</button>
    </form>
  </div>
</div>

<footer class="text-center mt-4 text-muted mb-3">
  © <?= date('Y') ?> Correctional Lessons Portal — Always here to help you
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('openSupport').onclick=()=>window.location.href='support.php';
</script>
</body>
</html>
