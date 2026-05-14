/* ============================================
   AlagApp Clinic - Doctor Dashboard Scripts
   Handles patient management, vaccinations, modals
   ============================================ */

// Global Variables
var currentPatient = null;
var currentPatientForVaccine = null;

// ---- Utility Functions ----
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
    notification.classList.remove('hidden');
    setTimeout(function() {
        notification.classList.remove('show');
    }, 3000);
}

// Shared confirm wrapper used in place of native confirm()
function doctorConfirm(message, onConfirm, opts) {
    opts = opts || {};
    if (typeof window.appConfirm === 'function') {
        window.appConfirm(opts.title || 'Please Confirm', message, function(ok){
            if (ok) onConfirm();
        }, { confirmText: opts.confirmText || 'Yes', cancelText: opts.cancelText || 'Cancel', primary: !!opts.primary });
        return;
    }
    if (confirm(message)) onConfirm();
}

// ---- Mobile Sidebar Toggle ----
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (sidebar) {
        sidebar.classList.toggle('open');
    }
    if (overlay) {
        overlay.classList.toggle('hidden');
    }
}

// ---- Navigation ----
function showSection(sectionName) {
    document.querySelectorAll('.section-content').forEach(function(section) {
        section.classList.add('hidden');
    });

    // Close sidebar on mobile
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (sidebar && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.add('hidden');
    }

    var targetSection = document.getElementById(sectionName + '-section');
    if (targetSection) {
        targetSection.classList.remove('hidden');
        targetSection.classList.add('fade-in');
        setActiveNav(sectionName);
        if (sectionName === 'dashboard') {
            setTimeout(initializeCharts, 100);
        }
    }
}

function setActiveNav(sectionName) {
    document.querySelectorAll('.nav-item').forEach(function(item) {
        item.classList.remove('active', 'bg-white/20');
    });
    var activeNav = document.querySelector('.nav-item[data-section="' + sectionName + '"]');
    if (activeNav) {
        activeNav.classList.add('active', 'bg-white/20');
    }
}

// ---- Modal Management ----
function closeModal(modalId) {
    var el = document.getElementById(modalId);
    if (el) el.classList.add('hidden');
}

function closeAllModals() {
    document.querySelectorAll('.modal-container').forEach(function(modal) {
        modal.classList.add('hidden');
    });
}

// ---- Notification System (duplicate definition removed; use earlier one) ----

// ---- Initialization ----
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.nav-item[data-section]').forEach(function(navItem) {
        navItem.addEventListener('click', function(e) {
            e.preventDefault();
            var sectionName = this.getAttribute('data-section');
            showSection(sectionName);
        });
    });

    setActiveNav('dashboard');
    var adminDateEl = document.getElementById('administration_date');
    if (adminDateEl) {
        adminDateEl.value = new Date().toISOString().split('T')[0];
    }
    initializeCharts();
});

