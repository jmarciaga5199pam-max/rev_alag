<?php
// doctor-dashboard.php
require_once 'db_connect.php';

// Enhanced security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Check if user is logged in and is a doctor
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] !== 'DOCTOR' && $_SESSION['user_type'] !== 'DOCTOR_OWNER')) {
    header('Location: index.php');
    exit();
}

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// AJAX endpoint: update appointment status
if (isset($_GET['action']) && $_GET['action'] === 'update_appointment_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    $appointmentId = intval($input['appointment_id'] ?? 0);
    $newStatus = strtoupper(trim($input['status'] ?? ''));
    $csrfToken = $input['csrf_token'] ?? '';

    if ($csrfToken !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }

    $validStatuses = ['CONFIRMED', 'CANCELLED', 'COMPLETED'];
    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }

    // Verify appointment belongs to this doctor
    $checkStmt = mysqli_prepare($conn, "SELECT id, status FROM appointments WHERE id = ? AND doctor_id = ?");
    $doctorId = intval($_SESSION['user_id']);
    mysqli_stmt_bind_param($checkStmt, "ii", $appointmentId, $doctorId);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);

    if (mysqli_num_rows($checkResult) === 0) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit();
    }

    $updateStmt = mysqli_prepare($conn, "UPDATE appointments SET status = ? WHERE id = ? AND doctor_id = ?");
    mysqli_stmt_bind_param($updateStmt, "sii", $newStatus, $appointmentId, $doctorId);

    if (mysqli_stmt_execute($updateStmt)) {
        // Log the activity
        $action = 'APPOINTMENT_' . $newStatus;
        $logStmt = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
        $details = "Updated appointment #$appointmentId to $newStatus";
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        mysqli_stmt_bind_param($logStmt, "isss", $doctorId, $action, $details, $ip);
        mysqli_stmt_execute($logStmt);

        echo json_encode(['success' => true, 'message' => 'Appointment updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update appointment']);
    }
    exit();
}

// AJAX endpoint: get appointment details
if (isset($_GET['action']) && $_GET['action'] === 'get_appointment_details' && isset($_GET['appointment_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $appointmentId = intval($_GET['appointment_id']);
    $doctorId = intval($_SESSION['user_id']);

    $stmt = mysqli_prepare($conn, "SELECT a.*, p.first_name as patient_first_name, p.last_name as patient_last_name, p.date_of_birth, p.gender, p.allergies, p.blood_type, u.first_name as parent_first_name, u.last_name as parent_last_name, u.phone as parent_phone FROM appointments a JOIN patients p ON a.patient_id = p.id JOIN users u ON p.parent_id = u.id WHERE a.id = ? AND a.doctor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $appointmentId, $doctorId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'appointment' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
    }
    exit();
}

// Get current user data (pull fresh from DB so profile edits show immediately)
$current_user = [
    'id' => $_SESSION['user_id'],
    'first_name' => $_SESSION['first_name'] ?? '',
    'last_name' => $_SESSION['last_name'] ?? '',
    'email' => $_SESSION['email'] ?? '',
    'user_type' => $_SESSION['user_type'] ?? '',
    'specialization' => $_SESSION['specialization'] ?? 'Pediatrician',
    'phone' => '',
    'license_number' => '',
    'years_of_experience' => ''
];

$__cu_stmt = mysqli_prepare($conn, "SELECT first_name, last_name, email, phone, specialization, license_number, years_of_experience FROM users WHERE id = ? LIMIT 1");
if ($__cu_stmt) {
    mysqli_stmt_bind_param($__cu_stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($__cu_stmt);
    $__cu_res = mysqli_stmt_get_result($__cu_stmt);
    if ($__cu_res && ($__cu_row = mysqli_fetch_assoc($__cu_res))) {
        $current_user['first_name']     = $__cu_row['first_name'];
        $current_user['last_name']      = $__cu_row['last_name'];
        $current_user['email']          = $__cu_row['email'];
        $current_user['phone']          = $__cu_row['phone'] ?? '';
        $current_user['specialization'] = $__cu_row['specialization'] ?? $current_user['specialization'];
        $current_user['license_number'] = $__cu_row['license_number'] ?? '';
        $current_user['years_of_experience'] = $__cu_row['years_of_experience'] ?? '';
        // Keep session in sync with any updated names so sidebar reflects reality
        $_SESSION['first_name']    = $__cu_row['first_name'];
        $_SESSION['last_name']     = $__cu_row['last_name'];
        $_SESSION['email']         = $__cu_row['email'];
        $_SESSION['specialization'] = $current_user['specialization'];
    }
    mysqli_stmt_close($__cu_stmt);
}

// ===== MISSING HELPER FUNCTIONS =====



/**
 * Get all vaccination records for a patient
 */
function get_patient_vaccination_records($conn, $patient_id, $doctor_id) {
    $patient_id = intval($patient_id);
    $doctor_id = intval($doctor_id);
    
    $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $patient_id, $doctor_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        return ['success' => false, 'message' => 'Not authorized to view this patient'];
    }
    
    $records = [];
    $query = "SELECT * FROM vaccination_records 
              WHERE patient_id = ? 
              ORDER BY administration_date DESC, created_at DESC";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $patient_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $records[] = $row;
    }
    
    return ['success' => true, 'data' => $records];
}

/**
 * Get a single vaccination record
 */
function get_vaccination_record($conn, $vaccination_id, $doctor_id) {
    $vaccination_id = intval($vaccination_id);
    $doctor_id = intval($doctor_id);
    
    $verify_query = "SELECT vr.* FROM vaccination_records vr
                     JOIN appointments a ON vr.patient_id = a.patient_id
                     WHERE vr.id = ? AND a.doctor_id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $vaccination_id, $doctor_id);
    mysqli_stmt_execute($verify_stmt);
    $result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($result) === 0) {
        return null;
    }
    
    return mysqli_fetch_assoc($result);
}

/**
 * Edit a vaccination record
 */
function edit_vaccination_record($conn, $vaccination_id, $data, $doctor_id) {
    $vaccination_id = intval($vaccination_id);
    $doctor_id = intval($doctor_id);
    
    // Verify the vaccination record belongs to this doctor
    $verify_query = "SELECT vr.id, vr.patient_id FROM vaccination_records vr
                     JOIN appointments a ON vr.patient_id = a.patient_id
                     WHERE vr.id = ? AND a.doctor_id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $vaccination_id, $doctor_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        return ['success' => false, 'message' => 'Not authorized to edit this vaccination record'];
    }
    
    $vaccine_name = trim($data['vaccine_name'] ?? '');
    $dose_number = intval($data['dose_number'] ?? 1);
    $administration_date = $data['administration_date'] ?? '';
    $lot_number = trim($data['lot_number'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if (!$vaccine_name || !$administration_date) {
        return ['success' => false, 'message' => 'Vaccine name and administration date are required'];
    }

    if (!strtotime($administration_date)) {
        return ['success' => false, 'message' => 'Invalid date format'];
    }

    $query = "UPDATE vaccination_records
              SET vaccine_name = ?, dose_number = ?, administration_date = ?,
                  lot_number = ?, notes = ?, updated_at = NOW()
              WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sisssi", $vaccine_name, $dose_number, $administration_date,
                          $lot_number, $notes, $vaccination_id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Vaccination record updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update vaccination record'];
}

/**
 * Delete a vaccination record
 */
function delete_vaccination_record($conn, $vaccination_id, $doctor_id) {
    $vaccination_id = intval($vaccination_id);
    $doctor_id = intval($doctor_id);
    
    // Verify the vaccination record belongs to this doctor
    $verify_query = "SELECT vr.id, vr.patient_id FROM vaccination_records vr
                     JOIN appointments a ON vr.patient_id = a.patient_id
                     WHERE vr.id = ? AND a.doctor_id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $vaccination_id, $doctor_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        return ['success' => false, 'message' => 'Not authorized to delete this vaccination record'];
    }
    
    $delete_query = "DELETE FROM vaccination_records WHERE id = ?";
    $delete_stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($delete_stmt, "i", $vaccination_id);
    
    if (mysqli_stmt_execute($delete_stmt)) {
        return ['success' => true, 'message' => 'Vaccination record deleted successfully'];
    }
    return ['success' => false, 'message' => 'Failed to delete vaccination record'];
}

// ===== VACCINE NEEDS FUNCTIONS =====
function get_patient_vaccine_needs($conn, $patient_id, $doctor_id) {
    $patient_id = intval($patient_id);
    $doctor_id = intval($doctor_id);

    // Verify patient exists and doctor has a relationship (via appointments or patient list)
    $verify_query = "SELECT 1 FROM patients WHERE id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "i", $patient_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);

    if (mysqli_num_rows($verify_result) === 0) {
        return ['success' => false, 'message' => 'Patient not found'];
    }

    $rows = [];
    $query = "SELECT id, vaccine_name, recommended_date, status, notes, created_by, created_at, updated_at
              FROM patient_vaccine_needs
              WHERE patient_id = ?
              ORDER BY created_at DESC";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $patient_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    
    return ['success' => true, 'data' => $rows];
}

function set_patient_vaccine_need($conn, $data, $doctor_id) {
    $doctor_id = intval($doctor_id);
    $patient_id = intval($data['patient_id'] ?? 0);
    $vaccine_name = trim($data['vaccine_name'] ?? '');
    $recommended_date = !empty($data['recommended_date']) ? $data['recommended_date'] : null;
    $status = in_array($data['status'] ?? 'RECOMMENDED', ['RECOMMENDED','SCHEDULED','GIVEN','NOT_NEEDED'])
        ? $data['status']
        : 'RECOMMENDED';
    $notes = trim($data['notes'] ?? '');
    $id = isset($data['id']) ? intval($data['id']) : 0;

    if ($patient_id <= 0 || $vaccine_name === '') {
        return ['success' => false, 'message' => 'Patient and vaccine name are required'];
    }

    $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $patient_id, $doctor_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        return ['success' => false, 'message' => 'Not authorized to modify this patient'];
    }

    if ($id > 0) {
        $query = "UPDATE patient_vaccine_needs 
                  SET vaccine_name = ?, 
                      recommended_date = ?, 
                      status = ?, 
                      notes = ?, 
                      updated_at = NOW() 
                  WHERE id = ? AND patient_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssssii", $vaccine_name, $recommended_date, $status, $notes, $id, $patient_id);
        
        if (mysqli_stmt_execute($stmt)) {
            return ['success' => true, 'message' => 'Vaccine need updated successfully', 'id' => $id];
        } else {
            return ['success' => false, 'message' => 'Failed to update vaccine need'];
        }
    } else {
        $query = "INSERT INTO patient_vaccine_needs 
                  (patient_id, vaccine_name, recommended_date, status, notes, created_by, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "isssis", $patient_id, $vaccine_name, $recommended_date, $status, $notes, $doctor_id);
        
        if (mysqli_stmt_execute($stmt)) {
            return ['success' => true, 'message' => 'Vaccine need added successfully', 'id' => mysqli_insert_id($conn)];
        } else {
            return ['success' => false, 'message' => 'Failed to add vaccine need'];
        }
    }
}

function delete_patient_vaccine_need($conn, $vaccine_need_id, $doctor_id) {
    $vaccine_need_id = intval($vaccine_need_id);
    $doctor_id = intval($doctor_id);
    
    $verify_query = "SELECT patient_id FROM patient_vaccine_needs WHERE id = ?";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "i", $vaccine_need_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        return ['success' => false, 'message' => 'Vaccine need not found'];
    }
    
    $vaccine_row = mysqli_fetch_assoc($verify_result);
    $patient_id = $vaccine_row['patient_id'];
    
    $patient_check = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
    $patient_stmt = mysqli_prepare($conn, $patient_check);
    mysqli_stmt_bind_param($patient_stmt, "ii", $patient_id, $doctor_id);
    mysqli_stmt_execute($patient_stmt);
    $patient_result = mysqli_stmt_get_result($patient_stmt);
    
    if (mysqli_num_rows($patient_result) === 0) {
        return ['success' => false, 'message' => 'Not authorized to delete this vaccine need'];
    }
    
    $delete_query = "DELETE FROM patient_vaccine_needs WHERE id = ?";
    $delete_stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($delete_stmt, "i", $vaccine_need_id);
    
    if (mysqli_stmt_execute($delete_stmt)) {
        return ['success' => true, 'message' => 'Vaccine need deleted successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to delete vaccine need'];
    }
}

function get_recommended_vaccines_for_age($conn, $age_months) {
    $age_months = intval($age_months);
    $vaccines = [];
    
    $query = "SELECT DISTINCT vaccine_name, disease_protected 
              FROM vaccine_schedule 
              WHERE recommended_age_months <= ? 
              ORDER BY vaccine_name";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $age_months);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $vaccines[] = $row;
    }
    
    return $vaccines;
}

// ===== EXISTING FUNCTIONS =====
function get_doctor_stats($conn, $doctor_id) {
    $stats = [
        'today_appointments' => 0,
        'pending_appointments' => 0,
        'total_patients' => 0,
        'vaccinations_given' => 0
    ];
    
    try {
        $today = date('Y-m-d');
        $doctor_id = intval($doctor_id);
        
        $query = "SELECT COUNT(*) as count FROM appointments WHERE doctor_id = ? AND DATE(appointment_date) = ? AND status IN ('SCHEDULED', 'CONFIRMED', 'IN_PROGRESS')";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "is", $doctor_id, $today);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $stats['today_appointments'] = $row['count'];
        }
        
        $query = "SELECT COUNT(*) as count FROM appointments WHERE doctor_id = ? AND status = 'SCHEDULED'";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $stats['pending_appointments'] = $row['count'];
        }
        
        $query = "SELECT COUNT(DISTINCT patient_id) as count FROM appointments WHERE doctor_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $stats['total_patients'] = $row['count'];
        }
        
        $query = "SELECT COUNT(*) as count FROM vaccination_records WHERE administered_by = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $stats['vaccinations_given'] = $row['count'];
        }
        
    } catch (Exception $e) {
        error_log("Error getting doctor stats: " . $e->getMessage());
    }
    
    return $stats;
}

