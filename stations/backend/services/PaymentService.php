<?php
/**
 * GNCP Workstations — Payment Service
 * Handles cashier payment validation, fee integrity checks, and OR issuance.
 */

class PaymentService {
    /**
     * Validates whether a student is eligible for cashier payment.
     * Prevents issuing Official Receipts for unverified or unapproved applicants.
     */
    public static function validatePaymentEligibility(array $existingRecord) {
        $status = strtoupper($existingRecord['status'] ?? '');
        if ($status === 'PRE_REGISTERED' || $status === 'PENDING') {
            throw new Exception("Student documents must be verified by the Registrar before accepting payment.");
        }
        if ($status === 'REJECTED' || $status === 'FLAGGED') {
            throw new Exception("Cannot process payment for a rejected applicant.");
        }

        // Check roadmap steps to ensure prior steps are completed and not flagged/rejected
        if (!in_array($status, ['PAID', 'PARTIAL', 'ENROLLED', 'PROMOTED', 'ACTIVE'], true)) {
            $roadmap = json_decode($existingRecord['roadmap'] ?? '[]', true) ?: [];
            foreach ($roadmap as $idx => $step) {
                $stepId = strtolower($step['stepId'] ?? '');
                $title  = strtolower($step['title'] ?? ($step['name'] ?? ''));
                $isClinic = in_array($stepId, ['clinic_checkup', 'medical_checkup'], true) || str_contains($title, 'medical') || str_contains($title, 'clinic');
                $stepStatus = strtoupper($step['status'] ?? '');
                if ($isClinic && in_array($stepStatus, ['PENDING', 'LOCKED'], true) && $status !== 'MEDICAL_CLEARED') {
                    throw new Exception("Medical clearance must be completed before accepting cashier payment.");
                }
                if ($stepStatus === 'FLAGGED' || $stepStatus === 'REJECTED') {
                    throw new Exception("Prior workstation step ({$title}) was flagged or rejected.");
                }
            }
        }

        return true;
    }
}
