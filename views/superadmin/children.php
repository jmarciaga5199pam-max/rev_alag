<?php
$layout = 'app';
$pageTitle = 'Children - Superadmin';
$breadcrumbs = [['label' => 'Superadmin', 'url' => '/superadmin/dashboard'], ['label' => 'Children']];
$sidebarNav = '
<a class="nav-link" href="/superadmin/dashboard"><i class="bi bi-shield-lock"></i> Superadmin</a>
<a class="nav-link" href="/superadmin/users"><i class="bi bi-people-fill"></i> User Management</a>
<a class="nav-link" href="/superadmin/appointments"><i class="bi bi-calendar-event"></i> Appointments</a>
<a class="nav-link active" href="/superadmin/children"><i class="bi bi-heart"></i> Children</a>
<hr class="my-2">
<a class="nav-link" href="/admin/dashboard"><i class="bi bi-speedometer2"></i> Admin Dashboard</a>
';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0"><i class="bi bi-heart me-2"></i>My Children Registry</h5>
        <small class="text-muted">Every patient (child) on the platform across all parent accounts.</small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="openAddChildModal()"><i class="bi bi-plus me-1"></i>Add Child</button>
        <a href="/superadmin/appointments" class="btn btn-outline-primary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Book Appointment</a>
    </div>
</div>

<div class="stat-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-9">
            <label class="form-label small">Search by child name, parent name or parent email</label>
            <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['search'] ?? '') ?>" placeholder="e.g. Maria, mom@example.com">
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary btn-sm w-100">Search</button>
        </div>
    </form>
</div>

<div class="row g-3">
    <?php if (empty($children)): ?>
        <div class="col-12">
            <div class="stat-card text-center py-5">
                <i class="bi bi-person-heart" style="font-size:4rem;color:#ddd;"></i>
                <h5 class="text-muted mt-3">No children found</h5>
                <p class="text-muted">Try a different search.</p>
            </div>
        </div>
    <?php else: foreach ($children as $c): ?>
        <div class="col-md-6 col-lg-4">
            <div class="stat-card h-100">
                <div class="d-flex align-items-start gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:54px;height:54px;background:<?= $c['gender'] === 'MALE' ? 'rgba(0,123,255,.1)' : 'rgba(255,107,154,.1)' ?>;">
                        <i class="bi bi-person-fill" style="color:<?= $c['gender'] === 'MALE' ? '#007bff' : '#FF6B9A' ?>;font-size:1.5rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></h6>
                        <div class="text-muted small mb-1">
                            <?= date('M j, Y', strtotime($c['date_of_birth'])) ?>
                            (<?= (int)((time()-strtotime($c['date_of_birth']))/31557600) ?>y)
                            • <?= htmlspecialchars($c['gender']) ?>
                            <?php if ($c['blood_type']): ?> • <?= htmlspecialchars($c['blood_type']) ?><?php endif; ?>
                        </div>
                        <?php if (!empty($c['allergies'])): ?>
                            <div class="small text-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($c['allergies']) ?></div>
                        <?php endif; ?>
                        <hr class="my-2">
                        <div class="small">
                            <div class="text-muted">Parent</div>
                            <strong><?= htmlspecialchars($c['parent_first_name'] . ' ' . $c['parent_last_name']) ?></strong><br>
                            <a href="mailto:<?= htmlspecialchars($c['parent_email']) ?>" class="text-decoration-none"><?= htmlspecialchars($c['parent_email']) ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<!-- Add Child Modal -->
<div class="modal fade" id="addChildModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus me-2"></i>Add Child</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addChildForm">
                    <div class="mb-3">
                        <label class="form-label">Parent <span class="text-danger">*</span></label>
                        <input type="text" id="parentSearch" class="form-control" placeholder="Search parent name or email..." oninput="searchParents(this.value)" autocomplete="off">
                        <input type="hidden" name="parent_id" id="selectedParentId">
                        <div id="parentResults" class="list-group mt-1" style="max-height:180px;overflow-y:auto;display:none;"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6"><label class="form-label">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" class="form-control" required></div>
                        <div class="col-6"><label class="form-label">Last Name <span class="text-danger">*</span></label><input type="text" name="last_name" class="form-control" required></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6"><label class="form-label">Date of Birth <span class="text-danger">*</span></label><input type="date" name="date_of_birth" class="form-control" required></div>
                        <div class="col-6"><label class="form-label">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select" required>
                                <option value="MALE">Male</option>
                                <option value="FEMALE">Female</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-4"><label class="form-label">Blood Type</label>
                            <select name="blood_type" class="form-select">
                                <option value="">Unknown</option>
                                <option>A+</option><option>A-</option><option>B+</option><option>B-</option>
                                <option>AB+</option><option>AB-</option><option>O+</option><option>O-</option>
                            </select>
                        </div>
                        <div class="col-4"><label class="form-label">Height (cm)</label><input type="number" name="height" class="form-control" step="0.1"></div>
                        <div class="col-4"><label class="form-label">Weight (kg)</label><input type="number" name="weight" class="form-control" step="0.1"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Allergies</label><textarea name="allergies" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3"><label class="form-label">Medical Conditions</label><textarea name="medical_conditions" class="form-control" rows="2"></textarea></div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" onclick="submitNewChild()">Add Child</button>
            </div>
        </div>
    </div>
</div>

<?php if (($pagination['total_pages'] ?? 0) > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
            <li class="page-item <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php ob_start(); ?>
<script>
function openAddChildModal() {
    document.getElementById('addChildForm').reset();
    document.getElementById('selectedParentId').value = '';
    document.getElementById('parentSearch').value = '';
    document.getElementById('parentResults').style.display = 'none';
    new bootstrap.Modal(document.getElementById('addChildModal')).show();
}

let parentSearchTimer = null;
async function searchParents(query) {
    clearTimeout(parentSearchTimer);
    const results = document.getElementById('parentResults');
    document.getElementById('selectedParentId').value = '';
    if (!query || query.length < 2) { results.style.display = 'none'; return; }
    parentSearchTimer = setTimeout(async () => {
        const r = await apiRequest('/superadmin/parents?search=' + encodeURIComponent(query));
        results.innerHTML = '';
        const list = (r && r.success && Array.isArray(r.data)) ? r.data : [];
        if (!list.length) { results.style.display = 'none'; return; }
        list.forEach(p => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action';
            item.innerHTML = `<strong>${p.first_name} ${p.last_name}</strong> <small class="text-muted">— ${p.email}</small>`;
            item.onclick = () => selectParent(p);
            results.appendChild(item);
        });
        results.style.display = 'block';
    }, 250);
}

function selectParent(p) {
    document.getElementById('parentSearch').value = `${p.first_name} ${p.last_name} (${p.email})`;
    document.getElementById('selectedParentId').value = p.id;
    document.getElementById('parentResults').style.display = 'none';
}

async function submitNewChild() {
    if (!document.getElementById('selectedParentId').value) {
        showToast('Please select a parent first.', 'error');
        return;
    }
    const form = document.getElementById('addChildForm');
    const result = await apiRequest('/superadmin/children/create', 'POST', new FormData(form));
    if (result.success) {
        showToast(result.message || 'Child added.', 'success');
        bootstrap.Modal.getInstance(document.getElementById('addChildModal')).hide();
        setTimeout(() => location.reload(), 600);
    }
}
</script>
<?php $extraScripts = ob_get_clean(); ?>
