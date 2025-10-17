<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

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
    if ($method === 'GET') {
        // ดึงข้อมูล group_user + relation_user + users + departments
        $stmt = $dbh->prepare("
            SELECT 
                gu.group_user_id,
                gu.group_name,
                gu.type AS group_type,
                ru.relation_user_id,
                u.ID AS u_id,
                u.user_id AS user_id, 
                u.full_name,
                u.department_id,
                d.department_name,
                u.role_id
            FROM group_user gu
            LEFT JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
            LEFT JOIN users u ON ru.u_id = u.ID
            LEFT JOIN departments d ON u.department_id = d.department_id
            ORDER BY gu.group_user_id DESC, u.full_name ASC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ดึง ENUM ของ type
        $stmtEnum = $dbh->prepare("
            SELECT COLUMN_TYPE 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'group_user' 
              AND COLUMN_NAME = 'type'
        ");
        $stmtEnum->execute();
        $enumRow = $stmtEnum->fetch(PDO::FETCH_ASSOC);

        $enumValues = [];
        if ($enumRow) {
            preg_match("/^enum\((.*)\)$/", $enumRow['COLUMN_TYPE'], $matches);
            $enumValues = str_getcsv($matches[1], ",", "'");
        }

        echo json_encode([
            "status" => "ok",
            "enum_type" => $enumValues,
            "data" => $results
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // เริ่ม Transaction
        $dbh->beginTransaction();
        // CREATE group_user
        if (!empty($input['group_name']) && !empty($input['type'])) {
            $stmt = $dbh->prepare("
                INSERT INTO group_user (group_name, type) 
                VALUES (:group_name, :type)
            ");
            $stmt->bindParam(":group_name", $input['group_name']);
            $stmt->bindParam(":type", $input['type']);
            $stmt->execute();

            $group_user_id = $dbh->lastInsertId();

            // Log สำหรับ group_user
            $logDataGroup = [
                'group_user_id' => $group_user_id,
                'group_name' => $input['group_name'],
                'type' => $input['type']
            ];
            $logModel->insertLog($u_id, 'group_user', 'INSERT', null, $logDataGroup);

            // เพิ่ม relation_user ถ้ามี u_ids
            if (!empty($input['u_ids']) && is_array($input['u_ids'])) {
                $stmtCheck = $dbh->prepare("
                    SELECT COUNT(*) FROM relation_user 
                    WHERE group_user_id = :group_user_id AND u_id = :u_id
                ");
                $stmtInsert = $dbh->prepare("
                    INSERT INTO relation_user (group_user_id, u_id)
                    VALUES (:group_user_id, :u_id)
                ");
                foreach ($input['u_ids'] as $uid) {
                    $stmtCheck->execute([
                        ":group_user_id" => $group_user_id,
                        ":u_id" => $uid
                    ]);
                    if ($stmtCheck->fetchColumn() == 0) {
                        $stmtInsert->execute([
                            ":group_user_id" => $group_user_id,
                            ":u_id" => $uid
                        ]);

                        $relation_user_id = $dbh->lastInsertId();

                        // Log สำหรับแต่ละ relation_user
                        $logDataRelation = [
                            'relation_user_id' => $relation_user_id,
                            'group_user_id' => $group_user_id,
                            'u_id' => $uid
                        ];
                        $logModel->insertLog($u_id, 'relation_user', 'INSERT', null, $logDataRelation);
                    }
                }
            }

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "เพิ่ม group_user และ relation_user สำเร็จ"]);

        } elseif (!empty($input['group_user_id']) && !empty($input['u_id'])) {
            // เพิ่ม relation_user แยก
            $stmtCheck = $dbh->prepare("
                SELECT COUNT(*) FROM relation_user 
                WHERE group_user_id = :group_user_id AND u_id = :u_id
            ");
            $stmtCheck->execute([
                ":group_user_id" => $input['group_user_id'],
                ":u_id" => $input['u_id']
            ]);

            if ($stmtCheck->fetchColumn() == 0) {
                $stmtInsert = $dbh->prepare("
                    INSERT INTO relation_user (group_user_id, u_id)
                    VALUES (:group_user_id, :u_id)
                ");
                $stmtInsert->execute([
                    ":group_user_id" => $input['group_user_id'],
                    ":u_id" => $input['u_id']
                ]);

                $relation_user_id = $dbh->lastInsertId();

                // Log สำหรับ relation_user
                $logDataRelation = [
                    'relation_user_id' => $relation_user_id,
                    'group_user_id' => $input['group_user_id'],
                    'u_id' => $input['u_id']
                ];
                $logModel->insertLog($u_id, 'relation_user', 'INSERT', null, $logDataRelation);

                $dbh->commit();
                echo json_encode(["status" => "ok", "message" => "เพิ่ม relation_user สำเร็จ"]);
            } else {
                $dbh->rollBack();
                echo json_encode(["status" => "error", "message" => "ผู้ใช้นี้อยู่ในกลุ่มแล้ว"]);
            }
        } else {
            $dbh->rollBack();
            echo json_encode(["status" => "error", "message" => "ข้อมูลไม่ครบ"]);
        }

    } elseif ($method === 'PUT') {
        // เริ่ม Transaction
        $dbh->beginTransaction();
        // UPDATE group_user พร้อม relation_user แบบยืดหยุ่น
        if (!empty($input['group_user_id']) && !empty($input['group_name']) && !empty($input['type'])) {
            // ดึงข้อมูลเดิมของ group_user
            $stmtOldGroup = $dbh->prepare("SELECT * FROM group_user WHERE group_user_id = :id");
            $stmtOldGroup->bindParam(":id", $input['group_user_id']);
            $stmtOldGroup->execute();
            $oldDataGroup = $stmtOldGroup->fetch(PDO::FETCH_ASSOC);

            // อัปเดต group_user
            $stmt = $dbh->prepare("
                UPDATE group_user 
                SET group_name = :group_name, type = :type 
                WHERE group_user_id = :id
            ");
            $stmt->bindParam(":group_name", $input['group_name']);
            $stmt->bindParam(":type", $input['type']);
            $stmt->bindParam(":id", $input['group_user_id']);
            $stmt->execute();

            // Log สำหรับ group_user
            $logDataGroup = [
                'group_user_id' => $input['group_user_id'],
                'group_name' => $input['group_name'],
                'type' => $input['type']
            ];
            $logModel->insertLog($u_id, 'group_user', 'UPDATE', $oldDataGroup, $logDataGroup);

            // อัปเดต relation_user แบบยืดหยุ่น
            if (isset($input['u_ids']) && is_array($input['u_ids'])) {
                // ดึงข้อมูล relation_user เดิมทั้งหมด
                $stmtOldRelations = $dbh->prepare("
                    SELECT * FROM relation_user WHERE group_user_id = :group_user_id
                ");
                $stmtOldRelations->bindParam(":group_user_id", $input['group_user_id']);
                $stmtOldRelations->execute();
                $oldRelations = $stmtOldRelations->fetchAll(PDO::FETCH_ASSOC);

                // 1. ลบ relation_user ที่ไม่อยู่ใน u_ids ใหม่
                if (!empty($input['u_ids'])) {
                    $placeholders = implode(',', array_fill(0, count($input['u_ids']), '?'));
                    $stmtDelete = $dbh->prepare("
                        DELETE FROM relation_user 
                        WHERE group_user_id = ? 
                        AND u_id NOT IN ($placeholders)
                    ");
                    $stmtDelete->execute(array_merge([$input['group_user_id']], $input['u_ids']));

                    // Log สำหรับแต่ละรายการที่ถูกลบ
                    foreach ($oldRelations as $oldRel) {
                        if (!in_array($oldRel['u_id'], $input['u_ids'])) {
                            $logModel->insertLog($u_id, 'relation_user', 'DELETE', $oldRel, null);
                        }
                    }
                } else {
                    // ถ้าไม่มี u_ids เลย ให้ลบทั้งหมด
                    $stmtDelete = $dbh->prepare("
                        DELETE FROM relation_user WHERE group_user_id = ?
                    ");
                    $stmtDelete->execute([$input['group_user_id']]);

                    foreach ($oldRelations as $oldRel) {
                        $logModel->insertLog($u_id, 'relation_user', 'DELETE', $oldRel, null);
                    }
                }

                // 2. INSERT เฉพาะผู้ใช้งานที่ยังไม่มีในกลุ่ม
                $stmtCheck = $dbh->prepare("
                    SELECT COUNT(*) FROM relation_user 
                    WHERE group_user_id = :group_user_id AND u_id = :u_id
                ");
                $stmtInsert = $dbh->prepare("
                    INSERT INTO relation_user (group_user_id, u_id)
                    VALUES (:group_user_id, :u_id)
                ");
                foreach ($input['u_ids'] as $uid) {
                    $stmtCheck->execute([
                        ":group_user_id" => $input['group_user_id'],
                        ":u_id" => $uid
                    ]);
                    if ($stmtCheck->fetchColumn() == 0) {
                        $stmtInsert->execute([
                            ":group_user_id" => $input['group_user_id'],
                            ":u_id" => $uid
                        ]);

                        $relation_user_id = $dbh->lastInsertId();

                        // Log สำหรับรายการใหม่
                        $logDataRelation = [
                            'relation_user_id' => $relation_user_id,
                            'group_user_id' => $input['group_user_id'],
                            'u_id' => $uid
                        ];
                        $logModel->insertLog($u_id, 'relation_user', 'INSERT', null, $logDataRelation);
                    }
                }
            }

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "อัปเดต group_user และ relation_user แบบยืดหยุ่นสำเร็จ"]);

        } elseif (!empty($input['relation_user_id']) && !empty($input['group_user_id']) && !empty($input['u_id'])) {
            // UPDATE relation_user แยก
            $stmtCheck = $dbh->prepare("
                SELECT COUNT(*) FROM relation_user
                WHERE group_user_id = :group_user_id AND u_id = :u_id
                  AND relation_user_id != :relation_user_id
            ");
            $stmtCheck->execute([
                ":group_user_id" => $input['group_user_id'],
                ":u_id" => $input['u_id'],
                ":relation_user_id" => $input['relation_user_id']
            ]);

            if ($stmtCheck->fetchColumn() == 0) {
                // ดึงข้อมูลเดิม
                $stmtOld = $dbh->prepare("SELECT * FROM relation_user WHERE relation_user_id = :id");
                $stmtOld->bindParam(":id", $input['relation_user_id']);
                $stmtOld->execute();
                $oldDataRelation = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $stmt = $dbh->prepare("
                    UPDATE relation_user 
                    SET group_user_id = :group_user_id, u_id = :u_id 
                    WHERE relation_user_id = :id
                ");
                $stmt->bindParam(":group_user_id", $input['group_user_id']);
                $stmt->bindParam(":u_id", $input['u_id']);
                $stmt->bindParam(":id", $input['relation_user_id']);
                $stmt->execute();

                // Log สำหรับ relation_user
                $logDataRelation = [
                    'relation_user_id' => $input['relation_user_id'],
                    'group_user_id' => $input['group_user_id'],
                    'u_id' => $input['u_id']
                ];
                $logModel->insertLog($u_id, 'relation_user', 'UPDATE', $oldDataRelation, $logDataRelation);

                $dbh->commit();
                echo json_encode(["status" => "ok", "message" => "อัปเดต relation_user สำเร็จ"]);
            } else {
                $dbh->rollBack();
                echo json_encode(["status" => "error", "message" => "ผู้ใช้นี้อยู่ในกลุ่มแล้ว"]);
            }

        } else {
            $dbh->rollBack();
            echo json_encode(["status" => "error", "message" => "ข้อมูลไม่ครบ"]);
        }

    } elseif ($method === 'DELETE') {
        // เริ่ม Transaction
        $dbh->beginTransaction();
        if (!empty($input['group_user_id'])) {
            // ดึงข้อมูล group_user ก่อนลบ
            $stmtOldGroup = $dbh->prepare("SELECT * FROM group_user WHERE group_user_id = :id");
            $stmtOldGroup->bindParam(":id", $input['group_user_id']);
            $stmtOldGroup->execute();
            $oldDataGroup = $stmtOldGroup->fetch(PDO::FETCH_ASSOC);

            // ดึงข้อมูล relation_user ก่อนลบ
            $stmtOldRelations = $dbh->prepare("SELECT * FROM relation_user WHERE group_user_id = :id");
            $stmtOldRelations->bindParam(":id", $input['group_user_id']);
            $stmtOldRelations->execute();
            $oldRelations = $stmtOldRelations->fetchAll(PDO::FETCH_ASSOC);

            // ลบ relation_user ทั้งหมด
            $stmt = $dbh->prepare("DELETE FROM relation_user WHERE group_user_id = :id");
            $stmt->bindParam(":id", $input['group_user_id']);
            $stmt->execute();

            // Log สำหรับแต่ละ relation_user ที่ถูกลบ
            foreach ($oldRelations as $oldRel) {
                $logModel->insertLog($u_id, 'relation_user', 'DELETE', $oldRel, null);
            }

            // ลบ group_user
            $stmt = $dbh->prepare("DELETE FROM group_user WHERE group_user_id = :id");
            $stmt->bindParam(":id", $input['group_user_id']);
            $stmt->execute();

            // Log สำหรับ group_user
            $logModel->insertLog($u_id, 'group_user', 'DELETE', $oldDataGroup, null);

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "ลบ group_user และ relation_user สำเร็จ"]);

        } elseif (!empty($input['relation_user_id'])) {
            // ดึงข้อมูล relation_user ก่อนลบ
            $stmtOld = $dbh->prepare("SELECT * FROM relation_user WHERE relation_user_id = :id");
            $stmtOld->bindParam(":id", $input['relation_user_id']);
            $stmtOld->execute();
            $oldDataRelation = $stmtOld->fetch(PDO::FETCH_ASSOC);

            // ลบ relation_user แยก
            $stmt = $dbh->prepare("DELETE FROM relation_user WHERE relation_user_id = :id");
            $stmt->bindParam(":id", $input['relation_user_id']);
            $stmt->execute();

            // Log สำหรับ relation_user
            $logModel->insertLog($u_id, 'relation_user', 'DELETE', $oldDataRelation, null);

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "ลบ relation_user สำเร็จ"]);

        } else {
            $dbh->rollBack();
            echo json_encode(["status" => "error", "message" => "กรุณาระบุ group_user_id หรือ relation_user_id"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }
} catch (Exception $e) {
    // Rollback ถ้ามี error
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>