<?php
session_start();
include('db_connect.php');

// 1️⃣ Determine user type
$isTeacher = isset($_SESSION['teacher_email']);
$isStudent = isset($_SESSION['user_id']);

if (!$isTeacher && !$isStudent) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$old_password || !$new_password || !$confirm_password) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirm password do not match.";
    } else {
        if ($isTeacher) {
            // Teachers
            $email = $_SESSION['teacher_email'];
            $stmt = $conn->prepare("SELECT password FROM teachers WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!password_verify($old_password, $res['password'])) {
                $error = "Old password is incorrect.";
            } else {
                $new_hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE teachers SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $new_hashed, $email);
                $stmt->execute();
                $stmt->close();
                $success = "Password changed successfully.";
            }
        } else {
            // Students
            $id = $_SESSION['user_id'];
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (md5($old_password) !== $res['password']) {
                $error = "Old password is incorrect.";
            } else {
                $new_hashed = md5($new_password);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $new_hashed, $id);
                $stmt->execute();
                $stmt->close();
                $success = "Password changed successfully.";
            }
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Change Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5" style="max-width: 400px;">
    <div class="card shadow-lg p-4">
        <h3 class="mb-3 text-center">Change Password</h3>
        <?php if($error) echo "<div class='alert alert-danger'>$error</div>"; ?>
        <?php if($success) echo "<div class='alert alert-success'>$success</div>"; ?>
        <form method="POST">
            <div class="mb-3">
                <label>Old Password</label>
                <input type="password" name="old_password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>New Password</label>
                <input type="password" name="new_password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Change Password</button>
        </form>
        <a href="<?= $isTeacher ? 'teachers.php' : 'book.php' ?>" class="btn btn-secondary w-100 mt-2">Back</a>
    </div>
</div>

</body>
</html>
