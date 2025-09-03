<?php
include "../config/jwt.php"; // DB + JWT

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);
$repair_type_id = isset($_GET['repair_type_id']) ? $_GET['repair_type_id'] : null;

try {
    if ($method === 'GET') {
        if ($repair_type_id) {
            $stmt = $dbh->prepare("
                SELECT rt.repair_type_id, rt.name_type,
                       gu.group_user_id, gu.group_name, gu.type AS group_type
                FROM repair_type rt
                LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
                WHERE rt.repair_type_id = :repair_type_id
                ORDER BY rt.repair_type_id DESC
            ");
            $stmt->bindParam(":repair_type_id", $repair_type_id);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode([
                    "status" => "error",
                    "message" => "ไม่พบข้อมูล repair_type_id นี้"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $data = [
                'repair_type_id' => $row['repair_type_id'],
                'name_type'      => $row['name_type'],
                'group_user'     => [
                    'group_user_id' => $row['group_user_id'],
                    'group_name'    => $row['group_name'],
                    'group_type'    => $row['group_type']
                ]
            ];

            echo json_encode(["status" => "ok", "data" => $data], JSON_UNESCAPED_UNICODE);
        } else {
            $stmt = $dbh->prepare("
                SELECT rt.repair_type_id, rt.name_type,
                       gu.group_user_id, gu.group_name, gu.type AS group_type
                FROM repair_type rt
                LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
                ORDER BY rt.repair_type_id DESC
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $repair_types = [];
            foreach ($results as $row) {
                $repair_types[] = [
                    'repair_type_id' => $row['repair_type_id'],
                    'name_type'      => $row['name_type'],
                    'group_user'     => [
                        'group_user_id' => $row['group_user_id'],
                        'group_name'    => $row['group_name'],
                        'group_type'    => $row['group_type']
                    ]
                ];
            }

            echo json_encode(["status" => "ok", "data" => $repair_types], JSON_UNESCAPED_UNICODE);
        }

    } elseif ($method === 'POST') {
        if (empty($input['name_type']) || empty($input['group_user_id'])) {
            echo json_encode([
                "status" => "error",
                "message" => "กรุณากรอก name_type และ group_user_id"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $dbh->prepare("
            INSERT INTO repair_type (name_type, group_user_id) 
            VALUES (:name_type, :group_user_id)
        ");
        $stmt->bindParam(":name_type", $input['name_type']);
        $stmt->bindParam(":group_user_id", $input['group_user_id']);
        $stmt->execute();
        $new_id = $dbh->lastInsertId();

        echo json_encode([
            "status" => "ok",
            "message" => "เพิ่มประเภทงานซ่อมเรียบร้อย",
            "repair_type_id" => $new_id
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'PUT') {
        if (empty($input['repair_type_id']) || empty($input['name_type']) || empty($input['group_user_id'])) {
            echo json_encode([
                "status" => "error",
                "message" => "กรุณากรอก repair_type_id, name_type และ group_user_id"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $dbh->prepare("
            UPDATE repair_type 
            SET name_type = :name_type, group_user_id = :group_user_id 
            WHERE repair_type_id = :repair_type_id
        ");
        $stmt->bindParam(":name_type", $input['name_type']);
        $stmt->bindParam(":group_user_id", $input['group_user_id']);
        $stmt->bindParam(":repair_type_id", $input['repair_type_id']);
        $stmt->execute();

        echo json_encode([
            "status" => "ok",
            "message" => "แก้ไขประเภทงานซ่อมเรียบร้อย"
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'DELETE') {
        if (empty($input['repair_type_id'])) {
            echo json_encode([
                "status" => "error",
                "message" => "กรุณากรอก repair_type_id"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $dbh->prepare("DELETE FROM repair_type WHERE repair_type_id = :repair_type_id");
        $stmt->bindParam(":repair_type_id", $input['repair_type_id']);
        $stmt->execute();

        echo json_encode([
            "status" => "ok",
            "message" => "ลบประเภทงานซ่อมเรียบร้อย"
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
