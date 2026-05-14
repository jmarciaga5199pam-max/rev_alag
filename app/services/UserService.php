<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Patient;
use App\Models\ActivityLog;
use App\Core\Database;

class UserService
{
    private User $userModel;
    private Patient $patientModel;
    private ActivityLog $activityLog;
    private Database $db;

    public function __construct()
    {
        $this->userModel = new User();
        $this->patientModel = new Patient();
        $this->activityLog = new ActivityLog();
        $this->db = Database::getInstance();
    }

    /**
     * Get user profile.
     */
    public function getProfile(int $userId): ?array
    {
        $user = $this->userModel->find($userId);
        if ($user) {
            unset($user['password'], $user['password_reset_token'], $user['two_factor_secret'], $user['email_verification_token']);
        }
        return $user;
    }

    /**
     * Update user profile.
     */
    public function updateProfile(int $userId, array $data): array
    {
        $allowedFields = ['first_name', 'last_name', 'phone', 'date_of_birth', 'gender', 'address',
                          'emergency_contact_name', 'emergency_contact_phone'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return ['success' => false, 'message' => 'No valid data provided.'];
        }

        $this->userModel->updateById($userId, $updateData);
        $this->activityLog->log('PROFILE_UPDATED', $userId, 'user', $userId, 'Profile updated');

        return ['success' => true, 'message' => 'Profile updated successfully.'];
    }

    /**
     * Toggle user status (admin action).
     */
    public function toggleStatus(int $userId, string $status, int $adminId): array
    {
        if ($userId === $adminId) {
            return ['success' => false, 'message' => 'You cannot change your own account status.'];
        }

        $validStatuses = ['active', 'inactive', 'suspended'];
        if (!in_array($status, $validStatuses)) {
            return ['success' => false, 'message' => 'Invalid status.'];
        }

        $this->userModel->updateById($userId, ['status' => $status]);
        $this->activityLog->log('USER_STATUS_CHANGED', $adminId, 'user', $userId, "Status changed to: $status");

        return ['success' => true, 'message' => "User status changed to {$status}."];
    }

    /**
     * Change a user's role (SUPERADMIN-only action).
     *
     * Cannot change the role of an existing doctor, and cannot promote
     * any user into a doctor role — doctor accounts must be provisioned
     * through the dedicated createDoctorAccount flow which enforces
     * license/specialization requirements.
     */
    public function changeUserRole(int $userId, string $newRole, int $superadminId): array
    {
        if ($userId === $superadminId) {
            return ['success' => false, 'message' => 'You cannot change your own role.'];
        }

        $validRoles = ['PARENT', 'DOCTOR', 'DOCTOR_OWNER', 'ADMIN', 'SUPERADMIN'];
        if (!in_array($newRole, $validRoles, true)) {
            return ['success' => false, 'message' => 'Invalid role.'];
        }

        $target = $this->userModel->find($userId);
        if (!$target) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        $currentRole = $target['user_type'];

        if (in_array($currentRole, ['DOCTOR', 'DOCTOR_OWNER'], true)) {
            return ['success' => false, 'message' => "You can't change the role of a doctor."];
        }

        if (in_array($newRole, ['DOCTOR', 'DOCTOR_OWNER'], true)) {
            return ['success' => false, 'message' => 'Doctor accounts must be created via "Add Doctor" so license details are captured.'];
        }

        if ($currentRole === $newRole) {
            return ['success' => false, 'message' => 'User already has this role.'];
        }

        $this->userModel->updateById($userId, ['user_type' => $newRole]);
        $this->activityLog->log(
            'USER_ROLE_CHANGED',
            $superadminId,
            'user',
            $userId,
            "Role changed from $currentRole to $newRole"
        );

        return ['success' => true, 'message' => "User role changed to {$newRole}."];
    }

    /**
     * Update core fields on a user account (SUPERADMIN-only action).
     * Doctor role cannot be changed away from DOCTOR / DOCTOR_OWNER, and
     * nobody can be promoted into a doctor role here.
     */
    public function updateUserAsSuperadmin(int $userId, array $data, int $superadminId): array
    {
        $target = $this->userModel->find($userId);
        if (!$target) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        $allowed = [
            'first_name', 'last_name', 'email', 'phone', 'user_type', 'status',
            'date_of_birth', 'gender', 'address',
            'emergency_contact_name', 'emergency_contact_phone',
        ];
        $update = array_intersect_key($data, array_flip($allowed));

        if (isset($update['email']) && $update['email'] !== $target['email']) {
            if ($this->userModel->exists('email', $update['email'], $userId)) {
                return ['success' => false, 'message' => 'Email is already in use by another account.'];
            }
        }

        if (isset($update['user_type']) && $update['user_type'] !== $target['user_type']) {
            $currentlyDoctor = in_array($target['user_type'], ['DOCTOR', 'DOCTOR_OWNER'], true);
            $targetDoctor = in_array($update['user_type'], ['DOCTOR', 'DOCTOR_OWNER'], true);
            if ($currentlyDoctor) {
                return ['success' => false, 'message' => "You can't change the role of a doctor."];
            }
            if ($targetDoctor) {
                return ['success' => false, 'message' => 'Doctor accounts must be created via "Add Doctor".'];
            }
            if ($userId === $superadminId) {
                return ['success' => false, 'message' => 'You cannot change your own role.'];
            }
            $validRoles = ['PARENT', 'DOCTOR', 'DOCTOR_OWNER', 'ADMIN', 'SUPERADMIN'];
            if (!in_array($update['user_type'], $validRoles, true)) {
                return ['success' => false, 'message' => 'Invalid role.'];
            }
        }

        if (isset($update['status']) && $userId === $superadminId) {
            unset($update['status']);
        }

        if (empty($update)) {
            return ['success' => false, 'message' => 'Nothing to update.'];
        }

        $this->userModel->updateById($userId, $update);

        $changedFields = implode(', ', array_keys($update));
        $this->activityLog->log('USER_UPDATED', $superadminId, 'user', $userId, "Fields updated: {$changedFields}");

        return ['success' => true, 'message' => 'User updated successfully.'];
    }