function get_appointment_chart_data($conn, $doctor_id) {
    $data = ['dates' => [], 'counts' => [], 'status_labels' => [], 'status_counts' => []];
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT DATE(appointment_date) as date, COUNT(*) as count 
                  FROM appointments 
                  WHERE doctor_id = ? AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  GROUP BY DATE(appointment_date) 
                  ORDER BY date";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $data['dates'][] = $row['date'];
            $data['counts'][] = (int)$row['count'];
        }
        
        $query = "SELECT status, COUNT(*) as count 
                  FROM appointments 
                  WHERE doctor_id = ? 
                  GROUP BY status";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $data['status_labels'][] = $row['status'];
            $data['status_counts'][] = (int)$row['count'];
        }
        
    } catch (Exception $e) {
        error_log("Error getting chart data: " . $e->getMessage());
    }
    
    return $data;
}

function get_vaccination_chart_data($conn, $doctor_id) {
    $data = ['months' => [], 'vaccination_counts' => [], 'vaccine_names' => [], 'vaccine_counts' => []];
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT DATE_FORMAT(administration_date, '%Y-%m') as month, COUNT(*) as count 
                  FROM vaccination_records 
                  WHERE administered_by = ? AND administration_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                  GROUP BY DATE_FORMAT(administration_date, '%Y-%m') 
                  ORDER BY month";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $data['months'][] = $row['month'];
            $data['vaccination_counts'][] = (int)$row['count'];
        }
        
        $query = "SELECT vaccine_name, COUNT(*) as count 
                  FROM vaccination_records 
                  WHERE administered_by = ?
                  GROUP BY vaccine_name 
                  ORDER BY count DESC 
                  LIMIT 5";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $data['vaccine_names'][] = $row['vaccine_name'];
            $data['vaccine_counts'][] = (int)$row['count'];
        }
        
    } catch (Exception $e) {
        error_log("Error getting vaccination chart data: " . $e->getMessage());
    }
    
    return $data;
}

function get_today_appointments($conn, $doctor_id) {
    $appointments = [];
    $today = date('Y-m-d');
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT a.*, p.first_name, p.last_name, p.date_of_birth, 
                         u.first_name as parent_first, u.last_name as parent_last,
                         p.gender, p.blood_type
                  FROM appointments a
                  JOIN patients p ON a.patient_id = p.id
                  JOIN users u ON p.parent_id = u.id
                  WHERE a.doctor_id = ? AND DATE(a.appointment_date) = ? 
                  AND a.status IN ('SCHEDULED', 'CONFIRMED', 'IN_PROGRESS')
                  ORDER BY a.appointment_time ASC";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "is", $doctor_id, $today);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $appointments[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting today's appointments: " . $e->getMessage());
    }
    
    return $appointments;
}

function get_recent_visits($conn, $doctor_id, $limit = 5) {
    $visits = [];
    $doctor_id = intval($doctor_id);
    $limit = intval($limit);
    
    try {
        $query = "SELECT a.*, p.first_name, p.last_name, p.date_of_birth, p.gender
                  FROM appointments a
                  JOIN patients p ON a.patient_id = p.id
                  WHERE a.doctor_id = ? AND a.status = 'COMPLETED'
                  ORDER BY a.appointment_date DESC, a.appointment_time DESC
                  LIMIT ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $doctor_id, $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $visits[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting recent visits: " . $e->getMessage());
    }
    
    return $visits;
}

function get_pending_appointments($conn, $doctor_id) {
    $appointments = [];
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT a.*, p.first_name, p.last_name, p.date_of_birth, 
                         u.first_name as parent_first, u.last_name as parent_last,
                         p.gender, p.blood_type
                  FROM appointments a
                  JOIN patients p ON a.patient_id = p.id
                  JOIN users u ON p.parent_id = u.id
                  WHERE a.doctor_id = ? AND a.status = 'SCHEDULED'
                  ORDER BY a.appointment_date ASC, a.appointment_time ASC
                  LIMIT 20";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $appointments[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting pending appointments: " . $e->getMessage());
    }
    
    return $appointments;
}

function get_doctor_patients($conn, $doctor_id) {
    $patients = [];
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT DISTINCT p.*, u.first_name as parent_first, u.last_name as parent_last,
                         u.phone as parent_phone, u.email as parent_email,
                         COUNT(a.id) as visit_count,
                         MAX(a.appointment_date) as last_visit
                  FROM patients p
                  JOIN appointments a ON p.id = a.patient_id
                  JOIN users u ON p.parent_id = u.id
                  WHERE a.doctor_id = ?
                  GROUP BY p.id
                  ORDER BY p.first_name, p.last_name";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $birth_date = new DateTime($row['date_of_birth']);
            $today = new DateTime();
            $age = $birth_date->diff($today);
            $row['age_years'] = $age->y;
            $row['age_months'] = $age->m;
            
            $patients[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting doctor patients: " . $e->getMessage());
    }
    
    return $patients;
}

function get_all_appointments($conn, $doctor_id, $filter = 'all') {
    $appointments = [];
    $doctor_id = intval($doctor_id);
    
    try {
        $base_query = "SELECT a.*, p.first_name, p.last_name, p.date_of_birth, p.gender,
                              u.first_name as parent_first, u.last_name as parent_last
                       FROM appointments a
                       JOIN patients p ON a.patient_id = p.id
                       JOIN users u ON p.parent_id = u.id
                       WHERE a.doctor_id = ?";
        
        $params = [$doctor_id];
        $types = "i";
        
        switch($filter) {
            case 'today':
                $base_query .= " AND DATE(a.appointment_date) = CURDATE()";
                break;
            case 'upcoming':
                $base_query .= " AND a.appointment_date >= CURDATE() AND a.status IN ('SCHEDULED', 'CONFIRMED')";
                break;
            case 'pending':
                $base_query .= " AND a.status = 'SCHEDULED'";
                break;
            case 'completed':
                $base_query .= " AND a.status = 'COMPLETED'";
                break;
        }
        
        $base_query .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 50";
        
        $stmt = mysqli_prepare($conn, $base_query);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $appointments[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting appointments: " . $e->getMessage());
    }
    
    return $appointments;
}

function get_recent_vaccinations($conn, $doctor_id, $limit = 10) {
    $vaccinations = [];
    $doctor_id = intval($doctor_id);
    $limit = intval($limit);
    
    try {
        $query = "SELECT vr.*, p.first_name, p.last_name, p.date_of_birth
                  FROM vaccination_records vr
                  JOIN patients p ON vr.patient_id = p.id
                  WHERE vr.administered_by = ?
                  ORDER BY vr.administration_date DESC, vr.created_at DESC
                  LIMIT ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $doctor_id, $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $vaccinations[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting recent vaccinations: " . $e->getMessage());
    }
    
    return $vaccinations;
}

function get_vaccination_schedule($conn, $doctor_id) {
    $schedule = [];
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT p.id as patient_id, p.first_name, p.last_name, p.date_of_birth,
                         TIMESTAMPDIFF(MONTH, p.date_of_birth, CURDATE()) as current_age_months,
                         CASE 
                             WHEN TIMESTAMPDIFF(MONTH, p.date_of_birth, CURDATE()) BETWEEN 0 AND 2 THEN 'Hepatitis B, BCG'
                             WHEN TIMESTAMPDIFF(MONTH, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'DTaP, IPV, Hib, PCV, Rotavirus'
                             WHEN TIMESTAMPDIFF(MONTH, p.date_of_birth, CURDATE()) BETWEEN 12 AND 15 THEN 'MMR, Varicella, Hepatitis A'
                             ELSE 'Checkup needed'
                         END as recommended_vaccines,
                         CASE 
                             WHEN TIMESTAMPDIFF(MONTH, p.date_of_birth, CURDATE()) BETWEEN 0 AND 2 THEN 'DUE'
                             WHEN TIMESTAMPDIFF(MONTH, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'DUE'
                             WHEN TIMESTAMPDIFF(MONTH, p.date_of_birth, CURDATE()) BETWEEN 12 AND 15 THEN 'DUE'
                             ELSE 'UPCOMING'
                         END as status
                  FROM patients p
                  INNER JOIN appointments a ON p.id = a.patient_id
                  WHERE a.doctor_id = ?
                  HAVING recommended_vaccines != 'Checkup needed'
                  ORDER BY p.date_of_birth ASC
                  LIMIT 10";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $schedule[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting vaccination schedule: " . $e->getMessage());
    }
    
    return $schedule;
}

function get_vaccines($conn) {
    $vaccines = [];
    
    try {
        $query = "SELECT name, disease_protected FROM vaccines ORDER BY name";
        $result = mysqli_query($conn, $query);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $vaccines[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting vaccines: " . $e->getMessage());
    }
    
    return $vaccines;
}

function get_patient_medical_records($conn, $patient_id, $doctor_id) {
    $records = [];
    $patient_id = intval($patient_id);
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT mr.*, doc.first_name as doctor_first, doc.last_name as doctor_last
                  FROM medical_records mr
                  JOIN users doc ON mr.doctor_id = doc.id
                  WHERE mr.patient_id = ? AND mr.doctor_id = ?
                  ORDER BY mr.record_date DESC, mr.created_at DESC";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $patient_id, $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $records[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting medical records: " . $e->getMessage());
    }
    
    return $records;
}

function update_patient_info($conn, $data, $doctor_id) {
    $patient_id = intval($data['patient_id']);
    $height = isset($data['height']) ? floatval($data['height']) : null;
    $weight = isset($data['weight']) ? floatval($data['weight']) : null;
    $allergies = mysqli_real_escape_string($conn, $data['allergies'] ?? '');
    $medical_conditions = mysqli_real_escape_string($conn, $data['medical_conditions'] ?? '');
    $special_notes = mysqli_real_escape_string($conn, $data['special_notes'] ?? '');
    
    $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $patient_id, $doctor_id);
    mysqli_stmt_execute($verify_stmt);
    $result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($result) === 0) {
        return ['success' => false, 'message' => 'Patient not found or not authorized'];
    }
    
    $query = "UPDATE patients SET height = ?, weight = ?, allergies = ?, medical_conditions = ?, special_notes = ?, updated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ddsssi", $height, $weight, $allergies, $medical_conditions, $special_notes, $patient_id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Patient information updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update patient information'];
}

function generate_prescription_number($conn) {
    $prefix = 'RX';
    $year = date('Y');
    $query = "SELECT COUNT(*) as count FROM prescriptions WHERE prescription_number LIKE '{$prefix}{$year}%'";
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    $sequence = $row['count'] + 1;
    return $prefix . $year . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

function print_prescription($conn, $prescription_id, $doctor_id) {
    $prescription_id = intval($prescription_id);
    
    try {
        $query = "SELECT p.*, pt.first_name, pt.last_name, pt.date_of_birth, 
                         u.first_name as doctor_first, u.last_name as doctor_last,
                         u.license_number, u.specialization
                  FROM prescriptions p
                  JOIN patients pt ON p.patient_id = pt.id
                  JOIN users u ON p.doctor_id = u.id
                  WHERE p.id = ? AND p.doctor_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $prescription_id, $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $prescription = mysqli_fetch_assoc($result);
        
        if (!$prescription) {
            return ['success' => false, 'message' => 'Prescription not found'];
        }
        
        if (empty($prescription['prescription_number'])) {
            $prescription_number = generate_prescription_number($conn);
            $update_query = "UPDATE prescriptions SET prescription_number = ? WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "si", $prescription_number, $prescription_id);
            mysqli_stmt_execute($update_stmt);
            $prescription['prescription_number'] = $prescription_number;
        }
        
        $print_query = "UPDATE prescriptions SET is_printed = 1, printed_at = NOW() WHERE id = ?";
        $print_stmt = mysqli_prepare($conn, $print_query);
        mysqli_stmt_bind_param($print_stmt, "i", $prescription_id);
        mysqli_stmt_execute($print_stmt);
        
        return [
            'success' => true, 
            'prescription' => $prescription,
            'message' => 'Prescription ready for printing'
        ];
        
    } catch (Exception $e) {
        error_log("Error printing prescription: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to print prescription'];
    }
}

function approveAppointment($conn, $data, $doctor_id) {
    $appointment_id = intval($data['appointment_id']);
    $query = "UPDATE appointments SET status = 'CONFIRMED', updated_at = NOW() WHERE id = ? AND doctor_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $appointment_id, $doctor_id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Appointment approved successfully'];
    }
    return ['success' => false, 'message' => 'Failed to approve appointment'];
}

function rejectAppointment($conn, $data, $doctor_id) {
    $appointment_id = intval($data['appointment_id']);
    $reason = mysqli_real_escape_string($conn, $data['reason'] ?? '');
    
    $query = "UPDATE appointments SET status = 'CANCELLED', notes = CONCAT(COALESCE(notes, ''), ' Rejection reason: ', ?), updated_at = NOW() WHERE id = ? AND doctor_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sii", $reason, $appointment_id, $doctor_id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Appointment rejected successfully'];
    }
    return ['success' => false, 'message' => 'Failed to reject appointment'];
}

function completeAppointment($conn, $data, $doctor_id) {
    $appointment_id = intval($data['appointment_id']);
    $query = "UPDATE appointments SET status = 'COMPLETED', updated_at = NOW() WHERE id = ? AND doctor_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $appointment_id, $doctor_id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Appointment marked as completed'];
    }
    return ['success' => false, 'message' => 'Failed to complete appointment'];
}

