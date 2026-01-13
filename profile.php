
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
include('db_connect.php');

// --- AUTH GUARD ---
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
    header('Location: index.php');
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$student_id = (int)$_SESSION['user_id'];

// --- HELPERS ---
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ensureDir(string $dir): void { if (!is_dir($dir)) { @mkdir($dir, 0755, true); } }
function isPathInDir(?string $path, string $dir): bool {
    if (!$path) return false;
    $real = @realpath($path);
    $realDir = @realpath($dir);
    return $real && $realDir && strncmp($real, $realDir, strlen($realDir)) === 0 && is_file($real);
}
function safeUploadsPath(string $basename): string {
    $basename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $basename);
    return 'uploads/' . time() . '_' . $basename;
}

// --- FETCH STUDENT DATA ---
$stmt = $conn->prepare('SELECT name, grade, email, profile_pic FROM users WHERE id=? LIMIT 1');
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($student_name, $student_grade, $student_email, $student_avatar);
$stmt->fetch();
$stmt->close();

$defaultAvatar = 'uploads/basic.jpg';
$student_avatar = isPathInDir($student_avatar, __DIR__ . '/uploads') ? $student_avatar : $defaultAvatar;

// --- TIMETABLE MAPPING ---
$gradeMap = [
    '6A'=>'page_01.png','7'=>'page_02.png','8A'=>'page_03.png','9'=>'page_04.png',
    '10'=>'page_05.png','8B'=>'page_21.png','6B'=>'page_23.png'
];
$dpMap = [
    'afruza'=>'page_06.png','matvey'=>'page_07.png','alisher'=>'page_08.png','nigora'=>'page_09.png',
    'nozima'=>'page_10.png','ibrohim'=>'page_11.png','laylo'=>'page_12.png',
    'maftuna'=>'page_13.png','jasmin'=>'page_14.png','bassal'=>'page_15.png','javohir'=>'page_16.png',
    'mokhinur'=>'page_17.png','khonzoda'=>'page_18.png','said'=>'page_19.png','odilzhon'=>'page_20.png','sayidbek'=>'page_22.png'
];
$ttBaseUrl = 'timetable/';
$ttBaseDir = __DIR__ . '/timetable/';
$studentFirst = strtolower(explode(' ', (string)$student_name)[0] ?? '');
$timetableFile = ((int)$student_grade >= 11) ? ($dpMap[$studentFirst] ?? null) : ($gradeMap[$student_grade] ?? null);
$timetableFs = $timetableFile ? ($ttBaseDir . $timetableFile) : null;
$timetableUrl = $timetableFile ? ($ttBaseUrl . $timetableFile) : null;
$timetableExists = $timetableFs && isPathInDir($timetableFs, $ttBaseDir);

$success = '';
$error = '';
if (isset($_GET['updated'])) {
    $success = '✅ Avatar updated successfully.';
}

