<?php
// db_connect.php

// Database configuration for XAMPP (default settings)
$host = 'localhost';
$username = 'u854009390_sllsggt';
$password = 'gOH6jc!sH%';
$database = 'u854009390_sllsggt';

// Create connection
$conn = mysqli_connect($host, $username, $password, $database);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to utf8
mysqli_set_charset($conn, "utf8");

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function to sanitize input
function sanitize_input($data) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($data));
}

// Validate CSRF token
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Check if user is logged in and is an admin or superadmin
function check_admin_auth() {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', ['ADMIN', 'SUPERADMIN'], true)) {
        header('Location: index.php');
        exit();
    }
}

// Get current user data
function get_current_session_user() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'first_name' => $_SESSION['first_name'] ?? '',
            'last_name' => $_SESSION['last_name'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'user_type' => $_SESSION['user_type'] ?? ''
        ];
    }
    return null;
}

// Check if user is logged in (for general pages)
function check_user_auth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit();
    }
}

// Get user by ID
function get_user_by_id($conn, $user_id) {
    $query = "SELECT * FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

// Get patient details
function get_patient_details($conn, $patient_id, $doctor_id = null) {
    $query = "SELECT p.*, u.first_name as parent_first, u.last_name as parent_last, u.email as parent_email 
              FROM patients p 
              JOIN users u ON p.parent_id = u.id 
              WHERE p.id = ?";
    
    if ($doctor_id) {
        $query .= " AND EXISTS (
            SELECT 1 FROM appointments a 
            WHERE a.patient_id = p.id AND a.doctor_id = ?
        )";
    }
    
    $stmt = mysqli_prepare($conn, $query);
    
    if ($doctor_id) {
        mysqli_stmt_bind_param($stmt, "ii", $patient_id, $doctor_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $patient_id);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

// Get patient appointments
function get_patient_appointments($conn, $patient_id, $doctor_id = null) {
    $query = "SELECT a.*, 
              d.first_name as doctor_first, d.last_name as doctor_last,
              a.type as service_name
              FROM appointments a
              JOIN users d ON a.doctor_id = d.id
              WHERE a.patient_id = ?";
    
    if ($doctor_id) {
        $query .= " AND a.doctor_id = ?";
    }
    
    $query .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    
    if ($doctor_id) {
        mysqli_stmt_bind_param($stmt, "ii", $patient_id, $doctor_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $patient_id);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $appointments = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $appointments[] = $row;
    }
    
    return $appointments;
}

// Get patient prescriptions
function get_patient_prescriptions($conn, $patient_id, $doctor_id = null) {
    $query = "SELECT pr.*, 
              d.first_name as doctor_first, d.last_name as doctor_last
              FROM prescriptions pr
              JOIN users d ON pr.doctor_id = d.id
              WHERE pr.patient_id = ?";
    
    if ($doctor_id) {
        $query .= " AND pr.doctor_id = ?";
    }
    
    $query .= " ORDER BY pr.prescription_date DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    
    if ($doctor_id) {
        mysqli_stmt_bind_param($stmt, "ii", $patient_id, $doctor_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $patient_id);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $prescriptions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $prescriptions[] = $row;
    }
    
    return $prescriptions;
}

// Get patient consultation notes
function get_patient_consultation_notes($conn, $patient_id, $doctor_id = null) {
    $query = "SELECT cn.*, 
              d.first_name as doctor_first, d.last_name as doctor_last
              FROM consultation_notes cn
              JOIN users d ON cn.doctor_id = d.id
              WHERE cn.patient_id = ?";
    
    if ($doctor_id) {
        $query .= " AND cn.doctor_id = ?";
    }
    
    $query .= " ORDER BY cn.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    
    if ($doctor_id) {
        mysqli_stmt_bind_param($stmt, "ii", $patient_id, $doctor_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $patient_id);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $notes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $notes[] = $row;
    }
    
    return $notes;
}

// Get patient vaccination history
function get_patient_vaccination_history($conn, $patient_id, $doctor_id = null) {
    $query = "SELECT vr.*, 
              u.first_name as doctor_first, u.last_name as doctor_last
              FROM vaccination_records vr
              LEFT JOIN users u ON vr.administered_by = u.id
              WHERE vr.patient_id = ?";
    
    if ($doctor_id) {
        $query .= " AND (vr.administered_by = ? OR vr.administered_by IS NULL)";
    }
    
    $query .= " ORDER BY vr.administration_date DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    
    if ($doctor_id) {
        mysqli_stmt_bind_param($stmt, "ii", $patient_id, $doctor_id);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $patient_id);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $vaccinations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $vaccinations[] = $row;
    }
    
    return $vaccinations;
}

// Export patient data function
function exportPatientData($conn, $patient_id, $doctor_id, $format = 'csv') {
    $patient_id = intval($patient_id);
    $doctor_id = intval($doctor_id);
    
    try {
        // Verify patient belongs to doctor
        $verify_query = "SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? LIMIT 1";
        $verify_stmt = mysqli_prepare($conn, $verify_query);
        mysqli_stmt_bind_param($verify_stmt, "ii", $patient_id, $doctor_id);
        mysqli_stmt_execute($verify_stmt);
        $result = mysqli_stmt_get_result($verify_stmt);
        
        if (mysqli_num_rows($result) === 0) {
            return ['success' => false, 'message' => 'Patient not found or not authorized'];
        }
        
        // Get patient data
        $patient_data = get_patient_details($conn, $patient_id, $doctor_id);
        
        if (!$patient_data) {
            return ['success' => false, 'message' => 'Patient data not found'];
        }
        
        // Get all related data
        $appointments = get_patient_appointments($conn, $patient_id, $doctor_id);
        $prescriptions = get_patient_prescriptions($conn, $patient_id, $doctor_id);
        $consultation_notes = get_patient_consultation_notes($conn, $patient_id, $doctor_id);
        $vaccination_history = get_patient_vaccination_history($conn, $patient_id, $doctor_id);
        
        // Prepare data for export
        $export_data = [
            'patient' => $patient_data,
            'appointments' => $appointments,
            'prescriptions' => $prescriptions,
            'consultation_notes' => $consultation_notes,
            'vaccination_history' => $vaccination_history
        ];
        
        // Log the export activity
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'EXPORT', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $details = "Exported patient data for ID: $patient_id";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        mysqli_stmt_bind_param($log_stmt, "iss", $doctor_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);
        
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

/**
 * Create an in-app notification (bell) entry. Best-effort: failures are
 * logged but not surfaced to callers — notifications are not load-bearing
 * for the underlying business action.
 */
function create_notification($conn, $user_id, $title, $message, $type = 'SYSTEM', $related_type = null, $related_id = null, $channel = 'IN_APP') {
    $user_id = intval($user_id);
    if ($user_id <= 0) return false;
    $sql = "INSERT INTO notifications (user_id, title, message, type, channel, related_type, related_id, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('create_notification prepare failed: ' . mysqli_error($conn));
        return false;
    }
    $rid = $related_id !== null ? intval($related_id) : null;
    mysqli_stmt_bind_param($stmt, "issssss", $user_id, $title, $message, $type, $channel, $related_type, $rid);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('create_notification exec failed: ' . mysqli_stmt_error($stmt));
        return false;
    }
    return mysqli_insert_id($conn);
}

/**
 * Send an appointment notification: writes an in-app bell entry AND sends
 * an email to the recipient. Safe to call from any dashboard.
 */
function send_appointment_notification($conn, $user_id, $title, $message, $appointment_id = null) {
    // Bell entry
    create_notification($conn, $user_id, $title, $message, 'APPOINTMENT', 'appointment', $appointment_id, 'ALL');

    // Email
    $stmt = mysqli_prepare($conn, "SELECT email, first_name, last_name FROM users WHERE id = ?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $u = mysqli_fetch_assoc($res);
    if (!$u || empty($u['email'])) return false;

    if (!function_exists('smtp_send_email')) {
        @include_once __DIR__ . '/smtp_mailer.php';
    }
    if (!function_exists('smtp_send_email')) return false;

    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    $subject = '[AlagApp Clinic] ' . $title;
    $safeMsg = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#fdf2f8;font-family:Arial,sans-serif;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#fdf2f8;padding:32px 0;"><tr><td align="center">'
        . '<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;border:1px solid #f0c3d4;overflow:hidden;">'
        . '<tr><td style="background:linear-gradient(135deg,#d03664,#ff7aa3);padding:24px 28px;">'
        . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700;">AlagApp Clinic</h1>'
        . '<p style="margin:6px 0 0;color:#ffe0ee;font-size:13px;">Appointment Update</p>'
        . '</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 12px;color:#333;font-size:15px;">Hi ' . htmlspecialchars($name ?: 'there', ENT_QUOTES) . ',</p>'
        . '<h2 style="margin:0 0 12px;color:#d03664;font-size:18px;">' . htmlspecialchars($title, ENT_QUOTES) . '</h2>'
        . '<p style="margin:0 0 20px;color:#555;font-size:14px;line-height:1.6;">' . $safeMsg . '</p>'
        . '<p style="margin:0;color:#999;font-size:12px;">You can view the full details when you log in to your AlagApp Clinic account.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
    return smtp_send_email($u['email'], $name, $subject, $html, $message);
}

/**
 * Convenience: return all admin/superadmin user IDs (used to notify clinic
 * staff about new appointments / cancellation requests).
 */
function get_admin_user_ids($conn) {
    $ids = [];
    $res = mysqli_query($conn, "SELECT id FROM users WHERE user_type IN ('ADMIN','SUPERADMIN') AND status = 'active'");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) { $ids[] = intval($r['id']); }
    }
    return $ids;
}

// Get all doctors
function get_all_doctors($conn) {
    $query = "SELECT id, first_name, last_name, specialization FROM users WHERE user_type IN ('DOCTOR', 'DOCTOR_OWNER') AND status = 'active' ORDER BY last_name, first_name";
    $result = mysqli_query($conn, $query);
    
    $doctors = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $doctors[] = $row;
        }
    }
    
    return $doctors;
}
?>