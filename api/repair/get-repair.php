<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);
    $page = isset($input['page']) ? (int)$input['page'] : 1;
    $limit = isset($input['limit']) ? (int)$input['limit'] : 10;
    $offset = ($page - 1) * $limit;
    $search = trim($input['search'] ?? '');
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;

    if ($user_id <= 0) {
        echo json_encode(['status' => false, 'message' => 'user_id is required']);
        exit;
    }

    $useLimit = $limit > 0;

    // ดึงกลุ่มที่ user สังกัด
    $sqlUserGroups = "
        SELECT ru.group_user_id
        FROM relation_user ru
        INNER JOIN users u ON ru.u_id = u.ID
        WHERE u.user_id = :user_id
    ";
    $stmtUserGroups = $dbh->prepare($sqlUserGroups);
    $stmtUserGroups->execute([':user_id' => $user_id]);
    $userGroups = $stmtUserGroups->fetchAll(PDO::FETCH_COLUMN);

    $where = "(r.user_id = :user_id"; 
    $params = [':user_id' => $user_id];

    if (!empty($userGroups)) {
        $groupPlaceholders = [];
        foreach ($userGroups as $idx => $groupId) {
            $placeholder = ":group_id_$idx";
            $groupPlaceholders[] = $placeholder;
            $params[$placeholder] = $groupId;
        }
        $where .= " OR rt.group_user_id IN (" . implode(',', $groupPlaceholders) . ")";
    }
    $where .= ")";

    if (!empty($search)) {
        $where .= " AND (r.title LIKE :search OR r.remark LIKE :search 
                    OR e.asset_code LIKE :search OR e.name LIKE :search)";
        $params[':search'] = "%$search%";
    }

    $sql = "SELECT 
                r.repair_id,
                r.equipment_id,
                e.name AS equipment_name,
                e.asset_code,
                r.title,
                r.remark,
                r.request_date,
                r.location,
                r.status AS repair_status,
                r.user_id,
                u_reporter.full_name AS reporter,
                r.repair_type_id,
                rt.name_type AS repair_type,
                rt.group_user_id,
                gu.group_name AS responsible_group
            FROM repair r
            LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
            LEFT JOIN users u_reporter ON r.user_id = u_reporter.user_id
            LEFT JOIN repair_type rt ON r.repair_type_id = rt.repair_type_id
            LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
            WHERE $where
            ORDER BY r.request_date DESC";

    if ($useLimit) {
        $sql .= " LIMIT :offset, :limit";
    }

    $stmt = $dbh->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    if ($useLimit) {
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // นับจำนวน repair ต่อ equipment
    $repairCount = [];
    foreach ($repairs as $row) {
        $equipId = $row['equipment_id'];
        if (!isset($repairCount[$equipId])) {
            $repairCount[$equipId] = 0;
        }
        $repairCount[$equipId]++;
    }

    // ดึง repair_results + files + spares
    foreach ($repairs as &$repair) {
        $stmt2 = $dbh->prepare("
            SELECT 
                rr.repair_result_id,
                rr.user_id AS responsible_id,
                u_responsible.full_name AS responsible_name,
                rr.performed_date,
                rr.solution,
                rr.cost,
                rr.status,
                rr.remark
            FROM repair_result rr
            LEFT JOIN users u_responsible ON rr.user_id = u_responsible.user_id
            WHERE rr.repair_id = ?
        ");
        $stmt2->execute([$repair['repair_id']]);
        $repairResults = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($repairResults as $row) {
            // ดึงไฟล์
            $stmtFiles = $dbh->prepare("
                SELECT file_repair_result_id, repair_file_name, repair_file_url, repair_type_name
                FROM file_repair_result
                WHERE repair_result_id = ?
            ");
            $stmtFiles->execute([$row['repair_result_id']]);
            $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

            // ดึงอะไหล่
            $stmtSpares = $dbh->prepare("
                SELECT sp.spare_part_id, sp.name AS spare_name
                FROM spare_parts_used spu
                LEFT JOIN spare_parts sp ON spu.spare_part_id = sp.spare_part_id
                WHERE spu.repair_result_id = ?
            ");
            $stmtSpares->execute([$row['repair_result_id']]);
            $spares = $stmtSpares->fetchAll(PDO::FETCH_ASSOC);

            $spareNames = array_map(fn($sp) => $sp['spare_name'], $spares);

            $results[] = [
                "repair_result_id" => $row["repair_result_id"],
                "responsible_id"   => $row["responsible_id"],
                "responsible_name" => $row["responsible_name"],
                "performed_date"   => $row["performed_date"],
                "solution"         => $row["solution"],
                "cost"             => $row["cost"],
                "status"           => $row["status"],
                "remark"           => $row["remark"],
                "spares"           => $spareNames,  
                "files"            => $files
            ];
        }

        $repair['repair_results'] = $results;
        $repair['repair_count'] = $repairCount[$repair['equipment_id']];
    }

    // นับ total สำหรับ pagination
    $countSql = "SELECT COUNT(*) 
                 FROM repair r
                 LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
                 LEFT JOIN repair_type rt ON r.repair_type_id = rt.repair_type_id
                 WHERE $where";
    $stmtCount = $dbh->prepare($countSql);
    foreach ($params as $key => $val) {
        $stmtCount->bindValue($key, $val);
    }
    $stmtCount->execute();
    $total = $stmtCount->fetchColumn();

    echo json_encode([
        "status" => "success",
        "page"   => $page,
        "limit"  => $limit,
        "total"  => (int)$total,
        "data"   => $repairs,
        "user_groups" => $userGroups  
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}