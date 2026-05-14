<?php
$layout = 'app';
$pageTitle = 'Appointments - Superadmin';
$breadcrumbs = [['label' => 'Superadmin', 'url' => '/superadmin/dashboard'], ['label' => 'Appointments']];
$sidebarNav = '
<a class="nav-link" href="/superadmin/dashboard"><i class="bi bi-shield-lock"></i> Superadmin</a>
<a class="nav-link" href="/superadmin/users"><i class="bi bi-people-fill"></i> User Management</a>
<a class="nav-link active" href="/superadmin/appointments"><i class="bi bi-calendar-event"></i> Appointments</a>
<a class="nav-link" href="/superadmin/children"><i class="bi bi-heart"></i> Children</a>
<hr class="my-2">
<a class="nav-link" href="/admin/dashboard"><i class="bi bi-speedometer2"></i> Admin Dashboard</a>
<a class="nav-link" href="/admin/activity-logs"><i class="bi bi-journal-text"></i> Activity Logs</a>
';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Appointments</h5>
        <small class="text-muted">Browse and book appointments on behalf of any patient.</small>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openBookModal()"><i class="bi bi-plus me-1"></i>Book Appointment</button>
</div>

<!-- Filters -->
<div class="stat-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Doctor</label>
            <select name="doctor_id" class="form-select form-select-sm">
                <option value="">All doctors</option>
                <?php foreach ($doctors as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ((string)($filters['doctor_id'] ?? '') === (string) $d['id']) ? 'selected' : '' ?>>
                        Dr. <?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                <?php foreach (['SCHEDULED','CONFIRMED','IN_PROGRESS','COMPLETED','CANCELLED','NO_SHOW','WAITLISTED'] as $s): ?>
                    <option value="<?= $s ?>" <?= (($filters['status'] ?? '') === $s) ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
        </div>
    </form>
</div>

<!-- Appointments Table -->
<div class="stat-card p-0">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead style="background:#f8f9fa;">
                <tr>
                    <th>Date</th><th>Time</th><th>Patient</th><th>Doctor</th><th>Type</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($appointments)): ?>
                <tr><td colspan="6">
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h5>No appointments found</h5>
                        <p>Try changing filters or book a new one.</p>
                    </div>
                </td></tr>
            <?php else: foreach ($appointments as $a): ?>
                <tr>
                    <td><?= date('M j, Y', strtotime($a['appointment_date'])) ?></td>
                    <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                    <td><?= htmlspecialchars($a['patient_first_name'] . ' ' . $a['patient_last_name']) ?></td>
                    <td>Dr. <?= htmlspecialchars($a['doctor_first_name'] . ' ' . $a['doctor_last_name']) ?></td>
                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($a['type']) ?></span></td>
                    <td><span class="badge" style="background:<?= match($a['status']) { 'COMPLETED'=>'#28a745','CONFIRMED'=>'#007bff','CANCELLED'=>'#dc3545','NO_SHOW'=>'#6c757d','IN_PROGRESS'=>'#ffc107', default=>'#FF6B9A' } ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (($pagination['total_pages'] ?? 0) > 1): ?>
    <div class="p-3 d-flex justify-content-between align-items-center border-top">
        <small class="text-muted">Showing <?= count($appointments) ?> of <?= $pagination['total'] ?> appointments</small>
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

