<?php
session_start();
include('db_connect.php');

// Check grade
$grade = $_SESSION["grade"] ?? null;
if (!$grade) {
    die("No grade in session. Please log in again.");
}
preg_match('/\d+/', $grade, $matches);
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

// Load iCal feed and parse
$icalUrl = "https://calendar.google.com/calendar/ical/c_fa8beeecc764d2836e99bf057540e15f037c8a762be33c3a0a660a1f45862f90%40group.calendar.google.com/private-c61ee269e22d68e2978fcaba1b6868e5/basic.ics";
$icalData = file_get_contents($icalUrl);

function parseIcsEvents($icalData) {
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $icalData, $matches);
    $events = [];

    foreach ($matches[1] as $eventData) {
        preg_match('/SUMMARY:(.*)/', $eventData, $summary);
        preg_match('/DTSTART(?:;TZID=.*)?:([\dTZ]+)/', $eventData, $start);

        if (!empty($start[1])) {
            $dateStr = $start[1];
            if (str_ends_with($dateStr, 'Z')) {
                $date = DateTime::createFromFormat('Ymd\THis\Z', $dateStr, new DateTimeZone('UTC'));
                $date->setTimezone(new DateTimeZone('Asia/Tashkent'));
            } else {
                $date = DateTime::createFromFormat('Ymd\THis', $dateStr);
            }

            // Normalize summary
            $summaryText = strtolower(trim($summary[1] ?? ''));
            $summaryText = preg_replace('/[^a-z0-9 ]/', '', $summaryText);

            $events[] = [
                'summary' => $summaryText,
                'start'   => $date ? $date->format('Y-m-d H:i') : ''
            ];
        }
    }
    return $events;
}

$events = parseIcsEvents($icalData);
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
// Events from PHP
const events = <?php echo json_encode($events); ?>;
const grade = <?php echo json_encode($grade_number); ?>;

function normalize(str) {
    return str.toLowerCase().replace(/[^a-z0-9]/g, '');
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
    const gradeStr = grade.toString();

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
