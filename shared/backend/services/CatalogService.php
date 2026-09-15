<?php
/**
 * Catalog Service — Manages academic programs, subjects, curriculum, and fee schedules.
 */

class CatalogService {

    public static function fetchCatalogData(PDO $pdo) {
        $programs = $pdo->query("SELECT * FROM `programs` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);

        $subjectsRaw = $pdo->query("SELECT * FROM `subjects` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);
        $subjects = array_map(function($sub) {
            return [
                'id' => (int)$sub['id'],
                'code' => $sub['code'],
                'title' => $sub['title'],
                'description' => $sub['description'],
                'lectureUnits' => (int)$sub['lecture_units'],
                'labUnits' => (int)$sub['lab_units'],
                'labFee' => (float)$sub['lab_fee'],
                'department' => $sub['department'],
                'prerequisites' => $sub['prerequisites']
            ];
        }, $subjectsRaw);

        $curriculumRaw = $pdo->query("SELECT c.*, s.code as subject_code, s.lecture_units, s.lab_units, s.lab_fee, s.prerequisites 
                                      FROM `curriculum` c
                                      LEFT JOIN `subjects` s ON c.subject = s.title
                                      ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $curriculum = array_map(function($cur) {
            return [
                'id' => (int)$cur['id'],
                'program' => $cur['program'],
                'subject' => $cur['subject'],
                'subjectCode' => $cur['subject_code'] ?? '',
                'lectureUnits' => (int)($cur['lecture_units'] ?? 0),
                'labUnits' => (int)($cur['lab_units'] ?? 0),
                'labFee' => (float)($cur['lab_fee'] ?? 0.00),
                'prerequisites' => $cur['prerequisites'] ?? 'None',
                'yearLevel' => $cur['year_level'],
                'semester' => $cur['semester'],
                'elective' => (bool)$cur['elective']
            ];
        }, $curriculumRaw);

        $academicPeriodsRaw = $pdo->query("SELECT * FROM `academic_periods` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);
        $academicPeriods = array_map(function($ap) {
            return [
                'id' => (int)$ap['id'],
                'name' => $ap['name'],
                'academicYear' => $ap['academic_year'],
                'semester' => $ap['semester'],
                'enrollmentStart' => $ap['enrollment_start'],
                'enrollmentEnd' => $ap['enrollment_end'],
                'status' => $ap['status']
            ];
        }, $academicPeriodsRaw);

        $feeScheduleRaw = $pdo->query("SELECT * FROM `fee_schedule` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);
        $feeSchedule = array_map(function($fs) {
            return [
                'id' => (int)$fs['id'],
                'type' => $fs['type'],
                'label' => $fs['label'],
                'amount' => (float)$fs['amount'],
                'perUnit' => (bool)$fs['per_unit']
            ];
        }, $feeScheduleRaw);

        return [
            'programs' => $programs,
            'subjects' => $subjects,
            'curriculum' => $curriculum,
            'academicPeriods' => $academicPeriods,
            'feeSchedule' => $feeSchedule
        ];
    }
}
