<?php
include "../config/jwt.php"; 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
try {
    if ($method !== 'GET') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }

    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        throw new Exception("User ID not found");
    }

    $equipment_id = isset($_GET['equipment_id']) ? (int)$_GET['equipment_id'] : null;

    $sql = "
        SELECT DISTINCT
          e.equipment_id,
          e.asset_code,
          e.name AS equipment_name,
          e.subcategory_id,
          es.name AS subcategory_name,
          es.category_id,
          e.location_department_id,
          d_location.department_name AS location_department_name,
          e.location_details,
          et.transfer_id,
          et.transfer_type,
          et.from_department_id,
          d_from.department_name AS from_department,
          et.to_department_id,
          d_to.department_name AS to_department,
          et.transfer_date,
          et.returned_date,
          et.reason,
          et.status,
          et.transfer_user_id,
          u_transfer.ID AS transfer_user_ID,
          u_transfer.user_id AS transfer_user_user_id,
          u_transfer.full_name AS transfer_user_name,
          d_transfer.department_name AS transfer_user_department,
          et.recipient_user_id,
          u_recipient.ID AS recipient_user_ID,
          u_recipient.user_id AS recipient_user_user_id,
          u_recipient.full_name AS recipient_user_name,
          d_recipient.department_name AS recipient_user_department

        FROM equipments e
        LEFT JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        LEFT JOIN equipment_transfers et ON et.equipment_id = e.equipment_id 
          AND et.recipient_user_id = :u_id 
          AND et.transfer_type = 'โอนย้ายชั่วคราว' 
          AND et.status = 0
        
        LEFT JOIN users u_transfer ON et.transfer_user_id = u_transfer.ID
        LEFT JOIN departments d_transfer ON u_transfer.department_id = d_transfer.department_id
        
        LEFT JOIN users u_recipient ON et.recipient_user_id = u_recipient.ID
        LEFT JOIN departments d_recipient ON u_recipient.department_id = d_recipient.department_id
        
        LEFT JOIN departments d_from ON et.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON et.to_department_id = d_to.department_id
        
        LEFT JOIN departments d_location ON e.location_department_id = d_location.department_id
        WHERE et.transfer_id IS NOT NULL
    ";

    if ($equipment_id) {
        $sql .= " AND e.equipment_id = :equipment_id ";
    }

    $sql .= " ORDER BY e.equipment_id DESC";

    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(":u_id", $u_id, PDO::PARAM_INT);
    if ($equipment_id) {
        $stmt->bindParam(":equipment_id", $equipment_id, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode(["status" => "error", "message" => "ไม่พบอุปกรณ์หรือคุณไม่มีสิทธิ์"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ดึงข้อมูลผู้ดูแลหลักสำหรับแต่ละ subcategory
    $result = [];
    $adminGroupsCache = [];

    foreach ($rows as $row) {
        $subcategory_id = $row['subcategory_id'];

        if (!isset($adminGroupsCache[$subcategory_id])) {
            $adminSql = "
                SELECT 
                    gu.group_user_id,
                    gu.group_name,
                    gu.type AS group_type,
                    u_admin.ID,
                    u_admin.user_id,
                    u_admin.full_name,
                    d_admin.department_name
                FROM relation_group rg
                LEFT JOIN group_user gu ON rg.group_user_id = gu.group_user_id
                LEFT JOIN relation_user ru_admin ON gu.group_user_id = ru_admin.group_user_id
                LEFT JOIN users u_admin ON ru_admin.u_id = u_admin.ID
                LEFT JOIN departments d_admin ON u_admin.department_id = d_admin.department_id
                WHERE rg.subcategory_id = :subcategory_id 
                AND gu.type = 'ผู้ดูแลหลัก'
            ";
            
            $adminStmt = $dbh->prepare($adminSql);
            $adminStmt->bindParam(":subcategory_id", $subcategory_id, PDO::PARAM_INT);
            $adminStmt->execute();
            $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

            $adminGroups = [];
            foreach ($admins as $admin) {
                $groupId = $admin['group_user_id'];
                if (!isset($adminGroups[$groupId])) {
                    $adminGroups[$groupId] = [
                        'group_id' => (int)$groupId,
                        'group_name' => $admin['group_name'],
                        'group_type' => $admin['group_type'],
                        'user_group' => []
                    ];
                }
                
                if ($admin['full_name']) {
                    $adminGroups[$groupId]['user_group'][] = [
                        'ID' => (int)$admin['ID'],
                        'user_id' => $admin['user_id'],
                        'full_name' => $admin['full_name'],
                        'department_name' => $admin['department_name']
                    ];
                }
            }
            $adminGroupsCache[$subcategory_id] = array_values($adminGroups);
        }

        // แปลง status_text
        $status_text = null;
        if ($row['transfer_type'] === "โอนย้ายชั่วคราว" && $row['status'] == 0) {
            $status_text = "ยังไม่คืน";
        } elseif ($row['transfer_type'] === "โอนย้ายชั่วคราว" && $row['status'] != 0) {
            $status_text = "คืนแล้ว";
        } else {
            $status_text = "สถานะอื่น ๆ";
        }

        $equipmentData = [
            'equipment_id' => (int)$row['equipment_id'],
            'asset_code' => $row['asset_code'],
            'equipment_name' => $row['equipment_name'],
            'transfer_type' => $row['transfer_type'],
            'status' => $row['status'],
            'status_text' => $status_text,
            'subcategory_id' => (int)$row['subcategory_id'],
            'subcategory_name' => $row['subcategory_name'],
            'location_department_id' => (int)$row['location_department_id'],
            'location_department_name' => $row['location_department_name'],
            'location_details' => $row['location_details'],
            'category_id' => (int)$row['category_id'],
            'from_department' => $row['from_department'],
            'to_department' => $row['to_department'],
            'transfer_date' => $row['transfer_date'],
            'returned_date' => $row['returned_date'],
            'reason' => $row['reason'],
            'admins' => $adminGroupsCache[$subcategory_id],
            'transfer_user_id' => [
                [
                    'ID' => (int)$row['transfer_user_ID'],
                    'user_id' => $row['transfer_user_user_id'],
                    'full_name' => $row['transfer_user_name'],
                    'department_name' => $row['transfer_user_department']
                ]
            ],
            'recipient_user' => [
                [
                    'ID' => (int)$row['recipient_user_ID'],
                    'user_id' => $row['recipient_user_user_id'],
                    'full_name' => $row['recipient_user_name'],
                    'department_name' => $row['recipient_user_department']
                ]
            ]
        ];
        $result[] = $equipmentData;
    }

    echo json_encode([
        "status" => "ok",
        "data" => $result
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