// ---- Chart Initialization ----
function initializeCharts() {
    var chartData = window.APPOINTMENT_CHART_DATA || { dates: [], counts: [] };
    var vaccinationChartData = window.VACCINATION_CHART_DATA || { months: [], vaccination_counts: [] };

    var appointmentsCtx = document.getElementById('appointmentsChart');
    if (appointmentsCtx && chartData.dates && chartData.dates.length > 0 && typeof Chart !== 'undefined') {
        new Chart(appointmentsCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: chartData.dates,
                datasets: [{
                    label: 'Daily Appointments',
                    data: chartData.counts,
                    borderColor: '#ff7aa3',
                    backgroundColor: 'rgba(255, 107, 154, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    var vaccinationsCtx = document.getElementById('vaccinationsChart');
    if (vaccinationsCtx && vaccinationChartData.months && vaccinationChartData.months.length > 0 && typeof Chart !== 'undefined') {
        new Chart(vaccinationsCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: vaccinationChartData.months,
                datasets: [{
                    label: 'Vaccinations Administered',
                    data: vaccinationChartData.vaccination_counts,
                    backgroundColor: '#4F46E5',
                    borderColor: '#3730A3',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }
}

// ---- Patient Management ----
function selectPatient(patientId, element) {
    document.querySelectorAll('.patient-item').forEach(function(item) {
        item.classList.remove('bg-primary/10', 'border-primary', 'selected');
    });
    if (element) {
        element.classList.add('bg-primary/10', 'border-primary', 'selected');
    }
    currentPatient = patientId;
    loadPatientDetails(patientId);
    loadPatientVaccineNeeds(patientId);
    loadPatientVaccinationHistory(patientId);
    loadPatientFiles(patientId);
}

function loadPatientFiles(patientId) {
    var container = document.getElementById('patientFilesList');
    if (!container) return;

    container.innerHTML = '<p class="text-gray-500 text-center py-4">Loading files…</p>';

    var formData = new FormData();
    formData.append('action', 'get_patient_files');
    formData.append('patient_id', patientId);
    formData.append('ajax', 'true');
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data.success) {
                container.innerHTML = '<p class="text-red-500 text-sm">' + escapeHtml(data.message || 'Failed to load files') + '</p>';
                return;
            }
            renderPatientFiles(data.files);
        })
        .catch(function(error) {
            console.error('Error loading patient files:', error);
            container.innerHTML = '<p class="text-red-500 text-sm">Failed to load files</p>';
        });
}

function renderPatientFiles(files) {
    var container = document.getElementById('patientFilesList');
    if (!container) return;

    if (!files || files.length === 0) {
        container.innerHTML = '<p class="text-gray-500 text-center py-4">No files uploaded</p>';
        return;
    }

    function formatBytes(bytes) {
        bytes = parseInt(bytes, 10) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function fileIcon(mime) {
        if (!mime) return 'fa-file';
        if (mime.indexOf('pdf') !== -1) return 'fa-file-pdf';
        if (mime.indexOf('image') === 0) return 'fa-file-image';
        if (mime.indexOf('word') !== -1 || mime.indexOf('msword') !== -1) return 'fa-file-word';
        return 'fa-file';
    }

    var html = '';
    files.forEach(function(f) {
        var uploader = (f.uploader_first || f.uploader_last)
            ? (f.uploader_first || '') + ' ' + (f.uploader_last || '')
            : 'Parent';
        var uploaded = f.created_at ? new Date(f.created_at.replace(' ', 'T')).toLocaleString() : '';
        html +=
            '<div class="p-3 border border-gray-200 rounded-lg bg-white hover:shadow-md transition-shadow">' +
                '<div class="flex items-start justify-between gap-3">' +
                    '<div class="flex items-start gap-3 min-w-0">' +
                        '<i class="fas ' + fileIcon(f.mime_type) + ' text-pink-500 text-2xl mt-1"></i>' +
                        '<div class="min-w-0">' +
                            '<div class="font-semibold text-gray-800 text-sm truncate">' + escapeHtml(f.original_filename || '') + '</div>' +
                            '<div class="text-xs text-gray-500">' +
                                escapeHtml(f.file_category || 'OTHER') + ' • ' + escapeHtml(formatBytes(f.file_size)) +
                            '</div>' +
                            (f.description ? '<div class="text-xs text-gray-600 mt-1">' + escapeHtml(f.description) + '</div>' : '') +
                            '<div class="text-xs text-gray-400 mt-1">Uploaded by ' + escapeHtml(uploader.trim()) + (uploaded ? ' • ' + escapeHtml(uploaded) : '') + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<a href="download_file.php?file_id=' + encodeURIComponent(f.id) + '" target="_blank" rel="noopener"' +
                        ' class="shrink-0 inline-flex items-center gap-1 px-3 py-1 text-xs bg-pink-100 text-pink-700 rounded hover:bg-pink-200 transition-colors">' +
                        '<i class="fas fa-download"></i> View' +
                    '</a>' +
                '</div>' +
            '</div>';
    });
    container.innerHTML = html;
}

function loadPatientDetails(patientId) {
    var patientSummary = document.getElementById('patientSummary');
    if (!patientSummary) return;

    patientSummary.innerHTML =
        '<div class="text-center">' +
        '<div class="loading-spinner mx-auto mb-2"></div>' +
        '<div class="text-sm text-gray-500">Loading patient details...</div>' +
        '</div>';

    var formData = new FormData();
    formData.append('action', 'get_patient_details');
    formData.append('patient_id', patientId);
    formData.append('ajax', 'true');
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                displayPatientDetails(data.patient, data.latest_vitals, data.recent_records, data.records_total);
            } else {
                patientSummary.innerHTML = '<p class="text-red-500">' + (data.message || 'Error') + '</p>';
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            patientSummary.innerHTML = '<p class="text-red-500">Failed to load patient details</p>';
        });
}

function displayPatientDetails(patient, latestVitals, recentRecords, totalRecords) {
    var patientSummary = document.getElementById('patientSummary');
    if (!patientSummary) return;

    // Cache for printing
    window.currentPatientSummaryData = {
        patient: patient,
        latest_vitals: latestVitals,
        recent_records: recentRecords,
        total_records: totalRecords
    };
    var printBtn = document.getElementById('printPatientSummaryBtn');
    if (printBtn) printBtn.classList.remove('hidden');
    patientSummary.classList.remove('text-center', 'text-gray-500');

    var age = patient.age_years > 0
        ? patient.age_years + ' years'
        : (patient.age_months || 0) + ' months';

    function fmtDate(d) {
        if (!d) return 'N/A';
        var dt = new Date(d);
        return isNaN(dt.getTime()) ? escapeHtml(d) : dt.toLocaleDateString();
    }
    function orNA(v) { return (v === null || v === undefined || v === '') ? 'N/A' : escapeHtml(String(v)); }

    var html = '<div class="text-left space-y-4">';

    // --- Patient Info ---
    html +=
        '<div><h4 class="font-semibold text-gray-800 mb-2">Patient Information</h4>' +
        '<div class="grid grid-cols-2 gap-y-1 gap-x-3 text-sm">' +
        '<div class="text-gray-600">Name:</div><div class="font-medium">' + escapeHtml(patient.first_name || '') + ' ' + escapeHtml(patient.last_name || '') + '</div>' +
        '<div class="text-gray-600">DOB:</div><div class="font-medium">' + fmtDate(patient.date_of_birth) + '</div>' +
        '<div class="text-gray-600">Age:</div><div class="font-medium">' + escapeHtml(age) + '</div>' +
        '<div class="text-gray-600">Gender:</div><div class="font-medium">' + orNA(patient.gender) + '</div>' +
        '<div class="text-gray-600">Blood Type:</div><div class="font-medium">' + orNA(patient.blood_type) + '</div>' +
        '<div class="text-gray-600">Parent:</div><div class="font-medium">' + orNA((patient.parent_first || '') + ' ' + (patient.parent_last || '')) + '</div>' +
        '<div class="text-gray-600">Parent Email:</div><div class="font-medium break-all">' + orNA(patient.parent_email) + '</div>' +
        '</div></div>';

    // --- Medical Info ---
    if (patient.allergies || patient.medical_conditions || patient.special_notes) {
        html += '<div><h4 class="font-semibold text-gray-800 mb-2">Medical Background</h4><div class="text-sm space-y-1">';
        if (patient.allergies)           html += '<div><span class="text-gray-600">Allergies:</span> <span class="font-medium">' + escapeHtml(patient.allergies) + '</span></div>';
        if (patient.medical_conditions)  html += '<div><span class="text-gray-600">Conditions:</span> <span class="font-medium">' + escapeHtml(patient.medical_conditions) + '</span></div>';
        if (patient.special_notes)       html += '<div><span class="text-gray-600">Notes:</span> <span class="font-medium">' + escapeHtml(patient.special_notes) + '</span></div>';
        html += '</div></div>';
    }

    // --- Recent Vitals ---
    html += '<div><h4 class="font-semibold text-gray-800 mb-2">Recent Vitals</h4>';
    if (!latestVitals) {
        html += '<p class="text-sm text-gray-500">No vitals recorded yet.</p>';
    } else {
        html += '<div class="grid grid-cols-2 gap-y-1 gap-x-3 text-sm">' +
            '<div class="text-gray-600">Recorded:</div><div class="font-medium">' + fmtDate(latestVitals.record_date) + '</div>' +
            '<div class="text-gray-600">Temperature:</div><div class="font-medium">' + (latestVitals.temperature !== null && latestVitals.temperature !== '' ? escapeHtml(latestVitals.temperature) + ' °C' : 'N/A') + '</div>' +
            '<div class="text-gray-600">Blood Pressure:</div><div class="font-medium">' + orNA(latestVitals.blood_pressure) + '</div>' +
            '<div class="text-gray-600">Heart Rate:</div><div class="font-medium">' + (latestVitals.heart_rate ? escapeHtml(latestVitals.heart_rate) + ' bpm' : 'N/A') + '</div>' +
            '<div class="text-gray-600">Height:</div><div class="font-medium">' + (latestVitals.height ? escapeHtml(latestVitals.height) + ' cm' : 'N/A') + '</div>' +
            '<div class="text-gray-600">Weight:</div><div class="font-medium">' + (latestVitals.weight ? escapeHtml(latestVitals.weight) + ' kg' : 'N/A') + '</div>' +
            '</div>';
    }
    html += '</div>';

    // --- Recent Medical History ---
    html += '<div><div class="flex items-center justify-between mb-2">' +
            '<h4 class="font-semibold text-gray-800">Recent Medical History</h4>' +
            '<span class="text-xs text-gray-500">' + (totalRecords || 0) + ' total</span>' +
            '</div>';
    if (!recentRecords || recentRecords.length === 0) {
        html += '<p class="text-sm text-gray-500">No medical records yet.</p>';
    } else {
        html += '<div class="space-y-2">';
        recentRecords.forEach(function(rec) {
            html +=
                '<div class="p-3 border border-gray-200 rounded-lg bg-gray-50">' +
                '<div class="flex justify-between items-start mb-1">' +
                    '<div class="text-sm font-medium text-gray-800">' + escapeHtml(rec.diagnosis || rec.record_type || 'Record') + '</div>' +
                    '<span class="text-xs text-gray-500">' + fmtDate(rec.record_date) + '</span>' +
                '</div>' +
                (rec.record_type ? '<div class="text-xs text-gray-600 mb-1"><span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-800 rounded">' + escapeHtml(rec.record_type) + '</span></div>' : '') +
                (rec.symptoms ? '<div class="text-xs text-gray-700"><strong>Symptoms:</strong> ' + escapeHtml(rec.symptoms) + '</div>' : '') +
                (rec.treatment_plan ? '<div class="text-xs text-gray-700 mt-1"><strong>Plan:</strong> ' + escapeHtml(rec.treatment_plan) + '</div>' : '') +
                '</div>';
        });
        html += '</div>';
    }
    html += '</div>';

    html += '</div>';
    patientSummary.innerHTML = html;
}

function printPatientSummary() {
    var cache = window.currentPatientSummaryData;
    if (!cache || !cache.patient) {
        showNotification('Select a patient first', 'error');
        return;
    }
    var patient = cache.patient;
    var vitals = cache.latest_vitals;
    var records = cache.recent_records || [];
    var info = window.DOCTOR_INFO || {};

    function safe(s) { return escapeHtml(s == null ? '' : String(s)); }
    function fmt(d) {
        if (!d) return 'N/A';
        var dt = new Date(d);
        return isNaN(dt.getTime()) ? safe(d) : dt.toLocaleDateString();
    }
    function orNA(v) { return (v === null || v === undefined || v === '') ? 'N/A' : safe(v); }

    var age = (patient.age_years > 0)
        ? patient.age_years + ' years'
        : ((patient.age_months || 0) + ' months');

    var hospital = info.hospital || 'AlagApp Pediatric Clinic';
    var hospitalAddr = info.hospital_address || '';
    var doctorName = info.name || 'Attending Physician';

    var rowsRecords = records.length
        ? records.map(function(rec) {
            return '<tr>' +
                '<td>' + fmt(rec.record_date) + '</td>' +
                '<td>' + safe(rec.record_type || '') + '</td>' +
                '<td>' + safe(rec.diagnosis || '') + '</td>' +
                '<td>' + safe(rec.symptoms || '') + '</td>' +
                '<td>' + safe(rec.treatment_plan || '') + '</td>' +
                '</tr>';
          }).join('')
        : '<tr><td colspan="5" style="text-align:center;color:#888;">No medical records.</td></tr>';

    var vitalsHtml = vitals
        ? '<table class="kv">' +
            '<tr><th>Recorded</th><td>' + fmt(vitals.record_date) + '</td></tr>' +
            '<tr><th>Temperature</th><td>' + (vitals.temperature ? safe(vitals.temperature) + ' &deg;C' : 'N/A') + '</td></tr>' +
            '<tr><th>Blood Pressure</th><td>' + orNA(vitals.blood_pressure) + '</td></tr>' +
            '<tr><th>Heart Rate</th><td>' + (vitals.heart_rate ? safe(vitals.heart_rate) + ' bpm' : 'N/A') + '</td></tr>' +
            '<tr><th>Height</th><td>' + (vitals.height ? safe(vitals.height) + ' cm' : 'N/A') + '</td></tr>' +
            '<tr><th>Weight</th><td>' + (vitals.weight ? safe(vitals.weight) + ' kg' : 'N/A') + '</td></tr>' +
          '</table>'
        : '<p>No vitals recorded yet.</p>';

    var pw = window.open('', '_blank', 'width=800,height=900');
    if (!pw) { showNotification('Please allow popups to print', 'error'); return; }

    var html = '<!DOCTYPE html><html><head><title>Patient Summary - ' +
        safe(patient.first_name + ' ' + patient.last_name) + '</title>' +
        '<style>' +
        '@page{margin:18mm;}' +
        'body{font-family:Arial,Helvetica,sans-serif;color:#222;margin:0;padding:24px;}' +
        '.hdr{border-bottom:3px double #ec4899;padding-bottom:10px;margin-bottom:18px;}' +
        '.hdr .h{font-size:22px;font-weight:bold;color:#be185d;}' +
        '.hdr .s{font-size:12px;color:#9d174d;margin-top:2px;}' +
        '.title{text-align:center;font-size:20px;color:#be185d;margin:8px 0 18px;letter-spacing:2px;font-weight:bold;}' +
        'h2{color:#be185d;font-size:15px;margin:18px 0 6px;border-left:4px solid #ec4899;padding-left:8px;}' +
        'table{width:100%;border-collapse:collapse;margin:6px 0;font-size:12px;}' +
        'table.kv th{text-align:left;width:160px;background:#fdf2f8;color:#9d174d;padding:6px 8px;border:1px solid #fbcfe8;font-weight:600;}' +
        'table.kv td{padding:6px 8px;border:1px solid #fbcfe8;}' +
        'table.records th{background:#ec4899;color:#fff;padding:6px 8px;text-align:left;}' +
        'table.records td{padding:6px 8px;border-bottom:1px solid #fbcfe8;vertical-align:top;}' +
        '.foot{margin-top:24px;border-top:2px dotted #f9a8d4;padding-top:8px;font-size:11px;color:#9d174d;text-align:center;font-style:italic;}' +
        '@media print{body{padding:0;}}' +
        '</style></head><body>' +
        '<div class="hdr">' +
            '<div class="h">' + safe(hospital) + '</div>' +
            (hospitalAddr ? '<div class="s">' + safe(hospitalAddr) + '</div>' : '') +
            '<div class="s">Attending: ' + safe(doctorName) + '</div>' +
        '</div>' +
        '<div class="title">PATIENT SUMMARY</div>' +
        '<h2>Patient Information</h2>' +
        '<table class="kv">' +
            '<tr><th>Name</th><td>' + safe(patient.first_name) + ' ' + safe(patient.last_name) + '</td></tr>' +
            '<tr><th>Date of Birth</th><td>' + fmt(patient.date_of_birth) + '</td></tr>' +
            '<tr><th>Age</th><td>' + safe(age) + '</td></tr>' +
            '<tr><th>Gender</th><td>' + orNA(patient.gender) + '</td></tr>' +
            '<tr><th>Blood Type</th><td>' + orNA(patient.blood_type) + '</td></tr>' +
            '<tr><th>Parent / Guardian</th><td>' + orNA((patient.parent_first || '') + ' ' + (patient.parent_last || '')) + '</td></tr>' +
            '<tr><th>Parent Email</th><td>' + orNA(patient.parent_email) + '</td></tr>' +
        '</table>' +
        ((patient.allergies || patient.medical_conditions || patient.special_notes)
            ? '<h2>Medical Background</h2><table class="kv">' +
                (patient.allergies ? '<tr><th>Allergies</th><td>' + safe(patient.allergies) + '</td></tr>' : '') +
                (patient.medical_conditions ? '<tr><th>Conditions</th><td>' + safe(patient.medical_conditions) + '</td></tr>' : '') +
                (patient.special_notes ? '<tr><th>Notes</th><td>' + safe(patient.special_notes) + '</td></tr>' : '') +
              '</table>'
            : '') +
        '<h2>Recent Vitals</h2>' + vitalsHtml +
        '<h2>Recent Medical History</h2>' +
        '<table class="records"><thead><tr>' +
            '<th>Date</th><th>Type</th><th>Diagnosis</th><th>Symptoms</th><th>Plan</th>' +
        '</tr></thead><tbody>' + rowsRecords + '</tbody></table>' +
        '<div class="foot">Printed on ' + new Date().toLocaleString() +
            ' &middot; Generated by AlagApp Patient Information System</div>' +
        '</body></html>';

    pw.document.write(html);
    pw.document.close();
    pw.focus();
    setTimeout(function() { pw.print(); }, 300);
}

function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return '';
    return String(unsafe)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ---- Vaccination History ----
function loadPatientVaccinationHistory(patientId) {
    var formData = new FormData();
    formData.append('action', 'get_patient_vaccination_records');
    formData.append('patient_id', patientId);
    formData.append('ajax', 'true');
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                renderPatientVaccinationHistory(data.data);
            }
        })
        .catch(function(error) { console.error('Error:', error); });
}

