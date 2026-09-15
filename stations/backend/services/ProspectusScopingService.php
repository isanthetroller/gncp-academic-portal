<?php
/**
 * GNCP Workstations — Prospectus & Curriculum Scoping Service
 * Scopes curriculum subjects, units, prerequisites, and section matches for active terms.
 */

class ProspectusScopingService {
    /**
     * Resolves the authoritative list of subjects for a given program, year level, semester, and curriculum version.
     */
    public static function getCurriculumSubjects(PDO $pdo, string $courseCode, string $yearLevel, string $semester, ?string $curriculumVersion = null): array {
        try {
            $progCode = $courseCode;
            $progName = $courseCode;
            $progStmt = $pdo->prepare("SELECT `name` FROM `programs` WHERE `code` = :code LIMIT 1");
            $progStmt->execute(['code' => $courseCode]);
            $pRow = $progStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $progName = $pRow['name'];
            }

            $params = [
                ':progName'   => $progName,
                ':progCode'   => $progCode,
                ':year_level' => $yearLevel,
                ':sem'        => $semester
            ];

            $versionClause = '';
            if (!empty($curriculumVersion)) {
                $versionClause = ' AND c.curriculum_version = :ver';
                $params[':ver'] = $curriculumVersion;
            }

            $stmt = $pdo->prepare("
                SELECT s.code, s.title, s.lecture_units, s.lab_units, (s.lecture_units + s.lab_units) AS units, s.lab_fee, s.prerequisites
                FROM `curriculum` c
                JOIN `subjects` s ON (c.subject = s.title OR c.subject = s.code)
                WHERE (c.program = :progName OR c.program = :progCode)
                  AND c.year_level = :year_level
                  AND c.semester = :sem" . $versionClause . "
                GROUP BY s.code
                ORDER BY s.code ASC
            ");
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($results) && !empty($curriculumVersion)) {
                unset($params[':ver']);
                $fallbackStmt = $pdo->prepare("
                    SELECT s.code, s.title, s.lecture_units, s.lab_units, (s.lecture_units + s.lab_units) AS units, s.lab_fee, s.prerequisites
                    FROM `curriculum` c
                    JOIN `subjects` s ON (c.subject = s.title OR c.subject = s.code)
                    WHERE (c.program = :progName OR c.program = :progCode)
                      AND c.year_level = :year_level
                      AND c.semester = :sem
                    GROUP BY s.code
                    ORDER BY s.code ASC
                ");
                $fallbackStmt->execute($params);
                $results = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            return $results;
        } catch (Exception $e) {
            if (function_exists('logAppError')) {
                logAppError('ProspectusScopingService::getCurriculumSubjects Error', [
                    'error'             => $e->getMessage(),
                    'courseCode'        => $courseCode,
                    'yearLevel'         => $yearLevel,
                    'semester'          => $semester,
                    'curriculumVersion' => $curriculumVersion
                ]);
            }
            return [];
        }
    }

    /**
     * Filters available class schedule sections matching student subjects and active semester
     */
    public static function matchSections(array $sectionsRaw, array $subjectTitles, string $programName, string $yearLevel, string $activeSem): array {
        $matching = [];
        foreach ($sectionsRaw as $sec) {
            if ($sec['capacity'] > 0 &&
                in_array($sec['subject'], $subjectTitles) &&
                (empty($sec['program']) || $sec['program'] === $programName) &&
                (empty($sec['year_level']) || $sec['year_level'] === $yearLevel) &&
                (empty($sec['semester']) || $sec['semester'] === $activeSem)) {

                $matching[] = [
                    'id'         => (int)$sec['id'],
                    'subject'    => $sec['subject'],
                    'code'       => $sec['code'],
                    'instructor' => $sec['instructor'],
                    'days'       => $sec['days'],
                    'time'       => $sec['time'],
                    'room'       => $sec['room'],
                    'capacity'   => (int)$sec['capacity']
                ];
            }
        }
        return $matching;
    }
}
