<?php
/**
 * User Model — Handles station_users table queries and user creation
 */
class UserModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function findByUsername($identity) {
        $stmt = $this->pdo->prepare("SELECT * FROM `station_users` WHERE LOWER(`username`) = LOWER(:u1) OR LOWER(`email`) = LOWER(:u2) LIMIT 1");
        $stmt->execute(['u1' => trim($identity), 'u2' => trim($identity)]);
        return $stmt->fetch();
    }

    public function getAllUsers() {
        $stmt = $this->pdo->query("SELECT id, username, name, email, role, UPPER(COALESCE(NULLIF(status, ''), 'ACTIVE')) as status, must_change_password, created_at FROM `station_users` ORDER BY id DESC");
        return $stmt->fetchAll();
    }

    public function createUser($data) {
        $stmt = $this->pdo->prepare("INSERT INTO `station_users` (`username`, `password`, `name`, `email`, `role`, `status`, `must_change_password`) VALUES (:username, :password, :name, :email, :role, :status, :must_change_password)");
        $hashed = password_hash($data['password'], PASSWORD_DEFAULT);
        $status = strtoupper(trim($data['status'] ?? 'ACTIVE')) ?: 'ACTIVE';
        $stmt->execute([
            'username'             => $data['username'],
            'password'             => $hashed,
            'name'                 => $data['name'],
            'email'                => $data['email'] ?? null,
            'role'                 => $data['role'],
            'status'               => $status,
            'must_change_password' => isset($data['must_change_password']) ? (int)$data['must_change_password'] : 1
        ]);
        return $this->pdo->lastInsertId();
    }

    public function updateUserStatus($id, $status) {
        $stmt = $this->pdo->prepare("UPDATE `station_users` SET `status` = :status WHERE `id` = :id");
        return $stmt->execute(['id' => $id, 'status' => strtoupper(trim($status))]);
    }

    public function findById($id) {
        $stmt = $this->pdo->prepare("SELECT `id`, `username`, `name`, `email`, `role`, UPPER(COALESCE(NULLIF(`status`, ''), 'ACTIVE')) AS `status`, `must_change_password` FROM `station_users` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => (int)$id]);
        return $stmt->fetch();
    }

    public function setOperatorPassword($id, $hashedPassword, $mustChange = 1, $email = null) {
        $sql = "UPDATE `station_users` SET `password` = :pass, `must_change_password` = :must_change";
        $params = [
            'pass'        => $hashedPassword,
            'must_change' => (int)$mustChange,
            'where_id'    => (int)$id
        ];
        if (!empty($email)) {
            $sql .= ", `email` = :email";
            $params['email'] = trim($email);
        }
        $sql .= " WHERE `id` = :where_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function changePassword($identity, $newPassword) {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // 1. Try station_users first
        $stmt = $this->pdo->prepare("UPDATE `station_users` SET `password` = :password, `must_change_password` = 0 WHERE `username` = :uname_where OR `email` = :email_where");
        $stmt->execute(['password' => $hashed, 'uname_where' => $identity, 'email_where' => $identity]);
        if ($stmt->rowCount() > 0) {
            return true;
        }

        // 2. Try pre_enrollments (PIN update for students)
        $stmt = $this->pdo->prepare("UPDATE `pre_enrollments` SET `temp_pin` = :pin WHERE `temp_student_id` = :uname_where OR `email` = :email_where");
        $stmt->execute(['pin' => substr($newPassword, 0, 6), 'uname_where' => $identity, 'email_where' => $identity]);
        return $stmt->rowCount() > 0;
    }

    public function getProfile($identity) {
        // 1. Try station_users first
        $stmt = $this->pdo->prepare("SELECT id, username, name, email, role, status, avatar, avatar AS photo, created_at FROM `station_users` WHERE `username` = :uname_where OR `email` = :email_where");
        $stmt->execute(['uname_where' => $identity, 'email_where' => $identity]);
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }

        // 2. Try students table (for enrolled students)
        $stmt = $this->pdo->prepare("SELECT id, id AS username, name, email, 'STUDENT' AS role, status, photo AS avatar, photo, created_at FROM `students` WHERE `id` = :uname_where OR `email` = :email_where");
        $stmt->execute(['uname_where' => $identity, 'email_where' => $identity]);
        $student = $stmt->fetch();
        if ($student) {
            return $student;
        }

        // 3. Try pre_enrollments table (for pending applicants)
        $stmt = $this->pdo->prepare("SELECT id, temp_student_id AS username, CONCAT(first_name, ' ', last_name) AS name, email, 'STUDENT' AS role, status, NULL AS avatar, NULL AS photo, created_at FROM `pre_enrollments` WHERE `temp_student_id` = :uname_where OR `email` = :email_where");
        $stmt->execute(['uname_where' => $identity, 'email_where' => $identity]);
        $pre = $stmt->fetch();
        if ($pre) {
            return $pre;
        }

        return null;
    }

    public function updateProfile($identity, $name, $email, $avatar = null) {
        // 1. Check if user is in station_users
        $stmt = $this->pdo->prepare("SELECT id FROM `station_users` WHERE `username` = :uname_where OR `email` = :email_where");
        $stmt->execute(['uname_where' => $identity, 'email_where' => $identity]);
        if ($stmt->fetch()) {
            $sql = "UPDATE `station_users` SET `name` = :set_name, `email` = :set_email";
            $params = ['set_name' => $name, 'set_email' => $email, 'where_uname' => $identity, 'where_email' => $identity];
            if ($avatar !== null) {
                $sql .= ", `avatar` = :set_avatar";
                $params['set_avatar'] = $avatar;
            }
            $sql .= " WHERE `username` = :where_uname OR `email` = :where_email";
            return $this->pdo->prepare($sql)->execute($params);
        }

        // 2. Check if student in students table
        $stmt = $this->pdo->prepare("SELECT id FROM `students` WHERE `id` = :uname_where OR `email` = :email_where");
        $stmt->execute(['uname_where' => $identity, 'email_where' => $identity]);
        if ($stmt->fetch()) {
            $sql = "UPDATE `students` SET `name` = :set_name, `email` = :set_email";
            $params = ['set_name' => $name, 'set_email' => $email, 'where_id' => $identity, 'where_email' => $identity];
            if ($avatar !== null) {
                $sql .= ", `photo` = :set_avatar";
                $params['set_avatar'] = $avatar;
            }
            $sql .= " WHERE `id` = :where_id OR `email` = :where_email";
            return $this->pdo->prepare($sql)->execute($params);
        }

        // 3. Check if applicant in pre_enrollments table
        $stmt = $this->pdo->prepare("SELECT id FROM `pre_enrollments` WHERE `temp_student_id` = :uname_where OR `email` = :email_where");
        $stmt->execute(['uname_where' => $identity, 'email_where' => $identity]);
        if ($stmt->fetch()) {
            $parts = explode(' ', trim($name));
            $fn = $parts[0];
            $ln = count($parts) > 1 ? end($parts) : '';
            $sql = "UPDATE `pre_enrollments` SET `first_name` = :set_fn, `last_name` = :set_ln, `email` = :set_email";
            $params = ['set_fn' => $fn, 'set_ln' => $ln, 'set_email' => $email, 'where_id' => $identity, 'where_email' => $identity];
            $sql .= " WHERE `temp_student_id` = :where_id OR `email` = :where_email";
            return $this->pdo->prepare($sql)->execute($params);
        }

        return false;
    }

    public function deleteTestUsers($pattern = 'test_%_auto_%') {
        $stmt1 = $this->pdo->prepare("DELETE FROM `station_users` WHERE `username` LIKE :p1 OR `email` LIKE :p2");
        $stmt1->execute(['p1' => $pattern, 'p2' => 'test.student.%']);
        $count1 = $stmt1->rowCount();

        $stmt2 = $this->pdo->prepare("DELETE FROM `pre_enrollments` WHERE `email` LIKE :p2 OR `first_name` LIKE 'Alexander%' OR `first_name` LIKE 'Jasmine%'");
        $stmt2->execute(['p2' => 'test.student.%']);
        $count2 = $stmt2->rowCount();

        $stmt3 = $this->pdo->prepare("DELETE FROM `students` WHERE `email` LIKE :p2 OR `temp_reference_no` LIKE 'REF-2026-%'");
        $stmt3->execute(['p2' => 'test.student.%']);
        $count3 = $stmt3->rowCount();

        return $count1 + $count2 + $count3;
    }
}
