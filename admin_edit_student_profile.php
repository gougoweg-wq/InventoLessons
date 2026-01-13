<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'db_connect.php';

/* Admin guard */
if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_email'] ?? '') !== 'admin@invento.uz') {
    header("Location: index.php");
    exit();
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* Get student id */
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($student_id <= 0) {
    http_response_code(400);
    echo "Invalid student id.";
    exit();
}

/* Fetch helpers */
function fetch_student(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, name, email, grade, created_at, profile_pic FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $rs = $stmt->get_result();
    $row = $rs->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function fetch_parent(mysqli $conn, int $student_id): ?array {
    $stmt = $conn->prepare("SELECT id, parent_email, student_id FROM parents WHERE student_id = ? LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $rs = $stmt->get_result();
    $row = $rs->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$student = fetch_student($conn, $student_id);
if (!$student) {
    http_response_code(404);
    echo "Student not found.";
    exit();
}
$parent = fetch_parent($conn, $student_id);

/* Ensure upload dir */
$uploadDir = __DIR__ . "/uploads/students";
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

$flash = null;

/* Delete student */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $flash = ['type'=>'danger','msg'=>'Invalid CSRF token. Reload the page and try again.'];
    } else {
        // use transaction to ensure integrity
        $conn->begin_transaction();
        try {
            $sid = $student_id;

            $stmt = $conn->prepare("DELETE FROM bookings WHERE user_id = ?");
            $stmt->bind_param("i", $sid);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM teacher_email_logs WHERE student_id = ?");
            $stmt->bind_param("i", $sid);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM parents WHERE student_id = ?");
            $stmt->bind_param("i", $sid);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $sid);
            $okUser = $stmt->execute();
            $stmt->close();

            if ($okUser) {
                $lg = $conn->prepare("INSERT INTO logs (admin_email, user_id, role, action) VALUES (?, ?, 'admin', 'Deleted student profile')");
                $lg->bind_param("si", $_SESSION['admin_email'], $sid);
                $lg->execute();
                $lg->close();
                $conn->commit();
                header("Location: admin_dashboard.php?deleted=1");
                exit();
            }
            $conn->rollback();
            $flash = ['type'=>'danger','msg'=>'Error while deleting student.'];
        } catch (Exception $ex) {
            $conn->rollback();
            $flash = ['type'=>'danger','msg'=>'Error while deleting student.'];
        }
    }
}

/* Save/update student */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $flash = ['type'=>'danger','msg'=>'Invalid CSRF token. Reload the page and try again.'];
    } else {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $grade = trim($_POST['grade'] ?? '');
        $parent_email = trim($_POST['parent_email'] ?? '');
        $new_password = trim($_POST['new_password'] ?? '');
        $profile_photo_path = $student['profile_pic'] ?? null;

        if ($name === '' || $email === '' || $grade === '') {
            $flash = ['type'=>'danger','msg'=>'Name, Email, and Grade are required.'];
        } else {
            // Start transaction
            $conn->begin_transaction();
            try {
                // Handle profile photo upload (safe checks)
                if (!empty($_FILES['profile_pic']['name'])) {
                    $f = $_FILES['profile_pic'];
                    if ($f['error'] === UPLOAD_ERR_OK) {
                        // limit size to 2MB
                        if ($f['size'] > 2 * 1024 * 1024) {
                            throw new RuntimeException('Image too large (max 2MB).');
                        }
                        // mime-type check
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($f['tmp_name']);
                        $ext = '';
                        $allowed = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/webp' => 'webp',
                            'image/gif'  => 'gif'
                        ];
                        if (!isset($allowed[$mime])) {
                            throw new RuntimeException('Invalid image type. Allowed: jpg, png, webp, gif.');
                        }
                        $ext = $allowed[$mime];
                        $newName = 'student_'.$student_id.'_'.time().'.'.$ext;
                        $dest = $uploadDir . '/' . $newName;
                        if (!move_uploaded_file($f['tmp_name'], $dest)) {
                            throw new RuntimeException('Photo upload failed; keeping previous photo.');
                        }
                        // remove previous photo if inside uploads/students
                        if (!empty($profile_photo_path)) {
                            $prev = realpath(__DIR__ . '/' . $profile_photo_path);
                            $uploadsDirReal = realpath($uploadDir);
                            if ($prev && $uploadsDirReal && strpos($prev, $uploadsDirReal) === 0 && is_file($prev)) {
                                @unlink($prev);
                            }
                        }
                        $profile_photo_path = 'uploads/students/'.$newName;
                    } else {
                        // upload error
                        // keep previous photo but set a warning
                        $flash = ['type'=>'warning','msg'=>'Photo upload error; keeping previous photo.'];
                    }
                }

                // Update users
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, grade = ?, profile_pic = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $email, $grade, $profile_photo_path, $student_id);
                $okUser = $stmt->execute();
                $stmt->close();

                // Optional password change
                if ($okUser && $new_password !== '') {
                    $hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hash, $student_id);
                    $stmt->execute();
                    $stmt->close();
                }

                // Upsert parent email
                if ($okUser) {
                    if ($parent_email !== '') {
                        if ($parent) {
                            $stmt = $conn->prepare("UPDATE parents SET parent_email = ? WHERE id = ?");
                            $stmt->bind_param("si", $parent_email, $parent['id']);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            $stmt = $conn->prepare("INSERT INTO parents (parent_email, student_id) VALUES (?, ?)");
                            $stmt->bind_param("si", $parent_email, $student_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    } else {
                        if ($parent) {
                            $stmt = $conn->prepare("DELETE FROM parents WHERE id = ? LIMIT 1");
                            $stmt->bind_param("i", $parent['id']);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    // Log update
                    $lg = $conn->prepare("INSERT INTO logs (admin_email, user_id, role, action) VALUES (?, ?, 'admin', 'Updated student profile')");
                    $lg->bind_param("si", $_SESSION['admin_email'], $student_id);
                    $lg->execute();
                    $lg->close();

                    $conn->commit();
                    header("Location: admin_edit_student_profile.php?id=".$student_id."&saved=1");
                    exit();
                } else {
                    $conn->rollback();
                    $flash = ['type'=>'danger','msg'=>'Database error while updating student.'];
                }
            } catch (Exception $ex) {
                $conn->rollback();
                if (!$flash) $flash = ['type'=>'danger','msg'=>$ex->getMessage()];
            }
        }
    }
}

