<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

try {
    // ✅ อ่านค่า user จาก JWT
    $currentUserId = null;
    if (isset($decoded)) {
        if (isset($decoded->data->ID)) {
            $currentUserId = $decoded->data->ID;
        } elseif (isset($decoded->ID)) {
            $currentUserId = $decoded->ID;
        }
    }

    if (!$currentUserId) {
        throw new Exception("ไม่พบข้อมูลผู้ใช้งาน (JWT ไม่ถูกต้อง)");
    }

    //  ดึงผู้ใช้ทั้งหมดที่ “ไม่ใช่ตัวเอง” และ “active”
    $sql = $sql = "
    SELECT 
        u.ID ,
        u.user_id,
        u.full_name,
        d.department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.ID != :currentUserId
    ORDER BY d.department_name, u.full_name ASC
";

    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(":currentUserId", $currentUserId, PDO::PARAM_INT);
    $stmt->execute();

    $users = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $users[] = [
            "ID" => (int)$row["ID"],
            "user_id" => (int)$row["user_id"],
            "full_name" => $row["full_name"],
            "department_name" => $row["department_name"]
        ];
    }

    echo json_encode([
        "status" => "success",
        "data" => $users
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
