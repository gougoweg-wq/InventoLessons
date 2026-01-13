<?php
session_start();
include('db_connect.php');

// Check admin access
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
    header("Location: index.php");
    exit();
}

$sql = "SELECT l.*, t.name AS teacher_name 
        FROM teacher_email_logs l 
        LEFT JOIN teachers t ON l.teacher_id = t.id 
        WHERE 1";

// 🔍 Apply filters
if (!empty($_GET['teacher'])) $sql .= " AND t.name LIKE '%" . $conn->real_escape_string($_GET['teacher']) . "%'";
if (!empty($_GET['student'])) $sql .= " AND l.student_name LIKE '%" . $conn->real_escape_string($_GET['student']) . "%'";
if (!empty($_GET['email'])) $sql .= " AND l.parent_email LIKE '%" . $conn->real_escape_string($_GET['email']) . "%'";
if (!empty($_GET['grade'])) $sql .= " AND l.student_grade = '" . $conn->real_escape_string($_GET['grade']) . "'";
if (!empty($_GET['language'])) $sql .= " AND l.language = '" . $conn->real_escape_string($_GET['language']) . "'";
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $sql .= " AND DATE(l.sent_at) BETWEEN '" . $_GET['start_date'] . "' AND '" . $_GET['end_date'] . "'";
}

$sql .= " ORDER BY l.sent_at DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Email Logs - Admin Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="admin-styles.css">
</head>
<body>

<nav class="navbar navbar-dark px-4">
  <a class="navbar-brand" href="admin_dashboard.php">
    <i class="fas fa-arrow-left me-2"></i>Admin Dashboard
  </a>
  <div><span class="text-white-50 small"><?= htmlspecialchars($_SESSION['admin_email']) ?></span></div>
</nav>

<div class="container">
  <div class="card">
    <div class="card-body">
      <h3 class="mb-4">
        <i class="fas fa-envelope me-2"></i>Email Logs
      </h3>

      <!-- 🔎 Search & Filter Form -->
      <form method="GET" class="row g-2 mb-4">
        <div class="col-md-2">
          <input type="text" name="teacher" class="form-control" placeholder="Teacher" value="<?= htmlspecialchars($_GET['teacher'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <input type="text" name="student" class="form-control" placeholder="Student" value="<?= htmlspecialchars($_GET['student'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <input type="text" name="email" class="form-control" placeholder="Parent Email" value="<?= htmlspecialchars($_GET['email'] ?? '') ?>">
        </div>
        <div class="col-md-1">
          <input type="text" name="grade" class="form-control" placeholder="Grade" value="<?= htmlspecialchars($_GET['grade'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <select name="language" class="form-select">
            <option value="">Language</option>
            <option value="en" <?= ($_GET['language'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
            <option value="ru" <?= ($_GET['language'] ?? '') === 'ru' ? 'selected' : '' ?>>Russian</option>
            <option value="uz" <?= ($_GET['language'] ?? '') === 'uz' ? 'selected' : '' ?>>Uzbek</option>
          </select>
        </div>
        <div class="col-md-2">
          <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
        </div>
        <div class="col-md-1">
          <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
        </div>
        <div class="col-md-12 d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search me-2"></i>Filter
          </button>
          <a href="admin_email_log.php" class="btn btn-secondary">
            <i class="fas fa-times me-2"></i>Clear
          </a>
        </div>
      </form>

      <!-- 📊 Results Table -->
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Teacher</th>
              <th>Student</th>
              <th>Grade</th>
              <th>Parent Email</th>
              <th>Language</th>
              <th>Attachment</th>
              <th>Message</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($result->num_rows > 0): ?>
              <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                  <td><?= date('M j, Y H:i', strtotime($row['sent_at'])) ?></td>
                  <td><?= htmlspecialchars($row['teacher_name'] ?? 'N/A') ?></td>
                  <td><?= htmlspecialchars($row['student_name']) ?></td>
                  <td><span class="badge badge-info"><?= htmlspecialchars($row['student_grade']) ?></span></td>
                  <td><?= htmlspecialchars($row['parent_email']) ?></td>
                  <td><span class="badge badge-success"><?= strtoupper($row['language'] ?? 'EN') ?></span></td>
                  <td>
                    <?php if ($row['attachment_path']): ?>
                      <a href="<?= htmlspecialchars($row['attachment_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-paperclip"></i> File
                      </a>
                    <?php else: ?>
                      <span class="text-muted">None</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary viewMessageBtn"
                            data-message="<?= htmlspecialchars($row['message'], ENT_QUOTES) ?>"
                            data-student="<?= htmlspecialchars($row['student_name']) ?>"
                            data-teacher="<?= htmlspecialchars($row['teacher_name'] ?? 'N/A') ?>">
                      <i class="fas fa-eye"></i> View
                    </button>
                  </td>
                  <td>
                    <a href="email_delete.php?id=<?= $row['id'] ?>" 
                       class="btn btn-sm btn-danger" 
                       onclick="return confirm('Delete this email log?')">
                      <i class="fas fa-trash"></i>
                    </a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center py-4">
                  <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                  <p class="text-muted">No email logs found</p>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Use the single modal implementation below — removed duplicate dynamic-modal code -->
<script>
document.querySelectorAll('.viewMessageBtn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.getElementById('modalTeacher').innerText = btn.dataset.teacher;
    document.getElementById('modalStudent').innerText = btn.dataset.student;
    // we intentionally allow basic HTML in messages that were saved from teachers;
    // if you prefer text-only rendering use textContent instead:
    document.getElementById('modalMessageContent').innerHTML = btn.dataset.message;
    new bootstrap.Modal(document.getElementById('messageModal')).show();
  });
});
</script>
</body>
</html>

<!-- 📩 Message Preview Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">📨 Sent Message</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>Teacher:</strong> <span id="modalTeacher"></span></p>
        <p><strong>Student:</strong> <span id="modalStudent"></span></p>
        <hr>
        <div id="modalMessageContent" style="white-space:pre-wrap;"></div>
      </div>
    </div>
  </div>
</div>
