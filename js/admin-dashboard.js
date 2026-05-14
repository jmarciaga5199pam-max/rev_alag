/* ============================================
   AlagApp Clinic - Admin Dashboard Scripts
   Handles section nav, modals, notifications
   ============================================ */

// ---- Mobile Sidebar Toggle ----
function toggleAdminSidebar() {
    var sidebar = document.getElementById('adminSidebar');
    var overlay = document.getElementById('adminSidebarOverlay');
    if (sidebar) sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('hidden');
}

// ---- Section Navigation ----
function showSection(sectionName) {
    document.querySelectorAll('.section-content').forEach(function(section) {
        section.classList.add('hidden');
    });

    // Close sidebar on mobile
    var sidebar = document.getElementById('adminSidebar');
    var overlay = document.getElementById('adminSidebarOverlay');
    if (sidebar && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.add('hidden');
    }

    var targetSection = document.getElementById(sectionName + '-section');
    if (targetSection) {
        targetSection.classList.remove('hidden');
    }

    document.querySelectorAll('nav a').forEach(function(link) {
        link.classList.remove('bg-white/20');
    });

    // Use event.target if available (called from onclick), otherwise find by data attribute
    if (typeof event !== 'undefined' && event && event.target) {
        var activeLink = event.target.closest('a');
        if (activeLink) {
            activeLink.classList.add('bg-white/20');
        }
    }
}

// ---- Notification System ----
// Prefer shared toast when available; fall back to legacy #notification element.
function showNotification(message, type) {
    type = type || 'success';
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
        return;
    }
    var notification = document.getElementById('notification');
    if (!notification) return;

    notification.textContent = message;
    notification.className = 'notification ' + type + ' show';

    setTimeout(function() {
        notification.classList.remove('show');
    }, 3000);
}

// Shared confirm wrapper — returns a Promise-like using callback style for legacy code.
function adminConfirm(message, onConfirm, opts) {
    opts = opts || {};
    if (typeof window.appConfirm === 'function') {
        window.appConfirm(opts.title || 'Please Confirm', message, function(ok) {
            if (ok) onConfirm();
            else if (typeof opts.onCancel === 'function') opts.onCancel();
        }, { confirmText: opts.confirmText || 'Yes', cancelText: opts.cancelText || 'Cancel', primary: !!opts.primary });
        return;
    }
    if (confirm(message)) onConfirm();
    else if (typeof opts.onCancel === 'function') opts.onCancel();
}

// ---- Modal Functions ----
function openAddUserModal() {
    var el = document.getElementById('addUserModal');
    if (el) el.classList.remove('hidden');
}

function closeAddUserModal() {
    var el = document.getElementById('addUserModal');
    if (el) el.classList.add('hidden');
    var form = document.getElementById('addUserForm');
    if (form) form.reset();
}

function openAddScheduleModal() {
    var el = document.getElementById('addScheduleModal');
    if (el) el.classList.remove('hidden');
}

function closeAddScheduleModal() {
    var el = document.getElementById('addScheduleModal');
    if (el) el.classList.add('hidden');
    var form = document.getElementById('addScheduleForm');
    if (form) form.reset();
}

function openAddServiceModal() {
    var el = document.getElementById('addServiceModal');
    if (el) el.classList.remove('hidden');
}

function closeAddServiceModal() {
    var el = document.getElementById('addServiceModal');
    if (el) el.classList.add('hidden');
    var form = document.getElementById('addServiceForm');
    if (form) form.reset();
}

// ---- User Management ----
function toggleUserStatus(userId) {
    adminConfirm('Are you sure you want to change this user\'s status?', function() {
        var formData = new FormData();
        formData.append('user_id', userId);
        formData.append('action', 'toggle_user_status');
        if (window.CSRF_TOKEN) formData.append('csrf_token', window.CSRF_TOKEN);

        fetch('admin-actions-secure.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification('User status updated successfully!');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showNotification(data.message || 'Error updating user status', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showNotification('Error updating user status', 'error');
        });
    }, { title: 'Change User Status', confirmText: 'Change', primary: true });
}

