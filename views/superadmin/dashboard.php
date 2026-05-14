<?php
$layout = 'app';
$pageTitle = 'Superadmin Dashboard - PediCare';
$breadcrumbs = [['label' => 'Superadmin'], ['label' => 'Dashboard']];
$sidebarNav = '
<a class="nav-link active" href="/superadmin/dashboard"><i class="bi bi-shield-lock"></i> Superadmin</a>
<a class="nav-link" href="/superadmin/users"><i class="bi bi-people-fill"></i> User Management</a>
<a class="nav-link" href="/superadmin/appointments"><i class="bi bi-calendar-event"></i> Appointments</a>
<a class="nav-link" href="/superadmin/children"><i class="bi bi-heart"></i> Children</a>
<hr class="my-2">
<div class="small text-muted px-3 mb-1">Admin tools</div>
<a class="nav-link" href="/admin/dashboard"><i class="bi bi-speedometer2"></i> Admin Dashboard</a>
<a class="nav-link" href="/admin/users"><i class="bi bi-people"></i> Admin Users</a>
<a class="nav-link" href="/admin/activity-logs"><i class="bi bi-journal-text"></i> Activity Logs</a>
<a class="nav-link" href="/admin/settings"><i class="bi bi-gear"></i> Clinic Settings</a>
<hr class="my-2">
<div class="small text-muted px-3 mb-1">Browse other roles</div>
<a class="nav-link" href="/doctor/dashboard"><i class="bi bi-prescription2"></i> Doctor View</a>
<a class="nav-link" href="/parent/dashboard"><i class="bi bi-heart-pulse"></i> Parent View</a>
';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Superadmin Console</h5>
        <small class="text-muted">Full access to every role and feature in PediCare.</small>
    </div>
    <a href="/superadmin/users" class="btn btn-primary btn-sm"><i class="bi bi-people-fill me-1"></i>Manage Users</a>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(255,107,154,.1);color:#FF6B9A;"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div style="font-size:.85rem;color:#888;">Total Users</div>
                    <div style="font-size:1.5rem;font-weight:700;"><?= $stats['total_users'] ?? 0 ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(0,123,255,.1);color:#007bff;"><i class="bi bi-prescription2"></i></div>
                <div>
                    <div style="font-size:.85rem;color:#888;">Doctors</div>
                    <div style="font-size:1.5rem;font-weight:700;"><?= $stats['total_doctors'] ?? 0 ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(40,167,69,.1);color:#28a745;"><i class="bi bi-heart-pulse"></i></div>
                <div>
                    <div style="font-size:.85rem;color:#888;">Parents</div>
                    <div style="font-size:1.5rem;font-weight:700;"><?= $stats['total_parents'] ?? 0 ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(220,53,69,.1);color:#dc3545;"><i class="bi bi-shield-check"></i></div>
                <div>
                    <div style="font-size:.85rem;color:#888;">Admins</div>
                    <div style="font-size:1.5rem;font-weight:700;"><?= $stats['total_admins'] ?? 0 ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Feature shortcuts: parent → admin -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card p-3">
            <h6 class="mb-3"><i class="bi bi-heart-pulse me-1"></i>Parent Features</h6>
            <div class="d-grid gap-2">
                <a class="btn btn-outline-secondary btn-sm text-start" href="/parent/dashboard"><i class="bi bi-house me-2"></i>Parent Dashboard</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/parent/children"><i class="bi bi-people me-2"></i>Children</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/parent/appointments"><i class="bi bi-calendar me-2"></i>Appointments</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/parent/doctors"><i class="bi bi-prescription2 me-2"></i>Doctors</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card p-3">
            <h6 class="mb-3"><i class="bi bi-prescription2 me-1"></i>Doctor Features</h6>
            <div class="d-grid gap-2">
                <a class="btn btn-outline-secondary btn-sm text-start" href="/doctor/dashboard"><i class="bi bi-house me-2"></i>Doctor Dashboard</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/doctor/appointments"><i class="bi bi-calendar-check me-2"></i>Appointments</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/doctor/patients"><i class="bi bi-clipboard-pulse me-2"></i>Patients</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/doctor/availability"><i class="bi bi-clock me-2"></i>Availability</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card p-3">
            <h6 class="mb-3"><i class="bi bi-speedometer2 me-1"></i>Admin Features</h6>
            <div class="d-grid gap-2">
                <a class="btn btn-outline-secondary btn-sm text-start" href="/admin/dashboard"><i class="bi bi-house me-2"></i>Admin Dashboard</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/admin/users"><i class="bi bi-people me-2"></i>Manage Users</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/admin/activity-logs"><i class="bi bi-journal-text me-2"></i>Activity Logs</a>
                <a class="btn btn-outline-secondary btn-sm text-start" href="/admin/settings"><i class="bi bi-gear me-2"></i>Clinic Settings</a>
            </div>
        </div>
    </div>