function renderPatientVaccinationHistory(records) {
    var container = document.getElementById('patientVaccinationHistory');
    if (!container) return;

    if (!records || records.length === 0) {
        container.innerHTML = '<p class="text-gray-500 text-center py-4">No vaccination history</p>';
        return;
    }

    var html = '<div class="space-y-3">';
    records.forEach(function(record) {
        html +=
            '<div class="p-3 border border-gray-200 rounded-lg bg-white hover:shadow-md transition-shadow">' +
            '<div class="flex justify-between items-start mb-2"><div>' +
            '<div class="font-semibold text-gray-800 text-sm">' + escapeHtml(record.vaccine_name) + '</div>' +
            '<div class="text-xs text-gray-600">' + new Date(record.administration_date).toLocaleDateString() + '</div>' +
            '</div>' +
            '<span class="px-2 py-1 text-xs font-semibold rounded bg-green-100 text-green-800">Dose #' + escapeHtml(record.dose_number) + '</span>' +
            '</div>' +
            (record.lot_number ? '<div class="text-xs text-gray-600 mb-2">Lot: ' + escapeHtml(record.lot_number) + '</div>' : '') +
            (record.notes ? '<div class="text-xs text-gray-600 mb-2"><strong>Notes:</strong> ' + escapeHtml(record.notes) + '</div>' : '') +
            '<div class="flex gap-2 mt-3">' +
            '<button onclick="editVaccinationModal(' + record.id + ')" class="flex-1 px-2 py-1 text-xs bg-indigo-100 text-indigo-700 rounded hover:bg-indigo-200 transition-colors">Edit</button>' +
            '<button onclick="deleteVaccinationFromRecord(' + record.id + ')" class="flex-1 px-2 py-1 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200 transition-colors">Delete</button>' +
            '</div></div>';
    });
    html += '</div>';
    container.innerHTML = html;
}

// ---- Vaccine Needs ----
function openVaccineNeedModal() {
    if (!currentPatient) {
        showNotification('Please select a patient first', 'error');
        return;
    }
    currentPatientForVaccine = currentPatient;

    var form = document.getElementById('vaccineNeedForm');
    if (form) form.reset();
    var needId = document.getElementById('vaccine_need_id');
    if (needId) needId.value = '';
    var patId = document.getElementById('vaccine_need_patient_id');
    if (patId) patId.value = currentPatient;

    var selectedPatient = document.querySelector('.patient-item.selected');
    if (selectedPatient) {
        var patientName = selectedPatient.querySelector('.font-semibold');
        var display = document.getElementById('vaccine_patient_display');
        if (patientName && display) display.textContent = patientName.textContent;
    }

    loadRecommendedVaccines(currentPatient);
    loadVaccineNeedsForModal(currentPatient);

    var modal = document.getElementById('vaccineNeedModal');
    if (modal) modal.classList.remove('hidden');
}

function loadRecommendedVaccines(patientId) {
    var formData = new FormData();
    formData.append('action', 'get_recommended_vaccines');
    formData.append('patient_id', patientId);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                var ageMonths = data.age_months;
                var ageDisplay = '';
                if (ageMonths < 12) {
                    ageDisplay = ageMonths + ' months';
                } else {
                    var years = Math.floor(ageMonths / 12);
                    var months = ageMonths % 12;
                    ageDisplay = years + ' year' + (years > 1 ? 's' : '') + ' ' + months + ' month' + (months !== 1 ? 's' : '');
                }
                var ageEl = document.getElementById('vaccine_age_display');
                if (ageEl) ageEl.textContent = ageDisplay;

                var vaccinesList = document.getElementById('recommendedVaccinesList');
                if (vaccinesList) {
                    if (data.data && data.data.length > 0) {
                        var html = '<ul class="list-disc list-inside space-y-1">';
                        data.data.forEach(function(vaccine) {
                            html += '<li>' + escapeHtml(vaccine.vaccine_name) + ' (' + escapeHtml(vaccine.disease_protected) + ')</li>';
                        });
                        html += '</ul>';
                        vaccinesList.innerHTML = html;
                    } else {
                        vaccinesList.innerHTML = '<p class="text-gray-700">No vaccines recommended for this age</p>';
                    }
                }
            }
        })
        .catch(function(error) { console.error('Error:', error); });
}

function loadVaccineNeedsForModal(patientId) {
    var formData = new FormData();
    formData.append('action', 'get_vaccine_needs');
    formData.append('patient_id', patientId);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.data) {
                renderVaccineNeedsForModal(data.data);
            }
        })
        .catch(function(error) { console.error('Error:', error); });
}

