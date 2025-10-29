<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        throw new Exception("User ID not found in token");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $dbh->prepare("
            SELECT 
                u.ID,
                u.user_id,
                u.full_name,
                u.department_id,
                d.department_name,
                u.role_id,
                r.role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN departments d ON u.department_id = d.department_id
            WHERE u.ID = :u_id
            ORDER BY u.ID ASC
        ");
        $stmt->bindParam(":u_id", $u_id, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
