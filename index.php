<?php
require 'db_connect.php';
require_once __DIR__ . '/smtp_mailer.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Queue an in-app toast to be rendered on the next page render of index.php.
 * Survives server-side redirects via the PHP session.
 */
if (!function_exists('flash_toast')) {
    function flash_toast($message, $type = 'error') {
        $_SESSION['__flash_toast'] = ['message' => (string)$message, 'type' => (string)$type];
    }
}

/**
 * Load a clinic setting value from the `clinic_settings` table with a fallback.
 * Used by the landing page footer so admins can edit content from the dashboard.
 */
if (!function_exists('clinic_setting')) {
    function clinic_setting($conn, $key, $default = '') {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $res = @mysqli_query($conn, "SELECT setting_key, setting_value FROM clinic_settings");
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $cache[$row['setting_key']] = $row['setting_value'];
                }
            }
        }
        return isset($cache[$key]) && $cache[$key] !== null && $cache[$key] !== ''
            ? $cache[$key]
            : $default;
    }
}

// AJAX endpoint: check if email already exists
if (isset($_GET['action']) && $_GET['action'] === 'check_email' && isset($_GET['email'])) {
    header('Content-Type: application/json; charset=utf-8');
    $checkEmail = trim($_GET['email']);
    if (!filter_var($checkEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => true, 'exists' => false]);
        exit();
    }
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $checkEmail);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = ($result && mysqli_num_rows($result) > 0);
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => true, 'exists' => $exists]);
    exit();
}

// AJAX endpoint: fetch announcement details by ID
if (isset($_GET['action']) && $_GET['action'] === 'get_announcement' && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id']);
    $stmt = mysqli_prepare($conn, "SELECT a.*, u.first_name, u.last_name
                                    FROM announcements a
                                    LEFT JOIN users u ON a.created_by = u.id
                                    WHERE a.id = ? AND a.is_active = 1");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $row['author'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if (empty($row['author'])) $row['author'] = 'Admin';
        $row['date'] = date('M j, Y', strtotime($row['published_at'] ?? $row['created_at']));
        echo json_encode(['success' => true, 'announcement' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
    }
    exit();
}

// AJAX endpoint: verify OTP code for pending registration
if (isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    header('Content-Type: application/json; charset=utf-8');
    handleVerifyOtp($conn);
    exit();
}

// AJAX endpoint: resend OTP code for pending registration
if (isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    header('Content-Type: application/json; charset=utf-8');
    handleResendOtp($conn);
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['login'])) {
        handleLogin($conn);
    } elseif (isset($_POST['register'])) {
        handleRegister($conn);
    }
}


function handleLogin($conn) {
    $email = trim($_POST['loginEmail']);
    $password = $_POST['loginPassword'];

    // Check if user exists - use prepared statement to prevent SQL injection
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        flash_toast('Database error. Please try again.', 'error');
        return;
    }

    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);

        // Check if user is active
        $is_active = true;
        if (isset($user['status'])) {
            $is_active = ($user['status'] == 'active');
        }

        if (!$is_active) {
            flash_toast('Account is deactivated. Please contact the administrator.', 'error');
            return;
        }

        // Verify password
        if (password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['specialization'] = $user['specialization'] ?? '';

            // Log login activity using prepared statement
            $logStmt = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action, ip_address) VALUES (?, 'LOGIN', ?)");
            $userId = $user['id'];
            $ipAddr = $_SERVER['REMOTE_ADDR'];
            mysqli_stmt_bind_param($logStmt, "is", $userId, $ipAddr);

            if (!mysqli_stmt_execute($logStmt)) {
                // If still failing, try without ip_address too
                $logStmt2 = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action) VALUES (?, 'LOGIN')");
                mysqli_stmt_bind_param($logStmt2, "i", $userId);
                mysqli_stmt_execute($logStmt2);
            }

            // Redirect based on user type
            switch ($user['user_type']) {
                case 'PARENT':
                    header("Location: parent-dashboard.php");
                    break;
                case 'DOCTOR':
                case 'DOCTOR_OWNER':
                    header("Location: doctor-dashboard.php");
                    break;
                case 'ADMIN':
                case 'SUPERADMIN':
                    header("Location: admin-dashboard.php");
                    break;
                default:
                    header("Location: parent-dashboard.php");
            }
            exit();
        } else {
            flash_toast('Invalid email or password.', 'error');
        }
    } else {
        flash_toast('Invalid email or password.', 'error');
    }
}

function handleRegister($conn) {
    // PHP 8.1+ turns mysqli errors into mysqli_sql_exception by default. Turn
    // that off so we can inspect return values / errors directly instead of
    // surfacing a generic 500 that shows up to the user as the opaque
    // "Registration failed. Please try again later." toast on the landing page.
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }

    $firstName = trim((string) ($_POST['firstName'] ?? ''));
    $lastName = trim((string) ($_POST['lastName'] ?? ''));
    $email = trim((string) ($_POST['registerEmail'] ?? ''));
    $phone = trim((string) ($_POST['phoneNumber'] ?? ''));
    $password = (string) ($_POST['registerPassword'] ?? '');
    $confirmPassword = (string) ($_POST['confirmPassword'] ?? '');
    $dateOfBirth = trim((string) ($_POST['dateOfBirth'] ?? ''));
    $gender = trim((string) ($_POST['gender'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $emergencyContactName = trim((string) ($_POST['emergencyContactName'] ?? ''));
    $emergencyContactPhone = trim((string) ($_POST['emergencyContactPhone'] ?? ''));
    $userType = 'PARENT';

    // Validate required fields
    if ($firstName === '' || $lastName === '' || $email === '' || $phone === '' || $password === '') {
        flash_toast('Please fill in all required fields.', 'error');
        return;
    }

    // Validate passwords match
    if ($password !== $confirmPassword) {
        flash_toast('Passwords do not match.', 'error');
        return;
    }

    // Validate password strength
    if (strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password) || !preg_match('/[!@#$%^&*]/', $password)) {
        flash_toast('Password must be at least 6 characters with uppercase, lowercase, number, and a special character.', 'error');
        return;
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_toast('Please enter a valid email address.', 'error');
        return;
    }

    // Check if email already exists
    $checkStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($checkStmt, "s", $email);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);

    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        flash_toast('Email already registered. Please use a different email or log in.', 'error');
        mysqli_stmt_close($checkStmt);
        return;
    }
    mysqli_stmt_close($checkStmt);

    // Validate phone number - Philippine format (9XXXXXXXXX)
    $cleanPhone = preg_replace('/\s+/', '', $phone);
    if (!preg_match('/^9\d{9}$/', $cleanPhone)) {
        flash_toast('Please enter a valid Philippine mobile number (10 digits starting with 9).', 'error');
        return;
    }

    // Validate age - must be 18 or older
    if ($dateOfBirth === '' || !strtotime($dateOfBirth)) {
        flash_toast('Please provide a valid date of birth.', 'error');
        return;
    }
    $dobTs = strtotime($dateOfBirth);
    $age = (int) date('Y') - (int) date('Y', $dobTs);
    if (date('md', $dobTs) > date('md')) {
        $age--;
    }
    if ($age < 18) {
        flash_toast('You must be 18 years or older to register.', 'error');
        return;
    }

    // Normalize optional/nullable columns. Empty strings must be converted to
    // NULL because MySQL STRICT_TRANS_TABLES (the default in 5.7+/8.0) rejects
    // '' for DATE and ENUM columns, which was silently failing the INSERT and
    // dropping the user back on the landing page with
    // "Registration failed. Please try again later.".
    $dateOfBirth = ($dateOfBirth !== '' && strtotime($dateOfBirth))
        ? date('Y-m-d', strtotime($dateOfBirth))
        : null;

    $validGenders = ['MALE', 'FEMALE', 'OTHER'];
    $gender = ($gender !== '' && in_array($gender, $validGenders, true)) ? $gender : null;

    $address = ($address !== '') ? $address : null;
    $emergencyContactName = ($emergencyContactName !== '') ? $emergencyContactName : null;
    $emergencyContactPhone = ($emergencyContactPhone !== '')
        ? preg_replace('/\s+/', '', $emergencyContactPhone)
        : null;

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Generate a 6-digit OTP and 10-minute expiry for email verification
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpExpires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    // Insert new user as 'pending' until the OTP is verified. Only after
    // successful OTP verification will the status flip to 'active' and the
    // user be auto-logged-in.
    $sql = "INSERT INTO users (first_name, last_name, email, phone, password, user_type, status, date_of_birth, gender, address, emergency_contact_name, emergency_contact_phone, email_otp_code, email_otp_expires, email_otp_attempts, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, 0, NOW())";
    $insertStmt = mysqli_prepare($conn, $sql);
    if (!$insertStmt) {
        error_log('Registration prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);
        flash_toast('Registration failed. Please try again later.', 'error');
        return;
    }
    mysqli_stmt_bind_param(
        $insertStmt,
        "sssssssssssss",
        $firstName, $lastName, $email, $cleanPhone, $hashedPassword, $userType,
        $dateOfBirth, $gender, $address, $emergencyContactName, $emergencyContactPhone,
        $otp, $otpExpires
    );

    if (mysqli_stmt_execute($insertStmt)) {
        $newUserId = mysqli_insert_id($conn);
        mysqli_stmt_close($insertStmt);

        // Log registration activity
        $logStmt = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action) VALUES (?, 'User registered (pending OTP)')");
        mysqli_stmt_bind_param($logStmt, "i", $newUserId);
        mysqli_stmt_execute($logStmt);
        mysqli_stmt_close($logStmt);

        // Send the OTP email. We still continue even if email dispatch fails
        // so the user can request a resend — but we surface it in the flash.
        $emailSent = send_otp_email_smtp($email, trim($firstName . ' ' . $lastName), $otp);

        // Park the pending user id in session so the OTP modal can verify
        // without re-collecting the email from the user.
        $_SESSION['pending_otp_user_id'] = $newUserId;
        $_SESSION['pending_otp_email']   = $email;

        if ($emailSent) {
            flash_toast('We sent a 6-digit verification code to ' . $email . '. Please enter it to finish signing up.', 'success');
        } else {
            flash_toast('Account created, but we could not send the verification email. Click "Resend code" to try again.', 'error');
        }

        // Redirect back to the landing page with a flag that pops the OTP modal.
        header("Location: index.php?otp=1");
        exit();
    } else {
        $err = mysqli_stmt_error($insertStmt);
        mysqli_stmt_close($insertStmt);
        error_log('Registration failed: ' . $err . ' | SQL: ' . $sql);
        flash_toast('Registration failed. Please try again later.', 'error');
    }
}

