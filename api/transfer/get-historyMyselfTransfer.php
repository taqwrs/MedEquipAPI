<?php
include "../config/jwt.php"; 
// ประวัติการโอนย้ายที่เกี่ยวข้องกับ u_id ที่ login อยู่

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ฟังก์ชันแปลงวันที่เป็น DD-MM-YYYY
function formatDate($date) {
    return $date ? date("d/m/Y", strtotime($date)) : null;
}

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
        SELECT DISTINCT
            ht.history_transfer_id,
            ht.transfer_id,
            ht.transfer_type,
            ht.equipment_id,
            ht.from_department_id,
            ht.to_department_id,
            ht.transfer_date,
            ht.returned_date,
            ht.reason,
            ht.transfer_user_id,
            ht.recipient_user_id,
            ht.updated_at,
            ht.now_equip_location_department_id,
            ht.now_equip_location_details,
            ht.old_subcategory_id,
            ht.new_subcategory_id,
            ht.now_subcategory_id,
            ht.status_transfer,
            
            -- ข้อมูลเครื่องมือ
            e.name AS equipment_name,
            e.asset_code,
            
            -- ข้อมูลผู้ใช้
            u_transfer.full_name AS transfer_user_name,
            u_recipient.full_name AS recipient_user_name,
            
            -- ข้อมูลแผนกต่างๆ
            d_from.department_name AS from_department_name,
            d_to.department_name AS to_department_name,
            d_now_location.department_name AS now_equip_location_department_name,
            
            -- ข้อมูล subcategory สำหรับโอนย้ายชั่วคราว (old_subcategory_id)
            CASE WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' THEN sc_old.name ELSE NULL END AS old_subcategory_name,
            
            -- ข้อมูล subcategory สำหรับโอนย้ายถาวร (now_subcategory_id)
            CASE WHEN ht.transfer_type = 'โอนย้ายถาวร' THEN sc_now.name ELSE NULL END AS now_subcategory_name,
            
            -- ข้อมูลผู้ดูแลหลักสำหรับ old_subcategory_id (เฉพาะโอนย้ายชั่วคราว)
            CASE WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' THEN temp_old_admin.admin_id ELSE NULL END AS old_admin_id,
            CASE WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' THEN temp_old_admin.admin_user_id ELSE NULL END AS old_admin_user_id,
            CASE WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' THEN temp_old_admin.admin_name ELSE NULL END AS old_admin_name,
            CASE WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' THEN temp_old_admin.admin_department ELSE NULL END AS old_admin_department,
            
            -- ข้อมูลผู้ดูแลหลักสำหรับ now_subcategory_id (เฉพาะโอนย้ายถาวร)
            CASE WHEN ht.transfer_type = 'โอนย้ายถาวร' THEN perm_now_admin.admin_id ELSE NULL END AS now_admin_id,
            CASE WHEN ht.transfer_type = 'โอนย้ายถาวร' THEN perm_now_admin.admin_user_id ELSE NULL END AS now_admin_user_id,
            CASE WHEN ht.transfer_type = 'โอนย้ายถาวร' THEN perm_now_admin.admin_name ELSE NULL END AS now_admin_name,
            CASE WHEN ht.transfer_type = 'โอนย้ายถาวร' THEN perm_now_admin.admin_department ELSE NULL END AS now_admin_department
            
        FROM history_transfer ht
        
        -- JOIN ข้อมูลเครื่องมือ
        LEFT JOIN equipments e ON ht.equipment_id = e.equipment_id
        
        -- JOIN ข้อมูลผู้ใช้
        LEFT JOIN users u_transfer ON ht.transfer_user_id = u_transfer.ID
        LEFT JOIN users u_recipient ON ht.recipient_user_id = u_recipient.ID
        
        -- JOIN ข้อมูลแผนกต่างๆ
        LEFT JOIN departments d_from ON ht.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON ht.to_department_id = d_to.department_id
        LEFT JOIN departments d_now_location ON ht.now_equip_location_department_id = d_now_location.department_id
        
        -- JOIN ข้อมูล subcategory สำหรับโอนย้ายชั่วคราว (old_subcategory_id)
        LEFT JOIN equipment_subcategories sc_old ON ht.old_subcategory_id = sc_old.subcategory_id 
                                                    AND ht.transfer_type = 'โอนย้ายชั่วคราว'
        
        -- JOIN ข้อมูล subcategory สำหรับโอนย้ายถาวร (now_subcategory_id)
        LEFT JOIN equipment_subcategories sc_now ON ht.now_subcategory_id = sc_now.subcategory_id 
                                                    AND ht.transfer_type = 'โอนย้ายถาวร'
        
        -- JOIN สำหรับผู้ดูแลหลักของ old_subcategory_id (เฉพาะโอนย้ายชั่วคราว)
        LEFT JOIN (
            SELECT DISTINCT
                rg.subcategory_id,
                u.ID AS admin_id,
                u.user_id AS admin_user_id,
                u.full_name AS admin_name,
                d.department_name AS admin_department,
                ROW_NUMBER() OVER (PARTITION BY rg.subcategory_id ORDER BY u.ID) as rn
            FROM relation_group rg
            JOIN group_user gu ON rg.group_user_id = gu.group_user_id 
            JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
            JOIN users u ON ru.u_id = u.ID
            LEFT JOIN departments d ON u.department_id = d.department_id
            WHERE gu.type = 'ผู้ดูแลหลัก'
        ) temp_old_admin ON ht.old_subcategory_id = temp_old_admin.subcategory_id 
                            AND temp_old_admin.rn = 1 
                            AND ht.transfer_type = 'โอนย้ายชั่วคราว'
        
        -- JOIN สำหรับผู้ดูแลหลักของ now_subcategory_id (เฉพาะโอนย้ายถาวร)
        LEFT JOIN (
            SELECT DISTINCT
                rg.subcategory_id,
                u.ID AS admin_id,
                u.user_id AS admin_user_id,
                u.full_name AS admin_name,
                d.department_name AS admin_department,
                ROW_NUMBER() OVER (PARTITION BY rg.subcategory_id ORDER BY u.ID) as rn
            FROM relation_group rg
            JOIN group_user gu ON rg.group_user_id = gu.group_user_id 
            JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
            JOIN users u ON ru.u_id = u.ID
            LEFT JOIN departments d ON u.department_id = d.department_id
            WHERE gu.type = 'ผู้ดูแลหลัก'
        ) perm_now_admin ON ht.now_subcategory_id = perm_now_admin.subcategory_id 
                           AND perm_now_admin.rn = 1 
                           AND ht.transfer_type = 'โอนย้ายถาวร'
        
        WHERE ht.transfer_user_id = :u_id OR ht.recipient_user_id = :u_id
        ORDER BY ht.history_transfer_id DESC
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
        
        if ($t['transfer_type'] === 'โอนย้ายชั่วคราว') {
            // โครงสร้างสำหรับโอนย้ายชั่วคราว
            $administrators = null;
            if ($t['old_admin_id']) {
                $administrators = [
                    "old_admin" => [
                        "admin_id" => (int)$t['old_admin_id'],
                        "admin_user_id" => $t['old_admin_user_id'],
                        "admin_name" => $t['old_admin_name'],
                        "admin_department" => $t['old_admin_department']
                    ]
                ];
            }
            
            $item = [
                "history_transfer_id" => (int)$t['history_transfer_id'],
                "transfer_id" => $t['transfer_id'],
                "transfer_type" => $t['transfer_type'],
                "equipment_id" => (int)$t['equipment_id'],
                "name" => $t['equipment_name'],
                "asset_code" => $t['asset_code'],
                "from_department_id" => $t['from_department_id'] ? (int)$t['from_department_id'] : null,
                "to_department_id" => $t['to_department_id'] ? (int)$t['to_department_id'] : null,
                "transfer_date" => formatDate($t['transfer_date']),
                "returned_date" => formatDate($t['returned_date']),
                "reason" => $t['reason'],
                "transfer_user_id" => (int)$t['transfer_user_id'],
                "recipient_user_id" => (int)$t['recipient_user_id'],
                "updated_at" => formatDate($t['updated_at']),
                "now_equip_location_department_id" => $t['now_equip_location_department_id'] ? (int)$t['now_equip_location_department_id'] : null,
                "now_equip_location_department_name" => $t['now_equip_location_department_name'],
                "now_equip_location_details" => $t['now_equip_location_details'],
                "old_subcategory_id" => $t['old_subcategory_id'] ? (int)$t['old_subcategory_id'] : null,
                "old_subcategory_name" => $t['old_subcategory_name'],
                "status_transfer" => $t['status_transfer'],
                "transfer_user_name" => $t['transfer_user_name'],
                "recipient_user_name" => $t['recipient_user_name'],
                "from_department" => $t['from_department_name'],
                "to_department" => $t['to_department_name'],
                "status" => $t['status_transfer'],
                "user_role" => $is_sender ? "ผู้โอน" : "ผู้รับ",
                "install_location" => $t['now_equip_location_details'] ?: "-",
                "administrators จากold_subcategory_id" => $administrators
            ];
            
            $temporary[] = $item;
            if ($is_sender) $summary["โอนย้ายชั่วคราว_ผู้โอน"]++;
            else $summary["โอนย้ายชั่วคราว_ผู้รับ"]++;
            
        } else {
            // โครงสร้างสำหรับโอนย้ายถาวร
            $administrators = null;
            if ($t['now_admin_id']) {
                $administrators = [
                    "new_admin" => [
                        "admin_id" => (int)$t['now_admin_id'],
                        "admin_user_id" => $t['now_admin_user_id'],
                        "admin_name" => $t['now_admin_name'],
                        "admin_department" => $t['now_admin_department']
                    ]
                ];
            }
            
            $item = [
                "history_transfer_id" => (int)$t['history_transfer_id'],
                "transfer_id" => $t['transfer_id'],
                "transfer_type" => $t['transfer_type'],
                "equipment_id" => (int)$t['equipment_id'],
                "name" => $t['equipment_name'],
                "asset_code" => $t['asset_code'],
                "from_department_id" => $t['from_department_id'] ? (int)$t['from_department_id'] : null,
                "to_department_id" => $t['to_department_id'] ? (int)$t['to_department_id'] : null,
                "transfer_date" => formatDate($t['transfer_date']),
                "returned_date" => formatDate($t['returned_date']),
                "reason" => $t['reason'],
                "transfer_user_id" => (int)$t['transfer_user_id'],
                "recipient_user_id" => (int)$t['recipient_user_id'],
                "updated_at" => formatDate($t['updated_at']),
                "now_equip_location_department_id" => $t['now_equip_location_department_id'] ? (int)$t['now_equip_location_department_id'] : null,
                "now_equip_location_department_name" => $t['now_equip_location_department_name'],
                "now_equip_location_details" => $t['now_equip_location_details'],
                "now_subcategory_id" => $t['now_subcategory_id'] ? (int)$t['now_subcategory_id'] : null,
                "now_subcategory_name" => $t['now_subcategory_name'],
                "status_transfer" => $t['status_transfer'],
                "transfer_user_name" => $t['transfer_user_name'],
                "recipient_user_name" => $t['recipient_user_name'],
                "from_department" => $t['from_department_name'],
                "to_department" => $t['to_department_name'],
                "status" => $t['status_transfer'],
                "user_role" => $is_sender ? "ผู้โอน" : "ผู้รับ",
                "install_location" => $t['now_equip_location_details'] ?: "-",
                "administrators จากnow_subcategory_id" => $administrators
            ];
            
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
