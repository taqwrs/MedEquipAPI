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
    $search = trim($input['search'] ?? '');
    $statusFilter = trim($input['status'] ?? '');
    $page = (int)($input['page'] ?? 1);
    $limit = (int)($input['limit'] ?? 10);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    $statusMap = [
        'waiting' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
    ];

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

    $whereSQL = "WHERE " . implode(" AND ", $where);

    $query = "
        SELECT w.*, e.name AS equipment_name, u.full_name AS requester_name, 
               a.full_name AS approver_name, wt.name AS writeoff_type_name
        FROM write_offs w
        LEFT JOIN equipments e ON w.equipment_id = e.equipment_id
        LEFT JOIN users u ON w.user_id = u.user_id
        LEFT JOIN users a ON w.approved_by = a.user_id
        LEFT JOIN writeoff_types wt ON w.writeoff_types_id = wt.writeoff_types_id
        $whereSQL
        ORDER BY 
            CASE WHEN w.status = 'รออนุมัติ' THEN 0 ELSE 1 END,
            w.writeoff_id DESC
    ";

    $countQuery = "SELECT COUNT(*) FROM write_offs w
                   LEFT JOIN equipments e ON w.equipment_id = e.equipment_id
                   $whereSQL";
    $countStmt = $dbh->prepare($countQuery);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();


    if ($useLimit) {
        $query .= " LIMIT :limit OFFSET :offset";
    }

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

    // ดึงข้อมูลไฟล์สำหรับแต่ละรายการ
    foreach ($writeoffs as &$wo) {
        $stmtFile = $dbh->prepare("
            SELECT 
                file_writeoffs_id, 
                File_name, 
                url, 
                type_name as file_type
            FROM file_writeoffs 
            WHERE writeoff_id = ?
        ");
        $stmtFile->execute([$wo['writeoff_id']]);
        $files = $stmtFile->fetchAll(PDO::FETCH_ASSOC);
        
        // แปลง URL เป็น absolute path ถ้าจำเป็น
        foreach ($files as &$file) {
            // ถ้า URL ไม่ได้เริ่มด้วย http แสดงว่าเป็น relative path
            if (!empty($file['url']) && !preg_match('/^https?:\/\//', $file['url'])) {
                // เพิ่ม base URL ให้กับ relative path
                // ปรับ path ตามโครงสร้างโฟลเดอร์ของคุณ
                $file['url'] = '/back_equip/api' . $file['url'];
            }
        }
        
        $wo['files'] = $files;
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