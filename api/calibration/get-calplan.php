<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? json_decode(file_get_contents("php://input"), true) ?? [] : $_GET;

    $user_id = trim($input['user_id'] ?? '');

    $isAdmin = false;
    $groupUserIds = [];
    $groupType = '';

    if ($user_id !== '') {


        $stmtUserInfo = $dbh->prepare("
            SELECT u.role_id, gu.type
            FROM users u
            LEFT JOIN relation_user ru ON ru.u_id = u.ID
            LEFT JOIN group_user gu ON gu.group_user_id = ru.group_user_id
            WHERE u.user_id = :user_id
            LIMIT 1
        ");
        $stmtUserInfo->bindValue(':user_id', $user_id);
        $stmtUserInfo->execute();
        $userInfo = $stmtUserInfo->fetch(PDO::FETCH_ASSOC);

        $roleId = $userInfo['role_id'] ?? null;
        $groupType = $userInfo['type'] ?? null;

        if ($roleId == 6) {
            $isAdmin = true;
        } else {
            $stmtGroup = $dbh->prepare("
                SELECT ru.group_user_id
                FROM relation_user ru
                INNER JOIN users u ON ru.u_id = u.ID
                WHERE u.user_id = :user_id
            ");
            $stmtGroup->bindValue(':user_id', $user_id);
            $stmtGroup->execute();
            $groupUserIds = $stmtGroup->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $search = trim($input['search'] ?? '');
    $page = (int)($input['page'] ?? 1);
    $limit = (int)($input['limit'] ?? 10);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    $where = ["cp.is_active IN (1)"];
    $params = [];

    if ($search !== '') {
        $where[] = "(cp.plan_name LIKE :search OR u.full_name LIKE :search OR c.name LIKE :search OR gu.group_name LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($isAdmin) {
        $where = ["cp.is_active IN (1)"];
        $params = [];
        if ($search !== '') {
            $where[] = "(cp.plan_name LIKE :search OR u.full_name LIKE :search OR c.name LIKE :search OR gu.group_name LIKE :search)";
            $params[':search'] = "%$search%";
        }
    } else {
        if ($groupType === 'ผู้ดูแลหลัก') {
            $where[] = "(
        cp.user_id = :user_id_main
        OR EXISTS (
            SELECT 1
            FROM plan_equipments pe
            INNER JOIN equipments e ON pe.equipment_id = e.equipment_id
            INNER JOIN relation_group rg ON e.subcategory_id = rg.subcategory_id
            INNER JOIN group_user gu2 ON gu2.group_user_id = rg.group_user_id
            INNER JOIN relation_user ru ON ru.group_user_id = rg.group_user_id
            INNER JOIN users u2 ON u2.ID = ru.u_id
            WHERE pe.plan_id = cp.plan_id
            AND gu2.type = 'ผู้ดูแลหลัก'
            AND u2.user_id = :user_id_main
        )
    )";
            $params[':user_id_main'] = $user_id;
        } else {
            // 🟦 ผู้ใช้ทั่วไป เห็นเฉพาะ group_user_id ของตัวเอง
            if (!empty($groupUserIds)) {
                $placeholders = [];
                foreach ($groupUserIds as $index => $groupId) {
                    $key = ":group_id_$index";
                    $placeholders[] = $key;
                    $params[$key] = $groupId;
                }
                $where[] = "cp.group_user_id IN (" . implode(',', $placeholders) . ")";
            } else {
                $where[] = "1 = 0"; 
            }
        }
    }

    $whereSQL = "WHERE " . implode(" AND ", $where);


    $query = "
        SELECT cp.*, u.full_name AS user_name, gu.group_name, c.name AS company_name,
               COUNT(DISTINCT dcp.details_cal_id) AS total_schedules
        FROM calibration_plans cp
        LEFT JOIN users u ON cp.user_id = u.user_id
        LEFT JOIN group_user gu ON cp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON cp.company_id = c.company_id
        LEFT JOIN details_calibration_plans dcp ON cp.plan_id = dcp.plan_id
        $whereSQL
        GROUP BY cp.plan_id
        ORDER BY cp.plan_id DESC
    ";

    $countQuery = "
        SELECT COUNT(DISTINCT cp.plan_id)
        FROM calibration_plans cp
        LEFT JOIN users u ON cp.user_id = u.user_id
        LEFT JOIN group_user gu ON cp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON cp.company_id = c.company_id
        $whereSQL
    ";

    // ✅ นับจำนวนทั้งหมด
    $countStmt = $dbh->prepare($countQuery);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    if ($useLimit) {
        $query .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
    }

    // ✅ ดึงข้อมูลแผน
    $stmt = $dbh->prepare($query);
    foreach ($params as $k => $v) {
        if ($k === ':limit' || $k === ':offset') {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $planIds = array_column($results, 'plan_id');

    // ✅ ดึงอุปกรณ์ในแผน
    $equipmentsMap = [];
    if (!empty($planIds)) {
        $equipPlaceholders = [];
        $equipParams = [];
        foreach ($planIds as $index => $planId) {
            $key = ":plan_id_equip_$index";
            $equipPlaceholders[] = $key;
            $equipParams[$key] = $planId;
        }

        $equipStmt = $dbh->prepare("
            SELECT pe.plan_id, e.equipment_id, e.name, e.asset_code
            FROM plan_equipments pe
            LEFT JOIN equipments e ON pe.equipment_id = e.equipment_id
            WHERE pe.plan_id IN (" . implode(',', $equipPlaceholders) . ")
        ");
        foreach ($equipParams as $k => $v) {
            $equipStmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $equipStmt->execute();
        $equipmentsRaw = $equipStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($equipmentsRaw as $eq) {
            $equipmentsMap[$eq['plan_id']][] = [
                "equipment_id" => (int)$eq['equipment_id'],
                "name" => $eq['name'] ?? "-",
                "asset_code" => $eq['asset_code'] ?? "-"
            ];
        }
    }

    // ✅ ดึงไฟล์แนบของแต่ละแผน
    $filesMap = [];
    if (!empty($planIds)) {
        $filePlaceholders = [];
        $fileParams = [];
        foreach ($planIds as $index => $planId) {
            $key = ":plan_id_file_$index";
            $filePlaceholders[] = $key;
            $fileParams[$key] = $planId;
        }

        $fileStmt = $dbh->prepare("
            SELECT plan_id, file_cal_id, file_cal_name, file_cal_url, cal_type_name
            FROM file_cal
            WHERE plan_id IN (" . implode(',', $filePlaceholders) . ")
        ");
        foreach ($fileParams as $k => $v) {
            $fileStmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $fileStmt->execute();
        $filesRaw = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filesRaw as $file) {
            $filesMap[$file['plan_id']][] = [
                "file_cal_id" => (int)$file['file_cal_id'],
                "file_cal_name" => $file['file_cal_name'],
                "file_cal_url" => $file['file_cal_url'],
                "cal_type_name" => $file['cal_type_name']
            ];
        }
    }

    // ✅ รวมข้อมูลทั้งหมด
    foreach ($results as &$res) {
        $res['equipments'] = $equipmentsMap[$res['plan_id']] ?? [];
        $res['files'] = $filesMap[$res['plan_id']] ?? [];
        $res['frequency_number'] = (int)$res['frequency_number'];
        $res['interval_count'] = (int)$res['interval_count'];
        $res['is_active'] = (int)$res['is_active'];
        $res['price'] = isset($res['price']) ? (float)$res['price'] : 0;
        $res['frequency_display'] = "ทุก {$res['frequency_number']} " .
            match ((int)$res['frequency_unit']) {
                1 => 'วัน',
                2 => 'สัปดาห์',
                3 => 'เดือน',
                4 => 'ปี',
                default => 'หน่วย',
            };
        $res['status_display'] = $res['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน';
    }

    echo json_encode([
        "status" => "success",
        "data" => array_values($results),
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $useLimit ? ceil($totalItems / $limit) : 1,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
