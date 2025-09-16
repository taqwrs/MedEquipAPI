<?php
include "../config/jwt.php"; // $decoded จาก JWT ถูก validate แล้ว

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }

    // ดึง u_id จาก JWT token
    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        echo json_encode(["status" => "error", "message" => "User ID not found in token"]);
        exit;
    }

    // ตรวจสอบว่าผู้ใช้งานมีอยู่จริง
    $checkUser = $dbh->prepare("SELECT ID, user_id, full_name, department_id FROM users WHERE ID = :u_id");
    $checkUser->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $checkUser->execute();
    $user = $checkUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "User not found or inactive"]);
        exit;
    }

    // Query ประวัติการโอนย้ายของผู้ใช้งานที่ login
    $sql = "
        SELECT 
            ht.history_transfer_id,
            ht.transfer_id,
            ht.transfer_type,
            ht.equipment_id,
            ht.transfer_date,
            ht.returned_date,
            ht.reason,
            ht.transfer_user_id,
            ht.recipient_user_id,
            ht.status_transfer,
            e.name AS equipment_name,
            e.asset_code,
            e.brand,
            e.model,
            e.serial_number,
            u_transfer.full_name AS transfer_user_name,
            u_recipient.full_name AS recipient_user_name,
            d_from.department_name AS from_department_name,
            d_to.department_name AS to_department_name
        FROM history_transfer ht
        LEFT JOIN equipments e ON ht.equipment_id = e.equipment_id
        LEFT JOIN users u_transfer ON ht.transfer_user_id = u_transfer.ID
        LEFT JOIN users u_recipient ON ht.recipient_user_id = u_recipient.ID
        LEFT JOIN departments d_from ON ht.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON ht.to_department_id = d_to.department_id
        WHERE ht.transfer_user_id = :u_id OR ht.recipient_user_id = :u_id
        ORDER BY ht.transfer_date DESC, ht.history_transfer_id DESC
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt->execute();
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $temporary = [];
    $permanent = [];
    $summary = [
        "โอนย้ายถาวร_ผู้โอน" => 0,
        "โอนย้ายถาวร_ผู้รับ" => 0,
        "โอนย้ายชั่วคราว_ผู้โอน" => 0,
        "โอนย้ายชั่วคราว_ผู้รับ" => 0
    ];

    foreach ($transfers as $t) {
        $is_sender = ($t['transfer_user_id'] == $u_id);
        $item = [
            "equipment_id" => (int)$t['equipment_id'],
            "name" => $t['equipment_name'],
            "asset_code" => $t['asset_code'],
            "brand" => $t['brand'],
            "model" => $t['model'],
            "serial_number" => $t['serial_number'],
            "transfer_user_id" => (int)$t['transfer_user_id'],
            "transfer_user_name" => $t['transfer_user_name'],
            "recipient_user_id" => (int)$t['recipient_user_id'],
            "recipient_user_name" => $t['recipient_user_name'],
            "transfer_date" => $t['transfer_date'],
            "returned_date" => $t['returned_date'],
            "status" => $t['status_transfer'],
            "reason" => $t['reason'],
            "user_role" => $is_sender ? "ผู้โอน" : "ผู้รับ",
            "from_department" => $t['from_department_name'],
            "to_department" => $t['to_department_name']
        ];

        if ($t['transfer_type'] === 'โอนย้ายชั่วคราว') {
            $temporary[] = $item;
            if ($is_sender) $summary["โอนย้ายชั่วคราว_ผู้โอน"]++;
            else $summary["โอนย้ายชั่วคราว_ผู้รับ"]++;
        } else {
            $permanent[] = $item;
            if ($is_sender) $summary["โอนย้ายถาวร_ผู้โอน"]++;
            else $summary["โอนย้ายถาวร_ผู้รับ"]++;
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "u_id" => (int)$user['ID'],
            "user_id" => $user['user_id'],
            "user_name" => $user['full_name'],
            "โอนย้ายชั่วคราว" => $temporary,
            "โอนย้ายถาวร" => $permanent,
            "summary" => $summary
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "เกิดข้อผิดพลาด: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
