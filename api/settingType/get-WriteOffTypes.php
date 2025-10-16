<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

try {
    if ($method === 'GET') {

        $stmt = $dbh->prepare("SELECT writeoff_types_id, name FROM writeoff_types ORDER BY writeoff_types_id DESC");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {

        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }

        $user_id = null;
        if (isset($decoded->data->ID)) {
            $user_id = $decoded->data->ID;
        } else {
            throw new Exception("ไม่พบข้อมูล user_id ใน token");
        }

        $dbh->beginTransaction();

        $stmt = $dbh->prepare("INSERT INTO writeoff_types (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();

        $typeId = $dbh->lastInsertId();

        // Log INSERT
        $logModel = new LogModel($dbh);
        $newData = [
            "writeoff_types_id" => $typeId,
            "name" => $input['name']
        ];
        $logModel->insertLog(
            $user_id,
            'writeoff_types',
            'INSERT',
            null,
            $newData,
            'transaction_logs'
        );

        $dbh->commit();
        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย", "data" => ["writeoff_types_id" => $typeId]], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'PUT') {

        if (empty($input['writeoff_types_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก writeoff_types_id และ name"]);
            exit;
        }

        $user_id = null;
        if (isset($decoded->data->ID)) {
            $user_id = $decoded->data->ID;
        } else {
            throw new Exception("ไม่พบข้อมูล user_id ใน token");
        }

        $dbh->beginTransaction();

        // Get old data before update
        $oldStmt = $dbh->prepare("SELECT * FROM writeoff_types WHERE writeoff_types_id = :id");
        $oldStmt->bindParam(":id", $input['writeoff_types_id']);
        $oldStmt->execute();
        $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("UPDATE writeoff_types SET name = :name WHERE writeoff_types_id = :id");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['writeoff_types_id']);
        $stmt->execute();

        // Log UPDATE
        $logModel = new LogModel($dbh);
        $newData = [
            "writeoff_types_id" => $input['writeoff_types_id'],
            "name" => $input['name']
        ];
        $logModel->insertLog(
            $user_id,
            'writeoff_types',
            'UPDATE',
            $oldData,
            $newData,
            'transaction_logs'
        );

        $dbh->commit();
        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'DELETE') {

        if (empty($input['writeoff_types_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก writeoff_types_id"]);
            exit;
        }

        $user_id = null;
        if (isset($decoded->data->ID)) {
            $user_id = $decoded->data->ID;
        } else {
            throw new Exception("ไม่พบข้อมูล user_id ใน token");
        }

        $dbh->beginTransaction();

        // Get data before delete
        $oldStmt = $dbh->prepare("SELECT * FROM writeoff_types WHERE writeoff_types_id = :id");
        $oldStmt->bindParam(":id", $input['writeoff_types_id']);
        $oldStmt->execute();
        $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("DELETE FROM writeoff_types WHERE writeoff_types_id = :id");
        $stmt->bindParam(":id", $input['writeoff_types_id']);
        $stmt->execute();

        // Log DELETE
        $logModel = new LogModel($dbh);
        $logModel->insertLog(
            $user_id,
            'writeoff_types',
            'DELETE',
            $oldData,
            null,
            'transaction_logs'
        );

        $dbh->commit();
        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"], JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>