function editUser(userId) {
    var row = document.querySelector('tr[data-user-id="' + userId + '"]');
    if (!row) {
        showNotification('User data not found. Please reload the page.', 'error');
        return;
    }
    var user;
    try { user = JSON.parse(row.getAttribute('data-user') || '{}'); }
    catch (e) { user = {}; }
    if (!user.id) {
        showNotification('Could not load user data.', 'error');
        return;
    }

    var set = function (id, value) {
        var el = document.getElementById(id);
        if (el) el.value = value == null ? '' : value;
    };
    set('editUserId', user.id);
    set('editUserFirstName', user.first_name);
    set('editUserLastName', user.last_name);
    set('editUserEmail', user.email);
    set('editUserPhone', user.phone);
    set('editUserDob', user.date_of_birth);
    set('editUserGender', user.gender);
    set('editUserAddress', user.address);
    set('editUserEmergencyName', user.emergency_contact_name);
    set('editUserEmergencyPhone', user.emergency_contact_phone);
    set('editUserStatus', user.status || 'active');

    var roleSelect = document.getElementById('editUserRole');
    var roleHint = document.getElementById('editUserRoleHint');
    var isDoctor = user.user_type === 'DOCTOR' || user.user_type === 'DOCTOR_OWNER';
    if (roleSelect) {
        if (isDoctor) {
            roleSelect.innerHTML = '<option value="' + user.user_type + '">' + user.user_type + '</option>';
            roleSelect.value = user.user_type;
            roleSelect.disabled = true;
            if (roleHint) roleHint.textContent = "Doctor roles cannot be changed.";
        } else {
            roleSelect.innerHTML =
                '<option value="PARENT">Parent</option>' +
                '<option value="ADMIN">Admin</option>' +
                '<option value="SUPERADMIN">Super Admin</option>';
            roleSelect.value = user.user_type || 'PARENT';
            roleSelect.disabled = false;
            if (roleHint) roleHint.textContent = "Promoting a user into a doctor role must be done via Add User.";
        }
    }

    var modal = document.getElementById('editUserModal');
    if (modal) modal.classList.remove('hidden');
}

function closeEditUserModal() {
    var modal = document.getElementById('editUserModal');
    if (modal) modal.classList.add('hidden');
}

function handleEditUser(event) {
    if (event && event.preventDefault) event.preventDefault();
    var formData = new FormData();
    formData.append('action', 'update_user_profile');
    var ids = [
        ['editUserId', 'user_id'],
        ['editUserFirstName', 'first_name'],
        ['editUserLastName', 'last_name'],
        ['editUserEmail', 'email'],
        ['editUserPhone', 'phone'],
        ['editUserDob', 'date_of_birth'],
        ['editUserGender', 'gender'],
        ['editUserAddress', 'address'],
        ['editUserEmergencyName', 'emergency_contact_name'],
        ['editUserEmergencyPhone', 'emergency_contact_phone'],
        ['editUserRole', 'user_type'],
        ['editUserStatus', 'status']
    ];
    ids.forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (el) formData.append(pair[1], el.value || '');
    });
    if (window.CSRF_TOKEN) formData.append('csrf_token', window.CSRF_TOKEN);

    fetch('admin-actions-secure.php', { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showNotification(data.message || 'User updated.', 'success');
                closeEditUserModal();
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showNotification(data.message || 'Error updating user.', 'error');
            }
        })
        .catch(function () {
            showNotification('Error updating user.', 'error');
        });
}