/**
 * Verify the 6-digit OTP that was emailed during registration. On success,
 * activate the user, clear the OTP columns, and create the session so the
 * parent lands on their dashboard exactly like the old auto-login flow.
 */
function handleVerifyOtp($conn) {
    $userId = (int) ($_SESSION['pending_otp_user_id'] ?? 0);
    $code   = trim((string) ($_POST['otp_code'] ?? ''));

    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Your verification session expired. Please register again.']);
        return;
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        echo json_encode(['success' => false, 'message' => 'Please enter the 6-digit code from your email.']);
        return;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, first_name, last_name, email, user_type, email_otp_code, email_otp_expires, email_otp_attempts
         FROM users WHERE id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        return;
    }

    // Brute-force protection: cap at 5 wrong attempts per code
    if ((int) $user['email_otp_attempts'] >= 5) {
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.']);
        return;
    }

    // Expiry check
    if (empty($user['email_otp_code']) || empty($user['email_otp_expires']) ||
        strtotime($user['email_otp_expires']) < time()) {
        echo json_encode(['success' => false, 'message' => 'The code has expired. Please request a new one.']);
        return;
    }

    if (!hash_equals((string) $user['email_otp_code'], $code)) {
        $inc = mysqli_prepare($conn, "UPDATE users SET email_otp_attempts = email_otp_attempts + 1 WHERE id = ?");
        mysqli_stmt_bind_param($inc, "i", $userId);
        mysqli_stmt_execute($inc);
        mysqli_stmt_close($inc);
        echo json_encode(['success' => false, 'message' => 'Incorrect code. Please try again.']);
        return;
    }

    // Success — flip to active, clear OTP, stamp email_verified_at
    $now = date('Y-m-d H:i:s');
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users
            SET status = 'active',
                email_verified_at = ?,
                email_otp_code = NULL,
                email_otp_expires = NULL,
                email_otp_attempts = 0
          WHERE id = ?"
    );
    mysqli_stmt_bind_param($upd, "si", $now, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    // Create session & auto-login
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['user_type']  = $user['user_type'];
    $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name']  = $user['last_name'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['specialization'] = '';

    unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_email']);

    echo json_encode([
        'success'  => true,
        'message'  => 'Email verified. Redirecting…',
        'redirect' => 'parent-dashboard.php',
    ]);
}

/**
 * Generate a new OTP for the pending registration and email it.
 */
function handleResendOtp($conn) {
    $userId = (int) ($_SESSION['pending_otp_user_id'] ?? 0);
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'No pending verification found. Please register again.']);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT first_name, last_name, email FROM users WHERE id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No pending verification found. Please register again.']);
        return;
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpExpires = date('Y-m-d H:i:s', time() + 600);
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET email_otp_code = ?, email_otp_expires = ?, email_otp_attempts = 0 WHERE id = ?"
    );
    mysqli_stmt_bind_param($upd, "ssi", $otp, $otpExpires, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    $sent = send_otp_email_smtp(
        $user['email'],
        trim($user['first_name'] . ' ' . $user['last_name']),
        $otp
    );

    echo json_encode([
        'success' => (bool) $sent,
        'message' => $sent
            ? 'A new verification code was sent to ' . $user['email'] . '.'
            : 'We could not send the email right now. Please try again in a moment.',
    ]);
}