function renderVaccineNeedsForModal(vaccines) {
    var container = document.getElementById('vaccineNeedsModalList');
    if (!container) return;

    if (!vaccines || vaccines.length === 0) {
        container.innerHTML = '<p class="text-gray-500 text-center py-8">No vaccine needs recorded</p>';
        return;
    }

    var statusColors = {
        'RECOMMENDED': 'bg-yellow-100 text-yellow-800',
        'SCHEDULED': 'bg-blue-100 text-blue-800',
        'GIVEN': 'bg-green-100 text-green-800',
        'NOT_NEEDED': 'bg-gray-100 text-gray-800'
    };

    var html = '';
    vaccines.forEach(function(vaccine) {
        var statusColor = statusColors[vaccine.status] || 'bg-gray-100 text-gray-800';
        var dateDisplay = vaccine.recommended_date
            ? new Date(vaccine.recommended_date).toLocaleDateString()
            : 'Not set';

        html +=
            '<div class="p-4 bg-white border border-gray-200 rounded-lg">' +
            '<div class="flex justify-between items-start mb-2"><div>' +
            '<div class="font-semibold text-gray-800">' + escapeHtml(vaccine.vaccine_name) + '</div>' +
            '<div class="text-sm text-gray-600">Due: ' + dateDisplay + '</div>' +
            '</div><span class="px-2 py-1 text-xs font-semibold rounded ' + statusColor + '">' + escapeHtml(vaccine.status) + '</span></div>' +
            (vaccine.notes ? '<div class="text-sm text-gray-600 mb-2"><strong>Notes:</strong> ' + escapeHtml(vaccine.notes) + '</div>' : '') +
            '<div class="flex gap-2 mt-3">' +
            '<button onclick=\'editVaccineNeed(' + JSON.stringify(vaccine) + ')\' class="flex-1 px-3 py-1 text-sm bg-indigo-100 text-indigo-700 rounded hover:bg-indigo-200 transition-colors">Edit</button>' +
            '<button onclick="deleteVaccineNeed(' + vaccine.id + ')" class="flex-1 px-3 py-1 text-sm bg-red-100 text-red-700 rounded hover:bg-red-200 transition-colors">Delete</button>' +
            '</div></div>';
    });
    container.innerHTML = html;
}

function editVaccineNeed(vaccine) {
    var fields = {
        'vaccine_need_id': vaccine.id,
        'vaccine_name_input': vaccine.vaccine_name,
        'recommended_date_input': vaccine.recommended_date || '',
        'vaccine_status_input': vaccine.status,
        'vaccine_notes_input': vaccine.notes || ''
    };
    for (var id in fields) {
        var el = document.getElementById(id);
        if (el) el.value = fields[id];
    }
}

function handleVaccineNeedForm(event) {
    event.preventDefault();
    var form = event.target;
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Saving vaccine need...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                form.reset();
                var needId = document.getElementById('vaccine_need_id');
                if (needId) needId.value = '';
                loadVaccineNeedsForModal(currentPatientForVaccine);
                loadPatientVaccineNeeds(currentPatientForVaccine);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showNotification('Failed to save vaccine need', 'error');
        });
}

function deleteVaccineNeed(vaccineNeedId) {
    doctorConfirm('Are you sure you want to delete this vaccine need?', function() {
        _performDeleteVaccineNeed(vaccineNeedId);
    }, { title: 'Delete Vaccine Need', confirmText: 'Delete' });
}

function _performDeleteVaccineNeed(vaccineNeedId) {
    var formData = new FormData();
    formData.append('action', 'delete_vaccine_need');
    formData.append('vaccine_need_id', vaccineNeedId);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    showNotification('Deleting vaccine need...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                loadVaccineNeedsForModal(currentPatientForVaccine);
                loadPatientVaccineNeeds(currentPatientForVaccine);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showNotification('Failed to delete vaccine need', 'error');
        });
}

function loadPatientVaccineNeeds(patientId) {
    var formData = new FormData();
    formData.append('action', 'get_vaccine_needs');
    formData.append('patient_id', patientId);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.data) {
                renderPatientVaccineNeeds(data.data);
            }
        })
        .catch(function(error) { console.error('Error:', error); });
}

function renderPatientVaccineNeeds(vaccines) {
    var container = document.getElementById('patientVaccineNeedsList');
    if (!container) return;

    if (!vaccines || vaccines.length === 0) {
        container.innerHTML = '<p class="text-gray-500 text-center py-4">No vaccine needs recorded</p>';
        return;
    }

    var statusColors = {
        'RECOMMENDED': 'bg-yellow-100 text-yellow-800',
        'SCHEDULED': 'bg-blue-100 text-blue-800',
        'GIVEN': 'bg-green-100 text-green-800',
        'NOT_NEEDED': 'bg-gray-100 text-gray-800'
    };

    var html = '<div class="space-y-3">';
    vaccines.forEach(function(vaccine) {
        var statusColor = statusColors[vaccine.status] || 'bg-gray-100 text-gray-800';
        var dateDisplay = vaccine.recommended_date
            ? new Date(vaccine.recommended_date).toLocaleDateString()
            : 'Not set';

        html +=
            '<div class="p-3 border border-gray-200 rounded-lg bg-white hover:shadow-md transition-shadow">' +
            '<div class="flex justify-between items-start mb-2"><div>' +
            '<div class="font-semibold text-gray-800 text-sm">' + escapeHtml(vaccine.vaccine_name) + '</div>' +
            '<div class="text-xs text-gray-600">Due: ' + dateDisplay + '</div></div>' +
            '<span class="px-2 py-1 text-xs font-semibold rounded ' + statusColor + '">' + escapeHtml(vaccine.status) + '</span></div>' +
            (vaccine.notes ? '<div class="text-xs text-gray-600 mb-2">' + escapeHtml(vaccine.notes) + '</div>' : '') +
            '</div>';
    });
    html += '</div>';
    container.innerHTML = html;
}

// ---- Vaccination Records ----
function openVaccinationModal() {
    if (!currentPatient) {
        showNotification('Please select a patient first', 'error');
        return;
    }
    var el = document.getElementById('vaccine_patient_id');
    if (el) el.value = currentPatient;
    var modal = document.getElementById('vaccinationModal');
    if (modal) modal.classList.remove('hidden');
}

function handleVaccinationForm(event) {
    event.preventDefault();
    var form = event.target;
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Recording vaccination...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                closeModal('vaccinationModal');
                form.reset();
                var dateEl = document.getElementById('administration_date');
                if (dateEl) dateEl.value = new Date().toISOString().split('T')[0];
                if (currentPatient) loadPatientVaccinationHistory(currentPatient);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to record vaccination', 'error');
            console.error('Error:', error);
        });
}

function editVaccinationModal(vaccinationId) {
    var formData = new FormData();
    formData.append('action', 'get_vaccination_record');
    formData.append('vaccination_id', vaccinationId);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.record) {
                showVaccinationEditModal(data.record);
            } else {
                showNotification('Failed to load vaccination record', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showNotification('Failed to load vaccination record', 'error');
        });
}

function showVaccinationEditModal(record) {
    var fields = {
        'edit_vaccination_id': record.id,
        'edit_vaccine_patient_id': record.patient_id,
        'edit_vaccine_name': record.vaccine_name,
        'edit_dose_number': record.dose_number,
        'edit_administration_date': record.administration_date,
        'edit_lot_number': record.lot_number || '',
        'edit_vaccination_notes': record.notes || ''
    };
    for (var id in fields) {
        var el = document.getElementById(id);
        if (el) el.value = fields[id];
    }
    var modal = document.getElementById('editVaccinationModal');
    if (modal) modal.classList.remove('hidden');
}

function handleEditVaccinationForm(event) {
    event.preventDefault();
    var form = event.target;
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Updating vaccination record...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                closeModal('editVaccinationModal');
                if (currentPatient) loadPatientVaccinationHistory(currentPatient);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to update vaccination record', 'error');
            console.error('Error:', error);
        });
}

function deleteVaccinationFromRecord(vaccinationId) {
    doctorConfirm('Are you sure you want to delete this vaccination record?', function() {
        var formData = new FormData();
        formData.append('action', 'delete_vaccination_record');
        formData.append('vaccination_id', vaccinationId);
        formData.append('csrf_token', window.CSRF_TOKEN || '');

        showNotification('Deleting vaccination record...', 'info');

        fetch('', { method: 'POST', body: formData })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    if (currentPatient) loadPatientVaccinationHistory(currentPatient);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                showNotification('Failed to delete vaccination record', 'error');
            });
    }, { title: 'Delete Vaccination Record', confirmText: 'Delete' });
}

