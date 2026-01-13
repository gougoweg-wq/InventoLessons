<?php
echo headerSection($logo, "Monthly Summary Report");
$total = array_sum($stats);
$attendanceRate = $total ? round(($stats['visited'] / $total) * 100, 1) : 0;
?>

<h3><?= htmlspecialchars($name) ?> – Grade <?= htmlspecialchars($grade) ?></h3>
<p>Period: <?= $from ?> → <?= $to ?></p>

<table border="1" cellpadding="8" width="100%">
  <tr>
    <th>Total Bookings</th>
    <th>Visited</th>
    <th>Attendance Rate</th>
  </tr>
  <tr>
    <td><?= $total ?></td>
    <td><?= $stats['visited'] ?></td>
    <td><?= $attendanceRate ?>%</td>
  </tr>
</table>

<h4 style="margin-top:20px;">All Lessons</h4>
<table border="1" cellpadding="8" width="100%">
  <tr>
    <th>Date</th>
    <th>Subject</th>
    <th>Status</th>
  </tr>
  <?php foreach($bookings as $b): ?>
  <tr>
    <td><?= $b['booking_date'] ?></td>
    <td><?= htmlspecialchars($b['subject']) ?></td>
    <td><?= htmlspecialchars($b['status']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?= footerSection(); ?>
