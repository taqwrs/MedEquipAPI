<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

// ========== ฟังก์ชันตรวจสอบข้อมูลซ้ำ ==========
if ($method === 'POST' && isset($input['check_duplicate'])) {
    try {
        $field = $input['field'] ?? '';
        $value = $input['value'] ?? '';
        $excludeId = $input['exclude_id'] ?? null;

        if (empty($field) || empty($value)) {
            echo json_encode([
                "status" => "error", 
                "message" => "กรุณาระบุ field และ value"
            ]);
            exit;
        }

        $allowedFields = ['name', 'tax_number'];
        if (!in_array($field, $allowedFields)) {
            echo json_encode([
                "status" => "error", 
                "message" => "Field ไม่ถูกต้อง"
            ]);
            exit;
        }

        $sql = "SELECT company_id, $field FROM companies WHERE $field = :value";
        
        if ($excludeId !== null) {
            $sql .= " AND company_id != :exclude_id";
        }
        
        $sql .= " LIMIT 1";

        $stmt = $dbh->prepare($sql);
        $stmt->bindParam(":value", $value);
        
        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // พบข้อมูลซ้ำ
            $fieldNameThai = [
                'name' => 'ชื่อบริษัทนี้มีอยู่แล้ว',
                'tax_number' => 'เลขผู้เสียภาษีนี้มีอยู่แล้ว'
            ];
            echo json_encode([
                "status" => "duplicate",
                "message" => $fieldNameThai[$field],
                "field" => $field,
                "existing_id" => $result['company_id']
            ]);
        } else {
            // ไม่พบข้อมูลซ้ำ
            echo json_encode([
                "status" => "available",
                "message" => "สามารถใช้งานได้",
                "field" => $field
            ]);
        }
        exit;

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error", 
            "message" => $e->getMessage()
        ]);
        exit;
    }
}

//ดึง ID จากตาราง users โดยใช้ user_id จาก JWT
$stmtUser = $dbh->prepare("SELECT ID FROM users WHERE user_id = :user_id LIMIT 1");
$stmtUser->bindParam(":user_id", $user_id);
$stmtUser->execute();
$userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
$u_id = $userData['ID'] ?? null;

if (!$u_id) {
    echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
    exit;
}

$logModel = new LogModel($dbh);

try {
    if ($method === 'GET') {
        $stmt = $dbh->prepare("
            SELECT 
                company_id,
                name,
                tax_number,
                address,
                phone,
                email,
                line_id,
                details
            FROM companies
            ORDER BY company_id DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // CREATE
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }

        $stmt = $dbh->prepare("
            INSERT INTO companies (name, tax_number, address, phone, email, line_id, details) 
            VALUES (:name, :tax_number, :address, :phone, :email, :line_id, :details)
        ");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":tax_number", $input['tax_number']);
        $stmt->bindParam(":address", $input['address']);
        $stmt->bindParam(":phone", $input['phone']);
        $stmt->bindParam(":email", $input['email']);
        $stmt->bindParam(":line_id", $input['line_id']);
        $stmt->bindParam(":details", $input['details']);
        $stmt->execute();

        $newCompanyId = $dbh->lastInsertId();

        $logData = [
            'company_id' => $newCompanyId,
            'name' => $input['name'],
            'tax_number' => $input['tax_number'] ?? null,
            'address' => $input['address'] ?? null,
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'] ?? null,
            'line_id' => $input['line_id'] ?? null,
            'details' => $input['details'] ?? null
        ];

        $logModel->insertLog($u_id, 'companies', 'INSERT', null, $logData);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['company_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก company_id และ name"]);
            exit;
        }

        // ดึงข้อมูลเดิมก่อน update
        $stmtOld = $dbh->prepare("SELECT * FROM companies WHERE company_id = :id");
        $stmtOld->bindParam(":id", $input['company_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // อัพเดทข้อมูล
        $stmt = $dbh->prepare("
            UPDATE companies 
            SET name = :name, tax_number = :tax_number, address = :address, 
                phone = :phone, email = :email, line_id = :line_id, details = :details
            WHERE company_id = :id
        ");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":tax_number", $input['tax_number']);
        $stmt->bindParam(":address", $input['address']);
        $stmt->bindParam(":phone", $input['phone']);
        $stmt->bindParam(":email", $input['email']);
        $stmt->bindParam(":line_id", $input['line_id']);
        $stmt->bindParam(":details", $input['details']);
        $stmt->bindParam(":id", $input['company_id']);
        $stmt->execute();

        $logData = [
            'company_id' => $input['company_id'],
            'name' => $input['name'],
            'tax_number' => $input['tax_number'] ?? null,
            'address' => $input['address'] ?? null,
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'] ?? null,
            'line_id' => $input['line_id'] ?? null,
            'details' => $input['details'] ?? null
        ];

        $logModel->insertLog($u_id, 'companies', 'UPDATE', $oldData, $logData);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['company_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก company_id"]);
            exit;
        }

        // ตรวจสอบการใช้งานใน equipments (manufacturer, supplier, maintainer)
        $stmtCheckEquip = $dbh->prepare("
            SELECT COUNT(*) as usage_count 
            FROM equipments 
            WHERE manufacturer_company_id = :company_id 
               OR supplier_company_id = :company_id 
               OR maintainer_company_id = :company_id
        ");
        $stmtCheckEquip->bindParam(":company_id", $input['company_id']);
        $stmtCheckEquip->execute();
        $equipUsage = $stmtCheckEquip->fetch(PDO::FETCH_ASSOC);

        // ตรวจสอบการใช้งานใน calibration_plans
        $stmtCheckCal = $dbh->prepare("
            SELECT COUNT(*) as usage_count 
            FROM calibration_plans 
            WHERE company_id = :company_id
        ");
        $stmtCheckCal->bindParam(":company_id", $input['company_id']);
        $stmtCheckCal->execute();
        $calUsage = $stmtCheckCal->fetch(PDO::FETCH_ASSOC);

        // ตรวจสอบการใช้งานใน maintenance_plans
        $stmtCheckMain = $dbh->prepare("
            SELECT COUNT(*) as usage_count 
            FROM maintenance_plans 
            WHERE company_id = :company_id
        ");
        $stmtCheckMain->bindParam(":company_id", $input['company_id']);
        $stmtCheckMain->execute();
        $mainUsage = $stmtCheckMain->fetch(PDO::FETCH_ASSOC);

        // รวมจำนวนการใช้งานทั้งหมด
        $totalUsage = $equipUsage['usage_count'] + $calUsage['usage_count'] + $mainUsage['usage_count'];

        if ($totalUsage > 0) {
            // ถูกใช้งานอยู่ ไม่สามารถลบได้
            echo json_encode([
                "status" => "error",
                "message" => "ไม่สามารถลบข้อมูลบริษัทนี้ได้เนื่องจากถูกใช้งานอยู่ {$totalUsage} รายการ",
                "usage_count" => $totalUsage
            ]);
            exit;
        }

        // ดึงข้อมูลก่อนลบ
        $stmtOld = $dbh->prepare("SELECT * FROM companies WHERE company_id = :id");
        $stmtOld->bindParam(":id", $input['company_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // ลบข้อมูล
        $stmt = $dbh->prepare("DELETE FROM companies WHERE company_id = :id");
        $stmt->bindParam(":id", $input['company_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'companies', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>