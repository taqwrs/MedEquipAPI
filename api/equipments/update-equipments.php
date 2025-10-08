<?php
include "../config/jwt.php";
include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"POST only"]);
    exit;
}

$equipment_id = $_POST['equipment_id'] ?? null;
if (!$equipment_id) {
    echo json_encode(["status"=>"error","message"=>"equipment_id required"]);
    exit;
}

$updated_by = $_POST['updated_by'] ?? ($decoded->user_id ?? null);
if (!$updated_by) {
    echo json_encode(["status"=>"error","message"=>"updated_by required"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) throw new Exception("User ID not found");

    // ------------------- ดึงข้อมูลเก่า -------------------
    $stmt = $dbh->prepare("SELECT * FROM equipments WHERE equipment_id=:id");
    $stmt->execute([':id'=>$equipment_id]);
    $oldEquipment = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $dbh->prepare("SELECT equipment_id FROM equipments WHERE main_equipment_id = :main_id");
    $stmt->execute([':main_id'=>$equipment_id]);
    $oldChilds = $stmt->fetchAll(PDO::FETCH_COLUMN,0);

    $stmt = $dbh->prepare("SELECT spare_part_id FROM spare_parts WHERE equipment_id = :main_id");
    $stmt->execute([':main_id'=>$equipment_id]);
    $oldSpares = $stmt->fetchAll(PDO::FETCH_COLUMN,0);

    // ------------------- Update Main Equipment -------------------
    $fields = [
        'name','asset_code','serial_number','brand','model',
        'import_type_id','subcategory_id','location_department_id',
        'manufacturer_company_id','supplier_company_id','maintainer_company_id',
        'user_id','status','record_status','details','first_register',
        'location_details','spec','production_year','price','contract',
        'start_date','end_date','warranty_condition'
    ]; // ลบ 'warranty_duration_days' ออก

    $setParts = [];
    $params = [':equipment_id'=>$equipment_id];
    $old_data = ['equipment_id' => $equipment_id];
    $new_data = ['equipment_id' => $equipment_id];

    foreach($fields as $f){
        $newValue = array_key_exists($f, $_POST) ? $_POST[$f] : null;

        $setParts[] = "$f=:$f";
        $params[":$f"] = $newValue;

        $oldVal = $oldEquipment[$f] ?? null;
        if($oldVal != $newValue){
            $old_data[$f] = $oldVal;
            $new_data[$f] = $newValue;
        }
    }

    // ----------- คำนวณ warranty_duration_days หลังบ้าน -----------
    if(!empty($_POST['start_date']) && !empty($_POST['end_date'])){
        $start = new DateTime($_POST['start_date']);
        $end = new DateTime($_POST['end_date']);
        $warranty_days = $start->diff($end)->days;
    } else {
        $warranty_days = null;
    }
    $setParts[] = "warranty_duration_days=:warranty_duration_days";
    $params[':warranty_duration_days'] = $warranty_days;

    $oldVal = $oldEquipment['warranty_duration_days'] ?? null;
    if($oldVal != $warranty_days){
        $old_data['warranty_duration_days'] = $oldVal;
        $new_data['warranty_duration_days'] = $warranty_days;
    }

    if(!empty($setParts)){
        $setParts[] = "updated_by=:updated_by";
        $setParts[] = "updated_at=NOW()";
        $params[':updated_by'] = $updated_by;

        $sql = "UPDATE equipments SET ".implode(',',$setParts)." WHERE equipment_id=:equipment_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    // ------------------- Update Child Equipments -------------------
    $childs = !empty($_POST['child_equipments']) ? json_decode($_POST['child_equipments'],true) : [];
    $childs = is_array($childs)?$childs:[];

    $removedChilds = array_diff($oldChilds,$childs);
    $addedChilds = array_diff($childs,$oldChilds);

    if($removedChilds){
        $placeholders = implode(',',array_fill(0,count($removedChilds),'?'));
        $stmt = $dbh->prepare("UPDATE equipments SET main_equipment_id=NULL, updated_by=?, updated_at=NOW() WHERE equipment_id IN ($placeholders)");
        $stmt->execute(array_merge([$updated_by],$removedChilds));

        $old_data['child_equipments'] = $removedChilds;
        $new_data['child_equipments'] = [];
    }

    if($addedChilds){
        foreach($addedChilds as $cid){
            if($cid==$equipment_id) throw new Exception("อุปกรณ์หลักไม่สามารถเป็นอุปกรณ์ย่อยของตัวเองได้");
            $stmt = $dbh->prepare("UPDATE equipments SET main_equipment_id=:main_id, updated_by=:updated_by, updated_at=NOW() WHERE equipment_id=:child_id");
            $stmt->execute([':main_id'=>$equipment_id,':updated_by'=>$updated_by,':child_id'=>$cid]);
        }
        $old_data['child_equipments'] = [];
        $new_data['child_equipments'] = $addedChilds;
    }

    // ------------------- Update Spare Parts -------------------
    $spares = !empty($_POST['spare_parts']) ? json_decode($_POST['spare_parts'],true) : [];
    $spares = is_array($spares)?$spares:[];

    $removedSpares = array_diff($oldSpares,$spares);
    $addedSpares = array_diff($spares,$oldSpares);

    if($removedSpares){
        $placeholders = implode(',',array_fill(0,count($removedSpares),'?'));
        $stmt = $dbh->prepare("UPDATE spare_parts SET equipment_id=NULL, updated_by=?, updated_at=NOW() WHERE spare_part_id IN ($placeholders)");
        $stmt->execute(array_merge([$updated_by],$removedSpares));

        $old_data['spare_parts'] = $removedSpares;
        $new_data['spare_parts'] = [];
    }

    if($addedSpares){
        foreach($addedSpares as $sid){
            $stmt = $dbh->prepare("UPDATE spare_parts SET equipment_id=:main_id, updated_by=:updated_by, updated_at=NOW() WHERE spare_part_id=:spare_id");
            $stmt->execute([':main_id'=>$equipment_id,':updated_by'=>$updated_by,':spare_id'=>$sid]);
        }

        $old_data['spare_parts'] = [];
        $new_data['spare_parts'] = $addedSpares;
    }

    // ------------------- Insert Log -------------------
    if(!empty($old_data) || !empty($new_data)){
        $log->insertLog($user_id,'equipments','UPDATE',$old_data,$new_data, 'register_logs');
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Update successfully","equipment_id"=>$equipment_id]);

}catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
