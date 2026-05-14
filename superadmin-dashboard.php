<?php
// superadmin-dashboard.php
//
// Dedicated landing page for the SUPERADMIN role. SuperAdmin is granted every
// feature/permission available in the system (parent, doctor, admin) plus the
// elevated user-management and cross-role views. This file reuses the existing
// admin-dashboard.php template — same UI shell, but with $is_superadmin=true
// so every gated block is rendered.
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Hard gate: only SUPERADMIN can access this page. ADMIN gets bounced to
// admin-dashboard.php; anyone else gets bounced to the regular dashboard.
if (($_SESSION['user_type'] ?? '') !== 'SUPERADMIN') {
    if (($_SESSION['user_type'] ?? '') === 'ADMIN') {
        header('Location: admin-dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

// Flag that lets admin-dashboard.php know it's being included from the
// superadmin landing page, so it doesn't try to redirect us back.
if (!defined('SUPERADMIN_DASHBOARD_ENTRY')) {
    define('SUPERADMIN_DASHBOARD_ENTRY', true);
}

require __DIR__ . '/admin-dashboard.php';
