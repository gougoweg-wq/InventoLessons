<?php
// Attendance Report Template
// This file is included by report_pdf.php and has access to all its variables
?>

<div class="header">
  <img src="<?= $logoBase64 ?>">
  <h2>Attendance Report</h2>
</div>

<h3 class="section-title">📅 Student Information</h3>
<p><strong>Name:</strong> <?= htmlspecialchars($name) ?><br>
<strong>Grade:</strong> <?= htmlspecialchars($grade) ?><br>
<strong>Email:</strong> <?= htmlspecialchars($email) ?><br>
<strong>Report Period:</strong> <?= $from ?> → <?= $to ?></p>

<h3 class="section-title">📊 Attendance Summary</h3>
<table>
  <tr>
    <th>Status</th>
    <th>Count</th>
    <th>Percentage</th>
  </tr>
  <?php 
  $totalLessons = array_sum($stats);
  foreach($stats as $status => $count): 
    $percentage = $totalLessons > 0 ? round(($count / $totalLessons) * 100, 1) : 0;
  ?>
  <tr>
    <td><?= ucfirst($status) ?></td>
    <td><?= $count ?></td>
    <td><?= $percentage ?>%</td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#e8f4f8;">
    <th>Total Lessons</th>
    <th><?= $totalLessons ?></th>
    <th>100%</th>
  </tr>
</table>

<div style="text-align:center;margin-top:30px;">
  <img src="<?= chart_pie_attendance($stats) ?>" width="280"><br>
  <small style="color:#666;">Attendance Distribution Chart</small>
</div>

<h3 class="section-title">📚 Detailed Lessons</h3>
<?php if (empty($bookingsArr)): ?>
  <p style="text-align:center;color:#666;margin-top:30px;">No lessons found for the selected period.</p>
<?php else: ?>
  <table>
    <tr>
      <th>Date</th>
      <th>Subject</th>
      <th>Status</th>
      <th>Teacher Comment</th>
    </tr>
    <?php foreach($bookingsArr as $b): ?>
    <tr>
      <td><?= date('M d, Y', strtotime($b['booking_date'])) ?></td>
      <td><?= htmlspecialchars($b['subject']) ?></td>
      <td style="<?= $b['status'] === 'visited' ? 'color:#28a745;' : ($b['status'] === 'canceled' ? 'color:#dc3545;' : 'color:#ff9800;') ?>">
        <?= ucfirst($b['status']) ?>
      </td>
      <td style="text-align:left;">
        <?= !empty($b['teacher_comment']) ? htmlspecialchars($b['teacher_comment']) : '<em style="color:#999;">No comment</em>' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<div class="footer">Invento – The Uzbek International School © <?= date('Y') ?></div>
