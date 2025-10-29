<?php
// include "../config/jwt.php";
// include "../config/config-subs.php"; // ต้องมี VAPID_SUBJECT, VAPID_PUBLIC, VAPID_PRIVATE
require '../../vendor/autoload.php';
$dbh = new PDO('mysql:host=192.168.2.41;dbname=intern_medequipment', 'intern', 'intern@Tsh');
const VAPID_SUBJECT = 'mailto:surapits@thaksinhospital.com';
const VAPID_PUBLIC = 'BLU8U0B0dbsPUjYzjn3wIvyhhxWvWKh2hVCSWhisJacbsMGXv80mMKsBf9XGzSkpENVohwSX06vvd5J1JYLx1cc';
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
function getActiveSubscriptions(PDO $dbh, $recipient_id): array
{
    $sql = "SELECT endpoint, p256dh, auth
          FROM push_subscriptions
          WHERE is_active = 1 AND u_id = ?";
    $st = $dbh->prepare($sql);
    $st->execute([$recipient_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// 4) ส่งแจ้งเตือนแบบ queue + flush (มีประสิทธิภาพกว่าเรียกครั้งละตัว)
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
    $webPush->setDefaultOptions(['TTL' => 60]); // อยู่ในคิว 60s

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
    $spare_part_id = $input->spare_part_id;

    // 1. ดึงข้อมูลอะไหล่
    $stmtSpare = $dbh->prepare("
        SELECT * 
        FROM spare_parts 
        WHERE spare_part_id = :spare_part_id
    ");
    $stmtSpare->execute([':spare_part_id' => $spare_part_id]);
    $spare = $stmtSpare->fetch(PDO::FETCH_ASSOC);
    if (!$spare) {
        throw new Exception("ไม่พบข้อมูลอะไหล่");
    }

    // 2. ดึงชื่อผู้ลงทะเบียน
    $stmtUser = $dbh->prepare("
        SELECT u.full_name
        FROM users u
        WHERE u.ID = :user_id
    ");
    $stmtUser->execute([':user_id' => $spare['user_id']]);
    $regis_user_name = $stmtUser->fetchColumn() ?: 'ไม่ทราบชื่อ';

    // // 3. ดึงผู้ดูแลหลักจาก spare_subcategory_id
    // $stmt = $dbh->prepare("
    //     SELECT 
    //         ru.u_id AS recipient_id,
    //         u.full_name AS recipient_name
    //     FROM relation_group rg
    //     JOIN group_user gu ON rg.group_user_id = gu.group_user_id
    //     JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
    //     LEFT JOIN users u ON ru.u_id = u.ID
    //     WHERE gu.type = 'ผู้ดูแลหลัก'
    //       AND rg.subcategory_id = :spare_subcategory_id
    // ");
    // $stmt->execute([':spare_subcategory_id' => $spare['spare_subcategory_id']]);
    // $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // if (!$recipients) {
    //     throw new Exception("ไม่พบผู้ดูแลหลักของหมวดหมู่อะไหล่");
    // }

    // 3. ดึงผู้ดูแลหลักแบบ fallback ไปที่ role_id = 6
    $stmt = $dbh->prepare("
    SELECT ID AS recipient_id, full_name AS recipient_name
    FROM users
    WHERE role_id = 6
    ");
    $stmt->execute();
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$recipients) {
        throw new Exception("ไม่พบผู้ดูแลหลัก (role_id=6)");
    }

    // 4. สร้าง array ของ recipients
    $recipientsArr = array_map(function ($r) {
        return [
            'recipient_id' => $r['recipient_id'],
            'recipient_name' => $r['recipient_name'] ?? ''
        ];
    }, $recipients);

    // 5. สร้าง payload สำหรับ notification
    $payload = [
        'title' => "มีการลงทะเบียนอะไหล่ใหม่",
        'body' => "อะไหล่: {$spare['asset_code']} - {$spare['name']}\nจาก: {$regis_user_name}",
        'url' => "https://medequipment.tsh/register/",
    ];

    // 6. ดึง subscription ของผู้ดูแลหลัก
    $targets = [];
    foreach ($recipientsArr as $r) {
        $targets = array_merge($targets, getActiveSubscriptions($dbh, $r['recipient_id']));
    }

    // var_dump($targets);
    if (empty($targets)) {
        // echo "aa";
        echo json_encode([
            "ok" => true,
            "requestId" => [],
            "notifyResults" => [],
            "summary" => ["total" => 0, "success" => 0, "failed" => 0]
        ]);
        exit;
    }
    $results = [];
    $results = sendPushToTargets($targets, $payload);
    // var_dump("results data:", $results);
    // 6) จัดการ endpoint ที่ตาย (404/410) -> set inactive
    $toDeactivate = [];
    foreach ($results as $r) {
        if (!$r['ok'] && in_array($r['status'] ?? null, [404, 410], true)) {
            $toDeactivate[] = $r['endpoint'];
        }
    }


    if ($toDeactivate) {
        // ปิดใช้งาน endpoint ที่ตาย
        // ถ้ามีคอลัมน์ endpoint_hash ให้ใช้แบบนี้ (ปลอดภัย/เร็วกว่า):
        //   UPDATE push_subscriptions SET is_active=0 WHERE endpoint_hash IN (SHA2(:ep1,256), SHA2(:ep2,256), ...)
        // ถ้าไม่มี endpoint_hash ให้ใช้ WHERE endpoint = :ep (อาจช้าได้ถ้าข้อมูลเยอะ)
        $dbh->beginTransaction();
        try {
            // ใช้ endpoint_hash ถ้ามี
            $useHash = true; // ตั้งค่านี้ตาม schema จริงของคุณ
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
                // แบบไม่ใช้ hash
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
            // ไม่ fail ทั้งงาน แค่แจ้งเตือนฝั่งผลลัพธ์
            $results[] = ['maintenance' => 'deactivate_failed', 'error' => $e->getMessage()];
        }
    }

    // 7) สรุปผล
    $success = count(array_filter($results, fn($r) => ($r['ok'] ?? false) === true));
    $failed = count($results) - $success;

    $dbh->beginTransaction();
    $date_time = date('Y-m-d H:i:s');

    // $updateSql = "UPDATE program SET  mention_code = ?,$recipient_name = ?,mention_by = ? ,mention_at = ? WHERE id = ?";
    // $stmt = $dbh->prepare($updateSql);
    // $stmt->bindParam(1, $recipient_id, PDO::PARAM_STR);
    // $stmt->bindParam(2, $$recipient_name, PDO::PARAM_STR);
    // $stmt->bindParam(3, $name, PDO::PARAM_STR);
    // $stmt->bindParam(4, $date_time, PDO::PARAM_STR);
    // $stmt->bindParam(5, $equipment_code, PDO::PARAM_INT);

    // $stmt->execute();
    // $dbh->commit();

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