<?php
// Teacher Feedback Report Template
// This file is included by report_pdf.php and has access to all its variables
?>

<div class="header">
  <img src="<?= $logoBase64 ?>">
  <h2>Teacher Feedback Report</h2>
</div>

<h3 class="section-title">📅 Student Information</h3>
<p><strong>Name:</strong> <?= htmlspecialchars($name) ?><br>
<strong>Grade:</strong> <?= htmlspecialchars($grade) ?><br>
<strong>Email:</strong> <?= htmlspecialchars($email) ?><br>
<strong>Report Period:</strong> <?= $from ?> → <?= $to ?></p>

<h3 class="section-title">💬 Teacher Comments & Feedback</h3>
<p>Review of teacher feedback for your lessons during the selected period.</p>

<?php if (empty($bookingsArr)): ?>
  <p style="text-align:center;color:#666;margin-top:30px;">No lessons found for the selected period.</p>
<?php else: ?>
  <table>
    <tr>
      <th>Date</th>
      <th>Subject</th>
      <th>Status</th>
      <th>Teacher Feedback</th>
    </tr>
    <?php foreach($bookingsArr as $b): ?>
    <tr>
      <td><?= date('M d, Y', strtotime($b['booking_date'])) ?></td>
      <td><?= htmlspecialchars($b['subject']) ?></td>
      <td style="<?= $b['status'] === 'visited' ? 'color:#28a745;' : ($b['status'] === 'canceled' ? 'color:#dc3545;' : 'color:#ff9800;') ?>">
        <?= ucfirst($b['status']) ?>
      </td>
      <td style="text-align:left;">
        <?= !empty($b['teacher_comment']) ? htmlspecialchars($b['teacher_comment']) : '<em style="color:#999;">No feedback provided</em>' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php if (!empty($signatureBase64)): ?>
<div style="text-align:right;margin-top:50px;margin-right:30px;">
  <img src="<?= $signatureBase64 ?>" height="60"><br>
  <strong>Academic Coordinator</strong><br>
  <small style="color:#666;"><?= date('F d, Y') ?></small>
</div>
<?php endif; ?>

<div class="footer">Invento – The Uzbek International School © <?= date('Y') ?></div>
