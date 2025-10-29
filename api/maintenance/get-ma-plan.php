<?php
include "../config/jwt.php";
include "../config/pagination_helper.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? json_decode(file_get_contents("php://input"), true) ?? [] : $_GET;

    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) {
        echo json_encode(buildApiResponse('error', null, null, 'Unauthorized'));
        exit;
    }
    $role_id = $decoded->data->role_id ?? null;
    $isAdmin = ($role_id == 6); // 6 = admin

    // --- เงื่อนไข non-admin ---
    $showInactive = isset($input['showInactive']) ? intval($input['showInactive']) : 0;
    $whereClause = "WHERE 1"; // เริ่มจากไม่มีเงื่อนไข
    if ($showInactive === 0) {
        $whereClause .= " AND mp.is_active = 1";
    }
    $additionalParams = [];

    if (!$isAdmin) {
        $stmtGroup = $dbh->prepare("
            SELECT gu.group_user_id, gu.type
            FROM relation_user ru
            INNER JOIN group_user gu ON gu.group_user_id = ru.group_user_id
            WHERE ru.u_id = :user_id
        ");
        $stmtGroup->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmtGroup->execute();
        $groups = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);

        $mainGroups = [];
        $normalGroups = [];
        foreach ($groups as $g) {
            if ($g['type'] === 'ผู้ดูแลหลัก')
                $mainGroups[] = $g['group_user_id'];
            else
                $normalGroups[] = $g['group_user_id'];
        }

        $allGroups = array_merge($mainGroups, $normalGroups);
        if (!empty($allGroups)) {
            $placeholders = [];
            foreach ($allGroups as $i => $gid) {
                $placeholders[] = ":grp_$i";
                $additionalParams[":grp_$i"] = $gid;
            }

            $groupCondition = "(
                mp.user_id = :user_id
                OR mp.group_user_id IN (" . implode(',', $placeholders) . ")
                OR EXISTS (
                    SELECT 1
                    FROM plan_ma_equipments pe
                    INNER JOIN equipments e ON pe.equipment_id = e.equipment_id
                    INNER JOIN relation_group rg ON e.subcategory_id = rg.subcategory_id
                    WHERE rg.group_user_id IN (" . implode(',', $placeholders) . ")
                      AND pe.plan_id = mp.plan_id
                )
            )";

            $additionalParams[':user_id'] = $user_id;
        } else {
            // ถ้าไม่มีกลุ่มเลย ให้เห็นเฉพาะแผนที่ตัวเองสร้าง
            $groupCondition = "mp.user_id = :user_id";
            $additionalParams[':user_id'] = $user_id;
        }

        $whereClause .= " AND " . $groupCondition;
    }

    // --- SQL  ---
    $baseSql = "
        SELECT mp.*, u.full_name AS user_name, gu.group_name, 
        c.name AS company_name, c.phone AS company_phone, c.email AS company_email,  
        u2.full_name AS updated_by_name, 
               COUNT(DISTINCT dmp.details_ma_id) AS total_schedules
        FROM maintenance_plans mp
        LEFT JOIN users u ON mp.user_id = u.ID
        LEFT JOIN users u2 ON mp.updated_by = u2.ID
        LEFT JOIN group_user gu ON mp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON mp.company_id = c.company_id
        LEFT JOIN details_maintenance_plans dmp ON mp.plan_id = dmp.plan_id
    ";

    $countSql = "
        SELECT COUNT(DISTINCT mp.plan_id)
        FROM maintenance_plans mp
        LEFT JOIN users u ON mp.user_id = u.ID
        LEFT JOIN group_user gu ON mp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON mp.company_id = c.company_id
    ";

    $searchFields = ['mp.plan_name', 'u.full_name', 'c.name', 'gu.group_name'];
    $orderBy = "GROUP BY mp.plan_id ORDER BY mp.plan_id DESC";

    // --- เรียก helper ---
    $response = handlePaginatedSearch($dbh, $input, $baseSql, $countSql, $searchFields, $orderBy, $whereClause, $additionalParams);

    // --- ถ้าเจอผลลัพธ์ ดึง equipments + files + frequency_display ---
    $planIds = array_column($response['data'] ?? [], 'plan_id');

    if (!empty($planIds)) {
        $inQuery = implode(',', array_fill(0, count($planIds), '?'));

        // Equipments
        $equipStmt = $dbh->prepare("
            SELECT pe.plan_id, e.equipment_id, e.name, e.asset_code
            FROM plan_ma_equipments pe
            LEFT JOIN equipments e ON pe.equipment_id = e.equipment_id
            WHERE pe.plan_id IN ($inQuery)
        ");
        $equipStmt->execute($planIds);
        $equipmentsMap = [];
        foreach ($equipStmt->fetchAll(PDO::FETCH_ASSOC) as $eq) {
            $equipmentsMap[$eq['plan_id']][] = [
                "equipment_id" => (int) $eq['equipment_id'],
                "name" => $eq['name'] ?? "-",
                "asset_code" => $eq['asset_code'] ?? "-"
            ];
        }

        // Files
        $fileStmt = $dbh->prepare("
            SELECT plan_id, file_ma_id, file_ma_name, file_ma_url, ma_type_name
            FROM file_ma
            WHERE plan_id IN ($inQuery)
        ");
        $fileStmt->execute($planIds);
        $filesMap = [];
        foreach ($fileStmt->fetchAll(PDO::FETCH_ASSOC) as $file) {
            $filesMap[$file['plan_id']][] = [
                "file_ma_id" => (int) $file['file_ma_id'],
                "file_ma_name" => $file['file_ma_name'],
                "file_ma_url" => $file['file_ma_url'],
                "ma_type_name" => $file['ma_type_name']
            ];
        }

        // --- เพิ่มข้อมูล extras ---
        foreach ($response['data'] as &$res) {
            $res['equipments'] = $equipmentsMap[$res['plan_id']] ?? [];
            $res['files'] = $filesMap[$res['plan_id']] ?? [];
            $res['frequency_display'] = "ทุก {$res['frequency_number']} " . match ((int) $res['frequency_unit']) {
                1 => 'วัน',
                2 => 'สัปดาห์',
                3 => 'เดือน',
                4 => 'ปี',
                default => 'หน่วย',
            };
            $res['status_display'] = $res['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน';
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(buildApiResponse('error', null, null, $e->getMessage()));
}
