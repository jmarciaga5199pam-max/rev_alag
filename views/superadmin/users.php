<?php
$layout = 'app';
$pageTitle = 'User Management - Superadmin';
$breadcrumbs = [['label' => 'Superadmin', 'url' => '/superadmin/dashboard'], ['label' => 'User Management']];
$sidebarNav = '
<a class="nav-link" href="/superadmin/dashboard"><i class="bi bi-shield-lock"></i> Superadmin</a>
<a class="nav-link active" href="/superadmin/users"><i class="bi bi-people-fill"></i> User Management</a>
<hr class="my-2">
<a class="nav-link" href="/admin/dashboard"><i class="bi bi-speedometer2"></i> Admin Dashboard</a>
<a class="nav-link" href="/admin/users"><i class="bi bi-people"></i> Admin Users</a>
<a class="nav-link" href="/admin/activity-logs"><i class="bi bi-journal-text"></i> Activity Logs</a>
<a class="nav-link" href="/admin/settings"><i class="bi bi-gear"></i> Clinic Settings</a>
';

$roleBadgeColor = fn(string $r): string => match ($r) {
    'SUPERADMIN' => '#6f42c1',
    'ADMIN' => '#dc3545',
    'DOCTOR', 'DOCTOR_OWNER' => '#007bff',
    'PARENT' => '#28a745',
    default => '#6c757d',
};
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>User Management</h5>
        <small class="text-muted">Change roles, toggle status and remove non-doctor accounts.</small>
    </div>
    <a href="/admin/export/users?<?= http_build_query($filters) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
</div>

<div class="alert alert-warning small mb-3">
    <i class="bi bi-shield-exclamation me-1"></i>
    <strong>Doctor protection:</strong> existing doctor accounts cannot have their role changed or be deleted from this page. Provision new doctors through the Admin &rarr; <em>Add Doctor</em> flow so license details are captured.
</div>