    /**
     * Delete a user (SUPERADMIN-only action). Doctors cannot be deleted here.
     */
    public function deleteUser(int $userId, int $superadminId): array
    {
        if ($userId === $superadminId) {
            return ['success' => false, 'message' => 'You cannot delete your own account.'];
        }

        $target = $this->userModel->find($userId);
        if (!$target) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        if (in_array($target['user_type'], ['DOCTOR', 'DOCTOR_OWNER'], true)) {
            return ['success' => false, 'message' => "You can't delete a doctor account from here."];
        }

        $this->userModel->deleteById($userId);
        $this->activityLog->log('USER_DELETED', $superadminId, 'user', $userId, "Deleted user {$target['email']}");

        return ['success' => true, 'message' => 'User deleted successfully.'];
    }

    /**
     * Bulk update user statuses.
     */
    public function bulkUpdateStatus(array $userIds, string $status, int $adminId): array
    {
        $userIds = array_filter($userIds, fn($id) => (int) $id !== $adminId);

        if (empty($userIds)) {
            return ['success' => false, 'message' => 'No valid users to update.'];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $params = array_merge([$status], array_map('intval', $userIds));

        $this->db->query(
            "UPDATE users SET status = ? WHERE id IN ($placeholders)",
            $params
        );

        $this->activityLog->log('BULK_STATUS_CHANGE', $adminId, 'user', null,
            count($userIds) . " users changed to $status");

        return ['success' => true, 'message' => count($userIds) . ' users updated successfully.'];
    }

    /**
     * Get users with pagination and filtering.
     */
    public function getUsers(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $sql = "SELECT id, first_name, last_name, email, phone, user_type, status,
                       date_of_birth, gender, address,
                       emergency_contact_name, emergency_contact_phone,
                       created_at
                FROM users WHERE 1=1";
        $countSql = "SELECT COUNT(*) FROM users WHERE 1=1";
        $params = [];
        $countParams = [];

        if (!empty($filters['search'])) {
            $search = "%{$filters['search']}%";
            $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
            $countSql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
            $params = array_merge($params, [$search, $search, $search]);
            $countParams = array_merge($countParams, [$search, $search, $search]);
        }

        if (!empty($filters['user_type'])) {
            $sql .= " AND user_type = ?";
            $countSql .= " AND user_type = ?";
            $params[] = $filters['user_type'];
            $countParams[] = $filters['user_type'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $countSql .= " AND status = ?";
            $params[] = $filters['status'];
            $countParams[] = $filters['status'];
        }

        $total = (int) $this->db->fetchColumn($countSql, $countParams);
        $offset = ($page - 1) * $perPage;

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        return [
            'data' => $this->db->fetchAll($sql, $params),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    // ─── Patient (Child) Management ────────────────────────────────────

    /**
     * Add a child (patient) for a parent.
     */
    public function addChild(array $data, int $parentId): array
    {
        $childId = $this->patientModel->create([
            'parent_id' => $parentId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'],
            'blood_type' => $data['blood_type'] ?? null,
            'height' => $data['height'] ?? null,
            'weight' => $data['weight'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'medical_conditions' => $data['medical_conditions'] ?? null,
            'special_notes' => $data['special_notes'] ?? null,
        ]);

        $this->activityLog->log('PATIENT_CREATED', $parentId, 'patient', $childId, "Patient {$data['first_name']} {$data['last_name']} added");

        // Generate vaccine schedule
        $vaccinationService = new VaccinationService();
        $vaccinationService->generateScheduleForPatient($childId);

        return [
            'success' => true,
            'message' => 'Child profile created successfully.',
            'data' => ['patient_id' => $childId],
        ];
    }

    /**
     * Update a child's profile.
     */
    public function updateChild(int $childId, array $data, int $parentId): array
    {
        if (!$this->patientModel->belongsToParent($childId, $parentId)) {
            return ['success' => false, 'message' => 'Access denied.'];
        }

        $allowedFields = ['first_name', 'last_name', 'date_of_birth', 'gender', 'blood_type',
                          'height', 'weight', 'allergies', 'medical_conditions', 'special_notes'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        $this->patientModel->updateById($childId, $updateData);
        $this->activityLog->log('PATIENT_UPDATED', $parentId, 'patient', $childId, 'Patient profile updated');

        return ['success' => true, 'message' => 'Child profile updated successfully.'];
    }

    /**
     * Get all children for a parent.
     */
    public function getChildren(int $parentId): array
    {
        return $this->patientModel->getByParent($parentId);
    }

    /**
     * Get system-wide statistics (for admin dashboard).
     */
    public function getAdminStats(): array
    {
        return array_merge(
            $this->userModel->getUserStats(),
            $this->patientModel->getStats(),
            (new \App\Models\Appointment())->getStats(),
            (new \App\Models\VaccinationRecord())->getStats()
        );
    }

    /**
     * Export users as CSV.
     */
    public function exportUsersCsv(array $filters = []): string
    {
        $result = $this->getUsers($filters, 1, 10000);
        $users = $result['data'];

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Type', 'Status', 'Created']);

        foreach ($users as $user) {
            fputcsv($output, [
                $user['id'], $user['first_name'], $user['last_name'],
                $user['email'], $user['phone'], $user['user_type'],
                $user['status'], $user['created_at'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