// ---- Modal Openers ----
function openConsultationModal() {
    if (!currentPatient) {
        showNotification('Please select a patient first', 'error');
        return;
    }
    var selectedPatient = document.querySelector('.patient-item.selected');
    if (selectedPatient) {
        var name = selectedPatient.querySelector('.font-semibold');
        var display = document.getElementById('consultationPatientName');
        if (name && display) display.textContent = name.textContent;
    }
    var patId = document.getElementById('consultation_patient_id');
    if (patId) patId.value = currentPatient;
    var modal = document.getElementById('consultationModal');
    if (modal) modal.classList.remove('hidden');
}

function openPrescriptionModal() {
    if (!currentPatient) {
        showNotification('Please select a patient first', 'error');
        return;
    }
    var selectedPatient = document.querySelector('.patient-item.selected');
    if (selectedPatient) {
        var name = selectedPatient.querySelector('.font-semibold');
        var display = document.getElementById('prescriptionPatientName');
        if (name && display) display.textContent = name.textContent;
    }
    var patId = document.getElementById('prescription_patient_id');
    if (patId) patId.value = currentPatient;
    var modal = document.getElementById('prescriptionModal');
    if (modal) modal.classList.remove('hidden');
}

function openMedicalRecordModal() {
    if (!currentPatient) {
        showNotification('Please select a patient first', 'error');
        return;
    }
    var selectedPatient = document.querySelector('.patient-item.selected');
    if (selectedPatient) {
        var name = selectedPatient.querySelector('.font-semibold');
        var display = document.getElementById('medicalRecordPatientName');
        if (name && display) display.textContent = name.textContent;
    }
    var patId = document.getElementById('medical_record_patient_id');
    if (patId) patId.value = currentPatient;
    var modal = document.getElementById('medicalRecordModal');
    if (modal) modal.classList.remove('hidden');
}

// ---- Form Handlers ----
function handleConsultationForm(event) {
    event.preventDefault();
    var form = event.target;
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Saving consultation notes...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                closeModal('consultationModal');
                form.reset();
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to save consultation notes', 'error');
            console.error('Error:', error);
        });
}

function handlePrescriptionForm(event) {
    event.preventDefault();
    var form = event.target;
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Saving prescription...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                closeModal('prescriptionModal');

                // Offer to print prescription
                var printData = {
                    patient_name: document.getElementById('prescriptionPatientName') ? document.getElementById('prescriptionPatientName').textContent : '',
                    medication: formData.get('medication_name') || '',
                    dosage: formData.get('dosage') || '',
                    frequency: formData.get('frequency') || '',
                    duration: formData.get('duration') || '',
                    instructions: formData.get('instructions') || '',
                    date: new Date().toLocaleDateString()
                };

                if (typeof window.appConfirm === 'function') {
                    // Offer to print the prescription directly.
                    window.appConfirm('Prescription saved', 'Print this prescription now?', function(ok) {
                        if (ok) printPrescription(printData);
                    }, { confirmText: 'Print', cancelText: 'Skip', primary: true });
                } else {
                    var action = prompt('Prescription saved! Enter:\n1 = Print\n2 = Download PDF\n(Cancel to skip)', '1');
                    if (action === '1') printPrescription(printData);
                    else if (action === '2') downloadPrescriptionPDF(printData);
                }

                form.reset();
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to save prescription', 'error');
            console.error('Error:', error);
        });
}

function printPrescription(data) {
    var printWindow = window.open('', '_blank', 'width=720,height=900');
    if (!printWindow) {
        showNotification('Please allow popups to print prescriptions', 'error');
        return;
    }

    var doctor = (window.DOCTOR_INFO || {});
    var doctorName = doctor.name || 'Attending Physician';
    var doctorSpec = doctor.specialization || 'Pediatrician';
    var doctorLicense = doctor.license_number || '';
    var hospital = doctor.hospital || 'AlagApp Pediatric Clinic';
    var hospitalAddr = doctor.hospital_address || '';
    var hospitalPhone = doctor.hospital_phone || '';
    var hospitalEmail = doctor.hospital_email || '';

    var html = '<!DOCTYPE html><html><head><title>Prescription</title>' +
        '<style>' +
        '@page{margin:0;}' +
        'body{font-family:Georgia,"Times New Roman",serif;margin:0;padding:0;background:#fff;color:#3b0d24;}' +
        '.sheet{max-width:720px;margin:0 auto;padding:40px;border:8px solid #ec4899;border-radius:12px;background:linear-gradient(180deg,#fff 0%,#fff5f8 100%);}' +
        '.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px double #ec4899;padding-bottom:14px;margin-bottom:18px;}' +
        '.clinic{font-size:26px;font-weight:bold;color:#be185d;letter-spacing:0.5px;}' +
        '.clinic-sub{font-size:12px;color:#9d174d;margin-top:2px;}' +
        '.doc-block{text-align:right;color:#9d174d;font-size:13px;line-height:1.4;}' +
        '.doc-block .doc-name{font-size:16px;font-weight:bold;color:#be185d;}' +
        '.title{text-align:center;font-size:22px;letter-spacing:6px;color:#db2777;margin:6px 0 18px;font-weight:bold;}' +
        '.meta{display:flex;justify-content:space-between;font-size:13px;color:#831843;margin-bottom:14px;}' +
        '.rx-row{display:flex;align-items:flex-start;margin:14px 0 6px;}' +
        '.rx-symbol{font-size:60px;font-weight:bold;color:#ec4899;line-height:1;font-family:"Times New Roman",serif;margin-right:18px;}' +
        '.rx-body{flex:1;background:#fff;border:1px solid #fbcfe8;border-radius:8px;padding:14px 18px;}' +
        '.field{margin:8px 0;font-size:14px;color:#3b0d24;}' +
        '.field-label{font-weight:bold;color:#be185d;display:inline-block;min-width:110px;}' +
        '.field-value{}' +
        '.signature-block{margin-top:60px;text-align:right;color:#9d174d;}' +
        '.signature-line{display:inline-block;width:240px;border-top:1.5px solid #9d174d;padding-top:6px;font-size:13px;}' +
        '.signature-name{font-weight:bold;color:#be185d;font-size:15px;}' +
        '.footer{margin-top:24px;border-top:2px dotted #f9a8d4;padding-top:10px;text-align:center;color:#9d174d;font-size:11px;font-style:italic;}' +
        '@media print{body{background:#fff;}.sheet{border-color:#ec4899;}}' +
        '</style></head><body>' +
        '<div class="sheet">' +
            '<div class="header">' +
                '<div>' +
                    '<div class="clinic">' + escapeHtml(hospital) + '</div>' +
                    (hospitalAddr ? '<div class="clinic-sub">' + escapeHtml(hospitalAddr) + '</div>' : '') +
                    (hospitalPhone || hospitalEmail
                        ? '<div class="clinic-sub">' +
                              (hospitalPhone ? 'Tel: ' + escapeHtml(hospitalPhone) : '') +
                              (hospitalPhone && hospitalEmail ? ' &middot; ' : '') +
                              (hospitalEmail ? escapeHtml(hospitalEmail) : '') +
                          '</div>'
                        : '') +
                '</div>' +
                '<div class="doc-block">' +
                    '<div class="doc-name">' + escapeHtml(doctorName) + '</div>' +
                    '<div>' + escapeHtml(doctorSpec) + '</div>' +
                    (doctorLicense ? '<div>License No.: ' + escapeHtml(doctorLicense) + '</div>' : '') +
                '</div>' +
            '</div>' +
            '<div class="title">PRESCRIPTION</div>' +
            '<div class="meta">' +
                '<div><strong>Patient:</strong> ' + escapeHtml(data.patient_name || '') + '</div>' +
                '<div><strong>Date:</strong> ' + escapeHtml(data.date || '') + '</div>' +
            '</div>' +
            '<div class="rx-row">' +
                '<div class="rx-symbol">&#8478;</div>' +
                '<div class="rx-body">' +
                    '<div class="field"><span class="field-label">Medication:</span><span class="field-value">' + escapeHtml(data.medication || '') + '</span></div>' +
                    '<div class="field"><span class="field-label">Dosage:</span><span class="field-value">' + escapeHtml(data.dosage || '') + '</span></div>' +
                    '<div class="field"><span class="field-label">Frequency:</span><span class="field-value">' + escapeHtml(data.frequency || '') + '</span></div>' +
                    '<div class="field"><span class="field-label">Duration:</span><span class="field-value">' + escapeHtml(data.duration || '') + '</span></div>' +
                    (data.instructions ? '<div class="field"><span class="field-label">Instructions:</span><span class="field-value">' + escapeHtml(data.instructions) + '</span></div>' : '') +
                '</div>' +
            '</div>' +
            '<div class="signature-block">' +
                '<div class="signature-line">' +
                    '<div class="signature-name">' + escapeHtml(doctorName) + '</div>' +
                    '<div>' + escapeHtml(doctorSpec) + (doctorLicense ? ' &middot; Lic. ' + escapeHtml(doctorLicense) : '') + '</div>' +
                '</div>' +
            '</div>' +
            '<div class="footer">' +
                escapeHtml(hospital) + (hospitalAddr ? ' &middot; ' + escapeHtml(hospitalAddr) : '') +
                '<br>This prescription was generated electronically by AlagApp Patient Information System.' +
            '</div>' +
        '</div>' +
        '</body></html>';
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 300);
}

