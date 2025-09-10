<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"POST method only"]);
    exit;
}

$requiredFields = ['name','asset_code','updated_by'];
foreach($requiredFields as $f){
    if(empty($input[$f])){
        echo json_encode(["status"=>"error","message"=>"Missing field: $f"]);
        exit;
    }
}

$fkChecks = [
    'manufacturer_company_id'=>['companies','company_id'],
    'supplier_company_id'=>['companies','company_id'],
    'maintainer_company_id'=>['companies','company_id'],
    'location_department_id'=>['departments','department_id'],
    'subcategory_id'=>['equipment_subcategories','subcategory_id'],
    'import_type_id'=>['import_types','import_type_id']
];

try {
    $dbh->beginTransaction();

    foreach($fkChecks as $field=>$check){
        $table=$check[0]; $pk=$check[1];
        if(isset($input[$field])){
            $stmt=$dbh->prepare("SELECT COUNT(*) FROM `$table` WHERE `$pk`=:val");
            $stmt->execute([':val'=>$input[$field]]);
            if($stmt->fetchColumn()==0) throw new Exception("Invalid FK value for $field");
        }
    }

    // คำนวณ warranty_duration_days สำหรับ Equipment หลัก
    if (!empty($input['start_date']) && !empty($input['end_date'])) {
        $start_date = new DateTime($input['start_date']);
        $end_date = new DateTime($input['end_date']);
        $interval = $start_date->diff($end_date);
        $input['warranty_duration_days'] = $interval->days;
    } else {
        $input['warranty_duration_days'] = null;
    }

    $allFields = [
        'name','brand','asset_code','model','serial_number','import_type_id','subcategory_id',
        'location_department_id','location_details','manufacturer_company_id','supplier_company_id',
        'maintainer_company_id','spec','production_year','price','contract','start_date','end_date',
        'warranty_duration_days','warranty_condition', 'user_id',
        'updated_by','record_status','status','active','first_register'
    ];

    $cols=[]; $placeholders=[]; $values=[];
    foreach($allFields as $f){
        $cols[]=$f;
        $placeholders[]=":$f";
        $values[":$f"] = $input[$f] ?? null;
    }
    $cols[]='updated_at'; $placeholders[]='NOW()';
    $sql="INSERT INTO equipments (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
    $stmt=$dbh->prepare($sql);
    foreach($values as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $equipment_id=$dbh->lastInsertId();

    // Child Equipments (เปลี่ยนเป็น UPDATE เพื่อเชื่อมโยง)
    if(!empty($input['child_equipments']) && is_array($input['child_equipments'])){
        foreach($input['child_equipments'] as $child){
            if (!empty($child['equipment_id'])) {
                $stmt = $dbh->prepare("UPDATE equipments SET main_equipment_id = :main_id WHERE equipment_id = :child_id");
                $stmt->execute([':main_id' => $equipment_id, ':child_id' => $child['equipment_id']]);
            }
        }
    }

    // Spare Parts (เปลี่ยนเป็น UPDATE เพื่อเชื่อมโยง)
    if(!empty($input['spare_parts']) && is_array($input['spare_parts'])){
        foreach($input['spare_parts'] as $spare){
            if (!empty($spare['spare_part_id'])) {
                $stmt = $dbh->prepare("UPDATE spare_parts SET equipment_id = :main_id WHERE spare_part_id = :spare_id");
                $stmt->execute([':main_id' => $equipment_id, ':spare_id' => $spare['spare_part_id']]);
            }
        }
    }

    // Files
    if(!empty($input['files']) && is_array($input['files'])){
        foreach($input['files'] as $file){
            $file['equipment_id']=$equipment_id;
            $cols=[]; $placeholders=[]; $values=[];
            foreach($file as $k=>$v){
                $cols[]=$k; $placeholders[]=":$k"; $values[":$k"]=$v;
            }
            $cols[]='updated_at'; $placeholders[]='NOW()';
            $sql="INSERT INTO file_equip (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
            $stmt=$dbh->prepare($sql);
            foreach($values as $k=>$v) $stmt->bindValue($k,$v);
            $stmt->execute();
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Insert successfully","equipment_id"=>$equipment_id]);

}catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
?>