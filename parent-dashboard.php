<?php
require 'db_connect.php';
require_once __DIR__ . '/smtp_mailer.php';



// AJAX endpoint to return medical records for a child (used by the Medical Records modal).
// Request: parent-dashboard.php?action=get_medical_records&child_id=NN
if (isset($_GET['action']) && $_GET['action'] === 'get_medical_records' && isset($_GET['child_id'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Ensure user is logged in and is the parent
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'PARENT') {
        echo json_encode(['success' => false, 'message' => 'Not authorized']);
        exit();
    }

    $parent_id = intval($_SESSION['user_id']);
    $child_id = intval($_GET['child_id']);

    // Verify child belongs to parent
    $check_query = "SELECT id FROM patients WHERE id = ? AND parent_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $child_id, $parent_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($check_result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Child not found or access denied']);
        exit();
    }

    // Load real data using helper functions from db_connect.php
    // Inside AJAX branch handling get_medical_records (do NOT reference $children)
    $consultations = get_patient_consultation_notes($conn, $child_id);
    $prescriptions = get_patient_prescriptions($conn, $child_id);
    $vaccinations = get_patient_vaccination_history($conn, $child_id);

    echo json_encode([
        'success' => true,
        'consultations' => $consultations,
        'prescriptions' => $prescriptions,
        'vaccinations' => $vaccinations
    ]);
    exit();
}

