import subprocess
import json
import os
import shutil

class DBHelper:
    @staticmethod
    def _get_php_executable():
        if os.path.exists(r"C:\xampp\php\php.exe"):
            return r"C:\xampp\php\php.exe"
        php_in_path = shutil.which("php")
        return php_in_path if php_in_path else "php"

    @staticmethod
    def execute_query(sql, params=None):
        """
        Executes a SQL query against MariaDB using the unified PHP Database singleton.
        Returns the fetched rows as a list of dicts.
        """
        if params is None:
            params = {}
        
        import base64
        db_path = os.path.abspath('shared/backend/config/database.php').replace('\\', '/')
        sql_b64 = base64.b64encode(sql.encode('utf-8')).decode('utf-8')
        params_b64 = base64.b64encode(json.dumps(params).encode('utf-8')).decode('utf-8')
        
        script = f"""<?php
        require_once '{db_path}';
        $pdo = Database::getInstance();
        $sql = base64_decode('{sql_b64}');
        $params = json_decode(base64_decode('{params_b64}'), true);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        """
        
        php_bin = DBHelper._get_php_executable()
        proc = subprocess.run([php_bin], input=script, capture_output=True, text=True, cwd=os.path.abspath("."))
        if proc.returncode != 0 or not proc.stdout.strip():
            if proc.stderr:
                print(f"[DBHelper execute_query Error] {proc.stderr.strip()}")
            return []
        try:
            return json.loads(proc.stdout.strip())
        except Exception as e:
            print(f"[DBHelper JSON parse error] {e} | Raw: {proc.stdout.strip()}")
            return []

    @staticmethod
    def execute_statement(sql, params=None):
        """
        Executes an INSERT, UPDATE, or DELETE statement.
        """
        if params is None:
            params = {}
            
        import base64
        db_path = os.path.abspath('shared/backend/config/database.php').replace('\\', '/')
        sql_b64 = base64.b64encode(sql.encode('utf-8')).decode('utf-8')
        params_b64 = base64.b64encode(json.dumps(params).encode('utf-8')).decode('utf-8')
        
        script = f"""<?php
        require_once '{db_path}';
        $pdo = Database::getInstance();
        $sql = base64_decode('{sql_b64}');
        $params = json_decode(base64_decode('{params_b64}'), true);
        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute($params);
        echo json_encode(['success' => (bool)$res, 'rowCount' => $stmt->rowCount()]);
        """
        php_bin = DBHelper._get_php_executable()
        proc = subprocess.run([php_bin], input=script, capture_output=True, text=True, cwd=os.path.abspath("."))
        if proc.returncode != 0 and proc.stderr:
            print(f"[DBHelper execute_statement Error] {proc.stderr.strip()}")
        try:
            return json.loads(proc.stdout.strip())
        except Exception:
            return {"success": False, "rowCount": 0}

    @staticmethod
    def get_pre_enrollment(reference_no):
        rows = DBHelper.execute_query(
            "SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref LIMIT 1",
            {"ref": reference_no}
        )
        return rows[0] if rows else None

    @staticmethod
    def get_student(student_id_or_ref):
        rows = DBHelper.execute_query(
            "SELECT * FROM `students` WHERE `id` = :id OR `temp_reference_no` = :ref LIMIT 1",
            {"id": student_id_or_ref, "ref": student_id_or_ref}
        )
        return rows[0] if rows else None

    @staticmethod
    def get_payments(reference_no):
        return DBHelper.execute_query(
            "SELECT * FROM `payments` WHERE `student_reference` = :ref ORDER BY `id` DESC",
            {"ref": reference_no}
        )

    @staticmethod
    def get_section(section_code):
        rows = DBHelper.execute_query(
            "SELECT * FROM `sections` WHERE `section_code` = :code LIMIT 1",
            {"code": section_code}
        )
        return rows[0] if rows else None

    @staticmethod
    def ensure_active_academic_period():
        active = DBHelper.execute_query("SELECT * FROM `academic_periods` WHERE `status` = 'Active' LIMIT 1")
        if not active:
            DBHelper.execute_statement(
                """
                INSERT INTO `academic_periods` (`name`, `academic_year`, `semester`, `enrollment_start`, `enrollment_end`, `status`)
                VALUES ('1st Semester A.Y. 2026-2027', '2026-2027', '1st Semester', '2026-01-01', '2026-12-31', 'Active')
                """
            )

    @staticmethod
    def cleanup_test_students(email_pattern="test.student.%@gncp.edu.ph"):
        DBHelper.ensure_active_academic_period()
        DBHelper.execute_statement(
            "DELETE FROM `pre_enrollments` WHERE `email` LIKE :pat",
            {"pat": email_pattern}
        )
        DBHelper.execute_statement(
            "DELETE FROM `students` WHERE `email` LIKE :pat OR `personal_info` LIKE :pat2",
            {"pat": email_pattern, "pat2": f"%{email_pattern.replace('%', '')}%"}
        )
