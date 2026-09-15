<?php
/**
 * Section Service — Manages block sections, cohorts, subject sections, and capacity tracking.
 */

class SectionService {

    public static function fetchSections(PDO $pdo) {
        $subjectSectionsRaw = $pdo->query("SELECT * FROM `subject_sections` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);
        $subjectSections = array_map(function($ss) {
            return [
                'id' => (int)$ss['id'],
                'program' => $ss['program'] ?? '',
                'yearLevel' => $ss['year_level'] ?? '',
                'semester' => $ss['semester'] ?? '',
                'subject' => $ss['subject'],
                'code' => $ss['code'],
                'instructor' => $ss['instructor'],
                'days' => $ss['days'],
                'time' => $ss['time'],
                'room' => $ss['room'],
                'capacity' => (int)$ss['capacity']
            ];
        }, $subjectSectionsRaw);

        $sectionsRaw = $pdo->query("
            SELECT s.*,
                   COALESCE(ap.name, '') AS period_name,
                   COALESCE(ap.status, 'Inactive') AS period_status,
                   COALESCE(ap.semester, '1st Semester') AS semester,
                   COALESCE(ap.academic_year, '2026-2027') AS school_year
            FROM `sections` s
            LEFT JOIN `academic_periods` ap ON s.academic_period_id = ap.id
            ORDER BY s.code ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sections = [];
        foreach ($sectionsRaw as $s) {
            $sections[] = [
                'id'                => (int)$s['id'],
                'code'              => $s['code'],
                'program'           => $s['program'],
                'yearLevel'         => $s['year_level'],
                'academicPeriodId'  => (int)$s['academic_period_id'],
                'curriculumVersion' => $s['curriculum_version'] ?? '2022 Curriculum',
                'capacity'          => (int)$s['capacity'],
                'adviser'           => $s['adviser'] ?? 'Unassigned',
                'semester'          => $s['semester'] ?? '1st Semester',
                'schoolYear'        => $s['school_year'] ?? '2026-2027',
                'periodStatus'      => $s['period_status'] ?? 'Inactive'
            ];
        }

        return [
            'sections' => $sections,
            'subjectSections' => $subjectSections
        ];
    }

    public static function getSectionsForProgram(PDO $pdo, $prog, $year = null, $sem = null) {
        if (!$prog) {
            return ['success' => false, 'message' => 'Program code is required.', 'code' => 400];
        }

        $progInput = trim((string)$prog);
        $progUpper = strtoupper($progInput);

        // Dynamically resolve all program codes and names from database
        $programsStmt = $pdo->query("SELECT `code`, `name` FROM `programs`");
        $allPrograms = $programsStmt ? $programsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $searchTokens = [$progInput, $progUpper];
        $matchedProgName = $progInput;
        $matchedProgCode = $progUpper;

        foreach ($allPrograms as $p) {
            $pCode = trim($p['code'] ?? '');
            $pName = trim($p['name'] ?? '');
            if (strcasecmp($pCode, $progInput) === 0 || strcasecmp($pName, $progInput) === 0) {
                $matchedProgName = $pName;
                $matchedProgCode = $pCode;
                $searchTokens[] = $pCode;
                $searchTokens[] = $pName;
                $searchTokens[] = strtoupper($pCode);
                $searchTokens[] = strtoupper($pName);
            }
        }

        // Generic alias handling for common abbreviation patterns (e.g. BS COE / BSCpE)
        if (strpos($progUpper, 'CPE') !== false || strpos($progUpper, 'COE') !== false) {
            $searchTokens[] = 'BSCOE';
            $searchTokens[] = 'BSCpE';
            $searchTokens[] = 'BSCPE';
            $searchTokens[] = 'BS Computer Engineering';
        }

        $searchTokens = array_values(array_unique(array_filter($searchTokens)));

        // Build generic OR conditions for program matching
        $progConditions = [];
        $params = [];
        foreach ($searchTokens as $idx => $token) {
            $paramKey = ':token_' . $idx;
            $progConditions[] = "s.program = {$paramKey}";
            $params[$paramKey] = $token;
        }
        $likeKey = ':token_like';
        $progConditions[] = "s.program LIKE {$likeKey}";
        $params[$likeKey] = '%' . $matchedProgCode . '%';

        $whereSql = '(' . implode(' OR ', $progConditions) . ')';

        // Optional year-level filter
        $yearFilter = null;
        if ($year && strtoupper(trim($year)) !== 'ALL') {
            $yearFilter = trim($year);
        }

        $sql = "
            SELECT s.*,
                   COALESCE(ap.semester, '1st Semester') AS semester,
                   COALESCE(ap.academic_year, '2026-2027') AS school_year,
                   (
                       COALESCE(
                           (SELECT COUNT(*) FROM `pre_enrollments` pe
                            WHERE pe.section_code = s.code
                              AND pe.status NOT IN ('Rejected','PRE_REGISTERED')),
                           0
                       ) + COALESCE(
                           (SELECT COUNT(*) FROM `students` st
                             WHERE JSON_UNQUOTE(JSON_EXTRACT(st.enrollment_data, '$.assignedSection')) = s.code),
                            0
                       )
                   ) AS enrolled_count
            FROM `sections` s
            LEFT JOIN `academic_periods` ap ON s.academic_period_id = ap.id
            WHERE {$whereSql}
            ORDER BY s.code ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If year-level filter was requested, separate matching cohorts
        $formatted = [];
        $yearMatched = [];
        foreach ($sections as $s) {
            $enrolled  = (int)($s['enrolled_count'] ?? 0);
            $capacity  = (int)($s['capacity'] ?? 40);
            $slots     = max(0, $capacity - $enrolled);
            $pct       = $capacity > 0 ? round(($enrolled / $capacity) * 100) : 0;

            $item = [
                'id'                => (int)($s['id'] ?? 0),
                'program'           => $s['program'] ?? $matchedProgName,
                'yearLevel'         => $s['year_level'] ?? ($yearFilter ?: '1st Year'),
                'code'              => $s['code'],
                'sectionName'       => ($s['program'] ?? $matchedProgName) . ' — Section ' . $s['code'],
                'capacity'          => $capacity,
                'enrolledCount'     => $enrolled,
                'availableSlots'    => $slots,
                'occupancyPct'      => $pct,
                'adviser'           => $s['adviser'] ?? 'Unassigned',
                'curriculumVersion' => $s['curriculum_version'] ?? '—',
                'semester'          => $s['semester'] ?? ($sem ?: '1st Semester'),
                'schoolYear'        => $s['school_year'] ?? '—'
            ];
            $formatted[] = $item;

            if ($yearFilter && strcasecmp(trim($s['year_level'] ?? ''), $yearFilter) === 0) {
                $yearMatched[] = $item;
            }
        }

        // Return year-matched sections if found; otherwise return all program sections
        $resultData = (!empty($yearMatched)) ? $yearMatched : $formatted;
        return ['success' => true, 'data' => $resultData, 'allSections' => $formatted];
    }
}
