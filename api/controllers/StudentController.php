<?php
/**
 * Student Controller — Handles public pre-registration, application tracking, and student lookup
 */
require_once __DIR__ . '/../models/StudentModel.php';

class StudentController {
    private $studentModel;

    public function __construct($pdo) {
        $this->studentModel = new StudentModel($pdo);
    }

    public function register($payload) {
        if (empty($payload['firstName']) || empty($payload['lastName']) || empty($payload['courseCode'])) {
            return ['success' => false, 'message' => 'First name, last name, and course choice are required.', 'code' => 400];
        }

        try {
            $res = $this->studentModel->createPreEnrollment($payload);
            return [
                'success' => true,
                'data' => $res,
                'message' => 'Application submitted successfully.'
            ];
        } catch (Exception $e) {
            logAppError("Student Registration Error: " . $e->getMessage(), ['payload' => $payload]);
            return ['success' => false, 'message' => 'We encountered an issue submitting your application. Please check your details or try again shortly.', 'code' => 500];
        }
    }

    public function track($refNo) {
        if (!$refNo) {
            return ['success' => false, 'message' => 'Reference number is required.', 'code' => 400];
        }

        $student = $this->studentModel->findByReferenceNumber($refNo);
        if (!$student) {
            return ['success' => false, 'message' => 'Application record not found.', 'code' => 404];
        }

        return [
            'success' => true,
            'data' => $student
        ];
    }

    public function getDocuments($identifier) {
        if (empty($identifier)) {
            return ['success' => false, 'message' => 'Student identifier or reference number is required.', 'code' => 400];
        }

        try {
            $data = $this->studentModel->getStudentRequirements($identifier);
            if (!$data) {
                return ['success' => false, 'message' => 'Student record not found.', 'code' => 404];
            }
            return [
                'success' => true,
                'data' => $data
            ];
        } catch (Exception $e) {
            logAppError("Get Documents Error: " . $e->getMessage(), ['identifier' => $identifier]);
            return ['success' => false, 'message' => 'Unable to retrieve document records: ' . $e->getMessage(), 'code' => 500];
        }
    }

    public function uploadDocument($payload) {
        $identifier = trim($payload['studentId'] ?? ($payload['referenceNumber'] ?? ($payload['email'] ?? '')));
        $docKey = trim($payload['docKey'] ?? ($payload['key'] ?? ''));
        $fileName = trim($payload['fileName'] ?? '');
        $fileType = trim($payload['fileType'] ?? '');
        $fileData = $payload['fileData'] ?? ($payload['fileBase64'] ?? ($payload['dataUri'] ?? null));
        $isUndertaking = !empty($payload['isUndertaking']);
        $undertakingReason = trim($payload['undertakingReason'] ?? '');
        $undertakingDeadline = trim($payload['undertakingDeadline'] ?? '');

        if (empty($identifier) || empty($docKey)) {
            return ['success' => false, 'message' => 'Student identifier and document requirement key are required.', 'code' => 400];
        }

        $uploadDir = __DIR__ . '/../../uploads/documents';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $softCopyUrl = null;
        $fileSize = 0;

        // 1. Process Base64 payload
        if (!empty($fileData)) {
            if (preg_match('/^data:([a-zA-Z0-9\/+-]+);base64,(.+)$/', $fileData, $matches)) {
                $fileType = $matches[1];
                $binaryData = base64_decode($matches[2]);
            } else {
                $binaryData = base64_decode($fileData);
            }

            if ($binaryData === false) {
                return ['success' => false, 'message' => 'Invalid base64 document content.', 'code' => 400];
            }

            $ext = 'pdf';
            if (stripos($fileType, 'image/jpeg') !== false || stripos($fileType, 'jpg') !== false) $ext = 'jpg';
            elseif (stripos($fileType, 'image/png') !== false || stripos($fileType, 'png') !== false) $ext = 'png';
            elseif (stripos($fileType, 'image/webp') !== false) $ext = 'webp';
            elseif (stripos($fileType, 'word') !== false || stripos($fileType, 'docx') !== false) $ext = 'docx';
            elseif (stripos($fileName, '.') !== false) {
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            }

            $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $docKey);
            $cleanStudent = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
            $targetName = "doc_{$cleanKey}_{$cleanStudent}_" . time() . ".{$ext}";
            $targetPath = $uploadDir . '/' . $targetName;

            if (file_put_contents($targetPath, $binaryData) === false) {
                return ['success' => false, 'message' => 'Failed to write uploaded file to disk.', 'code' => 500];
            }

            $softCopyUrl = "/systemtest/uploads/documents/{$targetName}";
            $fileSize = strlen($binaryData);
            if (empty($fileName)) {
                $fileName = $targetName;
            }
        } elseif (!empty($_FILES['file']['tmp_name'])) {
            // 2. Process Multipart Upload
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $docKey);
            $cleanStudent = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
            $targetName = "doc_{$cleanKey}_{$cleanStudent}_" . time() . ".{$ext}";
            $targetPath = $uploadDir . '/' . $targetName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'message' => 'Failed to move uploaded document.', 'code' => 500];
            }

            $softCopyUrl = "/systemtest/uploads/documents/{$targetName}";
            $fileName = $file['name'];
            $fileType = $file['type'];
            $fileSize = $file['size'];
        } else {
            return ['success' => false, 'message' => 'No document file data provided.', 'code' => 400];
        }

        try {
            $updated = $this->studentModel->saveStudentDocument(
                $identifier,
                $docKey,
                $softCopyUrl,
                $fileName,
                $fileType,
                $fileSize,
                $isUndertaking,
                $undertakingReason,
                $undertakingDeadline
            );

            return [
                'success' => true,
                'data' => $updated,
                'message' => 'Document uploaded successfully and submitted for Registrar review.'
            ];
        } catch (Exception $e) {
            logAppError("Upload Document Error: " . $e->getMessage(), ['identifier' => $identifier, 'docKey' => $docKey]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    public function verifyDocument($payload) {
        $identifier = trim($payload['studentId'] ?? ($payload['referenceNumber'] ?? ''));
        $docKey = trim($payload['docKey'] ?? ($payload['key'] ?? ''));
        $status = trim($payload['status'] ?? 'VERIFIED');
        $remarks = trim($payload['remarks'] ?? '');
        $verifiedBy = trim($payload['verifiedBy'] ?? 'Registrar Officer');

        if (empty($identifier) || empty($docKey)) {
            return ['success' => false, 'message' => 'Student reference and document key are required.', 'code' => 400];
        }

        try {
            $updated = $this->studentModel->verifyStudentDocument($identifier, $docKey, $status, $remarks, $verifiedBy);
            return [
                'success' => true,
                'data' => $updated,
                'message' => "Document requirement {$docKey} marked as {$status}."
            ];
        } catch (Exception $e) {
            logAppError("Verify Document Error: " . $e->getMessage(), ['identifier' => $identifier, 'docKey' => $docKey]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    public function cleanupTestRecords($payload) {
        $pattern = $payload['email_pattern'] ?? 'test.student.%@gncp.edu.ph';
        try {
            $deleted = $this->studentModel->deleteTestRecords($pattern);
            return [
                'success' => true,
                'data' => ['deleted' => $deleted],
                'message' => "Successfully purged {$deleted} test records."
            ];
        } catch (Exception $e) {
            if (function_exists('logAppError')) {
                logAppError("Cleanup Test Records Error: " . $e->getMessage());
            }
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }
}
