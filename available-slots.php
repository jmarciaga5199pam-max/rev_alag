<?php
require 'db_connect.php';
header('Content-Type: application/json');

// Check if parent logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'PARENT') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$doctor_id = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if (!$doctor_id || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

// Get doctor's availability for this date
$availability = get_doctor_availability_for_date($conn, $doctor_id, $date);

// Generate 30-minute slots
$slots = generate_available_slots($availability);

// Mark already-booked slots as unavailable
$booked = [];
$bq = mysqli_prepare($conn, "SELECT appointment_time FROM appointments
                              WHERE doctor_id = ? AND appointment_date = ?
                                AND status IN ('SCHEDULED','CONFIRMED','IN_PROGRESS')");
if ($bq) {
    mysqli_stmt_bind_param($bq, "is", $doctor_id, $date);
    mysqli_stmt_execute($bq);
    $br = mysqli_stmt_get_result($bq);
    while ($r = mysqli_fetch_assoc($br)) {
        $t = substr($r['appointment_time'], 0, 5); // HH:MM
        $booked[$t] = true;
    }
}
foreach ($slots as &$slot) {
    $key = substr($slot['time'], 0, 5);
    if (isset($booked[$key])) {
        $slot['available'] = false;
    }
}
unset($slot);

echo json_encode([
    'success' => true,
    'data' => $slots
]);

function get_doctor_availability_for_date($conn, $doctor_id, $date) {
    $doctor_id = intval($doctor_id);

    // 1) Specific date overrides in doctor_availability take precedence.
    $query = "SELECT start_time, end_time, availability_type FROM doctor_availability
              WHERE doctor_id = ? AND specific_date = ? AND active = 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $doctor_id, $date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        return $row;
    }

    $day_name = strtoupper(date('l', strtotime($date)));

    // 2) Doctor's Weekly Working Schedule (doctor_schedules) is the canonical
    //    source for recurring availability — set from My Schedule on the
    //    doctor dashboard. Reflect this in parent booking.
    $query = "SELECT start_time, end_time FROM doctor_schedules
              WHERE doctor_id = ? AND day_of_week = ? AND active = 1
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $doctor_id, $day_name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $row['availability_type'] = 'RECURRING';
        return $row;
    }

    // 3) Fallback to legacy recurring entries in doctor_availability.
    $query = "SELECT start_time, end_time, availability_type FROM doctor_availability
              WHERE doctor_id = ? AND day_of_week = ? AND availability_type = 'RECURRING' AND active = 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $doctor_id, $day_name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        return $row;
    }

    // 4) No schedule defined for this day — treat as unavailable so the parent
    //    sees an empty slot list rather than a default 9-5 window the doctor
    //    never agreed to.
    return ['start_time' => '00:00:00', 'end_time' => '00:00:00', 'availability_type' => 'UNAVAILABLE'];
}

function generate_available_slots($availability) {
    $start = new DateTime($availability['start_time']);
    $end = new DateTime($availability['end_time']);
    $slots = [];
    
    if ($availability['availability_type'] === 'UNAVAILABLE') {
        return $slots; // No slots
    }
    
    for ($current = clone $start; $current < $end; $current->modify('+30 minutes')) {
        $time24 = $current->format('H:i:s');
        $timeFormatted = $current->format('g:i A');
        
        $slots[] = [
            'time' => $time24,
            'formatted' => $timeFormatted,
            'available' => true
        ];
    }
    
    return $slots;
}
?>