// AJAX endpoint: get available dates & times for a doctor (used by booking modal).
// Request: parent-dashboard.php?action=get_available_slots&doctor_id=NN
if (isset($_GET['action']) && $_GET['action'] === 'get_available_slots' && isset($_GET['doctor_id'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'PARENT') {
        echo json_encode(['success' => false, 'message' => 'Not authorized']);
        exit();
    }

    $doctor_id = intval($_GET['doctor_id']);

    // ── 1. Load doctor's recurring weekly schedule ──────────────────────────
    $schedule_map = []; // day_of_week => ['start_time', 'end_time']
    $sched_stmt = mysqli_prepare($conn,
        "SELECT day_of_week, start_time, end_time
         FROM doctor_schedules
         WHERE doctor_id = ? AND active = 1");
    mysqli_stmt_bind_param($sched_stmt, "i", $doctor_id);
    mysqli_stmt_execute($sched_stmt);
    $sched_res = mysqli_stmt_get_result($sched_stmt);
    while ($row = mysqli_fetch_assoc($sched_res)) {
        $schedule_map[strtoupper($row['day_of_week'])] = [
            'start_time' => $row['start_time'],
            'end_time'   => $row['end_time'],
        ];
    }

    // ── 2. Load clinic business hours as a fallback ─────────────────────────
    $business_hours = [];
    $bh_res = mysqli_query($conn, "SELECT setting_value FROM clinic_settings WHERE setting_key = 'business_hours' LIMIT 1");
    if ($bh_res && ($bh_row = mysqli_fetch_assoc($bh_res))) {
        $decoded = json_decode($bh_row['setting_value'], true);
        if (is_array($decoded)) $business_hours = $decoded;
    }

    // ── 3. Load doctor-specific availability overrides (next 60 days) ───────
    $today    = new DateTime();
    $end_date = (new DateTime())->modify('+60 days');
    $first_day = $today->format('Y-m-d');
    $last_day  = $end_date->format('Y-m-d');

    $avail_map = []; // date => array of override rows
    $avail_stmt = mysqli_prepare($conn,
        "SELECT specific_date, start_time, end_time, availability_type, reason, is_all_day
         FROM doctor_availability
         WHERE doctor_id = ? AND specific_date BETWEEN ? AND ? AND active = 1");
    mysqli_stmt_bind_param($avail_stmt, "iss", $doctor_id, $first_day, $last_day);
    mysqli_stmt_execute($avail_stmt);
    $avail_res = mysqli_stmt_get_result($avail_stmt);
    while ($a = mysqli_fetch_assoc($avail_res)) {
        $avail_map[$a['specific_date']][] = $a;
    }

    // ── 4. Build the list of available dates ────────────────────────────────
    $php_days = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
    $clinic_day_keys = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

    $available_dates = [];

    $period = new DatePeriod(
        $today,
        new DateInterval('P1D'),
        (clone $end_date)->modify('+1 day')
    );

    foreach ($period as $dt) {
        $date     = $dt->format('Y-m-d');
        $day_upper = strtoupper($dt->format('l')); // e.g. MONDAY
        $day_lower = strtolower($dt->format('l')); // e.g. monday

        // Determine base working hours for this day
        $start_time = null;
        $end_time   = null;

        if (isset($schedule_map[$day_upper])) {
            // Doctor has a specific recurring schedule
            $start_time = $schedule_map[$day_upper]['start_time'];
            $end_time   = $schedule_map[$day_upper]['end_time'];
        } elseif (!empty($business_hours[$day_lower]) && isset($business_hours[$day_lower]['open'])) {
            // Fall back to clinic hours
            $start_time = $business_hours[$day_lower]['open'];
            $end_time   = $business_hours[$day_lower]['close'];
        } elseif (empty($business_hours)) {
            // No clinic hours configured at all — default Mon-Sat
            $day_num = (int)$dt->format('N');
            if ($day_num <= 6) {
                $start_time = '08:00:00';
                $end_time   = '17:00:00';
            }
        }

        // Skip days the doctor/clinic doesn't work
        if (!$start_time) continue;

        // Apply doctor-specific overrides
        if (isset($avail_map[$date])) {
            $is_unavailable  = false;
            $avail_override  = null;
            foreach ($avail_map[$date] as $entry) {
                if ($entry['availability_type'] === 'UNAVAILABLE') {
                    $is_unavailable = true;
                } elseif ($entry['availability_type'] === 'AVAILABLE') {
                    $avail_override = $entry;
                }
            }
            if ($is_unavailable && !$avail_override) continue; // blocked day
            if ($avail_override && !$avail_override['is_all_day']) {
                $start_time = $avail_override['start_time'] ?: $start_time;
                $end_time   = $avail_override['end_time']   ?: $end_time;
            }
        }

        $available_dates[] = [
            'date'       => $date,
            'start_time' => $start_time,
            'end_time'   => $end_time,
        ];
    }

    echo json_encode(['success' => true, 'available_dates' => $available_dates]);
    exit();
}

// -- Page logic starts here --

// Check if user is logged in and is a parent
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'PARENT') {
    header("Location: index.php");
    exit();
}

// Capture flash messages to render as toasts (rendered at end of <body>)
$__parentFlashes = [];
if (isset($_SESSION['success_message'])) {
    $__parentFlashes[] = ['message' => $_SESSION['success_message'], 'type' => 'success'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $__parentFlashes[] = ['message' => $_SESSION['error_message'], 'type' => 'error'];
    unset($_SESSION['error_message']);
}
if (isset($_SESSION['info_message'])) {
    $__parentFlashes[] = ['message' => $_SESSION['info_message'], 'type' => 'info'];
    unset($_SESSION['info_message']);
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

// Get user details
$user_query = "SELECT * FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($user_result);

// Get user's children
$children_query = "SELECT * FROM patients WHERE parent_id = ?";
$stmt = mysqli_prepare($conn, $children_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$children_result = mysqli_stmt_get_result($stmt);
$children = [];
while ($row = mysqli_fetch_assoc($children_result)) {
    $children[] = $row;
}
// === Load files for each child (add after you fetch $children) ===
foreach ($children as &$child) {
    $files_q = "SELECT id, original_filename, mime_type, file_size, file_category, description, created_at FROM patient_files WHERE patient_id = ? ORDER BY created_at DESC";
    $fstmt = mysqli_prepare($conn, $files_q);
    mysqli_stmt_bind_param($fstmt, "i", $child['id']);
    mysqli_stmt_execute($fstmt);
    $fres = mysqli_stmt_get_result($fstmt);
    $child_files = [];
    while ($frow = mysqli_fetch_assoc($fres)) {
        $child_files[] = $frow;
    }
    $child['files'] = $child_files;
}
unset($child);
// --- Populate vaccine needs grouped by patient_id (place this AFTER you populate $children) ---
$vaccine_needs_by_child = [];

$child_ids = array_map(function($c){ return intval($c['id']); }, $children ?? []);
$child_ids = array_filter($child_ids); // remove zeros / falsy

if (!empty($child_ids)) {
    // safe because we've cast IDs to integers
    $in_list = implode(',', $child_ids);

    $q = "SELECT id, patient_id, vaccine_name, recommended_date, status, notes, created_by, created_at, updated_at
          FROM patient_vaccine_needs
          WHERE patient_id IN ($in_list)
          ORDER BY COALESCE(recommended_date, created_at) ASC";
    $res = mysqli_query($conn, $q);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $pid = intval($row['patient_id']);
            if (!isset($vaccine_needs_by_child[$pid])) $vaccine_needs_by_child[$pid] = [];
            $vaccine_needs_by_child[$pid][] = $row;
        }
        mysqli_free_result($res);
    } else {
        error_log('patient_vaccine_needs query failed: ' . mysqli_error($conn));
    }
}
// Get appointments
$appointments_query = "SELECT a.*, p.first_name as child_first_name, p.last_name as child_last_name, 
                              u.first_name as doctor_first_name, u.last_name as doctor_last_name
                       FROM appointments a 
                       JOIN patients p ON a.patient_id = p.id 
                       JOIN users u ON a.doctor_id = u.id 
                       WHERE p.parent_id = ? 
                       ORDER BY a.appointment_date DESC, a.appointment_time DESC";
$stmt = mysqli_prepare($conn, $appointments_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$appointments_result = mysqli_stmt_get_result($stmt);
$appointments = [];
while ($row = mysqli_fetch_assoc($appointments_result)) {
    $appointments[] = $row;
}

// Get vaccination records - Enhanced query with better data
$vaccinations_query = "SELECT vr.*, p.first_name as child_first_name, p.last_name as child_last_name,
                              u.first_name as doctor_first_name, u.last_name as doctor_last_name,
                              p.date_of_birth as child_dob
                       FROM vaccination_records vr 
                       JOIN patients p ON vr.patient_id = p.id 
                       LEFT JOIN users u ON vr.administered_by = u.id 
                       WHERE p.parent_id = ? 
                       ORDER BY vr.administration_date DESC, vr.vaccine_name ASC";
$stmt = mysqli_prepare($conn, $vaccinations_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$vaccinations_result = mysqli_stmt_get_result($stmt);
$vaccinations = [];
while ($row = mysqli_fetch_assoc($vaccinations_result)) {
    $vaccinations[] = $row;
}

// Get available doctors
$doctors_query = "SELECT id, first_name, last_name, specialization 
                  FROM users 
                  WHERE user_type IN ('DOCTOR','DOCTOR_OWNER') AND status = 'active'
                  ORDER BY last_name, first_name";
$doctors_result = mysqli_query($conn, $doctors_query);
$doctors = [];
while ($row = mysqli_fetch_assoc($doctors_result)) {
    $doctors[] = $row;
}

// Common vaccine schedules for reference
$common_vaccines = [
    'BCG' => ['doses' => 1, 'description' => 'Tuberculosis vaccine', 'schedule' => 'At birth', 'details' => 'Bacillus Calmette-Guérin (BCG) vaccine protects against tuberculosis (TB). It is given as a single dose at birth or as soon as possible after birth. The vaccine is injected intradermally in the upper left arm.', 'side_effects' => 'Small bump at injection site, mild fever. A small scar may develop.'],
    'Hepatitis B' => ['doses' => 3, 'description' => 'Hepatitis B prevention', 'schedule' => 'Birth, 1 month, 6 months', 'details' => 'Protects against Hepatitis B virus which can cause chronic liver disease and liver cancer. Given in 3 doses: at birth, 1 month, and 6 months of age.', 'side_effects' => 'Soreness at injection site, mild fever, fatigue.'],
    'DPT' => ['doses' => 5, 'description' => 'Diphtheria, Pertussis, Tetanus', 'schedule' => '6, 10, 14 weeks; 15-18 months; 4-6 years', 'details' => 'Combination vaccine protecting against three serious bacterial diseases: Diphtheria (throat infection), Pertussis (whooping cough), and Tetanus (lockjaw). Given in 5 doses across early childhood.', 'side_effects' => 'Fever, fussiness, soreness at injection site, mild swelling.'],
    'Polio' => ['doses' => 4, 'description' => 'Polio vaccine', 'schedule' => '6, 10, 14 weeks; 4-6 years', 'details' => 'Protects against poliovirus which can cause paralysis. Available as oral (OPV) or injectable (IPV) form. Given in 4 doses to ensure complete immunity.', 'side_effects' => 'Very rare side effects. Mild soreness at injection site for IPV.'],
    'MMR' => ['doses' => 2, 'description' => 'Measles, Mumps, Rubella', 'schedule' => '9-12 months; 15-18 months', 'details' => 'Combination vaccine protecting against Measles (highly contagious respiratory disease), Mumps (swelling of salivary glands), and Rubella (German measles). Given in 2 doses.', 'side_effects' => 'Mild fever, rash, temporary joint pain. Rare: febrile seizures.'],
    'Varicella' => ['doses' => 2, 'description' => 'Chickenpox vaccine', 'schedule' => '12-15 months; 4-6 years', 'details' => 'Protects against Varicella-zoster virus (chickenpox). Prevents the itchy, blister-like rash, fever, and potential complications such as skin infections and pneumonia.', 'side_effects' => 'Soreness at injection site, mild rash, low fever.'],
    'Hepatitis A' => ['doses' => 2, 'description' => 'Hepatitis A prevention', 'schedule' => '12 months; 18 months', 'details' => 'Protects against Hepatitis A virus which causes liver inflammation. Spread through contaminated food or water. Given in 2 doses, 6 months apart.', 'side_effects' => 'Soreness at injection site, headache, tiredness.'],
    'Pneumococcal' => ['doses' => 4, 'description' => 'Pneumonia prevention', 'schedule' => '6, 10, 14 weeks; 12-15 months', 'details' => 'Protects against Streptococcus pneumoniae bacteria which can cause pneumonia, meningitis, and blood infections. PCV13 vaccine is given in 4 doses.', 'side_effects' => 'Redness/swelling at injection site, mild fever, irritability.'],
    'Rotavirus' => ['doses' => 3, 'description' => 'Rotavirus gastroenteritis prevention', 'schedule' => '6, 10, 14 weeks', 'details' => 'Protects against rotavirus, the most common cause of severe diarrhea in infants and young children worldwide. Given orally (not an injection) in 2-3 doses.', 'side_effects' => 'Mild, temporary diarrhea or vomiting, irritability.'],
    'Influenza' => ['doses' => 'Annual', 'description' => 'Seasonal flu vaccine', 'schedule' => 'Annually from 6 months', 'details' => 'Protects against seasonal influenza viruses. Recommended annually as flu strains change each year. Children receiving it for the first time need 2 doses, 4 weeks apart.', 'side_effects' => 'Soreness at injection site, low-grade fever, body aches.']
];

// Get growth records for each child
$growthRecords = [];
foreach ($children as $child) {
    $growthQuery = "SELECT height, weight, created_at 
                    FROM growth_records 
                    WHERE patient_id = ? 
                    AND height IS NOT NULL 
                    AND weight IS NOT NULL
                    ORDER BY created_at";
    $stmt = mysqli_prepare($conn, $growthQuery);
    mysqli_stmt_bind_param($stmt, "i", $child['id']);
    mysqli_stmt_execute($stmt);
    $growthResult = mysqli_stmt_get_result($stmt);
    $growthRecords[$child['id']] = [];
    while ($row = mysqli_fetch_assoc($growthResult)) {
        $growthRecords[$child['id']][] = $row;
    }
}

// Handle form submissions (Add/Edit child, Book/Cancel appointment)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_child'])) {
        if (handleAddChild($conn, $user_id)) {
            $_SESSION['success_message'] = 'Child added successfully!';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_POST['edit_child'])) {
        if (handleEditChild($conn, $user_id)) {
            $_SESSION['success_message'] = "Child's profile updated successfully!";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_POST['book_appointment'])) {
        if (handleBookAppointment($conn, $user_id)) {
            $_SESSION['success_message'] = 'Appointment booked successfully!';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_POST['edit_appointment'])) {
        if (handleEditAppointment($conn, $user_id)) {
            $_SESSION['success_message'] = 'Appointment updated successfully!';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_POST['cancel_appointment'])) {
        if (handleCancelAppointment($conn, $user_id)) {
            $_SESSION['success_message'] = 'Cancellation request submitted. The clinic will review and confirm.';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_POST['change_password'])) {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            $_SESSION['error_message'] = 'Invalid request. Please try again.';
        } else {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $_SESSION['error_message'] = 'All password fields are required.';
            } elseif ($new_password !== $confirm_password) {
                $_SESSION['error_message'] = 'New password and confirmation do not match.';
            } elseif (strlen($new_password) < 6) {
                $_SESSION['error_message'] = 'New password must be at least 6 characters.';
            } else {
                $pw_stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
                mysqli_stmt_bind_param($pw_stmt, "i", $user_id);
                mysqli_stmt_execute($pw_stmt);
                $pw_result = mysqli_stmt_get_result($pw_stmt);
                $pw_row = mysqli_fetch_assoc($pw_result);

                if (!$pw_row || !password_verify($current_password, $pw_row['password'])) {
                    $_SESSION['error_message'] = 'Current password is incorrect.';
                } else {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_pw = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
                    mysqli_stmt_bind_param($update_pw, "si", $hashed, $user_id);
                    if (mysqli_stmt_execute($update_pw)) {
                        $_SESSION['success_message'] = 'Password changed successfully!';
                    } else {
                        $_SESSION['error_message'] = 'Failed to change password. Please try again.';
                    }
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } elseif (isset($_POST['update_profile'])) {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            $_SESSION['error_message'] = 'Invalid request. Please try again.';
        } else {
            $first_name = sanitize_input($_POST['profile_first_name'] ?? '');
            $last_name = sanitize_input($_POST['profile_last_name'] ?? '');
            $email = sanitize_input($_POST['profile_email'] ?? '');
            $phone = preg_replace('/\s+/', '', $_POST['profile_phone'] ?? '');
            $dob   = trim($_POST['profile_date_of_birth'] ?? '');
            $gender_raw = strtoupper(trim($_POST['profile_gender'] ?? ''));
            $address = sanitize_input($_POST['profile_address'] ?? '');
            $ec_name  = sanitize_input($_POST['profile_emergency_name'] ?? '');
            $ec_phone = preg_replace('/\s+/', '', $_POST['profile_emergency_phone'] ?? '');

            $validGenders = ['MALE', 'FEMALE', 'OTHER'];
            $gender = in_array($gender_raw, $validGenders, true) ? $gender_raw : null;

            // Normalize date: empty string => NULL
            $dob_value = ($dob !== '' && strtotime($dob)) ? $dob : null;

            if (empty($first_name) || empty($last_name) || empty($email)) {
                $_SESSION['error_message'] = 'First name, last name, and email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['error_message'] = 'Please enter a valid email address.';
            } elseif ($phone !== '' && !preg_match('/^9\d{9}$/', $phone)) {
                $_SESSION['error_message'] = 'Phone number must be 10 digits starting with 9.';
            } elseif (empty($ec_name) || empty($ec_phone)) {
                $_SESSION['error_message'] = 'Emergency contact name and phone are required.';
            } elseif (!preg_match('/^9\d{9}$/', $ec_phone)) {
                $_SESSION['error_message'] = 'Emergency contact phone must be 10 digits starting with 9.';
            } else {
                // Check email uniqueness (excluding current user)
                $check = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ?");
                mysqli_stmt_bind_param($check, "si", $email, $user_id);
                mysqli_stmt_execute($check);
                $check_result = mysqli_stmt_get_result($check);
                if (mysqli_num_rows($check_result) > 0) {
                    $_SESSION['error_message'] = 'That email address is already in use.';
                } else {
                    $update = mysqli_prepare($conn, "UPDATE users SET
                        first_name = ?, last_name = ?, email = ?, phone = ?,
                        date_of_birth = ?, gender = ?, address = ?,
                        emergency_contact_name = ?, emergency_contact_phone = ?
                        WHERE id = ?");
                    mysqli_stmt_bind_param($update, "sssssssssi",
                        $first_name, $last_name, $email, $phone,
                        $dob_value, $gender, $address,
                        $ec_name, $ec_phone, $user_id);
                    if (mysqli_stmt_execute($update)) {
                        $_SESSION['first_name'] = $first_name;
                        $_SESSION['last_name'] = $last_name;
                        $_SESSION['email'] = $email;
                        $_SESSION['success_message'] = 'Profile updated successfully!';
                    } else {
                        $_SESSION['error_message'] = 'Failed to update profile. Please try again.';
                    }
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// === File upload handler (add near other POST handlers) ===
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // ... existing POST branches ...

    // File upload - field name: patient_file
    if (isset($_POST['upload_patient_file'])) {
        if (handleUploadPatientFile($conn, $user_id)) {
            $_SESSION['success_message'] = 'File uploaded successfully!';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Secure file upload function
function handleUploadPatientFile($conn, $u_id) {
    if (!isset($_POST['upload_patient_file']) || !isset($_POST['upload_patient_id'])) {
        $_SESSION['error_message'] = 'Invalid upload request.';
        return false;
    }

    $patient_id = intval($_POST['upload_patient_id']);
    $notes = mysqli_real_escape_string($conn, trim($_POST['upload_notes'] ?? ''));

    // Verify child belongs to parent
    $check_query = "SELECT id FROM patients WHERE id = ? AND parent_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $patient_id, $u_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    if (mysqli_num_rows($check_result) == 0) {
        $_SESSION['error_message'] = 'Child not found or you do not have permission.';
        return false;
    }

    if (!isset($_FILES['patient_file']) || $_FILES['patient_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error_message'] = 'No file uploaded or upload error.';
        return false;
    }

    $file = $_FILES['patient_file'];

    // Config: allowed types + max size (adjust as needed)
    $maxBytes = 5 * 1024 * 1024; // 5 MB
    $allowedExtensions = ['pdf','jpg','jpeg','png','doc','docx'];
    $allowedMime = [
        'application/pdf',
        'image/jpeg','image/png',
        'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    // Basic checks
    if ($file['size'] > $maxBytes) {
        $_SESSION['error_message'] = 'File too large. Maximum is 5 MB.';
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    if (!in_array($mime, $allowedMime)) {
        $_SESSION['error_message'] = 'File type not allowed.';
        return false;
    }

    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions)) {
        $_SESSION['error_message'] = 'File extension not allowed.';
        return false;
    }

    // Prepare safe storage
    $upload_base = __DIR__ . '/uploads/patient_files';
    if (!is_dir($upload_base)) {
        if (!mkdir($upload_base, 0750, true)) {
            $_SESSION['error_message'] = 'Failed to create upload directory.';
            return false;
        }
    }

    // Create unique stored filename (avoid collisions)
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $upload_base . '/' . $storedFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $_SESSION['error_message'] = 'Failed to move uploaded file.';
        return false;
    }

    // Allowed file categories (must match the patient_files.file_category enum).
    $allowedCategories = ['LAB_RESULT', 'MRI', 'XRAY', 'PRESCRIPTION', 'REFERRAL', 'IMMUNIZATION', 'OTHER'];
    $category = strtoupper(trim($_POST['file_category'] ?? 'OTHER'));
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'OTHER';
    }

    // Store metadata in DB (schema column is `description`, legacy migrations used `notes`)
    $insert = "INSERT INTO patient_files (patient_id, uploaded_by, original_filename, stored_filename, mime_type, file_size, file_category, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $insert);
    mysqli_stmt_bind_param($stmt, "iississ", $patient_id, $u_id, $origName, $storedFilename, $mime, $file['size'], $category, $notes);
    if (!mysqli_stmt_execute($stmt)) {
        // cleanup file on DB error
        @unlink($destination);
        error_log('DB error saving file: ' . mysqli_error($conn));
        $_SESSION['error_message'] = 'Error saving file. Please try again.';
        return false;
    }

    return true;
}

function handleEditChild($conn, $parent_id) {
    $child_id = intval($_POST['child_id'] ?? 0);
    $parent_id = intval($parent_id);
    if ($child_id <= 0) {
        $_SESSION['error_message'] = 'Invalid child.';
        return false;
    }

    // Verify ownership
    $check = mysqli_prepare($conn, "SELECT id FROM patients WHERE id = ? AND parent_id = ?");
    mysqli_stmt_bind_param($check, "ii", $child_id, $parent_id);
    mysqli_stmt_execute($check);
    $check_res = mysqli_stmt_get_result($check);
    if (mysqli_num_rows($check_res) === 0) {
        $_SESSION['error_message'] = "Child not found or you don't have permission.";
        return false;
    }

    $first_name = trim($_POST['child_first_name'] ?? '');
    $last_name = trim($_POST['child_last_name'] ?? '');
    $date_of_birth = trim($_POST['child_dob'] ?? '');
    $gender = trim($_POST['child_gender'] ?? '');
    $blood_type = trim($_POST['child_blood_type'] ?? '');
    $height = isset($_POST['child_height']) && $_POST['child_height'] !== '' ? floatval($_POST['child_height']) : null;
    $weight = isset($_POST['child_weight']) && $_POST['child_weight'] !== '' ? floatval($_POST['child_weight']) : null;
    $allergies = trim($_POST['child_allergies'] ?? '');
    $medical_conditions = trim($_POST['child_medical_conditions'] ?? '');

    if ($first_name === '' || $last_name === '' || $date_of_birth === '' || $gender === '') {
        $_SESSION['error_message'] = 'First name, last name, date of birth and gender are required.';
        return false;
    }

    $update = "UPDATE patients SET first_name = ?, last_name = ?, date_of_birth = ?, gender = ?, blood_type = ?, height = ?, weight = ?, allergies = ?, medical_conditions = ? WHERE id = ? AND parent_id = ?";
    $stmt = mysqli_prepare($conn, $update);
    mysqli_stmt_bind_param(
        $stmt, "sssssddssii",
        $first_name, $last_name, $date_of_birth, $gender, $blood_type,
        $height, $weight, $allergies, $medical_conditions, $child_id, $parent_id
    );

    if (mysqli_stmt_execute($stmt)) {
        return true;
    }
    error_log('Error updating child: ' . mysqli_error($conn));
    $_SESSION['error_message'] = "Error updating child's profile. Please try again.";
    return false;
}

function handleEditAppointment($conn, $user_id) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid request. Please refresh the page and try again.';
        return false;
    }

    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $appointment_date = trim($_POST['appointment_date'] ?? '');
    $appointment_time = trim($_POST['appointment_time'] ?? '');
    $appointment_type = trim($_POST['appointment_service'] ?? 'CONSULTATION');
    $reason = trim($_POST['appointment_notes'] ?? '');

    if ($appointment_id <= 0 || $appointment_date === '' || $appointment_time === '') {
        $_SESSION['error_message'] = 'Please fill in all required fields.';
        return false;
    }

    $dateTs = strtotime($appointment_date);
    if ($dateTs === false || $dateTs < strtotime(date('Y-m-d'))) {
        $_SESSION['error_message'] = 'Invalid or past appointment date.';
        return false;
    }

    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $appointment_time)) {
        $_SESSION['error_message'] = 'Invalid appointment time.';
        return false;
    }

    $allowed_types = ['CONSULTATION', 'VACCINATION', 'CHECKUP', 'FOLLOW_UP', 'URGENT', 'OTHER'];
    if (!in_array($appointment_type, $allowed_types, true)) {
        $appointment_type = 'CONSULTATION';
    }

    // Verify the appointment belongs to one of the parent's children.
    $verify = "SELECT a.id, a.doctor_id, a.status FROM appointments a
               JOIN patients p ON a.patient_id = p.id
               WHERE a.id = ? AND p.parent_id = ?";
    $vs = mysqli_prepare($conn, $verify);
    mysqli_stmt_bind_param($vs, "ii", $appointment_id, $user_id);
    mysqli_stmt_execute($vs);
    $vr = mysqli_stmt_get_result($vs);
    $row = mysqli_fetch_assoc($vr);
    if (!$row) {
        $_SESSION['error_message'] = 'Appointment not found.';
        return false;
    }
    if (in_array($row['status'], ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true)) {
        $_SESSION['error_message'] = 'This appointment can no longer be edited.';
        return false;
    }

    // Conflict check (same doctor, same date/time, excluding self)
    $conflict = mysqli_prepare($conn, "SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status IN ('SCHEDULED','CONFIRMED') AND id != ?");
    mysqli_stmt_bind_param($conflict, "issi", $row['doctor_id'], $appointment_date, $appointment_time, $appointment_id);
    mysqli_stmt_execute($conflict);
    if (mysqli_num_rows(mysqli_stmt_get_result($conflict)) > 0) {
        $_SESSION['error_message'] = 'That time is already booked. Please pick another.';
        return false;
    }

    if (strlen($reason) > 1000) {
        $reason = substr($reason, 0, 1000);
    }

    $update = "UPDATE appointments SET appointment_date = ?, appointment_time = ?, type = ?, reason = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update);
    mysqli_stmt_bind_param($stmt, "ssssi", $appointment_date, $appointment_time, $appointment_type, $reason, $appointment_id);
    if (mysqli_stmt_execute($stmt)) {
        $when = date('M j, Y', strtotime($appointment_date)) . ' at ' . date('h:i A', strtotime($appointment_time));
        send_appointment_notification($conn, $user_id, 'Appointment updated',
            "Your appointment has been rescheduled to $when.", $appointment_id);
        send_appointment_notification($conn, intval($row['doctor_id']), 'Appointment updated',
            "An appointment has been rescheduled to $when by the parent.", $appointment_id);
        foreach (get_admin_user_ids($conn) as $aid) {
            send_appointment_notification($conn, $aid, 'Appointment updated',
                "An appointment was rescheduled to $when.", $appointment_id);
        }
        return true;
    }
    error_log('Error updating appointment: ' . mysqli_error($conn));
    $_SESSION['error_message'] = 'Error updating appointment.';
    return false;
}

function handleAddChild($conn, $parent_id) {
    $first_name = trim($_POST['child_first_name'] ?? '');
    $last_name = trim($_POST['child_last_name'] ?? '');
    $date_of_birth = trim($_POST['child_dob'] ?? '');
    $gender = trim($_POST['child_gender'] ?? '');
    $blood_type = trim($_POST['child_blood_type'] ?? '');
    $height = isset($_POST['child_height']) && $_POST['child_height'] !== '' ? floatval($_POST['child_height']) : null;
    $weight = isset($_POST['child_weight']) && $_POST['child_weight'] !== '' ? floatval($_POST['child_weight']) : null;
    $allergies = trim($_POST['child_allergies'] ?? '');
    $medical_conditions = trim($_POST['child_medical_conditions'] ?? '');
    $parent_id = intval($parent_id);

    $query = "INSERT INTO patients (parent_id, first_name, last_name, date_of_birth, gender, blood_type, height, weight, allergies, medical_conditions)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "isssssddss", $parent_id, $first_name, $last_name, $date_of_birth, $gender, $blood_type, $height, $weight, $allergies, $medical_conditions);

    if (mysqli_stmt_execute($stmt)) {
        return true;
    } else {
        error_log('Error adding child: ' . mysqli_error($conn));
        $_SESSION['error_message'] = 'Error adding child. Please try again.';
        return false;
    }
}



function handleBookAppointment($conn, $user_id) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid request. Please refresh the page and try again.';
        return false;
    }

    $patient_id = intval($_POST['appointment_child'] ?? 0);
    $doctor_id = intval($_POST['appointment_doctor'] ?? 0);
    $appointment_date = trim($_POST['appointment_date'] ?? '');
    $appointment_time = trim($_POST['appointment_time'] ?? '');
    $appointment_type = trim($_POST['appointment_service'] ?? 'CONSULTATION');
    $reason = trim($_POST['appointment_notes'] ?? '');

    // Required field validation
    if ($patient_id <= 0 || $doctor_id <= 0 || $appointment_date === '' || $appointment_time === '') {
        $_SESSION['error_message'] = 'Please fill in all required fields.';
        return false;
    }

    // Date validation (must be a valid date, not in the past)
    $dateTs = strtotime($appointment_date);
    if ($dateTs === false) {
        $_SESSION['error_message'] = 'Invalid appointment date.';
        return false;
    }
    if ($dateTs < strtotime(date('Y-m-d'))) {
        $_SESSION['error_message'] = 'Appointment date cannot be in the past.';
        return false;
    }

    // Time format validation (HH:MM or HH:MM:SS)
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $appointment_time)) {
        $_SESSION['error_message'] = 'Invalid appointment time.';
        return false;
    }

    // Type whitelist validation
    $allowed_types = ['CONSULTATION', 'VACCINATION', 'CHECKUP', 'FOLLOW_UP', 'URGENT', 'OTHER'];
    if (!in_array($appointment_type, $allowed_types, true)) {
        $appointment_type = 'CONSULTATION';
    }

    // Notes length cap
    if (strlen($reason) > 1000) {
        $reason = substr($reason, 0, 1000);
    }

    // Verify child belongs to parent
    $check_query = "SELECT id FROM patients WHERE id = ? AND parent_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $patient_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    if (mysqli_num_rows($check_result) == 0) {
        $_SESSION['error_message'] = 'Child not found or you do not have permission.';
        return false;
    }

    // Basic conflict check: same doctor, same date & time
    $conflict_query = "SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status IN ('SCHEDULED', 'CONFIRMED')";
    $conflict_stmt = mysqli_prepare($conn, $conflict_query);
    mysqli_stmt_bind_param($conflict_stmt, "iss", $doctor_id, $appointment_date, $appointment_time);
    mysqli_stmt_execute($conflict_stmt);
    $conflict_result = mysqli_stmt_get_result($conflict_stmt);
    if (mysqli_num_rows($conflict_result) > 0) {
        $_SESSION['error_message'] = 'Selected time is already booked. Please choose another time.';
        return false;
    }

    // ✅ FIXED: added created_by column and bind $user_id
    $query = "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, type, reason, status, created_by)
              VALUES (?, ?, ?, ?, ?, ?, 'SCHEDULED', ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "iissssi", $patient_id, $doctor_id, $appointment_date, $appointment_time, $appointment_type, $reason, $user_id);

    if (mysqli_stmt_execute($stmt)) {
        $appointment_id = mysqli_insert_id($conn);
        // Notify parent, doctor and admins (email + bell).
        $when = date('M j, Y', strtotime($appointment_date)) . ' at ' . date('h:i A', strtotime($appointment_time));
        $title = 'Appointment booked';
        $msg = "An appointment has been scheduled for $when ($appointment_type).";
        send_appointment_notification($conn, $user_id, $title, $msg, $appointment_id);
        send_appointment_notification($conn, $doctor_id, 'New appointment scheduled',
            "A new appointment was booked for $when ($appointment_type).", $appointment_id);
        foreach (get_admin_user_ids($conn) as $aid) {
            send_appointment_notification($conn, $aid, 'New appointment scheduled',
                "A parent booked an appointment for $when ($appointment_type).", $appointment_id);
        }
        return true;
    } else {
        error_log('Error booking appointment: ' . mysqli_error($conn));
        $_SESSION['error_message'] = 'Error booking appointment. Please try again.';
        return false;
    }
}

