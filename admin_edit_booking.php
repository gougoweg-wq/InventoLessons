<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/* ---------------------------------
   🔐 Access Gate
--------------------------------- */
if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_email'] ?? '') !== 'admin@invento.uz') {
  http_response_code(403);
  exit("<div class='alert alert-danger m-3'>Access denied.</div>");
}

/* ---------------------------------
   🔧 Helpers
--------------------------------- */
function table_has_column(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $result = $conn->query($sql);

    return ($result && $result->num_rows > 0);
}


function phpmailer_send_wrapper($to, $subject, $html, $alt='') {
  // Try to use project’s existing mail helper if available
  if (!function_exists('sendMail') && !function_exists('send_mail') && !function_exists('mailer_send')) {
    @include_once __DIR__.'/mailer.php';
    @include_once __DIR__.'/mail/mailer.php';
    @include_once __DIR__.'/includes/mailer.php';
    @include_once __DIR__.'/sendMail.php';
    @include_once __DIR__.'/mail/sendMail.php';
  }
  if (function_exists('sendMail'))    return sendMail($to, $subject, $html, $alt);
  if (function_exists('send_mail'))   return send_mail($to, $subject, $html, $alt);
  if (function_exists('mailer_send')) return mailer_send($to, $subject, $html, $alt);

  // Fallback (simple PHP mail)
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type: text/html; charset=UTF-8\r\n";
  $headers .= "From: Invento <no-reply@invento.uz>\r\n";
  return @mail($to, $subject, $html, $headers);
}

