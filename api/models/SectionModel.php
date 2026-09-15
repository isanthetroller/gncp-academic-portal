<?php
/**
 * Section Model — Handles sections, subject_sections, academic_periods
 */
class SectionModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAllSections() {
        $stmt = $this->pdo->query("SELECT * FROM `sections` ORDER BY `id` DESC");
        return $stmt->fetchAll();
    }

    public function getActiveTerm() {
        $stmt = $this->pdo->query("SELECT * FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1");
        return $stmt->fetch();
    }

    public function getAllTerms() {
        $stmt = $this->pdo->query("SELECT * FROM `academic_periods` ORDER BY `id` DESC");
        return $stmt->fetchAll();
    }

    public function saveSection($sec) {
        // Fetch active period ID if academicPeriodId not explicitly provided or if inactive
        $activePeriodId = $sec['academicPeriodId'] ?? $sec['academic_period_id'] ?? null;
        if ($activePeriodId) {
            $perCheck = $this->pdo->prepare("SELECT `status` FROM `academic_periods` WHERE `id` = :id");
            $perCheck->execute(['id' => (int)$activePeriodId]);
            $perStatus = $perCheck->fetchColumn();
            if (!$perStatus || strtoupper($perStatus) !== 'ACTIVE') {
                $activeTerm = $this->getActiveTerm();
                $activePeriodId = $activeTerm['id'] ?? null;
            }
        } else {
            $activeTerm = $this->getActiveTerm();
            $activePeriodId = $activeTerm['id'] ?? null;
        }

        $targetId = !empty($sec['id']) ? (int)$sec['id'] : null;
        if (!$targetId) {
            $checkStmt = $this->pdo->prepare("
                SELECT id FROM `sections` 
                WHERE `code` = :code AND `program` = :prog AND `year_level` = :yl AND `academic_period_id` = :ap_id 
                LIMIT 1
            ");
            $checkStmt->execute([
                'code'  => $sec['code'],
                'prog'  => $sec['program'],
                'yl'    => $sec['yearLevel'] ?? $sec['year_level'] ?? '1st Year',
                'ap_id' => (int)$activePeriodId
            ]);
            $foundId = $checkStmt->fetchColumn();
            if ($foundId) {
                $targetId = (int)$foundId;
            }
        }

        if ($targetId) {
            $stmt = $this->pdo->prepare("
                UPDATE `sections` 
                SET `code` = :code, 
                    `program` = :prog, 
                    `year_level` = :yl, 
                    `academic_period_id` = :ap_id, 
                    `curriculum_version` = :cver, 
                    `capacity` = :cap, 
                    `adviser` = :adviser 
                WHERE `id` = :id
            ");
            $stmt->execute([
                'code'    => $sec['code'],
                'prog'    => $sec['program'],
                'yl'      => $sec['yearLevel'] ?? $sec['year_level'] ?? '1st Year',
                'ap_id'   => (int)$activePeriodId,
                'cver'    => $sec['curriculumVersion'] ?? $sec['curriculum_version'] ?? '2022 Curriculum',
                'cap'     => (int)($sec['capacity'] ?? 40),
                'adviser' => $sec['adviser'] ?? null,
                'id'      => $targetId
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO `sections` (`code`, `program`, `year_level`, `academic_period_id`, `curriculum_version`, `capacity`, `adviser`) 
                VALUES (:code, :prog, :yl, :ap_id, :cver, :cap, :adviser)
            ");
            $stmt->execute([
                'code'    => $sec['code'],
                'prog'    => $sec['program'],
                'yl'      => $sec['yearLevel'] ?? $sec['year_level'] ?? '1st Year',
                'ap_id'   => (int)$activePeriodId,
                'cver'    => $sec['curriculumVersion'] ?? $sec['curriculum_version'] ?? '2022 Curriculum',
                'cap'     => (int)($sec['capacity'] ?? 40),
                'adviser' => $sec['adviser'] ?? null
            ]);
        }
        return $this->getAllSections();
    }

    public function saveTerm($term) {
        $status = !empty($term['isActive']) || ($term['status'] ?? '') === 'Active' ? 'Active' : 'Draft';
        if ($status === 'Active') {
            $this->pdo->query("UPDATE `academic_periods` SET `status` = 'Closed' WHERE `status` = 'Active'");
        }
        $name = $term['name'] ?? (($term['semester'] ?? '1st Semester') . ' ' . ($term['schoolYear'] ?? $term['academicYear'] ?? '2026-2027'));
        $stmt = $this->pdo->prepare("
            INSERT INTO `academic_periods` (`name`, `academic_year`, `semester`, `status`) 
            VALUES (:name, :sy, :sem, :status)
        ");
        $stmt->execute([
            'name'   => $name,
            'sy'     => $term['schoolYear'] ?? $term['academicYear'] ?? '2026-2027',
            'sem'    => $term['semester'] ?? '1st Semester',
            'status' => $status
        ]);
        return $this->getAllTerms();
    }
}

