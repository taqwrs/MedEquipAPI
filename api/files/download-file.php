$type = $_GET['type'] ?? null;
$file_id = $_GET['file_id'] ?? null;
if (!$type || !$file_id) exit('Missing parameters');

switch ($type) {
    case 'equip':
        $stmt = $dbh->prepare("SELECT equip_url AS url, equip_name AS name FROM file_equip WHERE file_equip_id=?");
        break;
    case 'spare':
        $stmt = $dbh->prepare("SELECT spare_url AS url, spare_name AS name FROM file_spare WHERE file_spare_id=?");
        break;
    case 'ma':
        $stmt = $dbh->prepare("SELECT file_ma_url AS url, file_ma_name AS name FROM file_ma WHERE file_ma_id=?");
        break;
    case 'ma-result':
        $stmt = $dbh->prepare("SELECT file_ma_url AS url, file_ma_name AS name FROM file_ma_result WHERE file_ma_result_id=?");
        break;
    default:
        exit('Unknown type');
}

$stmt->execute([$file_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) exit('File not found');

$filePath = __DIR__ . "/../.." . $row['url'];
if (!file_exists($filePath)) exit('File not found');

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($row['name']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