function log_booking_change(mysqli $conn, int $bookingId, string $adminEmail, string $action, $oldVal=null, $newVal=null) {
  $conn->query("CREATE TABLE IF NOT EXISTS booking_history (
      id INT AUTO_INCREMENT PRIMARY KEY,
      booking_id INT NOT NULL,
      admin_email VARCHAR(255),
      action VARCHAR(255),
      old_value TEXT,
      new_value TEXT,
      timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $stmt = $conn->prepare("INSERT INTO booking_history (booking_id, admin_email, action, old_value, new_value) VALUES (?,?,?,?,?)");
  $old = is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
  $new = is_scalar($newVal) ? (string)$newVal : json_encode($newVal, JSON_UNESCAPED_UNICODE);
  $stmt->bind_param("issss", $bookingId, $adminEmail, $action, $old, $new);
  $stmt->execute();
  $stmt->close();
}

function add_log(mysqli $conn, $adminEmail, $userId, $action) {
  $conn->query("CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_email VARCHAR(255),
    user_id INT,
    role VARCHAR(20),
    action TEXT,
    log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )");
  $stmt = $conn->prepare("INSERT INTO logs (admin_email, role, user_id, action) VALUES (?, 'admin', ?, ?)");
  $stmt->bind_param("sis", $adminEmail, $userId, $action);
  $stmt->execute();
  $stmt->close();
}

/* ---------------------------------
   📥 Input
--------------------------------- */
$adminEmail = $_SESSION['admin_email'];
$bookingId  = intval($_GET['id'] ?? 0);
if ($bookingId <= 0) {
  exit("<div class='alert alert-danger m-3'>Invalid booking ID.</div>");
}

/* ---------------------------------
   🧭 Schema detection
--------------------------------- */
$hasTeacherId = table_has_column($conn, 'bookings', 'teacher_id');
$hasStatus    = table_has_column($conn, 'bookings', 'status'); // you confirmed this exists

// Allowed statuses per your enum
$ALLOWED_STATUS = ['booked','visited','canceled','not attended'];

/* ---------------------------------
   ⚠️ Warnings table (ensure)
--------------------------------- */
$conn->query("CREATE TABLE IF NOT EXISTS student_warnings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  reason TEXT,
  issued_by VARCHAR(255),
  issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ---------------------------------
   🧾 Fetch booking
--------------------------------- */
$sql = "
  SELECT b.id, b.subject, b.booking_date, u.id AS user_id, u.name, u.grade, u.email AS student_email
  ".($hasTeacherId ? ", b.teacher_id" : "")."
  ".($hasStatus    ? ", b.status"     : "")."
  FROM bookings b
  JOIN users u ON b.user_id = u.id
  WHERE b.id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $bookingId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) exit("<div class='alert alert-warning m-3'>Booking not found.</div>");

/* ---------------------------------
   👪 Parent emails
--------------------------------- */
$parentEmails = [];
$pq = $conn->prepare("SELECT parent_email FROM parents WHERE student_id=? AND parent_email IS NOT NULL AND parent_email<>''");
$pq->bind_param("i", $booking['user_id']);
$pq->execute();
$pr = $pq->get_result();
while ($row = $pr->fetch_assoc()) {
  if (filter_var($row['parent_email'], FILTER_VALIDATE_EMAIL)) $parentEmails[] = $row['parent_email'];
}
$pq->close();

/* ---------------------------------
   🧑‍🏫 Teachers list (if teacher_id exists)
--------------------------------- */
$teachers = [];
if ($hasTeacherId) {
  $tr = $conn->query("SELECT id, name, email, grades FROM teachers ORDER BY name ASC");
  while ($t = $tr->fetch_assoc()) $teachers[] = $t;
}

/* ---------------------------------
   📨 POST actions
--------------------------------- */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  /* Update booking */
  if ($action === 'update') {
    $newSubject = trim($_POST['subject'] ?? $booking['subject']);
    $newDate    = trim($_POST['booking_date'] ?? date('Y-m-d H:i:s', strtotime($booking['booking_date'])));
    $newTeacher = $hasTeacherId ? intval($_POST['teacher_id'] ?? ($booking['teacher_id'] ?? 0)) : null;
    $newStatus  = $hasStatus ? trim($_POST['status'] ?? $booking['status'] ?? 'booked') : null;
    if ($hasStatus && !in_array($newStatus, $ALLOWED_STATUS, true)) {
      $newStatus = $booking['status'] ?? 'booked';
    }

    $oldSnapshot = $booking;

    if ($hasTeacherId && $hasStatus) {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=?, teacher_id=?, status=? WHERE id=?");
      $upd->bind_param("ssisi", $newSubject, $newDate, $newTeacher, $newStatus, $bookingId);
    } elseif ($hasTeacherId) {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=?, teacher_id=? WHERE id=?");
      $upd->bind_param("ssii", $newSubject, $newDate, $newTeacher, $bookingId);
    } elseif ($hasStatus) {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=?, status=? WHERE id=?");
      $upd->bind_param("sssi", $newSubject, $newDate, $newStatus, $bookingId);
    } else {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=? WHERE id=?");
      $upd->bind_param("ssi", $newSubject, $newDate, $bookingId);
    }
    $ok = $upd->execute();
    $upd->close();

    if ($ok) {
      log_booking_change($conn, $bookingId, $adminEmail, 'update',
        $oldSnapshot,
        [
          'subject'      => $newSubject,
          'booking_date' => $newDate,
          'teacher_id'   => $newTeacher,
          'status'       => $newStatus
        ]
      );
      add_log($conn, $adminEmail, $booking['user_id'], "Edited booking #{$bookingId} ({$newSubject})");
      header("Location: ".$_SERVER['REQUEST_URI']);
      exit;
    } else {
      $flash = ['type'=>'danger','msg'=>'Failed to update booking.'];
    }
  }

  /* Cancel booking (soft): status='canceled' */
  if ($action === 'cancel') {
    $reason = trim($_POST['reason'] ?? '');
    $old = $booking;

    if ($hasStatus) {
      $upd = $conn->prepare("UPDATE bookings SET status='canceled' WHERE id=?");
      $upd->bind_param("i", $bookingId);
      $ok = $upd->execute();
      $upd->close();
    } else {
      // No status column: keep the hard-delete fallback
      $del = $conn->prepare("DELETE FROM bookings WHERE id=?");
      $del->bind_param("i", $bookingId);
      $ok = $del->execute();
      $del->close();
    }

    if ($ok) {
      log_booking_change($conn, $bookingId, $adminEmail, 'cancel', $old, ['reason'=>$reason]);
      add_log($conn, $adminEmail, $booking['user_id'], "Cancelled booking #{$bookingId}");

      // email student + parents
      $subject = "Booking Canceled: ".$booking['subject'];
      $html = "<div style='font-family:Segoe UI,Arial,sans-serif'>
        <p>Hello,</p>
        <p>The booking for <strong>".htmlspecialchars($booking['subject'])."</strong> scheduled on <strong>".htmlspecialchars($booking['booking_date'])."</strong> has been <strong>canceled</strong>.</p>"
        .($reason !== '' ? "<p><em>Reason:</em> ".nl2br(htmlspecialchars($reason))."</p>" : "")
        ."<hr><small>— Invento</small></div>";
      $alt = "Booking canceled: {$booking['subject']} on {$booking['booking_date']}".($reason ? "\nReason: $reason" : "");

      $sentOk = 0; $targets = [];
      if (filter_var($booking['student_email'], FILTER_VALIDATE_EMAIL)) $targets[] = $booking['student_email'];
      $targets = array_merge($targets, $parentEmails);
      $targets = array_values(array_unique($targets));
      foreach ($targets as $to) if (phpmailer_send_wrapper($to, $subject, $html, $alt)) $sentOk++;

      if ($hasStatus) {
        header("Location: ".$_SERVER['REQUEST_URI']);
        exit;
      } else {
        header("Location: admin_dashboard.php#bookings");
        exit;
      }
    } else {
      $flash = ['type'=>'danger','msg'=>'Failed to cancel booking.'];
    }
  }

  /* Send reminder email */
  if ($action === 'send_reminder') {
    $subjectLine = "Reminder: ".$booking['subject']." on ".date('M j, Y H:i', strtotime($booking['booking_date']));
    $html = "<div style='font-family:Segoe UI,Arial,sans-serif'>
      <p>Hello ".htmlspecialchars($booking['name']).",</p>
      <p>This is a reminder for your booking:</p>
      <ul>
        <li><strong>Subject:</strong> ".htmlspecialchars($booking['subject'])."</li>
        <li><strong>Date & Time:</strong> ".htmlspecialchars($booking['booking_date'])."</li>
      </ul>
      <hr><small>— Invento</small></div>";
    $alt = "Reminder for booking\nSubject: {$booking['subject']}\nWhen: {$booking['booking_date']}";

    $targets = [];
    if (filter_var($booking['student_email'], FILTER_VALIDATE_EMAIL)) $targets[] = $booking['student_email'];
    $targets = array_merge($targets, $parentEmails);
    $targets = array_values(array_unique($targets));

    $sentOk = 0;
    foreach ($targets as $to) if (phpmailer_send_wrapper($to, $subjectLine, $html, $alt)) $sentOk++;

    log_booking_change($conn, $bookingId, $adminEmail, 'send_reminder', null, ['sent_to'=>$targets]);
    add_log($conn, $adminEmail, $booking['user_id'], "Sent reminder for booking #{$bookingId}");

    $flash = ['type'=>'success','msg'=>"Reminder sent to {$sentOk} recipient(s)."];
  }

  /* Add warning */
  if ($action === 'add_warning') {
    $reason = trim($_POST['warning_reason'] ?? '');
    if ($reason !== '') {
      $stmt = $conn->prepare("INSERT INTO student_warnings (student_id, reason, issued_by) VALUES (?,?,?)");
      $stmt->bind_param("iss", $booking['user_id'], $reason, $adminEmail);
      $stmt->execute();
      $stmt->close();
      log_booking_change($conn, $bookingId, $adminEmail, 'add_warning', null, ['student_id'=>$booking['user_id'], 'reason'=>$reason]);
      header("Location: ".$_SERVER['REQUEST_URI']);
      exit;
    } else {
      $flash = ['type'=>'danger','msg'=>'Warning reason required.'];
    }
  }
}

