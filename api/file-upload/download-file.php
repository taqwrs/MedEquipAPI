<?php
if (!isset($_GET['file']) || !isset($_GET['type'])) {
    http_response_code(400);
    exit("File name or type is missing");
}

$fileName = basename($_GET['file']);
$fileType = basename($_GET['type']);
$baseDir = __DIR__ . '/';

if ($fileType === 'equip' || $fileType === 'file_equip') {
    $targetDir = $baseDir . 'file_equip/';
} elseif ($fileType === 'spare' || $fileType === 'file_spare') {
    $targetDir = $baseDir . 'file_spare/';
} else {
    http_response_code(400);
    exit("Invalid file type");
}

$filePath = $targetDir . $fileName;
if (!file_exists($filePath)) {
    http_response_code(404);
    exit("File not found");
}

// กำหนด MIME type
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'doc'  => 'application/msword',
];

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . $fileName . '"'); // inline = preview
readfile($filePath);
exit;