<!-- Filters -->
<div class="stat-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small">Search</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or email..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Role</label>
            <select name="user_type" class="form-select form-select-sm">
                <option value="">All Roles</option>
                <option value="PARENT" <?= ($filters['user_type'] ?? '') === 'PARENT' ? 'selected' : '' ?>>Parent</option>
                <option value="DOCTOR" <?= ($filters['user_type'] ?? '') === 'DOCTOR' ? 'selected' : '' ?>>Doctor</option>
                <option value="DOCTOR_OWNER" <?= ($filters['user_type'] ?? '') === 'DOCTOR_OWNER' ? 'selected' : '' ?>>Doctor Owner</option>
                <option value="ADMIN" <?= ($filters['user_type'] ?? '') === 'ADMIN' ? 'selected' : '' ?>>Admin</option>
                <option value="SUPERADMIN" <?= ($filters['user_type'] ?? '') === 'SUPERADMIN' ? 'selected' : '' ?>>Superadmin</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="suspended" <?= ($filters['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="stat-card p-0">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead style="background:#f8f9fa;">
                <tr>
                    <th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="6">
                    <div class="empty-state">
                        <i class="bi bi-people"></i>
                        <h5>No users found</h5>
                        <p>Try adjusting your filters.</p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($users as $u):
                    $isDoctor = in_array($u['user_type'], ['DOCTOR', 'DOCTOR_OWNER'], true);
                    $isSelf = (int) $u['id'] === (int) ($user['id'] ?? 0);
                    $availableRoles = array_values(array_filter(['PARENT', 'ADMIN', 'SUPERADMIN'], fn($r) => $r !== $u['user_type']));
                ?>
                <tr data-user='<?= htmlspecialchars(json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
                    <td><strong><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></strong><?= $isSelf ? ' <span class="badge bg-secondary ms-1">you</span>' : '' ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <?php if ($isDoctor || $isSelf): ?>
                            <span class="badge" style="background:<?= $roleBadgeColor($u['user_type']) ?>"><?= htmlspecialchars($u['user_type']) ?></span>
                            <?php if ($isDoctor): ?><i class="bi bi-lock-fill text-muted ms-1" title="Doctor role is locked"></i><?php endif; ?>
                        <?php else: ?>
                            <select class="form-select form-select-sm role-select" data-user-id="<?= $u['id'] ?>" data-current="<?= htmlspecialchars($u['user_type']) ?>" onchange="onRoleChange(this)" style="min-width:140px;">
                                <option value="<?= htmlspecialchars($u['user_type']) ?>" selected><?= htmlspecialchars($u['user_type']) ?> (current)</option>
                                <?php foreach ($availableRoles as $role): ?>
                                    <option value="<?= $role ?>">Change to <?= $role ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge" style="background:<?= match($u['status']) { 'active' => '#28a745', 'inactive' => '#ffc107', default => '#dc3545' } ?>"><?= htmlspecialchars($u['status']) ?></span></td>
                    <td style="font-size:.85rem;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($isSelf): ?>
                                    <li><span class="dropdown-item-text text-muted small"><i class="bi bi-lock me-1"></i>You can't modify your own account</span></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="#" onclick="openEditModal(<?= $u['id'] ?>); return false;"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                                    <?php if ($u['status'] === 'active'): ?>
                                        <li><a class="dropdown-item" href="#" onclick="toggleStatus(<?= $u['id'] ?>, 'inactive'); return false;"><i class="bi bi-x-circle me-2"></i>Deactivate</a></li>
                                    <?php else: ?>
                                        <li><a class="dropdown-item" href="#" onclick="toggleStatus(<?= $u['id'] ?>, 'active'); return false;"><i class="bi bi-check-circle me-2"></i>Activate</a></li>
                                    <?php endif; ?>
                                    <li><a class="dropdown-item text-warning" href="#" onclick="toggleStatus(<?= $u['id'] ?>, 'suspended'); return false;"><i class="bi bi-ban me-2"></i>Suspend</a></li>
                                    <?php if (!$isDoctor): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteUser(<?= $u['id'] ?>); return false;"><i class="bi bi-trash me-2"></i>Delete user</a></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (($pagination['total_pages'] ?? 0) > 1): ?>
    <div class="p-3 d-flex justify-content-between align-items-center border-top">
        <small class="text-muted">Showing <?= count($users) ?> of <?= $pagination['total'] ?> users</small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                <li class="page-item <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Edit User Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editUserForm">
                    <input type="hidden" name="user_id" id="editUserId">

                    <h6 class="text-muted text-uppercase small mb-2">Identity</h6>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="editFirstName" class="form-control" required maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="editLastName" class="form-control" required maxlength="50">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="editEmail" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" id="editPhone" class="form-control" maxlength="20">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" id="editDob" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select name="gender" id="editGender" class="form-select">
                                <option value="">Unspecified</option>
                                <option value="MALE">Male</option>
                                <option value="FEMALE">Female</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="editAddress" class="form-control" rows="2"></textarea>
                    </div>

                    <h6 class="text-muted text-uppercase small mb-2 mt-3">Emergency Contact</h6>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Contact Name</label>
                            <input type="text" name="emergency_contact_name" id="editEmergencyName" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Phone</label>
                            <input type="tel" name="emergency_contact_phone" id="editEmergencyPhone" class="form-control" maxlength="20">
                        </div>
                    </div>

                    <h6 class="text-muted text-uppercase small mb-2 mt-3">Account</h6>
                    <div class="row mb-1">
                        <div class="col-md-6">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="user_type" id="editUserType" class="form-select" required>
                                <option value="PARENT">Parent</option>
                                <option value="ADMIN">Admin</option>
                                <option value="SUPERADMIN">Superadmin</option>
                            </select>
                            <small class="text-muted" id="editRoleHint"></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" id="editStatus" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" onclick="submitEditUser()">Save changes</button>
            </div>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
function onRoleChange(select) {
    const userId = select.dataset.userId;
    const current = select.dataset.current;
    const newRole = select.value;
    if (newRole === current) return;
    showConfirm(`Change this user's role to "${newRole}"?`, async () => {
        const result = await apiRequest('/superadmin/users/change-role', 'POST', { user_id: userId, user_type: newRole });
        if (result.success) { showToast(result.message, 'success'); setTimeout(() => location.reload(), 800); }
        else { select.value = current; }
    }, () => { select.value = current; });
}

async function toggleStatus(userId, status) {
    showConfirm(`Change user status to "${status}"?`, async () => {
        const result = await apiRequest('/superadmin/users/toggle-status', 'POST', { user_id: userId, status });
        if (result.success) { showToast(result.message, 'success'); setTimeout(() => location.reload(), 800); }
    });
}

async function deleteUser(userId) {
    showConfirm('Delete this user? This action cannot be undone.', async () => {
        const result = await apiRequest('/superadmin/users/delete', 'POST', { user_id: userId });
        if (result.success) { showToast(result.message, 'success'); setTimeout(() => location.reload(), 800); }
    });
}

function openEditModal(userId) {
    const row = [...document.querySelectorAll('tr[data-user]')]
        .find(r => {
            try { return JSON.parse(r.dataset.user).id == userId; }
            catch (e) { return false; }
        });
    if (!row) { showToast('Could not load user.', 'error'); return; }

    let u;
    try { u = JSON.parse(row.dataset.user); }
    catch (e) { showToast('Could not parse user data.', 'error'); return; }

    const isDoctor = ['DOCTOR', 'DOCTOR_OWNER'].includes(u.user_type);

    document.getElementById('editUserId').value = u.id;
    document.getElementById('editFirstName').value = u.first_name || '';
    document.getElementById('editLastName').value = u.last_name || '';
    document.getElementById('editEmail').value = u.email || '';
    document.getElementById('editPhone').value = u.phone || '';
    document.getElementById('editDob').value = u.date_of_birth || '';
    document.getElementById('editGender').value = u.gender || '';
    document.getElementById('editAddress').value = u.address || '';
    document.getElementById('editEmergencyName').value = u.emergency_contact_name || '';
    document.getElementById('editEmergencyPhone').value = u.emergency_contact_phone || '';
    document.getElementById('editStatus').value = u.status || 'active';

    const roleSelect = document.getElementById('editUserType');
    const roleHint = document.getElementById('editRoleHint');
    if (isDoctor) {
        // Lock doctor role — superadmins can't change a doctor's role
        roleSelect.innerHTML = `<option value="${u.user_type}">${u.user_type}</option>`;
        roleSelect.value = u.user_type;
        roleSelect.disabled = true;
        roleHint.textContent = "Doctor roles are locked.";
    } else {
        roleSelect.innerHTML = `
            <option value="PARENT">Parent</option>
            <option value="ADMIN">Admin</option>
            <option value="SUPERADMIN">Superadmin</option>`;
        roleSelect.value = u.user_type;
        roleSelect.disabled = false;
        roleHint.textContent = "Promoting into a doctor role must be done via Add Doctor.";
    }

    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

async function submitEditUser() {
    const form = document.getElementById('editUserForm');
    const data = Object.fromEntries(new FormData(form).entries());
    const result = await apiRequest(`/superadmin/users/${data.user_id}/update`, 'POST', data);
    if (result.success) {
        showToast(result.message || 'User updated.', 'success');
        bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
        setTimeout(() => location.reload(), 600);
    }
}
</script>
<?php $extraScripts = ob_get_clean(); ?>
