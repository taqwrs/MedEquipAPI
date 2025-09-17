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
    // อ่าน input JSON จาก React
    $input = json_decode(file_get_contents("php://input"), true);
    $search = $input['search'] ?? '';
    $statusFilter = $input['status'] ?? '';

    $query = "
        SELECT w.*, e.name AS equipment_name, u.full_name AS requester_name, 
               a.full_name AS approver_name, wt.name AS writeoff_type_name
        FROM write_offs w
        LEFT JOIN equipments e ON w.equipment_id = e.equipment_id
        LEFT JOIN users u ON w.user_id = u.user_id
        LEFT JOIN users a ON w.approved_by = a.user_id
        LEFT JOIN writeoff_types wt ON w.writeoff_types_id = wt.writeoff_types_id
        WHERE 1
    ";

    $params = [];

    // Search filter
    if ($search !== '') {
        $query .= " AND (e.name LIKE ? OR w.asset_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Status filter
    $statusMap = [
        'waiting' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
    ];

    if ($statusFilter !== '' && isset($statusMap[$statusFilter])) {
        $query .= " AND w.status = ?";
        $params[] = $statusMap[$statusFilter];
    }

    $query .= " ORDER BY w.writeoff_id DESC";

    $stmt = $dbh->prepare($query);
    $stmt->execute($params);
    $writeoffs = $stmt->fetchAll(PDO::FETCH_ASSOC);


    foreach ($writeoffs as &$wo) {
        $stmtFile = $dbh->prepare("SELECT file_writeoffs_id, File_name, url, type_name FROM file_writeoffs WHERE writeoff_id = ?");
        $stmtFile->execute([$wo['writeoff_id']]);
        $wo['files'] = $stmtFile->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(["status" => "success", "data" => $writeoffs]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
