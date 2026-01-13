<?php
include('db_connect.php');

header("Content-Type: text/csv; charset=UTF-8");
header('Content-Disposition: attachment; filename="email_logs.csv"');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$out = fopen("php://output", "w");
fputcsv($out, ['Date','Teacher','Student','Grade','Parent Email','Language','Message']);

// Base query
$sql = "SELECT l.*, t.name AS teacher_name
        FROM teacher_email_logs l
        LEFT JOIN teachers t ON t.id = l.teacher_id
        WHERE 1=1";

// Filters (safe via real_escape_string)
$params = [];
if (!empty($_GET['teacher'])) {
    $v = $conn->real_escape_string($_GET['teacher']);
    $sql .= " AND t.name LIKE '%$v%'";
}
if (!empty($_GET['student'])) {
    $v = $conn->real_escape_string($_GET['student']);
    $sql .= " AND l.student_name LIKE '%$v%'";
}
if (!empty($_GET['email'])) {
    $v = $conn->real_escape_string($_GET['email']);
    $sql .= " AND l.parent_email LIKE '%$v%'";
}
if (!empty($_GET['grade'])) {
    $v = $conn->real_escape_string($_GET['grade']);
    $sql .= " AND l.student_grade = '$v'";
}
if (!empty($_GET['language'])) {
    $v = $conn->real_escape_string($_GET['language']);
    $sql .= " AND l.language = '$v'";
}
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $s = $conn->real_escape_string($_GET['start_date']);
    $e = $conn->real_escape_string($_GET['end_date']);
    $sql .= " AND DATE(l.sent_at) BETWEEN '$s' AND '$e'";
}

$sql .= " ORDER BY l.sent_at DESC";

$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        // Keep message as plain text (strip tags) for CSV exports
        $msg = isset($r['message']) ? strip_tags($r['message']) : '';
        fputcsv($out, [$r['sent_at'] ?? '', $r['teacher_name'] ?? '', $r['student_name'] ?? '', $r['student_grade'] ?? '', $r['parent_email'] ?? '', $r['language'] ?? '', $msg]);
    }
}
fclose($out);
exit;
?>