// --- HANDLE AVATAR UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$csrf)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $file = $_FILES['avatar'] ?? null;
        if (!$file || !is_uploaded_file($file['tmp_name'])) {
            $error = 'No file uploaded.';
        } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $error = 'Upload failed. Please try again.';
        } elseif ((int)$file['size'] > 5 * 1024 * 1024) {
            $error = 'File too large. Max 5MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($file['tmp_name']);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            ];
            if (!isset($allowed[$mime])) {
                $error = 'Unsupported file type.';
            } else {
                ensureDir('uploads');
                $ext = $allowed[$mime];
                $base = pathinfo((string)$file['name'], PATHINFO_FILENAME);
                $safeBase = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $base) ?: 'avatar';
                $target = 'uploads/' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $target)) {
                    $stmt = $conn->prepare('UPDATE users SET profile_pic=? WHERE id=?');
                    $stmt->bind_param('si', $target, $student_id);
                    $stmt->execute();
                    $stmt->close();
                    header('Location: profile.php?updated=1');
                    exit();
                } else {
                    $error = 'Failed to save the file.';
                }
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Profile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
:root { --primary-color:#1a5f7a; --primary-dark:#144a5e; --primary-light:#2a7a94; --secondary-color:#f39c12; --success-color:#27ae60; --danger-color:#e74c3c; --light-bg:#f8fafc; --white:#fff; --text-primary:#2d3748; --text-secondary:#718096; --border-color:#e2e8f0; --shadow-sm:0 1px 3px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.24); --shadow-md:0 4px 6px rgba(0,0,0,.1),0 2px 4px rgba(0,0,0,.06); --shadow-lg:0 10px 25px rgba(0,0,0,.1),0 6px 10px rgba(0,0,0,.08); --shadow-xl:0 20px 40px rgba(0,0,0,.15);} 
*{margin:0;padding:0;box-sizing:border-box}
body{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);font-family:'Inter',sans-serif;min-height:100vh;position:relative}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(circle at 20% 80%,rgba(26,95,122,.1) 0%,transparent 50%),radial-gradient(circle at 80% 20%,rgba(243,156,18,.1) 0%,transparent 50%);pointer-events:none;z-index:-1}
.navbar{background:linear-gradient(135deg,var(--primary-color) 0%,var(--primary-dark) 100%);box-shadow:0 8px 32px rgba(26,95,122,.3);backdrop-filter:blur(10px);border-bottom:2px solid rgba(255,255,255,.1);padding:1rem 0}
.navbar-brand{color:#fff!important;font-weight:700;font-size:1.5rem;display:flex;align-items:center}
.profile-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.2);transition:.3s}
.profile-avatar:hover{transform:scale(1.1);box-shadow:0 4px 12px rgba(0,0,0,.3)}
.dropdown-menu{border-radius:12px;box-shadow:var(--shadow-lg);border:none;backdrop-filter:blur(10px);background:rgba(255,255,255,.98)}
.dropdown-item{border-radius:8px;margin:.25rem .5rem;transition:.3s;padding:.5rem 1rem}
.dropdown-item:hover{background:linear-gradient(135deg,rgba(26,95,122,.1) 0%,rgba(26,95,122,.05) 100%);transform:translateX(5px)}
.container{max-width:1200px;margin:0 auto;padding:2rem}
.card{border:none;box-shadow:var(--shadow-xl);border-radius:20px;background:rgba(255,255,255,.98);backdrop-filter:blur(20px);transition:.3s;overflow:hidden;position:relative}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--primary-color),var(--secondary-color),var(--primary-color));background-size:200% 100%;animation:shimmer 3s linear infinite}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
.card:hover{transform:translateY(-5px);box-shadow:0 25px 50px rgba(0,0,0,.2)}
.avatar-lg{width:140px;height:140px;border-radius:50%;border:4px solid var(--primary-light);object-fit:cover;margin-bottom:10px;box-shadow:var(--shadow-md)}
h3{color:var(--text-primary);font-weight:700;margin-bottom:1rem;}
.form-control,.form-select{border-radius:10px;border:2px solid var(--border-color);padding:.75rem 1rem;transition:.3s;background:#fff}
.form-control:focus,.form-select:focus{border-color:var(--primary-color);box-shadow:0 0 0 .2rem rgba(26,95,122,.25);transform:translateY(-1px)}
.btn{border-radius:10px;font-weight:600;padding:.75rem 1.5rem;transition:.3s;border:none;position:relative;overflow:hidden}
.btn::before{content:'';position:absolute;top:50%;left:50%;width:0;height:0;border-radius:50%;background:rgba(255,255,255,.3);transform:translate(-50%,-50%);transition:width .6s,height .6s}
.btn:hover::before{width:300px;height:300px}
.btn-primary{background:linear-gradient(135deg,var(--primary-color) 0%,var(--primary-light) 100%);box-shadow:0 4px 12px rgba(26,95,122,.3)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(26,95,122,.4)}
.btn-light{background:rgba(255,255,255,.9);border:2px solid rgba(255,255,255,.3)}
.btn-light:hover{background:#fff;transform:translateY(-2px)}
.alert{border-radius:12px;border:none;padding:1rem 1.5rem;margin-bottom:1.5rem;box-shadow:var(--shadow-md);animation:slideDown .3s ease-out}
@keyframes slideDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
.alert-success{background:linear-gradient(135deg,#d4edda 0%,#c3e6cb 100%);color:#155724}
.alert-danger{background:linear-gradient(135deg,#f8d7da 0%,#f5c6cb 100%);color:#721c24}
.fade-out{opacity:0;transition:opacity 1s ease-out}
.modal-body img{max-width:100%;height:auto;border-radius:12px;transition:transform .3s}
.zoom-controls{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);display:flex;gap:10px;z-index:10}
.zoom-btn{background:var(--primary-color);color:#fff;border:none;border-radius:50%;width:45px;height:45px;font-size:22px}
.zoom-btn:hover{background:var(--primary-dark)}
footer{color:#e6e6e6}
@media (max-width:768px){.container{padding:1rem}.navbar-brand{font-size:1.2rem}}
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 sticky-top">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    <span class="navbar-brand"><i class="fas fa-user"></i>&nbsp; My Profile</span>
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
  <?php if ($success): ?>
    <div id="alertBox" class="alert alert-success text-center fw-bold">
      <i class="fas fa-check-circle me-2"></i><?= h($success) ?>
    </div>
  <?php elseif ($error): ?>
    <div id="alertBox" class="alert alert-danger text-center fw-bold">
      <i class="fas fa-exclamation-circle me-2"></i><?= h($error) ?>
    </div>
  <?php endif; ?>

  <div class="card p-4 p-md-5 text-center">
    <img src="<?= h($student_avatar) ?>" onerror="this.src='uploads/basic.jpg';" class="avatar-lg" alt="Profile Picture">
    <h3 class="fw-bold mb-1"><?= h($student_name) ?></h3>
    <p class="text-muted mb-2">Grade <?= h((string)$student_grade) ?></p>
    <p><strong>Email:</strong> <?= h($student_email) ?></p>

    <form method="POST" enctype="multipart/form-data" class="mt-3 d-inline-block">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input class="form-control" style="max-width:320px;display:inline-block" type="file" name="avatar" accept="image/*" required>
      <button class="btn btn-primary ms-2"><i class="fas fa-image me-2"></i>Change Avatar</button>
    </form>

    <hr class="my-4">

    <?php if ($timetableExists && $timetableUrl): ?>
      <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#timetableModal">
        <i class="fas fa-calendar-day me-2"></i>View Timetable
      </button>
    <?php else: ?>
      <p class="text-muted mt-2">No timetable available.</p>
    <?php endif; ?>
  </div>
</div>

<!-- TIMETABLE MODAL -->
<div class="modal fade" id="timetableModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content bg-dark text-center position-relative">
      <div class="modal-header border-0">
        <h5 class="modal-title text-white"><i class="fas fa-calendar-day me-2"></i>Your Timetable</h5>
        <div>
          <?php if ($timetableUrl): ?>
            <a href="<?= h($timetableUrl) ?>" download class="btn btn-light me-2"><i class="fas fa-download me-1"></i>Download</a>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
        </div>
      </div>
      <div class="modal-body d-flex justify-content-center align-items-center">
        <?php if ($timetableUrl): ?>
          <img id="zoomImage" src="<?= h($timetableUrl) ?>?t=<?= time() ?>" alt="Timetable Image">
        <?php else: ?>
          <p class="text-white">Timetable not found.</p>
        <?php endif; ?>
      </div>
      <div class="zoom-controls">
        <button class="zoom-btn" id="zoomIn">＋</button>
        <button class="zoom-btn" id="zoomOut">－</button>
      </div>
    </div>
  </div>
</div>

<footer class="text-center mt-4 mb-3">
  © <?= date('Y') ?> Correctional Lessons Portal — Empowering Students Through Guidance
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Alert auto-hide
const alertBox = document.getElementById('alertBox');
if (alertBox) {
  setTimeout(() => { alertBox.classList.add('fade-out'); setTimeout(() => alertBox.remove(), 1000); }, 5000);
}
// Support redirect
document.getElementById('openSupport')?.addEventListener('click', () => { window.location.href = 'support.php'; });
// Zoom controls
let zoom = 1; 
const img = document.getElementById('zoomImage');
if (img) {
  document.getElementById('zoomIn').onclick = () => { zoom = Math.min(zoom + 0.2, 5); img.style.transform = `scale(${zoom})`; };
  document.getElementById('zoomOut').onclick = () => { zoom = Math.max(zoom - 0.2, 0.4); img.style.transform = `scale(${zoom})`; };
}
</script>
</body>
</html>