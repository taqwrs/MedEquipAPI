<?php
include "../config/jwt.php"; // DB + JWT

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);
$group_user_id = isset($_GET['group_user_id']) ? $_GET['group_user_id'] : null;

try {
    if ($method === 'GET') {
        if ($group_user_id) {
            $stmt = $dbh->prepare("
                SELECT group_user_id, group_name, type
                FROM group_user
                WHERE group_user_id = :group_user_id
                ORDER BY group_user_id DESC
            ");
            $stmt->bindParam(":group_user_id", $group_user_id);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode([
                    "status" => "error",
                    "message" => "ไม่พบข้อมูล group_user_id นี้"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode(["status" => "ok", "data" => $row], JSON_UNESCAPED_UNICODE);
        } else {
            $stmt = $dbh->prepare("
                SELECT group_user_id, group_name, type
                FROM group_user
                ORDER BY group_user_id DESC
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);
        }

    } elseif ($method === 'POST') {
        if (empty($input['group_name']) || empty($input['type'])) {
            echo json_encode([
                "status" => "error",
                "message" => "กรุณากรอก group_name และ type"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $dbh->prepare("
            INSERT INTO group_user (group_name, type) 
            VALUES (:group_name, :type)
        ");
        $stmt->bindParam(":group_name", $input['group_name']);
        $stmt->bindParam(":type", $input['type']);
        $stmt->execute();
        $new_id = $dbh->lastInsertId();

        echo json_encode([
            "status" => "ok",
            "message" => "เพิ่มกลุ่มผู้ใช้งานเรียบร้อย",
            "group_user_id" => $new_id
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'PUT') {
        if (empty($input['group_user_id']) || empty($input['group_name']) || empty($input['type'])) {
            echo json_encode([
                "status" => "error",
                "message" => "กรุณากรอก group_user_id, group_name และ type"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $dbh->prepare("
            UPDATE group_user 
            SET group_name = :group_name, type = :type
            WHERE group_user_id = :group_user_id
        ");
        $stmt->bindParam(":group_name", $input['group_name']);
        $stmt->bindParam(":type", $input['type']);
        $stmt->bindParam(":group_user_id", $input['group_user_id']);
        $stmt->execute();

        echo json_encode([
            "status" => "ok",
            "message" => "แก้ไขกลุ่มผู้ใช้งานเรียบร้อย"
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'DELETE') {
        if (empty($input['group_user_id'])) {
            echo json_encode([
                "status" => "error",
                "message" => "กรุณากรอก group_user_id"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $dbh->prepare("DELETE FROM group_user WHERE group_user_id = :group_user_id");
        $stmt->bindParam(":group_user_id", $input['group_user_id']);
        $stmt->execute();

        echo json_encode([
            "status" => "ok",
            "message" => "ลบกลุ่มผู้ใช้งานเรียบร้อย"
        ], JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Method not allowed"
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