function downloadPrescriptionPDF(data) {
    // Dynamically load jsPDF if not already loaded
    if (typeof window.jspdf === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
        script.onload = function() { generatePrescriptionPDF(data); };
        script.onerror = function() {
            showNotification('Failed to load PDF library. Using print instead.', 'error');
            printPrescription(data);
        };
        document.head.appendChild(script);
    } else {
        generatePrescriptionPDF(data);
    }
}

function generatePrescriptionPDF(data) {
    var jsPDF = window.jspdf.jsPDF;
    var doc = new jsPDF();
    var pageWidth = doc.internal.pageSize.getWidth();
    var pageHeight = doc.internal.pageSize.getHeight();

    var info = (window.DOCTOR_INFO || {});
    var doctorName = info.name || 'Attending Physician';
    var doctorSpec = info.specialization || 'Pediatrician';
    var doctorLicense = info.license_number || '';
    var hospital = info.hospital || 'AlagApp Pediatric Clinic';
    var hospitalAddr = info.hospital_address || '';
    var hospitalPhone = info.hospital_phone || '';
    var hospitalEmail = info.hospital_email || '';

    // Pink theme colors
    var pinkBorder = [236, 72, 153];   // #ec4899
    var pinkDark   = [190, 24, 93];    // #be185d
    var pinkAccent = [157, 23, 77];    // #9d174d
    var pinkLight  = [253, 242, 248];  // #fdf2f8
    var pinkHair   = [251, 207, 232];  // #fbcfe8

    // Outer pink border
    doc.setDrawColor(pinkBorder[0], pinkBorder[1], pinkBorder[2]);
    doc.setLineWidth(2);
    doc.rect(8, 8, pageWidth - 16, pageHeight - 16, 'S');

    // Light pink background fill inside border
    doc.setFillColor(pinkLight[0], pinkLight[1], pinkLight[2]);
    doc.rect(10, 10, pageWidth - 20, pageHeight - 20, 'F');

    var y = 22;

    // Hospital / clinic header (left)
    doc.setTextColor(pinkDark[0], pinkDark[1], pinkDark[2]);
    doc.setFontSize(18);
    doc.setFont('helvetica', 'bold');
    doc.text(hospital, 18, y);
    y += 6;
    doc.setFontSize(9);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(pinkAccent[0], pinkAccent[1], pinkAccent[2]);
    if (hospitalAddr) { doc.text(hospitalAddr, 18, y); y += 4; }
    var contact = '';
    if (hospitalPhone) contact += 'Tel: ' + hospitalPhone;
    if (hospitalEmail) contact += (contact ? '   ' : '') + hospitalEmail;
    if (contact) { doc.text(contact, 18, y); y += 4; }

    // Doctor block (right) at top
    var topY = 22;
    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(pinkDark[0], pinkDark[1], pinkDark[2]);
    doc.text(doctorName, pageWidth - 18, topY, { align: 'right' });
    doc.setFontSize(9);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(pinkAccent[0], pinkAccent[1], pinkAccent[2]);
    doc.text(doctorSpec, pageWidth - 18, topY + 5, { align: 'right' });
    if (doctorLicense) {
        doc.text('License No.: ' + doctorLicense, pageWidth - 18, topY + 10, { align: 'right' });
    }

    y = Math.max(y, topY + 14) + 4;

    // Double underline
    doc.setDrawColor(pinkBorder[0], pinkBorder[1], pinkBorder[2]);
    doc.setLineWidth(0.6);
    doc.line(18, y, pageWidth - 18, y);
    doc.line(18, y + 1.4, pageWidth - 18, y + 1.4);
    y += 10;

    // Title
    doc.setFontSize(18);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(pinkDark[0], pinkDark[1], pinkDark[2]);
    doc.text('PRESCRIPTION', pageWidth / 2, y, { align: 'center' });
    y += 10;

    // Patient + Date row
    doc.setFontSize(11);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(pinkDark[0], pinkDark[1], pinkDark[2]);
    doc.text('Patient:', 18, y);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(60, 13, 36);
    doc.text(data.patient_name || '', 38, y);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(pinkDark[0], pinkDark[1], pinkDark[2]);
    doc.text('Date:', pageWidth - 60, y);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(60, 13, 36);
    doc.text(data.date || '', pageWidth - 45, y);
    y += 8;

    // Rx symbol
    doc.setFontSize(40);
    doc.setFont('times', 'bold');
    doc.setTextColor(pinkBorder[0], pinkBorder[1], pinkBorder[2]);
    doc.text('Rx', 22, y + 14);

    // Rx body box
    doc.setDrawColor(pinkHair[0], pinkHair[1], pinkHair[2]);
    doc.setLineWidth(0.3);
    doc.setFillColor(255, 255, 255);
    var boxX = 44, boxY = y + 2, boxW = pageWidth - 18 - boxX, boxH = 60;
    doc.roundedRect(boxX, boxY, boxW, boxH, 3, 3, 'FD');

    // Medication fields inside box
    var fy = boxY + 8;
    doc.setFontSize(11);
    var rxFields = [
        ['Medication', data.medication],
        ['Dosage', data.dosage],
        ['Frequency', data.frequency],
        ['Duration', data.duration],
        ['Instructions', data.instructions]
    ];
    rxFields.forEach(function(f) {
        if (f[1]) {
            doc.setFont('helvetica', 'bold');
            doc.setTextColor(pinkDark[0], pinkDark[1], pinkDark[2]);
            doc.text(f[0] + ':', boxX + 4, fy);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(60, 13, 36);
            var lines = doc.splitTextToSize(f[1], boxW - 38);
            doc.text(lines, boxX + 32, fy);
            fy += 6 * lines.length;
        }
    });

    y = boxY + boxH + 22;

    // Signature line on right
    doc.setDrawColor(pinkAccent[0], pinkAccent[1], pinkAccent[2]);
    doc.setLineWidth(0.5);
    doc.line(pageWidth - 90, y, pageWidth - 18, y);
    y += 5;
    doc.setFontSize(11);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(pinkDark[0], pinkDark[1], pinkDark[2]);
    doc.text(doctorName, pageWidth - 18, y, { align: 'right' });
    y += 5;
    doc.setFontSize(9);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(pinkAccent[0], pinkAccent[1], pinkAccent[2]);
    doc.text(doctorSpec + (doctorLicense ? '  -  Lic. ' + doctorLicense : ''),
             pageWidth - 18, y, { align: 'right' });

    // Footer
    doc.setFontSize(8);
    doc.setFont('helvetica', 'italic');
    doc.setTextColor(pinkAccent[0], pinkAccent[1], pinkAccent[2]);
    var footerText = hospital + (hospitalAddr ? ' - ' + hospitalAddr : '');
    doc.text(footerText, pageWidth / 2, pageHeight - 18, { align: 'center' });
    doc.text('Generated by AlagApp Patient Information System',
             pageWidth / 2, pageHeight - 13, { align: 'center' });

    // Download
    var filename = 'prescription_' + (data.patient_name || 'patient').replace(/[^a-zA-Z0-9]/g, '_') + '_' + new Date().toISOString().slice(0, 10) + '.pdf';
    doc.save(filename);
    showNotification('Prescription PDF downloaded!', 'success');
}

function handleDoctorProfileForm(event) {
    event.preventDefault();
    var form = event.target;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Saving profile...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message || 'Profile updated', 'success');
                // Reflect new name in sidebar immediately
                var fn = form.elements['first_name'] ? form.elements['first_name'].value : '';
                var ln = form.elements['last_name']  ? form.elements['last_name'].value  : '';
                var sp = form.elements['specialization'] ? form.elements['specialization'].value : '';
                var nameEl = document.getElementById('doctorName');
                if (nameEl && fn && ln) nameEl.textContent = 'Dr. ' + fn + ' ' + ln;
                var specEl = document.getElementById('doctorSpecialty');
                if (specEl && sp) specEl.textContent = sp;
            } else {
                showNotification(data.message || 'Failed to update profile', 'error');
            }
        })
        .catch(function(err) {
            console.error(err);
            showNotification('Failed to update profile', 'error');
        });
}

function handleDoctorPasswordForm(event) {
    event.preventDefault();
    var form = event.target;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    var newPw = form.elements['new_password'].value;
    var confirmPw = form.elements['confirm_password'].value;
    if (newPw !== confirmPw) {
        showNotification('New password and confirmation do not match', 'error');
        return;
    }
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Changing password...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message || 'Password changed', 'success');
                form.reset();
            } else {
                showNotification(data.message || 'Failed to change password', 'error');
            }
        })
        .catch(function(err) {
            console.error(err);
            showNotification('Failed to change password', 'error');
        });
}