function handleCancelAppointment($conn, $user_id) {
    $appointment_id = intval($_POST['cancel_appointment_id'] ?? 0);
    $reason = trim($_POST['cancel_reason'] ?? '');
    if (strlen($reason) > 500) $reason = substr($reason, 0, 500);

    // Verify appointment belongs to parent's child
    $check_query = "SELECT a.id, a.status FROM appointments a
                    JOIN patients p ON a.patient_id = p.id
                    WHERE a.id = ? AND p.parent_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $appointment_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $appt = mysqli_fetch_assoc($check_result);

    if (!$appt) {
        $_SESSION['error_message'] = 'Appointment not found or you do not have permission to cancel.';
        return false;
    }
    if (in_array($appt['status'], ['CANCELLED', 'COMPLETED', 'NO_SHOW', 'CANCELLATION_REQUESTED'], true)) {
        $_SESSION['error_message'] = 'This appointment cannot be cancelled again.';
        return false;
    }

    // Parent-initiated cancellation requires clinic/doctor approval — mark as
    // CANCELLATION_REQUESTED and record who/when so staff can approve.
    $query = "UPDATE appointments SET status = 'CANCELLATION_REQUESTED', cancellation_reason = ?, cancellation_requested_by = ?, cancellation_requested_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sii", $reason, $user_id, $appointment_id);

    if (mysqli_stmt_execute($stmt)) {
        // Pull doctor id for notification
        $info = mysqli_query($conn, "SELECT doctor_id FROM appointments WHERE id = " . $appointment_id);
        $doctor_id = ($info && ($d = mysqli_fetch_assoc($info))) ? intval($d['doctor_id']) : 0;
        $title = 'Cancellation requested';
        $msg = "A parent requested cancellation of an appointment. Reason: " . ($reason ?: '(no reason given)');
        if ($doctor_id) send_appointment_notification($conn, $doctor_id, $title, $msg, $appointment_id);
        foreach (get_admin_user_ids($conn) as $aid) {
            send_appointment_notification($conn, $aid, $title, $msg, $appointment_id);
        }
        send_appointment_notification($conn, $user_id, 'Cancellation request submitted',
            'Your cancellation request has been received. The clinic will review and confirm shortly.', $appointment_id);
        return true;
    }
    error_log('Error requesting cancellation: ' . mysqli_error($conn));
    $_SESSION['error_message'] = 'Error submitting cancellation request. Please try again.';
    return false;
}