// Inline role-dropdown handler. The role <select> sits directly in the table
// row; selecting a new value asks for confirmation and posts to the server.
function changeUserRoleSelect(userId, selectEl) {
    if (!selectEl) return;
    var newRole = (selectEl.value || '').toUpperCase();
    var currentRole = (selectEl.getAttribute('data-current-role') || '').toUpperCase();
    if (newRole === currentRole) return;

    var revert = function () { selectEl.value = currentRole; };

    adminConfirm(
        "Change this user's role from " + currentRole + " to " + newRole + "?",
        function () {
            var formData = new FormData();
            formData.append('action', 'update_user_role');
            formData.append('user_id', userId);
            formData.append('new_role', newRole);
            if (window.CSRF_TOKEN) formData.append('csrf_token', window.CSRF_TOKEN);

            fetch('admin-actions-secure.php', { method: 'POST', body: formData })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        showNotification(data.message || 'User role updated.', 'success');
                        selectEl.setAttribute('data-current-role', newRole);
                        setTimeout(function () { location.reload(); }, 800);
                    } else {
                        showNotification(data.message || 'Error updating role.', 'error');
                        revert();
                    }
                })
                .catch(function () {
                    showNotification('Error updating role.', 'error');
                    revert();
                });
        },
        { title: 'Change User Role', confirmText: 'Change', primary: true, onCancel: revert }
    );
}

// Legacy compatibility wrapper — kept in case external code still calls it.
function changeUserRole(userId, currentRole) {
    var row = document.querySelector('tr[data-user-id="' + userId + '"]');
    var select = row ? row.querySelector('select[data-current-role]') : null;
    if (select) {
        showNotification('Use the role dropdown in the user row.', 'info');
        select.focus();
    } else {
        showNotification("A doctor's role cannot be changed here.", 'error');
    }
}

function filterUsers() {
    var roleFilter = document.getElementById('userRoleFilter');
    var statusFilter = document.getElementById('userStatusFilter');
    var role = roleFilter ? (roleFilter.value || '').toUpperCase().trim() : '';
    var status = statusFilter ? (statusFilter.value || '').toLowerCase().trim() : '';

    // Prefer the exact table body; fall back to any users table.
    var tbody = document.getElementById('usersTableBody');
    var rows = tbody
        ? tbody.querySelectorAll('tr')
        : document.querySelectorAll('#users-section tbody tr');

    var visible = 0;
    rows.forEach(function (row) {
        // Use data attributes when present, else fall back to cell text.
        var rowRole = (row.getAttribute('data-role') || '').toUpperCase().trim();
        var rowStatus = (row.getAttribute('data-status') || '').toLowerCase().trim();
        if (!rowRole) {
            var roleCell = row.querySelector('td:nth-child(3)');
            rowRole = roleCell ? roleCell.textContent.trim().toUpperCase() : '';
        }
        if (!rowStatus) {
            var statusCell = row.querySelector('td:nth-child(4)');
            rowStatus = statusCell ? statusCell.textContent.trim().toLowerCase() : '';
        }

        // Treat DOCTOR_OWNER as DOCTOR for filtering purposes.
        var roleMatch = !role
            || rowRole === role
            || (role === 'DOCTOR' && rowRole === 'DOCTOR_OWNER');
        var statusMatch = !status || rowStatus === status;

        var show = roleMatch && statusMatch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    // Optional "no results" row
    var emptyRow = document.getElementById('usersFilterEmptyRow');
    if (emptyRow) emptyRow.style.display = visible === 0 ? '' : 'none';
}

// ---- Schedule Management ----
function editSchedule(scheduleId) {
    // Open schedule modal for editing (re-use add modal)
    openAddScheduleModal();
    showNotification('Modify the schedule details and save.', 'info');
}

function deleteSchedule(scheduleId) {
    adminConfirm('Are you sure you want to delete this schedule? This cannot be undone.', function() {
        var formData = new FormData();
        formData.append('schedule_id', scheduleId);
        formData.append('action', 'delete_schedule');
        if (window.CSRF_TOKEN) formData.append('csrf_token', window.CSRF_TOKEN);

        fetch('admin-actions-secure.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification('Schedule deleted successfully!');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showNotification(data.message || 'Error deleting schedule', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showNotification('Error deleting schedule', 'error');
        });
    }, { title: 'Delete Schedule', confirmText: 'Delete' });
}

// ---- Service Management ----
function editService(serviceId) {
    // Open service modal for editing (re-use add modal)
    openAddServiceModal();
    showNotification('Modify the service details and save.', 'info');
}

function toggleServiceStatus(serviceId) {
    adminConfirm('Are you sure you want to change this service\'s status?', function() {
        var formData = new FormData();
        formData.append('service_id', serviceId);
        formData.append('action', 'toggle_service_status');
        if (window.CSRF_TOKEN) formData.append('csrf_token', window.CSRF_TOKEN);

        fetch('admin-actions-secure.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification('Service status updated successfully!');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showNotification(data.message || 'Error updating service status', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showNotification('Error updating service status', 'error');
        });
    }, { title: 'Change Service Status', confirmText: 'Change', primary: true });
}