function handleMedicalRecordForm(event) {
    event.preventDefault();
    var form = event.target;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    var formData = new FormData(form);
    formData.append('ajax', 'true');

    showNotification('Saving medical record...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                closeModal('medicalRecordModal');
                form.reset();
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to save medical record', 'error');
            console.error('Error:', error);
        });
}

// ---- Appointment Actions ----
function approveAppointment(appointmentId) {
    var formData = new FormData();
    formData.append('csrf_token', window.CSRF_TOKEN || '');
    formData.append('action', 'approve_appointment');
    formData.append('appointment_id', appointmentId);
    formData.append('ajax', 'true');

    showNotification('Approving appointment...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to approve appointment', 'error');
            console.error('Error:', error);
        });
}

function rejectAppointment(appointmentId) {
    function doReject(reason) {
        var formData = new FormData();
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        formData.append('action', 'reject_appointment');
        formData.append('appointment_id', appointmentId);
        formData.append('reason', reason);
        formData.append('ajax', 'true');

        showNotification('Rejecting appointment...', 'info');

        fetch('', { method: 'POST', body: formData })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(function(error) {
                showNotification('Failed to reject appointment', 'error');
                console.error('Error:', error);
            });
    }

    // Prefer in-app prompt modal if available
    if (typeof window.appPrompt === 'function') {
        window.appPrompt('Reject Appointment', 'Please provide a reason for rejecting this appointment:', function(reason) {
            if (reason && reason.trim()) doReject(reason.trim());
        });
        return;
    }
    var reason = prompt('Enter reason for rejection:');
    if (!reason) return;
    doReject(reason);
}

function completeAppointment(appointmentId) {
    var formData = new FormData();
    formData.append('csrf_token', window.CSRF_TOKEN || '');
    formData.append('action', 'complete_appointment');
    formData.append('appointment_id', appointmentId);
    formData.append('ajax', 'true');

    showNotification('Completing appointment...', 'info');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to complete appointment', 'error');
            console.error('Error:', error);
        });
}

function filterAppointments() {
    var filter = document.getElementById('appointmentFilter');
    if (!filter) return;

    var value = filter.value.toLowerCase();
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

    var rows = document.querySelectorAll('#appointments-section tbody tr');
    rows.forEach(function(row) {
        if (value === 'all') {
            row.style.display = '';
            return;
        }

        var dateCell = row.querySelector('td:nth-child(2)');
        var statusCell = row.querySelector('td:nth-child(5)');
        var rowDate = dateCell ? dateCell.textContent.trim() : '';
        var rowStatus = statusCell ? statusCell.textContent.trim().toLowerCase() : '';

        var show = false;
        if (value === 'today') {
            var parsedDate = new Date(rowDate);
            parsedDate.setHours(0, 0, 0, 0);
            show = parsedDate.getTime() === today.getTime();
        } else if (value === 'upcoming') {
            var parsedUpcoming = new Date(rowDate);
            parsedUpcoming.setHours(0, 0, 0, 0);
            show = parsedUpcoming >= today && rowStatus !== 'completed' && rowStatus !== 'cancelled';
        } else if (value === 'pending') {
            show = rowStatus.indexOf('pending') !== -1;
        } else if (value === 'completed') {
            show = rowStatus.indexOf('completed') !== -1 || rowStatus.indexOf('complete') !== -1;
        }

        row.style.display = show ? '' : 'none';
    });
}

function viewAppointmentDetails(appointmentId) {
    var formData = new FormData();
    formData.append('action', 'get_appointment_details');
    formData.append('appointment_id', appointmentId);
    formData.append('ajax', 'true');
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification('Appointment loaded', 'success');
                console.log('Appointment Details:', data.appointment);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(function(error) {
            showNotification('Failed to load appointment details', 'error');
            console.error('Error:', error);
        });
}

// ---- Close Modals ----
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-backdrop')) {
        closeAllModals();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllModals();
    }
});

// ============================================
// Doctor Calendar - Schedule & Availability
// ============================================
var doctorCalendar = null;
var doctorCalendarData = { unavailable_dates: [], appointments: [], working_days: [], appointment_counts: {} };

function initDoctorCalendar() {
    var calendarEl = document.getElementById('doctorCalendar');
    if (!calendarEl) return;
    var now = new Date();
    loadDoctorCalendarData(now.getMonth() + 1, now.getFullYear());
}


function loadDoctorCalendarData(month, year) {
    fetch('get_availability.php?doctor_id=' + encodeURIComponent(window.DOCTOR_ID) + '&month=' + month + '&year=' + year, {
        credentials: 'same-origin'
    })
    .then(function(response) { return response.text(); })
    .then(function(text) {
        try {
            var data = JSON.parse(text);
            if (data.success) {
                doctorCalendarData = {
                    unavailable_dates: data.unavailable_dates || [],
                    appointments: data.appointments || [],
                    working_days: data.working_days || [],
                    appointment_counts: data.appointment_counts || {}
                };
                renderDoctorCalendar(month, year);
            } else {
                console.error('Doctor calendar load error:', data);
                renderDoctorCalendar(month, year);
            }
        } catch (err) {
            console.error('Invalid JSON from get_availability:', text);
            renderDoctorCalendar(month, year);
        }
    })
    .catch(function(err) {
        console.error('Error loading doctor calendar data:', err);
        renderDoctorCalendar(month, year);
    });
}

function renderDoctorCalendar(month, year) {
    var container = document.getElementById('doctorCalendar');
    if (!container) return;

    var currentDate = new Date(year, month - 1, 1);
    var firstDay = new Date(year, month - 1, 1);
    var lastDay = new Date(year, month, 0);
    var prevLastDay = new Date(year, month - 1, 0);
    var firstDayIndex = firstDay.getDay();
    var nextDays = 7 - lastDay.getDay() - 1;
    if (nextDays < 0) nextDays = 6;

    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var dayNames = ['SUNDAY','MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY'];

    var html = '<div class="calendar-container">' +
        '<div class="calendar-header">' +
        '<button class="calendar-nav-btn" onclick="navigateDoctorCalendar(-1,' + month + ',' + year + ')"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>' +
        '<h3 class="calendar-month-year">' + months[month - 1] + ' ' + year + '</h3>' +
        '<button class="calendar-nav-btn" onclick="navigateDoctorCalendar(1,' + month + ',' + year + ')"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>' +
        '</div>' +
        '<div class="calendar-weekdays"><div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div></div>' +
        '<div class="calendar-days">';

    // Previous month days
    for (var x = firstDayIndex; x > 0; x--) {
        html += '<div class="calendar-day prev-month">' + (prevLastDay.getDate() - x + 1) + '</div>';
    }

    var today = new Date();
    today.setHours(0, 0, 0, 0);

    for (var day = 1; day <= lastDay.getDate(); day++) {
        var dateStr = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        var dayOfWeek = new Date(year, month - 1, day).getDay();
        var dayName = dayNames[dayOfWeek];

        var dayClass = 'calendar-day';
        var dayData = '';
        var isUnavailable = false;
        var isPast = false;

        var currentDay = new Date(year, month - 1, day);
        if (currentDay < today) {
            dayClass += ' past-date';
            isPast = true;
        }

        if (day === today.getDate() && (month - 1) === today.getMonth() && year === today.getFullYear()) {
            dayClass += ' today';
        }

        if (doctorCalendarData.working_days.length > 0 && doctorCalendarData.working_days.indexOf(dayName) === -1) {
            dayClass += ' non-working-day';
        }

        var unavailable = null;
        for (var u = 0; u < doctorCalendarData.unavailable_dates.length; u++) {
            if (doctorCalendarData.unavailable_dates[u].date === dateStr && doctorCalendarData.unavailable_dates[u].availability_type === 'UNAVAILABLE') {
                unavailable = doctorCalendarData.unavailable_dates[u];
                break;
            }
        }
        if (unavailable) {
            isUnavailable = true;
            dayClass += ' unavailable-date';
            dayData = ' data-reason="' + escapeHtml(unavailable.reason || 'Unavailable') + '"';
        }

        var apptCount = doctorCalendarData.appointment_counts[dateStr] || 0;
        if (apptCount > 0) {
            dayClass += ' has-appointments';
            dayData += ' data-appointment-count="' + apptCount + '"';
        }

        // Doctors can click any future date to toggle availability or view details
        if (!isPast) {
            dayClass += ' clickable';
        }

        html += '<div class="' + dayClass + '" data-date="' + dateStr + '"' + dayData + ' onclick="handleDoctorDayClick(\'' + dateStr + '\',' + isPast + ',' + isUnavailable + ')">' +
            '<span class="day-number">' + day + '</span>' +
            (apptCount > 0 ? '<span class="appointment-indicator">' + apptCount + '</span>' : '') +
            (isUnavailable ? '<span class="unavailable-badge">X</span>' : '') +
            '</div>';
    }

    for (var j = 1; j <= nextDays; j++) {
        html += '<div class="calendar-day next-month">' + j + '</div>';
    }

    html += '</div></div>';
    container.innerHTML = html;

    // Store current month/year for navigation
    container.dataset.month = month;
    container.dataset.year = year;
}

