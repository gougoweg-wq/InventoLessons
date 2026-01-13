<?php
session_start();
include('db_connect.php');

// Check grade in session
$gradeRaw = $_SESSION["grade"] ?? null;
if (!$gradeRaw) {
    die("No grade in session. Please log in again.");
}
preg_match('/\d+/', $gradeRaw, $matches);
$grade_number = $matches[0] ?? null;
if (!$grade_number) {
    die("Invalid grade format.");
}

// Allowed subjects by grade
$subjectsByGrade = [
    "6" => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Science", "English A", "English B"],
    "7" => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Science", "English A", "English B"],
    "8" => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Science", "English A", "English B"],
    "9" => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Physics", "Chemistry", "Biology", "English A", "English B"],
    "10" => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "I&S", "Math", "Physics", "Chemistry", "Biology", "English A", "English B"],
    "11" => ["Uzbek A", "Uzbek B", "Russian A", "Russian B", "Business Management", "Math", "Physics", "Chemistry", "Biology", "English A", "English B"],
];
$allowedSubjects = $subjectsByGrade[$grade_number] ?? [];

// Default iCal URL (replace if you have a different feed)
$icalUrl = "https://calendar.google.com/calendar/ical/c_fa8beeecc764d2836e99bf057540e15f037c8a762be33c3a0a660a1f45862f90%40group.calendar.google.com/private-c61ee269e22d68e2978fcaba1b6868e5/basic.ics";

// Fetch with a short timeout and error handling
$icalData = false;
try {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "User-Agent: InventoBooking/1.0\r\n"
        ]
    ]);
    $icalData = @file_get_contents($icalUrl, false, $ctx);
} catch (Throwable $e) {
    $icalData = false;
}
if ($icalData === false || trim($icalData) === '') {
    // On failure, use empty events and let UI show "no slots"
    $events = [];
} else {
    $events = parseIcsEvents($icalData);
}

function parseIcsEvents(string $icalData): array {
    // Robust ICS parser for simple VEVENT extraction
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $icalData, $matches);
    $events = [];

    foreach ($matches[1] as $eventData) {
        // SUMMARY may be folded across lines per RFC5545; join folded lines first
        $eventData = preg_replace("/\r\n[ \t]/", "", $eventData);
        if (preg_match('/SUMMARY:(.*?)(?:\r\n|$)/i', $eventData, $s)) {
            $summary = trim($s[1]);
        } else {
            $summary = '';
        }

        // DTSTART: handle date-time with Z or without, or date-only
        if (preg_match('/DTSTART(?:;TZID=[^:]+)?:([0-9TZ]+)/', $eventData, $d)) {
            $dateStr = $d[1];
            $date = null;
            // Try full datetime with Z
            if (str_ends_with($dateStr, 'Z')) {
                $date = DateTime::createFromFormat('Ymd\THis\Z', $dateStr, new DateTimeZone('UTC'));
                if ($date) $date->setTimezone(new DateTimeZone('Asia/Tashkent'));
            } elseif (preg_match('/^\d{8}T\d{6}$/', $dateStr)) {
                $date = DateTime::createFromFormat('Ymd\THis', $dateStr);
            } elseif (preg_match('/^\d{8}$/', $dateStr)) {
                // All-day event (date-only)
                $date = DateTime::createFromFormat('Ymd', $dateStr);
                $date->setTime(9, 0); // default time
            }
            $start = $date ? $date->format('Y-m-d H:i') : '';
            // Normalize summary text for easier searching
            $summaryText = mb_strtolower(trim($summary), 'UTF-8');
            $summaryText = preg_replace('/[^a-z0-9 ]/', ' ', $summaryText);
            $summaryText = preg_replace('/\s+/', ' ', $summaryText);
            $events[] = ['summary' => $summaryText, 'start' => $start];
        }
    }
    return $events;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Book a Slot</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark px-3">
  <span class="navbar-brand">Booking Portal</span>
  <div>
    <a href="dashboard.php" class="btn btn-outline-light btn-sm">My Bookings</a>
    <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
  </div>
</nav>

<div class="container mt-4">
  <div class="card shadow-lg p-4">
    <h3 class="mb-3">Available Slots</h3>

    <form method="POST" action="save_booking.php">
      <div class="mb-3">
        <label>Choose Subject</label>
        <select id="subjectSelect" name="subject" class="form-select" required>
          <option value="">-- Select Subject --</option>
          <?php foreach ($allowedSubjects as $sub): ?>
            <option value="<?= htmlspecialchars($sub) ?>"><?= htmlspecialchars($sub) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label>Select Slot</label>
        <select id="slotSelect" name="slot" class="form-select" required>
          <option value="">-- Select subject first --</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary w-100">Book Slot</button>
    </form>
  </div>
</div>

<script>
const events = <?php echo json_encode($events, JSON_UNESCAPED_UNICODE); ?>;
const grade = <?php echo json_encode($grade_number); ?>;

function normalize(str) {
    return (str || '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

document.getElementById('subjectSelect').addEventListener('change', function() {
    const subjectRaw = this.value;
    const slotSelect = document.getElementById('slotSelect');
    slotSelect.innerHTML = "";

    if (!subjectRaw) {
        slotSelect.innerHTML = "<option value=''>-- Select subject first --</option>";
        return;
    }

    const subjectNorm = normalize(subjectRaw);
    const gradeStr = String(grade);

    const gradePatterns = [
        subjectNorm + gradeStr,
        subjectNorm + " " + gradeStr,
        gradeStr + subjectNorm,
        gradeStr + " " + subjectNorm,
        "grade" + gradeStr + subjectNorm,
        "grade" + gradeStr + " " + subjectNorm,
        subjectNorm
    ];

    const filtered = events.filter(e => {
        const summaryNorm = normalize(e.summary);
        return gradePatterns.some(p => summaryNorm.includes(p));
    });

    if (filtered.length === 0) {
        slotSelect.innerHTML = "<option value=''>No slots for this subject</option>";
        return;
    }

    filtered.forEach(e => {
        const opt = document.createElement("option");
        opt.value = e.start;
        opt.textContent = e.start + " - " + e.summary;
        slotSelect.appendChild(opt);
    });
});
</script>
</body>
</html>
