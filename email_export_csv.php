<?php
include('db_connect.php');
header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=email_logs.csv");

$out = fopen("php://output", "w");
fputcsv($out, ['Date','Teacher','Student','Grade','Parent Email','Language','Message']);

$sql = "SELECT l.*, t.name AS teacher_name
        FROM teacher_email_logs l LEFT JOIN teachers t ON t.id=l.teacher_id WHERE 1";

$map = [
  'elog_teacher'  => [" AND t.name LIKE '%s%'", true],
  'elog_student'  => [" AND l.student_name LIKE '%s%'", true],
  'elog_email'    => [" AND l.parent_email LIKE '%s%'", true],
  'elog_grade'    => [" AND l.student_grade='%s'", false],
  'elog_language' => [" AND l.language='%s'", false],
];

foreach ($map as $k => [$fmt, $like]) {
  if (!empty($_GET[$k])) {
    $v = $conn->real_escape_string($_GET[$k]);
    $sql .= sprintf($fmt, $v);
  }
}
if (!empty($_GET['elog_start']) && !empty($_GET['elog_end'])) {
  $s = $conn->real_escape_string($_GET['elog_start']);
  $e = $conn->real_escape_string($_GET['elog_end']);
  $sql .= " AND DATE(l.sent_at) BETWEEN '$s' AND '$e'";
}
$sql .= " ORDER BY l.sent_at DESC";

$res = $conn->query($sql);
while($r = $res->fetch_assoc()) {
  fputcsv($out, [$r['sent_at'],$r['teacher_name'],$r['student_name'],$r['student_grade'],$r['parent_email'],$r['language'],$r['message']]);
}
fclose($out); exit;
