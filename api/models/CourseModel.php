<?php
/**
 * Course Model — Handles programs, subjects, and curriculum tables
 */
class CourseModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAllPrograms() {
        $stmt = $this->pdo->query("SELECT * FROM `programs` ORDER BY `id` DESC");
        return $stmt->fetchAll();
    }

    public function getAllSubjects() {
        $stmt = $this->pdo->query("SELECT * FROM `subjects` ORDER BY `id` DESC");
        return $stmt->fetchAll();
    }

    public function getCurriculum() {
        $stmt = $this->pdo->query("SELECT * FROM `curriculum` ORDER BY `id` ASC");
        return $stmt->fetchAll();
    }

    public function getFullCatalog() {
        return [
            'programs' => $this->getAllPrograms(),
            'subjects' => $this->getAllSubjects(),
            'curriculum' => $this->getCurriculum()
        ];
    }

    public function saveProgram($programData) {
        $status = $programData['status'] ?? 'Active';
        if (!empty($programData['id'])) {
            $stmt = $this->pdo->prepare("UPDATE `programs` SET `code`=:code,`name`=:name,`department`=:dept,`status`=:status WHERE `id`=:id");
            $stmt->execute([
                'code'   => $programData['code'] ?? '',
                'name'   => $programData['name'] ?? '',
                'dept'   => $programData['department'] ?? '',
                'status' => $status,
                'id'     => (int)$programData['id']
            ]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO `programs`(`code`,`name`,`department`,`status`) VALUES(:code,:name,:dept,:status)");
            $stmt->execute([
                'code'   => $programData['code'] ?? '',
                'name'   => $programData['name'] ?? '',
                'dept'   => $programData['department'] ?? '',
                'status' => $status
            ]);
        }
        return $this->getAllPrograms();
    }

    public function saveSubject($subjectData) {
        if (!empty($subjectData['id'])) {
            $stmt = $this->pdo->prepare("UPDATE `subjects` SET `code`=:code, `title`=:title, `description`=:desc, `lecture_units`=:lec, `lab_units`=:lab, `lab_fee`=:fee, `prerequisites`=:pre WHERE `id`=:id");
            $stmt->execute([
                'code'   => $subjectData['code'],
                'title'  => $subjectData['title'],
                'desc'   => $subjectData['description'] ?? '',
                'lec'    => (int)($subjectData['lectureUnits'] ?? 0),
                'lab'    => (int)($subjectData['labUnits'] ?? 0),
                'fee'    => (float)($subjectData['labFee'] ?? 0),
                'pre'    => is_array($subjectData['prerequisites'] ?? null) ? json_encode($subjectData['prerequisites']) : ($subjectData['prerequisites'] ?? '[]'),
                'id'     => (int)$subjectData['id']
            ]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO `subjects` (`code`, `title`, `description`, `lecture_units`, `lab_units`, `lab_fee`, `prerequisites`) VALUES (:code, :title, :desc, :lec, :lab, :fee, :pre)");
            $stmt->execute([
                'code'   => $subjectData['code'],
                'title'  => $subjectData['title'],
                'desc'   => $subjectData['description'] ?? '',
                'lec'    => (int)($subjectData['lectureUnits'] ?? 0),
                'lab'    => (int)($subjectData['labUnits'] ?? 0),
                'fee'    => (float)($subjectData['labFee'] ?? 0),
                'pre'    => is_array($subjectData['prerequisites'] ?? null) ? json_encode($subjectData['prerequisites']) : ($subjectData['prerequisites'] ?? '[]')
            ]);
        }
        return $this->getAllSubjects();
    }

    public function saveCurriculum($currData) {
        $prog = $currData['program'] ?? '';
        $sub = $currData['subject'] ?? '';
        $yl = $currData['yearLevel'] ?? '1st Year';
        $sem = $currData['semester'] ?? '1st Semester';
        $el = (int)($currData['elective'] ?? 0);
        $currV = $currData['curriculumVersion'] ?? '2022 Curriculum';

        if (!empty($currData['id'])) {
            $stmt = $this->pdo->prepare("UPDATE `curriculum` SET `program`=:prog,`subject`=:sub,`year_level`=:yl,`semester`=:sem,`elective`=:el,`curriculum_version`=:curr_v WHERE `id`=:id");
            $stmt->execute([
                'prog'   => $prog,
                'sub'    => $sub,
                'yl'     => $yl,
                'sem'    => $sem,
                'el'     => $el,
                'curr_v' => $currV,
                'id'     => (int)$currData['id']
            ]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO `curriculum`(`program`,`subject`,`year_level`,`semester`,`elective`,`curriculum_version`) VALUES(:prog,:sub,:yl,:sem,:el,:curr_v)");
            $stmt->execute([
                'prog'   => $prog,
                'sub'    => $sub,
                'yl'     => $yl,
                'sem'    => $sem,
                'el'     => $el,
                'curr_v' => $currV
            ]);
        }
        return $this->getCurriculum();
    }

    public function deleteCurriculum($id) {
        $stmt = $this->pdo->prepare("DELETE FROM `curriculum` WHERE `id`=:id");
        $stmt->execute(['id' => (int)$id]);
        return $this->getCurriculum();
    }

    public function cloneCurriculumVersion($program, $fromVersion, $toVersion) {
        $sourceRows = $this->pdo->prepare("SELECT * FROM `curriculum` WHERE `program` = :prog AND `curriculum_version` = :from_v");
        $sourceRows->execute(['prog' => $program, 'from_v' => $fromVersion]);
        $entries = $sourceRows->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($entries)) {
            $del = $this->pdo->prepare("DELETE FROM `curriculum` WHERE `program` = :prog AND `curriculum_version` = :to_v");
            $del->execute(['prog' => $program, 'to_v' => $toVersion]);

            $ins = $this->pdo->prepare("INSERT INTO `curriculum` (`program`, `subject`, `year_level`, `semester`, `elective`, `curriculum_version`) VALUES (:prog, :sub, :yl, :sem, :el, :curr_v)");
            foreach ($entries as $e) {
                $ins->execute([
                    'prog'   => $program,
                    'sub'    => $e['subject'],
                    'yl'     => $e['year_level'],
                    'sem'    => $e['semester'],
                    'el'     => (int)$e['elective'],
                    'curr_v' => $toVersion
                ]);
            }
        }
        return $this->getCurriculum();
    }
}
