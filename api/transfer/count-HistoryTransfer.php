<?php
include "../config/jwt.php"; // มี $dbh และ $decoded จาก JWT

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ดึง u_id จาก JWT token
    $u_id = $decoded->data->ID;
    if (!$u_id) {
        echo json_encode(["status" => "error", "message" => "ไม่พบรหัสผู้ใช้งานใน Token"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ตรวจสอบ user
    $checkUser = $dbh->prepare("SELECT ID, user_id, full_name, department_id FROM users WHERE ID = :u_id");
    $checkUser->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $checkUser->execute();
    $user = $checkUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "ไม่พบผู้ใช้งานหรือผู้ใช้งานไม่ถูกต้อง"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ฟังก์ชัน query reusable
    function fetchTransfers($dbh, $u_id, $type, $roleField) {
        $sql = "
            SELECT ht.equipment_id, e.name, e.asset_code
            FROM history_transfer ht
            JOIN equipments e ON ht.equipment_id = e.equipment_id
            WHERE ht.transfer_type = :type
              AND ht.$roleField = :u_id
        ";
        $stmt = $dbh->prepare($sql);
        $stmt->bindParam(':type', $type, PDO::PARAM_STR);
        $stmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------
    // 1.1 โอนย้ายชั่วคราว
    $temp_sender = fetchTransfers($dbh, $u_id, "โอนย้ายชั่วคราว", "transfer_user_id");
    $temp_recipient = fetchTransfers($dbh, $u_id, "โอนย้ายชั่วคราว", "recipient_user_id");

    // 1.2 โอนย้ายถาวร
    $perm_sender = fetchTransfers($dbh, $u_id, "โอนย้ายถาวร", "transfer_user_id");
    $perm_recipient = fetchTransfers($dbh, $u_id, "โอนย้ายถาวร", "recipient_user_id");

    // รวมทั้งหมด
    $all_transfers = array_merge($temp_sender, $temp_recipient, $perm_sender, $perm_recipient);

    // -------------------------------
    // Response ภาษาไทย
    echo json_encode([
        "status" => "สำเร็จ",
        "message" => "ดึงข้อมูลการโอนย้ายเรียบร้อยแล้ว",
        "ข้อมูลผู้ใช้งาน" => [
            "ID" => (int)$user['ID'],
            "user_id" => $user['user_id'],
            "full_name" => $user['full_name'],
            "department_id" => $user['department_id'] ? (int)$user['department_id'] : null
        ],
        "สรุปการโอนย้าย" => [
            "การโอนย้ายทั้งหมด" => [
                "จำนวนทั้งหมด" => count($all_transfers),
                "รายการเครื่องมือ" => $all_transfers
            ],
            "การโอนย้ายชั่วคราว" => [
                "เป็นผู้โอน" => [
                    "จำนวน" => count($temp_sender),
                    "รายการเครื่องมือ" => $temp_sender
                ],
                "เป็นผู้รับ" => [
                    "จำนวน" => count($temp_recipient),
                    "รายการเครื่องมือ" => $temp_recipient
                ]
            ],
            "การโอนย้ายถาวร" => [
                "เป็นผู้โอน" => [
                    "จำนวน" => count($perm_sender),
                    "รายการเครื่องมือ" => $perm_sender
                ],
                "เป็นผู้รับ" => [
                    "จำนวน" => count($perm_recipient),
                    "รายการเครื่องมือ" => $perm_recipient
                ]
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
