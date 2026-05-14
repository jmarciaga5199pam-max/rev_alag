<?php
/**
 * smtp_mailer.php
 * Email sender using PHP's built-in mail() function.
 *
 * USAGE:
 *   require_once __DIR__ . '/smtp_mailer.php';
 *   $ok = smtp_send_email($to, $toName, $subject, $htmlBody, $textBody);
 *
 * CONFIGURATION — edit the constants below OR set PHP environment variables
 * (Environment variables take precedence, constants are the fallback.)
 */

// ─── Edit these to match your sending mailbox ────────────────────────────────
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', 'thepeonyflower@alagapp.site');
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'AlagApp Clinic');
}
if (!defined('SMTP_REPLY_TO')) {
    define('SMTP_REPLY_TO', 'thepeonyflower@alagapp.site');
}
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Send an email via PHP's built-in mail() function.
 *
 * @param string $toEmail   Recipient email address
 * @param string $toName    Recipient display name
 * @param string $subject   Email subject
 * @param string $htmlBody  HTML email body
 * @param string $textBody  Plain-text fallback (unused by mail(); kept for API
 *                          compatibility with the previous SMTP implementation)
 * @return bool             true on success, false on failure
 */
function smtp_send_email($toEmail, $toName, $subject, $htmlBody, $textBody = '') {
    // Resolve config — env vars override constants
    $from     = getenv('SMTP_FROM_EMAIL') ?: SMTP_FROM_EMAIL;
    $fromName = getenv('SMTP_FROM_NAME')  ?: SMTP_FROM_NAME;
    $replyTo  = getenv('SMTP_REPLY_TO')   ?: SMTP_REPLY_TO;

    // Validate recipient email address before attempting to send
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("mail(): invalid recipient email address: $toEmail");
        return false;
    }

    // Encode sender display name and subject for UTF-8 safety
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject)  . '?=';

    // Build headers — use "\r\n" line breaks per RFC 2822
    $headers  = "From: $encodedFromName <$from>\r\n";
    $headers .= "Reply-To: <$replyTo>\r\n";
    $headers .= "Return-Path: <$from>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Optional 5th parameter: set the envelope sender. Helps with SPF alignment
    // on shared hosting. Only appended if safe_mode-style restrictions are off.
    $additionalParams = '-f' . $from;

    // PHP's mail() returns false on outright failure; true means the message
    // was accepted by the local MTA for delivery (not that it arrived).
    $sent = @mail($toEmail, $encodedSubject, $htmlBody, $headers, $additionalParams);

    if (!$sent) {
        $err = error_get_last();
        error_log('mail() failed to send to ' . $toEmail . ($err ? ' — ' . $err['message'] : ''));
    }

    return (bool) $sent;
}

/**
 * Convenience wrapper — sends an OTP email in AlagApp's branded template.
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $otp     6-digit code
 * @return bool
 */
function send_otp_email_smtp($toEmail, $toName, $otp) {
    $subject = 'Your AlagApp Clinic verification code';

    $html = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#fdf2f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#fdf2f8;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:12px;border:1px solid #f0c3d4;overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#d03664,#ff7aa3);padding:28px 32px;">
            <h1 style="margin:0;color:#fff;font-size:24px;font-weight:700;">AlagApp Clinic</h1>
            <p style="margin:6px 0 0;color:#ffe0ee;font-size:14px;">Email Verification</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 16px;color:#333;font-size:16px;">Hi ' . htmlspecialchars($toName, ENT_QUOTES) . ',</p>
            <p style="margin:0 0 24px;color:#555;font-size:15px;line-height:1.6;">
              Use the 6-digit code below to verify your email address and finish creating your account.
              This code expires in <strong>10 minutes</strong>.
            </p>
            <!-- OTP Box -->
            <div style="background:#fff0f7;border:2px dashed #f0c3d4;border-radius:12px;
                        text-align:center;padding:24px 16px;margin-bottom:24px;">
              <div style="font-size:42px;font-weight:700;letter-spacing:12px;color:#d03664;
                          font-family:\'Courier New\',monospace;">' . htmlspecialchars($otp) . '</div>
            </div>
            <p style="margin:0 0 8px;color:#888;font-size:13px;">
              ⏱ This code will expire in 10 minutes.
            </p>
            <p style="margin:0;color:#aaa;font-size:12px;">
              If you did not request this, you can safely ignore this email.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#fdf2f8;padding:16px 32px;border-top:1px solid #f5d0e0;">
            <p style="margin:0;color:#bbb;font-size:11px;text-align:center;">
              © ' . date('Y') . ' AlagApp Clinic &nbsp;|&nbsp; alagapp.site
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

    $text = "Your AlagApp Clinic verification code: $otp\nThis code expires in 10 minutes.\nIf you did not request this, ignore this email.";

    return smtp_send_email($toEmail, $toName, $subject, $html, $text);
}
