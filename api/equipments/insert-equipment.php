<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"POST method only"]);
    exit;
}

// ต้องมีค่าที่จำเป็นสำหรับอุปกรณ์หลัก
$requiredFields = ['name','asset_code','updated_by'];
foreach($requiredFields as $f){
    if(empty($input[$f])){
        echo json_encode(["status"=>"error","message"=>"Missing field: $f"]);
        exit;
    }
}

// ตรวจสอบ FK แบบ manual
$fkChecks = [
    'manufacturer_company_id' => ['companies', 'company_id'],
    'supplier_company_id' => ['companies', 'company_id'],
    'maintainer_company_id' => ['companies', 'company_id'],
    'location_department_id' => ['departments', 'department_id'],
    'subcategory_id' => ['equipment_subcategories', 'subcategory_id'],
    'import_type_id' => ['import_types', 'import_type_id']
];

try{
    $dbh->beginTransaction();

    foreach($fkChecks as $field => $check){
        $table = $check[0];
        $pkColumn = $check[1];

        if(isset($input[$field])){
            $stmt = $dbh->prepare("SELECT COUNT(*) FROM `$table` WHERE `$pkColumn`=:val");
            $stmt->execute([':val'=>$input[$field]]);
            if($stmt->fetchColumn()==0){
                throw new Exception("Invalid FK value for $field: ".$input[$field]);
            }
        }
    }

    // ---------- Insert Equipment หลัก ----------
    $allFields = [
        'name','brand','asset_code','model','serial_number','import_type_id','subcategory_id',
        'location_department_id','location_details','manufacturer_company_id','supplier_company_id',
        'maintainer_company_id','spec','production_year','price','contract','start_date','end_date',
        'warranty_duration_days','warranty_condition','group_user_id','group_responsible_id','user_id',
        'updated_by','record_status','status','active','first_register'
    ];
    $cols = []; $placeholders = []; $values = [];
    foreach($allFields as $f){
        $cols[] = $f;
        $placeholders[] = ":$f";
        $values[":$f"] = $input[$f] ?? null;
    }
    $cols[] = 'updated_at';
    $placeholders[] = 'NOW()';
    $sql = "INSERT INTO equipments (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
    $stmt = $dbh->prepare($sql);
    foreach($values as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $equipment_id = $dbh->lastInsertId();

    // ---------- Child Equipments ----------
    if(!empty($input['child_equipments']) && is_array($input['child_equipments'])){
        foreach($input['child_equipments'] as $child){
            $child['main_equipment_id'] = $equipment_id;
            $child['updated_by'] = $input['updated_by'];
            
            // กำหนดค่าเริ่มต้นสำหรับคอลัมน์ที่อาจไม่มีข้อมูล
            $child['location_details'] = $child['location_details'] ?? '';
            $child['first_register'] = $child['first_register'] ?? null;
            $child['record_status'] = $child['record_status'] ?? 'draft';
            $child['status'] = $child['status'] ?? 'in_stock';
            $child['active'] = $child['active'] ?? 1;

            $childFields = ['name','brand','asset_code','model','serial_number','import_type_id','subcategory_id','location_department_id','location_details','manufacturer_company_id','supplier_company_id','maintainer_company_id','spec','production_year','price','contract','start_date','end_date','warranty_duration_days','warranty_condition','group_user_id','group_responsible_id','user_id','main_equipment_id','updated_by','record_status','status','active','first_register'];
            $childCols=[]; $childPlaceholders=[]; $childValues=[];

            foreach($childFields as $f){
                $childCols[] = $f;
                $childPlaceholders[] = ":$f";
                $childValues[":$f"] = $child[$f] ?? null;
            }
            $childCols[]='updated_at'; $childPlaceholders[]='NOW()';
            $sql="INSERT INTO equipments (".implode(',',$childCols).") VALUES (".implode(',',$childPlaceholders).")";
            $stmt=$dbh->prepare($sql);
            foreach($childValues as $k=>$v) $stmt->bindValue($k,$v);
            $stmt->execute();
        }
    }

    // ---------- Spare Parts ----------
    if(!empty($input['spare_parts']) && is_array($input['spare_parts'])){
        foreach($input['spare_parts'] as $spare){
            $spare['equipment_id']=$equipment_id;
            $spare['updated_by']=$input['updated_by'];

            // กำหนดค่าเริ่มต้นสำหรับคอลัมน์ที่อาจไม่มีข้อมูล
            $spare['first_register'] = $spare['first_register'] ?? null;
            $spare['active'] = $spare['active'] ?? 1;
            
            $spareFields = ['name','brand','asset_code','model','serial_number','import_type_id','spec','spare_subcate_id','price','contract','start_date','end_date','warranty_duration_days','warranty_condition','group_user_id','group_responsible_id','user_id','manufacturer_company_id','supplier_company_id','maintainer_company_id','production_year','location_department_id','location_details','first_register','active','equipment_id','updated_by'];
            $cols=[]; $placeholders=[]; $values=[];

            foreach($spareFields as $f){
                $cols[] = $f;
                $placeholders[] = ":$f";
                $values[":$f"] = $spare[$f] ?? null;
            }
            $cols[]='updated_at'; $placeholders[]='NOW()';
            $sql="INSERT INTO spare_parts (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
            $stmt=$dbh->prepare($sql);
            foreach($values as $k=>$v) $stmt->bindValue($k,$v);
            $stmt->execute();
        }
    }

    // ---------- Files ----------
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
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}