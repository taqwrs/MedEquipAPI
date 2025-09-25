<?php 
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $u_id = $decoded->data->ID ?? null;
    if (!$u_id)
        throw new Exception("User ID not found");

    $baseWhere = "WHERE (ht.transfer_user_id = :u_id OR ht.recipient_user_id = :u_id)
        AND (
            (ht.transfer_type = 'โอนย้ายถาวร' AND ht.status_transfer = 1) OR
            (ht.transfer_type = 'โอนย้ายชั่วคราว')
        )";

    // ดึงข้อมูลหลัก
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
            e.name AS equipment_name,
            e.asset_code,
            u_transfer.full_name AS transfer_user_name,
            u_recipient.full_name AS recipient_user_name,
            d_from.department_name AS from_department_name,
            d_to.department_name AS to_department_name,
            d_now_location.department_name AS now_equip_location_department_name,
            CASE WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' THEN sc_old.name ELSE NULL END AS old_subcategory_name,
            CASE WHEN ht.transfer_type = 'โอนย้ายถาวร' THEN sc_now.name ELSE NULL END AS now_subcategory_name
        FROM history_transfer ht
        LEFT JOIN equipments e ON ht.equipment_id = e.equipment_id
        LEFT JOIN users u_transfer ON ht.transfer_user_id = u_transfer.ID
        LEFT JOIN users u_recipient ON ht.recipient_user_id = u_recipient.ID
        LEFT JOIN departments d_from ON ht.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON ht.to_department_id = d_to.department_id
        LEFT JOIN departments d_now_location ON ht.now_equip_location_department_id = d_now_location.department_id
        LEFT JOIN equipment_subcategories sc_old ON ht.old_subcategory_id = sc_old.subcategory_id 
                                                    AND ht.transfer_type = 'โอนย้ายชั่วคราว'
        LEFT JOIN equipment_subcategories sc_now ON ht.now_subcategory_id = sc_now.subcategory_id 
                                                    AND ht.transfer_type = 'โอนย้ายถาวร'
        {$baseWhere}
        ORDER BY ht.history_transfer_id DESC
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':u_id', $u_id, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $finalData = [];
    foreach ($rows as $history) {
        $subcategory_id = null;
        if ($history['transfer_type'] === 'โอนย้ายชั่วคราว') {
            $subcategory_id = $history['old_subcategory_id'];
        } elseif ($history['transfer_type'] === 'โอนย้ายถาวร') {
            $subcategory_id = $history['now_subcategory_id'];
        }

        // ดึง admins
        $admins = [];
        if ($subcategory_id) {
            $sqlAdmins = "
                SELECT 
                    gu.group_user_id AS group_id,
                    gu.group_name,
                    gu.type AS group_type,
                    u.ID,
                    u.user_id,
                    u.full_name,
                    d.department_name
                FROM relation_group rg
                JOIN group_user gu ON rg.group_user_id = gu.group_user_id
                JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
                JOIN users u ON ru.u_id = u.ID
                LEFT JOIN departments d ON u.department_id = d.department_id
                WHERE gu.type = 'ผู้ดูแลหลัก'
                  AND rg.subcategory_id = :subcategory_id
                ORDER BY gu.group_user_id, u.ID
            ";
            $stmtAdmin = $dbh->prepare($sqlAdmins);
            $stmtAdmin->bindValue(':subcategory_id', $subcategory_id, PDO::PARAM_INT);
            $stmtAdmin->execute();
            $adminRows = $stmtAdmin->fetchAll(PDO::FETCH_ASSOC);

            foreach ($adminRows as $admin) {
                $found = false;
                foreach ($admins as &$group) {
                    if ($group['group_id'] == $admin['group_id']) {
                        $group['user_group'][] = [
                            "ID" => $admin['ID'],
                            "user_id" => $admin['user_id'],
                            "full_name" => $admin['full_name'],
                            "department_name" => $admin['department_name']
                        ];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $admins[] = [
                        "group_id" => $admin['group_id'],
                        "group_name" => $admin['group_name'],
                        "group_type" => $admin['group_type'],
                        "user_group" => [
                            [
                                "ID" => $admin['ID'],
                                "user_id" => $admin['user_id'],
                                "full_name" => $admin['full_name'],
                                "department_name" => $admin['department_name']
                            ]
                        ]
                    ];
                }
            }
        }

        $transfer_user = $history['transfer_user_id'] ? [
            "ID" => $history['transfer_user_id'],
            "full_name" => $history['transfer_user_name']
        ] : null;

        $recipient_user = $history['recipient_user_id'] ? [
            "ID" => $history['recipient_user_id'],
            "full_name" => $history['recipient_user_name']
        ] : null;

        $status_display = '';
        if ($history['transfer_type'] === 'โอนย้ายถาวร') {
            $status_display = 'ไม่ต้องคืน';
        } elseif ($history['transfer_type'] === 'โอนย้ายชั่วคราว') {
            $status_display = ($history['status_transfer'] == 0) ? 'ยังไม่คืน' : 'คืนแล้ว';
        }

        $finalData[] = [
            "history_transfer_id" => $history['history_transfer_id'],
            "transfer_id" => $history['transfer_id'],
            "transfer_type" => $history['transfer_type'],
            "equipment_id" => $history['equipment_id'],
            "asset_code" => $history['asset_code'],
            "equipment_name" => $history['equipment_name'],
            "from_department_name" => $history['from_department_name'],
            "to_department_name" => $history['to_department_name'],
            "now_equip_location_department_name" => $history['now_equip_location_department_name'],
            "old_subcategory_name" => $history['old_subcategory_name'],
            "now_subcategory_name" => $history['now_subcategory_name'],
            "transfer_user" => $transfer_user,
            "recipient_user" => $recipient_user,
            "admins" => $admins,
            "status_display" => $status_display,
            "updated_at" => $history['updated_at']
        ];
    }

    echo json_encode([
        "status" => "success",
        "data" => $finalData
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