</div>

<!-- All Children (Parent role view) -->
<div class="stat-card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h6 class="mb-0"><i class="bi bi-people me-1"></i>All Children <span class="text-muted small">(every parent's children)</span></h6>
            <small class="text-muted">Showing the 10 most recently registered out of <?= (int) ($childrenCount ?? 0) ?> total.</small>
        </div>
        <a href="/superadmin/children" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-right me-1"></i>View all</a>
    </div>
    <?php if (empty($allChildren)): ?>
        <div class="empty-state py-3"><i class="bi bi-person-x"></i><p class="mb-0">No children registered yet.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead style="background:#f8f9fa;">
                    <tr><th>Child</th><th>DOB</th><th>Gender</th><th>Blood</th><th>Parent</th><th>Email</th></tr>
                </thead>
                <tbody>
                <?php foreach ($allChildren as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></strong></td>
                        <td><?= date('M j, Y', strtotime($c['date_of_birth'])) ?></td>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($c['gender']) ?></span></td>
                        <td><?= htmlspecialchars($c['blood_type'] ?? '-') ?: '-' ?></td>
                        <td><?= htmlspecialchars($c['parent_first_name'] . ' ' . $c['parent_last_name']) ?></td>
                        <td><a href="mailto:<?= htmlspecialchars($c['parent_email']) ?>" class="text-decoration-none"><?= htmlspecialchars($c['parent_email']) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- All Appointments (Parent role view) -->
<div class="stat-card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h6 class="mb-0"><i class="bi bi-calendar-event me-1"></i>All Appointments <span class="text-muted small">(across every parent)</span></h6>
            <small class="text-muted">Showing the 10 latest out of <?= (int) ($appointmentsCount ?? 0) ?> total.</small>
        </div>
        <a href="/superadmin/appointments" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-right me-1"></i>View all</a>
    </div>
    <?php if (empty($allAppointments)): ?>
        <div class="empty-state py-3"><i class="bi bi-calendar-x"></i><p class="mb-0">No appointments yet.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead style="background:#f8f9fa;">
                    <tr><th>Date</th><th>Time</th><th>Patient</th><th>Parent</th><th>Doctor</th><th>Type</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($allAppointments as $a): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($a['appointment_date'])) ?></td>
                        <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                        <td><?= htmlspecialchars($a['patient_first_name'] . ' ' . $a['patient_last_name']) ?></td>
                        <td><?= htmlspecialchars($a['parent_first_name'] . ' ' . $a['parent_last_name']) ?></td>
                        <td>Dr. <?= htmlspecialchars($a['doctor_first_name'] . ' ' . $a['doctor_last_name']) ?></td>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($a['type']) ?></span></td>
                        <td><span class="badge" style="background:<?= match($a['status']) {
                            'COMPLETED' => '#28a745', 'CONFIRMED' => '#007bff',
                            'CANCELLED' => '#dc3545', 'NO_SHOW' => '#6c757d',
                            'IN_PROGRESS' => '#ffc107', default => '#FF6B9A'
                        } ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Doctor Schedule (Doctor role view) -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="stat-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="mb-0"><i class="bi bi-calendar-day me-1"></i>Today's Doctor Schedule</h6>
                    <small class="text-muted"><?= date('F j, Y') ?> — every doctor's bookings.</small>
                </div>
            </div>
            <?php if (empty($doctorSchedule)): ?>
                <div class="empty-state py-3"><i class="bi bi-calendar-check"></i><p class="mb-0">No bookings today.</p></div>
            <?php else: ?>
                <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead style="background:#f8f9fa;position:sticky;top:0;">
                            <tr><th>Time</th><th>Doctor</th><th>Patient</th><th>Type</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($doctorSchedule as $s): ?>
                            <tr>
                                <td><strong><?= date('g:i A', strtotime($s['appointment_time'])) ?></strong></td>
                                <td>Dr. <?= htmlspecialchars($s['doctor_first_name'] . ' ' . $s['doctor_last_name']) ?></td>
                                <td><?= htmlspecialchars($s['patient_first_name'] . ' ' . $s['patient_last_name']) ?></td>
                                <td><span class="badge bg-light text-dark"><?= htmlspecialchars($s['type']) ?></span></td>
                                <td><span class="badge" style="background:<?= match($s['status']) {
                                    'COMPLETED' => '#28a745', 'CONFIRMED' => '#007bff',
                                    'CANCELLED' => '#dc3545', 'NO_SHOW' => '#6c757d',
                                    'IN_PROGRESS' => '#ffc107', default => '#FF6B9A'
                                } ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="stat-card p-3 h-100">
            <h6 class="mb-3"><i class="bi bi-people-fill me-1"></i>Doctor Workload</h6>
            <?php if (empty($doctorWorkload)): ?>
                <div class="empty-state py-3"><i class="bi bi-prescription2"></i><p class="mb-0">No active doctors.</p></div>
            <?php else: ?>
                <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
                    <table class="table table-sm mb-0 align-middle">
                        <thead style="background:#f8f9fa;position:sticky;top:0;">
                            <tr><th>Doctor</th><th class="text-end">Today</th><th class="text-end">This week</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($doctorWorkload as $d): ?>
                            <tr>
                                <td>Dr. <?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></td>
                                <td class="text-end"><span class="badge bg-light text-dark"><?= (int) ($d['today_count'] ?? 0) ?></span></td>
                                <td class="text-end"><span class="badge bg-light text-dark"><?= (int) ($d['week_count'] ?? 0) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Superadmin-only actions + activity feed -->