/* ---------------------------------
   📜 Load warnings & history (with filters)
--------------------------------- */
$period   = $_GET['period'] ?? 'week'; // week|month|custom
$startStr = $_GET['start']  ?? '';
$endStr   = $_GET['end']    ?? '';

if ($period === 'week') {
  $start = (new DateTime('monday this week'))->format('Y-m-d 00:00:00');
  $end   = (new DateTime('sunday this week'))->format('Y-m-d 23:59:59');
} elseif ($period === 'month') {
  $start = (new DateTime('first day of this month'))->format('Y-m-d 00:00:00');
  $end   = (new DateTime('last day of this month'))->format('Y-m-d 23:59:59');
} else {
  $start = $startStr ? date('Y-m-d 00:00:00', strtotime($startStr)) : '1970-01-01 00:00:00';
  $end   = $endStr   ? date('Y-m-d 23:59:59', strtotime($endStr))   : '2999-12-31 23:59:59';
}

$warnings = [];
$wr = $conn->prepare("SELECT id, reason, issued_by, issued_at FROM student_warnings WHERE student_id=? ORDER BY issued_at DESC");
$wr->bind_param("i", $booking['user_id']);
$wr->execute();
$wres = $wr->get_result();
while ($w = $wres->fetch_assoc()) $warnings[] = $w;
$wr->close();

