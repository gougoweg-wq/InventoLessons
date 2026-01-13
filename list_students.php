<?php
session_start();
include('db_connect.php');
if (!isset($_SESSION['teacher_email'])) {
    header("Location: index.php");
    exit();
}

$teacherGrades = $_SESSION['teacher_grades'];
$allowedGrades = [];
foreach (explode(",", $teacherGrades) as $range) {
    $range = trim($range);
    if (strpos($range, "-") !== false) {
        list($start, $end) = explode("-", $range);
        for ($i = (int)$start; $i <= (int)$end; $i++) {
            $allowedGrades[] = (string)$i;
        }
    } else {
        $allowedGrades[] = (string)$range;
    }
}

// Fetch students
$placeholders = str_repeat('?,', count($allowedGrades)-1) . '?';
$paramTypes = str_repeat('s', count($allowedGrades));
$stmt = $conn->prepare("SELECT name, email, grade FROM users WHERE grade IN ($placeholders)");
$stmt->bind_param($paramTypes, ...$allowedGrades);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Students List</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
  <div class="card p-4">
    <h4 class="mb-3">Students in Your Grades (<?= htmlspecialchars($_SESSION['teacher_grades']) ?>)</h4>
    <table class="table table-bordered">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Grade</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['name']) ?></td>
          <td><?= htmlspecialchars($s['email']) ?></td>
          <td><?= htmlspecialchars($s['grade']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <a href="teachers.php" class="btn btn-primary">Back to Dashboard</a>
  </div>
</div>
</body>
</html>