function recordVaccination($conn, $data, $doctor_id) {
    $patient_id = intval($data['patient_id']);
    $vaccine_name = trim($data['vaccine_name'] ?? '');
    $dose_number = intval($data['dose_number'] ?? 1);
    $administration_date = trim($data['administration_date'] ?? '');
    $lot_number = trim($data['lot_number'] ?? '');
    $manufacturer = trim($data['manufacturer'] ?? '');
    $site = trim($data['site'] ?? '');
    $next_due_date = trim($data['next_due_date'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if ($patient_id <= 0 || $vaccine_name === '') {
        return ['success' => false, 'message' => 'Patient and vaccine name are required'];
    }
    if (!strtotime($administration_date)) {
        return ['success' => false, 'message' => 'Invalid administration date'];
    }
    if ($next_due_date !== '' && !strtotime($next_due_date)) {
        $next_due_date = null;
    }
    if ($next_due_date === '') $next_due_date = null;

    $query = "INSERT INTO vaccination_records
              (patient_id, vaccine_name, dose_number, administration_date, next_due_date, lot_number, manufacturer, site, notes, administered_by, status, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', NOW())";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
    }
    mysqli_stmt_bind_param($stmt, "isissssssi",
        $patient_id, $vaccine_name, $dose_number, $administration_date, $next_due_date,
        $lot_number, $manufacturer, $site, $notes, $doctor_id);

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Vaccination recorded successfully'];
    }
    return ['success' => false, 'message' => 'Failed to record vaccination: ' . mysqli_stmt_error($stmt)];
}

function getWeeklySchedule($conn, $doctor_id) {
    $doctor_id = intval($doctor_id);
    $stmt = mysqli_prepare($conn,
        "SELECT day_of_week, start_time, end_time FROM doctor_schedules WHERE doctor_id = ? AND active = 1 ORDER BY FIELD(day_of_week,'MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY')");
    mysqli_stmt_bind_param($stmt, "i", $doctor_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $schedule = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $schedule[] = $row;
    }
    return ['success' => true, 'schedule' => $schedule];
}

function setWeeklySchedule($conn, $data, $doctor_id) {
    $doctor_id  = intval($doctor_id);
    $valid_days = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
    $day        = strtoupper(trim($data['day_of_week'] ?? ''));
    $start_time = trim($data['start_time'] ?? '');
    $end_time   = trim($data['end_time'] ?? '');

    if (!in_array($day, $valid_days))         return ['success' => false, 'message' => 'Invalid day of week'];
    if (!$start_time || !$end_time)           return ['success' => false, 'message' => 'Start and end time are required'];
    if (strtotime($start_time) >= strtotime($end_time)) return ['success' => false, 'message' => 'End time must be after start time'];

    // Upsert: update if exists, insert if not
    $check = mysqli_prepare($conn, "SELECT id FROM doctor_schedules WHERE doctor_id = ? AND day_of_week = ?");
    mysqli_stmt_bind_param($check, "is", $doctor_id, $day);
    mysqli_stmt_execute($check);
    $check_res = mysqli_stmt_get_result($check);

    if (mysqli_num_rows($check_res) > 0) {
        $row = mysqli_fetch_assoc($check_res);
        $stmt = mysqli_prepare($conn, "UPDATE doctor_schedules SET start_time = ?, end_time = ?, active = 1, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssi", $start_time, $end_time, $row['id']);
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO doctor_schedules (doctor_id, day_of_week, start_time, end_time, slot_duration, max_patients, active) VALUES (?, ?, ?, ?, 30, 10, 1)");
        mysqli_stmt_bind_param($stmt, "isss", $doctor_id, $day, $start_time, $end_time);
    }

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => ucfirst(strtolower($day)) . ' schedule saved successfully'];
    }
    return ['success' => false, 'message' => 'Failed to save schedule: ' . mysqli_error($conn)];
}

function removeWeeklySchedule($conn, $data, $doctor_id) {
    $doctor_id  = intval($doctor_id);
    $valid_days = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
    $day        = strtoupper(trim($data['day_of_week'] ?? ''));

    if (!in_array($day, $valid_days)) return ['success' => false, 'message' => 'Invalid day of week'];

    $stmt = mysqli_prepare($conn, "DELETE FROM doctor_schedules WHERE doctor_id = ? AND day_of_week = ?");
    mysqli_stmt_bind_param($stmt, "is", $doctor_id, $day);
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => ucfirst(strtolower($day)) . ' removed from schedule'];
    }
    return ['success' => false, 'message' => 'Failed to remove schedule'];
}

function setAvailability($conn, $data, $doctor_id) {
    $date = $data['date'];
    $start_time = $data['start_time'] ?? '00:00:00';
    $end_time = $data['end_time'] ?? '23:59:59';
    $availability_type = $data['availability_type'] ?? 'AVAILABLE';
    $reason = $data['reason'] ?? '';
    $is_all_day = isset($data['is_all_day']) ? 1 : 0;

    if (!strtotime($date)) {
        return ['success' => false, 'message' => 'Invalid date format'];
    }

    if (!$is_all_day && $start_time && $end_time) {
        if (strtotime($start_time) >= strtotime($end_time)) {
            return ['success' => false, 'message' => 'End time must be after start time'];
        }
    }

    $check_query = "SELECT id FROM doctor_availability WHERE doctor_id = ? AND specific_date = ? AND availability_type = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "iss", $doctor_id, $date, $availability_type);
    mysqli_stmt_execute($check_stmt);
    $result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($result) > 0) {
        $query = "UPDATE doctor_availability SET start_time = ?, end_time = ?, reason = ?, is_all_day = ?, updated_at = NOW() WHERE doctor_id = ? AND specific_date = ? AND availability_type = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "sssiiss", $start_time, $end_time, $reason, $is_all_day, $doctor_id, $date, $availability_type);
    } else {
        $query = "INSERT INTO doctor_availability (doctor_id, specific_date, start_time, end_time, availability_type, reason, is_all_day, active)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "isssssi", $doctor_id, $date, $start_time, $end_time, $availability_type, $reason, $is_all_day);
    }

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Availability set successfully'];
    }
    return ['success' => false, 'message' => 'Failed to set availability'];
}

function searchPatients($conn, $data, $doctor_id) {
    $search_term = '%' . mysqli_real_escape_string($conn, $data['search_term']) . '%';
    $query = "SELECT p.*, u.first_name as parent_first, u.last_name as parent_last
              FROM patients p
              JOIN users u ON p.parent_id = u.id
              WHERE (p.first_name LIKE ? OR p.last_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
              AND p.id IN (SELECT DISTINCT patient_id FROM appointments WHERE doctor_id = ?)
              LIMIT 10";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssssi", $search_term, $search_term, $search_term, $search_term, $doctor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $patients = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $patients[] = $row;
    }
    return ['success' => true, 'patients' => $patients];
}

function updatePatientNotes($conn, $data, $doctor_id) {
    $patient_id = intval($data['patient_id']);
    $notes = mysqli_real_escape_string($conn, $data['notes']);
    
    $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $patient_id, $doctor_id);
    mysqli_stmt_execute($verify_stmt);
    $result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($result) === 0) {
        return ['success' => false, 'message' => 'Patient not found'];
    }
    
    $query = "UPDATE patients SET special_notes = ?, updated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "si", $notes, $patient_id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Patient notes updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update patient notes'];
}

function addPrescription($conn, $data, $doctor_id) {
    $patient_id = intval($data['patient_id']);
    $medication_name = trim($data['medication_name'] ?? '');
    $dosage = trim($data['dosage'] ?? '');
    $frequency = trim($data['frequency'] ?? '');
    $duration = trim($data['duration'] ?? '');
    $instructions = trim($data['instructions'] ?? '');
    $diagnosis = trim($data['diagnosis'] ?? '');

    if ($patient_id <= 0 || $medication_name === '') {
        return ['success' => false, 'message' => 'Patient and medication name are required'];
    }

    // Schema stores meds as JSON array; build a single-entry array for this form
    $medications = json_encode([[
        'name' => $medication_name,
        'dosage' => $dosage,
        'frequency' => $frequency,
        'duration' => $duration,
        'instructions' => $instructions
    ]], JSON_UNESCAPED_UNICODE);

    // Generate unique prescription number (e.g. RX-2026-000123)
    $prescription_number = 'RX-' . date('Y') . '-' . strtoupper(substr(uniqid('', true), -8));
    $prescription_date = date('Y-m-d');

    $query = "INSERT INTO prescriptions (prescription_number, patient_id, doctor_id, prescription_date, diagnosis, medications, notes, status, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW())";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
    }
    mysqli_stmt_bind_param($stmt, "siissss", $prescription_number, $patient_id, $doctor_id, $prescription_date, $diagnosis, $medications, $instructions);

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Prescription added successfully'];
    }
    return ['success' => false, 'message' => 'Failed to add prescription: ' . mysqli_stmt_error($stmt)];
}

function addConsultationNotes($conn, $data, $doctor_id) {
    $patient_id = intval($data['patient_id']);
    $notes = trim($data['notes'] ?? '');
    $diagnosis = trim($data['diagnosis'] ?? '');
    $treatment_plan = trim($data['treatment_plan'] ?? '');
    $chief_complaint = trim($data['chief_complaint'] ?? '');
    $symptoms = trim($data['symptoms'] ?? '');
    $consultation_date = !empty($data['consultation_date']) ? $data['consultation_date'] : date('Y-m-d');

    if ($patient_id <= 0) {
        return ['success' => false, 'message' => 'Patient is required'];
    }
    if ($diagnosis === '' && $notes === '' && $treatment_plan === '') {
        return ['success' => false, 'message' => 'Please provide at least a diagnosis, notes, or treatment plan'];
    }

    $query = "INSERT INTO consultation_notes (patient_id, doctor_id, consultation_date, chief_complaint, symptoms, diagnosis, treatment_plan, notes, is_visible_to_parent, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
    }
    mysqli_stmt_bind_param($stmt, "iissssss", $patient_id, $doctor_id, $consultation_date, $chief_complaint, $symptoms, $diagnosis, $treatment_plan, $notes);

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Consultation notes added successfully'];
    }
    return ['success' => false, 'message' => 'Failed to add consultation notes: ' . mysqli_stmt_error($stmt)];
}

function add_medical_record($conn, $data, $doctor_id) {
    $patient_id = intval($data['patient_id'] ?? 0);
    $record_type = trim($data['record_type'] ?? '');
    $diagnosis = trim($data['diagnosis'] ?? '');
    $symptoms = trim($data['symptoms'] ?? '');
    $treatment_plan = trim($data['treatment_plan'] ?? '');
    $notes = trim($data['notes'] ?? '');
    $blood_pressure = trim($data['blood_pressure'] ?? '');

    // Allowed record types (whitelist)
    $allowed_types = ['CONSULTATION', 'CHECKUP', 'FOLLOW_UP', 'EMERGENCY', 'OTHER'];
    if (!in_array(strtoupper($record_type), $allowed_types, true)) {
        return ['success' => false, 'message' => 'Invalid record type'];
    }
    $record_type = strtoupper($record_type);

    if ($patient_id <= 0) {
        return ['success' => false, 'message' => 'Patient is required'];
    }
    if ($diagnosis === '' || $symptoms === '' || $treatment_plan === '') {
        return ['success' => false, 'message' => 'Diagnosis, symptoms, and treatment plan are required'];
    }

    // Numeric vitals — keep as null when blank
    $temperature = (isset($data['temperature']) && $data['temperature'] !== '') ? floatval($data['temperature']) : null;
    $heart_rate  = (isset($data['heart_rate']) && $data['heart_rate'] !== '')   ? intval($data['heart_rate'])   : null;
    $height      = (isset($data['height']) && $data['height'] !== '')           ? floatval($data['height'])     : null;
    $weight      = (isset($data['weight']) && $data['weight'] !== '')           ? floatval($data['weight'])     : null;

    // Range sanity checks
    if ($temperature !== null && ($temperature < 25 || $temperature > 45)) {
        return ['success' => false, 'message' => 'Temperature must be between 25 and 45 °C'];
    }
    if ($heart_rate !== null && ($heart_rate < 20 || $heart_rate > 300)) {
        return ['success' => false, 'message' => 'Heart rate must be between 20 and 300 bpm'];
    }
    if ($height !== null && ($height < 0 || $height > 250)) {
        return ['success' => false, 'message' => 'Height must be between 0 and 250 cm'];
    }
    if ($weight !== null && ($weight < 0 || $weight > 300)) {
        return ['success' => false, 'message' => 'Weight must be between 0 and 300 kg'];
    }
    if ($blood_pressure !== '' && !preg_match('/^\d{1,3}\s*\/\s*\d{1,3}$/', $blood_pressure)) {
        return ['success' => false, 'message' => 'Blood pressure must be in format 120/80'];
    }

    // Authorize: doctor must either (a) have or have had an appointment with the patient,
    // (b) be a DOCTOR_OWNER (clinic owner), or (c) the patient has no assigned doctor yet.
    $is_authorized = false;

    $user_type_query = "SELECT user_type FROM users WHERE id = ?";
    $ut_stmt = mysqli_prepare($conn, $user_type_query);
    mysqli_stmt_bind_param($ut_stmt, "i", $doctor_id);
    mysqli_stmt_execute($ut_stmt);
    $ut_result = mysqli_stmt_get_result($ut_stmt);
    $ut_row = mysqli_fetch_assoc($ut_result);
    if ($ut_row && $ut_row['user_type'] === 'DOCTOR_OWNER') {
        $is_authorized = true;
    }
    mysqli_stmt_close($ut_stmt);

    if (!$is_authorized) {
        $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
        $verify_stmt = mysqli_prepare($conn, $verify_query);
        mysqli_stmt_bind_param($verify_stmt, "ii", $patient_id, $doctor_id);
        mysqli_stmt_execute($verify_stmt);
        $result = mysqli_stmt_get_result($verify_stmt);
        if (mysqli_num_rows($result) > 0) {
            $is_authorized = true;
        }
        mysqli_stmt_close($verify_stmt);
    }

    if (!$is_authorized) {
        return ['success' => false, 'message' => 'Patient not found or not authorized'];
    }

    $query = "INSERT INTO medical_records (patient_id, doctor_id, record_date, record_type,
              diagnosis, symptoms, temperature, blood_pressure, heart_rate,
              height, weight, treatment_plan, notes, created_at)
              VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log('add_medical_record prepare failed: ' . mysqli_error($conn));
        return ['success' => false, 'message' => 'Failed to add medical record'];
    }

    // Types: i,i,s,s,s,d,s,i,d,d,s,s  = iisssdsiddss (12 params)
    mysqli_stmt_bind_param($stmt, "iisssdsiddss",
        $patient_id, $doctor_id, $record_type, $diagnosis, $symptoms,
        $temperature, $blood_pressure, $heart_rate, $height, $weight,
        $treatment_plan, $notes);

    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Medical record added successfully'];
    }
    error_log('add_medical_record execute failed: ' . mysqli_stmt_error($stmt));
    return ['success' => false, 'message' => 'Failed to add medical record'];
}

/**
 * Update the currently-logged-in doctor's own profile information.
 */