// ensure booking_history exists
$conn->query("CREATE TABLE IF NOT EXISTS booking_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  admin_email VARCHAR(255),
  action VARCHAR(255),
  old_value TEXT,
  new_value TEXT,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$history = [];
$hr = $conn->prepare("SELECT admin_email, action, old_value, new_value, timestamp FROM booking_history WHERE booking_id=? AND timestamp BETWEEN ? AND ? ORDER BY id DESC");
$hr->bind_param("iss", $bookingId, $start, $end);
$hr->execute();
$hres = $hr->get_result();
while ($h = $hres->fetch_assoc()) $history[] = $h;
$hr->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Booking #<?= htmlspecialchars($bookingId) ?> - Admin Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="admin-styles.css">
<style>
.booking-header {
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
  color: white;
  border-radius: 12px;
  padding: 2rem;
  margin-bottom: 2rem;
  box-shadow: var(--shadow-lg);
}

.booking-id {
  font-size: 2rem;
  font-weight: 700;
  margin-bottom: 0.5rem;
}

.booking-meta {
  opacity: 0.9;
  font-size: 1.1rem;
}

.status-badge {
  padding: 0.5rem 1rem;
  border-radius: 20px;
  font-weight: 600;
  font-size: 0.9rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.status-booked { background: rgba(52, 152, 219, 0.2); color: #3498db; }
.status-visited { background: rgba(39, 174, 96, 0.2); color: #27ae60; }
.status-canceled { background: rgba(231, 76, 60, 0.2); color: #e74c3c; }
.status-not-attended { background: rgba(243, 156, 18, 0.2); color: #f39c12; }

.warning-item {
  background: rgba(243, 156, 18, 0.1);
  border-left: 4px solid var(--warning-color);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 0.75rem;
  transition: all 0.3s ease;
}

.warning-item:hover {
  background: rgba(243, 156, 18, 0.15);
  transform: translateX(5px);
}

.history-item {
  background: white;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 0.75rem;
  transition: all 0.3s ease;
}

.history-item:hover {
  box-shadow: var(--shadow-sm);
  transform: translateY(-2px);
}

.action-buttons {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.section-title {
  color: var(--text-primary);
  font-weight: 700;
  margin-bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.form-floating {
  margin-bottom: 1.5rem;
}

.breadcrumb-nav {
  background: white;
  border-radius: 12px;
  padding: 1rem 1.5rem;
  margin-bottom: 2rem;
  box-shadow: var(--shadow-sm);
  border: 1px solid var(--border-color);
}

.breadcrumb {
  margin: 0;
  background: none;
}

.breadcrumb-item a {
  color: var(--primary-color);
  text-decoration: none;
  font-weight: 500;
}

.breadcrumb-item.active {
  color: var(--text-secondary);
}
</style>
</head>
<body>

<!-- Enhanced Navbar -->
<nav class="navbar navbar-dark px-4">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php">
      <i class="fas fa-arrow-left me-2"></i>
      <span>Admin Dashboard</span>
    </a>
    <div class="d-flex align-items-center">
      <span class="text-white-50 small me-3"><?= htmlspecialchars($_SESSION['admin_email']) ?></span>
      <div class="dropdown">
        <button class="btn btn-outline-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
          <i class="fas fa-user-circle me-1"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="admin_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
          <li><a class="dropdown-item" href="admin_bookings_list.php"><i class="fas fa-calendar me-2"></i>All Bookings</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="admin_logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="container">
  <!-- Breadcrumb Navigation -->
  <nav class="breadcrumb-nav">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="admin_dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
      <li class="breadcrumb-item"><a href="admin_dashboard.php#bookings">Bookings</a></li>
      <li class="breadcrumb-item active">Edit Booking #<?= htmlspecialchars($bookingId) ?></li>
    </ol>
  </nav>

  <!-- Booking Header -->
  <div class="booking-header">
    <div class="row align-items-center">
      <div class="col-md-8">
        <div class="booking-id">
          <i class="fas fa-edit me-2"></i>
          Booking #<?= htmlspecialchars($bookingId) ?>
        </div>
        <div class="booking-meta">
          <i class="fas fa-user me-2"></i><?= htmlspecialchars($booking['name']) ?> (Grade <?= htmlspecialchars($booking['grade']) ?>)
          <span class="mx-3">•</span>
          <i class="fas fa-book me-2"></i><?= htmlspecialchars($booking['subject']) ?>
          <span class="mx-3">•</span>
          <i class="fas fa-calendar me-2"></i><?= date('M j, Y H:i', strtotime($booking['booking_date'])) ?>
        </div>
      </div>
      <div class="col-md-4 text-md-end">
        <span class="status-badge status-<?= $booking['status'] ?? 'booked' ?>">
          <?= ucfirst($booking['status'] ?? 'booked') ?>
        </span>
      </div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
      <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
      <?= htmlspecialchars($flash['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left: Edit Form -->
    <div class="col-lg-7">
      <div class="card">
        <div class="card-body p-4">
          <h5 class="section-title">
            <i class="fas fa-edit"></i>
            Booking Details
          </h5>
          
          <form method="POST">
            <input type="hidden" name="action" value="update">
            
            <div class="form-floating">
              <input type="text" class="form-control" id="student" disabled 
                     value="<?= htmlspecialchars($booking['name'].' (Grade '.$booking['grade'].')') ?>">
              <label for="student"><i class="fas fa-user me-2"></i>Student</label>
            </div>

            <div class="form-floating">
              <input type="text" name="subject" class="form-control" id="subject" required 
                     value="<?= htmlspecialchars($booking['subject']) ?>">
              <label for="subject"><i class="fas fa-book me-2"></i>Subject</label>
            </div>

            <div class="form-floating">
              <input type="datetime-local" name="booking_date" class="form-control" id="booking_date" required 
                     value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($booking['booking_date']))) ?>">
              <label for="booking_date"><i class="fas fa-calendar me-2"></i>Date & Time</label>
            </div>
          </div>

            <?php if ($hasTeacherId): ?>
            <div class="form-floating">
              <select name="teacher_id" class="form-select" id="teacher">
                <option value="">— Not assigned —</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= intval($t['id']) ?>" <?= (!empty($booking['teacher_id']) && intval($booking['teacher_id']) === intval($t['id']))?'selected':'' ?>>
                    <?= htmlspecialchars($t['name']) ?> <?= $t['grades'] ? ' ('.$t['grades'].')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <label for="teacher"><i class="fas fa-chalkboard-teacher me-2"></i>Teacher</label>
            </div>
            <?php endif; ?>

            <?php if ($hasStatus): ?>
            <div class="form-floating">
              <select name="status" class="form-select" id="status" required>
                <?php
                  $current = $booking['status'] ?? 'booked';
                  foreach ($ALLOWED_STATUS as $st):
                ?>
                  <option value="<?= $st ?>" <?= $st===$current?'selected':'' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
              </select>
              <label for="status"><i class="fas fa-flag me-2"></i>Status</label>
            </div>
            <?php endif; ?>

            <div class="action-buttons">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-2"></i>Save Changes
              </button>
              <button type="submit" name="action" value="send_reminder" class="btn btn-outline-primary">
                <i class="fas fa-envelope me-2"></i>Send Reminder
              </button>
              <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="fas fa-trash me-2"></i>Cancel Booking
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Right: Warnings + History -->
    <div class="col-lg-5">
      <!-- Student Warnings -->
      <div class="card mb-4">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="section-title mb-0">
              <i class="fas fa-exclamation-triangle text-warning"></i>
              Student Warnings
            </h5>
            <a class="btn btn-sm btn-outline-primary" href="admin_student_warnings.php?id=<?= intval($booking['user_id']) ?>">
              <i class="fas fa-external-link-alt me-1"></i>View All
            </a>
          </div>

          <?php if (count($warnings) === 0): ?>
            <div class="alert alert-success">
              <i class="fas fa-check-circle me-2"></i>
              No warnings for this student.
            </div>
          <?php else: ?>
            <div class="warnings-list mb-3">
              <?php foreach ($warnings as $w): ?>
                <div class="warning-item">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <strong class="text-warning">
                      <i class="fas fa-exclamation-triangle me-1"></i>
                      Warning
                    </strong>
                    <small class="text-muted"><?= date('M j, Y H:i', strtotime($w['issued_at'])) ?></small>
                  </div>
                  <div class="mb-1">
                    <strong>By:</strong> <?= htmlspecialchars($w['issued_by'] ?: 'System') ?>
                  </div>
                  <div class="warning-reason">
                    <?= nl2br(htmlspecialchars($w['reason'])) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="action" value="add_warning">
            <div class="form-floating mb-3">
              <textarea name="warning_reason" class="form-control" id="warning_reason" 
                        style="height: 100px" placeholder="Enter warning reason..." required></textarea>
              <label for="warning_reason"><i class="fas fa-edit me-2"></i>Add New Warning</label>
            </div>
            <button type="submit" class="btn btn-warning">
              <i class="fas fa-plus me-2"></i>Add Warning
            </button>
          </form>
        </div>
      </div>

      <!-- Booking History -->
      <div class="card">
        <div class="card-body p-4">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="section-title mb-0">
              <i class="fas fa-history text-info"></i>
              Booking History
            </h5>
            <form class="d-flex gap-2 align-items-center" method="GET">
              <input type="hidden" name="id" value="<?= intval($bookingId) ?>">
              <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="week"  <?= $period==='week'?'selected':''  ?>>This week</option>
                <option value="month" <?= $period==='month'?'selected':'' ?>>This month</option>
                <option value="custom"<?= $period==='custom'?'selected':''?>>Custom</option>
              </select>
              <?php if ($period==='custom'): ?>
                <input type="date" name="start" class="form-control form-control-sm" value="<?= htmlspecialchars($startStr) ?>">
                <input type="date" name="end"   class="form-control form-control-sm" value="<?= htmlspecialchars($endStr) ?>">
                <button class="btn btn-sm btn-outline-primary">Apply</button>
              <?php endif; ?>
            </form>
          </div>

          <?php if (count($history) === 0): ?>
            <div class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i>
              No history logs for the selected period.
            </div>
          <?php else: ?>
            <div class="history-list">
              <?php foreach ($history as $h): ?>
                <div class="history-item">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <strong class="text-primary">
                      <i class="fas fa-edit me-1"></i>
                      <?= htmlspecialchars($h['action']) ?>
                    </strong>
                    <small class="text-muted"><?= date('M j, Y H:i', strtotime($h['timestamp'])) ?></small>
                  </div>
                  <div class="mb-1">
                    <strong>Admin:</strong> <?= htmlspecialchars($h['admin_email']) ?>
                  </div>
                  <div class="history-details small text-muted">
                    <?php
                      $old = $h['old_value']; $new = $h['new_value'];
                      $oj = json_decode($old, true);
                      $nj = json_decode($new, true);
                      if ($oj && $nj) {
                        echo "<strong>Changes:</strong><br>";
                        foreach ($nj as $key => $newVal) {
                          if (isset($oj[$key]) && $oj[$key] != $newVal) {
                            echo "• " . ucfirst($key) . ": " . htmlspecialchars($oj[$key]) . " → " . htmlspecialchars($newVal) . "<br>";
                          }
                        }
                      } else {
                        echo htmlspecialchars($h['changes'] ?: 'No details available');
                      }
                    ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Cancel Booking Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="cancel">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">
          <i class="fas fa-trash me-2"></i>
          Cancel Booking #<?= intval($bookingId) ?>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <strong>Warning:</strong> This action cannot be undone.
        </div>
        <p>Are you sure you want to cancel this booking?</p>
        <ul class="list-unstyled">
          <li><strong>Student:</strong> <?= htmlspecialchars($booking['name']) ?></li>
          <li><strong>Subject:</strong> <?= htmlspecialchars($booking['subject']) ?></li>
          <li><strong>Date:</strong> <?= date('M j, Y H:i', strtotime($booking['booking_date'])) ?></li>
        </ul>
        <div class="form-floating">
          <textarea name="cancel_reason" class="form-control" id="cancel_reason" 
                    style="height: 100px" placeholder="Enter cancellation reason..." required></textarea>
          <label for="cancel_reason">Cancellation Reason</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-2"></i>Keep Booking
        </button>
        <button type="submit" class="btn btn-danger">
          <i class="fas fa-trash me-2"></i>Cancel Booking
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-dismiss alerts
document.querySelectorAll('.alert').forEach(alert => {
  setTimeout(() => {
    if (alert.classList.contains('show')) {
      const bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    }
  }, 5000);
});

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
  const requiredFields = this.querySelectorAll('[required]');
  let isValid = true;
  
  requiredFields.forEach(field => {
    if (!field.value.trim()) {
      field.classList.add('is-invalid');
      isValid = false;
    } else {
      field.classList.remove('is-invalid');
    }
  });
  
  if (!isValid) {
    e.preventDefault();
    alert('Please fill in all required fields.');
  }
});

// Smooth animations
document.querySelectorAll('.card').forEach((card, index) => {
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
