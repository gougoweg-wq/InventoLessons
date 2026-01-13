<?php
// admin_nav.php - Include this at the top of all admin pages
session_start();
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
    header("Location: index.php");
    exit();
}
?>
<nav class="navbar navbar-dark sticky-top" style="background:#3D90D7;">
  <div class="container-fluid px-4">
    <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php">
      <img src="invento.png" height="40" class="me-2" alt="Invento Logo">
      <span>Admin Panel</span>
    </a>
    <div class="d-flex align-items-center gap-3">
      <span class="text-white-50 small"><?= htmlspecialchars($_SESSION['admin_email']) ?></span>
      <div class="dropdown">
        <button class="btn btn-outline-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
          Settings
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#changePassModal">Change Password</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="admin_logout.php">Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<!-- Change Password Modal -->
<div class="modal fade" id="changePassModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" action="admin_change_password.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Change Password</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Current Password</label>
          <input type="password" name="current" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">New Password</label>
          <input type="password" name="new" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm New Password</label>
          <input type="password" name="confirm" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Update Password</button>
      </div>
    </form>
  </div>
</div>