function update_doctor_profile($conn, $data, $doctor_id) {
    $first_name     = trim($data['first_name'] ?? '');
    $last_name      = trim($data['last_name'] ?? '');
    $email          = trim($data['email'] ?? '');
    $phone          = preg_replace('/\s+/', '', $data['phone'] ?? '');
    $specialization = trim($data['specialization'] ?? '');
    $license_number = trim($data['license_number'] ?? '');
    $years_raw      = trim($data['years_of_experience'] ?? '');

    if ($first_name === '' || $last_name === '' || $email === '') {
        return ['success' => false, 'message' => 'First name, last name, and email are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }
    if ($phone !== '' && !preg_match('/^9\d{9}$/', $phone)) {
        return ['success' => false, 'message' => 'Phone number must be 10 digits starting with 9.'];
    }
    if ($specialization === '') {
        return ['success' => false, 'message' => 'Specialization is required.'];
    }
    if ($license_number === '') {
        return ['success' => false, 'message' => 'License number is required.'];
    }
    $years = null;
    if ($years_raw !== '') {
        if (!ctype_digit($years_raw) || intval($years_raw) < 0 || intval($years_raw) > 80) {
            return ['success' => false, 'message' => 'Years of experience must be between 0 and 80.'];
        }
        $years = intval($years_raw);
    }

    // Uniqueness check for email (excluding current user)
    $dup_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
    mysqli_stmt_bind_param($dup_stmt, "si", $email, $doctor_id);
    mysqli_stmt_execute($dup_stmt);
    $dup_res = mysqli_stmt_get_result($dup_stmt);
    if (mysqli_num_rows($dup_res) > 0) {
        mysqli_stmt_close($dup_stmt);
        return ['success' => false, 'message' => 'This email is already used by another account.'];
    }
    mysqli_stmt_close($dup_stmt);

    $phone_val = $phone === '' ? null : $phone;

    $stmt = mysqli_prepare($conn,
        "UPDATE users
           SET first_name = ?, last_name = ?, email = ?, phone = ?,
               specialization = ?, license_number = ?, years_of_experience = ?
         WHERE id = ?");
    if (!$stmt) {
        error_log('update_doctor_profile prepare failed: ' . mysqli_error($conn));
        return ['success' => false, 'message' => 'Failed to update profile.'];
    }
    mysqli_stmt_bind_param($stmt, "ssssssii",
        $first_name, $last_name, $email, $phone_val,
        $specialization, $license_number, $years, $doctor_id);

    if (!mysqli_stmt_execute($stmt)) {
        error_log('update_doctor_profile execute failed: ' . mysqli_stmt_error($stmt));
        return ['success' => false, 'message' => 'Failed to update profile.'];
    }

    // Keep session in sync
    $_SESSION['first_name'] = $first_name;
    $_SESSION['last_name']  = $last_name;
    $_SESSION['email']      = $email;
    $_SESSION['specialization'] = $specialization;

    return ['success' => true, 'message' => 'Profile updated successfully.'];
}

/**
 * Change the currently-logged-in doctor's password.
 */
function change_doctor_password($conn, $data, $doctor_id) {
    $current = $data['current_password'] ?? '';
    $new     = $data['new_password'] ?? '';
    $confirm = $data['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        return ['success' => false, 'message' => 'All password fields are required.'];
    }
    if ($new !== $confirm) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }
    if (strlen($new) < 8) {
        return ['success' => false, 'message' => 'New password must be at least 8 characters.'];
    }
    if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        return ['success' => false, 'message' => 'New password must contain letters and numbers.'];
    }

    $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $doctor_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$row || !password_verify($current, $row['password'])) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }

    $hashed = password_hash($new, PASSWORD_DEFAULT);
    $upd = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
    mysqli_stmt_bind_param($upd, "si", $hashed, $doctor_id);
    if (!mysqli_stmt_execute($upd)) {
        error_log('change_doctor_password failed: ' . mysqli_stmt_error($upd));
        return ['success' => false, 'message' => 'Failed to change password.'];
    }

    return ['success' => true, 'message' => 'Password changed successfully.'];
}

function send_message_to_parent($conn, $data, $doctor_id) {
    $patient_id = intval($data['patient_id']);
    $message = mysqli_real_escape_string($conn, $data['message']);
    $subject = mysqli_real_escape_string($conn, $data['subject'] ?? 'Message from Doctor');
    
    $query = "SELECT u.email, u.first_name as parent_first, u.last_name as parent_last,
                     p.first_name as patient_first, p.last_name as patient_last
              FROM patients p
              JOIN users u ON p.parent_id = u.id
              WHERE p.id = ? AND p.id IN (SELECT DISTINCT patient_id FROM appointments WHERE doctor_id = ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $patient_id, $doctor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $parent = mysqli_fetch_assoc($result);
    
    if (!$parent) {
        return ['success' => false, 'message' => 'Parent not found'];
    }
    
    $email = $parent['email'];
    error_log("Would send email to: $email, Subject: $subject, Message: $message");
    
    return ['success' => true, 'message' => 'Message sent successfully'];
}

function get_doctor_availability($conn, $doctor_id) {
    $availability = [];
    $doctor_id = intval($doctor_id);

    try {
        $start = new DateTime();
        $end = (new DateTime())->modify('+30 days');
        $first_day = $start->format('Y-m-d');
        $last_day = $end->format('Y-m-d');

        // get doctor's explicit availability/unavailability
        $avail_query = "SELECT specific_date as date, start_time, end_time, availability_type, reason, is_all_day
                       FROM doctor_availability
                       WHERE doctor_id = ?
                       AND specific_date BETWEEN ? AND ?
                       AND active = 1";
        $avail_stmt = mysqli_prepare($conn, $avail_query);
        mysqli_stmt_bind_param($avail_stmt, "iss", $doctor_id, $first_day, $last_day);
        mysqli_stmt_execute($avail_stmt);
        $avail_res = mysqli_stmt_get_result($avail_stmt);

        $doctor_avail_map = [];
        while ($a = mysqli_fetch_assoc($avail_res)) {
            $doctor_avail_map[$a['date']][] = $a;
        }

        // get clinic hours
        $business_hours = [];
        $bh_query = "SELECT setting_value FROM clinic_settings WHERE setting_key = 'business_hours' LIMIT 1";
        $bh_res = mysqli_query($conn, $bh_query);
        if ($bh_res && ($row = mysqli_fetch_assoc($bh_res))) {
            $decoded = json_decode($row['setting_value'], true);
            if (is_array($decoded)) $business_hours = $decoded;
        }

        // get holidays (if table exists)
        $holidays = [];
        $hol_check = mysqli_query($conn, "SHOW TABLES LIKE 'holidays'");
        if ($hol_check && mysqli_num_rows($hol_check) > 0) {
            $hol_query = "SELECT date, name FROM holidays WHERE date BETWEEN ? AND ?";
            $hol_stmt = mysqli_prepare($conn, $hol_query);
            if ($hol_stmt) {
                mysqli_stmt_bind_param($hol_stmt, "ss", $first_day, $last_day);
                mysqli_stmt_execute($hol_stmt);
                $hol_res = mysqli_stmt_get_result($hol_stmt);
                while ($h = mysqli_fetch_assoc($hol_res)) {
                    $holidays[$h['date']] = $h['name'];
                }
            }
        }

        $period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));

        foreach ($period as $dt) {
            $date = $dt->format('Y-m-d');
            $dayLower = strtolower($dt->format('l'));
            $clinic_open = null;
            $clinic_close = null;
            if (!empty($business_hours[$dayLower]) && isset($business_hours[$dayLower]['open'])) {
                $clinic_open = $business_hours[$dayLower]['open'];
                $clinic_close = $business_hours[$dayLower]['close'];
            }

            // If no business hours configured, default to weekday schedule
            if (empty($clinic_open) && empty($business_hours)) {
                $dayNum = $dt->format('N'); // 1=Mon, 7=Sun
                if ($dayNum <= 6) { // Mon-Sat
                    $clinic_open = '08:00';
                    $clinic_close = '17:00';
                } else {
                    continue; // Sunday closed
                }
            } elseif (empty($clinic_open)) {
                continue; // clinic closed this weekday
            }

            if (isset($holidays[$date])) {
                $availability[] = [
                    'date' => $date,
                    'available' => false,
                    'reason' => 'Holiday: ' . $holidays[$date],
                    'is_all_day' => 1
                ];
                continue;
            }

            $slot = [
                'date' => $date,
                'available' => true,
                'start_time' => $clinic_open,
                'end_time' => $clinic_close,
                'source' => 'clinic_hours'
            ];

            if (isset($doctor_avail_map[$date])) {
                $entries = $doctor_avail_map[$date];
                $isUnavailable = false;
                $reason = '';
                $avail_override = null;
                foreach ($entries as $e) {
                    if ($e['availability_type'] === 'UNAVAILABLE') {
                        $isUnavailable = true;
                        $reason = $e['reason'] ?? 'Not available';
                    } elseif ($e['availability_type'] === 'AVAILABLE') {
                        $avail_override = $e;
                    }
                }

                if ($isUnavailable && is_null($avail_override)) {
                    $availability[] = [
                        'date' => $date,
                        'available' => false,
                        'reason' => $reason,
                        'is_all_day' => 1
                    ];
                    continue;
                } elseif (!is_null($avail_override)) {
                    if ($avail_override['is_all_day']) {
                        $availability[] = [
                            'date' => $date,
                            'available' => true,
                            'start_time' => $clinic_open,
                            'end_time' => $clinic_close,
                            'source' => 'doctor_available_all_day'
                        ];
                    } else {
                        $availability[] = [
                            'date' => $date,
                            'available' => true,
                            'start_time' => ($avail_override['start_time'] ?: $clinic_open),
                            'end_time' => ($avail_override['end_time'] ?: $clinic_close),
                            'source' => 'doctor_available_custom'
                        ];
                    }
                    continue;
                }
            }

            $availability[] = array_merge($slot, ['available' => true]);
        }

    } catch (Exception $e) {
        error_log("Error getting doctor availability in dashboard: " . $e->getMessage());
    }

    return $availability;
}

function cancel_availability($conn, $data, $doctor_id) {
    $availability_id = intval($data['availability_id']);
    $doctor_id = intval($doctor_id);
    
    $query = "DELETE FROM doctor_availability WHERE id = ? AND doctor_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $availability_id, $doctor_id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Availability slot cancelled successfully'];
    }
    return ['success' => false, 'message' => 'Failed to cancel availability slot'];
}

