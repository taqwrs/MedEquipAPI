<?php
// include "../config/jwt.php";
// include "../config/config-subs.php";
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

// ฟังก์ชันดึงข้อมูลผู้ใช้จาก recipient_user_id
function getUserName(PDO $dbh, $user_id): ?string
{
    $sql = "SELECT full_name FROM users WHERE ID = ?";
    $st = $dbh->prepare($sql);
    $st->execute([$user_id]);
    $result = $st->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['full_name'] : null;
}

// ดึง subscriptions จาก DB (เฉพาะที่ active)
function getActiveSubscriptions(PDO $dbh, $recipient_id): array
{
    $sql = "SELECT endpoint, p256dh, auth
          FROM push_subscriptions
          WHERE is_active = 1 AND u_id = ?";
    $st = $dbh->prepare($sql);
    $st->execute([$recipient_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ส่งแจ้งเตือนแบบ queue + flush
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
    $transfer_user_id = $input->transfer_user_id;
    $recipient_user_id = $input->recipient_user_id ?? null; // ผู้ที่ส่งคืนเครื่องมือ
    $equipment_code = $input->equipment_code ?? '';
    $equipment_name = $input->equipment_name ?? '';
    $requestId = (string) time();

    // ดึงชื่อผู้ส่งคืนเครื่องมือจากฐานข้อมูล
    $recipient_user_name = 'ผู้ใช้'; // ค่า default
    if ($recipient_user_id) {
        $name = getUserName($dbh, $recipient_user_id);
        if ($name) {
            $recipient_user_name = $name;
        }
    }

    $payload = [
        'title' => "คุณได้รับเครื่องมือคืนเรียบร้อยแล้ว",
        'body' => "เครื่องมือ: {$equipment_code} - {$equipment_name}\nโดย: {$recipient_user_name}",
        'url' => "https://medequipment.tsh/transfer/"
    ];

    $targets = getActiveSubscriptions($dbh, $transfer_user_id);

    if (empty($targets)) {
        echo json_encode([
            "ok" => true,
            "requestId" => $requestId,
            "notifyResults" => [],
            "summary" => ["total" => 0, "success" => 0, "failed" => 0]
        ]);
        exit;
    }

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
            $useHash = true;
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
            if ($dbh->inTransaction())
                $dbh->rollBack();
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
        ],
    ]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}