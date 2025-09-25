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

    $input = json_decode(file_get_contents('php://input'), true);
    $search = trim($input['search'] ?? '');
    $filterType = trim($input['filter'] ?? '');
    $page = (int) ($input['page'] ?? 1);
    $limit = (int) ($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    $searchCondition = '';
    $filterCondition = '';
    $params = [':u_id' => $u_id];

    if (!empty($search)) {
        $searchCondition = "AND (
            e.name LIKE :search OR 
            e.asset_code LIKE :search OR 
            d_from.department_name LIKE :search OR
            d_to.department_name LIKE :search OR
            d_now_location.department_name LIKE :search OR
            ht.status_transfer LIKE :search
        )";
        $params[':search'] = "%$search%";
    }

    if (!empty($filterType)) {
        if ($filterType === 'borrow')
            $filterCondition = "AND ht.transfer_type = 'โอนย้ายชั่วคราว'";
        if ($filterType === 'transfer')
            $filterCondition = "AND ht.transfer_type = 'โอนย้ายถาวร'";
    }

    $baseWhere = "WHERE (ht.transfer_user_id = :u_id OR ht.recipient_user_id = :u_id)
        AND (
            (ht.transfer_type = 'โอนย้ายถาวร' AND ht.status_transfer = 1) OR
            (ht.transfer_type = 'โอนย้ายชั่วคราว')
        )
        {$searchCondition}
        {$filterCondition}";

    // นับจำนวนรายการ
    $countSql = "
        SELECT COUNT(DISTINCT ht.history_transfer_id) as total
        FROM history_transfer ht
        LEFT JOIN equipments e ON ht.equipment_id = e.equipment_id
        LEFT JOIN departments d_from ON ht.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON ht.to_department_id = d_to.department_id
        LEFT JOIN departments d_now_location ON ht.now_equip_location_department_id = d_now_location.department_id
        {$baseWhere}
    ";
    $countStmt = $dbh->prepare($countSql);
    foreach ($params as $k => $v)
        $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $totalItems = (int) $countStmt->fetchColumn();
    $totalPages = $useLimit ? ceil($totalItems / $limit) : 1;

    // ดึงข้อมูลพร้อมคำนวณ status_display
    $sql = "
        SELECT DISTINCT
            ht.history_transfer_id,
            ht.transfer_id,
            ht.transfer_type,
            ht.equipment_id,
            ht.from_department_id,
            ht.to_department_id,
            ht.transfer_date,
            ht.updated_at,
            ht.now_equip_location_department_id,
            ht.now_equip_location_details,
            ht.status_transfer,
            e.name AS equipment_name,
            e.asset_code,
            d_from.department_name AS from_department_name,
            d_to.department_name AS to_department_name,
            d_now_location.department_name AS now_equip_location_department_name,
            CASE
                WHEN ht.transfer_type = 'โอนย้ายถาวร' THEN 'ไม่ต้องคืน'
                WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' AND (ht.status_transfer = 0 OR ht.status_transfer IS NULL) THEN 'ยังไม่คืน'
                WHEN ht.transfer_type = 'โอนย้ายชั่วคราว' AND ht.status_transfer = 1 THEN 'คืนแล้ว'
                ELSE '-'
            END AS status_display
        FROM history_transfer ht
        LEFT JOIN equipments e ON ht.equipment_id = e.equipment_id
        LEFT JOIN departments d_from ON ht.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON ht.to_department_id = d_to.department_id
        LEFT JOIN departments d_now_location ON ht.now_equip_location_department_id = d_now_location.department_id
        {$baseWhere}
        ORDER BY ht.history_transfer_id DESC
    ";

    if ($useLimit) {
        $sql .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v)
        $stmt->bindValue($k, $v);

    if ($useLimit) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        "status" => "success",
        "data" => $rows
    ];

    if ($useLimit) {
        $response["pagination"] = [
            "totalItems" => $totalItems,
            "totalPages" => $totalPages,
            "currentPage" => $page,
            "limit" => $limit
        ];
    } else {
        $response["total"] = count($rows);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