// Display success/error messages from session (legacy key)
if (isset($_SESSION['message'])) {
    flash_toast($_SESSION['message'], $_SESSION['message_type'] ?? 'info');
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AlagApp Clinic - Patient Information System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pixi.js/7.3.2/pixi.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="css/shared.css" rel="stylesheet">
    <link href="css/index.css" rel="stylesheet">
    <style>
        :root {
            --primary-pink: #d03664;
            --light-pink: #FFBCD9;
            --dark-text: #333333;
            --light-gray: #f8f0f4;
        }
        
        body {
            font-family: 'Source Sans Pro', sans-serif;
            background: linear-gradient(135deg, #ffffff 0%, #fef5f8 100%);
        }
        
        .font-inter { font-family: 'Inter', sans-serif; }
        
        .text-primary { color: var(--primary-pink); }
        .bg-primary { background-color: var(--primary-pink); }
        .bg-light-pink { background-color: var(--light-pink); }
        
        .hero-bg {
            background: linear-gradient(rgba(255, 107, 154, 0.1), rgba(255, 188, 217, 0.1)), 
                        url('https://images.unsplash.com/photo-1519494026892-80bbd2d6fd0d?ixlib=rb-4.0.3&auto=format&fit=crop&w=1953&q=80') center/cover;
            min-height: 100vh;
        }
        
        .card-hover {
            transition: all 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(255, 107, 154, 0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-pink), var(--light-pink));
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 107, 154, 0.3);
        }
        
        .floating-particles {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            pointer-events: none;
        }
        
        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: var(--light-pink);
            border-radius: 50%;
            opacity: 0.6;
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
        
        .modal-backdrop {
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.4);
        }
        
        .typewriter {
            overflow: hidden;
            border-right: 3px solid var(--primary-pink);
            white-space: nowrap;
            animation: typing 3.5s steps(40, end), blink-caret 0.75s step-end infinite;
        }
        
        @keyframes typing {
            from { width: 0; }
            to { width: 100%; }
        }
        
        @keyframes blink-caret {
            from, to { border-color: transparent; }
            50% { border-color: var(--primary-pink); }
        }
        
        .captcha-container {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        
        .service-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-pink), var(--light-pink));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            transition: all 0.3s ease;
        }
        
        .service-icon:hover {
            transform: scale(1.1) rotate(5deg);
        }
        
        .service-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }
        
        /* Custom Splide Styles for Services Carousel */
        #services-carousel .splide__slide {
            padding: 0.5rem;
        }
        
        #services-carousel .splide__arrow {
            position: static;
            transform: none;
            opacity: 1;
            background: var(--primary-pink);
        }
        
        #services-carousel .splide__arrow:disabled {
            opacity: 0.5;
        }
        
        #services-carousel .splide__arrow svg {
            fill: none;
        }
        
        #services-carousel .splide__progress {
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
        }

        /* Announcement Carousel Styles */
        .announcement-card {
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary-pink);
            background: linear-gradient(to bottom right, white, #fdf2f8);
        }

        .announcement-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(255, 107, 154, 0.15);
            background: linear-gradient(to bottom right, white, #fce7f3);
        }

        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Pink category badges */
        .category-badge {
            background: var(--light-pink);
            color: var(--primary-pink);
            border: 1px solid rgba(255, 107, 154, 0.3);
        }

        /* Splide custom styles for announcements with pink theme */
        #announcements-carousel .splide__slide {
            padding: 0.5rem;
        }

        #announcements-carousel .splide__arrow {
            position: static;
            transform: none;
            opacity: 1;
            background: linear-gradient(135deg, var(--primary-pink), #ff8fab);
            box-shadow: 0 4px 15px rgba(255, 107, 154, 0.3);
        }

        #announcements-carousel .splide__arrow:hover {
            background: linear-gradient(135deg, #ff5a8c, #ff7aa3);
            transform: scale(1.1);
        }

        #announcements-carousel .splide__arrow:disabled {
            opacity: 0.5;
        }

        #announcements-carousel .splide__arrow svg {
            fill: none;
        }

        #announcements-carousel .splide__progress {
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
        }

        /* Pink progress bar */
        #announcements-carousel .splide__progress__bar {
            background: #fce7f3;
        }

        #announcements-carousel .splide__progress__bar__fill {
            background: linear-gradient(90deg, var(--primary-pink), #ff8fab);
        }

        /* Modal pink enhancements */
        .modal-header-pink {
            background: linear-gradient(135deg, var(--primary-pink), #ff8fab);
        }

        /* Mobile responsive handled in css/index.css */

    /* Ensure form elements are readable on mobile */
    input, select, textarea {
        font-size: 16px; /* Prevents zoom on iOS */
    }

    /* Improve focus states for accessibility */
    input:focus, select:focus, textarea:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(208, 54, 100, 0.1);
    }

    /* Style for error states */
    .border-red-500 {
        border-color: #ef4444;
    }

    .ring-red-200 {
        --tw-ring-color: rgba(254, 202, 202, 0.5);
    }

    /* Border colors for validation states */
    .border-green-500 {
        border-color: #10B981 !important;
    }
    
    .border-yellow-500 {
        border-color: #F59E0B !important;
    }
    
    .border-red-500 {
        border-color: #EF4444 !important;
    }
    
    /* Ring colors for validation states */
    .ring-green-200 {
        --tw-ring-color: rgba(187, 247, 208, 0.5);
    }
    
    .ring-yellow-200 {
        --tw-ring-color: rgba(253, 230, 138, 0.5);
    }
    
    .ring-red-200 {
        --tw-ring-color: rgba(254, 202, 202, 0.5);
    }
    
    /* Progress bar colors */
    .bg-green-500 {
        background-color: #10B981;
    }
    
    .bg-yellow-500 {
        background-color: #F59E0B;
    }
    
    .bg-red-500 {
        background-color: #EF4444;
    }
    
    /* Background colors for strength indicators */
    .bg-green-50 {
        background-color: #ECFDF5;
    }
    
    .bg-yellow-50 {
        background-color: #FFFBEB;
    }
    
    .bg-red-50 {
        background-color: #FEF2F2;
    }

    @keyframes pulse-once {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }
    
    .animate-pulse-once {
        animation: pulse-once 0.3s ease-in-out;
    }
    
    .requirement-met {
        background-color: rgba(34, 197, 94, 0.1);
        padding: 2px 4px;
        border-radius: 4px;
        margin: 1px 0;
    }
    
    .requirement-not-met {
        padding: 2px 4px;
        border-radius: 4px;
        margin: 1px 0;
    }
    
    /* Smooth transitions for input states */
    input, select, textarea {
        transition: all 0.3s ease;
    }
    
    input:focus, select:focus, textarea:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(208, 54, 100, 0.1);
    }
    
    /* Custom scrollbar for modal */
    #registerModal > div::-webkit-scrollbar {
        width: 6px;
    }
    
    #registerModal > div::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    #registerModal > div::-webkit-scrollbar-thumb {
        background: var(--primary-pink);
        border-radius: 4px;
    }
    
    /* Responsive adjustments handled in css/index.css */
    @media (max-width: 640px) {
        input, select, textarea {
            font-size: 16px !important; /* Prevents zoom on iOS */
        }
    }
    
    /* Progress bar animation */
    #passwordStrengthBar {
        transition: width 0.5s ease-in-out, background-color 0.5s ease;
    }
    
    /* Requirement indicator animation */
    #passwordRequirements div {
        transition: all 0.3s ease;
    }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white/95 backdrop-blur-sm shadow-sm z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <h1 class="text-2xl font-inter font-bold text-primary">AlagApp</h1>
                    </div>
                </div>
                
                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-8">
                        <a href="#home" class="text-gray-700 hover:text-primary px-3 py-2 text-sm font-medium transition-colors">Home</a>
                        <a href="#services" class="text-gray-700 hover:text-primary px-3 py-2 text-sm font-medium transition-colors">Services</a>
                        <a href="#doctors" class="text-gray-700 hover:text-primary px-3 py-2 text-sm font-medium transition-colors">Doctors</a>
                        <a href="#about" class="text-gray-700 hover:text-primary px-3 py-2 text-sm font-medium transition-colors">About</a>
                        <button onclick="openLoginModal()" class="btn-primary text-white px-6 py-2 rounded-lg font-medium">Login/Sign up</button>
                    </div>
                </div>
                
                <div class="md:hidden">
                    <button onclick="toggleMobileMenu()" class="text-gray-700 hover:text-primary">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile menu -->
        <div id="mobileMenu" class="md:hidden hidden bg-white border-t">
            <div class="px-2 pt-2 pb-3 space-y-1">
                <a href="#home" class="block px-3 py-2 text-gray-700 hover:text-primary">Home</a>
                <a href="#services" class="block px-3 py-2 text-gray-700 hover:text-primary">Services</a>
                <a href="#doctors" class="block px-3 py-2 text-gray-700 hover:text-primary">Doctors</a>
                <a href="#about" class="block px-3 py-2 text-gray-700 hover:text-primary">About</a>
                <button onclick="openLoginModal()" class="w-full text-left px-3 py-2 text-primary font-medium">Login/Sign up</button>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-bg relative flex items-center justify-center">
        <div class="floating-particles">
            <div class="particle" style="left: 10%; animation-delay: 0s;"></div>
            <div class="particle" style="left: 20%; animation-delay: 1s;"></div>
            <div class="particle" style="left: 30%; animation-delay: 2s;"></div>
            <div class="particle" style="left: 40%; animation-delay: 3s;"></div>
            <div class="particle" style="left: 50%; animation-delay: 4s;"></div>
            <div class="particle" style="left: 60%; animation-delay: 5s;"></div>
            <div class="particle" style="left: 70%; animation-delay: 1.5s;"></div>
            <div class="particle" style="left: 80%; animation-delay: 2.5s;"></div>
            <div class="particle" style="left: 90%; animation-delay: 3.5s;"></div>
        </div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-3xl sm:text-5xl md:text-7xl font-inter font-bold text-gray-800 mb-6">
                    <span class="typewriter">Caring for Little Ones</span>
                </h1>
                <p class="text-base sm:text-xl md:text-2xl text-gray-600 mb-8 leading-relaxed">
                    Comprehensive pediatric patient information system designed for modern healthcare.
                    Streamline appointments, track vaccinations, and manage patient records with ease.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center px-4">
                    <button onclick="openRegisterModal()" class="btn-primary text-white px-6 sm:px-8 py-3 sm:py-4 rounded-lg text-base sm:text-lg font-semibold w-full sm:w-auto">
                        Get Started Today
                    </button>
                    <button onclick="scrollToServices()" class="border-2 border-primary text-primary px-6 sm:px-8 py-3 sm:py-4 rounded-lg text-base sm:text-lg font-semibold hover:bg-primary hover:text-white transition-all w-full sm:w-auto">
                        Explore Services
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services" class="py-12 sm:py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-2xl sm:text-4xl font-inter font-bold text-gray-800 mb-4">Our Services</h2>
                <p class="text-base sm:text-xl text-gray-600 max-w-3xl mx-auto">
                    Comprehensive pediatric healthcare services designed to meet the unique needs of children and families.
                </p>
            </div>
            
            <!-- Services Carousel (dynamic from DB) -->
            <div class="splide" id="services-carousel">
                <div class="splide__track">
                    <ul class="splide__list">
                        <?php
                        // Fetch active services from the database
                        $servicesQuery = "SELECT id, name, description, duration, cost FROM services WHERE active = 1 ORDER BY id ASC";
                        $servicesResult = mysqli_query($conn, $servicesQuery);

                        // Fallback default icon (stethoscope) used when no specific match
                        $defaultServiceIcon = '<path d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zM10 4h4v2h-4V4zm6 11h-3v3h-2v-3H8v-2h3v-3h2v3h3v2z"/>';

                        // Map common service names to icons to keep the existing visual feel
                        $serviceIcons = [
                            'vaccination' => '<path d="M11 1v4H8l4 4 4-4h-3V1h-2zm-4.83 7L2 12.17l1.41 1.41L5 12l1.41 1.41L5 14.83 6.41 16.24 8 14.66l1.41 1.41-1.41 1.42 1.41 1.41L11 17.32V21h2v-3.68l1.59 1.58 1.41-1.41-1.41-1.42L16 15.07l1.59 1.59 1.41-1.42L17.59 13.83 19 12.41 17.59 11 16 12.59 14.59 11 16 9.59 14.59 8.17 13 9.76 11.41 8.17z"/>',
                            'checkup'     => '<path d="M12 12.75c1.63 0 3.07.39 4.24.9 1.08.48 1.76 1.56 1.76 2.73V18H6v-1.61c0-1.18.68-2.26 1.76-2.73 1.17-.52 2.61-.91 4.24-.91zM4 13c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm1.13 1.1c-.37-.06-.74-.1-1.13-.1-.99 0-1.93.21-2.78.58C.48 14.9 0 15.62 0 16.43V18h4.5v-1.61c0-.83.23-1.61.63-2.29zM20 13c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm4 3.43c0-.81-.48-1.53-1.22-1.85-.85-.37-1.79-.58-2.78-.58-.39 0-.76.04-1.13.1.4.68.63 1.46.63 2.29V18H24v-1.57zM12 6c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3z"/>',
                            'clearance'   => '<path d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82L8.6 22.5l3.4-1.47 3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z"/>',
                            'referral'    => '<path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>',
                            'ear'         => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>',
                            'certif'      => '<path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zM6 20V4h7v5h5v11H6zm5-5.5l-1.5 1.5L8 16.5 11.5 20l7-7-1.5-1.5L11 17z"/>',
                        ];

                        $pickServiceIcon = function ($name) use ($serviceIcons, $defaultServiceIcon) {
                            $n = strtolower((string)$name);
                            foreach ($serviceIcons as $needle => $path) {
                                if (strpos($n, $needle) !== false) return $path;
                            }
                            return $defaultServiceIcon;
                        };

                        $hasService = false;
                        if ($servicesResult && mysqli_num_rows($servicesResult) > 0) {
                            while ($svc = mysqli_fetch_assoc($servicesResult)) {
                                $hasService = true;
                                $name = htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8');
                                $desc = htmlspecialchars($svc['description'] ?? '', ENT_QUOTES, 'UTF-8');
                                if ($desc === '') $desc = 'Professional pediatric service available at AlagApp Clinic.';
                                $cost = (float)$svc['cost'];
                                $dur  = (int)$svc['duration'];
                                $iconPath = $pickServiceIcon($svc['name']);
                                ?>
                                <li class="splide__slide">
                                    <div class="card-hover bg-white rounded-xl shadow-lg p-8 text-center border border-gray-100 h-full flex flex-col">
                                        <div class="service-icon">
                                            <svg viewBox="0 0 24 24" fill="white"><?php echo $iconPath; ?></svg>
                                        </div>
                                        <h3 class="text-2xl font-inter font-semibold text-gray-800 mb-3"><?php echo $name; ?></h3>
                                        <p class="text-gray-600 mb-4 flex-1"><?php echo $desc; ?></p>
                                        <div class="flex items-center justify-center gap-3 text-sm text-gray-500 mb-4">
                                            <?php if ($cost > 0): ?>
                                                <span class="font-semibold text-primary">&#8369;<?php echo number_format($cost, 2); ?></span>
                                            <?php endif; ?>
                                            <?php if ($dur > 0): ?>
                                                <span><?php echo $dur; ?> min</span>
                                            <?php endif; ?>
                                        </div>
                                        <button onclick="openRegisterModal()" class="text-primary font-semibold hover:underline">Book Now &rarr;</button>
                                    </div>
                                </li>
                                <?php
                            }
                        }

                        if (!$hasService):
                            // Gentle fallback message if the services table is empty
                        ?>
                        <li class="splide__slide">
                            <div class="card-hover bg-white rounded-xl shadow-lg p-8 text-center border border-gray-100 h-full">
                                <div class="service-icon">
                                    <svg viewBox="0 0 24 24" fill="white"><?php echo $defaultServiceIcon; ?></svg>
                                </div>
                                <h3 class="text-2xl font-inter font-semibold text-gray-800 mb-4">Services Coming Soon</h3>
                                <p class="text-gray-600 mb-6">We are updating our service catalog. Please check back soon.</p>
                                <button onclick="openRegisterModal()" class="text-primary font-semibold hover:underline">Register Now &rarr;</button>
                            </div>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <!-- Carousel Progress Bar -->
                <div class="splide__progress mt-8">
                    <div class="splide__progress__bar bg-gray-200 h-1 rounded-full">
                        <div class="splide__progress__bar__fill bg-primary h-full rounded-full"></div>
                    </div>
                </div>
                
                <!-- Custom Navigation -->
                <div class="splide__arrows flex justify-center mt-6 space-x-4">
                    <button class="splide__arrow splide__arrow--prev bg-primary text-white p-3 rounded-full hover:bg-opacity-90 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </button>
                    <button class="splide__arrow splide__arrow--next bg-primary text-white p-3 rounded-full hover:bg-opacity-90 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Announcements Carousel Section -->
    <section id="announcements" class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-2xl sm:text-4xl font-inter font-bold text-gray-800 mb-4">Latest Announcements</h2>
                <p class="text-base sm:text-xl text-gray-600 max-w-3xl mx-auto">
                    Stay updated with the latest news and important updates from AlagApp Clinic.
                </p>
            </div>
            
            <!-- Announcements Carousel -->
            <div class="splide" id="announcements-carousel">
                <div class="splide__track">
                    <ul class="splide__list">
                        <?php
                        // Fetch active announcements from database with author name
                        $announcementQuery = "SELECT a.*, u.first_name, u.last_name
                                              FROM announcements a
                                              LEFT JOIN users u ON a.created_by = u.id
                                              WHERE a.is_active = 1
                                              AND (a.expires_at IS NULL OR a.expires_at > NOW())
                                              ORDER BY a.created_at DESC";
                        $announcementResult = mysqli_query($conn, $announcementQuery);

                        if ($announcementResult && mysqli_num_rows($announcementResult) > 0) {
                            while ($announcement = mysqli_fetch_assoc($announcementResult)) {
                                $formattedDate = date('M j, Y', strtotime($announcement['published_at'] ?? $announcement['created_at']));
                                $authorName = trim(($announcement['first_name'] ?? '') . ' ' . ($announcement['last_name'] ?? ''));
                                if (empty($authorName)) $authorName = 'Admin';
                                $categoryClass = getCategoryClass($announcement['category']);
                        ?>
                        <li class="splide__slide">
                            <div class="announcement-card bg-white rounded-xl shadow-lg border border-gray-100 p-6 h-full">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="<?php echo $categoryClass; ?> px-3 py-1 rounded-full text-sm font-medium">
                                        <?php echo htmlspecialchars($announcement['category']); ?>
                                    </span>
                                    <span class="text-sm text-gray-500"><?php echo $formattedDate; ?></span>
                                </div>

                                <h3 class="text-xl font-inter font-semibold text-gray-800 mb-3">
                                    <?php echo htmlspecialchars($announcement['title']); ?>
                                </h3>

                                <p class="text-gray-600 mb-4 line-clamp-3">
                                    <?php echo htmlspecialchars(substr(strip_tags($announcement['content']), 0, 200)); ?>
                                </p>

                                <div class="flex items-center justify-between mt-auto">
                                    <span class="text-sm text-gray-500">By: <?php echo htmlspecialchars($authorName); ?></span>
                                    <button onclick="openAnnouncementModal(<?php echo $announcement['id']; ?>)"
                                            class="text-primary hover:text-primary-dark text-sm font-medium transition-colors">
                                        Read More &rarr;
                                    </button>
                                </div>
                            </div>
                        </li>
                        <?php
                            }
                        } else {
                        ?>
                        <li class="splide__slide">
                            <div class="announcement-card bg-white rounded-xl shadow-lg border border-gray-100 p-6 h-full text-center">
                                <div class="service-icon mx-auto mb-4">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2ZM20 16H5.2L4 17.2V4H20V16Z"/>
                                    </svg>
                                </div>
                                <h3 class="text-xl font-inter font-semibold text-gray-800 mb-3">No Announcements</h3>
                                <p class="text-gray-600">Check back later for updates and important information.</p>
                            </div>
                        </li>
                        <?php
                        }

                        // Helper function for category styling
                        function getCategoryClass($category) {
                            switch (strtoupper($category)) {
                                case 'MAINTENANCE':
                                    return 'bg-yellow-100 text-yellow-800';
                                case 'HEALTH_ADVISORY':
                                    return 'bg-red-100 text-red-800';
                                case 'EVENT':
                                    return 'bg-green-100 text-green-800';
                                case 'PROMOTION':
                                    return 'bg-purple-100 text-purple-800';
                                default:
                                    return 'bg-blue-100 text-blue-800';
                            }
                        }
                        ?>
                    </ul>
                </div>
                
                <!-- Carousel Progress Bar -->
                <div class="splide__progress mt-8">
                    <div class="splide__progress__bar bg-gray-200 h-1 rounded-full">
                        <div class="splide__progress__bar__fill bg-primary h-full rounded-full"></div>
                    </div>
                </div>
                
                <!-- Custom Navigation -->
                <div class="splide__arrows flex justify-center mt-6 space-x-4">
                    <button class="splide__arrow splide__arrow--prev bg-primary text-white p-3 rounded-full hover:bg-opacity-90 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </button>
                    <button class="splide__arrow splide__arrow--next bg-primary text-white p-3 rounded-full hover:bg-opacity-90 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Announcement Detail Modal -->
    <div id="announcementModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto border-2 border-primary">
            <div class="p-8">
                <!-- Modal Header with Pink Background -->
                <div class="bg-gradient-to-r from-primary to-pink-400 -m-8 mb-6 p-6 rounded-t-xl">
                    <div class="flex items-center justify-between text-white">
                        <div>
                            <span id="modalCategory" class="bg-white/20 px-3 py-1 rounded-full text-sm font-medium backdrop-blur-sm"></span>
                            <span id="modalDate" class="text-pink-100 text-sm ml-3"></span>
                        </div>
                        <button onclick="closeAnnouncementModal()" class="text-white hover:text-pink-100 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <h2 id="modalTitle" class="text-2xl font-inter font-bold text-white mt-4"></h2>
                    <p id="modalAuthor" class="text-pink-100 text-sm mt-2"></p>
                </div>
                
                <!-- Modal Content -->
                <div id="announcementContent" class="prose prose-lg max-w-none text-gray-600">
                    <!-- Content will be loaded here via JavaScript -->
                </div>
                
                <div class="mt-8 text-center">
                    <button onclick="closeAnnouncementModal()" class="btn-primary text-white px-8 py-3 rounded-lg font-medium hover:shadow-lg transition-all">
                        Close Announcement
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Doctors Section -->
    <section id="doctors" class="py-12 sm:py-20 bg-light-pink/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-2xl sm:text-4xl font-inter font-bold text-gray-800 mb-4">Our Medical Team</h2>
                <p class="text-base sm:text-xl text-gray-600 max-w-3xl mx-auto">
                    Meet our experienced pediatric specialists dedicated to providing exceptional care for your children.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php
                // Fetch all active doctors from the database
                $doctorQuery = "SELECT id, first_name, last_name, specialization, years_of_experience FROM users WHERE user_type IN ('DOCTOR', 'DOCTOR_OWNER') AND status = 'active' ORDER BY years_of_experience DESC LIMIT 6";
                $doctorResult = mysqli_query($conn, $doctorQuery);

                if ($doctorResult && mysqli_num_rows($doctorResult) > 0) {
                    $doctorColors = ['from-pink-400 to-rose-400', 'from-blue-400 to-indigo-400', 'from-green-400 to-teal-400', 'from-purple-400 to-violet-400', 'from-orange-400 to-amber-400', 'from-cyan-400 to-sky-400'];
                    $colorIndex = 0;

                    while ($doctor = mysqli_fetch_assoc($doctorResult)) {
                        $gradient = $doctorColors[$colorIndex % count($doctorColors)];
                        $colorIndex++;
                        $initials = strtoupper(substr($doctor['first_name'], 0, 1) . substr($doctor['last_name'], 0, 1));
                        $experience = $doctor['years_of_experience'] ? $doctor['years_of_experience'] . '+ years' : '';
                ?>
                <?php
                    $docFullName  = 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
                    $docSpecialty = $doctor['specialization'] ?? 'Pediatrician';
                ?>
                <div class="card-hover bg-white rounded-xl shadow-lg overflow-hidden cursor-pointer"
                     onclick="showDoctorProfile(this)"
                     data-doctor-id="<?php echo (int) $doctor['id']; ?>"
                     data-doctor-name="<?php echo htmlspecialchars($docFullName, ENT_QUOTES); ?>"
                     data-doctor-specialty="<?php echo htmlspecialchars($docSpecialty, ENT_QUOTES); ?>"
                     data-doctor-experience="<?php echo htmlspecialchars($experience, ENT_QUOTES); ?>"
                     data-doctor-initials="<?php echo htmlspecialchars($initials, ENT_QUOTES); ?>"
                     data-doctor-gradient="<?php echo htmlspecialchars($gradient, ENT_QUOTES); ?>">
                    <div class="bg-gradient-to-r <?php echo $gradient; ?> p-6 text-center">
                        <div class="w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-3">
                            <span class="text-white text-2xl font-bold"><?php echo htmlspecialchars($initials); ?></span>
                        </div>
                        <h3 class="text-xl font-inter font-bold text-white hover:underline">
                            <?php echo htmlspecialchars($docFullName); ?>
                        </h3>
                        <p class="text-white/80 text-xs mt-1">Click card to view profile</p>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center mb-3">
                            <svg class="w-5 h-5 text-primary mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($docSpecialty); ?></span>
                        </div>
                        <?php if ($experience): ?>
                        <div class="flex items-center mb-3">
                            <svg class="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="text-gray-600"><?php echo htmlspecialchars($experience); ?> experience</span>
                        </div>
                        <?php endif; ?>
                        <button type="button"
                                onclick="event.stopPropagation(); openRegisterModal();"
                                class="w-full mt-3 btn-primary text-white py-2.5 rounded-lg font-medium text-sm">
                            Book Appointment
                        </button>
                    </div>
                </div>
                <?php
                    }
                } else {
                ?>
                <div class="col-span-full text-center py-12">
                    <p class="text-gray-500 text-lg">Our medical team information will be available soon.</p>
                </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-12 sm:py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-2xl sm:text-4xl font-inter font-bold text-gray-800 mb-6">Why Choose AlagApp?</h2>
                    <p class="text-base sm:text-xl text-gray-600 mb-8">
                        Our comprehensive patient information system streamlines healthcare delivery, making it easier for parents, doctors, and administrators to focus on what matters most - your child's health.
                    </p>
                    
                    <div class="space-y-6">
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-primary rounded-full flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Easy Appointment Booking</h3>
                                <p class="text-gray-600">Schedule appointments online with real-time availability and instant confirmation.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-primary rounded-full flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Vaccination Tracking</h3>
                                <p class="text-gray-600">Keep track of your child's immunization schedule with automated reminders.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-primary rounded-full flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Secure Medical Records</h3>
                                <p class="text-gray-600">Access and manage your child's health information securely from anywhere.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-primary rounded-full flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">24/7 Access</h3>
                                <p class="text-gray-600">Manage appointments and view records anytime, anywhere with our secure platform.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="relative">
                    <img src="https://images.unsplash.com/photo-1532938911079-1b06ac7ceec7?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80" alt="Happy children in clinic" class="rounded-xl shadow-lg w-full">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent rounded-xl"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="text-white pt-16 pb-8 relative overflow-hidden" style="background: linear-gradient(135deg, var(--primary-pink), var(--light-pink));">
        <div class="absolute inset-0 opacity-10 pointer-events-none" aria-hidden="true">
            <div class="absolute -top-20 -left-20 w-80 h-80 bg-primary rounded-full blur-3xl"></div>
            <div class="absolute -bottom-20 -right-20 w-80 h-80 bg-light-pink rounded-full blur-3xl"></div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12">
                <!-- Brand column -->
                <div class="lg:col-span-1">
                    <h3 class="text-2xl font-inter font-bold text-white mb-3">AlagApp Clinic</h3>
                    <p class="text-white/90 text-sm leading-relaxed mb-4">
                        Comprehensive pediatric healthcare management — caring for your little ones with love, technology, and expertise.
                    </p>
                    <div class="flex space-x-3">
                        <a href="#" aria-label="Twitter" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white flex items-center justify-center text-white hover:text-primary transition-all hover:-translate-y-0.5">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M22.46 6c-.77.35-1.6.58-2.46.69.88-.53 1.56-1.37 1.88-2.38-.83.5-1.75.85-2.72 1.05C18.37 4.5 17.26 4 16 4c-2.35 0-4.27 1.92-4.27 4.29 0 .34.04.67.11.98C8.28 9.09 5.11 7.38 3 4.79c-.37.63-.58 1.37-.58 2.15 0 1.49.75 2.81 1.91 3.56-.71 0-1.37-.2-1.95-.5v.03c0 2.08 1.48 3.82 3.44 4.21a4.22 4.22 0 0 1-1.93.07 4.28 4.28 0 0 0 4 2.98 8.521 8.521 0 0 1-5.33 1.84c-.34 0-.68-.02-1.02-.06C3.44 20.29 5.7 21 8.12 21 16 21 20.33 14.46 20.33 8.79c0-.19 0-.37-.01-.56.84-.6 1.56-1.36 2.14-2.23z"/></svg>
                        </a>
                        <a href="#" aria-label="Facebook" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white flex items-center justify-center text-white hover:text-primary transition-all hover:-translate-y-0.5">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>
                        </a>
                        <a href="#" aria-label="LinkedIn" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white flex items-center justify-center text-white hover:text-primary transition-all hover:-translate-y-0.5">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.852 3.37-1.852 3.601 0 4.267 2.37 4.267 5.455v6.288zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.063 2.063 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452z"/></svg>
                        </a>
                        <a href="#" aria-label="Instagram" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white flex items-center justify-center text-white hover:text-primary transition-all hover:-translate-y-0.5">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zm0 10.162a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                        </a>
                    </div>
                </div>

                <!-- Navigation -->
                <div>
                    <h4 class="font-inter font-semibold text-white mb-4 uppercase text-sm tracking-wider">Explore</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#home" class="text-white/90 hover:text-white transition-colors">Home</a></li>
                        <li><a href="#services" class="text-white/90 hover:text-white transition-colors">Services</a></li>
                        <li><a href="#about" class="text-white/90 hover:text-white transition-colors">About Us</a></li>
                        <li><a href="#announcements" class="text-white/90 hover:text-white transition-colors">Announcements</a></li>
                        <li><a href="#" onclick="document.getElementById('loginModal').classList.remove('hidden'); return false;" class="text-white/90 hover:text-white transition-colors">Patient Login</a></li>
                    </ul>
                </div>

                <!-- Services -->
                <div>
                    <h4 class="font-inter font-semibold text-white mb-4 uppercase text-sm tracking-wider">Services</h4>
                    <ul class="space-y-2 text-sm">
                        <li class="text-white/90">General Pediatric Checkups</li>
                        <li class="text-white/90">Vaccinations & Immunization</li>
                        <li class="text-white/90">Developmental Assessments</li>
                        <li class="text-white/90">Growth Monitoring</li>
                        <li class="text-white/90">Consultations & Prescriptions</li>
                    </ul>
                </div>

                <!-- Contact (values are editable by admins in System Settings → "Get In Touch") -->
                <div>
                    <h4 class="font-inter font-semibold text-white mb-4 uppercase text-sm tracking-wider">Get In Touch</h4>
                    <ul class="space-y-3 text-sm text-white/90">
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span><?php echo htmlspecialchars(clinic_setting($conn, 'contact_address', 'Manila, Philippines')); ?></span>
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <span><?php echo htmlspecialchars(clinic_setting($conn, 'contact_phone', '+63 (2) 1234 5678')); ?></span>
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <span><?php echo htmlspecialchars(clinic_setting($conn, 'contact_email', 'hello@alagapp.clinic')); ?></span>
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span><?php echo htmlspecialchars(clinic_setting($conn, 'contact_hours', 'Mon – Sat: 8:00 AM – 6:00 PM')); ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-white/30 pt-6 flex flex-col md:flex-row items-center justify-between gap-3">
                <p class="text-white/90 text-xs text-center md:text-left">
                    © <?php echo date('Y'); ?> AlagApp Clinic. All rights reserved.
                </p>
                <div class="flex flex-wrap justify-center gap-x-5 gap-y-1 text-xs">
                    <a href="#" onclick="showTermsModal(); return false;" class="text-white/90 hover:text-white transition-colors">Terms of Service</a>
                    <a href="#" onclick="showPrivacyModal(); return false;" class="text-white/90 hover:text-white transition-colors">Privacy Policy</a>
                    <a href="#announcements" class="text-white/90 hover:text-white transition-colors">Announcements</a>
                    <span class="text-white/90">Made with <span class="text-white">&hearts;</span> for Filipino families</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- Login Modal -->
    <div id="loginModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 transform transition-all">
            <div class="p-8">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-inter font-bold text-gray-800 mb-2">Welcome Back</h2>
                    <p class="text-gray-600">Sign in to your AlagApp account</p>
                </div>
                
                <form id="loginForm" method="POST" onsubmit="return validateLoginForm()">
                    <input type="hidden" name="login" value="1">
                    
                    <div class="space-y-6">
                        <div>
                            <label for="loginEmail" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                            <input type="email" id="loginEmail" name="loginEmail" required 
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                placeholder="Enter your email"
                                value="<?php echo isset($_POST['loginEmail']) ? htmlspecialchars($_POST['loginEmail']) : ''; ?>">
                        </div>
                        
                        <div>
                            <label for="loginPassword" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                            <input type="password" id="loginPassword" name="loginPassword" required 
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                placeholder="Enter your password">
                            <div class="mt-1 flex justify-end">
                                <button type="button" onclick="togglePasswordVisibility('loginPassword')" class="text-sm text-primary hover:underline">
                                    Show Password
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full btn-primary text-white py-3 rounded-lg font-semibold transition-all duration-200 hover:scale-105">
                            <span id="loginButtonText">Sign In</span>
                            <div id="loginSpinner" class="hidden inline-block ml-2">
                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                            </div>
                        </button>
                    </div>
                </form>
                
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-600">
                        Don't have an account? 
                        <button onclick="switchToRegister()" class="text-primary font-semibold hover:underline">Sign up</button>
                    </p>
                </div>
                
                <button onclick="closeLoginModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Register Modal - Complete Redesign -->
    <div id="registerModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[95vh] overflow-y-auto border-2 border-primary">
            <!-- Modal Header -->
            <div class="modal-header-pink p-6 rounded-t-xl">
                <div class="flex items-center justify-between">
                    <div class="text-white">
                        <h2 class="text-3xl font-inter font-bold">Join AlagApp Clinic</h2>
                        <p class="text-pink-100 mt-2">Create your parent account to get started</p>
                    </div>
                    <button onclick="closeRegisterModal()" class="text-white hover:text-pink-100 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            
            <!-- Registration Form -->
            <div class="p-8">
                <form id="registerForm" method="POST" onsubmit="return validateRegisterForm()">
                    <input type="hidden" name="register" value="1">
                    
                    <!-- Section 1: Personal Information -->
                    <div class="mb-8">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-primary" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                            </svg>
                            Personal Information
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- First Name -->
                            <div>
                                <label for="firstName" class="block text-sm font-medium text-gray-700 mb-2">
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="text" id="firstName" name="firstName" required 
                                        class="w-full px-4 py-3 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                        placeholder="Enter first name"
                                        value="<?php echo isset($_POST['firstName']) ? htmlspecialchars($_POST['firstName']) : ''; ?>">
                                    <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Last Name -->
                            <div>
                                <label for="lastName" class="block text-sm font-medium text-gray-700 mb-2">
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="text" id="lastName" name="lastName" required 
                                        class="w-full px-4 py-3 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                        placeholder="Enter last name"
                                        value="<?php echo isset($_POST['lastName']) ? htmlspecialchars($_POST['lastName']) : ''; ?>">
                                    <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Date of Birth -->
                            <div>
                                <label for="dateOfBirth" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date of Birth <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="date" id="dateOfBirth" name="dateOfBirth" required
                                        class="w-full px-4 py-3 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                        max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                                        title="You must be at least 18 years old to register.">
                                    <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Gender -->
                            <div>
                                <label for="gender" class="block text-sm font-medium text-gray-700 mb-2">
                                    Gender <span class="text-red-500">*</span>
                                </label>
                                <select id="gender" name="gender" required 
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200 appearance-none">
                                    <option value="">Select gender</option>
                                    <option value="MALE">Male</option>
                                    <option value="FEMALE">Female</option>
                                    <option value="OTHER">Other / Prefer not to say</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 2: Contact & Address -->
                    <div class="mb-8">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-primary" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                            </svg>
                            Contact Information
                        </h3>
                        
                        <div class="space-y-6">
                            <!-- Email Address -->
                            <div>
                                <label for="registerEmail" class="block text-sm font-medium text-gray-700 mb-2">
                                    Email Address <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="email" id="registerEmail" name="registerEmail" required 
                                        class="w-full px-4 py-3 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                        placeholder="your.email@example.com"
                                        value="<?php echo isset($_POST['registerEmail']) ? htmlspecialchars($_POST['registerEmail']) : ''; ?>"
                                        onblur="validateEmail()">
                                    <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/>
                                            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/>
                                        </svg>
                                    </div>
                                </div>
                                <div id="emailError" class="mt-1 text-sm text-red-600 hidden"></div>
                            </div>
                            
                            <!-- Phone Number -->
                            <div>
                                <label for="phoneNumber" class="block text-sm font-medium text-gray-700 mb-2">
                                    Phone Number <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500">+63</span>
                                    </div>
                                    <input type="tel" id="phoneNumber" name="phoneNumber" required
                                        class="w-full pl-16 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                        placeholder="9XX XXX XXXX"
                                        maxlength="12"
                                        title="Philippine mobile number format: 9XXXXXXXXX (10 digits starting with 9)"
                                        value="<?php echo isset($_POST['phoneNumber']) ? htmlspecialchars($_POST['phoneNumber']) : ''; ?>"
                                        oninput="formatPhoneNumber(this)">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Philippine mobile number format: 9XXXXXXXXX</p>
                            </div>
                            
                            <!-- Address -->
                            <div>
                                <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                                    Address
                                </label>
                                <textarea id="address" name="address" rows="2"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200 resize-none"
                                    placeholder="Enter your complete address (optional)"></textarea>
                            </div>
                            
                            <!-- Emergency Contact Information -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="emergencyContactName" class="block text-sm font-medium text-gray-700 mb-2">
                                        Emergency Contact Name
                                    </label>
                                    <input type="text" id="emergencyContactName" name="emergencyContactName"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                        placeholder="Full name of emergency contact">
                                </div>
                                <div>
                                    <label for="emergencyContactPhone" class="block text-sm font-medium text-gray-700 mb-2">
                                        Emergency Contact Phone
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <span class="text-gray-500">+63</span>
                                        </div>
                                        <input type="tel" id="emergencyContactPhone" name="emergencyContactPhone"
                                            class="w-full pl-16 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                                            placeholder="9XX XXX XXXX"
                                            pattern="[9]\d{9}"
                                            maxlength="10"
                                            title="Philippine mobile number format: 9XXXXXXXXX">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 3: Account Security -->
                    <div class="mb-8">
                        <h3 class="text-xl font-inter font-semibold text-gray-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-primary" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                            </svg>
                            Account Security
                        </h3>
                        
                        <div class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Password -->
                                <div>
                                    <label for="registerPassword" class="block text-sm font-medium text-gray-700 mb-2">
                                        Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="registerPassword" name="registerPassword" required 
                                            class="w-full px-4 py-3 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200 password-strength-input"
                                            placeholder="Create secure password"
                                            oninput="checkPasswordStrength(this.value)"
                                            onfocus="showPasswordRequirements()"
                                            onblur="hidePasswordRequirements()">
                                        <button type="button" onclick="togglePasswordVisibility('registerPassword')" 
                                                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                            <svg class="w-5 h-5 eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </button>
                                    </div>
                                    
                                    <!-- Password Strength Indicator -->
                                    <div class="mt-2 flex justify-between items-center">
                                        <div id="passwordStrength" class="text-sm font-medium"></div>
                                    </div>
                                </div>
                                
                                <!-- Confirm Password -->
                                <div>
                                    <label for="confirmPassword" class="block text-sm font-medium text-gray-700 mb-2">
                                        Confirm Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" id="confirmPassword" name="confirmPassword" required 
                                            class="w-full px-4 py-3 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200 password-match-input"
                                            placeholder="Confirm your password"
                                            oninput="checkPasswordMatch()">
                                        <button type="button" onclick="togglePasswordVisibility('confirmPassword')" 
                                                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                            <svg class="w-5 h-5 eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <div id="passwordMatch" class="mt-2 text-sm hidden"></div>
                                </div>
                            </div>
                            
                            <!-- Password Requirements Panel -->
                            <div id="passwordRequirements" class="mt-4 hidden bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                                <p class="text-sm font-medium text-gray-700 mb-3">Password Requirements:</p>
                                <div class="space-y-2">
                                    <div id="reqLength" class="flex items-center transition-all duration-300">
                                        <span class="mr-2 text-gray-400">○</span>
                                        <span class="text-xs text-gray-600">At least 6 characters</span>
                                    </div>
                                    <div id="reqUppercase" class="flex items-center transition-all duration-300">
                                        <span class="mr-2 text-gray-400">○</span>
                                        <span class="text-xs text-gray-600">One uppercase letter (A-Z)</span>
                                    </div>
                                    <div id="reqLowercase" class="flex items-center transition-all duration-300">
                                        <span class="mr-2 text-gray-400">○</span>
                                        <span class="text-xs text-gray-600">One lowercase letter (a-z)</span>
                                    </div>
                                    <div id="reqNumber" class="flex items-center transition-all duration-300">
                                        <span class="mr-2 text-gray-400">○</span>
                                        <span class="text-xs text-gray-600">One number (0-9)</span>
                                    </div>
                                    <div id="reqSpecial" class="flex items-center transition-all duration-300">
                                        <span class="mr-2 text-gray-400">○</span>
                                        <span class="text-xs text-gray-600">One special character (!@#$%^&*)</span>
                                    </div>
                                </div>
                                
                                <!-- Visual Strength Meter -->
                                <div class="mt-3">
                                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                                        <span>Weak</span>
                                        <span>Medium</span>
                                        <span>Strong</span>
                                    </div>
                                    <div id="passwordStrengthVisual" class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div id="passwordStrengthBar" class="h-full bg-gray-400 transition-all duration-300" style="width: 0%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Terms & Conditions Notice -->
                    <div class="mb-8">
                        <div class="p-4 bg-pink-50 rounded-lg border border-pink-200 text-sm text-gray-700">
                            By creating an account you agree to the
                            <button type="button" onclick="showTermsModal()" class="text-primary font-semibold underline hover:no-underline">Terms of Service and Privacy Policy</button>.
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="flex flex-col sm:flex-row gap-4">
                        <button type="button" onclick="closeRegisterModal()" 
                                class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-all duration-200">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 btn-primary text-white py-3 rounded-lg font-semibold transition-all duration-200 hover:scale-[1.02] flex items-center justify-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            <span id="registerButtonText">Create Account</span>
                            <div id="registerSpinner" class="hidden ml-2">
                                <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-white"></div>
                            </div>
                        </button>
                    </div>
                </form>
                
                <!-- Login Link -->
                <div class="mt-8 pt-6 border-t border-gray-200 text-center">
                    <p class="text-sm text-gray-600">
                        Already have an account? 
                        <button onclick="switchToLogin()" class="text-primary font-semibold hover:underline ml-1">
                            Sign in to your account
                        </button>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- OTP Verification Modal -->
    <div id="otpModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 transform transition-all">
            <div class="modal-header-pink p-6 rounded-t-xl text-white text-center">
                <h2 class="text-2xl font-inter font-bold">Verify Your Email</h2>
                <p class="text-pink-100 text-sm mt-2">
                    We sent a 6-digit code to
                    <strong id="otpEmailDisplay"><?php echo htmlspecialchars($_SESSION['pending_otp_email'] ?? ''); ?></strong>
                </p>
            </div>
            <div class="p-8">
                <form id="otpForm" onsubmit="return handleVerifyOtp(event)">
                    <div class="mb-5">
                        <label for="otpCode" class="block text-sm font-medium text-gray-700 mb-2 text-center">
                            Enter Verification Code
                        </label>
                        <input type="text" id="otpCode" name="otp_code" required
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               autocomplete="one-time-code"
                               class="w-full text-center text-3xl tracking-[10px] font-mono font-bold
                                      px-4 py-3 border-2 border-gray-300 rounded-lg
                                      focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="000000">
                        <p class="text-xs text-gray-500 text-center mt-2">
                            The code expires in 10 minutes.
                        </p>
                    </div>

                    <div id="otpMessage" class="hidden mb-4 p-3 rounded-lg text-sm"></div>

                    <button type="submit" id="otpVerifyBtn"
                            class="w-full btn-primary text-white py-3 rounded-lg font-semibold mb-3 flex items-center justify-center">
                        <span id="otpVerifyText">Verify &amp; Continue</span>
                        <div id="otpVerifySpinner" class="hidden ml-2">
                            <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-white"></div>
                        </div>
                    </button>

                    <div class="text-center text-sm text-gray-600">
                        Didn't receive the code?
                        <button type="button" id="otpResendBtn" onclick="handleResendOtp()"
                                class="text-primary font-semibold hover:underline ml-1">
                            Resend code
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Doctor Profile Modal -->
    <div id="doctorProfileModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full mx-4 overflow-hidden">
            <div id="doctorProfileHeader" class="bg-gradient-to-r from-pink-400 to-rose-400 p-8 text-center relative">
                <button type="button" onclick="closeDoctorProfile()"
                        class="absolute top-3 right-3 text-white hover:text-pink-100" aria-label="Close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
                <div class="w-24 h-24 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-3">
                    <span id="doctorProfileInitials" class="text-white text-3xl font-bold">--</span>
                </div>
                <h3 id="doctorProfileName" class="text-2xl font-inter font-bold text-white">Dr. Name</h3>
                <p id="doctorProfileSpecialty" class="text-white/90 text-sm mt-1">Specialty</p>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="bg-light-pink/20 rounded-lg p-4 text-center">
                        <p class="text-xs text-gray-500 uppercase">Experience</p>
                        <p id="doctorProfileExperience" class="text-lg font-semibold text-primary mt-1">—</p>
                    </div>
                    <div class="bg-light-pink/20 rounded-lg p-4 text-center">
                        <p class="text-xs text-gray-500 uppercase">Patients</p>
                        <p class="text-lg font-semibold text-primary mt-1">Accepting</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 leading-relaxed mb-6">
                    A dedicated pediatric specialist at AlagApp Clinic, committed to providing the highest standard
                    of care for your children. Book an appointment below to schedule a consultation.
                </p>
                <div class="flex gap-3">
                    <button type="button" onclick="closeDoctorProfile()"
                            class="flex-1 py-3 rounded-lg border border-gray-300 text-gray-700 font-medium hover:bg-gray-50">
                        Close
                    </button>
                    <button type="button" onclick="closeDoctorProfile(); openRegisterModal();"
                            class="flex-1 btn-primary text-white py-3 rounded-lg font-semibold">
                        Book Appointment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotPasswordModal" class="fixed inset-0 modal-backdrop hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 transform transition-all">
            <div class="p-8">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-inter font-bold text-gray-800 mb-2">Reset Password</h2>
                    <p class="text-gray-600">Enter your email to receive reset instructions</p>
                </div>
                
                <form id="forgotPasswordForm" onsubmit="return handleForgotPassword(event)">
                    <div class="space-y-6">
                        <div>
                            <label for="forgotEmail" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                            <input type="email" id="forgotEmail" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                                placeholder="Enter your registered email">
                        </div>
                        <div id="forgotPasswordMessage" class="hidden"></div>

                        <button type="submit" id="forgotPasswordBtn" class="w-full btn-primary text-white py-3 rounded-lg font-semibold">
                            Send Reset Instructions
                        </button>
                    </div>
                </form>
                
                <div class="mt-6 text-center">
                    <button onclick="closeForgotPassword()" class="text-primary font-semibold hover:underline">Back to login</button>
                </div>
                
                <button onclick="closeForgotPassword()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Terms & Conditions Modal -->
    <div id="termsModal" class="fixed inset-0 modal-backdrop hidden z-[60] flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-pink-100 bg-gradient-to-r from-pink-50 to-white rounded-t-xl">
                <h2 class="text-xl font-inter font-bold text-primary">Terms of Service &amp; Privacy Policy</h2>
                <button onclick="closeTermsModal()" class="text-gray-400 hover:text-primary" aria-label="Close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div id="termsModalScroll" class="overflow-y-auto px-6 py-5 text-sm text-gray-700 space-y-4 flex-1">
                <h3 class="font-semibold text-gray-900">1. Acceptance of Terms</h3>
                <p>By creating an AlagApp Clinic account, you accept these terms. AlagApp provides pediatric clinic management tools for parents, doctors, and administrators.</p>

                <h3 class="font-semibold text-gray-900">2. Account Responsibilities</h3>
                <p>You agree to provide accurate information, keep your password confidential, and notify the clinic promptly of any unauthorized access. You are responsible for all activity under your account.</p>

                <h3 class="font-semibold text-gray-900">3. Medical Disclaimer</h3>
                <p>AlagApp is a management platform — not a substitute for professional medical advice. Always consult your doctor for medical decisions. In emergencies, contact your nearest hospital immediately.</p>

                <h3 class="font-semibold text-gray-900">4. Privacy &amp; Data Protection</h3>
                <p>We handle personal and health information in line with the Philippine Data Privacy Act (RA 10173). Your data is used only to provide clinic services (appointments, medical records, vaccinations, prescriptions) and is never sold to third parties.</p>
                <p>We use prepared statements, password hashing, and session protections to secure your data. You may request access, correction, or deletion of your data at any time.</p>

                <h3 class="font-semibold text-gray-900">5. Children's Information</h3>
                <p>Parents/guardians are responsible for information entered about minors. Medical records are only visible to the parent account and clinic staff involved in the child's care.</p>

                <h3 class="font-semibold text-gray-900">6. Acceptable Use</h3>
                <p>Do not misuse the service, attempt to access other users' data, upload malicious files, or use automated scripts to extract information.</p>

                <h3 class="font-semibold text-gray-900">7. Account Termination</h3>
                <p>We may deactivate accounts that violate these terms or attempt fraud. You may request deletion of your account through clinic staff.</p>

                <h3 class="font-semibold text-gray-900">8. Changes to These Terms</h3>
                <p>Terms may be updated for legal or operational reasons. Continued use of the service after an update constitutes acceptance of the revised terms.</p>

                <h3 class="font-semibold text-gray-900">9. Contact</h3>
                <p>Questions about these Terms or our Privacy Policy? Reach us at hello@alagapp.clinic.</p>

                <p class="text-xs text-gray-500 pt-2 border-t border-gray-200">Last updated: <?php echo date('F j, Y'); ?></p>

                <div id="termsScrollHint" class="text-center text-xs text-pink-600 py-2">
                    ↓ Please scroll to the bottom to acknowledge ↓
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-xl flex flex-col sm:flex-row-reverse gap-3">
                <button id="termsAgreeBtn" type="button" onclick="agreeToTerms()" disabled
                    class="btn-primary text-white px-5 py-2.5 rounded-lg font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                    I have read and agree
                </button>
                <button type="button" onclick="closeTermsModal()" class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-white">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script src="js/shared-toast.js"></script>
    <script>
    /* ====== Terms & Conditions gating ====== */
    function showTermsModal() {
        var m = document.getElementById('termsModal');
        if (!m) return;
        m.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        var scroll = document.getElementById('termsModalScroll');
        if (scroll) {
            scroll.scrollTop = 0;
            setTimeout(checkTermsScroll, 50);
            scroll.addEventListener('scroll', checkTermsScroll);
        }
    }
    function closeTermsModal() {
        var m = document.getElementById('termsModal');
        if (!m) return;
        m.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
    function checkTermsScroll() {
        var el = document.getElementById('termsModalScroll');
        var btn = document.getElementById('termsAgreeBtn');
        var hint = document.getElementById('termsScrollHint');
        if (!el || !btn) return;
        // Enable once within 20px of the bottom
        var atBottom = (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 20);
        if (atBottom) {
            btn.disabled = false;
            btn.classList.remove('disabled:opacity-50');
            if (hint) hint.classList.add('hidden');
        }
    }
    function agreeToTerms() {
        var cb = document.getElementById('terms');
        var status = document.getElementById('termsReadStatus');
        if (cb) {
            cb.disabled = false;
            cb.dataset.viewed = 'true';
            cb.checked = true;
            cb.classList.remove('cursor-not-allowed');
            cb.classList.add('cursor-pointer');
        }
        if (status) {
            status.classList.remove('text-pink-700');
            status.classList.add('text-green-700');
            status.innerHTML = '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Terms reviewed — you may now check the box.';
        }
        var errEl = document.getElementById('termsError');
        if (errEl) errEl.classList.add('hidden');
        closeTermsModal();
    }
    function ensureTermsViewed(e) {
        var cb = document.getElementById('terms');
        if (cb && cb.dataset.viewed !== 'true') {
            e.preventDefault();
            showTermsModal();
            return false;
        }
        return true;
    }
    function showPrivacyModal() { showTermsModal(); }
    window.showTermsModal = showTermsModal;
    window.closeTermsModal = closeTermsModal;
    window.showPrivacyModal = showPrivacyModal;
    window.agreeToTerms = agreeToTerms;
    window.ensureTermsViewed = ensureTermsViewed;
    </script>
    <script src="js/index.js"></script>
    <?php if (!empty($_SESSION['__flash_toast'])): ?>
    <script>
    (function () {
        var flash = <?php echo json_encode($_SESSION['__flash_toast']); ?>;
        function fire() {
            if (typeof window.showToast === 'function') {
                window.showToast(flash.message, flash.type || 'info', 5000);
            } else {
                setTimeout(fire, 60);
            }
        }
        document.addEventListener('DOMContentLoaded', fire);
    })();
    </script>
    <?php unset($_SESSION['__flash_toast']); endif; ?>

    <!-- Email OTP Verification Logic -->
    <script>
    (function () {
        const otpModal = document.getElementById('otpModal');
        if (!otpModal) return;

        function openOtpModal() {
            otpModal.classList.remove('hidden');
            const reg = document.getElementById('registerModal');
            if (reg) reg.classList.add('hidden');
            const inp = document.getElementById('otpCode');
            if (inp) setTimeout(function(){ inp.focus(); }, 100);
        }
        function closeOtpModal() { otpModal.classList.add('hidden'); }
        function showOtpMessage(type, msg) {
            const box = document.getElementById('otpMessage');
            if (!box) return;
            box.textContent = msg;
            box.className = 'mb-4 p-3 rounded-lg text-sm ' +
                (type === 'error' ? 'bg-red-50 text-red-700 border border-red-200'
                                  : 'bg-green-50 text-green-700 border border-green-200');
            box.classList.remove('hidden');
        }

        // Auto-open when redirected from register
        const params = new URLSearchParams(window.location.search);
        if (params.get('otp') === '1') {
            openOtpModal();
        }

        // Numeric-only + auto-trim
        const codeInput = document.getElementById('otpCode');
        if (codeInput) {
            codeInput.addEventListener('input', function () {
                this.value = this.value.replace(/\D+/g, '').slice(0, 6);
            });
        }

        window.handleVerifyOtp = async function (e) {
            e.preventDefault();
            const btn = document.getElementById('otpVerifyBtn');
            const txt = document.getElementById('otpVerifyText');
            const spin = document.getElementById('otpVerifySpinner');
            const code = (document.getElementById('otpCode').value || '').trim();
            if (code.length !== 6) {
                showOtpMessage('error', 'Please enter the full 6-digit code.');
                return false;
            }
            btn.disabled = true; txt.textContent = 'Verifying…'; spin.classList.remove('hidden');

            try {
                const fd = new FormData();
                fd.append('action', 'verify_otp');
                fd.append('otp_code', code);
                const res = await fetch('index.php', {
                    method: 'POST', body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json().catch(function(){ return {}; });
                if (data && data.success) {
                    showOtpMessage('success', 'Email verified! Redirecting…');
                    setTimeout(function () {
                        window.location.href = data.redirect || 'parent-dashboard.php';
                    }, 700);
                } else {
                    showOtpMessage('error', (data && data.message) || 'Invalid or expired code.');
                    btn.disabled = false; txt.textContent = 'Verify & Continue'; spin.classList.add('hidden');
                }
            } catch (err) {
                showOtpMessage('error', 'Network error. Please try again.');
                btn.disabled = false; txt.textContent = 'Verify & Continue'; spin.classList.add('hidden');
            }
            return false;
        };

        window.handleResendOtp = async function () {
            const resend = document.getElementById('otpResendBtn');
            resend.disabled = true;
            const original = resend.textContent;
            resend.textContent = 'Sending…';
            try {
                const fd = new FormData();
                fd.append('action', 'resend_otp');
                const res = await fetch('index.php', {
                    method: 'POST', body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json().catch(function(){ return {}; });
                if (data && data.success) {
                    showOtpMessage('success', 'A new code has been sent to your email.');
                } else {
                    showOtpMessage('error', (data && data.message) || 'Could not resend code.');
                }
            } catch (err) {
                showOtpMessage('error', 'Network error. Please try again.');
            }
            // simple 30-second cooldown
            let left = 30;
            resend.textContent = 'Resend in ' + left + 's';
            const iv = setInterval(function () {
                left--;
                if (left <= 0) {
                    clearInterval(iv);
                    resend.disabled = false;
                    resend.textContent = original;
                } else {
                    resend.textContent = 'Resend in ' + left + 's';
                }
            }, 1000);
        };
    })();
    </script>

    <!-- Doctor Profile Modal Logic -->
    <script>
    window.showDoctorProfile = function (cardEl) {
        if (!cardEl) return;
        var modal = document.getElementById('doctorProfileModal');
        if (!modal) return;
        var header = document.getElementById('doctorProfileHeader');
        var name = cardEl.getAttribute('data-doctor-name') || '';
        var specialty = cardEl.getAttribute('data-doctor-specialty') || 'Pediatrician';
        var experience = cardEl.getAttribute('data-doctor-experience') || '—';
        var initials = cardEl.getAttribute('data-doctor-initials') || '';
        var gradient = cardEl.getAttribute('data-doctor-gradient') || 'from-pink-400 to-rose-400';

        // Swap header gradient
        if (header) {
            header.className = 'p-8 text-center relative bg-gradient-to-r ' + gradient;
        }
        document.getElementById('doctorProfileName').textContent = name;
        document.getElementById('doctorProfileSpecialty').textContent = specialty;
        document.getElementById('doctorProfileExperience').textContent = experience || '—';
        document.getElementById('doctorProfileInitials').textContent = initials;
        modal.classList.remove('hidden');
    };
    window.closeDoctorProfile = function () {
        var modal = document.getElementById('doctorProfileModal');
        if (modal) modal.classList.add('hidden');
    };
    </script>
</body>
</html>
