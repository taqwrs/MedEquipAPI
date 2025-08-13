<!-- <?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array("status" => "error", "message" => "GET method required"));
    die();
}

try {


    
    $ward = 'W5A';
$url  = "http://192.168.2.21/productivity/getWardNow.php?ward=" . urlencode($ward);

// เรียก API
$response = @file_get_contents($url);
if ($response === false) {
    // เกิดข้อผิดพลาดในการเชื่อมต่อ
    echo "Error: ไม่สามารถเรียก API ได้\n";
    exit;
}

// แปลง JSON เป็นตัวแปร PHP
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error: ไม่สามารถ decode JSON ได้ (" . json_last_error_msg() . ")\n";
    exit;
}

// ถ้า API คืนมาเป็นจำนวน (integer) ตรงๆ
$total = is_int($data) ? $data : ($data['TOTAL'] ?? null);

if ($total === null) {
    echo "Error: รูปแบบข้อมูลไม่ถูกต้อง\n";
} else {
    echo "จำนวนผู้ป่วยใน Ward {$ward} ตอนนี้: {$total}\n";
}

    if (1==1) {
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "success", "data" => $results]);
    } else {
        echo json_encode(["status" => "error", "message" => "No data found"]);
    }
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