// ---- Appointment Management ----
function editAppointment(appointmentId) {
    // Scroll the specific appointment row into view and highlight it
    var row = document.querySelector('.js-appointment-row[data-appointment-id="' + appointmentId + '"]')
           || document.querySelectorAll('#appointmentsTableBody tr')[0];
    if (row) {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('ring-2', 'ring-primary');
        setTimeout(function () { row.classList.remove('ring-2', 'ring-primary'); }, 1500);
    }
    // Open the status update modal as the primary edit action
    updateAppointmentStatus(appointmentId);
}

function updateAppointmentStatus(appointmentId) {
    // Find current status from row
    var rowBtn = document.querySelector('button[onclick^="updateAppointmentStatus(' + appointmentId + ')"]');
    var row = rowBtn ? rowBtn.closest('tr') : null;
    var currentStatus = row ? (row.getAttribute('data-status') || '').toUpperCase() : '';

    // Build a pink-themed modal on the fly
    var existing = document.getElementById('updateStatusModal');
    if (existing) existing.remove();

    var statuses = ['SCHEDULED', 'CONFIRMED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'NO_SHOW'];
    var options = statuses.map(function(s) {
        return '<option value="' + s + '"' + (s === currentStatus ? ' selected' : '') + '>' + s.replace('_', ' ') + '</option>';
    }).join('');

    var modal = document.createElement('div');
    modal.id = 'updateStatusModal';
    modal.className = 'fixed inset-0 z-50 flex items-center justify-center p-4';
    modal.style.backgroundColor = 'rgba(0,0,0,.45)';
    modal.innerHTML =
        '<div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6">' +
          '<div class="flex items-start justify-between mb-4">' +
            '<div>' +
              '<h3 class="text-xl font-semibold text-gray-800">Update Appointment Status</h3>' +
              '<p class="text-sm text-gray-500 mt-1">Appointment #' + appointmentId + '</p>' +
            '</div>' +
            '<button type="button" class="text-gray-400 hover:text-primary" onclick="document.getElementById(\'updateStatusModal\').remove()">' +
              '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>' +
            '</button>' +
          '</div>' +
          '<label class="block text-sm font-medium text-gray-700 mb-2">New Status</label>' +
          '<select id="newAppointmentStatus" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">' + options + '</select>' +
          '<div class="flex gap-3 mt-6">' +
            '<button type="button" onclick="document.getElementById(\'updateStatusModal\').remove()" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>' +
            '<button type="button" id="confirmStatusBtn" class="flex-1 bg-primary text-white px-4 py-2 rounded-lg font-semibold hover:opacity-90">Save</button>' +
          '</div>' +
        '</div>';
    document.body.appendChild(modal);

    document.getElementById('confirmStatusBtn').addEventListener('click', function() {
        var sel = document.getElementById('newAppointmentStatus');
        var newStatus = sel.value;

        var formData = new FormData();
        formData.append('action', 'update_appointment_status');
        formData.append('appointment_id', appointmentId);
        formData.append('status', newStatus);
        formData.append('csrf_token', window.CSRF_TOKEN || '');

        fetch('admin-actions-secure.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    showNotification('Appointment status updated', 'success');
                    // Update the row without reload
                    if (row) {
                        row.setAttribute('data-status', newStatus);
                        var statusCell = row.querySelector('td:nth-child(5) span');
                        if (statusCell) {
                            statusCell.textContent = newStatus.replace('_', ' ');
                            statusCell.className = 'px-2 py-1 text-xs font-medium rounded-full ' + _statusBadgeClass(newStatus);
                        }
                    }
                    var m = document.getElementById('updateStatusModal');
                    if (m) m.remove();
                } else {
                    showNotification((data && data.message) || 'Failed to update status', 'error');
                }
            })
            .catch(function() { showNotification('Network error', 'error'); });
    });
}

