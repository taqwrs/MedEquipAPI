<?php
include "../config/jwt.php";
include "../config/LogModel.php"; // LogModel

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $input = $_GET;
} else {
    $input = json_decode(file_get_contents("php://input"), true);
}

// รองรับ _method จาก POST
if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

// ดึง ID ของผู้ใช้จาก JWT
$stmtUser = $dbh->prepare("SELECT ID FROM users WHERE user_id = :user_id LIMIT 1");
$stmtUser->bindParam(":user_id", $user_id);
$stmtUser->execute();
$userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
$u_id = $userData['ID'] ?? null;

if (!$u_id) {
    echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
    exit;
}

$logModel = new LogModel($dbh);

try {
    // ================== GET ==================
    if ($method === 'GET') {
        $search = trim($input['search'] ?? '');
        $page   = (int) ($input['page'] ?? 1);
        $limit  = (int) ($input['limit'] ?? 5);
        $offset = ($page - 1) * $limit;
        $useLimit = $limit > 0;

        $countSql = "
            SELECT COUNT(*) as total
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.department_id
            WHERE 1=1
        ";
        if (!empty($search)) {
            $countSql .= " AND (
                u.user_id LIKE :search 
                OR u.full_name LIKE :search 
                OR d.department_name LIKE :search
            )";
        }

        $countStmt = $dbh->prepare($countSql);
        if (!empty($search)) {
            $searchParam = "%$search%";
            $countStmt->bindParam(":search", $searchParam);
        }
        $countStmt->execute();
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $sql = "
            SELECT 
                u.ID,
                u.user_id,
                u.full_name,
                u.department_id,
                d.department_name,
                u.role_id,
                r.role_name,
                u.first_login,
                u.last_login
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN departments d ON u.department_id = d.department_id
            WHERE 1=1
        ";

        if (!empty($search)) {
            $sql .= " AND (
                u.user_id LIKE :search 
                OR u.full_name LIKE :search 
                OR d.department_name LIKE :search
            )";
        }

        $sql .= " ORDER BY u.ID ASC";

        if ($useLimit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $dbh->prepare($sql);

        if (!empty($search)) {
            $stmt->bindParam(":search", $searchParam);
        }
        if ($useLimit) {
            $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "ok",
            "page" => $page,
            "limit" => $limit,
            "total" => (int)$total,
            "data" => $results
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // ================== CREATE ==================
        $required = ['user_id', 'full_name', 'department_id', 'role_id', 'first_login', 'last_login'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                echo json_encode(["status" => "error", "message" => "กรุณากรอก $field"]);
                exit;
            }
        }

        // เริ่ม Transaction
        $dbh->beginTransaction();

        $stmt = $dbh->prepare("
            INSERT INTO users 
                (user_id, full_name, department_id, role_id, first_login, last_login) 
            VALUES 
                (:user_id, :full_name, :department_id, :role_id, :first_login, :last_login)
        ");
        $stmt->bindParam(":user_id", $input['user_id']);
        $stmt->bindParam(":full_name", $input['full_name']);
        $stmt->bindParam(":department_id", $input['department_id']);
        $stmt->bindParam(":role_id", $input['role_id']);
        $stmt->bindParam(":first_login", $input['first_login']);
        $stmt->bindParam(":last_login", $input['last_login']);
        $stmt->execute();

        $inserted_id = $dbh->lastInsertId();

        // บันทึก log
        $newData = [
            'ID' => $inserted_id,
            'user_id' => $input['user_id'],
            'full_name' => $input['full_name'],
            'department_id' => $input['department_id'],
            'role_id' => $input['role_id'],
            'first_login' => $input['first_login'],
            'last_login' => $input['last_login']
        ];
        $logModel->insertLog($u_id, 'users', 'INSERT', null, $newData);

        // Commit Transaction
        $dbh->commit();

        echo json_encode(["status" => "ok", "message" => "เพิ่มผู้ใช้เรียบร้อย", "ID" => $inserted_id]);

    } elseif ($method === 'PUT') {
        // ================== UPDATE ==================
        if (empty($input['ID'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก ID"]);
            exit;
        }

        // ดึงข้อมูลเดิมก่อน update
        $stmtOld = $dbh->prepare("SELECT * FROM users WHERE ID = :ID");
        $stmtOld->bindParam(":ID", $input['ID']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if (!$oldData) {
            echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
            exit;
        }

        // เริ่ม Transaction
        $dbh->beginTransaction();

        // Update
        $stmt = $dbh->prepare("
            UPDATE users SET 
                user_id = :user_id, 
                full_name = :full_name, 
                department_id = :department_id, 
                role_id = :role_id, 
                first_login = :first_login, 
                last_login = :last_login
            WHERE ID = :ID
        ");
        $stmt->bindParam(":user_id", $input['user_id']);
        $stmt->bindParam(":full_name", $input['full_name']);
        $stmt->bindParam(":department_id", $input['department_id']);
        $stmt->bindParam(":role_id", $input['role_id']);
        $stmt->bindParam(":first_login", $input['first_login']);
        $stmt->bindParam(":last_login", $input['last_login']);
        $stmt->bindParam(":ID", $input['ID']);
        $stmt->execute();

        // บันทึก log
        $newData = [
            'ID' => $input['ID'],
            'user_id' => $input['user_id'],
            'full_name' => $input['full_name'],
            'department_id' => $input['department_id'],
            'role_id' => $input['role_id'],
            'first_login' => $input['first_login'],
            'last_login' => $input['last_login']
        ];
        $logModel->insertLog($u_id, 'users', 'UPDATE', $oldData, $newData);

        // Commit Transaction
        $dbh->commit();

        echo json_encode(["status" => "ok", "message" => "แก้ไขผู้ใช้เรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // ================== DELETE ==================
        if (empty($input['ID'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก ID"]);
            exit;
        }

        // ดึงข้อมูลก่อนลบ
        $stmtOld = $dbh->prepare("SELECT * FROM users WHERE ID = :ID");
        $stmtOld->bindParam(":ID", $input['ID']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if (!$oldData) {
            echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
            exit;
        }

        // เริ่ม Transaction
        $dbh->beginTransaction();

        // Delete
        $stmt = $dbh->prepare("DELETE FROM users WHERE ID = :ID");
        $stmt->bindParam(":ID", $input['ID']);
        $stmt->execute();

        // บันทึก log
        $logModel->insertLog($u_id, 'users', 'DELETE', $oldData, null);

        // Commit Transaction
        $dbh->commit();

        echo json_encode(["status" => "ok", "message" => "ลบผู้ใช้เรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    // Rollback ถ้ามี Transaction ที่ยังไม่ commit
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>