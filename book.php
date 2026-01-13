<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
include('db_connect.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/Exception.php';

// --- HELPERS ---
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ($needle === '') return true;
        $len = strlen($needle);
        return $len === 0 ? true : substr($haystack, -$len) === $needle;
    }
}

function normalizeSubject(string $s): string {
    $s = preg_replace('/ (HL|SL)$/i', '', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9 ]+/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function isPathInUploads(?string $path): bool {
    if (!$path) return false;
    $real = @realpath($path);
    $uploads = @realpath(__DIR__ . '/uploads');
    return $real && $uploads && strncmp($real, $uploads, strlen($uploads)) === 0 && file_exists($real);
}

function jsonSafe($data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function sendEmail(string $to, string $name, string $subjectLine, string $title, string $contentHtml, ?string $avatarPath): void {
    $mail = new PHPMailer(true);
    try {
        $host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $username = getenv('MAIL_USERNAME') ?: '';
        $password = getenv('MAIL_PASSWORD') ?: '';
        $port = (int)(getenv('MAIL_PORT') ?: 465);
        $secure = strtolower((string)(getenv('MAIL_SMTP_SECURE') ?: 'ssl'));
        $from = getenv('MAIL_FROM') ?: ($username ?: 'no-reply@example.com');
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Correctional Lessons | Invento';
        $timeout = (int)(getenv('MAIL_TIMEOUT') ?: 10);

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = ($secure === 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $port;
        $mail->Timeout = $timeout;

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to, $name);

        $logo = __DIR__ . '/With logo (1) (1).png';
        $sig = __DIR__ . '/signature.png';
        if (is_file($logo)) $mail->AddEmbeddedImage($logo, 'logoimg');
        if (is_file($sig)) $mail->AddEmbeddedImage($sig, 'signimg');

        $avatarHTML = '';
        if ($avatarPath && isPathInUploads($avatarPath)) {
            $mail->AddEmbeddedImage($avatarPath, 'avatarimg');
            $avatarHTML = "<img src='cid:avatarimg' width='80' height='80' style='border-radius:50%;margin-right:20px;'>";
        }

        $mail->isHTML(true);
        $mail->Subject = $subjectLine;
        $mail->Body = "<div style='font-family:Segoe UI,sans-serif;background:#f8fbff;border-radius:10px;padding:20px'>
            <div style='text-align:center'><img src='cid:logoimg' width='150' alt='Logo'><h2 style='color:#2d6bb3'>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h2></div>
            <div style='background:white;padding:20px;border-radius:10px;display:flex;align-items:center;'>$avatarHTML<div>$contentHtml</div></div>
            <div style='text-align:center;margin-top:20px'><img src='cid:signimg' width='160' alt='Signature'><p style='font-size:12px;color:#777'>Invento – The Uzbek International School</p></div>
        </div>";

        if ($username && $password) {
            $mail->send();
        } else {
            error_log('Email skipped: missing SMTP credentials');
        }
    } catch (Exception $e) {
        error_log('Email failed: ' . $mail->ErrorInfo);
    }
}

// --- AUTH ---
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'student')) {
    header('Location: index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$student_id = (int)$_SESSION['user_id'];

// --- FETCH STUDENT DATA ---
$stmt = $conn->prepare('SELECT name, grade, email, profile_pic FROM users WHERE id=? LIMIT 1');
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($student_name, $student_grade, $student_email, $student_avatar);
$stmt->fetch();
$stmt->close();

$student_avatar = isPathInUploads($student_avatar) ? $student_avatar : 'uploads/basic.jpg';

// --- STUDENT SUBJECT MAPPING (HL/SL) ---
$dp2Students = [
    'afruza' => ["English B HL", "Russian A SL", "Math SL", "Biology HL", "Chemistry HL", "Business Management HL", "TOK"],
    'nigora' => ["English B HL", "Russian A SL", "Math SL", "Biology HL", "Chemistry HL", "Business Management HL", "TOK"],
    'matt' => ["English B HL", "Russian A SL", "Math SL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'alisher' => ["English B HL", "Russian A SL", "Math SL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'nozima' => ["English B HL", "Russian A SL", "Math SL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'ibrohim' => ["English B HL", "Russian A SL", "Math SL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'laylo' => ["English B HL", "Russian A SL", "Math SL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"]
];

$dp1Students = [
    'maftuna' => ["English B HL", "Russian A SL", "Math HL", "Biology HL", "Business Management HL", "Art SL", "TOK"],
    'jasmin' => ["English B SL", "Russian A SL", "Math HL", "Biology HL", "Chemistry HL", "Business Management HL", "TOK"],
    'bassal' => ["English A SL", "Math HL", "Physics SL", "Chemistry HL", "Business Management HL", "TOK"],
    'javohir' => ["English B HL", "Russian A SL", "Math HL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'mokhinur' => ["English B HL", "Russian A SL", "Math HL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'khonzoda' => ["English A HL", "Chemistry HL", "Math SL", "Biology SL", "Business Management HL", "Art SL", "TOK"],
    'sayidbek' => ["English A SL", "Russian B SL", "Math SL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'said' => ["English B HL", "Russian A SL", "Math HL", "Computer Science SL", "Business Management HL", "Art SL", "TOK"],
    'odilzhon' => ["English B SL", "Russian A SL", "Math HL", "Physics SL", "Computer Science SL", "Business Management HL", "TOK"]
];

$mypSubjects = [
    '6' => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Science", "English A", "English B"],
    '7' => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Science", "English A", "English B"],
    '8' => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Science", "English A", "English B"],
    '9' => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Physics", "Chemistry", "Biology", "English A", "English B"],
    '10' => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Physics", "Chemistry", "Biology", "English A", "English B"]
];

$first = strtolower(explode(' ', (string)$student_name)[0] ?? '');
$studentSubjects = [];

if ((int)$student_grade === 12) {
    $studentSubjects = $dp2Students[$first] ?? [];
} elseif ((int)$student_grade === 11) {
    $studentSubjects = $dp1Students[$first] ?? [];
} elseif (isset($mypSubjects[(string)$student_grade])) {
    $studentSubjects = $mypSubjects[(string)$student_grade];
}

$allowedSubjects = array_values(array_unique(array_map(function ($subj) {
    return preg_replace('/ (HL|SL)$/i', '', $subj);
}, $studentSubjects)));

// --- GOOGLE CALENDAR EVENTS (cached) ---
$ics_url = 'https://calendar.google.com/calendar/ical/c_fa8beeecc764d2836e99bf057540e15f037c8a762be33c3a0a660a1f45862f90%40group.calendar.google.com/public/basic.ics';
$allEvents = [];
$cacheFile = sys_get_temp_dir() . '/calendar_cache_' . md5($ics_url) . '.json';
$cacheTime = 300; // 5 minutes

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    $decoded = json_decode((string)file_get_contents($cacheFile), true);
    $allEvents = is_array($decoded) ? $decoded : [];
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'InventoBooking/1.0']]);
    $ics_content = @file_get_contents($ics_url, false, $ctx);
    if ($ics_content) {
        $tz = new DateTimeZone('Asia/Tashkent');
        if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics_content, $matches)) {
            foreach ($matches[1] as $event) {
                // Unfold folded lines per RFC5545
                $event = preg_replace("/\r\n[ \t]/", '', $event);

                // Ignore all-day events (VALUE=DATE)
                if (!preg_match('/DTSTART[^:]*:(\d{8}T\d{6}Z?)/', $event, $start)) {
                    continue;
                }
                $dateStr = $start[1];
                $date = null;
                if (str_ends_with($dateStr, 'Z')) {
                    $date = new DateTime($dateStr, new DateTimeZone('UTC'));
                    $date->setTimezone($tz);
                } else {
                    $date = DateTime::createFromFormat('Ymd\THis', $dateStr, $tz);
                }
                if (!$date) continue;

                if (!preg_match('/SUMMARY:(.*)/', $event, $summary)) continue;
                $sum = trim($summary[1]);

                if ($date > new DateTime('now', $tz)) {
                    $allEvents[] = ['date' => $date->format('Y-m-d H:i'), 'summary' => $sum];
                }
            }
        }
        @file_put_contents($cacheFile, json_encode($allEvents, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

// --- BOOKING SUBMISSION ---
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $slotRaw = trim((string)($_POST['slot'] ?? ''));
        $subjectPosted = trim((string)($_POST['subject'] ?? ''));

        // Basic validation
        if (empty($slotRaw) || empty($subjectPosted)) {
            $error = '❌ Please select both subject and time slot.';
        } else {
            // Validate subject against whitelist (normalized)
            $normalizedPostedId = normalizeSubject($subjectPosted);
            $allowedIds = array_map('normalizeSubject', $allowedSubjects);
            if (!in_array($normalizedPostedId, $allowedIds, true)) {
                $error = '❌ Invalid subject selection.';
            } else {
                // Validate slot time (Asia/Tashkent)
                $tz = new DateTimeZone('Asia/Tashkent');
                $slot = DateTime::createFromFormat('Y-m-d H:i', $slotRaw, $tz) ?: DateTime::createFromFormat('Y-m-d H:i:s', $slotRaw, $tz);
                if (!$slot) {
                    $error = '❌ Invalid slot time format.';
                } elseif ($slot < new DateTime('now', $tz)) {
                    $error = '❌ Slot must be in the future.';
                } else {
                    // Prevent overlapping bookings (±1 hour)
                    $startTime = (clone $slot)->modify('-1 hour');
                    $endTime = (clone $slot)->modify('+1 hour');
                    $stmtCheck = $conn->prepare('SELECT COUNT(*) FROM bookings WHERE user_id=? AND booking_date BETWEEN ? AND ? LIMIT 1');
                    $start = $startTime->format('Y-m-d H:i:s');
                    $end = $endTime->format('Y-m-d H:i:s');
                    $stmtCheck->bind_param('iss', $student_id, $start, $end);
                    $stmtCheck->execute();
                    $stmtCheck->bind_result($conflictCount);
                    $stmtCheck->fetch();
                    $stmtCheck->close();

                    if ((int)$conflictCount > 0) {
                        $error = '❌ You already have a booking within 1 hour of this time.';
                    } else {
                        // Verify slot exists in ICS calendar
                        $slotExists = false;
                        foreach ($allEvents as $event) {
                            $eventDt = new DateTime($event['date'], $tz);
                            if ($eventDt->format('Y-m-d H:i') === $slot->format('Y-m-d H:i')) {
                                $slotExists = true;
                                break;
                            }
                        }
                        if (!$slotExists) {
                            $error = '❌ This slot is not available in the teacher\'s calendar.';
                        } else {
                            // Prevent exact duplicate (same user, subject, datetime) unless canceled
                            $stmtDup = $conn->prepare("SELECT COUNT(*) FROM bookings WHERE user_id=? AND subject=? AND booking_date=? AND (status IS NULL OR status<>'canceled') LIMIT 1");
                            $bookingDate = $slot->format('Y-m-d H:i:s');
                            $stmtDup->bind_param('iss', $student_id, $subjectPosted, $bookingDate);
                            $stmtDup->execute();
                            $stmtDup->bind_result($dupCount);
                            $stmtDup->fetch();
                            $stmtDup->close();
                            if ((int)$dupCount > 0) {
                                $error = '❌ You already booked this exact slot.';
                            } else {
                                // Insert booking with additional validation
                                $stmt = $conn->prepare('INSERT INTO bookings(user_id, subject, booking_date, created_at, student_name, student_grade, student_email) VALUES (?, ?, ?, NOW(), ?, ?, ?)');
                                $stmt->bind_param('isssss', $student_id, $subjectPosted, $bookingDate, $student_name, $student_grade, $student_email);

                                if ($stmt->execute()) {
                                    $booking_id = $stmt->insert_id;
                                    $stmt->close();

                                    // Fetch teacher
                                    $teacher_email = null;
                                    $teacher_name = 'Teacher';
                                    $stmtT = $conn->prepare("SELECT name, email FROM teachers WHERE LOWER(course) LIKE ? OR LOWER(grades) LIKE ? LIMIT 1");
                                    $like = '%' . strtolower($subjectPosted) . '%';
                                    $gradeLike = '%' . (string)$student_grade . '%';
                                    $stmtT->bind_param('ss', $like, $gradeLike);
                                    $stmtT->execute();
                                    $stmtT->bind_result($teacher_name, $teacher_email);
                                    $stmtT->fetch();
                                    $stmtT->close();

                                    // Send emails
                                    $studentBody =
                                        '<p>Dear <b>' . htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') . "</b>,</p>\n" .
                                        '<p>Your correctional lesson has been successfully booked:</p>' .
                                        '<ul>' .
                                        '<li><b>Subject:</b> ' . htmlspecialchars($subjectPosted, ENT_QUOTES, 'UTF-8') . '</li>' .
                                        '<li><b>Date &amp; Time:</b> ' . htmlspecialchars($bookingDate, ENT_QUOTES, 'UTF-8') . '</li>' .
                                        '<li><b>Grade:</b> ' . htmlspecialchars((string)$student_grade, ENT_QUOTES, 'UTF-8') . '</li>' .
                                        '</ul>' .
                                        '<p>Please arrive on time.</p>';

                                    $teacherBody =
                                        '<p>Dear <b>' . htmlspecialchars($teacher_name, ENT_QUOTES, 'UTF-8') . "</b>,</p>\n" .
                                        '<p><b>' . htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') . '</b> (Grade ' . htmlspecialchars((string)$student_grade, ENT_QUOTES, 'UTF-8') . ') booked a lesson:</p>' .
                                        '<ul>' .
                                        '<li><b>Subject:</b> ' . htmlspecialchars($subjectPosted, ENT_QUOTES, 'UTF-8') . '</li>' .
                                        '<li><b>Date:</b> ' . htmlspecialchars($bookingDate, ENT_QUOTES, 'UTF-8') . '</li>' .
                                        '<li><b>Student Email:</b> ' . htmlspecialchars($student_email, ENT_QUOTES, 'UTF-8') . '</li>' .
                                        '</ul>';

                                    sendEmail($student_email, $student_name, 'Booking Confirmed – ' . $subjectPosted, 'Booking Confirmation', $studentBody, $student_avatar);
                                    if ($teacher_email) {
                                        sendEmail($teacher_email, $teacher_name, 'New Lesson Booking – ' . $subjectPosted, 'New Lesson Booking', $teacherBody, $student_avatar);
                                    }

                                    $success = '✅ Booking completed successfully for ' . htmlspecialchars($subjectPosted, ENT_QUOTES, 'UTF-8') . ' at ' . htmlspecialchars($bookingDate, ENT_QUOTES, 'UTF-8') . '.';
                                } else {
                                    $error = '❌ Booking failed. Please try again.';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ... (rest of the code remains the same)
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Book a Lesson - Invento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

:root {
  --primary-color: #1a5f7a;
  --primary-dark: #144a5e;
  --primary-light: #2a7a94;
  --secondary-color: #f39c12;
  --success-color: #27ae60;
  --danger-color: #e74c3c;
  --light-bg: #f8fafc;
  --white: #ffffff;
  --text-primary: #2d3748;
  --text-secondary: #718096;
  --border-color: #e2e8f0;
  --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
  --shadow-md: 0 4px 6px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.06);
  --shadow-lg: 0 10px 25px rgba(0,0,0,0.1), 0 6px 10px rgba(0,0,0,0.08);
  --shadow-xl: 0 20px 40px rgba(0,0,0,0.15);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body { 
  background: #ffffff;
  font-family: 'Inter', sans-serif;
  min-height: 100vh;
  position: relative;
}

body::before {
  content: '';
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: 
    radial-gradient(circle at 20% 80%, rgba(26, 95, 122, 0.03) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(243, 156, 18, 0.03) 0%, transparent 50%);
  pointer-events: none;
  z-index: -1;
}

.navbar { 
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
  box-shadow: 0 8px 32px rgba(26, 95, 122, 0.3);
  backdrop-filter: blur(10px);
  border-bottom: 2px solid rgba(255, 255, 255, 0.1);
  padding: 1rem 0;
}

.navbar-brand { color: #fff !important; font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; }

.profile-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.2); transition: all 0.3s ease; }
.profile-avatar:hover { transform: scale(1.1); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }

.dropdown-menu { border-radius: 12px; box-shadow: var(--shadow-lg); border: none; backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.98); }
.dropdown-item { border-radius: 8px; margin: 0.25rem 0.5rem; transition: all 0.3s ease; padding: 0.5rem 1rem; }
.dropdown-item:hover { background: linear-gradient(135deg, rgba(26, 95, 122, 0.1) 0%, rgba(26, 95, 122, 0.05) 100%); transform: translateX(5px); }

.container { max-width: 1200px; margin: 0 auto; padding: 2rem; }

.card { border: none; box-shadow: var(--shadow-xl); border-radius: 20px; background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(20px); transition: all 0.3s ease; overflow: hidden; position: relative; }
.card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--primary-color), var(--secondary-color), var(--primary-color)); background-size: 200% 100%; animation: shimmer 3s linear infinite; }
@keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
.card:hover { transform: translateY(-5px); box-shadow: 0 25px 50px rgba(0,0,0,0.2); }

h3 { color: var(--text-primary); font-weight: 700; margin-bottom: 1.5rem; position: relative; padding-left: 1rem; }
h3::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 24px; background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); border-radius: 2px; }

.form-label { font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; }
.form-select, .form-control { border-radius: 10px; border: 2px solid var(--border-color); padding: 0.75rem 1rem; transition: all 0.3s ease; background: white; }
.form-select:focus, .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.2rem rgba(26, 95, 122, 0.25); transform: translateY(-1px); }

.btn { border-radius: 10px; font-weight: 600; padding: 0.75rem 1.5rem; transition: all 0.3s ease; border: none; position: relative; overflow: hidden; }
.btn::before { content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; border-radius: 50%; background: rgba(255, 255, 255, 0.3); transform: translate(-50%, -50%); transition: width 0.6s, height 0.6s; }
.btn:hover::before { width: 300px; height: 300px; }
.btn-primary { background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); box-shadow: 0 4px 12px rgba(26, 95, 122, 0.3); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(26, 95, 122, 0.4); }
.btn-light { background: rgba(255, 255, 255, 0.9); border: 2px solid rgba(255, 255, 255, 0.3); }
.btn-light:hover { background: white; transform: translateY(-2px); }

.alert { border-radius: 12px; border: none; padding: 1rem 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-md); animation: slideDown 0.3s ease-out; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
.alert-success { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); color: #155724; }
.alert-danger { background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); color: #721c24; }
.fade-out { opacity: 0; transition: opacity 1s ease-out; }
.loading-spinner { display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1000; }
.spinner-border { width: 3rem; height: 3rem; border-width: 0.3rem; }
@media (max-width: 768px) { .container { padding: 1rem; } .navbar-brand { font-size: 1.2rem; } h3 { font-size: 1.3rem; } }
::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: var(--light-bg); }
::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); }
@keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.card { animation: fadeInUp 0.6s ease-out; }
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand">
            <i class="fas fa-calendar-alt"></i> Book a Lesson
        </span>
        <div class="dropdown">
            <button class="btn btn-light border-0 dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown">
                <img src="<?= htmlspecialchars($student_avatar, ENT_QUOTES, 'UTF-8') ?>" onerror="this.src='uploads/basic.jpg';" class="profile-avatar me-2" alt="Avatar">
                <span class="d-none d-md-inline"><?= htmlspecialchars(explode(' ', (string)$student_name)[0] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
                <li><a class="dropdown-item" href="book.php"><i class="fas fa-calendar-alt"></i> Book a Lesson</a></li>
                <li><a class="dropdown-item" href="history.php"><i class="fas fa-history"></i> Your Bookings</a></li>
                <li><a class="dropdown-item" href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li><button class="dropdown-item" id="openSupport" type="button"><i class="fas fa-headset"></i> Support</button></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <?php if(!empty($success)): ?>
        <div id="alertBox" class="alert alert-success text-center fw-bold">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php elseif(!empty($error)): ?>
        <div id="alertBox" class="alert alert-danger text-center fw-bold">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="card p-4 p-md-5">
        <h3><i class="fas fa-calendar-check"></i> Available Slots</h3>
        <form method="POST" id="bookingForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-4">
                <label class="form-label"><i class="fas fa-book me-2"></i>Choose Subject</label>
                <select id="subjectSelect" name="subject" class="form-select" required>
                    <option value="">-- Select Subject --</option>
                    <?php foreach($allowedSubjects as $sub): $id = normalizeSubject($sub); ?>
                        <option value="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>" data-id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label"><i class="fas fa-clock me-2"></i>Select Slot</label>
                <select id="slotSelect" name="slot" class="form-select" required>
                    <option value="">-- Select subject first --</option>
                </select>
                <small class="text-muted mt-1 d-block">
                    <i class="fas fa-info-circle"></i> Only future time slots are shown
                </small>
            </div>
            <button type="submit" class="btn btn-primary w-100" id="submitBtn">
                <i class="fas fa-check me-2"></i>Confirm Booking
            </button>
            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Alert auto-hide
const alertBox = document.getElementById('alertBox');
if (alertBox) {
  setTimeout(() => {
    alertBox.classList.add('fade-out');
    setTimeout(() => alertBox.remove(), 1000);
  }, 5000);
}

// Support navigation
document.getElementById('openSupport')?.addEventListener('click', () => {
  window.location.href = 'support.php';
});

// Calendar events and student data
const events = <?= jsonSafe($allEvents) ?>;
const studentSubjects = <?= jsonSafe($studentSubjects) ?>;
const studentGrade = <?= (int)$student_grade ?>;

// Helpers
function normalizeSubjectId(s) {
  return s.toLowerCase().replace(/ (hl|sl)$/i, '').replace(/[^a-z0-9 ]/g, '').trim().replace(/\s+/g, ' ');
}
function normalizeWithLevel(s) {
  return s.toLowerCase().replace(/[^a-z0-9 ]/g, '').trim().replace(/\s+/g, ' ');
}
function extractGrade(text) {
  const match = text.match(/\b(6|7|8|9|10|11|12)\b/);
  return match ? parseInt(match[1], 10) : null;
}

function matchesStudentLevel(eventSummary, selectedId) {
  const normEvent = normalizeWithLevel(eventSummary);
  if (!normEvent.includes(selectedId)) return false;

  const eventGrade = extractGrade(eventSummary);
  if (eventGrade !== null && eventGrade !== studentGrade) return false;

  if (studentGrade == 11 || studentGrade == 12) {
    let studentLevel = null; // 'hl' or 'sl'
    for (const subj of studentSubjects) {
      if (normalizeSubjectId(subj) === selectedId) {
        if (/\bhl\b/i.test(subj)) studentLevel = 'hl';
        else if (/\bsl\b/i.test(subj)) studentLevel = 'sl';
        break;
      }
    }
    const hasHL = /\bhl\b/.test(normEvent);
    const hasSL = /\bsl\b/.test(normEvent);
    if (hasHL || hasSL) {
      if (hasHL && studentLevel !== 'hl') return false;
      if (hasSL && studentLevel !== 'sl') return false;
    }
    return true;
  }
  return true;
}

// Subject selection handler
document.getElementById('subjectSelect').addEventListener('change', function () {
  const slots = document.getElementById('slotSelect');
  slots.innerHTML = '<option value="">Loading slots...</option>';

  setTimeout(() => {
    slots.innerHTML = '';
    const selectedOption = this.options[this.selectedIndex];
    const selectedId = selectedOption ? (selectedOption.dataset.id || normalizeSubjectId(selectedOption.value)) : '';

    const filtered = events.filter(e => matchesStudentLevel(e.summary, selectedId));

    if (filtered.length === 0) {
      slots.innerHTML = '<option value="">No available slots for this subject</option>';
      return;
    }

    filtered.sort((a, b) => new Date(a.date) - new Date(b.date));

    filtered.forEach(e => {
      const opt = document.createElement('option');
      opt.value = e.date;
      const dateObj = new Date(e.date);
      const dateStr = dateObj.toLocaleDateString('en-GB', {
        weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
      });
      opt.textContent = `${dateStr} - ${e.summary}`;
      slots.appendChild(opt);
    });
  }, 100);
});

// Form submission with loading state
document.getElementById('bookingForm').addEventListener('submit', function () {
  const submitBtn = document.getElementById('submitBtn');
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Booking...';
  
  // Re-enable after 5 seconds in case of error
  setTimeout(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm Booking';
  }, 5000);
});
</script>
</body>
</html>