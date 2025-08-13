<?php
include "../config/jwt.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

try {
    $query_user = "SELECT role_id FROM users WHERE employee_code = :employee_code";
    $stmt_user = $dbh->prepare($query_user);
    $stmt_user->bindParam(":employee_code", $employee_code);
    $stmt_user->execute();

    $r_id = null;
    if ($row = $stmt_user->fetch(PDO::FETCH_ASSOC)) {
        $r_id = $row['role_id'];
    }

    if ($r_id !== 6 && $r_id !== 9) {
        echo json_encode([
            "status" => "error", 
            "message" => "NSO และ Admin เท่านั้นที่แก้ไขได้"
        ]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);
    $newFTE = isset($input['FTE_1']) ? floatval($input['FTE_1']) : null;

    if (!$newFTE) {
        echo json_encode(["status" => "error", "message" => "Missing FTE_1"]);
        exit;
    }

    $update = $dbh->prepare("
        UPDATE nursing_hours 
        SET FTE_1 = ?, active = 1
    ");
    $update->execute([$newFTE]);

    echo json_encode([
        "status" => "success", 
        "message" => "FTE_1 updated for all wards",
        "updated_by_role" => $r_id
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>