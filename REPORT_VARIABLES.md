# Report System Variables Documentation

## Database Fields Available in All Templates

### From `report_pdf.php` main query:
```sql
SELECT subject, teacher_comment, booking_date, status, attendance, student_name, student_grade, student_email
FROM bookings
WHERE user_id=? AND DATE(booking_date) BETWEEN ? AND ?
ORDER BY booking_date ASC
```

### Variables Available in All Template Files:

#### Student Information (from users table):
- `$name` - Student full name
- `$grade` - Student grade
- `$email` - Student email
- `$student_id` - Student ID (from session)

#### Date Range:
- `$from` - Start date (YYYY-MM-DD format)
- `$to` - End date (YYYY-MM-DD format)

#### Booking Data:
- `$bookingsArr` - Array of all booking records with fields:
  - `subject` - Subject name
  - `teacher_comment` - Teacher's comment/feedback
  - `booking_date` - Date and time of booking
  - `status` - Booking status (booked, visited, canceled, not attended)
  - `attendance` - Attendance status
  - `student_name` - Student name (from booking record)
  - `student_grade` - Student grade (from booking record)
  - `student_email` - Student email (from booking record)

#### Statistics:
- `$stats` - Array with status counts:
  - `booked` - Number of booked lessons
  - `visited` - Number of attended lessons
  - `canceled` - Number of canceled lessons
  - `not attended` - Number of missed lessons
- `$totalLessons` - Total number of lessons (sum of all stats)

#### Assets:
- `$logoBase64` - Base64 encoded logo image
- `$signatureBase64` - Base64 encoded signature image
- `$qrBase64` - Base64 encoded QR code

#### Functions Available:
- `chart_pie_attendance($stats)` - Generates pie chart for attendance
- `chart_bar_subjects($bookingsArr)` - Generates bar chart for subjects

## Template Files Using These Variables:

1. **attendance.php** - Uses all variables correctly ✅
2. **monthly.php** - Uses all variables correctly ✅
3. **feedback.php** - Uses all variables correctly ✅
4. **subject.php** - Uses all variables correctly ✅

## Status Values:
- `booked` - Lesson is scheduled but not yet attended
- `visited` - Student attended the lesson
- `canceled` - Lesson was canceled
- `not attended` - Student missed the lesson

## Color Coding Used:
- Green (#28a745) - visited/attended
- Red (#dc3545) - canceled
- Orange (#ff9800) - not attended
- Blue (#3498db) - booked

All templates now use consistent variable names and database fields for accurate reporting.
