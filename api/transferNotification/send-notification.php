<?php
// include "../config/jwt.php";
// include "../config/config-subs.php"; // ต้องมี VAPID_SUBJECT, VAPID_PUBLIC, VAPID_PRIVATE
require '../../vendor/autoload.php';
$dbh = new PDO('mysql:host=192.168.2.41;dbname=intern_medequipment', 'intern', 'intern@Tsh');
const VAPID_SUBJECT = 'mailto:surapits@thaksinhospital.com';
const VAPID_PUBLIC  = 'BLU8U0B0dbsPUjYzjn3wIvyhhxWvWKh2hVCSWhisJacbsMGXv80mMKsBf9XGzSkpENVohwSX06vvd5J1JYLx1cc';
const VAPID_PRIVATE = 'ITjZ7YXOJYG93JJho-l3z5aTUawifIx9JKeCIsHJB0U';

$input = json_decode(file_get_contents('php://input'));

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
// payload ที่อยากส่ง (ถ้าหน้าบ้านส่งมา ให้เอามา merge/override ได้)

// 3) ดึง subscriptions จาก DB (เฉพาะที่ active)
//    ถ้าอยากจำกัดเฉพาะแอดมิน ให้เพิ่ม WHERE emp_code IN (...) เองได้
// ฟังก์ชันดึง subscriptions ของทุก user ใน department
function getActiveSubscriptions(PDO $dbh, $department_id): array
{
    $sql = "SELECT ps.endpoint, ps.p256dh, ps.auth, u.u_id, u.full_name
            FROM push_subscriptions ps
            INNER JOIN users u ON ps.u_id = u.u_id
            WHERE ps.is_active = 1 
            AND u.department_id = ?";
    $st = $dbh->prepare($sql);
    $st->execute([$department_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ฟังก์ชันส่ง push notification
function sendPushToTargets(array $targets, array $payload): array
{
    $auth = [
        'VAPID' => [
            'subject' => VAPID_SUBJECT,
            'publicKey' => VAPID_PUBLIC,
            'privateKey' => VAPID_PRIVATE,
        ]
    ];
    $webPush = new WebPush($auth);
    $webPush->setDefaultOptions(['TTL' => 60]);

    // queue ทุกตัว
    foreach ($targets as $t) {
        $sub = [
            'endpoint' => $t['endpoint'],
            'keys' => [
                'p256dh' => $t['p256dh'],
                'auth' => $t['auth'],
            ],
        ];
        $webPush->queueNotification(
            Subscription::create($sub),
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    // flush และเก็บผลลัพธ์
    $results = [];
    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            $results[] = ['endpoint' => $endpoint, 'ok' => true];
        } else {
            $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
            $reason = $report->getReason();
            $results[] = ['endpoint' => $endpoint, 'ok' => false, 'status' => $status, 'reason' => $reason];
        }
    }
    return $results;
}


try {
    $to_department_id = $input->to_department ?? null;
    $to_department_name = $input->to_department_name ?? '';
    $equipment_code = $input->equipment_code ?? '';
    $equipment_name = $input->equipment_name ?? '';
    $transfer_user_name = $input->transfer_user_name ?? '';
    $equipment_id = $input->equipment_id ?? null;
    $transfer_type = $input->transfer_type ?? '';

    // ตรวจสอบว่ามี department_id หรือไม่
    if (!$to_department_id) {
        echo json_encode([
            "ok" => false,
            "message" => "ไม่พบรหัสแผนกปลายทาง (to_department)"
        ]);
        exit;
    }

    $requestId = (string) time();
    
    // สร้าง payload สำหรับการแจ้งเตือน
    $payload = [
        'title' => "แผนกของคุณได้รับการโอนย้ายเครื่องมือ {$transfer_type}",
        'body' => "เครื่องมือ: {$equipment_code} - {$equipment_name}\nจาก: {$transfer_user_name}",
        'url' => "https://medequipment.tsh/transfer/",
        'data' => [
            'equipment_id' => $equipment_id,
            'equipment_code' => $equipment_code,
            'to_department_id' => $to_department_id,
            'transfer_type' => $transfer_type
        ]
    ];

    // ดึง subscriptions ของทุก user ใน department
    $targets = getActiveSubscriptions($dbh, $to_department_id);

    if (empty($targets)) {
        echo json_encode([
            "ok" => true,
            "requestId" => $requestId,
            "notifyResults" => [],
            "summary" => [
                "total" => 0,
                "success" => 0,
                "failed" => 0,
                "users_notified" => 0
            ],
            "message" => "ไม่พบ user ที่มี push subscription ใน department นี้"
        ]);
        exit;
    }

    // นับจำนวน user ที่จะส่ง
    $unique_users = array_unique(array_column($targets, 'u_id'));
    $user_count = count($unique_users);

    // ส่ง push notification
    $results = sendPushToTargets($targets, $payload);

    // จัดการ endpoint ที่ตาย (404/410)
    $toDeactivate = [];
    foreach ($results as $r) {
        if (!$r['ok'] && in_array($r['status'] ?? null, [404, 410], true)) {
            $toDeactivate[] = $r['endpoint'];
        }
    }

    if ($toDeactivate) {
        $dbh->beginTransaction();
        try {
            $useHash = true; // ตั้งค่าตาม schema ของคุณ
            if ($useHash) {
                $parts = [];
                $params = [];
                foreach ($toDeactivate as $i => $ep) {
                    $key = ":ep{$i}";
                    $parts[] = "SHA2({$key}, 256)";
                    $params[$key] = $ep;
                }
                $sql = "UPDATE push_subscriptions SET is_active = 0 WHERE endpoint_hash IN (" . implode(',', $parts) . ")";
                $st = $dbh->prepare($sql);
                $st->execute($params);
            } else {
                $sql = "UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = :ep";
                $st = $dbh->prepare($sql);
                foreach ($toDeactivate as $ep) {
                    $st->execute([':ep' => $ep]);
                }
            }
            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            $results[] = ['maintenance' => 'deactivate_failed', 'error' => $e->getMessage()];
        }
    }

    // สรุปผล
    $success = count(array_filter($results, fn($r) => ($r['ok'] ?? false) === true));
    $failed = count($results) - $success;

    echo json_encode([
        "ok" => true,
        "requestId" => $requestId,
        "notifyResults" => $results,
        "summary" => [
            "total" => count($results),
            "success" => $success,
            "failed" => $failed,
            "deactivated" => count($toDeactivate),
            "users_notified" => $user_count,
            "department_id" => $to_department_id
        ],
    ]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
?>