function navigateDoctorCalendar(direction, currentMonth, currentYear) {
    var newMonth = currentMonth + direction;
    var newYear = currentYear;
    if (newMonth < 1) { newMonth = 12; newYear--; }
    if (newMonth > 12) { newMonth = 1; newYear++; }
    loadDoctorCalendarData(newMonth, newYear);
}

function handleDoctorDayClick(dateStr, isPast, isUnavailable) {
    // Show day appointments in the side panel
    showDayAppointments(dateStr);

    // Show a popup modal listing all appointments for the clicked date
    showDayAppointmentsModal(dateStr);

    if (isPast) return;

    // If it's already unavailable, offer to remove it
    if (isUnavailable) {
        doctorConfirm('This date is marked as unavailable. Make it available again?', function() {
            toggleDoctorAvailability(dateStr, false);
        }, { title: 'Make Date Available', confirmText: 'Yes, make available', primary: true });
    } else {
        // Fill the unavailability form date
        var dateInput = document.getElementById('unavailableDate');
        if (dateInput) dateInput.value = dateStr;
    }
}

function showDayAppointmentsModal(dateStr) {
    var modal = document.getElementById('calendarDayModal');
    var titleEl = document.getElementById('calendarDayModalTitle');
    var bodyEl = document.getElementById('calendarDayModalBody');
    if (!modal || !bodyEl) return;

    var dateObj = new Date(dateStr + 'T00:00:00');
    var options = { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' };
    var dateLabel = dateObj.toLocaleDateString('en-US', options);
    if (titleEl) titleEl.textContent = 'Appointments on ' + dateLabel;

    var dayAppointments = (doctorCalendarData.appointments || []).filter(function(a) {
        return (a.date || a.appointment_date) === dateStr;
    });

    if (dayAppointments.length === 0) {
        bodyEl.innerHTML =
            '<div class="text-center py-8">' +
            '<i class="fas fa-calendar-times text-gray-300 text-4xl mb-3"></i>' +
            '<p class="text-gray-500">No appointments scheduled for this day.</p>' +
            '</div>';
    } else {
        // Sort by time
        dayAppointments.sort(function(a, b) {
            var ta = (a.time || a.appointment_time || '');
            var tb = (b.time || b.appointment_time || '');
            return ta.localeCompare(tb);
        });

        var html = '';
        dayAppointments.forEach(function(appt) {
            var time = (appt.time || appt.appointment_time || '');
            time = time ? time.substring(0, 5) : '';
            var patientName = (appt.patient_first_name || appt.first_name || '') + ' ' +
                              (appt.patient_last_name || appt.last_name || '');
            patientName = patientName.trim() || 'Unknown Patient';
            var status = (appt.status || '').toUpperCase();
            var statusClass = '';
            switch (status) {
                case 'CONFIRMED':   statusClass = 'bg-green-100 text-green-800'; break;
                case 'SCHEDULED':   statusClass = 'bg-orange-100 text-orange-800'; break;
                case 'IN_PROGRESS': statusClass = 'bg-blue-100 text-blue-800'; break;
                case 'COMPLETED':   statusClass = 'bg-gray-100 text-gray-800'; break;
                case 'CANCELLED':   statusClass = 'bg-red-100 text-red-800'; break;
                default:            statusClass = 'bg-gray-100 text-gray-800';
            }

            html +=
                '<div class="p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">' +
                    '<div class="flex justify-between items-start mb-2">' +
                        '<div>' +
                            '<div class="font-semibold text-gray-800">' + escapeHtml(patientName) + '</div>' +
                            '<div class="text-sm text-gray-600 mt-1">' +
                                '<i class="far fa-clock mr-1"></i>' + escapeHtml(time || 'N/A') +
                                ' &bull; ' + escapeHtml(appt.type || 'Consultation') +
                            '</div>' +
                            (appt.reason ? '<div class="text-xs text-gray-500 mt-1"><strong>Reason:</strong> ' + escapeHtml(appt.reason) + '</div>' : '') +
                        '</div>' +
                        '<span class="px-3 py-1 text-xs font-semibold rounded-full ' + statusClass + '">' + escapeHtml(status || 'N/A') + '</span>' +
                    '</div>' +
                '</div>';
        });
        bodyEl.innerHTML = html;
    }

    modal.classList.remove('hidden');
}

function showDayAppointments(dateStr) {
    var titleEl = document.getElementById('selectedDateTitle');
    var container = document.getElementById('calendarDayAppointments');
    if (!container) return;

    var dateObj = new Date(dateStr + 'T00:00:00');
    var options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
    if (titleEl) titleEl.textContent = dateObj.toLocaleDateString('en-US', options);

    var dayAppointments = (doctorCalendarData.appointments || []).filter(function(a) {
        return (a.date || a.appointment_date) === dateStr;
    });

    if (dayAppointments.length === 0) {
        container.innerHTML =
            '<div class="text-center py-6">' +
            '<i class="fas fa-calendar-check text-gray-300 text-3xl mb-3"></i>' +
            '<p class="text-gray-500 text-sm">No appointments on this day</p>' +
            '</div>';
        return;
    }

    var html = '';
    dayAppointments.forEach(function(appt) {
        var time = (appt.time || appt.appointment_time || '');
        time = time ? time.substring(0, 5) : '';
        var patientName = (appt.patient_first_name || '') + ' ' + (appt.patient_last_name || '');
        var statusClass = '';
        switch ((appt.status || '').toUpperCase()) {
            case 'CONFIRMED': statusClass = 'bg-green-100 text-green-800'; break;
            case 'SCHEDULED': statusClass = 'bg-orange-100 text-orange-800'; break;
            case 'COMPLETED': statusClass = 'bg-blue-100 text-blue-800'; break;
            default: statusClass = 'bg-gray-100 text-gray-800';
        }

        html +=
            '<div class="p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">' +
            '<div class="flex justify-between items-start">' +
            '<div><div class="font-medium text-gray-800 text-sm">' + escapeHtml(patientName) + '</div>' +
            '<div class="text-xs text-gray-500"><i class="far fa-clock mr-1"></i>' + escapeHtml(time) + '</div></div>' +
            '<span class="px-2 py-0.5 text-xs font-medium rounded-full ' + statusClass + '">' + escapeHtml(appt.status || '') + '</span>' +
            '</div>' +
            '<div class="text-xs text-gray-500 mt-1">' + escapeHtml(appt.type || 'Consultation') + '</div>' +
            '</div>';
    });
    container.innerHTML = html;
}

function toggleDoctorAvailability(dateStr, markUnavailable, reason) {
    var formData = new FormData();
    formData.append('csrf_token', window.CSRF_TOKEN || '');
    formData.append('date', dateStr);

    if (markUnavailable) {
        formData.append('action', 'set_unavailable');
        formData.append('reason', reason || '');
        formData.append('is_all_day', '1');
    } else {
        formData.append('action', 'remove_unavailable');
    }

    fetch('manage_availability.php', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                showNotification(data.message, 'success');
                // Reload calendar data
                var container = document.getElementById('doctorCalendar');
                var m = container ? parseInt(container.dataset.month) : (new Date().getMonth() + 1);
                var y = container ? parseInt(container.dataset.year) : new Date().getFullYear();
                loadDoctorCalendarData(m, y);
            } else {
                showNotification(data.message || 'Error updating availability', 'error');
            }
        })
        .catch(function(err) {
            showNotification('Failed to update availability', 'error');
            console.error('Error:', err);
        });
}

// Handle Set Unavailable form submission
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('setUnavailableForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var dateInput = document.getElementById('unavailableDate');
            var reasonInput = document.getElementById('unavailableReason');
            if (!dateInput || !dateInput.value) {
                showNotification('Please select a date', 'error');
                return;
            }
            toggleDoctorAvailability(dateInput.value, true, reasonInput ? reasonInput.value : '');
            if (reasonInput) reasonInput.value = '';
        });
    }
});

// Initialize doctor calendar when schedule section is shown
var origShowSection = window.showSection;
window.showSection = function(sectionName) {
    origShowSection(sectionName);
    if (sectionName === 'schedule' && !doctorCalendar) {
        doctorCalendar = true;
        initDoctorCalendar();
    }
};

// Also init on load if schedule is the default section
document.addEventListener('DOMContentLoaded', function() {
    // Pre-initialize after a small delay so charts init first
    setTimeout(function() {
        if (document.getElementById('doctorCalendar')) {
            initDoctorCalendar();
        }
    }, 500);
});