// The Settings update functionality has been intentionally removed per request.
// The UI will display profile fields read-only and instruct users to contact support.

// Helper functions for status display
function getStatusColor($status) {
    switch ($status) {
        case 'SCHEDULED': return 'text-orange-600';
        case 'CONFIRMED': return 'text-green-600';
        case 'COMPLETED': return 'text-blue-600';
        case 'CANCELLED': return 'text-red-600';
        default: return 'text-gray-600';
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'SCHEDULED': return 'bg-orange-100 text-orange-800';
        case 'CONFIRMED': return 'bg-green-100 text-green-800';
        case 'COMPLETED': return 'bg-blue-100 text-blue-800';
        case 'CANCELLED': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

function getVaccineNeedBadge($status) {
    switch ($status) {
        case 'GIVEN':
            return 'bg-green-100 text-green-800';
        case 'SCHEDULED':
        case 'PENDING':
            return 'bg-blue-100 text-blue-800';
        case 'OVERDUE':
        case 'MISSED':
            return 'bg-red-100 text-red-800';
        case 'PARTIAL':
            return 'bg-yellow-100 text-yellow-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

function getVaccineTypeBadge($type) {
    switch ($type) {
        case 'ROUTINE': return 'bg-purple-100 text-purple-800';
        case 'OPTIONAL': return 'bg-yellow-100 text-yellow-800';
        case 'SPECIAL': return 'bg-pink-100 text-pink-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

function getVaccinationStatusBadge($status) {
    $s = strtoupper(trim((string)$status));
    switch ($s) {
        case 'COMPLETED':
        case 'GIVEN':
            return 'bg-green-100 text-green-800';
        case 'SCHEDULED':
        case 'PENDING':
            return 'bg-blue-100 text-blue-800';
        case 'OVERDUE':
        case 'MISSED':
            return 'bg-red-100 text-red-800';
        case 'PARTIAL':
            return 'bg-yellow-100 text-yellow-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard - AlagApp Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="css/shared.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <style>
        /* Parent dashboard uses --primary-pink-dashboard value */
        :root {
            --primary-pink: #ff7aa3;
            --primary-pink-dashboard: #ff7aa3;
            --light-pink: #FFBCD9;
            --dark-text: #333333;
            --light-gray: #F6F6F8;
        }

        /* Base styles from shared.css and dashboard.css;
           only page-specific overrides below */

        /* Grid, modal, nav, spinner, medical record styles are in dashboard.css */
        /* Calendar styles are in dashboard.css */

        /* Appointment List Styles */
        .appointment-list-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            height: 100%;
            overflow-y: auto;
        }

        .appointment-list-header {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }

        .appointment-item {
            background: #f9fafb;
            border-left: 4px solid #6366f1;
            padding: 12px;
            margin-bottom: 12px;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .appointment-item:hover {
            background: #f3f4f6;
            transform: translateX(4px);
        }

        .appointment-item-date {
            font-weight: 600;
            color: #6366f1;
            font-size: 0.875rem;
            margin-bottom: 4px;
        }

        .appointment-item-time {
            color: #6b7280;
            font-size: 0.875rem;
            margin-bottom: 8px;
        }

        .appointment-item-patient {
            font-weight: 500;
            color: #374151;
            margin-bottom: 4px;
        }

        .appointment-item-type {
            display: inline-block;
            background: #e0e7ff;
            color: #4338ca;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 8px;
        }

        .appointment-item-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .appointment-item-status.scheduled {
            background: #fef3c7;
            color: #92400e;
        }

        .appointment-item-status.confirmed {
            background: #d1fae5;
            color: #065f46;
        }

        .appointment-item-status.completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .appointment-item-status.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .no-appointments {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }

        .no-appointments svg {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            opacity: 0.5;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .calendar-container {
                padding: 12px;
            }
            
            .calendar-month-year {
                font-size: 1rem;
            }
            
            .calendar-days {
                gap: 4px;
            }
            
            .calendar-day .day-number {
                font-size: 0.75rem;
            }
            
            .appointment-indicator {
                width: 16px;
                height: 16px;
                font-size: 0.625rem;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Menu Button -->
    <div class="md:hidden fixed top-4 left-4 z-50">
        <button id="mobileMenuButton" class="bg-primary text-white p-3 rounded-lg shadow-lg">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
    </div>

    <!-- Mobile Overlay -->
    <div id="mobileOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden md:hidden"></div>

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="sidebar w-64 text-white">
            <div class="p-4 md:p-6">
                <div class="flex justify-between items-center mb-6 md:mb-8">
                    <h1 class="text-xl md:text-2xl font-inter font-bold">AlagApp</h1>
                    <button id="closeSidebar" class="md:hidden text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="mb-6 md:mb-8">
                    <a href="#profile" onclick="showSection('profile')" title="View your profile"
                       class="flex items-center space-x-3 mb-4 p-2 -m-2 rounded-lg hover:bg-white/10 transition-colors cursor-pointer group">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-white/20 rounded-full flex items-center justify-center group-hover:bg-white/30 transition-colors">
                            <svg class="w-5 h-5 md:w-6 md:h-6" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-sm md:text-base truncate group-hover:underline"><?php echo htmlspecialchars($user_name); ?></div>
                            <div class="text-xs md:text-sm text-white/80 truncate"><?php echo htmlspecialchars($user_email); ?></div>
                        </div>
                    </a>
                </div>
                
                <nav class="space-y-1 md:space-y-2">
                    <a href="#dashboard" onclick="showSection('dashboard')" class="flex items-center space-x-3 px-3 py-2 md:px-4 md:py-3 rounded-lg bg-white/20 text-sm md:text-base">
                        <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path>
                        </svg>
                        <span>Dashboard</span>
                    </a>
                    
                    <a href="#children" onclick="showSection('children')" class="flex items-center space-x-3 px-3 py-2 md:px-4 md:py-3 rounded-lg hover:bg-white/20 transition-colors text-sm md:text-base">
                        <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                        </svg>
                        <span>My Children</span>
                    </a>
                    
                    <a href="#appointments" onclick="showSection('appointments')" class="flex items-center space-x-3 px-3 py-2 md:px-4 md:py-3 rounded-lg hover:bg-white/20 transition-colors text-sm md:text-base">
                        <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                        </svg>
                        <span>Appointments</span>
                    </a>
                    
                    <a href="#vaccinations" onclick="showSection('vaccinations')" class="flex items-center space-x-3 px-3 py-2 md:px-4 md:py-3 rounded-lg hover:bg-white/20 transition-colors text-sm md:text-base">
                        <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z" clip-rule="evenodd"></path>
                        </svg>
                        <span>Vaccinations</span>
                    </a>
                    
                    <a href="#profile" onclick="showSection('profile')" class="flex items-center space-x-3 px-3 py-2 md:px-4 md:py-3 rounded-lg hover:bg-white/20 transition-colors text-sm md:text-base">
                        <svg class="nav-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                        </svg>
                        <span>Profile</span>
                    </a>
                </nav>

                <div class="mt-8 pt-8 border-t border-white/20">
                    <a href="logout.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg hover:bg-white/20 transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 001 1h12a1 1 0 001-1V4a1 1 0 00-1-1H3zm10.293 9.707a1 1 0 001.414-1.414l-3-3a1 1 0 00-1.414 0l-3 3a1 1 0 101.414 1.414L9 10.414V16a1 1 0 102 0v-5.586l1.293 1.293z" clip-rule="evenodd"></path>
                        </svg>
                        <span>Logout</span>
                    </a>
            </div>
        </div>
        
        
    </div>
        
        <!-- Main Content -->
        <div class="main-content flex-1 overflow-auto min-h-screen">
            <!-- Top bar: notification bell -->
            <div class="flex items-center justify-end gap-2 px-4 md:px-6 lg:px-8 pt-4">
                <div id="notificationBellMount"></div>
            </div>
            <!-- Dashboard Section -->
            <div id="dashboard-section" class="section-content p-4 md:p-6 lg:p-8">
                <div class="mb-6 md:mb-8">
                    <h1 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Dashboard</h1>
                    <p class="text-gray-600 responsive-text">Welcome to your pediatric care dashboard</p>
                </div>
                
                <!-- Quick Stats (3 clickable cards) -->
                <div class="stats-grid mb-6 md:mb-8">
                    <a href="#children" onclick="showSection('children')" class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6 block">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 rounded-full bg-primary/10">
                                <svg class="w-6 h-6 md:w-8 md:h-8 text-primary" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3 md:ml-4">
                                <div class="text-xl md:text-2xl font-bold text-gray-800"><?php echo count($children); ?></div>
                                <div class="text-xs md:text-sm text-gray-600">Children</div>
                            </div>
                        </div>
                    </a>
                    
                    <a href="#appointments" onclick="showSection('appointments')" class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6 block">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 rounded-full bg-orange-100">
                                <svg class="w-6 h-6 md:w-8 md:h-8 text-orange-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3 md:ml-4">
                                <?php
                                $upcoming_count = 0;
                                foreach ($appointments as $apt) {
                                    if (in_array($apt['status'], ['SCHEDULED', 'CONFIRMED'])) {
                                        $upcoming_count++;
                                    }
                                }
                                ?>
                                <div class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $upcoming_count; ?></div>
                                <div class="text-xs md:text-sm text-gray-600">Upcoming Appointments</div>
                            </div>
                        </div>
                    </a>
                    
                    <a href="#vaccinations" onclick="showSection('vaccinations')" class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6 block">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 rounded-full bg-green-100">
                                <svg class="w-6 h-6 md:w-8 md:h-8 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3 md:ml-4">
                                <div class="text-xl md:text-2xl font-bold text-gray-800"><?php echo count($vaccinations); ?></div>
                                <div class="text-xs md:text-sm text-gray-600">Vaccinations</div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Recent Activity -->
                <div class="content-grid">
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Recent Appointments</h3>
                        <div class="space-y-3 md:space-y-4">
                            <?php if (count($appointments) > 0): ?>
                                <?php foreach (array_slice($appointments, 0, 5) as $appointment): ?>
                                    <div class="flex items-center justify-between p-3 md:p-4 bg-gray-50 rounded-lg">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-semibold text-gray-800 text-sm md:text-base truncate">
                                                <?php echo htmlspecialchars($appointment['child_first_name'] . ' ' . $appointment['child_last_name']); ?>
                                            </div>
                                            <div class="text-xs md:text-sm text-gray-600 truncate"><?php echo htmlspecialchars($appointment['type']); ?></div>
                                            <div class="text-xs md:text-sm text-gray-500 truncate">
                                                Dr. <?php echo htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']); ?>
                                            </div>
                                        </div>
                                        <div class="text-right ml-2">
                                            <div class="text-xs md:text-sm font-medium <?php echo getStatusColor($appointment['status']); ?>">
                                                <?php echo htmlspecialchars($appointment['status']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('M j, Y', strtotime($appointment['appointment_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm md:text-base">No recent appointments</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Recent Vaccinations</h3>
                        <div class="space-y-3 md:space-y-4">
                            <?php if (count($vaccinations) > 0): ?>
                                <?php foreach (array_slice($vaccinations, 0, 5) as $vaccination): ?>
                                    <div class="flex items-center justify-between p-3 md:p-4 bg-green-50 rounded-lg">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-semibold text-gray-800 text-sm md:text-base truncate">
                                                <?php echo htmlspecialchars($vaccination['child_first_name'] . ' ' . $vaccination['child_last_name']); ?>
                                            </div>
                                            <div class="text-xs md:text-sm text-gray-600 truncate"><?php echo htmlspecialchars($vaccination['vaccine_name']); ?></div>
                                            <div class="text-xs md:text-sm text-gray-500 truncate">
                                                Dose <?php echo htmlspecialchars($vaccination['dose_number']); ?>
                                            </div>
                                        </div>
                                        <div class="text-right ml-2">
                                            <div class="text-xs md:text-sm font-medium text-green-600">Completed</div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('M j, Y', strtotime($vaccination['administration_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm md:text-base">No vaccination records</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Children Section -->
            <div id="children-section" class="section-content p-4 md:p-6 lg:p-8 hidden">
                <div class="mb-6 md:mb-8">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">My Children</h1>
                            <p class="text-gray-600 responsive-text">Manage your children's health profiles</p>
                        </div>
                        <?php if (count($children) > 0): ?>
                        <button onclick="openAddChildModal()" class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive w-full sm:w-auto">
                            Add Child
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="children-grid">
                    <?php if (count($children) > 0): ?>
                        <?php foreach ($children as $child): ?>
                            <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                                <div class="flex items-center mb-4">
                                    <div class="w-12 h-12 md:w-16 md:h-16 bg-primary/10 rounded-full flex items-center justify-center mr-3 md:mr-4">
                                        <svg class="w-6 h-6 md:w-8 md:h-8 text-primary" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 truncate">
                                            <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                        </h3>
                                        <p class="text-gray-600 text-sm md:text-base">
                                            Born <?php echo date('M j, Y', strtotime($child['date_of_birth'])); ?>
                                        </p>
                                    </div>
                                    
                                </div>
                                
                                <div class="space-y-2 mb-4">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 text-sm md:text-base">Gender:</span>
                                        <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($child['gender']); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 text-sm md:text-base">Blood Type:</span>
                                        <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($child['blood_type'] ?: 'Not specified'); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 text-sm md:text-base">Height:</span>
                                        <span class="font-medium text-sm md:text-base"><?php echo $child['height'] ? $child['height'] . ' cm' : 'Not recorded'; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 text-sm md:text-base">Weight:</span>
                                        <span class="font-medium text-sm md:text-base"><?php echo $child['weight'] ? $child['weight'] . ' kg' : 'Not recorded'; ?></span>
                                    </div>
                                </div>
                                
                                <?php if ($child['allergies']): ?>
                                    <div class="mb-4">
                                        <div class="text-sm font-medium text-gray-700 mb-1">Allergies:</div>
                                        <div class="text-sm text-red-600"><?php echo htmlspecialchars($child['allergies']); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($child['medical_conditions']): ?>
                                    <div class="mb-4">
                                        <div class="text-sm font-medium text-gray-700 mb-1">Medical Conditions:</div>
                                        <div class="text-sm text-orange-600"><?php echo htmlspecialchars($child['medical_conditions']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <!-- Files list (place inside each child card) -->
                                <div class="mt-4">
                                    <div class="text-sm font-medium text-gray-700 mb-2">Files</div>
                                    <?php if (!empty($child['files'])):
                                        $category_labels = [
                                            'LAB_RESULT'   => 'Laboratory Results',
                                            'MRI'          => 'MRI Scans',
                                            'XRAY'         => 'X-Ray Images',
                                            'PRESCRIPTION' => 'Prescriptions',
                                            'REFERRAL'     => 'Referrals',
                                            'IMMUNIZATION' => 'Immunization',
                                            'OTHER'        => 'Other',
                                        ];
                                        $files_by_cat = [];
                                        foreach ($child['files'] as $cf) {
                                            $key = $cf['file_category'] ?? 'OTHER';
                                            $files_by_cat[$key][] = $cf;
                                        }
                                    ?>
                                        <?php foreach ($category_labels as $key => $label):
                                            if (empty($files_by_cat[$key])) continue; ?>
                                            <details class="mb-2 border border-gray-200 rounded bg-gray-50" open>
                                                <summary class="cursor-pointer px-2 py-1 text-xs font-semibold text-gray-700">
                                                    <?php echo htmlspecialchars($label); ?>
                                                    <span class="text-gray-500 font-normal">(<?php echo count($files_by_cat[$key]); ?>)</span>
                                                </summary>
                                                <ul class="space-y-2 text-sm p-2">
                                                <?php foreach ($files_by_cat[$key] as $cf): ?>
                                                    <li class="flex items-center justify-between bg-white p-2 rounded border">
                                                        <div class="truncate max-w-xs">
                                                            <strong><?php echo htmlspecialchars($cf['original_filename']); ?></strong>
                                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($cf['mime_type']); ?> • <?php echo round($cf['file_size']/1024,1) . ' KB'; ?></div>
                                                        </div>
                                                        <div class="flex items-center space-x-2">
                                                            <a href="download_file.php?file_id=<?php echo $cf['id']; ?>" class="text-sm text-blue-600 hover:underline">Download</a>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-xs text-gray-500">No files uploaded</div>
                                    <?php endif; ?>
                                </div>

                                <!-- Upload button -->
                                <div class="mt-3">
                                    <button onclick="openUploadModal(<?php echo $child['id']; ?>, '<?php echo htmlspecialchars(addslashes($child['first_name'] . ' ' . $child['last_name'])); ?>')" 
                                        class="w-full bg-indigo-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-indigo-700">
                                        Upload File
                                    </button>
                                </div>
                                <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2">
                                    <button onclick="openMedicalRecords(<?php echo $child['id']; ?>, '<?php echo htmlspecialchars(addslashes($child['first_name'] . ' ' . $child['last_name'])); ?>')"
                                            class="flex-1 bg-blue-100 text-blue-700 px-3 py-2 md:px-4 md:py-2 rounded-lg font-medium hover:bg-blue-200 transition-colors text-sm md:text-base">
                                        Medical Records
                                    </button>
                                    <button onclick="viewChildVaccinations(<?php echo $child['id']; ?>)"
                                            class="flex-1 bg-green-100 text-green-700 px-3 py-2 md:px-4 md:py-2 rounded-lg font-medium hover:bg-green-200 transition-colors text-sm md:text-base">
                                        Vaccinations
                                    </button>
                                </div>
                                <div class="mt-2">
                                    <button type="button"
                                            data-child='<?php echo htmlspecialchars(json_encode([
                                                "id" => $child["id"],
                                                "first_name" => $child["first_name"],
                                                "last_name" => $child["last_name"],
                                                "date_of_birth" => $child["date_of_birth"],
                                                "gender" => $child["gender"],
                                                "blood_type" => $child["blood_type"],
                                                "height" => $child["height"],
                                                "weight" => $child["weight"],
                                                "allergies" => $child["allergies"],
                                                "medical_conditions" => $child["medical_conditions"],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>'
                                            onclick="openEditChildModal(this)"
                                            class="w-full bg-amber-100 text-amber-700 px-3 py-2 rounded-lg font-medium hover:bg-amber-200 transition-colors text-sm md:text-base">
                                        Edit Health Profile
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-full text-center py-8 md:py-12">
                            <div class="text-gray-500 mb-4 text-sm md:text-base">No children added yet</div>
                            <button onclick="openAddChildModal()" class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive">
                                Add Your First Child
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
           <!-- Appointments Section -->
            <div id="appointments-section" class="section-content p-4 md:p-6 lg:p-8 hidden">
                <div class="mb-6 md:mb-8">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Appointments</h1>
                            <p class="text-gray-600 responsive-text">Schedule and manage your children's appointments</p>
                        </div>
                        <button onclick="openBookAppointmentModal()" 
                                class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive w-full sm:w-auto">
                            Book New Appointment
                        </button>
                    </div>
                </div>

                <!-- Calendar and Appointments Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Left Side: Appointment List -->
                    <div class="lg:col-span-1">
                        <div class="appointment-list-container">
                            <div class="appointment-list-header">
                                <h3>Upcoming Appointments</h3>
                            </div>
                            <div id="appointmentsList">
                                <?php if (count($appointments) > 0): ?>
                                    <?php 
                                    $upcoming_count = 0;
                                    foreach ($appointments as $appointment): 
                                        if (in_array($appointment['status'], ['SCHEDULED', 'CONFIRMED'])): 
                                            $upcoming_count++;
                                    ?>
                                        <div class="appointment-item">
                                            <div class="appointment-item-date">
                                                <?php echo date('M j, Y', strtotime($appointment['appointment_date'])); ?>
                                            </div>
                                            <div class="appointment-item-time">
                                                ⏰ <?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?>
                                            </div>
                                            <div class="appointment-item-patient">
                                                <?php echo htmlspecialchars($appointment['child_first_name'] . ' ' . $appointment['child_last_name']); ?>
                                            </div>
                                            <div class="mt-2">
                                                <span class="appointment-item-type"><?php echo htmlspecialchars($appointment['type']); ?></span>
                                                <span class="appointment-item-status <?php echo strtolower($appointment['status']); ?>">
                                                    <?php echo htmlspecialchars($appointment['status']); ?>
                                                </span>
                                            </div>
                                            <div class="text-sm text-gray-600 mt-2">
                                                Dr. <?php echo htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']); ?>
                                            </div>
                                            <?php if (in_array($appointment['status'], ['SCHEDULED', 'CONFIRMED'], true)): ?>
                                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                                    <button type="button"
                                                            data-appointment='<?php echo htmlspecialchars(json_encode([
                                                                "id" => $appointment["id"],
                                                                "appointment_date" => $appointment["appointment_date"],
                                                                "appointment_time" => $appointment["appointment_time"],
                                                                "type" => $appointment["type"],
                                                                "reason" => $appointment["reason"],
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>'
                                                            onclick="openEditAppointmentModal(this)"
                                                            class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                                        Edit
                                                    </button>
                                                    <button type="button"
                                                            onclick="openCancelAppointmentModal(<?php echo (int) $appointment['id']; ?>)"
                                                            class="text-xs text-red-600 hover:text-red-800 font-medium">
                                                        Request Cancellation
                                                    </button>
                                                </div>
                                            <?php elseif ($appointment['status'] === 'CANCELLATION_REQUESTED'): ?>
                                                <div class="mt-3 text-xs text-amber-700 font-medium">
                                                    Cancellation pending clinic approval
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    
                                    if ($upcoming_count == 0): ?>
                                        <div class="no-appointments">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            <p>No upcoming appointments</p>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="no-appointments">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <p>No appointments scheduled</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Side: Calendar -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-lg shadow p-6">
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Select Doctor</label>
                                <select id="doctorSelect" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                                    <option value="">-- Select a Doctor --</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <option value="<?php echo $doctor['id']; ?>">
                                            Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                            <?php if ($doctor['specialization']): ?>
                                                - <?php echo htmlspecialchars($doctor['specialization']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="appointmentCalendar"></div>
                            
                            <div class="mt-6">
                                <div class="flex flex-wrap gap-4 text-sm">
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded bg-blue-100 border-2 border-blue-500 mr-2"></div>
                                        <span class="text-gray-700">Today</span>
                                    </div>
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded bg-green-50 border-2 border-green-500 mr-2"></div>
                                        <span class="text-gray-700">Has Appointments</span>
                                    </div>
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded bg-red-100 border-red-200 mr-2"></div>
                                        <span class="text-gray-700">Doctor Unavailable</span>
                                    </div>
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 rounded bg-gray-100 mr-2"></div>
                                        <span class="text-gray-700">Non-Working Day</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Appointment History -->
                <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                    <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Appointment History</h3>
                    <?php if (count($appointments) > 0): ?>
                        <div class="table-container">
                            <table class="w-full min-w-max">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Date & Time</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Child</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Doctor</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Type</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($appointments, 0, 10) as $appointment): ?>
                                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <div class="font-medium text-gray-800 text-xs md:text-sm">
                                                    <?php echo date('M j, Y', strtotime($appointment['appointment_date'])); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <div class="font-medium text-gray-800 text-xs md:text-sm">
                                                    <?php echo htmlspecialchars($appointment['child_first_name'] . ' ' . $appointment['child_last_name']); ?>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <div class="text-xs md:text-sm text-gray-700">
                                                    Dr. <?php echo htmlspecialchars($appointment['doctor_first_name'] . ' ' . $appointment['doctor_last_name']); ?>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <span class="text-xs md:text-sm"><?php echo htmlspecialchars($appointment['type']); ?></span>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo getStatusBadge($appointment['status']); ?>">
                                                    <?php echo htmlspecialchars($appointment['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 md:py-12">
                            <div class="text-gray-500 mb-4 text-sm md:text-base">No appointment history</div>
                            <button onclick="openBookAppointmentModal()" class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive">
                                Schedule Your First Appointment
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Vaccinations Section -->
            <div id="vaccinations-section" class="section-content p-4 md:p-6 lg:p-8 hidden">
                <div class="mb-6 md:mb-8">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Vaccinations</h1>
                            <p class="text-gray-600 responsive-text">Track your children's immunization records</p>
                        </div>
                        <button onclick="openVaccinationInfoModal()" class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive w-full sm:w-auto">
                            Vaccine Schedule Info
                        </button>
                    </div>
                </div>

                <!-- Vaccination Statistics -->
                <div class="stats-grid mb-6 md:mb-8">
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 rounded-full bg-green-100">
                                <svg class="w-6 h-6 md:w-8 md:h-8 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3 md:ml-4">
                                <?php
                                $completed_count = 0;
                                foreach ($vaccinations as $vax) {
                                    if ($vax['status'] === 'COMPLETED') {
                                        $completed_count++;
                                    }
                                }
                                ?>
                                <div class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $completed_count; ?></div>
                                <div class="text-xs md:text-sm text-gray-600">Completed Vaccinations</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 rounded-full bg-blue-100">
                                <svg class="w-6 h-6 md:w-8 md:h-8 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3 md:ml-4">
                                <?php
                                $scheduled_count = 0;
                                foreach ($vaccinations as $vax) {
                                    if ($vax['status'] === 'SCHEDULED') {
                                        $scheduled_count++;
                                    }
                                }
                                ?>
                                <div class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $scheduled_count; ?></div>
                                <div class="text-xs md:text-sm text-gray-600">Scheduled Vaccinations</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 rounded-full bg-orange-100">
                                <svg class="w-6 h-6 md:w-8 md:h-8 text-orange-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3 md:ml-4">
                                <?php
                                $overdue_count = 0;
                                foreach ($vaccinations as $vax) {
                                    if ($vax['status'] === 'OVERDUE') {
                                        $overdue_count++;
                                    }
                                }
                                ?>
                                <div class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $overdue_count; ?></div>
                                <div class="text-xs md:text-sm text-gray-600">Overdue Vaccinations</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vaccination Records -->
                <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6 mb-6 md:mb-8">
                    <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Vaccination Records</h3>
                    
                    <?php if (count($vaccinations) > 0): ?>
                        <div class="table-container">
                            <table class="w-full min-w-max">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Child Name</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Vaccine</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Dose</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Date</th>
                                        <th class="text-left py-2 px-2 md:py-3 md:px-4 font-semibold text-gray-700 text-xs md:text-sm">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vaccinations as $vaccination): ?>
                                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <div class="font-medium text-gray-800 text-xs md:text-sm truncate max-w-[100px] md:max-w-none">
                                                    <?php echo htmlspecialchars($vaccination['child_first_name'] . ' ' . $vaccination['child_last_name']); ?>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <div class="font-medium text-gray-800 text-xs md:text-sm truncate max-w-[100px] md:max-w-none"><?php echo htmlspecialchars($vaccination['vaccine_name']); ?></div>
                                                <div class="text-xs text-gray-500">
                                                    <span class="px-1 py-0.5 text-xs rounded-full <?php echo getVaccineTypeBadge($vaccination['vaccine_type'] ?? 'ROUTINE'); ?>">
                                                        <?php echo htmlspecialchars($vaccination['vaccine_type'] ?? 'ROUTINE'); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <?php if ($vaccination['dose_number']): ?>
                                                    <span class="font-medium text-xs md:text-sm">Dose <?php echo htmlspecialchars($vaccination['dose_number']); ?></span>
                                                    <?php if ($vaccination['total_doses']): ?>
                                                        <span class="text-xs text-gray-500">of <?php echo htmlspecialchars($vaccination['total_doses']); ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-gray-500 text-xs md:text-sm">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <?php if ($vaccination['administration_date']): ?>
                                                    <div class="text-xs md:text-sm font-medium text-gray-800">
                                                        <?php echo date('M j, Y', strtotime($vaccination['administration_date'])); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-500 text-xs md:text-sm">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 md:py-3 md:px-4">
                                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo getVaccinationStatusBadge($vaccination['status'] ?? 'COMPLETED'); ?>">
                                                    <?php echo htmlspecialchars($vaccination['status'] ?? 'COMPLETED'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 md:py-12">
                            <div class="text-gray-500 mb-4 text-sm md:text-base">No vaccination records found</div>
                            <p class="text-gray-600 mb-6 text-sm md:text-base">Your children's vaccination records will appear here once they are added by healthcare providers.</p>
                            <button onclick="openBookAppointmentModal()" class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive">
                                Schedule Vaccination Appointment
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Vaccine Schedule Information -->
                <div class="content-grid">
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Common Childhood Vaccines</h3>
                        <p class="text-xs text-gray-500 mb-3">Click a vaccine to see detailed information</p>
                        <div class="space-y-2 md:space-y-3">
                            <?php foreach ($common_vaccines as $name => $details): ?>
                                <div class="vaccine-item cursor-pointer flex justify-between items-center p-2 md:p-3 bg-gray-50 rounded-lg hover:bg-pink-50 hover:border-pink-200 border border-transparent transition-all"
                                     onclick="showVaccineDetail('<?php echo htmlspecialchars(addslashes($name)); ?>')">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium text-gray-800 text-sm md:text-base truncate"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="text-xs md:text-sm text-gray-600 truncate"><?php echo htmlspecialchars($details['description']); ?></div>
                                    </div>
                                    <div class="flex items-center ml-2">
                                        <span class="text-xs md:text-sm font-medium text-gray-700 mr-2">
                                            <?php echo is_numeric($details['doses']) ? $details['doses'] . ' doses' : $details['doses']; ?>
                                        </span>
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Vaccine Needs by Child</h3>
                        <div class="space-y-3 md:space-y-4">
                            <?php if (count($children) > 0): ?>
                                <?php foreach ($children as $child): ?>
                                    <?php
                                    $needs = $vaccine_needs_by_child[$child['id']] ?? [];
                                    ?>
                                    <div class="p-3 md:p-4 border border-gray-200 rounded-lg">
                                        <div class="flex justify-between items-center mb-3">
                                            <h4 class="font-semibold text-gray-800 text-sm md:text-base truncate flex-1 mr-2">
                                                <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                            </h4>
                                            <div class="text-xs md:text-sm text-gray-600">
                                                Born <?php echo date('M j, Y', strtotime($child['date_of_birth'])); ?>
                                            </div>
                                        </div>

                                        <?php if (empty($needs)): ?>
                                            <div class="text-sm text-gray-500">No vaccine needs recorded for this child.</div>
                                        <?php else: ?>
                                            <div class="space-y-2">
                                                <?php foreach ($needs as $n): ?>
                                                    <div class="flex items-start justify-between bg-gray-50 p-2 md:p-3 rounded">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-gray-800 text-sm md:text-base truncate">
                                                                <?php echo htmlspecialchars($n['vaccine_name']); ?>
                                                            </div>
                                                            <div class="text-xs text-gray-500">
                                                                <?php
                                                                    $rec = $n['recommended_date'] && $n['recommended_date'] !== '0000-00-00'
                                                                        ? date('M j, Y', strtotime($n['recommended_date']))
                                                                        : ($n['created_at'] ? date('M j, Y', strtotime($n['created_at'])) : '-');
                                                                    echo 'Recommended: ' . $rec;
                                                                ?>
                                                            </div>
                                                            <?php if (!empty($n['notes'])): ?>
                                                                <div class="text-xs text-gray-600 mt-1"><?php echo htmlspecialchars($n['notes']); ?></div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <div class="ml-3 flex flex-col items-end">
                                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo getVaccineNeedBadge($n['status'] ?? 'RECOMMENDED'); ?>">
                                                                <?php echo htmlspecialchars($n['status'] ?? 'RECOMMENDED'); ?>
                                                            </span>
                                                            <?php if (!empty($n['updated_at'])): ?>
                                                                <div class="text-xs text-gray-500 mt-1"><?php echo date('M j, Y', strtotime($n['updated_at'])); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm md:text-base">No children registered</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Profile Section -->
            <div id="profile-section" class="section-content p-4 md:p-6 lg:p-8 hidden">
                <div class="mb-6 md:mb-8">
                    <h1 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">My Profile</h1>
                    <p class="text-gray-600 responsive-text">View and manage your account information</p>
                </div>

                <div class="content-grid">
                    <!-- Profile Information -->
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Profile Information</h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="update_profile" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-grid">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">First Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="profile_first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Last Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="profile_last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" name="profile_email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number <span class="text-red-500">*</span></label>
                                    <input type="tel" name="profile_phone" required pattern="^9\d{9}$" title="10 digits starting with 9"
                                        value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Date of Birth</label>
                                    <input type="date" name="profile_date_of_birth" value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>"
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Gender</label>
                                    <select name="profile_gender" class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                        <?php $g = strtoupper($user['gender'] ?? ''); ?>
                                        <option value="" <?php echo $g === '' ? 'selected' : ''; ?>>Prefer not to say</option>
                                        <option value="MALE"   <?php echo $g === 'MALE'   ? 'selected' : ''; ?>>Male</option>
                                        <option value="FEMALE" <?php echo $g === 'FEMALE' ? 'selected' : ''; ?>>Female</option>
                                        <option value="OTHER"  <?php echo $g === 'OTHER'  ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Home Address</label>
                                <input type="text" name="profile_address" maxlength="255"
                                    value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>"
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                            </div>
                            <div class="pt-2 border-t border-gray-100">
                                <h4 class="text-sm font-semibold text-gray-700 mb-3">Emergency Contact</h4>
                                <div class="form-grid">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Contact Person <span class="text-red-500">*</span></label>
                                        <input type="text" name="profile_emergency_name" required maxlength="100"
                                            value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>"
                                            class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Contact Phone <span class="text-red-500">*</span></label>
                                        <input type="tel" name="profile_emergency_phone" required pattern="^9\d{9}$" title="10 digits starting with 9"
                                            value="<?php echo htmlspecialchars($user['emergency_contact_phone'] ?? ''); ?>"
                                            class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                    </div>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Account Type</label>
                                    <input type="text" value="<?php echo htmlspecialchars(ucfirst(strtolower($user['user_type'] ?? 'Parent'))); ?>" disabled
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 text-sm md:text-base">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Member Since</label>
                                    <input type="text" value="<?php echo isset($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'N/A'; ?>" disabled
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 text-sm md:text-base">
                                </div>
                            </div>
                            <button type="submit" class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive w-full">
                                Update Profile
                            </button>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="card-hover bg-white rounded-xl shadow-lg p-4 md:p-6">
                        <h3 class="text-lg md:text-xl font-inter font-semibold text-gray-800 mb-4">Change Password</h3>
                        <form method="POST" class="space-y-4" id="changePasswordForm">
                            <input type="hidden" name="change_password" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Current Password <span class="text-red-500">*</span></label>
                                <input type="password" name="current_password" required
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">New Password <span class="text-red-500">*</span></label>
                                <input type="password" name="new_password" id="newPassword" required minlength="6"
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <p class="text-xs text-gray-500 mt-1">Minimum 6 characters with uppercase, lowercase, and a number</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password <span class="text-red-500">*</span></label>
                                <input type="password" name="confirm_password" id="confirmPassword" required minlength="6"
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                            </div>
                            <button type="submit" class="btn-primary text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-semibold btn-responsive w-full">
                                Change Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Child Modal -->
    <div id="addChildModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="text-center mb-6 md:mb-8">
                    <h2 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Add Child</h2>
                    <p class="text-gray-600 responsive-text">Add your child's health profile</p>
                </div>
                
                <form method="POST" class="space-y-4 md:space-y-6">
                    <input type="hidden" name="add_child" value="1">

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="child_first_name" required minlength="1" maxlength="50"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" name="child_last_name" required minlength="1" maxlength="50"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date of Birth <span class="text-red-500">*</span></label>
                            <input type="date" name="child_dob" required max="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Gender <span class="text-red-500">*</span></label>
                            <select name="child_gender" required
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">Select gender</option>
                                <option value="MALE">Male</option>
                                <option value="FEMALE">Female</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Blood Type</label>
                            <select name="child_blood_type"
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">Select blood type</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Height (cm)</label>
                            <input type="number" name="child_height" step="0.1" min="0" max="250" inputmode="decimal"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"
                                placeholder="e.g. 110.5">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Weight (kg)</label>
                            <input type="number" name="child_weight" step="0.1" min="0" max="300" inputmode="decimal"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"
                                placeholder="e.g. 25.5">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Known Allergies</label>
                        <textarea name="child_allergies" rows="3" maxlength="500"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"
                                placeholder="List any known allergies..."></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Medical Conditions</label>
                        <textarea name="child_medical_conditions" rows="3" maxlength="500"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"
                                placeholder="Any relevant medical conditions..."></textarea>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4">
                        <button type="submit" class="flex-1 btn-primary text-white py-2 md:py-3 rounded-lg font-semibold text-sm md:text-base">
                            Add Child
                        </button>
                        <button type="button" onclick="closeAddChildModal()"
                                class="flex-1 border border-gray-300 text-gray-700 py-2 md:py-3 rounded-lg font-semibold hover:bg-gray-50 text-sm md:text-base">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Child Modal -->
    <div id="editChildModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="text-center mb-6 md:mb-8">
                    <h2 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Edit Child's Health Profile</h2>
                    <p class="text-gray-600 responsive-text">Update your child's information</p>
                </div>

                <form method="POST" id="editChildForm" class="space-y-4 md:space-y-6">
                    <input type="hidden" name="edit_child" value="1">
                    <input type="hidden" name="child_id" id="edit_child_id">

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name <span class="text-red-500">*</span></label>
                            <input type="text" name="child_first_name" id="edit_child_first_name" required minlength="1" maxlength="50"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" name="child_last_name" id="edit_child_last_name" required minlength="1" maxlength="50"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date of Birth <span class="text-red-500">*</span></label>
                            <input type="date" name="child_dob" id="edit_child_dob" required max="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Gender <span class="text-red-500">*</span></label>
                            <select name="child_gender" id="edit_child_gender" required
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">Select gender</option>
                                <option value="MALE">Male</option>
                                <option value="FEMALE">Female</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Blood Type</label>
                            <select name="child_blood_type" id="edit_child_blood_type"
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">Select blood type</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Height (cm)</label>
                            <input type="number" name="child_height" id="edit_child_height" step="0.1" min="0" max="250" inputmode="decimal"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Weight (kg)</label>
                            <input type="number" name="child_weight" id="edit_child_weight" step="0.1" min="0" max="300" inputmode="decimal"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Known Allergies</label>
                        <textarea name="child_allergies" id="edit_child_allergies" rows="3" maxlength="500"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Medical Conditions</label>
                        <textarea name="child_medical_conditions" id="edit_child_medical_conditions" rows="3" maxlength="500"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"></textarea>
                    </div>

                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4">
                        <button type="submit" class="flex-1 btn-primary text-white py-2 md:py-3 rounded-lg font-semibold text-sm md:text-base">
                            Save Changes
                        </button>
                        <button type="button" onclick="closeEditChildModal()"
                                class="flex-1 border border-gray-300 text-gray-700 py-2 md:py-3 rounded-lg font-semibold hover:bg-gray-50 text-sm md:text-base">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Book Appointment Modal -->
    <div id="bookAppointmentModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="text-center mb-6 md:mb-8">
                    <h2 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Book Appointment</h2>
                    <p class="text-gray-600 responsive-text">Schedule a new appointment for your child</p>
                </div>
                
                <form method="POST" id="bookAppointmentForm" class="space-y-4 md:space-y-6">
                    <input type="hidden" name="book_appointment" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select Child <span class="text-red-500">*</span></label>
                            <select name="appointment_child" id="modalAppointmentChild" required 
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">-- Select Child --</option>
                                <?php foreach ($children as $child): ?>
                                    <option value="<?php echo $child['id']; ?>">
                                        <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select Doctor <span class="text-red-500">*</span></label>
                            <select name="appointment_doctor" id="modalAppointmentDoctor" required 
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">-- Select Doctor --</option>
                                <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['id']; ?>">
                                        Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                        <?php if ($doctor['specialization']): ?>
                                            (<?php echo htmlspecialchars($doctor['specialization']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Appointment Date <span class="text-red-500">*</span></label>
                            <input type="date" id="modalAppointmentDate" name="appointment_date" required 
                                min="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Appointment Time <span class="text-red-500">*</span></label>
                            <select name="appointment_time" id="modalAppointmentTime" required 
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">-- Select Time --</option>
                                <option value="09:00:00">09:00 AM</option>
                                <option value="09:30:00">09:30 AM</option>
                                <option value="10:00:00">10:00 AM</option>
                                <option value="10:30:00">10:30 AM</option>
                                <option value="11:00:00">11:00 AM</option>
                                <option value="11:30:00">11:30 AM</option>
                                <option value="13:00:00">01:00 PM</option>
                                <option value="13:30:00">01:30 PM</option>
                                <option value="14:00:00">02:00 PM</option>
                                <option value="14:30:00">02:30 PM</option>
                                <option value="15:00:00">03:00 PM</option>
                                <option value="15:30:00">03:30 PM</option>
                                <option value="16:00:00">04:00 PM</option>
                                <option value="16:30:00">04:30 PM</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Service Type <span class="text-red-500">*</span></label>
                            <select name="appointment_service" required 
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="CONSULTATION">General Consultation</option>
                                <option value="VACCINATION">Vaccination</option>
                                <option value="CHECKUP">Regular Checkup</option>
                                <option value="FOLLOW_UP">Follow-up Visit</option>
                                <option value="URGENT">Urgent Care</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Notes / Symptoms</label>
                        <textarea name="appointment_notes" rows="3" maxlength="1000"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"
                                placeholder="Please describe any symptoms or specific concerns..."></textarea>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4">
                        <button type="submit" class="flex-1 btn-primary text-white py-2 md:py-3 rounded-lg font-semibold text-sm md:text-base">
                            Book Appointment
                        </button>
                        <button type="button" onclick="closeBookAppointmentModal()"
                                class="flex-1 border border-gray-300 text-gray-700 py-2 md:py-3 rounded-lg font-semibold hover:bg-gray-50 text-sm md:text-base">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Appointment Modal -->
    <div id="editAppointmentModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="text-center mb-6 md:mb-8">
                    <h2 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Edit Appointment</h2>
                    <p class="text-gray-600 responsive-text">Update date, time, or details</p>
                </div>

                <form method="POST" id="editAppointmentForm" class="space-y-4 md:space-y-6">
                    <input type="hidden" name="edit_appointment" value="1">
                    <input type="hidden" name="appointment_id" id="edit_appointment_id">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="form-grid">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Appointment Date <span class="text-red-500">*</span></label>
                            <input type="date" name="appointment_date" id="edit_appointment_date" required
                                min="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Appointment Time <span class="text-red-500">*</span></label>
                            <select name="appointment_time" id="edit_appointment_time" required
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                                <option value="">-- Select Time --</option>
                                <option value="09:00:00">09:00 AM</option>
                                <option value="09:30:00">09:30 AM</option>
                                <option value="10:00:00">10:00 AM</option>
                                <option value="10:30:00">10:30 AM</option>
                                <option value="11:00:00">11:00 AM</option>
                                <option value="11:30:00">11:30 AM</option>
                                <option value="13:00:00">01:00 PM</option>
                                <option value="13:30:00">01:30 PM</option>
                                <option value="14:00:00">02:00 PM</option>
                                <option value="14:30:00">02:30 PM</option>
                                <option value="15:00:00">03:00 PM</option>
                                <option value="15:30:00">03:30 PM</option>
                                <option value="16:00:00">04:00 PM</option>
                                <option value="16:30:00">04:30 PM</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Service Type <span class="text-red-500">*</span></label>
                        <select name="appointment_service" id="edit_appointment_service" required
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base">
                            <option value="CONSULTATION">General Consultation</option>
                            <option value="VACCINATION">Vaccination</option>
                            <option value="CHECKUP">Regular Checkup</option>
                            <option value="FOLLOW_UP">Follow-up Visit</option>
                            <option value="URGENT">Urgent Care</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Notes / Symptoms</label>
                        <textarea name="appointment_notes" id="edit_appointment_notes" rows="3" maxlength="1000"
                                class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm md:text-base"></textarea>
                    </div>

                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4">
                        <button type="submit" class="flex-1 btn-primary text-white py-2 md:py-3 rounded-lg font-semibold text-sm md:text-base">
                            Save Changes
                        </button>
                        <button type="button" onclick="closeEditAppointmentModal()"
                                class="flex-1 border border-gray-300 text-gray-700 py-2 md:py-3 rounded-lg font-semibold hover:bg-gray-50 text-sm md:text-base">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Appointment Modal (request approval) -->
    <div id="cancelAppointmentModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full">
            <div class="p-6">
                <h2 class="text-xl font-inter font-bold text-gray-800 mb-2">Request Cancellation</h2>
                <p class="text-sm text-gray-600 mb-4">
                    Your appointment will not be cancelled immediately — the clinic will review your request and confirm.
                </p>
                <form method="POST">
                    <input type="hidden" name="cancel_appointment" value="1">
                    <input type="hidden" name="cancel_appointment_id" id="cancel_appointment_id">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Reason for cancellation <span class="text-red-500">*</span></label>
                    <textarea name="cancel_reason" id="cancel_reason" rows="3" required maxlength="500"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm mb-4"
                            placeholder="Please tell us why you need to cancel..."></textarea>
                    <div class="flex space-x-3">
                        <button type="submit" class="flex-1 bg-red-600 text-white py-2 rounded-lg font-semibold hover:bg-red-700 text-sm">
                            Submit Request
                        </button>
                        <button type="button" onclick="closeCancelAppointmentModal()"
                                class="flex-1 border border-gray-300 text-gray-700 py-2 rounded-lg font-semibold hover:bg-gray-50 text-sm">
                            Keep Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Vaccination Info Modal -->
    <div id="vaccinationInfoModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="text-center mb-6 md:mb-8">
                    <h2 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2">Childhood Vaccination Schedule</h2>
                    <p class="text-gray-600 responsive-text">Recommended immunization schedule for children</p>
                </div>
                
                <div class="space-y-4 md:space-y-6">
                    <!-- Vaccine schedule content (same as earlier) -->
                    <div class="border-l-4 border-green-500 pl-3 md:pl-4">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-2">At Birth</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center p-2 md:p-3 bg-green-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">BCG</div>
                                    <div class="text-xs md:text-sm text-gray-600">Tuberculosis prevention</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full">Required</span>
                            </div>
                            <div class="flex justify-between items-center p-2 md:p-3 bg-green-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">Hepatitis B</div>
                                    <div class="text-xs md:text-sm text-gray-600">First dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full">Required</span>
                            </div>
                        </div>
                    </div>

                    <!-- 6 Weeks -->
                    <div class="border-l-4 border-blue-500 pl-3 md:pl-4">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-2">6 Weeks</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center p-2 md:p-3 bg-blue-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">DPT</div>
                                    <div class="text-xs md:text-sm text-gray-600">First dose - Diphtheria, Pertussis, Tetanus</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">Required</span>
                            </div>
                            <div class="flex justify-between items-center p-2 md:p-3 bg-blue-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">Polio</div>
                                    <div class="text-xs md:text-sm text-gray-600">First dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">Required</span>
                            </div>
                        </div>
                    </div>

                    <!-- 10 Weeks -->
                    <div class="border-l-4 border-purple-500 pl-3 md:pl-4">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-2">10 Weeks</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center p-2 md:p-3 bg-purple-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">DPT</div>
                                    <div class="text-xs md:text-sm text-gray-600">Second dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded-full">Required</span>
                            </div>
                            <div class="flex justify-between items-center p-2 md:p-3 bg-purple-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">Polio</div>
                                    <div class="text-xs md:text-sm text-gray-600">Second dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded-full">Required</span>
                            </div>
                        </div>
                    </div>

                    <!-- 14 Weeks -->
                    <div class="border-l-4 border-orange-500 pl-3 md:pl-4">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-2">14 Weeks</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center p-2 md:p-3 bg-orange-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">DPT</div>
                                    <div class="text-xs md:text-sm text-gray-600">Third dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded-full">Required</span>
                            </div>
                            <div class="flex justify-between items-center p-2 md:p-3 bg-orange-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">Polio</div>
                                    <div class="text-xs md:text-sm text-gray-600">Third dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded-full">Required</span>
                            </div>
                        </div>
                    </div>

                    <!-- 6-9 Months -->
                    <div class="border-l-4 border-red-500 pl-3 md:pl-4">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-2">6-9 Months</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center p-2 md:p-3 bg-red-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">Vitamin A</div>
                                    <div class="text-xs md:text-sm text-gray-600">First dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">Recommended</span>
                            </div>
                        </div>
                    </div>

                    <!-- 9-12 Months -->
                    <div class="border-l-4 border-indigo-500 pl-3 md:pl-4">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-2">9-12 Months</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center p-2 md:p-3 bg-indigo-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm md:text-base">Measles</div>
                                    <div class="text-xs md:text-sm text-gray-600">First dose</div>
                                </div>
                                <span class="px-2 py-1 text-xs bg-indigo-100 text-indigo-800 rounded-full">Required</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 md:mt-8 p-3 md:p-4 bg-yellow-50 rounded-lg">
                    <h4 class="font-semibold text-yellow-800 mb-2 text-sm md:text-base">Important Notes:</h4>
                    <ul class="text-xs md:text-sm text-yellow-700 list-disc list-inside space-y-1">
                        <li>This is a general guideline - actual schedule may vary based on health conditions</li>
                        <li>Consult with your pediatrician for personalized vaccination schedule</li>
                        <li>Keep vaccination records safe for school admission and travel</li>
                        <li>Report any adverse reactions to your healthcare provider immediately</li>
                    </ul>
                </div>

                <div class="flex justify-center mt-6 md:mt-8">
                    <button type="button" onclick="closeVaccinationInfoModal()" 
                            class="btn-primary text-white px-6 md:px-8 py-2 md:py-3 rounded-lg font-semibold text-sm md:text-base">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload File Modal -->
    <div id="uploadFileModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="text-center mb-6">
                    <h2 id="uploadModalTitle" class="text-2xl font-bold text-gray-800">Upload File</h2>
                    <p id="uploadModalChildInfo" class="text-gray-600 text-sm">Upload a file for the child</p>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="upload_patient_file" value="1">
                    <input type="hidden" name="upload_patient_id" id="upload_patient_id" value="">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select file <span class="text-red-500">*</span></label>
                        <input type="file" name="patient_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="w-full">
                        <p class="text-xs text-gray-500 mt-1">Allowed: pdf, jpg, png, docx. Max 5 MB.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">File Category <span class="text-red-500">*</span></label>
                        <select name="file_category" required class="w-full border rounded p-2 text-sm">
                            <option value="">-- Select category --</option>
                            <option value="LAB_RESULT">Laboratory Results</option>
                            <option value="MRI">MRI Scan</option>
                            <option value="XRAY">X-Ray</option>
                            <option value="PRESCRIPTION">Prescription</option>
                            <option value="REFERRAL">Referral</option>
                            <option value="IMMUNIZATION">Immunization Record</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                        <textarea name="upload_notes" rows="3" class="w-full border rounded p-2 text-sm" placeholder="Describe the document (e.g., lab result, referral)..."></textarea>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-primary text-white px-4 py-2 rounded">Upload</button>
                        <button type="button" onclick="closeUploadModal()" class="flex-1 border border-gray-300 px-4 py-2 rounded">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Medical Records Modal -->
    <div id="medicalRecordsModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto w-full max-w-4xl">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="flex justify-between items-center mb-6 md:mb-8">
                    <div>
                        <h2 class="text-2xl md:text-3xl font-inter font-bold text-gray-800 mb-2" id="medicalRecordsTitle">Medical Records</h2>
                        <p class="text-gray-600 responsive-text" id="medicalRecordsChildInfo"></p>
                    </div>
                    <button type="button" onclick="closeMedicalRecordsModal()" 
                            class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- Tabs for different record types -->
                <div class="mb-6 border-b border-gray-200">
                    <nav class="flex space-x-8">
                        <button onclick="showMedicalRecordsTab('consultations')" 
                                class="py-2 px-1 border-b-2 font-medium text-sm md:text-base medical-records-tab active" data-tab="consultations">
                            Consultations
                        </button>
                        <button onclick="showMedicalRecordsTab('prescriptions')" 
                                class="py-2 px-1 border-b-2 font-medium text-sm md:text-base text-gray-500 hover:text-gray-700 hover:border-gray-300 medical-records-tab" data-tab="prescriptions">
                            Prescriptions
                        </button>
                        <button onclick="showMedicalRecordsTab('vaccinations')" 
                                class="py-2 px-1 border-b-2 font-medium text-sm md:text-base text-gray-500 hover:text-gray-700 hover:border-gray-300 medical-records-tab" data-tab="vaccinations">
                            Vaccinations
                        </button>
                    </nav>
                </div>
                
                <!-- Consultations Tab Content -->
                <div id="consultations-tab" class="medical-records-content">
                    <div class="space-y-4" id="consultations-content">
                        <!-- Consultations will be loaded here -->
                    </div>
                </div>
                
                <!-- Prescriptions Tab Content -->
                <div id="prescriptions-tab" class="medical-records-content hidden">
                    <div class="space-y-4" id="prescriptions-content">
                        <!-- Prescriptions will be loaded here -->
                    </div>
                </div>
                
                <!-- Vaccinations Tab Content -->
                <div id="vaccinations-tab" class="medical-records-content hidden">
                    <div class="space-y-4" id="vaccinations-content">
                        <!-- Vaccinations will be loaded here -->
                    </div>
                </div>
                
                <div class="mt-6 md:mt-8 text-center">
                    <button type="button" onclick="closeMedicalRecordsModal()" 
                            class="btn-primary text-white px-6 md:px-8 py-2 md:py-3 rounded-lg font-semibold text-sm md:text-base">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Vaccine Detail Modal -->
    <div id="vaccineDetailModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-xl shadow-2xl max-h-screen overflow-y-auto w-full max-w-lg">
            <div class="p-4 md:p-6 lg:p-8">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl md:text-2xl font-inter font-bold text-gray-800" id="vaccineDetailName"></h2>
                    <button onclick="closeVaccineDetailModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="flex flex-wrap gap-2 mb-2">
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-pink-100 text-pink-800" id="vaccineDetailDoses"></span>
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-blue-100 text-blue-800" id="vaccineDetailSchedule"></span>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-700 text-sm mb-1">About this vaccine</h4>
                        <p class="text-sm text-gray-600" id="vaccineDetailInfo"></p>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-700 text-sm mb-1">Possible Side Effects</h4>
                        <p class="text-sm text-gray-600" id="vaccineDetailSideEffects"></p>
                    </div>
                </div>
                <div class="mt-6 text-center">
                    <button onclick="closeVaccineDetailModal()" class="btn-primary text-white px-6 py-2 rounded-lg font-semibold text-sm">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Bridge: pass PHP-generated URL to external JS
    window.MEDICAL_RECORDS_URL = '<?php echo basename(__FILE__); ?>';

    // Vaccine detail data
    var vaccineData = <?php echo json_encode($common_vaccines); ?>;

    function showVaccineDetail(name) {
        var vaccine = vaccineData[name];
        if (!vaccine) return;
        document.getElementById('vaccineDetailName').textContent = name;
        document.getElementById('vaccineDetailDoses').textContent = (typeof vaccine.doses === 'number' ? vaccine.doses + ' dose(s)' : vaccine.doses);
        document.getElementById('vaccineDetailSchedule').textContent = 'Schedule: ' + (vaccine.schedule || 'Consult doctor');
        document.getElementById('vaccineDetailInfo').textContent = vaccine.details || vaccine.description || '';
        document.getElementById('vaccineDetailSideEffects').textContent = vaccine.side_effects || 'Consult your pediatrician for information.';
        document.getElementById('vaccineDetailModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeVaccineDetailModal() {
        document.getElementById('vaccineDetailModal').classList.add('hidden');
        document.body.style.overflow = '';
    }
    </script>
    <script>window.CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';</script>
    <script src="js/shared-toast.js"></script>
    <script src="js/parent-dashboard.js"></script>
    <script src="js/notification-bell.js"></script>
    <script>
    // ── Appointment booking: restrict date picker based on doctor's schedule ──
    (function () {
        const doctorSelect = document.getElementById('modalAppointmentDoctor');
        const dateInput    = document.getElementById('modalAppointmentDate');
        if (!doctorSelect || !dateInput) return;

        let availableDates = []; // array of 'YYYY-MM-DD' strings

        function isDateAvailable(dateStr) {
            return availableDates.includes(dateStr);
        }

        async function fetchAvailableSlots(doctorId) {
            dateInput.disabled = true;
            dateInput.value = '';
            dateInput.title = 'Loading available dates…';
            availableDates = [];

            if (!doctorId) {
                dateInput.disabled = false;
                dateInput.title = '';
                return;
            }

            try {
                const res  = await fetch('parent-dashboard.php?action=get_available_slots&doctor_id=' + encodeURIComponent(doctorId));
                const data = await res.json();
                if (data.success && Array.isArray(data.available_dates)) {
                    availableDates = data.available_dates.map(d => d.date);
                }
            } catch (e) {
                console.error('Could not fetch available slots', e);
            }

            dateInput.disabled = availableDates.length === 0;
            dateInput.title = availableDates.length === 0
                ? 'This doctor has no available dates'
                : 'Pick an available date';

            // Set min to first available date (or today)
            const today = new Date().toISOString().split('T')[0];
            const firstAvail = availableDates.find(d => d >= today) || today;
            dateInput.min = firstAvail;

            // If the previously selected date is no longer valid, clear it
            if (dateInput.value && !isDateAvailable(dateInput.value)) {
                dateInput.value = '';
            }
        }

        // Validate selected date against available list
        dateInput.addEventListener('change', function () {
            if (this.value && availableDates.length > 0 && !isDateAvailable(this.value)) {
                (window.showToast || function(m){alert(m);})('The selected date is not available for this doctor. Please choose another date.', 'warning');
                this.value = '';
            }
        });

        doctorSelect.addEventListener('change', function () {
            fetchAvailableSlots(this.value);
        });

        // If doctor is already selected (e.g. form re-open), fetch immediately
        if (doctorSelect.value) fetchAvailableSlots(doctorSelect.value);
    })();
    </script>

    <!-- Cancel-appointment confirm handler (uses shared appConfirm) -->
    <script>
    (function () {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.classList || !form.classList.contains('js-cancel-appt-form')) return;
            if (form.dataset.confirmed === '1') return; // already confirmed; allow submit
            e.preventDefault();
            var submit = function () { form.dataset.confirmed = '1'; form.submit(); };
            if (typeof window.appConfirm === 'function') {
                window.appConfirm('Cancel Appointment', 'Are you sure you want to cancel this appointment? This cannot be undone.', function (ok) {
                    if (ok) submit();
                }, { confirmText: 'Yes, cancel', cancelText: 'Keep it' });
            } else if (confirm('Are you sure you want to cancel this appointment?')) {
                submit();
            }
        });
    })();
    </script>

    <?php if (!empty($__parentFlashes)): ?>
    <script>
    (function () {
        var flashes = <?php echo json_encode($__parentFlashes); ?>;
        function fire() {
            if (typeof window.showToast !== 'function') { return setTimeout(fire, 60); }
            flashes.forEach(function (f, i) {
                setTimeout(function () { window.showToast(f.message, f.type || 'info', 5000); }, i * 120);
            });
        }
        document.addEventListener('DOMContentLoaded', fire);
    })();
    </script>
    <?php endif; ?>
</body>
</html>