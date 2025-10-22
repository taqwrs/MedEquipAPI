<?php
// include "../config/jwt-admin.php";
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header("Access-Control-Allow-Headers: Content-Type, Authorization");
require '../../vendor/autoload.php';
include "../config/config-subs.php";

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array("status" => "error", "message" => "GET method required"));
    die();
}

try {
//   jsonOut(['key'=>VAPID_PUBLIC]);
// ---------- OUTPUT ----------
echo json_encode([
    "key" => VAPID_PUBLIC
]);


} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
?>