<div class="row g-3">
    <div class="col-md-4">
        <div class="stat-card p-3">
            <h6 class="mb-3"><i class="bi bi-shield-lock me-1"></i>Superadmin Actions</h6>
            <div class="d-grid gap-2">
                <a class="btn btn-primary btn-sm text-start" href="/superadmin/users"><i class="bi bi-people-fill me-2"></i>User Management</a>
                <a class="btn btn-primary btn-sm text-start" href="/superadmin/appointments"><i class="bi bi-calendar-plus me-2"></i>Appointments</a>
                <a class="btn btn-primary btn-sm text-start" href="/superadmin/children"><i class="bi bi-heart me-2"></i>My Children</a>
                <a class="btn btn-outline-primary btn-sm text-start" href="/admin/export/users"><i class="bi bi-download me-2"></i>Export Users CSV</a>
                <a class="btn btn-outline-primary btn-sm text-start" href="/admin/activity-logs"><i class="bi bi-journal me-2"></i>View Audit Trail</a>
            </div>
            <div class="alert alert-warning small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Doctor roles are managed via the "Add Doctor" admin flow and cannot be changed or deleted from user management.
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="stat-card p-3">
            <h6 class="mb-3"><i class="bi bi-clock-history me-1"></i>Recent System Activity</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>User</th><th>Action</th><th>Details</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php if (empty($recentActivity)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No recent activity</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentActivity as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?></td>
                            <td><span class="badge bg-light text-dark"><?= htmlspecialchars($log['action']) ?></span></td>
                            <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($log['details'] ?? '') ?>"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                            <td style="font-size:.8rem;color:#888;"><?= date('M j, g:i A', strtotime($log['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
