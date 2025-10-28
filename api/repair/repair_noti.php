<?php
require '../../vendor/autoload.php';

$dbh = new PDO('mysql:host=192.168.2.41;dbname=intern_medequipment', 'intern', 'intern@Tsh');
const VAPID_SUBJECT = 'mailto:surapits@thaksinhospital.com';
const VAPID_PUBLIC  = 'BLU8U0B0dbsPUjYzjn3wIvyhhxWvWKh2hVCSWhisJacbsMGXv80mMKsBf9XGzSkpENVohwSX06vvd5J1JYLx1cc';
const VAPID_PRIVATE = 'ITjZ7YXOJYG93JJho-l3z5aTUawifIx9JKeCIsHJB0U';

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
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

// ฟังก์ชันดึง subscriptions ของผู้ใช้ในกลุ่ม
function getGroupSubscriptions(PDO $dbh, int $group_user_id): array
{
    $sql = "SELECT DISTINCT ps.endpoint, ps.p256dh, ps.auth, u.ID as user_id, u.full_name
            FROM push_subscriptions ps
            INNER JOIN relation_user ru ON ps.u_id = ru.u_id
            INNER JOIN users u ON ru.u_id = u.ID
            WHERE ru.group_user_id = :group_user_id 
            AND ps.is_active = 1";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':group_user_id' => $group_user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    $webPush->setDefaultOptions(['TTL' => 300]); // 5 นาที

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

    $results = [];
    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            $results[] = ['endpoint' => $endpoint, 'ok' => true];
        } else {
            $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
            $reason = $report->getReason();
            $results[] = [
                'endpoint' => $endpoint, 
                'ok' => false, 
                'status' => $status, 
                'reason' => $reason
            ];
        }
    }
    return $results;
}

// ปิดการใช้งาน endpoint ที่ตาย
function deactivateEndpoints(PDO $dbh, array $endpoints): void
{
    if (empty($endpoints)) return;

    $dbh->beginTransaction();
    try {
        $sql = "UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = :endpoint";
        $stmt = $dbh->prepare($sql);
        
        foreach ($endpoints as $ep) {
            $stmt->execute([':endpoint' => $ep]);
        }
        
        $dbh->commit();
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        throw $e;
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $repair_id = $input['repair_id'] ?? null;
    $repair_type_id = $input['repair_type_id'] ?? null;
    $equipment_code = $input['equipment_code'] ?? '';
    $equipment_name = $input['equipment_name'] ?? '';
    $title = $input['title'] ?? '';
    $reporter_name = $input['reporter_name'] ?? '';
    $location = $input['location'] ?? '';

    if (!$repair_type_id) {
        echo json_encode([
            "success" => false,
            "message" => "repair_type_id is required"
        ]);
        exit;
    }

    // ดึง group_user_id จาก repair_type
    $sqlGroup = "SELECT rt.group_user_id, rt.name_type, gu.group_name
                 FROM repair_type rt
                 LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
                 WHERE rt.repair_type_id = :repair_type_id";
    
    $stmtGroup = $dbh->prepare($sqlGroup);
    $stmtGroup->execute([':repair_type_id' => $repair_type_id]);
    $groupData = $stmtGroup->fetch(PDO::FETCH_ASSOC);

    if (!$groupData || !$groupData['group_user_id']) {
        echo json_encode([
            "success" => false,
            "message" => "ไม่พบกลุ่มผู้รับผิดชอบสำหรับประเภทการซ่อมนี้"
        ]);
        exit;
    }

    $group_user_id = $groupData['group_user_id'];
    $repair_type_name = $groupData['name_type'];
    $group_name = $groupData['group_name'];

    // ดึง subscriptions ของสมาชิกในกลุ่ม
    $targets = getGroupSubscriptions($dbh, $group_user_id);

    if (empty($targets)) {
        echo json_encode([
            "success" => true,
            "message" => "ไม่มีผู้ใช้ในกลุ่มที่เปิดการแจ้งเตือน",
            "group_name" => $group_name,
            "notifyResults" => [],
            "summary" => [
                "total" => 0,
                "success" => 0,
                "failed" => 0
            ]
        ]);
        exit;
    }

    $payload = [
        'title' => "งานซ่อมใหม่: {$repair_type_name}",
        'body' => "เครื่องมือ: {$equipment_code} - {$equipment_name}\n" .
                  "หัวข้อ: {$title}\n",
        'url' => "http://localhost:5173/repair",
    ];

    $results = sendPushToTargets($targets, $payload);

    $toDeactivate = [];
    foreach ($results as $r) {
        if (!$r['ok'] && in_array($r['status'] ?? null, [404, 410], true)) {
            $toDeactivate[] = $r['endpoint'];
        }
    }

    if (!empty($toDeactivate)) {
        deactivateEndpoints($dbh, $toDeactivate);
    }

    $success = count(array_filter($results, fn($r) => ($r['ok'] ?? false) === true));
    $failed = count($results) - $success;

    echo json_encode([
        "success" => true,
        "message" => "ส่งการแจ้งเตือนเรียบร้อย",
        "group_name" => $group_name,
        "repair_type" => $repair_type_name,
        "notifyResults" => $results,
        "summary" => [
            "total" => count($results),
            "success" => $success,
            "failed" => $failed,
            "deactivated" => count($toDeactivate)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}