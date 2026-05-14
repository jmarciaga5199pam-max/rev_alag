<?php
/**
 * debug_otp.php
 * Upload to public_html, visit it once, then DELETE immediately.
 * Visit: https://alagapp.site/debug_otp.php
 */

// Show ALL errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db_connect.php';

$results = [];

// 1. Check smtp_mailer.php exists and loads
$mailerPath = __DIR__ . '/smtp_mailer.php';
if (!file_exists($mailerPath)) {
    $results[] = ['❌', 'smtp_mailer.php NOT FOUND at: ' . $mailerPath];
} else {
    $results[] = ['✅', 'smtp_mailer.php found at: ' . $mailerPath];
    require_once $mailerPath;

    if (!function_exists('send_otp_email_smtp')) {
        $results[] = ['❌', 'send_otp_email_smtp() function does NOT exist after including smtp_mailer.php'];
    } else {
        $results[] = ['✅', 'send_otp_email_smtp() function exists'];
    }
}

// 2. Check OTP columns exist in users table
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_otp_code'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $results[] = ['✅', 'email_otp_code column exists in users table'];
} else {
    $results[] = ['❌', 'email_otp_code column MISSING from users table — run the SQL migration!'];
}

$colCheck2 = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_otp_expires'");
if ($colCheck2 && mysqli_num_rows($colCheck2) > 0) {
    $results[] = ['✅', 'email_otp_expires column exists'];
} else {
    $results[] = ['❌', 'email_otp_expires column MISSING'];
}

$colCheck3 = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_otp_attempts'");
if ($colCheck3 && mysqli_num_rows($colCheck3) > 0) {
    $results[] = ['✅', 'email_otp_attempts column exists'];
} else {
    $results[] = ['❌', 'email_otp_attempts column MISSING'];
}

// 3. Check users table has 'pending' as valid status enum value
$enumCheck = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status'");
if ($enumCheck) {
    $row = mysqli_fetch_assoc($enumCheck);
    $type = $row['Type'] ?? '';
    if (strpos($type, 'pending') !== false) {
        $results[] = ['✅', "status column includes 'pending': $type"];
    } else {
        $results[] = ['❌', "status column does NOT include 'pending'. Current type: $type — This will cause registration INSERT to fail silently!"];
    }
}

// 4. Try a test INSERT into users (then roll it back)
$testEmail = 'otp_debug_test_' . time() . '@test.invalid';
$testOtp   = '123456';
$testExp   = date('Y-m-d H:i:s', time() + 600);
$testPwd   = password_hash('testpassword', PASSWORD_DEFAULT);

mysqli_begin_transaction($conn);
$ins = mysqli_prepare($conn,
    "INSERT INTO users (first_name, last_name, email, phone, password, user_type, status,
     date_of_birth, gender, address, emergency_contact_name, emergency_contact_phone,
     email_otp_code, email_otp_expires, email_otp_attempts, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, 0, NOW())"
);

if (!$ins) {
    $results[] = ['❌', 'Prepare INSERT failed: ' . mysqli_error($conn)];
} else {
    $fn = 'Test'; $ln = 'User'; $ph = '9123456789'; $ut = 'PARENT';
    $dob = null; $gen = null; $addr = null; $ecn = null; $ecp = null;
    mysqli_stmt_bind_param($ins, 'sssssssssssss',
        $fn, $ln, $testEmail, $ph, $testPwd, $ut,
        $dob, $gen, $addr, $ecn, $ecp, $testOtp, $testExp
    );

    if (mysqli_stmt_execute($ins)) {
        $newId = mysqli_insert_id($conn);
        $results[] = ['✅', "Test INSERT succeeded — new user id=$newId (will be rolled back)"];
    } else {
        $results[] = ['❌', 'Test INSERT failed: ' . mysqli_stmt_error($ins)];
    }
}
mysqli_rollback($conn); // Always rollback — this is just a test
$results[] = ['ℹ️', 'Transaction rolled back — no test data was saved'];

// 5. Actually send a test OTP email
if (function_exists('send_otp_email_smtp')) {
    $results[] = ['ℹ️', 'Attempting to send test OTP email to geremillo@gmail.com...'];
    $sent = send_otp_email_smtp('geremillo@gmail.com', 'Test User', '987654');
    if ($sent) {
        $results[] = ['✅', 'OTP email sent successfully! Check geremillo@gmail.com inbox/spam'];
    } else {
        $results[] = ['❌', 'send_otp_email_smtp() returned false — check PHP error log'];
    }
}

// 6. Check PHP error log location
$results[] = ['ℹ️', 'PHP error_log location: ' . (ini_get('error_log') ?: 'not set / check Hostinger logs')];
$results[] = ['ℹ️', 'PHP version: ' . PHP_VERSION];

// ── Output ──────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>OTP Debug — AlagApp</title>
<style>
  body  { font-family: monospace; background: #1e1e2e; color: #cdd6f4; padding: 2rem; }
  h1    { color: #f38ba8; }
  table { border-collapse: collapse; width: 100%; }
  td    { padding: 10px 14px; border-bottom: 1px solid #313244; vertical-align: top; }
  td:first-child { font-size: 1.3rem; width: 2.5rem; }
  .ok   { color: #a6e3a1; }
  .err  { color: #f38ba8; }
  .info { color: #89dceb; }
</style>
</head>
<body>
<h1>🩺 AlagApp OTP Debug</h1>
<table>
<?php foreach ($results as [$icon, $msg]): ?>
  <?php
    $cls = 'info';
    if ($icon === '✅') $cls = 'ok';
    if ($icon === '❌') $cls = 'err';
  ?>
  <tr class="<?= $cls ?>">
    <td><?= $icon ?></td>
    <td><?= htmlspecialchars($msg) ?></td>
  </tr>
<?php endforeach; ?>
</table>
<p style="color:#6c7086;font-size:0.8rem;margin-top:2rem;">
  ⚠️ Delete this file immediately after use: <code><?= __FILE__ ?></code>
</p>
</body>
</html>
