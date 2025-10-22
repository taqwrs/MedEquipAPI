<?php
// C:\xampp\htdocs\back_equip\api\files\download-file.php

// เพิ่ม CORS headers ที่ด้านบนสุด
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Accept, If-Modified-Since, If-None-Match");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Expose-Headers: Content-Disposition");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include "../config/config.php"; // ให้ $dbh พร้อมใช้งาน

$type = $_GET['type'] ?? null;
$file_id = $_GET['file_id'] ?? null;

if (!$type || !$file_id) {
    error_log("Missing parameters: type={$type}, file_id={$file_id}");
    http_response_code(400);
    echo 'Missing parameters';
    exit;
}

// Mapping table/column และ folder ตามจริง
$typeMap = [
    'equipment' => [
        'table' => 'file_equip',
        'urlCol' => 'equip_url',
        'nameCol' => 'file_equip_name',
        'idCol' => 'file_equip_id',
        'folder' => '/../file-upload/file_equip/'
    ],
    'spare' => [
        'table' => 'file_spare',
        'urlCol' => 'spare_url',
        'nameCol' => 'file_spare_name',
        'idCol' => 'file_spare_id',
        'folder' => '/../file-upload/file_spare/'
    ],
    'ma' => [
        'table' => 'file_ma',
        'urlCol' => 'file_ma_url',
        'nameCol' => 'file_ma_name',
        'idCol' => 'file_ma_id',
        'folder' => '/../file-upload/file_ma/'
    ],
    'ma-result' => [
        'table' => 'file_ma_result',
        'urlCol' => 'file_ma_url',
        'nameCol' => 'file_ma_name',
        'idCol' => 'file_ma_result_id',
        'folder' => '/../file-upload/file_ma_result/'
    ],
];

if (!isset($typeMap[$type])) {
    error_log("Unknown type: {$type}");
    http_response_code(400);
    echo 'Unknown type';
    exit;
}

$map = $typeMap[$type];

// ดึงข้อมูลไฟล์จาก DB
$stmt = $dbh->prepare("
    SELECT {$map['urlCol']} AS url, {$map['nameCol']} AS name 
    FROM {$map['table']} 
    WHERE {$map['idCol']} = ?
");
$stmt->execute([$file_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    error_log("File not found in DB: type={$type}, id={$file_id}");
    http_response_code(404);
    echo 'File not found in DB';
    exit;
}

$url = $row['url'];
$localFolder = realpath(__DIR__ . $map['folder']) . DIRECTORY_SEPARATOR;

error_log("Attempting to fetch file: {$url}");

// ถ้า URL เป็นไฟล์เว็บ ให้ดาวน์โหลดจาก URL
if (filter_var($url, FILTER_VALIDATE_URL)) {
    error_log("Detected external URL, fetching: {$url}");
    
    $fileContents = @file_get_contents($url);
    if ($fileContents === false) {
        error_log("Cannot fetch file from URL: {$url}");
        http_response_code(500);
        echo 'Cannot fetch file from URL';
        exit;
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($row['name']) . '"'); // ใช้ชื่อไฟล์จาก DB
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($fileContents));

    echo $fileContents;
    exit;
}

// ถ้าเป็นไฟล์ local
$fileName = basename($url);
$filePath = $localFolder . $fileName;

error_log("Local file path resolved to: {$filePath}");

if (!file_exists($filePath)) {
    error_log("File not found on server: {$filePath}");
    http_response_code(404);
    echo 'File not found on server';
    exit;
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($row['name']) . '"'); // ใช้ชื่อไฟล์จาก DB
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

readfile($filePath);
error_log("File sent successfully: {$filePath}");
exit;
?>