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

function getMainAdminSubscriptions(PDO $dbh, int $equipment_id): array
{
    $sql = "SELECT DISTINCT 
                ps.endpoint, 
                ps.p256dh, 
                ps.auth, 
                u.ID as user_id, 
                u.full_name
            FROM equipments e
            INNER JOIN relation_group rg ON e.subcategory_id = rg.subcategory_id
            INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
            INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
            INNER JOIN users u ON ru.u_id = u.ID
            INNER JOIN push_subscriptions ps ON u.ID = ps.u_id
            WHERE e.equipment_id = :equipment_id
            AND gu.type = 'ผู้ดูแลหลัก'
            AND ps.is_active = 1";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':equipment_id' => $equipment_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


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
    $webPush->setDefaultOptions(['TTL' => 300]);

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

    $writeoff_id = $input['writeoff_id'] ?? null;
    $equipment_id = $input['equipment_id'] ?? null;
    $equipment_code = $input['equipment_code'] ?? '';
    $equipment_name = $input['equipment_name'] ?? '';
    $writeoff_type = $input['writeoff_type'] ?? '';

    if (!$equipment_id) {
        echo json_encode([
            "success" => false,
            "message" => "equipment_id is required"
        ]);
        exit;
    }

    $targets = getMainAdminSubscriptions($dbh, $equipment_id);

    if (empty($targets)) {
        echo json_encode([
            "success" => true,
            "message" => "ไม่มีผู้ดูแลหลักที่เปิดการแจ้งเตือนสำหรับเครื่องมือนี้",
            "notifyResults" => [],
            "summary" => [
                "total" => 0,
                "success" => 0,
                "failed" => 0
            ]
        ]);
        exit;
    }

    $sqlGroup = "SELECT DISTINCT gu.group_name
                 FROM equipments e
                 INNER JOIN relation_group rg ON e.subcategory_id = rg.subcategory_id
                 INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
                 WHERE e.equipment_id = :equipment_id 
                 AND gu.type = 'ผู้ดูแลหลัก'";
    
    $stmtGroup = $dbh->prepare($sqlGroup);
    $stmtGroup->execute([':equipment_id' => $equipment_id]);
    $groups = $stmtGroup->fetchAll(PDO::FETCH_COLUMN);
    $group_names = implode(', ', $groups);


    $payload = [
        'title' => "คำขอแทงจำหน่ายใหม่",
        'body' => "เครื่องมือ: {$equipment_code} - {$equipment_name}\n"  ,
        'url' => "https://medequipment.tsh/disposal",
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

    // สรุปผล
    $success = count(array_filter($results, fn($r) => ($r['ok'] ?? false) === true));
    $failed = count($results) - $success;

    echo json_encode([
        "success" => true,
        "message" => "ส่งการแจ้งเตือนเรียบร้อย",
        "notified_groups" => $group_names,
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