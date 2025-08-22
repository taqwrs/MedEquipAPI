<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    $action = $input->action ?? 'get_roles'; // ค่า default

    switch ($action) {

        // ====== CASE 1: ตรวจสอบสิทธิ์เข้าถึงเมนู ======
        case 'check_permission':

            if (!isset($input->menuPath) || !isset($input->name)) {
                echo json_encode(["status" => "error", "message" => "Missing parameters"]);
                exit;
            }

            $stmt = $dbh->prepare("SELECT permission.status FROM menu LEFT JOIN permission ON menu.menu_id = permission.menu_id INNER JOIN users On users.role_id = permission.role_id WHERE menu.path_name = ? AND users.full_name like ?");
            $stmt->execute([$input->menuPath, $input->name]);

            $permission_status = $stmt->fetchColumn();

            if ($permission_status == 1) {
                echo json_encode(["status" => "ok", "message" => "Access granted"]);
            } else {
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => "Access denied"]);
            }
            break;

        // ====== CASE 2: เพิ่มบทบาทใหม่ ======
        case 'add_role':
            if (empty($input->role_name)) {
                echo json_encode(["status" => "error", "message" => "Missing role_name"]);
                exit;
            }

            $stmt = $dbh->prepare("INSERT INTO role (role_name) VALUES (?)");
            $stmt->execute([$input->role_name]);

            $newRoleId = $dbh->lastInsertId();

            echo json_encode(["status" => "ok", "message" => "Role added", "role_id" => $newRoleId]);
            break;

        // ====== CASE 3: แก้ไขชื่อบทบาท ======
        case 'edit_role':
            if (empty($input->role_id) || empty($input->new_name)) {
                echo json_encode(["status" => "error", "message" => "Missing role_id or new_name"]);
                exit;
            }

            $stmt = $dbh->prepare("UPDATE role SET role_name = ? WHERE role_id = ?");
            $stmt->execute([$input->new_name, $input->role_id]);

            echo json_encode(["status" => "ok", "message" => "Role updated"]);
            break;

        // ====== CASE 4: ลบบทบาท ======
        case 'delete_role':
            if (empty($input->role_id)) {
                echo json_encode(["status" => "error", "message" => "Missing role_id"]);
                exit;
            }

            $dbh->prepare("DELETE FROM permission WHERE role_id = ?")->execute([$input->role_id]);
            $dbh->prepare("DELETE FROM role WHERE role_id = ?")->execute([$input->role_id]);

            echo json_encode(["status" => "ok", "message" => "Role deleted"]);
            break;

        // ====== CASE 5: ดึง role ทั้งหมด ======
        case 'get_roles':
        default:
            $sql = "SELECT r.role_id, r.role_name, COUNT(DISTINCT u.user_id) AS user_count, m.path_name FROM role r LEFT JOIN users u ON u.role_id = r.role_id LEFT JOIN permission p ON p.role_id = r.role_id AND p.status = 1 LEFT JOIN menu m ON m.id = p.menu_id GROUP BY r.role_id, m.path_name ORDER BY r.role_id ASC";

            $stmt = $dbh->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($rows as $row) {
                $roleId = $row['role_id'];
                if (!isset($grouped[$roleId])) {
                    $grouped[$roleId] = [
                        "role_id" => $roleId,
                        "role_name" => $row['role_name'],
                        "user_count" => $row['user_count'],
                        "menus" => []
                    ];
                }

                if (!empty($row['path_name'])) {
                    $grouped[$roleId]["menus"][] = ["path_name" => $row['path_name']];
                }
            }

            echo json_encode([
                "status" => "ok",
                "roles" => array_values($grouped)
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
