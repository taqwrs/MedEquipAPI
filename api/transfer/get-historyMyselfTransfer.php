<?php
include "../config/jwt.php"; 
// ประวัติการโอนย้ายที่เกี่ยวข้องกับ u_id ที่ login อยู่

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }

    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) throw new Exception("User ID not found");

    $searchText  = $_GET['search'] ?? '';
    $filterType  = $_GET['filter'] ?? '';

    $searchCondition = '';
    $filterCondition = '';
    $params = [':u_id' => $u_id];

    // Search
    if (!empty($searchText)) {
        $searchCondition = "AND (
            e.name LIKE :search OR 
            e.asset_code LIKE :search OR 
            d_from.department_name LIKE :search OR
            d_to.department_name LIKE :search OR
            d_now_location.department_name LIKE :search OR
            ht.status_transfer LIKE :search
        )";
        $params[':search'] = '%' . $searchText . '%';
    }

    // Filter
    if (!empty($filterType)) {
        if ($filterType === 'borrow') $filterCondition = "AND ht.transfer_type = 'โอนย้ายชั่วคราว'";
        if ($filterType === 'transfer') $filterCondition = "AND ht.transfer_type = 'โอนย้ายถาวร'";
    }

    // Query รายละเอียด (เอา LIMIT และ OFFSET ออก)
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
        
        WHERE (ht.transfer_user_id = :u_id OR ht.recipient_user_id = :u_id)
        {$searchCondition}
        {$filterCondition}
        ORDER BY ht.history_transfer_id DESC
    ";

    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter status ตาม transfer_type
    $filteredRows = array_filter($rows, function($row) {
        if ($row['transfer_type'] === 'โอนย้ายถาวร') {
            return $row['status_transfer'] == 1; // ไม่ต้องคืน
        } elseif ($row['transfer_type'] === 'โอนย้ายชั่วคราว') {
            return true; // คืนหรือยังไม่คืน อยู่ที่ status 0/1
        }
        return false;
    });

    // เพิ่มข้อมูล main_admin ใน response
    $finalData = array_map(function($row) {
        // กำหนดข้อมูลผู้ดูแลหลักตามประเภทการโอนย้าย
        $mainAdmin = null;
        
        if ($row['transfer_type'] === 'โอนย้ายชั่วคราว') {
            // ใช้ข้อมูล old_admin สำหรับโอนย้ายชั่วคราว
            if ($row['old_admin_name']) {
                $mainAdmin = [
                    'admin_id' => $row['old_admin_id'],
                    'admin_user_id' => $row['old_admin_user_id'],
                    'admin_name' => $row['old_admin_name'],
                    'admin_department' => $row['old_admin_department']
                ];
            }
        } elseif ($row['transfer_type'] === 'โอนย้ายถาวร') {
            // ใช้ข้อมูล now_admin สำหรับโอนย้ายถาวร
            if ($row['now_admin_name']) {
                $mainAdmin = [
                    'admin_id' => $row['now_admin_id'],
                    'admin_user_id' => $row['now_admin_user_id'],
                    'admin_name' => $row['now_admin_name'],
                    'admin_department' => $row['now_admin_department']
                ];
            }
        }
        
        // เพิ่ม main_admin ลงใน row
        $row['main_admin'] = $mainAdmin;
        
        // เพิ่มสถานะที่แสดงผลให้ชัดเจน
        if ($row['transfer_type'] === 'โอนย้ายถาวร') {
            $row['status_display'] = 'ไม่ต้องคืน';
        } elseif ($row['transfer_type'] === 'โอนย้ายชั่วคราว') {
            $row['status_display'] = ($row['status_transfer'] == 0) ? 'ยังไม่คืน' : 'คืนแล้ว';
        }
        
        return $row;
    }, array_values($filteredRows));

    // ไม่ต้อง pagination แล้ว
    echo json_encode([
        "status" => "success",
        "total" => count($finalData),
        "data" => $finalData
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}