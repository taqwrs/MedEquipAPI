<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {

    // รับค่าจาก input: role_id และ permissions (array ของ path_name เมนูที่อนุญาต)
    $role_id = $input->role_id ?? null;
    $permissions = $input->permissions ?? [];

    // ตรวจสอบว่ามี role_id และ permissions ถูกต้องหรือไม่
    if (!$role_id || !is_array($permissions)) {
        throw new Exception("Missing role_id or permissions array");
    }

    // ดึงเมนูทั้งหมดจากตาราง menu เพื่อเอา path_name มาแมปกับ id
    $stmt = $dbh->prepare("SELECT id, path_name FROM menu");
    $stmt->execute();
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // สร้างแผนที่ (map) ของ path_name => menu_id เพื่อ lookup ง่ายขึ้น
    $menuMap = [];
    foreach ($menus as $menu) {
        $menuMap[$menu['path_name']] = $menu['id'];
    }

    // วนลูปทุกเมนูเพื่อตรวจสอบว่าบทบาทนี้ควรมีสิทธิ์ในเมนูไหนบ้าง
    foreach ($menuMap as $path => $menu_id) {
        // ถ้า path_name อยู่ใน permissions -> ให้ status เป็น 1 (มีสิทธิ์), ไม่งั้นเป็น 0
        $status = in_array($path, $permissions) ? 1 : 0;

        // ตรวจสอบว่ามี record เดิมในตาราง permission หรือไม่
        $check = $dbh->prepare("SELECT * FROM permission WHERE role_id = ? AND menu_id = ?");
        $check->execute([$role_id, $menu_id]);

        if ($check->rowCount() > 0) {
            // ถ้ามีแล้ว -> ทำการอัปเดต status
            $update = $dbh->prepare("UPDATE permission SET status = ? WHERE role_id = ? AND menu_id = ?");
            $update->execute([$status, $role_id, $menu_id]);
        } else {
            // ถ้ายังไม่มี record -> แทรกใหม่
            $insert = $dbh->prepare("INSERT INTO permission (role_id, menu_id, status) VALUES (?, ?, ?)");
            $insert->execute([$role_id, $menu_id, $status]);
        }
    }

    // ส่งผลลัพธ์กลับเป็น JSON ยืนยันว่าการอัปเดตสิทธิ์เสร็จสมบูรณ์
    echo json_encode(["status" => "ok", "message" => "Permission updated successfully"]);
} catch (Exception $e) {
    //    $dbh->rollBack();

    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

// โค้ดนี้ใช้สำหรับ บันทึก/อัปเดตสิทธิ์การเข้าถึงเมนู (menu) ของบทบาท (role) โดยจะ:

// รับ role_id และ array ของ permissions (เช่น ['/dashboard', '/report'])

// เทียบกับ path_name ของเมนูทั้งหมด

// แล้ว อัปเดตหรือแทรกข้อมูลในตาราง permission ให้ตรงกับเมนูที่มีสิทธิ์ (status = 1) และไม่มีสิทธิ์ (status = 0)