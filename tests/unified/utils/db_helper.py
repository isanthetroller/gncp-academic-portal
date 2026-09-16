import re
import pymysql
import sys
import os

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
from config import DB_CONFIG

class DBHelper:
    @staticmethod
    def get_connection():
        return pymysql.connect(
            **DB_CONFIG,
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=True
        )

    @staticmethod
    def _normalize_sql_and_params(sql, params):
        if isinstance(params, dict):
            # Convert :name to %(name)s
            sql = re.sub(r':([a-zA-Z0-9_]+)', r'%(\1)s', sql)
            return sql, params
        return sql, params or ()

    @staticmethod
    def execute_query(sql, params=None):
        sql, params = DBHelper._normalize_sql_and_params(sql, params)
        conn = DBHelper.get_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute(sql, params)
                return cursor.fetchall()
        finally:
            conn.close()

    @staticmethod
    def execute_statement(sql, params=None):
        sql, params = DBHelper._normalize_sql_and_params(sql, params)
        conn = DBHelper.get_connection()
        try:
            with conn.cursor() as cursor:
                affected = cursor.execute(sql, params)
                return affected
        finally:
            conn.close()

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
    def purge_test_data():
        queries = [
            "DELETE FROM pre_enrollments WHERE email LIKE 'test.%' OR email LIKE 'dup.test.%' OR temp_student_id LIKE 'TEST-%'",
            "DELETE FROM students WHERE institutional_email LIKE 'test.%' OR id LIKE 'TEST-%'",
            "DELETE FROM announcements WHERE title LIKE 'TEST_%' OR title LIKE 'UNIFIED_TEST_%'",
            "DELETE FROM academic_milestones WHERE title LIKE 'TEST_%' OR title LIKE 'UNIFIED_TEST_%'"
        ]
        for q in queries:
            try:
                DBHelper.execute_statement(q)
            except Exception:
                pass