<!-- Book Modal -->
<div class="modal fade" id="bookApptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Book Appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="bookApptForm">
                    <div class="mb-3">
                        <label class="form-label">Patient <span class="text-danger">*</span></label>
                        <input type="text" id="patientSearch" class="form-control" placeholder="Search patient name or parent email..." oninput="searchPatients(this.value)" autocomplete="off">
                        <input type="hidden" name="patient_id" id="selectedPatientId">
                        <div id="patientResults" class="list-group mt-1" style="max-height:180px;overflow-y:auto;display:none;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Doctor <span class="text-danger">*</span></label>
                        <select name="doctor_id" id="bookDoctor" class="form-select" required onchange="loadBookSlots()">
                            <option value="">Select doctor...</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?= $d['id'] ?>">Dr. <?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?><?= $d['specialization'] ? ' — ' . htmlspecialchars($d['specialization']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="appointment_date" id="bookDate" class="form-control" min="<?= date('Y-m-d') ?>" required onchange="loadBookSlots()">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Time slot <span class="text-danger">*</span></label>
                        <select name="appointment_time" id="bookTime" class="form-select" required>
                            <option value="">Select doctor and date first</option>
                        </select>
                    </div>
                    <div class="row mb-3">
                        <div class="col-7">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="CONSULTATION">Consultation</option>
                                <option value="VACCINATION">Vaccination</option>
                                <option value="CHECKUP">Checkup</option>
                                <option value="FOLLOW_UP">Follow-up</option>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label">Duration (min)</label>
                            <input type="number" name="duration" class="form-control" min="15" step="15" value="30">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="Reason for visit..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" onclick="submitBooking()">Book Appointment</button>
            </div>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
function openBookModal() {
    document.getElementById('bookApptForm').reset();
    document.getElementById('selectedPatientId').value = '';
    document.getElementById('patientResults').style.display = 'none';
    new bootstrap.Modal(document.getElementById('bookApptModal')).show();
}

let patientSearchTimer = null;
async function searchPatients(query) {
    clearTimeout(patientSearchTimer);
    const results = document.getElementById('patientResults');
    if (!query || query.length < 2) { results.style.display = 'none'; return; }
    patientSearchTimer = setTimeout(async () => {
        // Reuse the children listing — superadmin already has access
        const r = await apiRequest('/superadmin/children?search=' + encodeURIComponent(query));
        results.innerHTML = '';
        const list = (r && r.success && Array.isArray(r.data)) ? r.data : [];
        if (!list.length) { results.style.display = 'none'; return; }
        list.slice(0, 20).forEach(c => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action';
            item.innerHTML = `<strong>${c.first_name} ${c.last_name}</strong> <small class="text-muted">— parent: ${c.parent_first_name} ${c.parent_last_name} (${c.parent_email})</small>`;
            item.onclick = () => selectPatient(c);
            results.appendChild(item);
        });
        results.style.display = 'block';
    }, 250);
}

function selectPatient(c) {
    document.getElementById('patientSearch').value = `${c.first_name} ${c.last_name} (parent: ${c.parent_email})`;
    document.getElementById('selectedPatientId').value = c.id;
    document.getElementById('patientResults').style.display = 'none';
}

async function loadBookSlots() {
    const doctorId = document.getElementById('bookDoctor').value;
    const date = document.getElementById('bookDate').value;
    const select = document.getElementById('bookTime');
    if (!doctorId || !date) { select.innerHTML = '<option value="">Select doctor and date first</option>'; return; }
    select.innerHTML = '<option value="">Loading slots...</option>';
    const r = await apiRequest(`/parent/available-slots?doctor_id=${doctorId}&date=${date}`);
    select.innerHTML = '<option value="">Select time...</option>';
    if (r.success) {
        r.data.filter(s => s.available).forEach(s => {
            select.innerHTML += `<option value="${s.time}">${s.formatted}</option>`;
        });
        if (select.options.length === 1) select.innerHTML = '<option value="">No available slots</option>';
    }
}

async function submitBooking() {
    if (!document.getElementById('selectedPatientId').value) {
        showToast('Please select a patient.', 'error');
        return;
    }
    const form = document.getElementById('bookApptForm');
    const result = await apiRequest('/superadmin/appointments/create', 'POST', new FormData(form));
    if (result.success) {
        showToast(result.message || 'Appointment booked.', 'success');
        bootstrap.Modal.getInstance(document.getElementById('bookApptModal')).hide();
        setTimeout(() => location.reload(), 700);
    }
}
</script>
<?php $extraScripts = ob_get_clean(); ?>