function _statusBadgeClass(status) {
    switch ((status || '').toUpperCase()) {
        case 'COMPLETED':   return 'bg-green-100 text-green-800';
        case 'CANCELLED':   return 'bg-red-100 text-red-800';
        case 'SCHEDULED':   return 'bg-blue-100 text-blue-800';
        case 'CONFIRMED':   return 'bg-emerald-100 text-emerald-800';
        case 'IN_PROGRESS': return 'bg-yellow-100 text-yellow-800';
        case 'NO_SHOW':     return 'bg-gray-200 text-gray-700';
        default:            return 'bg-gray-100 text-gray-800';
    }
}

// ---- Printable Reports ----
function printReport(type) {
    var titles = {
        users: 'All Users Report',
        services: 'Clinic Services Report',
        appointments: 'All Appointments Report',
        logs: 'Audit Logs Report'
    };
    var sectionMap = {
        users: 'users-section',
        services: 'services-section',
        appointments: 'appointments-section',
        logs: 'logs-section'
    };
    var sectionId = sectionMap[type];
    if (!sectionId) return;
    var section = document.getElementById(sectionId);
    if (!section) return;
    var table = section.querySelector('table');
    if (!table) {
        showNotification('No data available to print', 'warning');
        return;
    }
    // Clone table and strip the actions column(s)
    var clone = table.cloneNode(true);
    // Remove last column (Actions) if present
    clone.querySelectorAll('tr').forEach(function(tr) {
        var cells = tr.children;
        if (!cells.length) return;
        var last = cells[cells.length - 1];
        var text = (last.textContent || '').trim().toLowerCase();
        if (text === 'actions' || last.querySelector('button')) {
            last.remove();
        }
    });
    // Remove hidden helper rows
    clone.querySelectorAll('.hidden').forEach(function(n) { n.classList.remove('hidden'); });
    clone.querySelectorAll('tr[style*="display: none"]').forEach(function(n){ n.remove(); });

    var title = titles[type] || 'Report';
    var now = new Date();
    var html =
        '<!doctype html><html><head><meta charset="utf-8"><title>' + title + '</title>' +
        '<style>' +
        'body { font-family: "Inter", Arial, sans-serif; color:#111; padding: 24px; }' +
        'h1 { color: #d03664; margin: 0 0 4px; }' +
        'h2 { color:#555; font-weight: 500; font-size: 13px; margin:0 0 16px; }' +
        '.report-meta { color:#666; font-size: 12px; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 8px; }' +
        'table { width: 100%; border-collapse: collapse; font-size: 12px; }' +
        'th { background:#fce4ec; color: #880e4f; text-align: left; padding: 8px; border: 1px solid #f8bbd0; }' +
        'td { padding: 6px 8px; border: 1px solid #eee; vertical-align: top; }' +
        'tr:nth-child(even) td { background:#fff7fa; }' +
        '.footer { margin-top: 24px; font-size: 11px; color: #888; text-align:center; }' +
        '@media print { body { padding: 0; } @page { size: A4; margin: 15mm; } }' +
        '</style></head><body>' +
        '<h1>AlagApp Clinic — ' + title + '</h1>' +
        '<h2>Patient Information System</h2>' +
        '<div class="report-meta">Generated: ' + now.toLocaleString() + '</div>' +
        clone.outerHTML +
        '<div class="footer">Confidential — for internal use only. © ' + now.getFullYear() + ' AlagApp Clinic.</div>' +
        '<script>window.onload=function(){setTimeout(function(){window.print();},200);};<\/script>' +
        '</body></html>';

    var w = window.open('', '_blank', 'width=900,height=700');
    if (!w) {
        showNotification('Please allow pop-ups to generate the printable report', 'warning');
        return;
    }
    w.document.open();
    w.document.write(html);
    w.document.close();
}

