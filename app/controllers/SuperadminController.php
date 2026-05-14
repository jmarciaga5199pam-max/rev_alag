<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\UserService;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Helpers\Response;

class SuperadminController extends Controller
{
    private UserService $userService;

    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
    }

    /**
     * Show superadmin dashboard.
     *
     * Combines admin-level system stats with superadmin-only shortcuts
     * (user role management, deep user controls) and surfaces parent/doctor
     * sections that superadmin can also browse into.
     */
    public function dashboard(): void
    {
        $stats = $this->userService->getAdminStats();
        $notificationModel = new Notification();
        $notifications = $notificationModel->getUnread($this->userId(), 10);
        $unreadCount = $notificationModel->getUnreadCount($this->userId());

        $activityLog = new ActivityLog();
        $recentActivity = $activityLog->getRecent(15);

        // ─── Cross-role visibility for the superadmin dashboard ───────────
        // Surfaces aggregated data from the parent + doctor roles so the
        // superadmin has a single landing page that mirrors what those roles
        // see in their own dashboards.
        $allChildren = $this->db->fetchAll(
            "SELECT p.id, p.first_name, p.last_name, p.date_of_birth, p.gender,
                    p.blood_type, p.created_at,
                    u.id AS parent_id, u.first_name AS parent_first_name,
                    u.last_name AS parent_last_name, u.email AS parent_email
             FROM patients p
             JOIN users u ON u.id = p.parent_id
             ORDER BY p.created_at DESC
             LIMIT 10"
        );
        $childrenCount = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM patients");

        $allAppointments = $this->db->fetchAll(
            "SELECT a.id, a.appointment_date, a.appointment_time, a.type, a.status,
                    p.first_name AS patient_first_name, p.last_name AS patient_last_name,
                    d.first_name AS doctor_first_name, d.last_name AS doctor_last_name,
                    par.first_name AS parent_first_name, par.last_name AS parent_last_name
             FROM appointments a
             JOIN patients p ON a.patient_id = p.id
             JOIN users d ON a.doctor_id = d.id
             JOIN users par ON p.parent_id = par.id
             ORDER BY a.appointment_date DESC, a.appointment_time DESC
             LIMIT 10"
        );
        $appointmentsCount = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM appointments");

        // Today's schedule across every doctor in the clinic (the doctor view).
        $today = date('Y-m-d');
        $doctorSchedule = $this->db->fetchAll(
            "SELECT a.id, a.appointment_date, a.appointment_time, a.type, a.status,
                    p.first_name AS patient_first_name, p.last_name AS patient_last_name,
                    d.id AS doctor_id, d.first_name AS doctor_first_name, d.last_name AS doctor_last_name
             FROM appointments a
             JOIN patients p ON a.patient_id = p.id
             JOIN users d ON a.doctor_id = d.id
             WHERE a.appointment_date = ?
             ORDER BY d.last_name, a.appointment_time",
            [$today]
        );

        // Per-doctor schedule summary (today + next 7 days) so the superadmin
        // can see who is busy without drilling into individual doctor views.
        $doctorWorkload = $this->db->fetchAll(
            "SELECT u.id, u.first_name, u.last_name,
                    SUM(CASE WHEN a.appointment_date = CURRENT_DATE THEN 1 ELSE 0 END) AS today_count,
                    SUM(CASE WHEN a.appointment_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL 7 DAY THEN 1 ELSE 0 END) AS week_count
             FROM users u
             LEFT JOIN appointments a ON a.doctor_id = u.id AND a.status NOT IN ('CANCELLED','NO_SHOW')
             WHERE u.user_type IN ('DOCTOR','DOCTOR_OWNER') AND u.status = 'active'
             GROUP BY u.id, u.first_name, u.last_name
             ORDER BY u.last_name"
        );

        $this->view('superadmin/dashboard', [
            'user' => $this->currentUser(),
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'allChildren' => $allChildren,
            'childrenCount' => $childrenCount,
            'allAppointments' => $allAppointments,
            'appointmentsCount' => $appointmentsCount,
            'doctorSchedule' => $doctorSchedule,
            'doctorWorkload' => $doctorWorkload,
        ]);
    }

    /**
     * User management page — includes all user types and role-change controls.
     */
    public function users(): void
    {
        $page = (int) ($_GET['page'] ?? 1);
        $filters = [
            'search' => $_GET['search'] ?? '',
            'user_type' => $_GET['user_type'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];

        $result = $this->userService->getUsers($filters, $page);

        if ($this->isAjax()) {
            Response::success($result);
            return;
        }

        $this->view('superadmin/users', [
            'user' => $this->currentUser(),
            'users' => $result['data'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    /**
     * Change a user's role. Doctors cannot have their role changed and
     * no user can be promoted *into* a doctor role from here.
     */
    public function changeRole(): void
    {
        $validation = $this->validate([
            'user_id' => 'required|integer',
            'user_type' => 'required|in:PARENT,DOCTOR,DOCTOR_OWNER,ADMIN,SUPERADMIN',
        ]);

        if (!empty($validation['errors'])) {
            Response::validationError($validation['errors']);
            return;
        }

        $userId = (int) $validation['data']['user_id'];
        $newRole = (string) $validation['data']['user_type'];

        $result = $this->userService->changeUserRole($userId, $newRole, (int) $this->userId());
        $result['success'] ? Response::success(null, $result['message']) : Response::error($result['message']);
    }

    /**
     * Toggle user status — same flow as admin, but exposed under /superadmin.
     */
    public function toggleStatus(): void
    {
        $validation = $this->validate([
            'user_id' => 'required|integer',
            'status' => 'required|in:active,inactive,suspended,pending',
        ]);

        if (!empty($validation['errors'])) {
            Response::validationError($validation['errors']);
            return;
        }

        $userId = (int) $validation['data']['user_id'];
        $status = (string) $validation['data']['status'];

        $result = $this->userService->toggleStatus($userId, $status, (int) $this->userId());
        $result['success'] ? Response::success(null, $result['message']) : Response::error($result['message']);
    }

    /**
     * Delete a user. Doctors cannot be deleted here.
     */
    public function deleteUser(): void
    {
        $validation = $this->validate([
            'user_id' => 'required|integer',
        ]);

        if (!empty($validation['errors'])) {
            Response::validationError($validation['errors']);
            return;
        }

        $userId = (int) $validation['data']['user_id'];
        $result = $this->userService->deleteUser($userId, (int) $this->userId());
        $result['success'] ? Response::success(null, $result['message']) : Response::error($result['message']);
    }

    /**
     * Edit a user's basic profile fields (name/email/phone/role/status).
     */
    public function updateUser(int $id): void
    {
        $validation = $this->validate([
            'first_name' => 'required|max:50',
            'last_name' => 'required|max:50',
            'email' => 'required|email|max:100',
            'user_type' => 'required|in:PARENT,DOCTOR,DOCTOR_OWNER,ADMIN,SUPERADMIN',
            'status' => 'required|in:active,inactive,suspended,pending',
        ]);

        if (!empty($validation['errors'])) {
            Response::validationError($validation['errors']);
            return;
        }

        $data = array_merge($validation['data'], [
            'phone' => $this->input('phone'),
            'date_of_birth' => $this->input('date_of_birth') ?: null,
            'gender' => $this->input('gender') ?: null,
            'address' => $this->input('address'),
            'emergency_contact_name' => $this->input('emergency_contact_name'),
            'emergency_contact_phone' => $this->input('emergency_contact_phone'),
        ]);

        $result = $this->userService->updateUserAsSuperadmin($id, $data, (int) $this->userId());
        $result['success'] ? Response::success(null, $result['message']) : Response::error($result['message']);
    }

    /**
     * Show superadmin appointments page — same controls as the admin
     * booking flow but reachable under the superadmin sidebar.
     */
    public function appointments(): void
    {
        $filters = [
            'doctor_id' => $_GET['doctor_id'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];
        $page = (int) ($_GET['page'] ?? 1);

        $appointmentService = new \App\Services\AppointmentService();
        $result = $appointmentService->getFiltered($filters, $page);

        $userModel = new \App\Models\User();
        $doctors = $userModel->getDoctors();

        $this->view('superadmin/appointments', [
            'user' => $this->currentUser(),
            'appointments' => $result['data'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'doctors' => $doctors,
        ]);
    }

    /**
     * Create an appointment (superadmin-driven, on behalf of any patient).
     */
    public function createAppointment(): void
    {
        $validation = $this->validate([
            'patient_id' => 'required|numeric',
            'doctor_id' => 'required|numeric',
            'appointment_date' => 'required|date',
            'appointment_time' => 'required',
        ]);

        if (!empty($validation['errors'])) {
            Response::validationError($validation['errors']);
            return;
        }

        $data = $validation['data'];
        $data['type'] = $this->input('type', 'CONSULTATION');
        $data['reason'] = $this->input('reason');
        $data['duration'] = (int) $this->input('duration', '30');

        $appointmentService = new \App\Services\AppointmentService();
        $result = $appointmentService->bookAppointment($data, (int) $this->userId());

        $result['success'] ? Response::success($result['data'] ?? null, $result['message']) : Response::error($result['message']);
    }

    /**
     * Create a child (patient) on behalf of any parent.
     */
    public function createChild(): void
    {
        $validation = $this->validate([
            'parent_id' => 'required|numeric',
            'first_name' => 'required|max:50',
            'last_name' => 'required|max:50',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:MALE,FEMALE,OTHER',
        ]);

        if (!empty($validation['errors'])) {
            Response::validationError($validation['errors']);
            return;
        }

        $parentId = (int) $validation['data']['parent_id'];
        $parent = (new \App\Models\User())->find($parentId);
        if (!$parent || $parent['user_type'] !== 'PARENT') {
            Response::error('Selected user is not a parent.');
            return;
        }

        $data = array_merge($validation['data'], [
            'blood_type' => $this->input('blood_type'),
            'height' => $this->input('height'),
            'weight' => $this->input('weight'),
            'allergies' => $this->input('allergies'),
            'medical_conditions' => $this->input('medical_conditions'),
            'special_notes' => $this->input('special_notes'),
        ]);

        $result = $this->userService->addChild($data, $parentId);

        if ($result['success']) {
            (new \App\Models\ActivityLog())->log(
                'PATIENT_CREATED_BY_SUPERADMIN',
                (int) $this->userId(),
                'patient',
                (int) ($result['data']['patient_id'] ?? 0),
                "Superadmin added child for parent #{$parentId}"
            );
        }

        $result['success']
            ? Response::success($result['data'] ?? null, $result['message'])
            : Response::error($result['message']);
    }

    /**
     * List parents — used by the superadmin Add Child modal.
     */
    public function listParents(): void
    {
        $search = trim((string) ($_GET['search'] ?? ''));
        $params = [];
        $sql = "SELECT id, first_name, last_name, email FROM users WHERE user_type = 'PARENT' AND status = 'active'";
        if ($search !== '') {
            $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
            $s = "%{$search}%";
            $params = [$s, $s, $s];
        }
        $sql .= " ORDER BY first_name ASC LIMIT 50";

        $parents = $this->db->fetchAll($sql, $params);
        Response::success($parents);
    }

    /**
     * Show "all children" page — every patient in the system.
     */
    public function children(): void
    {
        $page = (int) ($_GET['page'] ?? 1);
        $perPage = 20;
        $search = trim((string) ($_GET['search'] ?? ''));

        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $s = "%{$search}%";
            $params = [$s, $s, $s, $s, $s];
        }

        $offset = ($page - 1) * $perPage;
        $children = $this->db->fetchAll(
            "SELECT p.id, p.first_name, p.last_name, p.date_of_birth, p.gender, p.blood_type,
                    p.allergies, p.medical_conditions, p.created_at,
                    u.id AS parent_id, u.first_name AS parent_first_name,
                    u.last_name AS parent_last_name, u.email AS parent_email
             FROM patients p
             JOIN users u ON u.id = p.parent_id
             WHERE {$where}
             ORDER BY p.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM patients p JOIN users u ON u.id = p.parent_id WHERE {$where}",
            $params
        );

        if ($this->isAjax()) {
            Response::success($children);
            return;
        }

        $this->view('superadmin/children', [
            'user' => $this->currentUser(),
            'children' => $children,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
            'filters' => ['search' => $search],
        ]);
    }
}
