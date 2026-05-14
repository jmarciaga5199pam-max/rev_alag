<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Prescription;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Core\Database;

class PrescriptionService
{
    private Prescription $prescriptionModel;
    private ActivityLog $activityLog;
    private Notification $notificationModel;
    private Database $db;

    public function __construct()
    {
        $this->prescriptionModel = new Prescription();
        $this->activityLog = new ActivityLog();
        $this->notificationModel = new Notification();
        $this->db = Database::getInstance();
    }

    /**
     * Create a new prescription.
     */
    public function create(array $data, int $doctorId): array
    {
        $prescriptionNumber = $this->prescriptionModel->generatePrescriptionNumber();

        $medications = is_string($data['medications']) ? $data['medications'] : json_encode($data['medications']);

        $prescriptionId = $this->prescriptionModel->create([
            'prescription_number' => $prescriptionNumber,
            'patient_id' => (int) $data['patient_id'],
            'doctor_id' => $doctorId,
            'appointment_id' => isset($data['appointment_id']) ? (int) $data['appointment_id'] : null,
            'prescription_date' => $data['prescription_date'] ?? date('Y-m-d'),
            'diagnosis' => $data['diagnosis'] ?? null,
            'medications' => $medications,
            'notes' => $data['notes'] ?? null,
        ]);

        // Notify parent — in-app + email
        $parent = $this->db->fetchOne(
            "SELECT u.id, u.email, u.first_name AS parent_first_name, p.first_name AS patient_first_name
             FROM patients p JOIN users u ON u.id = p.parent_id WHERE p.id = ?",
            [(int) $data['patient_id']]
        );
        if ($parent) {
            $message = "A new prescription ({$prescriptionNumber}) has been created for {$parent['patient_first_name']}.";
            $this->notificationModel->createNotification(
                (int) $parent['id'],
                'New Prescription',
                $message,
                'SYSTEM',
                'ALL',
                'prescription',
                $prescriptionId
            );

            if (!empty($parent['email'])) {
                $emailBody = "
                    <h2 style='color:#FF6B9A;margin-top:0;'>New Prescription</h2>
                    <p>Hi " . htmlspecialchars($parent['parent_first_name']) . ",</p>
                    <p>{$message}</p>
                    <p>You can view and print it from your PediCare parent dashboard.</p>
                ";
                (new NotificationService())->sendEmail($parent['email'], 'New Prescription - PediCare Clinic', $emailBody);
            }
        }

        $this->activityLog->log('PRESCRIPTION_CREATED', $doctorId, 'prescription', $prescriptionId,
            "Prescription {$prescriptionNumber} created for patient #{$data['patient_id']}");

        return [
            'success' => true,
            'message' => 'Prescription created successfully.',
            'data' => ['prescription_id' => $prescriptionId, 'prescription_number' => $prescriptionNumber],
        ];
    }

    /**
     * Update a prescription.
     */
    public function update(int $prescriptionId, array $data, int $userId): array
    {
        $prescription = $this->prescriptionModel->find($prescriptionId);
        if (!$prescription) {
            return ['success' => false, 'message' => 'Prescription not found.'];
        }

        $updateData = [];
        if (isset($data['medications'])) {
            $updateData['medications'] = is_string($data['medications']) ? $data['medications'] : json_encode($data['medications']);
        }
        if (isset($data['diagnosis'])) {
            $updateData['diagnosis'] = $data['diagnosis'];
        }
        if (isset($data['notes'])) {
            $updateData['notes'] = $data['notes'];
        }
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        $this->prescriptionModel->updateById($prescriptionId, $updateData);

        $this->activityLog->log('PRESCRIPTION_UPDATED', $userId, 'prescription', $prescriptionId, 'Prescription updated');

        return ['success' => true, 'message' => 'Prescription updated successfully.'];
    }

    /**
     * Delete a prescription.
     */
    public function delete(int $prescriptionId, int $userId): array
    {
        $prescription = $this->prescriptionModel->find($prescriptionId);
        if (!$prescription) {
            return ['success' => false, 'message' => 'Prescription not found.'];
        }

        $this->prescriptionModel->deleteById($prescriptionId);
        $this->activityLog->log('PRESCRIPTION_DELETED', $userId, 'prescription', $prescriptionId,
            "Prescription {$prescription['prescription_number']} deleted");

        return ['success' => true, 'message' => 'Prescription deleted successfully.'];
    }

    /**
     * Get prescriptions for a patient.
     */
    public function getByPatient(int $patientId): array
    {
        return $this->prescriptionModel->getByPatient($patientId);
    }

    /**
     * Get prescription for printing.
     */
    public function getForPrint(int $prescriptionId): ?array
    {
        return $this->prescriptionModel->getForPrint($prescriptionId);
    }
}