/* After operations refresh */
$student = fetch_student($conn, $student_id);
$parent  = fetch_parent($conn, $student_id);

/* grades */
$grades = range(6, 12);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Student • <?= htmlspecialchars($student['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="admin-styles.css">
<style>
.avatar {
  width: 96px; height: 96px; border-radius: 50%;
  object-fit: cover; border: 2px solid #e9ecef;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.label-sm {
  font-size: .875rem;
  color: var(--text-secondary);
  font-weight: 500;
}
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4">
  <a class="navbar-brand" href="admin_dashboard.php">← Admin Dashboard</a>
  <div><span class="text-white-50 small"><?= htmlspecialchars($_SESSION['admin_email']) ?></span></div>
</nav>

<div class="container my-4">

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="d-flex align-items-center gap-3 mb-3">
    <img class="avatar" src="<?= $student['profile_pic'] ? htmlspecialchars($student['profile_pic']) : 'https://via.placeholder.com/96x96.png?text=Photo' ?>" alt="Profile photo">
    <div>
      <h3 class="mb-0"><?= htmlspecialchars($student['name']) ?></h3>
      <div class="text-muted">ID #<?= (int)$student['id'] ?> • Grade <?= htmlspecialchars($student['grade']) ?></div>
    </div>
  </div>

  <div class="row g-4">
    <!-- Left: Profile form -->
    <div class="col-lg-8">
      <form class="card p-3" method="POST" enctype="multipart/form-data" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="save">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="name">Student Name</label>
            <input type="text" id="name" name="name" class="form-control" required
                   value="<?= htmlspecialchars($student['name']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Student Email</label>
            <input type="email" id="email" name="email" class="form-control" required
                   value="<?= htmlspecialchars($student['email']) ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label" for="grade">Grade</label>
            <select id="grade" name="grade" class="form-select" required>
              <?php foreach ($grades as $g): ?>
                <option value="<?= $g ?>" <?= ($student['grade']==$g ? 'selected' : '') ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-8">
            <label class="form-label" for="parent_email">Parent Email (optional)</label>
            <input type="email" id="parent_email" name="parent_email" class="form-control"
                   value="<?= htmlspecialchars($parent['parent_email'] ?? '') ?>">
            <div class="form-text">Leave blank to remove the parent link.</div>
          </div>

          <div class="col-12">
            <label class="form-label" for="profile_pic">Profile Photo (jpg, png, webp, gif) — max 2MB</label>
            <input type="file" id="profile_pic" name="profile_pic" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
          </div>

          <div class="col-12">
            <span class="label-sm d-block mb-1">Password (optional)</span>
            <div class="input-group">
              <span class="input-group-text">New Password</span>
              <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Leave empty to keep current">
            </div>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Save Changes</button>
            <a class="btn btn-outline-secondary" href="admin_dashboard.php">Cancel</a>
          </div>
        </div>
      </form>
    </div>

    <!-- Right: Meta & Danger zone -->
    <div class="col-lg-4">
      <div class="card p-3 mb-3">
        <div class="mb-2 fw-semibold">Student Info</div>
        <div class="small text-muted">Created at</div>
        <div class="mb-3"><?= htmlspecialchars($student['created_at']) ?></div>

        <div class="small text-muted">User ID</div>
        <div class="mb-3"><?= (int)$student['id'] ?></div>

        <div class="small text-muted">Current Parent</div>
        <div class="mb-1"><?= $parent ? htmlspecialchars($parent['parent_email']) : '<em>None</em>' ?></div>
      </div>

      <form class="card p-3 border-danger" method="POST"
            onsubmit="return confirm('Are you sure you want to permanently delete this student and related records?');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="delete">
        <div class="mb-2 fw-semibold text-danger">Danger Zone</div>
        <p class="small mb-3">This will remove the student, their bookings, email logs, and parent link.</p>
        <button type="submit" class="btn btn-outline-danger w-100">Delete Student</button>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
