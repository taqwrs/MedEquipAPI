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
    $user_id = trim($input['user_id'] ?? '');
    $search = trim($input['search'] ?? '');
    $statusFilter = trim($input['status'] ?? '');
    $page = (int)($input['page'] ?? 1);
    $limit = (int)($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    $statusMap = [
        'waiting' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
    ];


    $roleId = 0;
    if ($user_id !== '') {
        $stmtRole = $dbh->prepare("SELECT role_id FROM users WHERE ID = :user_id");
        $stmtRole->bindValue(':user_id', $user_id, PDO::PARAM_STR);
        $stmtRole->execute();
        $roleId = (int)$stmtRole->fetchColumn();
    }

    $isAdminMain = false;
    $groupUserIds = [];
    if ($user_id !== '' && $roleId !== 6) { 
        $stmtGroup = $dbh->prepare("
            SELECT gu.group_user_id, gu.type
            FROM relation_user ru
            INNER JOIN users u ON ru.u_id = u.ID
            INNER JOIN group_user gu ON ru.group_user_id = gu.group_user_id
            WHERE u.ID = :user_id
        ");
        $stmtGroup->bindValue(':user_id', $user_id, PDO::PARAM_STR);
        $stmtGroup->execute();
        $rows = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $groupUserIds[] = $row['group_user_id'];
            if ($row['type'] === 'ผู้ดูแลหลัก') {
                $isAdminMain = true;
            }
        }
    }

    // Build WHERE
    $where = ["1"];
    $params = [];

    if ($search !== '') {
        $where[] = "(e.name LIKE :search OR w.asset_number LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($statusFilter !== '' && isset($statusMap[$statusFilter])) {
        $where[] = "w.status = :statusFilter";
        $params[':statusFilter'] = $statusMap[$statusFilter];
    }

    // ✅ เงื่อนไขกรองรายการ (w.user_id เก็บค่า ID อยู่แล้ว ไม่ต้องแก้)
    if ($roleId !== 6) {
        if ($isAdminMain && !empty($groupUserIds)) {
            $inQuery = [];
            foreach ($groupUserIds as $k => $id) {
                $key = ":group_$k";
                $inQuery[] = $key;
                $params[$key] = $id;
            }
            $where[] = "(
                w.equipment_id IN (
                    SELECT e.equipment_id
                    FROM equipments e
                    INNER JOIN relation_group rg ON e.subcategory_id = rg.subcategory_id
                    WHERE rg.group_user_id IN (" . implode(',', $inQuery) . ")
                )
                OR w.user_id = :user_id
            )";
            $params[':user_id'] = $user_id;
        } else {
            $where[] = "w.user_id = :user_id";
            $params[':user_id'] = $user_id;
        }
    }
    $whereSQL = "WHERE " . implode(" AND ", $where);

    // ✅ แก้ไข: JOIN users ด้วย w.user_id = u.ID และ w.approved_by = a.ID
    $query = "
        SELECT w.*, e.name AS equipment_name, e.subcategory_id,
               u.full_name AS requester_name, 
               a.full_name AS approver_name, wt.name AS writeoff_type_name
        FROM write_offs w
        LEFT JOIN equipments e ON w.equipment_id = e.equipment_id
        LEFT JOIN users u ON w.user_id = u.ID
        LEFT JOIN users a ON w.approved_by = a.ID
        LEFT JOIN writeoff_types wt ON w.writeoff_types_id = wt.writeoff_types_id
        $whereSQL
        ORDER BY 
            CASE WHEN w.status = 'รออนุมัติ' THEN 0 ELSE 1 END,
            w.writeoff_id DESC
    ";

    $countQuery = "
        SELECT COUNT(*) 
        FROM write_offs w
        LEFT JOIN equipments e ON w.equipment_id = e.equipment_id
        $whereSQL
    ";

    $countStmt = $dbh->prepare($countQuery);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    if ($useLimit) $query .= " LIMIT :limit OFFSET :offset";

    $stmt = $dbh->prepare($query);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    if ($useLimit) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $writeoffs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($writeoffs as &$wo) {
        // ตรวจสอบสิทธิ์อนุมัติสำหรับแต่ละรายการ
        $canApprove = false;
        
        // กรณีเป็น role_id = 6 (Super Admin)
        if ($roleId === 6) {
            $canApprove = true;
        } 
        elseif ($isAdminMain && !empty($groupUserIds) && !empty($wo['subcategory_id'])) {

            $checkGroupStmt = $dbh->prepare("
                SELECT COUNT(*) 
                FROM relation_group 
                WHERE subcategory_id = :subcategory_id 
                AND group_user_id IN (" . implode(',', array_map(function($k) { return ":chk_group_$k"; }, array_keys($groupUserIds))) . ")
            ");
            $checkGroupStmt->bindValue(':subcategory_id', $wo['subcategory_id'], PDO::PARAM_INT);
            foreach ($groupUserIds as $k => $id) {
                $checkGroupStmt->bindValue(":chk_group_$k", $id, PDO::PARAM_INT);
            }
            $checkGroupStmt->execute();
            $inGroup = (int)$checkGroupStmt->fetchColumn() > 0;
            $canApprove = $inGroup;
        }
        
        $wo['can_approve'] = $canApprove;

        // ดึงไฟล์แนบ
        $stmtFile = $dbh->prepare("
            SELECT DISTINCT file_writeoffs_id, File_name, url, type_name 
            FROM file_writeoffs 
            WHERE writeoff_id = :writeoff_id
        ");
        $stmtFile->bindValue(':writeoff_id', $wo['writeoff_id'], PDO::PARAM_INT);
        $stmtFile->execute();
        $wo['files'] = $stmtFile->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        "status" => "success",
        "data" => $writeoffs,
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