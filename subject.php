<?php
// Subject Performance Report Template
// This file is included by report_pdf.php and has access to all its variables

// Calculate subject-wise statistics
$subjectStats = [];
foreach($bookingsArr as $b) {
  $subject = $b['subject'];
  // Normalize status for consistency
  $status = strtolower(trim($b['status'] ?? ''));
  if ($status === 'cancelled') $status = 'canceled'; // Handle spelling variations
  
  if (!isset($subjectStats[$subject])) {
    $subjectStats[$subject] = [
      'visited' => 0,
      'booked' => 0,
      'canceled' => 0,
      'not attended' => 0,
      'total' => 0
    ];
  }
  if (isset($subjectStats[$subject][$status])) {
    $subjectStats[$subject][$status]++;
  }
  $subjectStats[$subject]['total']++;
}
?>

<div class="header">
  <img src="<?= $logoBase64 ?>">
  <h2>Subject Performance Report</h2>
</div>

<h3 class="section-title">📅 Student Information</h3>
<p><strong>Name:</strong> <?= htmlspecialchars($name) ?><br>
<strong>Grade:</strong> <?= htmlspecialchars($grade) ?><br>
<strong>Email:</strong> <?= htmlspecialchars($email) ?><br>
<strong>Report Period:</strong> <?= $from ?> → <?= $to ?></p>

<h3 class="section-title">📘 Performance by Subject</h3>
<p>This report shows your attendance performance across different subjects.</p>

<?php if (empty($subjectStats)): ?>
  <p style="text-align:center;color:#666;margin-top:30px;">No lessons found for the selected period.</p>
<?php else: ?>
  <table>
    <tr>
      <th>Subject</th>
      <th>Total Lessons</th>
      <th>Attended</th>
      <th>Canceled</th>
      <th>Not Attended</th>
      <th>Still Booked</th>
      <th>Attendance Rate</th>
    </tr>
    <?php foreach($subjectStats as $subject => $stats): ?>
    <?php 
      $attendanceRate = $stats['total'] > 0 ? round(($stats['visited'] / $stats['total']) * 100, 1) : 0;
      $rateColor = $attendanceRate >= 80 ? 'color:#28a745;' : ($attendanceRate >= 60 ? 'color:#ff9800;' : 'color:#dc3545;');
    ?>
    <tr>
      <td style="text-align:left;font-weight:bold;"><?= htmlspecialchars($subject) ?></td>
      <td><?= $stats['total'] ?></td>
      <td style="color:#28a745;"><?= $stats['visited'] ?></td>
      <td style="color:#dc3545;"><?= $stats['canceled'] ?></td>
      <td style="color:#ff9800;"><?= $stats['not attended'] ?></td>
      <td style="color:#3498db;"><?= $stats['booked'] ?></td>
      <td style="<?= $rateColor ?>font-weight:bold;"><?= $attendanceRate ?>%</td>
    </tr>
    <?php endforeach; ?>
  </table>

  <div style="text-align:center;margin-top:30px;">
    <?php [$barChart, $summary] = chart_bar_subjects($bookingsArr); ?>
    <img src="<?= $barChart ?>" width="480"><br>
    <small style="color:#666;">Subject Attendance Rate Comparison</small>
  </div>
<?php endif; ?>

<h3 class="section-title">📚 Detailed Lessons by Subject</h3>
<?php if (empty($bookingsArr)): ?>
  <p style="text-align:center;color:#666;margin-top:30px;">No lessons found for the selected period.</p>
<?php else: ?>
  <?php 
  // Group lessons by subject
  $lessonsBySubject = [];
  foreach($bookingsArr as $b) {
    $lessonsBySubject[$b['subject']][] = $b;
  }
  ?>
  
  <?php foreach($lessonsBySubject as $subject => $lessons): ?>
    <h4 style="color:#1a5f7a;margin-top:25px;margin-bottom:15px;border-bottom:1px solid #1a5f7a;padding-bottom:5px;">
      <?= htmlspecialchars($subject) ?> (<?= count($lessons) ?> lessons)
    </h4>
    <table style="margin-bottom:20px;">
      <tr>
        <th>Date</th>
        <th>Status</th>
        <th>Teacher Comment</th>
      </tr>
      <?php foreach($lessons as $lesson): ?>
      <?php 
        // Normalize status for consistent display
        $lessonStatus = strtolower(trim($lesson['status'] ?? ''));
        if ($lessonStatus === 'cancelled') $lessonStatus = 'canceled';
      ?>
      <tr>
        <td><?= date('M d, Y', strtotime($lesson['booking_date'])) ?></td>
        <td style="<?= $lessonStatus === 'visited' ? 'color:#28a745;' : ($lessonStatus === 'canceled' ? 'color:#dc3545;' : 'color:#ff9800;') ?>">
          <?= ucfirst($lessonStatus) ?>
        </td>
        <td style="text-align:left;">
          <?= !empty($lesson['teacher_comment']) ? htmlspecialchars($lesson['teacher_comment']) : '<em style="color:#999;">No comment</em>' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
<?php endif; ?>

<div class="footer">Invento – The Uzbek International School © <?= date('Y') ?></div>
