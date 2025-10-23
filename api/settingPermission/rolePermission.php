<?php
include "../config/jwt.php";
include "../config/LogModel.php"; // LogModel

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

// รองรับ _method
if ($method === "POST" && isset($input["_method"])) {
    $method = strtoupper($input["_method"]);
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
    if ($method === "GET") {
        $stmt = $dbh->prepare("
            SELECT 
                r.role_id,
                r.role_name,
                p.permission_id,
                p.menu_id,
                p.status,
                m.menu_name,
                m.path_name
            FROM roles r
            LEFT JOIN permission p ON r.role_id = p.role_id
            LEFT JOIN menu m ON p.menu_id = m.menu_id
            ORDER BY r.role_id DESC,m.menu_id
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($results as $row) {
            $roleId = $row['role_id'];
            if (!isset($data[$roleId])) {
                $data[$roleId] = [
                    "role_id" => $row['role_id'],
                    "role_name" => $row['role_name'],
                    "permissions" => []
                ];
            }

            if ($row['permission_id']) {
                $data[$roleId]["permissions"][] = [
                    "permission_id" => $row['permission_id'],
                    "menu_id" => $row['menu_id'],
                    "menu_name" => $row['menu_name'],
                    "path_name" => $row['path_name'],
                    "status" => $row['status'] == 1 ? "1 มีสิทธิ์" : "0 ไม่มีสิทธิ์"
                ];
            }
        }

        echo json_encode(["status" => "ok", "data" => array_values($data)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ================== POST ==================
    if ($method === "POST") {
        // ====== เพิ่ม role พร้อม auto permission ======
        if (!empty($input["role_name"])) {
            // ตรวจสอบชื่อซ้ำ
            $stmtCheck = $dbh->prepare("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = LOWER(TRIM(:role_name))");
            $stmtCheck->bindParam(":role_name", $input["role_name"]);
            $stmtCheck->execute();
            
            if ($stmtCheck->fetch()) {
                echo json_encode([
                    "status" => "duplicate", 
                    "message" => "ชื่อบทบาทนี้มีอยู่แล้ว"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $dbh->beginTransaction();
            // เพิ่ม role
            $stmt = $dbh->prepare("INSERT INTO roles (role_name) VALUES (:role_name)");
            $stmt->bindParam(":role_name", $input["role_name"]);
            $stmt->execute();
            $role_id = $dbh->lastInsertId();

            // ดึงเมนูทั้งหมด
            $stmt = $dbh->prepare("SELECT menu_id FROM menu");
            $stmt->execute();
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // เพิ่ม permission ทุกเมนู status=0
            $stmt = $dbh->prepare("INSERT INTO permission (role_id, menu_id, status) VALUES (:role_id, :menu_id, 0)");
            $permissionIds = [];
            foreach ($menus as $menu) {
                $stmt->bindParam(":role_id", $role_id);
                $stmt->bindParam(":menu_id", $menu['menu_id']);
                $stmt->execute();
                $permissionIds[] = $dbh->lastInsertId();
            }

            // บันทึก log สำหรับ role
            $roleLogData = [
                'role_id' => $role_id,
                'role_name' => $input["role_name"]
            ];
            $logModel->insertLog($u_id, 'roles', 'INSERT', null, $roleLogData);

            // บันทึก log สำหรับ permissions
            $permissionsLogData = [
                'role_id' => $role_id,
                'role_name' => $input["role_name"],
                'permissions_created' => count($menus),
                'permission_ids' => $permissionIds
            ];
            $logModel->insertLog($u_id, 'permission', 'INSERT', null, $permissionsLogData);

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "เพิ่ม role และ permission อัตโนมัติเรียบร้อย", "role_id" => $role_id]);
            exit;
        }

        echo json_encode(["status" => "error", "message" => "กรอกข้อมูลไม่ครบ"]);
        exit;
    }

    // ================== PUT ==================
    if ($method === "PUT") {
        // ====== แก้ไขชื่อ role ======
        if (!empty($input["role_id"]) && !empty($input["role_name"]) && empty($input["permissions"])) {
            // ตรวจสอบชื่อซ้ำ (ยกเว้น role_id ปัจจุบัน)
            $stmtCheck = $dbh->prepare("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = LOWER(TRIM(:role_name)) AND role_id != :role_id");
            $stmtCheck->bindParam(":role_name", $input["role_name"]);
            $stmtCheck->bindParam(":role_id", $input["role_id"]);
            $stmtCheck->execute();
            
            if ($stmtCheck->fetch()) {
                echo json_encode([
                    "status" => "duplicate", 
                    "message" => "ชื่อบทบาทนี้มีอยู่แล้ว"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // ดึงข้อมูลเดิม
            $stmtOld = $dbh->prepare("SELECT * FROM roles WHERE role_id = :role_id");
            $stmtOld->bindParam(":role_id", $input["role_id"]);
            $stmtOld->execute();
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

            // อัพเดท
            $stmt = $dbh->prepare("UPDATE roles SET role_name = :role_name WHERE role_id = :role_id");
            $stmt->bindParam(":role_name", $input["role_name"]);
            $stmt->bindParam(":role_id", $input["role_id"]);
            $stmt->execute();

            // บันทึก log
            $newData = [
                'role_id' => $input["role_id"],
                'role_name' => $input["role_name"]
            ];
            $logModel->insertLog($u_id, 'roles', 'UPDATE', $oldData, $newData);

            echo json_encode(["status" => "ok", "message" => "แก้ไข role เรียบร้อย"]);
            exit;
        }

        // ====== แก้ไข permission หลายตัวพร้อมกัน (จาก Frontend) ======
        if (!empty($input["role_id"]) && isset($input["permissions"]) && is_array($input["permissions"])) {
            $dbh->beginTransaction();

            // ดึงข้อมูล role
            $stmtRole = $dbh->prepare("SELECT role_name FROM roles WHERE role_id = :role_id");
            $stmtRole->bindParam(":role_id", $input["role_id"]);
            $stmtRole->execute();
            $roleData = $stmtRole->fetch(PDO::FETCH_ASSOC);

            if (!$roleData) {
                echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูล role"]);
                exit;
            }

            // ดึงข้อมูล permission เดิมก่อน update
            $stmtOldPerms = $dbh->prepare("SELECT * FROM permission WHERE role_id = :role_id");
            $stmtOldPerms->bindParam(":role_id", $input["role_id"]);
            $stmtOldPerms->execute();
            $oldPermissions = $stmtOldPerms->fetchAll(PDO::FETCH_ASSOC);

            // Update แต่ละ permission
            $stmt = $dbh->prepare("UPDATE permission SET status = :status WHERE permission_id = :permission_id");
            $updatedPermissions = [];

            foreach ($input["permissions"] as $perm) {
                if (isset($perm["permission_id"]) && isset($perm["status"])) {
                    $stmt->bindParam(":status", $perm["status"]);
                    $stmt->bindParam(":permission_id", $perm["permission_id"]);
                    $stmt->execute();

                    $updatedPermissions[] = [
                        'permission_id' => $perm["permission_id"],
                        'status' => $perm["status"]
                    ];
                }
            }

            // ดึงข้อมูล permission ใหม่หลัง update
            $stmtNewPerms = $dbh->prepare("SELECT * FROM permission WHERE role_id = :role_id");
            $stmtNewPerms->bindParam(":role_id", $input["role_id"]);
            $stmtNewPerms->execute();
            $newPermissions = $stmtNewPerms->fetchAll(PDO::FETCH_ASSOC);

            // บันทึก log แค่ครั้งเดียว พร้อมข้อมูลรวม
            $oldLogData = [
                'role_id' => $input["role_id"],
                'role_name' => $roleData['role_name'],
                'permissions_updated' => count($updatedPermissions),
                'permissions_data' => $oldPermissions
            ];

            $newLogData = [
                'role_id' => $input["role_id"],
                'role_name' => $roleData['role_name'],
                'permissions_updated' => count($updatedPermissions),
                'permissions_data' => $newPermissions
            ];

            $logModel->insertLog($u_id, 'permission', 'UPDATE', $oldLogData, $newLogData);

            $dbh->commit();
            echo json_encode([
                "status" => "ok", 
                "message" => "แก้ไข permissions เรียบร้อย",
                "updated_count" => count($updatedPermissions)
            ]);
            exit;
        }

        echo json_encode(["status" => "error", "message" => "กรอกข้อมูลไม่ครบสำหรับ PUT"]);
        exit;
    }

    // ================== DELETE ==================
    if ($method === "DELETE") {
        // ====== ลบ role และ permission ทั้งหมด ======
        if (!empty($input["role_id"])) {
            $dbh->beginTransaction();

            // ดึงข้อมูลก่อนลบ
            $stmtRole = $dbh->prepare("SELECT * FROM roles WHERE role_id = :role_id");
            $stmtRole->bindParam(":role_id", $input["role_id"]);
            $stmtRole->execute();
            $roleData = $stmtRole->fetch(PDO::FETCH_ASSOC);

            // ดึงข้อมูล permission ก่อนลบ
            $stmtPerm = $dbh->prepare("SELECT * FROM permission WHERE role_id = :role_id");
            $stmtPerm->bindParam(":role_id", $input["role_id"]);
            $stmtPerm->execute();
            $permissionsData = $stmtPerm->fetchAll(PDO::FETCH_ASSOC);

            // ลบ permission ของ role
            $stmt = $dbh->prepare("DELETE FROM permission WHERE role_id = :role_id");
            $stmt->bindParam(":role_id", $input["role_id"]);
            $stmt->execute();

            // บันทึก log สำหรับ permissions ที่ถูกลบ
            if (!empty($permissionsData)) {
                $permissionsLogData = [
                    'role_id' => $input["role_id"],
                    'permissions_deleted' => count($permissionsData),
                    'permissions_data' => $permissionsData
                ];
                $logModel->insertLog($u_id, 'permission', 'DELETE', $permissionsLogData, null);
            }

            // ลบ role
            $stmt = $dbh->prepare("DELETE FROM roles WHERE role_id = :role_id");
            $stmt->bindParam(":role_id", $input["role_id"]);
            $stmt->execute();

            // บันทึก log สำหรับ role
            $logModel->insertLog($u_id, 'roles', 'DELETE', $roleData, null);

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "ลบ role และ permission เรียบร้อย"]);
            exit;
        }

        echo json_encode(["status" => "error", "message" => "กรอกข้อมูลไม่ครบสำหรับ DELETE"]);
        exit;
    }

    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>