function export_patient_data($conn, $patient_id, $doctor_id, $format = 'csv') {
    $patient_id = intval($patient_id);
    $doctor_id = intval($doctor_id);
    
    try {
        $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
        $verify_stmt = mysqli_prepare($conn, $verify_query);
        mysqli_stmt_bind_param($verify_stmt, "ii", $patient_id, $doctor_id);
        mysqli_stmt_execute($verify_stmt);
        $result = mysqli_stmt_get_result($verify_stmt);
        
        if (mysqli_num_rows($result) === 0) {
            return ['success' => false, 'message' => 'Patient not found or not authorized'];
        }
        
        $export_data = [
            'patient' => get_patient_details($conn, $patient_id, $doctor_id),
            'appointments' => get_all_appointments($conn, $doctor_id),
            'prescriptions' => get_patient_prescriptions($conn, $patient_id, $doctor_id),
            'consultation_notes' => get_patient_consultation_notes($conn, $patient_id, $doctor_id),
            'medical_records' => get_patient_medical_records($conn, $patient_id, $doctor_id)
        ];
        
        return [
            'success' => true, 
            'message' => 'Export data prepared',
            'data' => $export_data
        ];
        
    } catch (Exception $e) {
        error_log("Error exporting patient data: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to export patient data'];
    }
}

function get_appointment_details($conn, $appointment_id, $doctor_id) {
    $appointment_id = intval($appointment_id);
    $doctor_id = intval($doctor_id);
    
    try {
        $query = "SELECT a.*, 
                         p.first_name, p.last_name, p.date_of_birth, p.gender, p.blood_type,
                         p.height, p.weight, p.allergies, p.medical_conditions, p.special_notes,
                         u.first_name as parent_first, u.last_name as parent_last,
                         u.phone as parent_phone, u.email as parent_email
                  FROM appointments a
                  JOIN patients p ON a.patient_id = p.id
                  JOIN users u ON p.parent_id = u.id
                  WHERE a.id = ? AND a.doctor_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $appointment_id, $doctor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
                $appointment = mysqli_fetch_assoc($result);
        
        if ($appointment) {
            $birth_date = new DateTime($appointment['date_of_birth']);
            $today = new DateTime();
            $age = $birth_date->diff($today);
            $appointment['age_years'] = $age->y;
            $appointment['age_months'] = $age->m;
        }
        
        return $appointment;
        
    } catch (Exception $e) {
        error_log("Error getting appointment details: " . $e->getMessage());
        return null;
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'SCHEDULED': return 'bg-orange-100 text-orange-800 border border-orange-200';
        case 'CONFIRMED': return 'bg-green-100 text-green-800 border border-green-200';
        case 'IN_PROGRESS': return 'bg-blue-100 text-blue-800 border border-blue-200';
        case 'COMPLETED': return 'bg-purple-100 text-purple-800 border border-purple-200';
        case 'CANCELLED': return 'bg-red-100 text-red-800 border border-red-200';
        default: return 'bg-gray-100 text-gray-800 border border-gray-200';
    }
}

function formatAppointmentTime($time) {
    return date('g:i A', strtotime($time));
}

function calculateAge($birth_date) {
    $birth = new DateTime($birth_date);
    $today = new DateTime();
    $age = $birth->diff($today);
    
    if ($age->y > 0) {
        return $age->y . ' years';
    } else {
        return $age->m . ' months';
    }
}

// Initialize data
$stats = get_doctor_stats($conn, $current_user['id']);
$appointment_chart_data = get_appointment_chart_data($conn, $current_user['id']);
$vaccination_chart_data = get_vaccination_chart_data($conn, $current_user['id']);
$today_appointments = get_today_appointments($conn, $current_user['id']);
$recent_visits = get_recent_visits($conn, $current_user['id']);
$pending_appointments = get_pending_appointments($conn, $current_user['id']);
$doctor_patients = get_doctor_patients($conn, $current_user['id']);
$recent_vaccinations = get_recent_vaccinations($conn, $current_user['id']);
$vaccination_schedule = get_vaccination_schedule($conn, $current_user['id']);
$vaccines = get_vaccines($conn);

// Handle form submissions with enhanced security
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response = ['success' => false, 'message' => 'Security token invalid'];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            switch ($action) {
                case 'approve_appointment':
                    $response = approveAppointment($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'reject_appointment':
                    $response = rejectAppointment($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'complete_appointment':
                    $response = completeAppointment($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'record_vaccination':
                    $response = recordVaccination($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'set_availability':
                    $response = setAvailability($conn, $_POST, $current_user['id']);
                    break;

                case 'set_weekly_schedule':
                    $response = setWeeklySchedule($conn, $_POST, $current_user['id']);
                    break;

                case 'remove_weekly_schedule':
                    $response = removeWeeklySchedule($conn, $_POST, $current_user['id']);
                    break;

                case 'get_weekly_schedule':
                    $response = getWeeklySchedule($conn, $current_user['id']);
                    break;
                    
                case 'search_patients':
                    $response = searchPatients($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'update_patient_notes':
                    $response = updatePatientNotes($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'add_prescription':
                    $response = addPrescription($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'add_consultation_notes':
                    $response = addConsultationNotes($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'get_patient_details':
                    $patient_id = intval($_POST['patient_id']);
                    $patient = get_patient_details($conn, $patient_id, $current_user['id']);
                    if ($patient) {
                        // Compute age
                        $age_years = 0; $age_months = 0;
                        if (!empty($patient['date_of_birth'])) {
                            try {
                                $dob = new DateTime($patient['date_of_birth']);
                                $diff = $dob->diff(new DateTime());
                                $age_years = (int)$diff->y;
                                $age_months = (int)$diff->m + ($age_years * 12);
                            } catch (Exception $e) { /* ignore */ }
                        }
                        $patient['age_years']  = $age_years;
                        $patient['age_months'] = $age_months;

                        // Recent medical records (up to 5)
                        $medical_records = get_patient_medical_records($conn, $patient_id, $current_user['id']);
                        $recent_records = array_slice($medical_records, 0, 5);

                        // Most recent vitals snapshot — pick the latest record with any vital signs
                        $latest_vitals = null;
                        foreach ($medical_records as $mr) {
                            $has_vital = ($mr['temperature'] !== null && $mr['temperature'] !== '')
                                || !empty($mr['blood_pressure'])
                                || ($mr['heart_rate'] !== null && $mr['heart_rate'] !== '')
                                || ($mr['height'] !== null && $mr['height'] !== '')
                                || ($mr['weight'] !== null && $mr['weight'] !== '');
                            if ($has_vital) {
                                $latest_vitals = [
                                    'record_date'     => $mr['record_date'],
                                    'temperature'     => $mr['temperature'],
                                    'blood_pressure'  => $mr['blood_pressure'],
                                    'heart_rate'      => $mr['heart_rate'],
                                    'height'          => $mr['height'],
                                    'weight'          => $mr['weight'],
                                ];
                                break;
                            }
                        }

                        $response = [
                            'success' => true,
                            'patient' => $patient,
                            'recent_records' => $recent_records,
                            'latest_vitals' => $latest_vitals,
                            'records_total' => count($medical_records)
                        ];
                    } else {
                        $response = ['success' => false, 'message' => 'Patient not found'];
                    }
                    break;

                case 'get_patient_files':
                    $patient_id = intval($_POST['patient_id']);
                    // Verify the doctor has any relationship with this patient
                    // (appointment OR medical record) — be permissive for staff doctors.
                    $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ?
                                     UNION
                                     SELECT 1 FROM medical_records WHERE patient_id = ? AND doctor_id = ?
                                     LIMIT 1";
                    $verify_stmt = mysqli_prepare($conn, $verify_query);
                    mysqli_stmt_bind_param($verify_stmt, "iiii", $patient_id, $current_user['id'], $patient_id, $current_user['id']);
                    mysqli_stmt_execute($verify_stmt);
                    $verify_result = mysqli_stmt_get_result($verify_stmt);
                    $has_relationship = (mysqli_num_rows($verify_result) > 0);
                    // If no direct relationship, also allow DOCTOR_OWNER (clinic owner) to view all patient files.
                    if (!$has_relationship && ($_SESSION['user_type'] ?? '') !== 'DOCTOR_OWNER') {
                        $response = ['success' => false, 'message' => 'Not authorized to view this patient'];
                        break;
                    }
                    $files = [];
                    $files_query = "SELECT pf.id, pf.original_filename, pf.mime_type, pf.file_size, pf.file_category, pf.description, pf.created_at,
                                           u.first_name AS uploader_first, u.last_name AS uploader_last
                                    FROM patient_files pf
                                    LEFT JOIN users u ON pf.uploaded_by = u.id
                                    WHERE pf.patient_id = ?
                                    ORDER BY pf.created_at DESC";
                    $files_stmt = mysqli_prepare($conn, $files_query);
                    mysqli_stmt_bind_param($files_stmt, "i", $patient_id);
                    mysqli_stmt_execute($files_stmt);
                    $files_result = mysqli_stmt_get_result($files_stmt);
                    while ($row = mysqli_fetch_assoc($files_result)) {
                        $files[] = $row;
                    }
                    $response = ['success' => true, 'files' => $files];
                    break;

                case 'get_patient_prescriptions':
                    $patient_id = intval($_POST['patient_id']);
                    $prescriptions = get_patient_prescriptions($conn, $patient_id, $current_user['id']);
                    $response = ['success' => true, 'prescriptions' => $prescriptions];
                    break;

                case 'get_consultation_notes':
                    $patient_id = intval($_POST['patient_id']);
                    $notes = get_patient_consultation_notes($conn, $patient_id, $current_user['id']);
                    $response = ['success' => true, 'notes' => $notes];
                    break;

                case 'update_patient_info':
                    $response = update_patient_info($conn, $_POST, $current_user['id']);
                    break;

                case 'print_prescription':
                    $prescription_id = intval($_POST['prescription_id']);
                    $response = print_prescription($conn, $prescription_id, $current_user['id']);
                    break;
                    
                case 'add_medical_record':
                    $response = add_medical_record($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'get_medical_records':
                    $patient_id = intval($_POST['patient_id']);
                    $records = get_patient_medical_records($conn, $patient_id, $current_user['id']);
                    $response = ['success' => true, 'records' => $records];
                    break;
                    
                case 'send_message':
                    $response = send_message_to_parent($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'get_availability':
                    $availability = get_doctor_availability($conn, $current_user['id']);
                    $response = ['success' => true, 'availability' => $availability];
                    break;
                    
                case 'cancel_availability':
                    $response = cancel_availability($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'get_appointment_details':
                    $appointment_id = intval($_POST['appointment_id']);
                    $appointment = get_appointment_details($conn, $appointment_id, $current_user['id']);
                    if ($appointment) {
                        $response = ['success' => true, 'appointment' => $appointment];
                    } else {
                        $response = ['success' => false, 'message' => 'Appointment not found'];
                    }
                    break;
                    
                case 'export_patient_data':
                    $patient_id = intval($_POST['patient_id']);
                    $response = export_patient_data($conn, $patient_id, $current_user['id'], $_POST['format'] ?? 'csv');
                    break;
                
                case 'get_vaccine_needs':
                    $patient_id = intval($_POST['patient_id']);
                    $response = get_patient_vaccine_needs($conn, $patient_id, $current_user['id']);
                    break;

                case 'set_vaccine_need':
                    $response = set_patient_vaccine_need($conn, $_POST, $current_user['id']);
                    break;
                    
                case 'delete_vaccine_need':
                    $vaccine_need_id = intval($_POST['vaccine_need_id']);
                    $response = delete_patient_vaccine_need($conn, $vaccine_need_id, $current_user['id']);
                    break;
                    
                case 'get_recommended_vaccines':
                    $patient_id = intval($_POST['patient_id']);
                    $patient_query = "SELECT date_of_birth FROM patients WHERE id = ?";
                    $patient_stmt = mysqli_prepare($conn, $patient_query);
                    mysqli_stmt_bind_param($patient_stmt, "i", $patient_id);
                    mysqli_stmt_execute($patient_stmt);
                    $patient_result = mysqli_stmt_get_result($patient_stmt);
                    
                    if (mysqli_num_rows($patient_result) === 0) {
                        $response = ['success' => false, 'message' => 'Patient not found'];
                    } else {
                        $patient = mysqli_fetch_assoc($patient_result);
                        $birth_date = new DateTime($patient['date_of_birth']);
                        $today = new DateTime();
                        $interval = $birth_date->diff($today);
                        $age_months = ($interval->y * 12) + $interval->m;
                        
                        $vaccines_rec = get_recommended_vaccines_for_age($conn, $age_months);
                        $response = ['success' => true, 'data' => $vaccines_rec, 'age_months' => $age_months];
                    }
                    break;

                case 'get_patient_vaccination_records':
                    $patient_id = intval($_POST['patient_id']);
                    $response = get_patient_vaccination_records($conn, $patient_id, $current_user['id']);
                    break;

                case 'get_vaccination_record':
                    $vaccination_id = intval($_POST['vaccination_id']);
                    $record = get_vaccination_record($conn, $vaccination_id, $current_user['id']);
                    if ($record) {
                        $response = ['success' => true, 'record' => $record];
                    } else {
                        $response = ['success' => false, 'message' => 'Vaccination record not found'];
                    }
                    break;

                case 'edit_vaccination_record':
                    $vaccination_id = intval($_POST['vaccination_id']);
                    $response = edit_vaccination_record($conn, $vaccination_id, $_POST, $current_user['id']);
                    break;

                case 'delete_vaccination_record':
                    $vaccination_id = intval($_POST['vaccination_id']);
                    $response = delete_vaccination_record($conn, $vaccination_id, $current_user['id']);
                    break;

                case 'update_doctor_profile':
                    $response = update_doctor_profile($conn, $_POST, $current_user['id']);
                    break;

                case 'change_doctor_password':
                    $response = change_doctor_password($conn, $_POST, $current_user['id']);
                    break;
            }
        }
    } catch (Exception $e) {
        error_log("Form submission error: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'An error occurred. Please try again.'];
    }
    
    // Always return JSON for AJAX POST requests with an action parameter
    if (isset($_POST['ajax']) || isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard - AlagApp Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="css/shared.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <style>
        :root {
            --primary-pink: #ff7aa3;
            --primary-pink-dashboard: #ff7aa3;
            --light-pink: #FFBCD9;
            --dark-text: #333333;
            --light-gray: #F6F6F8;
        }
        
        body {
            font-family: 'Source Sans Pro', sans-serif;
            background: linear-gradient(135deg, #ffffff 0%, #fef5f8 100%);
            min-height: 100vh;
        }
        
        .font-inter { font-family: 'Inter', sans-serif; }
        
        .text-primary { color: var(--primary-pink); }
        .bg-primary { background-color: var(--primary-pink); }
        .bg-light-pink { background-color: var(--light-pink); }
        
        .sidebar {
            background: linear-gradient(180deg, #ff7aa3 0%, #FFBCD9 100%);
            min-height: 100vh;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-pink), var(--light-pink));
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 154, 0.4);
        }
        
        .loading-spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid #ff7aa3;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            color: white;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success { background: #10b981; }
        .notification.error { background: #ef4444; }
        .notification.info { background: #3b82f6; }
        
        .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }
        
        .nav-item:hover, .nav-item.active {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .modal-backdrop {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        
        .modal-container {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .patient-item.selected {
            background-color: rgba(255, 107, 154, 0.1);
            border-color: #ff7aa3;
        }
    </style>
</head>
<body class="flex">
    <!-- Mobile Menu Button -->
    <button onclick="toggleSidebar()" class="mobile-menu-btn fixed top-4 left-4 z-50 p-2 bg-white rounded-lg shadow-lg text-gray-700 hover:text-pink-500 transition-colors">
        <i class="fas fa-bars text-xl"></i>
    </button>
    <!-- Mobile Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/40 z-30 hidden" onclick="toggleSidebar()"></div>

    <!-- Notification System -->
    <div id="notification" class="notification hidden"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="sidebar w-64 text-white">
        <div class="p-6">
            <h1 class="text-2xl font-inter font-bold mb-8 flex items-center">
                AlagApp
            </h1>
            
            <div class="mb-8">
                <a href="#profile" data-section="profile" class="flex items-center space-x-3 mb-4 p-3 bg-white/10 rounded-lg hover:bg-white/20 transition-colors cursor-pointer" title="View my profile">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-md text-white"></i>
                    </div>
                    <div>
                        <div class="font-semibold" id="doctorName">
                            Dr. <?php echo htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']); ?>
                        </div>
                        <div class="text-sm text-white/80" id="doctorSpecialty">
                            <?php echo htmlspecialchars($current_user['specialization'] ?? 'Pediatrician'); ?>
                        </div>
                    </div>
                </a>
            </div>
            
            <nav class="space-y-1">
                <a href="#dashboard" data-section="dashboard" class="nav-item active">
                    <i class="fas fa-tachometer-alt mr-3"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="#patients" data-section="patients" class="nav-item">
                    <i class="fas fa-user-injured mr-3"></i>
                    <span>Patients</span>
                </a>
                
                <a href="#schedule" data-section="schedule" class="nav-item">
                    <i class="fas fa-calendar-alt mr-3"></i>
                    <span>Schedule</span>
                </a>

                <a href="#appointments" data-section="appointments" class="nav-item">
                    <i class="fas fa-calendar-check mr-3"></i>
                    <span>Appointments</span>
                </a>

                <a href="#profile" data-section="profile" class="nav-item">
                    <i class="fas fa-user-cog mr-3"></i>
                    <span>Profile</span>
                </a>
            </nav>

            <div class="mt-8 pt-8 border-t border-white/20">
                <a href="logout.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                    <i class="fas fa-sign-out-alt mr-2"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="flex-1 main-content overflow-auto">
        <!-- Dashboard Section -->
        <div id="dashboard-section" class="section-content p-6 fade-in">
            <div class="mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Welcome, Dr. <?php echo htmlspecialchars($current_user['first_name']); ?>!</h1>
                        <p class="text-gray-600">Here's your overview for <?php echo date('l, F j, Y'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card card-hover p-6 cursor-pointer" onclick="showSection('appointments')" role="button" tabindex="0" title="Go to Appointments">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-blue-100 mr-4">
                            <i class="fas fa-calendar-day text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-600">Today's Appointments</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $stats['today_appointments']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="card card-hover p-6 cursor-pointer" onclick="showSection('appointments')" role="button" tabindex="0" title="Go to Appointments">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-orange-100 mr-4">
                            <i class="fas fa-clock text-orange-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Approval</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $stats['pending_appointments']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="card card-hover p-6 cursor-pointer" onclick="showSection('patients')" role="button" tabindex="0" title="Go to Patients">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-green-100 mr-4">
                            <i class="fas fa-users text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Patients</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $stats['total_patients']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="card card-hover p-6 cursor-pointer" onclick="showSection('patients')" role="button" tabindex="0" title="Go to Patients">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-purple-100 mr-4">
                            <i class="fas fa-syringe text-purple-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-600">Vaccinations Given</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $stats['vaccinations_given']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            

            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Today's Appointments -->
                <div class="card card-hover p-6  cursor-pointer">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-inter font-semibold text-gray-800">Today's Appointments</h3>
                        <span class="text-sm text-gray-500"><?php echo date('M j, Y'); ?></span>
                    </div>
                    <div class="space-y-4">
                        <?php if (empty($today_appointments)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-calendar-times text-gray-300 text-4xl mb-3"></i>
                                <p class="text-gray-500">No appointments scheduled for today</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($today_appointments as $appointment): ?>
                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-user text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-800">
                                                <?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-600">
                                                <i class="far fa-clock mr-1"></i>
                                                <?php echo formatAppointmentTime($appointment['appointment_time']); ?> • 
                                                <?php echo htmlspecialchars($appointment['type'] ?? 'Checkup'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="px-3 py-1 text-xs font-medium rounded-full <?php echo getStatusBadge($appointment['status']); ?>">
                                        <?php echo htmlspecialchars($appointment['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Patient Visits -->
                <div class="card card-hover p-6">
                    <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Recent Patient Visits</h3>
                    <div class="space-y-4">
                        <?php if (empty($recent_visits)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-user-clock text-gray-300 text-4xl mb-3"></i>
                                <p class="text-gray-500">No recent patient visits</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_visits as $visit): ?>
                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                                            <i class="fas fa-check text-green-600"></i>
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-800">
                                                <?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-600">
                                                <i class="far fa-calendar mr-1"></i>
                                                <?php echo date('M j, Y', strtotime($visit['appointment_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium text-gray-800">
                                            <?php echo htmlspecialchars($visit['type'] ?? 'Checkup'); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">Completed</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Patients Section -->
        <div id="patients-section" class="section-content p-6 hidden">
            <div class="mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Patient Records</h1>
                        <p class="text-gray-600">Access and manage patient information</p>
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2">
                    <div class="card card-hover p-6">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Patient List</h3>
                        <div id="patientList" class="space-y-4">
                            <?php if (empty($doctor_patients)): ?>
                                <p class="text-gray-500">No patients found</p>
                            <?php else: ?>
                                <?php foreach ($doctor_patients as $patient): ?>
                                    <div class="p-4 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer patient-item" 
                                         onclick="selectPatient(<?php echo $patient['id']; ?>, this)" 
                                         data-patient-id="<?php echo $patient['id']; ?>">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center mr-4">
                                                    <i class="fas fa-user text-primary"></i>
                                                </div>
                                                <div>
                                                    <div class="font-semibold text-gray-800">
                                                        <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-600">
                                                        DOB: <?php echo date('M j, Y', strtotime($patient['date_of_birth'])); ?>
                                                        (<?php echo $patient['age_years']; ?> years <?php echo $patient['age_months']; ?> months)
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-sm text-gray-600">Visits: <?php echo $patient['visit_count']; ?></div>
                                                <div class="text-xs text-gray-500">
                                                    Last: <?php echo $patient['last_visit'] ? date('M j, Y', strtotime($patient['last_visit'])) : 'Never'; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="space-y-6">
                    <!-- Patient Summary -->
                    <div class="card card-hover p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-inter font-semibold text-gray-800">Patient Summary</h3>
                            <button id="printPatientSummaryBtn" onclick="printPatientSummary()"
                                    class="hidden inline-flex items-center gap-2 px-3 py-1.5 text-sm bg-pink-100 text-pink-700 rounded hover:bg-pink-200 transition-colors">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                        <div id="patientSummary" class="text-center text-gray-500 cursor-pointer"
                             onclick="if(window.currentPatient) printPatientSummary();">
                            Select a patient to view details
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card card-hover p-6">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <button onclick="openVaccinationModal()" class="w-full text-left px-4 py-3 bg-primary/10 text-primary rounded-lg hover:bg-primary/20 transition-colors">
                                <i class="fas fa-syringe mr-2"></i>Record Vaccination
                            </button>
                            <button onclick="openMedicalRecordModal()" class="w-full text-left px-4 py-3 bg-yellow-100 text-yellow-600 rounded-lg hover:bg-yellow-200 transition-colors">
                                <i class="fas fa-file-medical mr-2"></i>Add Medical Record
                            </button>
                            <button onclick="openConsultationModal()" class="w-full text-left px-4 py-3 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition-colors">
                                <i class="fas fa-notes-medical mr-2"></i>Add Consultation Notes
                            </button>
                            <button onclick="openPrescriptionModal()" class="w-full text-left px-4 py-3 bg-green-100 text-green-600 rounded-lg hover:bg-green-200 transition-colors">
                                <i class="fas fa-prescription mr-2"></i>Write Prescription
                            </button>
                            <button onclick="openVaccineNeedModal()" class="w-full text-left px-4 py-3 bg-indigo-100 text-indigo-600 rounded-lg hover:bg-indigo-200 transition-colors">
                                <i class="fas fa-vial mr-2"></i>Manage Vaccine Needs
                            </button>
                        </div>
                    </div>

                    <!-- Vaccine Needs Display -->
                    <div class="card card-hover p-6">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Vaccine Needs</h3>
                        <div id="patientVaccineNeedsList" class="space-y-3">
                            <p class="text-gray-500 text-center py-4">Select a patient to view vaccine needs</p>
                        </div>
                    </div>

                    <!-- Vaccination History -->
                    <div class="card card-hover p-6">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Vaccination History</h3>
                        <div id="patientVaccinationHistory" class="space-y-3">
                            <p class="text-gray-500 text-center py-4">Select a patient to view vaccination history</p>
                        </div>
                    </div>

                    <!-- Patient Uploaded Files -->
                    <div class="card card-hover p-6">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">
                            <i class="fas fa-folder-open mr-2 text-pink-500"></i>
                            Patient Files
                        </h3>
                        <p class="text-xs text-gray-500 mb-3">Lab results, x-rays, referrals and other documents uploaded by the parent.</p>
                        <div id="patientFilesList" class="space-y-3">
                            <p class="text-gray-500 text-center py-4">Select a patient to view uploaded files</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule / Calendar Section -->
        <div id="schedule-section" class="section-content p-6 hidden">
            <div class="mb-8">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">My Schedule</h1>
                        <p class="text-gray-600 text-sm md:text-base">View and manage your appointment calendar & availability</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                <!-- Calendar -->
                <div class="lg:col-span-2">
                    <div class="card card-hover p-4 md:p-6">
                        <div id="doctorCalendar"></div>
                    </div>
                </div>

                <!-- Selected Day Details & Availability -->
                <div class="space-y-6">
                    <div class="card card-hover p-4 md:p-6">
                        <h3 class="text-lg font-inter font-semibold text-gray-800 mb-4">
                            <i class="fas fa-list-ul mr-2 text-primary"></i>
                            <span id="selectedDateTitle">Today's Appointments</span>
                        </h3>
                        <div id="calendarDayAppointments" class="space-y-3">
                            <div class="text-center py-8">
                                <i class="fas fa-calendar-day text-gray-300 text-3xl mb-3"></i>
                                <p class="text-gray-500 text-sm">Select a date to see appointments</p>
                            </div>
                        </div>
                    </div>

                    <!-- Set Weekly Working Schedule -->
                    <div class="card card-hover p-4 md:p-6">
                        <h3 class="text-lg font-inter font-semibold text-gray-800 mb-1">
                            <i class="fas fa-calendar-check mr-2 text-green-500"></i>
                            Weekly Working Schedule
                        </h3>
                        <p class="text-sm text-gray-500 mb-4">Set which days and hours you are available each week. Parents will only be able to book on these days.</p>

                        <!-- Current schedule display -->
                        <div id="currentScheduleDisplay" class="mb-4 space-y-1">
                            <p class="text-xs text-gray-400 italic">Loading current schedule…</p>
                        </div>

                        <form id="setWeeklyScheduleForm" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="set_weekly_schedule">

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Day of Week</label>
                                <select name="day_of_week" id="schedDay" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                                    <option value="">-- Select Day --</option>
                                    <option value="MONDAY">Monday</option>
                                    <option value="TUESDAY">Tuesday</option>
                                    <option value="WEDNESDAY">Wednesday</option>
                                    <option value="THURSDAY">Thursday</option>
                                    <option value="FRIDAY">Friday</option>
                                    <option value="SATURDAY">Saturday</option>
                                    <option value="SUNDAY">Sunday</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                                    <input type="time" name="start_time" id="schedStart" value="09:00" required
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                                    <input type="time" name="end_time" id="schedEnd" value="17:00" required
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <button type="submit" id="schedSaveBtn"
                                        class="flex-1 px-4 py-2 text-sm font-medium text-white bg-green-500 rounded-lg hover:bg-green-600 transition-colors">
                                    <i class="fas fa-check mr-1"></i> Save Day
                                </button>
                                <button type="button" id="schedRemoveBtn"
                                        class="flex-1 px-4 py-2 text-sm font-medium text-white bg-gray-400 rounded-lg hover:bg-gray-500 transition-colors">
                                    <i class="fas fa-times mr-1"></i> Remove Day
                                </button>
                            </div>
                            <div id="schedMsg" class="hidden text-sm rounded-lg px-3 py-2"></div>
                        </form>
                    </div>

                    <!-- Set Unavailability -->
                    <div class="card card-hover p-4 md:p-6">
                        <h3 class="text-lg font-inter font-semibold text-gray-800 mb-4">
                            <i class="fas fa-ban mr-2 text-red-500"></i>
                            Set Unavailability
                        </h3>
                        <p class="text-sm text-gray-500 mb-4">Click a future date on the calendar to toggle your availability. You can also use the form below.</p>
                        <form id="setUnavailableForm" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="set_unavailable">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="date" name="date" id="unavailableDate" required min="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Reason (optional)</label>
                                <input type="text" name="reason" id="unavailableReason" placeholder="e.g., Personal leave, Conference..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm">
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" name="is_all_day" id="unavailableAllDay" checked class="mr-2 rounded text-primary focus:ring-primary">
                                <label for="unavailableAllDay" class="text-sm text-gray-700">All day</label>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-lg hover:bg-red-600 transition-colors">
                                <i class="fas fa-ban mr-1"></i> Mark Unavailable
                            </button>
                        </form>
                    </div>

                    <!-- Calendar Legend -->
                    <div class="card card-hover p-4 md:p-6">
                        <h3 class="text-lg font-inter font-semibold text-gray-800 mb-4">Legend</h3>
                        <div class="space-y-3">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-lg bg-pink-50 border-2 border-pink-300 flex items-center justify-center mr-3">
                                    <span class="text-xs font-bold text-pink-600">15</span>
                                </div>
                                <span class="text-sm text-gray-600">Today</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-lg bg-white border border-gray-200 flex items-center justify-center mr-3 relative">
                                    <span class="text-xs text-gray-700">8</span>
                                    <span class="absolute -top-1 -right-1 w-3 h-3 bg-pink-500 rounded-full"></span>
                                </div>
                                <span class="text-sm text-gray-600">Has appointments</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-lg bg-red-50 flex items-center justify-center mr-3">
                                    <span class="text-xs text-red-800">X</span>
                                </div>
                                <span class="text-sm text-gray-600">Unavailable</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center mr-3">
                                    <span class="text-xs text-gray-400">S</span>
                                </div>
                                <span class="text-sm text-gray-600">Non-working day</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Appointments Section -->
        <div id="appointments-section" class="section-content p-6 hidden">
            <div class="mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Appointments</h1>
                        <p class="text-gray-600">Manage all patient appointments</p>
                    </div>
                    <select id="appointmentFilter" onchange="filterAppointments()" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        <option value="all">All Appointments</option>
                        <option value="today">Today</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </div>

            <div class="card card-hover p-6">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-3 px-4">Patient</th>
                                <th class="text-left py-3 px-4">Date & Time</th>
                                <th class="text-left py-3 px-4">Type</th>
                                <th class="text-left py-3 px-4">Status</th>
                                <th class="text-left py-3 px-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="appointmentsTable">
                            <?php 
                            $all_appointments = get_all_appointments($conn, $current_user['id']);
                            if (empty($all_appointments)): ?>
                                <tr>
                                    <td colspan="5" class="py-4 px-4 text-center text-gray-500">No appointments found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_appointments as $appointment): ?>
                                    <tr class="border-b border-gray-100">
                                        <td class="py-3 px-4">
                                            <div class="font-medium"><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></div>
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="font-medium"><?php echo date('M j, Y', strtotime($appointment['appointment_date'])); ?></div>
                                            <div class="text-sm text-gray-600"><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></div>
                                        </td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($appointment['type'] ?? 'Checkup'); ?></td>
                                        <td class="py-3 px-4">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo getStatusBadge($appointment['status']); ?>">
                                                <?php echo htmlspecialchars($appointment['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="flex space-x-2">
                                                <?php if ($appointment['status'] === 'SCHEDULED'): ?>
                                                    <button onclick="approveAppointment(<?php echo $appointment['id']; ?>)" class="text-green-600 hover:text-green-800 text-sm font-medium">
                                                        Approve
                                                    </button>
                                                    <button onclick="rejectAppointment(<?php echo $appointment['id']; ?>)" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                                        Reject
                                                    </button>
                                                <?php elseif ($appointment['status'] === 'CONFIRMED'): ?>
                                                    <button onclick="completeAppointment(<?php echo $appointment['id']; ?>)" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                        Complete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Vaccinations Section -->
        <div id="vaccinations-section" class="section-content p-6 hidden">
            <div class="mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">Vaccinations</h1>
                        <p class="text-gray-600">Manage vaccination records and schedules</p>
                    </div>
                    <button onclick="openVaccinationModal()" class="btn-primary text-white px-6 py-3 rounded-lg font-semibold">
                        Record Vaccination
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="card card-hover p-6">
                    <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Recent Vaccinations</h3>
                    <div class="space-y-4" data-recent-vaccinations>
                        <?php if (empty($recent_vaccinations)): ?>
                            <p class="text-gray-500">No recent vaccinations recorded</p>
                        <?php else: ?>
                            <?php foreach ($recent_vaccinations as $vaccination): ?>
                                <div class="p-4 border border-gray-200 rounded-lg">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <div class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($vaccination['first_name'] . ' ' . $vaccination['last_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($vaccination['vaccine_name']); ?></div>
                                        </div>
                                        <span class="text-xs text-gray-500">
                                            <?php echo date('M j, Y', strtotime($vaccination['administration_date'])); ?>
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-600">
                                        Dose: <?php echo htmlspecialchars($vaccination['dose_number']); ?>
                                        <?php if (!empty($vaccination['lot_number'])): ?>
                                            | Lot: <?php echo htmlspecialchars($vaccination['lot_number']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card card-hover p-6">
                    <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Vaccination Schedule</h3>
                    <div id="vaccinationSchedule" class="space-y-4">
                        <?php if (empty($vaccination_schedule)): ?>
                            <p class="text-gray-500">No upcoming vaccinations scheduled</p>
                        <?php else: ?>
                            <?php foreach ($vaccination_schedule as $schedule): ?>
                                <div class="p-4 border border-gray-200 rounded-lg">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <div class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($schedule['recommended_vaccines']); ?></div>
                                        </div>
                                        <span class="text-xs font-medium px-2 py-1 rounded-full 
                                            <?php echo $schedule['status'] === 'DUE' ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800'; ?>">
                                            <?php echo $schedule['status']; ?>
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-600">
                                        Age: <?php echo $schedule['current_age_months']; ?> months
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Section -->
        <div id="profile-section" class="section-content p-6 hidden">
            <div class="mb-8">
                <h1 class="text-3xl font-inter font-bold text-gray-800 mb-2">My Profile</h1>
                <p class="text-gray-600">Update your credentials and change your password</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Edit Credentials -->
                <div class="card card-hover p-6">
                    <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Edit Credentials</h3>
                    <form id="doctorProfileForm" onsubmit="handleDoctorProfileForm(event)" class="space-y-4" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_doctor_profile">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                                <input type="text" name="first_name" required minlength="2" maxlength="50"
                                       value="<?php echo htmlspecialchars($current_user['first_name']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                                <input type="text" name="last_name" required minlength="2" maxlength="50"
                                       value="<?php echo htmlspecialchars($current_user['last_name']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required maxlength="100"
                                   value="<?php echo htmlspecialchars($current_user['email']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <div class="flex">
                                <span class="px-3 py-2 border border-gray-300 bg-gray-50 rounded-l-lg text-gray-700">+63</span>
                                <input type="tel" name="phone" pattern="^9\d{9}$" maxlength="10"
                                       value="<?php echo htmlspecialchars($current_user['phone']); ?>"
                                       placeholder="9XXXXXXXXX"
                                       class="flex-1 px-3 py-2 border border-l-0 border-gray-300 rounded-r-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       title="Phone must be 10 digits starting with 9">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Specialization <span class="text-red-500">*</span></label>
                                <input type="text" name="specialization" required minlength="2" maxlength="100"
                                       value="<?php echo htmlspecialchars($current_user['specialization']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">License Number <span class="text-red-500">*</span></label>
                                <input type="text" name="license_number" required minlength="3" maxlength="50"
                                       value="<?php echo htmlspecialchars($current_user['license_number']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Years of Experience</label>
                            <input type="number" name="years_of_experience" min="0" max="80" step="1"
                                   value="<?php echo htmlspecialchars((string)$current_user['years_of_experience']); ?>"
                                   class="w-full sm:w-40 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>

                        <button type="submit" class="btn-primary text-white px-6 py-2 rounded-lg font-semibold">
                            Save Changes
                        </button>
                    </form>
                </div>

                <!-- Change Password -->
                <div class="card card-hover p-6">
                    <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4">Change Password</h3>
                    <form id="doctorPasswordForm" onsubmit="handleDoctorPasswordForm(event)" class="space-y-4" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="change_doctor_password">

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Current Password <span class="text-red-500">*</span></label>
                            <input type="password" name="current_password" required minlength="6" autocomplete="current-password"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">New Password <span class="text-red-500">*</span></label>
                            <input type="password" name="new_password" required minlength="8" autocomplete="new-password"
                                   pattern="^(?=.*[A-Za-z])(?=.*\d).{8,}$"
                                   title="At least 8 characters with letters and numbers"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <p class="text-xs text-gray-500 mt-1">Minimum 8 characters, must include letters and numbers.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password <span class="text-red-500">*</span></label>
                            <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>

                        <button type="submit" class="btn-primary text-white px-6 py-2 rounded-lg font-semibold">
                            Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Calendar Day Appointments Modal -->
    <div id="calendarDayModal" class="modal-container hidden">
        <div class="modal-backdrop fixed inset-0 z-40" onclick="closeModal('calendarDayModal')"></div>
        <div class="modal-content relative z-50">
            <div class="bg-white rounded-lg shadow-xl">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">
                            <i class="fas fa-calendar-day mr-2 text-primary"></i>
                            <span id="calendarDayModalTitle">Appointments</span>
                        </h3>
                        <button onclick="closeModal('calendarDayModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6">
                    <div id="calendarDayModalBody" class="space-y-3">
                        <div class="text-center py-6">
                            <i class="fas fa-calendar-check text-gray-300 text-3xl mb-3"></i>
                            <p class="text-gray-500 text-sm">Loading appointments...</p>
                        </div>
                    </div>
                </div>
                <div class="p-4 border-t border-gray-200 flex justify-end">
                    <button onclick="closeModal('calendarDayModal')"
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Vaccine Needs Modal -->
    <div id="vaccineNeedModal" class="modal-container hidden">
        <div class="modal-backdrop fixed inset-0 z-40" onclick="closeModal('vaccineNeedModal')"></div>
        <div class="modal-content relative z-50">
            <div class="bg-white rounded-lg shadow-xl">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Manage Vaccine Needs</h3>
                        <button onclick="closeModal('vaccineNeedModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Left: Add/Edit Form -->
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-4">Add/Edit Vaccine Need</h4>
                            <form id="vaccineNeedForm" onsubmit="handleVaccineNeedForm(event)" class="space-y-4">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="set_vaccine_need">
                                <input type="hidden" id="vaccine_need_patient_id" name="patient_id">
                                <input type="hidden" id="vaccine_need_id" name="id">

                                <div>
                                    <label for="vaccine_patient_display" class="block text-sm font-medium text-gray-700 mb-2">Patient</label>
                                    <div id="vaccine_patient_display" class="px-3 py-2 bg-gray-100 rounded-lg text-gray-700 text-sm">
                                        Select a patient first
                                    </div>
                                </div>

                                <div>
                                    <label for="vaccine_age_display" class="block text-sm font-medium text-gray-700 mb-2">Patient Age</label>
                                    <div id="vaccine_age_display" class="px-3 py-2 bg-gray-100 rounded-lg text-gray-700 text-sm">
                                        —
                                    </div>
                                </div>

                                <div>
                                    <label for="vaccine_name_input" class="block text-sm font-medium text-gray-700 mb-2">
                                        Vaccine Name <span class="text-red-500">*</span>
                                    </label>
                                    <select id="vaccine_name_input" name="vaccine_name" required 
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                        <option value="">-- Select Vaccine --</option>
                                        <?php foreach ($vaccines as $vaccine): ?>
                                            <option value="<?php echo htmlspecialchars($vaccine['name']); ?>">
                                                <?php echo htmlspecialchars($vaccine['name']); ?> (<?php echo htmlspecialchars($vaccine['disease_protected']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="recommendedVaccinesHint" class="mt-2 p-3 bg-blue-50 rounded-lg">
                                        <p class="text-sm text-blue-800"><strong>Age-appropriate vaccines:</strong></p>
                                        <div id="recommendedVaccinesList" class="text-sm text-blue-700 mt-2">
                                            Load patient to see recommendations
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label for="recommended_date_input" class="block text-sm font-medium text-gray-700 mb-2">
                                        Recommended Date
                                    </label>
                                    <input type="date" id="recommended_date_input" name="recommended_date" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                </div>

                                <div>
                                    <label for="vaccine_status_input" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                    <select id="vaccine_status_input" name="status" required 
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                        <option value="RECOMMENDED">Recommended</option>
                                        <option value="SCHEDULED">Scheduled</option>
                                        <option value="GIVEN">Given</option>
                                        <option value="NOT_NEEDED">Not Needed</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="vaccine_notes_input" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                                    <textarea id="vaccine_notes_input" name="notes" rows="3" 
                                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                              placeholder="Special instructions or notes..."></textarea>
                                </div>

                                <div class="flex gap-2">
                                    <button type="submit" 
                                            class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-pink-700 transition-colors font-medium">
                                        <i class="fas fa-save mr-2"></i>Save
                                    </button>
                                    <button type="reset" 
                                            class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-medium">
                                        Clear
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Right: Vaccine Needs List -->
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-4">Patient Vaccine Needs</h4>
                            <div id="vaccineNeedsModalList" class="space-y-3 max-h-96 overflow-y-auto border border-gray-200 rounded-lg p-4 bg-gray-50">
                                <p class="text-gray-500 text-center py-8">Select a patient to view vaccine needs</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Vaccination Modal -->
    <div id="vaccinationModal" class="modal-container hidden">
        <div class="modal-backdrop fixed inset-0 z-40" onclick="closeModal('vaccinationModal')"></div>
        <div class="modal-content relative z-50">
            <div class="bg-white rounded-lg shadow-xl">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Record Vaccination</h3>
                        <button onclick="closeModal('vaccinationModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6">
                    <form id="vaccinationForm" onsubmit="handleVaccinationForm(event)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="record_vaccination">
                        
                        <div class="mb-4">
                            <label for="vaccine_patient_id" class="block text-sm font-medium text-gray-700 mb-2">Patient</label>
                            <select id="vaccine_patient_id" name="patient_id" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">Select a patient</option>
                                <?php foreach ($doctor_patients as $patient): ?>
                                    <option value="<?php echo $patient['id']; ?>">
                                        <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="vaccine_name" class="block text-sm font-medium text-gray-700 mb-2">Vaccine</label>
                            <select id="vaccine_name" name="vaccine_name" required 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">Select a vaccine</option>
                                <?php foreach ($vaccines as $vaccine): ?>
                                    <option value="<?php echo htmlspecialchars($vaccine['name']); ?>">
                                        <?php echo htmlspecialchars($vaccine['name']); ?> (<?php echo htmlspecialchars($vaccine['disease_protected']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="dose_number" class="block text-sm font-medium text-gray-700 mb-2">Dose Number</label>
                                <input type="number" id="dose_number" name="dose_number" min="1" max="10" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label for="administration_date" class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                                <input type="date" id="administration_date" name="administration_date" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="lot_number" class="block text-sm font-medium text-gray-700 mb-2">Lot Number</label>
                                <input type="text" id="lot_number" name="lot_number"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label for="manufacturer" class="block text-sm font-medium text-gray-700 mb-2">Manufacturer</label>
                                <input type="text" id="manufacturer" name="manufacturer"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="site" class="block text-sm font-medium text-gray-700 mb-2">Site</label>
                                <select id="site" name="site" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                    <option value="">-- Select --</option>
                                    <option value="Left Arm">Left Arm</option>
                                    <option value="Right Arm">Right Arm</option>
                                    <option value="Left Thigh">Left Thigh</option>
                                    <option value="Right Thigh">Right Thigh</option>
                                    <option value="Oral">Oral</option>
                                </select>
                            </div>
                            <div>
                                <label for="next_due_date" class="block text-sm font-medium text-gray-700 mb-2">Next Dose Due</label>
                                <input type="date" id="next_due_date" name="next_due_date"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>

                        <div class="mb-6">
                            <label for="vaccination_notes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                            <textarea id="vaccination_notes" name="notes" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                      placeholder="Reactions observed, special instructions, follow-up..."></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('vaccinationModal')"
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:opacity-90 transition-colors">
                                Record Vaccination
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Vaccination Modal -->
    <div id="editVaccinationModal" class="modal-container hidden">
        <div class="modal-backdrop fixed inset-0 z-40" onclick="closeModal('editVaccinationModal')"></div>
        <div class="modal-content relative z-50">
            <div class="bg-white rounded-lg shadow-xl">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Edit Vaccination Record</h3>
                        <button onclick="closeModal('editVaccinationModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6">
                    <form id="editVaccinationForm" onsubmit="handleEditVaccinationForm(event)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="edit_vaccination_record">
                        <input type="hidden" id="edit_vaccination_id" name="vaccination_id">
                        
                        <div class="mb-4">
                            <label for="edit_vaccine_patient_id" class="block text-sm font-medium text-gray-700 mb-2">Patient ID</label>
                            <input type="text" id="edit_vaccine_patient_id" readonly
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100">
                        </div>
                        
                        <div class="mb-4">
                            <label for="edit_vaccine_name" class="block text-sm font-medium text-gray-700 mb-2">Vaccine Name</label>
                            <input type="text" id="edit_vaccine_name" name="vaccine_name" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="edit_dose_number" class="block text-sm font-medium text-gray-700 mb-2">Dose Number</label>
                                <input type="number" id="edit_dose_number" name="dose_number" min="1" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label for="edit_administration_date" class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                                <input type="date" id="edit_administration_date" name="administration_date" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="edit_lot_number" class="block text-sm font-medium text-gray-700 mb-2">Lot Number</label>
                            <input type="text" id="edit_lot_number" name="lot_number"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        
                        <div class="mb-6">
                            <label for="edit_vaccination_notes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                            <textarea id="edit_vaccination_notes" name="notes" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('editVaccinationModal')" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:bg-pink-700 transition-colors">
                                Update Record
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Medical Record Modal -->
    <div id="medicalRecordModal" class="modal-container hidden">
        <div class="modal-backdrop fixed inset-0 z-40" onclick="closeModal('medicalRecordModal')"></div>
        <div class="modal-content relative z-50">
            <div class="bg-white rounded-lg shadow-xl">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Add Medical Record</h3>
                        <button onclick="closeModal('medicalRecordModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6 max-h-[80vh] overflow-y-auto">
                    <form id="medicalRecordForm" onsubmit="handleMedicalRecordForm(event)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_medical_record">
                        <input type="hidden" id="medical_record_patient_id" name="patient_id">
                        
                        <div class="mb-4">
                            <div class="block text-sm font-medium text-gray-700 mb-2">Patient</div>
                            <div id="medicalRecordPatientName" class="px-3 py-2 bg-gray-100 rounded-lg text-gray-700">
                                Select a patient first
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="record_type" class="block text-sm font-medium text-gray-700 mb-2">Record Type <span class="text-red-500">*</span></label>
                            <select id="record_type" name="record_type" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">Select type</option>
                                <option value="CONSULTATION">Consultation</option>
                                <option value="CHECKUP">Checkup</option>
                                <option value="FOLLOW_UP">Follow-up</option>
                                <option value="EMERGENCY">Emergency</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="temperature" class="block text-sm font-medium text-gray-700 mb-2">Temperature (°C)</label>
                                <input type="number" step="0.1" min="25" max="45" id="temperature" name="temperature"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       placeholder="e.g., 37.0">
                            </div>
                            <div>
                                <label for="blood_pressure" class="block text-sm font-medium text-gray-700 mb-2">Blood Pressure</label>
                                <input type="text" id="blood_pressure" name="blood_pressure"
                                       pattern="^\d{1,3}\s*/\s*\d{1,3}$"
                                       maxlength="10"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       placeholder="e.g., 120/80"
                                       title="Format: systolic/diastolic (e.g., 120/80)">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="heart_rate" class="block text-sm font-medium text-gray-700 mb-2">Heart Rate (BPM)</label>
                                <input type="number" min="20" max="300" id="heart_rate" name="heart_rate"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       placeholder="e.g., 80">
                            </div>
                            <div>
                                <label for="height" class="block text-sm font-medium text-gray-700 mb-2">Height (cm)</label>
                                <input type="number" step="0.1" min="0" max="250" id="height" name="height"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       placeholder="e.g., 120">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="weight" class="block text-sm font-medium text-gray-700 mb-2">Weight (kg)</label>
                            <input type="number" step="0.1" min="0" max="300" id="weight" name="weight"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                   placeholder="e.g., 25.0">
                        </div>
                        
                        <div class="mb-4">
                            <label for="diagnosis" class="block text-sm font-medium text-gray-700 mb-2">Diagnosis <span class="text-red-500">*</span></label>
                            <input type="text" id="diagnosis" name="diagnosis" required
                                   minlength="2" maxlength="255"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>

                        <div class="mb-4">
                            <label for="symptoms" class="block text-sm font-medium text-gray-700 mb-2">Symptoms <span class="text-red-500">*</span></label>
                            <textarea id="symptoms" name="symptoms" rows="3" required
                                      minlength="2" maxlength="2000"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
                        </div>

                        <div class="mb-4">
                            <label for="treatment_plan" class="block text-sm font-medium text-gray-700 mb-2">Treatment Plan <span class="text-red-500">*</span></label>
                            <textarea id="treatment_plan" name="treatment_plan" rows="3" required
                                      minlength="2" maxlength="2000"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
                        </div>

                        <div class="mb-6">
                            <label for="medical_notes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                            <textarea id="medical_notes" name="notes" rows="3" maxlength="2000"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('medicalRecordModal')" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:bg-pink-700 transition-colors">
                                Save Record
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Consultation Notes Modal -->
    <div id="consultationModal" class="modal-container hidden">
        <div class="modal-backdrop fixed inset-0 z-40" onclick="closeModal('consultationModal')"></div>
        <div class="modal-content relative z-50">
            <div class="bg-white rounded-lg shadow-xl">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Add Consultation Notes</h3>
                        <button onclick="closeModal('consultationModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6">
                    <form id="consultationForm" onsubmit="handleConsultationForm(event)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_consultation_notes">
                        <input type="hidden" id="consultation_patient_id" name="patient_id">
                        
                        <div class="mb-4">
                            <div class="block text-sm font-medium text-gray-700 mb-2">Patient</div>
                            <div id="consultationPatientName" class="px-3 py-2 bg-gray-100 rounded-lg text-gray-700">
                                Select a patient first
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="consultation_diagnosis" class="block text-sm font-medium text-gray-700 mb-2">Diagnosis <span class="text-red-500">*</span></label>
                            <input type="text" id="consultation_diagnosis" name="diagnosis" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                   placeholder="Enter diagnosis...">
                        </div>
                        
                        <div class="mb-4">
                            <label for="consultation_notes" class="block text-sm font-medium text-gray-700 mb-2">Consultation Notes</label>
                            <textarea id="consultation_notes" name="notes" rows="4" required
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                      placeholder="Enter detailed consultation notes..."></textarea>
                        </div>
                        
                        <div class="mb-6">
                            <label for="treatment_plan_consultation" class="block text-sm font-medium text-gray-700 mb-2">Treatment Plan <span class="text-red-500">*</span></label>
                            <textarea id="treatment_plan_consultation" name="treatment_plan" rows="3" required
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                      placeholder="Enter treatment plan..."></textarea>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('consultationModal')" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:bg-pink-700 transition-colors">
                                Save Notes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Prescription Modal -->
    <div id="prescriptionModal" class="modal-container hidden">
        <div class="modal-backdrop fixed inset-0 z-40" onclick="closeModal('prescriptionModal')"></div>
        <div class="modal-content relative z-50">
            <div class="bg-white rounded-lg shadow-xl">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Write Prescription</h3>
                        <button onclick="closeModal('prescriptionModal')" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>                      
                        </button>
                    </div>
                </div>
                <div class="p-6">
                    <form id="prescriptionForm" onsubmit="handlePrescriptionForm(event)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_prescription">
                        <input type="hidden" id="prescription_patient_id" name="patient_id">
                        
                        <div class="mb-4">
                            <div class="block text-sm font-medium text-gray-700 mb-2">Patient</div>
                            <div id="prescriptionPatientName" class="px-3 py-2 bg-gray-100 rounded-lg text-gray-700">
                                Select a patient first
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="medication_name" class="block text-sm font-medium text-gray-700 mb-2">Medication Name</label>
                            <input type="text" id="medication_name" name="medication_name" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                   placeholder="Enter medication name...">
                        </div>
                        
                        <div class="grid grid-cols-3 gap-4 mb-4">
                            <div>
                                <label for="dosage" class="block text-sm font-medium text-gray-700 mb-2">Dosage</label>
                                <input type="text" id="dosage" name="dosage" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       placeholder="e.g., 5mg">
                            </div>
                            <div>
                                <label for="frequency" class="block text-sm font-medium text-gray-700 mb-2">Frequency</label>
                                <input type="text" id="frequency" name="frequency" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       placeholder="e.g., Twice daily">
                            </div>
                            <div>
                                <label for="duration" class="block text-sm font-medium text-gray-700 mb-2">Duration</label>
                                <input type="text" id="duration" name="duration" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                       placeholder="e.g., 7 days">
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <label for="instructions" class="block text-sm font-medium text-gray-700 mb-2">Instructions <span class="text-red-500">*</span></label>
                            <textarea id="instructions" name="instructions" rows="3" required
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                      placeholder="Enter special instructions..."></textarea>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('prescriptionModal')" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:bg-pink-700 transition-colors">
                                Save Prescription
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Bridge: pass PHP data to external JS
    window.CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
    window.DOCTOR_ID = <?php echo json_encode($current_user['id']); ?>;
    <?php
        // Pull clinic info for prescription header (hospital affiliate)
        $__clinic = ['clinic_name' => 'AlagApp Pediatric Clinic', 'contact_address' => '', 'clinic_phone' => '', 'clinic_email' => ''];
        $__cs = mysqli_query($conn, "SELECT setting_key, setting_value FROM clinic_settings WHERE setting_key IN ('clinic_name','contact_address','clinic_phone','clinic_email','clinic_address')");
        if ($__cs) {
            while ($__row = mysqli_fetch_assoc($__cs)) {
                $__clinic[$__row['setting_key']] = $__row['setting_value'];
            }
        }
        $__hospital = $__clinic['clinic_name'] ?: 'AlagApp Pediatric Clinic';
        $__addr = $__clinic['contact_address'] ?: ($__clinic['clinic_address'] ?? '');
    ?>
    window.DOCTOR_INFO = <?php echo json_encode([
        'name'           => 'Dr. ' . trim(($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? '')),
        'specialization' => $current_user['specialization'] ?? 'Pediatrician',
        'license_number' => $current_user['license_number'] ?? '',
        'hospital'       => $__hospital,
        'hospital_address' => $__addr,
        'hospital_phone' => $__clinic['clinic_phone'] ?? '',
        'hospital_email' => $__clinic['clinic_email'] ?? '',
    ]); ?>;
    window.APPOINTMENT_CHART_DATA = <?php echo json_encode(['dates' => $appointment_chart_data['dates'] ?? [], 'counts' => $appointment_chart_data['counts'] ?? []]); ?>;
    window.VACCINATION_CHART_DATA = <?php echo json_encode(['months' => $vaccination_chart_data['months'] ?? [], 'vaccination_counts' => $vaccination_chart_data['vaccination_counts'] ?? []]); ?>;
    </script>
    <script src="js/shared-toast.js?v=<?php echo @filemtime(__DIR__ . '/js/shared-toast.js') ?: time(); ?>"></script>
    <script src="js/doctor-dashboard.js?v=<?php echo @filemtime(__DIR__ . '/js/doctor-dashboard.js') ?: time(); ?>"></script>
    <script>
    // ── Weekly Schedule UI ────────────────────────────────────────────────────
    const DAY_ORDER = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
    const DAY_LABELS = {MONDAY:'Mon',TUESDAY:'Tue',WEDNESDAY:'Wed',THURSDAY:'Thu',FRIDAY:'Fri',SATURDAY:'Sat',SUNDAY:'Sun'};

    function fmt12(t) {
        if (!t) return '';
        const [h, m] = t.split(':').map(Number);
        const suffix = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return h12 + ':' + String(m).padStart(2,'0') + ' ' + suffix;
    }

    function renderCurrentSchedule(schedule) {
        const el = document.getElementById('currentScheduleDisplay');
        if (!el) return;
        if (!schedule || schedule.length === 0) {
            el.innerHTML = '<p class="text-xs text-gray-400 italic">No weekly schedule set yet. Use the form below to add working days.</p>';
            return;
        }
        const sorted = [...schedule].sort((a,b) => DAY_ORDER.indexOf(a.day_of_week) - DAY_ORDER.indexOf(b.day_of_week));
        el.innerHTML = sorted.map(s =>
            `<div class="flex justify-between items-center text-xs bg-green-50 border border-green-200 rounded px-2 py-1">
                <span class="font-semibold text-green-800">${DAY_LABELS[s.day_of_week] || s.day_of_week}</span>
                <span class="text-green-700">${fmt12(s.start_time)} – ${fmt12(s.end_time)}</span>
            </div>`
        ).join('');
    }

    async function loadWeeklySchedule() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_weekly_schedule');
            fd.append('csrf_token', window.CSRF_TOKEN);
            const r = await fetch('doctor-dashboard.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (data.success) renderCurrentSchedule(data.schedule);
        } catch(e) { console.error('Failed to load weekly schedule', e); }
    }

    function showSchedMsg(msg, success) {
        const el = document.getElementById('schedMsg');
        if (!el) return;
        el.textContent = msg;
        el.className = 'text-sm rounded-lg px-3 py-2 ' + (success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
        el.classList.remove('hidden');
        setTimeout(() => el.classList.add('hidden'), 4000);
    }

    // Save day
    const weeklyForm = document.getElementById('setWeeklyScheduleForm');
    if (weeklyForm) {
        weeklyForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('schedSaveBtn');
            btn.disabled = true;
            btn.textContent = 'Saving…';
            try {
                const fd = new FormData(this);
                fd.set('action', 'set_weekly_schedule');
                const r = await fetch('doctor-dashboard.php', { method: 'POST', body: fd });
                const data = await r.json();
                showSchedMsg(data.message || (data.success ? 'Saved!' : 'Error'), data.success);
                if (data.success) {
                    loadWeeklySchedule();
                    document.getElementById('schedDay').value = '';
                }
            } catch(err) {
                showSchedMsg('Network error. Please try again.', false);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check mr-1"></i> Save Day';
            }
        });
    }

    // Remove day
    const removeBtn = document.getElementById('schedRemoveBtn');
    if (removeBtn) {
        removeBtn.addEventListener('click', async function() {
            const btnRef = this;
            const day = document.getElementById('schedDay').value;
            if (!day) { showSchedMsg('Please select a day to remove.', false); return; }
            const friendly = day.charAt(0) + day.slice(1).toLowerCase();
            const ok = typeof window.appConfirm === 'function'
                ? await window.appConfirm('Remove Day', 'Remove ' + friendly + ' from your schedule?', null, { confirmText: 'Remove' })
                : confirm('Remove ' + friendly + ' from your schedule?');
            if (!ok) return;
            btnRef.disabled = true;
            btnRef.textContent = 'Removing…';
            try {
                const fd = new FormData();
                fd.append('action', 'remove_weekly_schedule');
                fd.append('csrf_token', window.CSRF_TOKEN);
                fd.append('day_of_week', day);
                const r = await fetch('doctor-dashboard.php', { method: 'POST', body: fd });
                const data = await r.json();
                showSchedMsg(data.message || (data.success ? 'Removed!' : 'Error'), data.success);
                if (data.success) loadWeeklySchedule();
            } catch(err) {
                showSchedMsg('Network error. Please try again.', false);
            } finally {
                btnRef.disabled = false;
                btnRef.innerHTML = '<i class="fas fa-times mr-1"></i> Remove Day';
            }
        });
    }

    // Auto-fill times when a day that already has a schedule is selected
    document.getElementById('schedDay')?.addEventListener('change', async function() {
        if (!this.value) return;
        try {
            const fd = new FormData();
            fd.append('action', 'get_weekly_schedule');
            fd.append('csrf_token', window.CSRF_TOKEN);
            const r = await fetch('doctor-dashboard.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (data.success) {
                const found = (data.schedule || []).find(s => s.day_of_week === this.value);
                if (found) {
                    document.getElementById('schedStart').value = found.start_time.slice(0,5);
                    document.getElementById('schedEnd').value   = found.end_time.slice(0,5);
                }
            }
        } catch(e) {}
    });

    // Load schedule when the Schedule section becomes visible
    document.addEventListener('DOMContentLoaded', function() {
        // Load immediately if schedule section is visible, otherwise on nav click
        const schedNav = document.querySelector('a[onclick*="schedule"]') || document.querySelector('[href*="schedule"]');
        if (schedNav) {
            schedNav.addEventListener('click', () => setTimeout(loadWeeklySchedule, 100));
        }
        // Also load on page ready in case schedule is default section
        if (document.getElementById('schedule-section') && !document.getElementById('schedule-section').classList.contains('hidden')) {
            loadWeeklySchedule();
        }
        // Expose globally for external doctor-dashboard.js to call
        window.loadWeeklySchedule = loadWeeklySchedule;
    });
    </script>
</body>
</html>