// Expose
window.printReport = printReport;
window.updateAppointmentStatus = updateAppointmentStatus;
window.editAppointment = editAppointment;

function filterAppointments() {
    var statusEl = document.getElementById('appointmentStatusFilter');
    var fromEl   = document.getElementById('appointmentDateFrom');
    var toEl     = document.getElementById('appointmentDateTo');
    var searchEl = document.getElementById('appointmentSearch');

    var status = statusEl ? (statusEl.value || '').toUpperCase() : '';
    var from   = fromEl ? fromEl.value : '';
    var to     = toEl ? toEl.value : '';
    var search = searchEl ? (searchEl.value || '').trim().toLowerCase() : '';

    var rows = document.querySelectorAll('#appointmentsTableBody tr.js-appointment-row');
    var visibleCount = 0;
    rows.forEach(function(row) {
        var rowStatus = (row.getAttribute('data-status') || '').toUpperCase();
        var rowDate   = row.getAttribute('data-date') || '';
        var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();

        var statusMatch = !status || rowStatus === status;
        var fromMatch = !from || (rowDate && rowDate >= from);
        var toMatch   = !to   || (rowDate && rowDate <= to);
        var searchMatch = !search || rowSearch.indexOf(search) !== -1;

        var show = statusMatch && fromMatch && toMatch && searchMatch;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    var emptyRow = document.getElementById('noAppointmentsRow');
    if (emptyRow) {
        if (visibleCount === 0) emptyRow.classList.remove('hidden');
        else emptyRow.classList.add('hidden');
    }
}

function clearAppointmentFilters() {
    ['appointmentStatusFilter', 'appointmentDateFrom', 'appointmentDateTo', 'appointmentSearch'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    filterAppointments();
}

function filterLogs() {
    var dateFilter = document.getElementById('logDateFilter');
    var actionFilter = document.getElementById('logActionFilter');
    var filterDate = dateFilter ? dateFilter.value : '';
    var filterAction = actionFilter ? actionFilter.value.toUpperCase() : '';

    var rows = document.querySelectorAll('#logs-section tbody tr');
    rows.forEach(function(row) {
        var timestampCell = row.querySelector('td:nth-child(1)');
        var actionCell = row.querySelector('td:nth-child(3)');
        var rowTimestamp = timestampCell ? timestampCell.textContent.trim() : '';
        var rowAction = actionCell ? actionCell.textContent.trim().toUpperCase() : '';

        var dateMatch = true;
        if (filterDate) {
            var rowDate = new Date(rowTimestamp);
            var filterDateObj = new Date(filterDate + 'T00:00:00');
            dateMatch = rowDate.getFullYear() === filterDateObj.getFullYear() &&
                        rowDate.getMonth() === filterDateObj.getMonth() &&
                        rowDate.getDate() === filterDateObj.getDate();
        }

        var actionMatch = !filterAction || rowAction.indexOf(filterAction) !== -1;

        row.style.display = (dateMatch && actionMatch) ? '' : 'none';
    });
}

function filterSchedulesByDoctor() {
    var filter = document.getElementById('scheduleDoctorFilter');
    var doctorId = filter ? filter.value : '';

    document.querySelectorAll('.schedule-entry').forEach(function(entry) {
        if (!doctorId || entry.getAttribute('data-doctor-id') === doctorId) {
            entry.style.display = '';
        } else {
            entry.style.display = 'none';
        }
    });
}