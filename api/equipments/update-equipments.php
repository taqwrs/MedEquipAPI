<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD']!=='POST'){
    echo json_encode(["status"=>"error","message"=>"POST only"]);
    exit;
}
if(empty($input['equipment_id'])){
    echo json_encode(["status"=>"error","message"=>"equipment_id required"]);
    exit;
}

$equipment_id = $input['equipment_id'];

try{
    $dbh->beginTransaction();

    // Update Equipment หลัก
    $fields=[
        'name','asset_code','serial_number','brand','model','import_type_id','subcategory_id',
        'location_department_id','manufacturer_company_id','supplier_company_id','maintainer_company_id',
        'user_id','status','record_status','first_register',
        'location_details','spec','production_year','price','contract','start_date','end_date',
        'warranty_duration_days','warranty_condition','updated_by'
    ];
    $setParts=[]; $params=[':equipment_id'=>$equipment_id];
    foreach($fields as $f){
        if(isset($input[$f])){
            $setParts[]="$f=:$f";
            $params[":$f"]=$input[$f];
        }
    }
    $setParts[]="updated_at=NOW()";
    if(!empty($setParts)){
        $sql="UPDATE equipments SET ".implode(',',$setParts)." WHERE equipment_id=:equipment_id";
        $stmt=$dbh->prepare($sql);
        $stmt->execute($params);
    }

    // Update/Insert Child Equipments
    if(!empty($input['child_equipments']) && is_array($input['child_equipments'])){
        foreach($input['child_equipments'] as $child){
            $child['main_equipment_id']=$equipment_id;
            $child['updated_by']=$input['updated_by'];
            $child['location_details'] = $child['location_details'] ?? '';
            $child['first_register'] = $child['first_register'] ?? null;
            $child['record_status'] = $child['record_status'] ?? 'draft';
            $child['status'] = $child['status'] ?? 'in_stock';
            $child['active'] = $child['active'] ?? 1;

            if(!empty($child['equipment_id'])){
                $child_id=$child['equipment_id'];
                unset($child['equipment_id']);
                $set=[]; $p=[':updated_by'=>$child['updated_by'], ':equipment_id'=>$child_id];
                foreach($child as $k=>$v){$set[]="$k=:$k"; $p[":$k"]=$v;}
                $set[]="updated_at=NOW()";
                $sql="UPDATE equipments SET ".implode(',',$set)." WHERE equipment_id=:equipment_id";
                $stmt=$dbh->prepare($sql);
                $stmt->execute($p);
            } else {
                $childFields = ['name','brand','asset_code','model','serial_number','import_type_id','subcategory_id','location_department_id','location_details','manufacturer_company_id','supplier_company_id','maintainer_company_id','spec','production_year','price','contract','start_date','end_date','warranty_duration_days','warranty_condition','user_id','main_equipment_id','updated_by','record_status','status','active','first_register'];
                $cols=[]; $placeholders=[]; $values=[];
                foreach($childFields as $f){$cols[]=$f; $placeholders[]=":$f"; $values[":$f"]=$child[$f] ?? null;}
                $cols[]='updated_at'; $placeholders[]='NOW()';
                $sql="INSERT INTO equipments (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
                $stmt=$dbh->prepare($sql);
                foreach($values as $k=>$v) $stmt->bindValue($k,$v);
                $stmt->execute();
            }
        }
    }

    // Update/Insert Spare Parts
    if(!empty($input['spare_parts']) && is_array($input['spare_parts'])){
        foreach($input['spare_parts'] as $spare){
            $spare['equipment_id']=$equipment_id;
            $spare['updated_by']=$input['updated_by'];
            $spare['first_register'] = $spare['first_register'] ?? null;
            $spare['active'] = $spare['active'] ?? 1;

            if(!empty($spare['spare_part_id'])){
                $spare_id=$spare['spare_part_id'];
                unset($spare['spare_part_id']);
                $set=[]; $p=[':spare_part_id'=>$spare_id, ':updated_by'=>$spare['updated_by']];
                foreach($spare as $k=>$v){$set[]="$k=:$k"; $p[":$k"]=$v;}
                $set[]="updated_at=NOW()";
                $sql="UPDATE spare_parts SET ".implode(',',$set)." WHERE spare_part_id=:spare_part_id";
                $stmt=$dbh->prepare($sql);
                $stmt->execute($p);
            } else {
                $spareFields=['name','brand','asset_code','model','serial_number','import_type_id','spec','spare_subcate_id','price','contract','start_date','end_date','warranty_duration_days','warranty_condition','user_id','manufacturer_company_id','supplier_company_id','maintainer_company_id','production_year','location_department_id','location_details','first_register','active','equipment_id','updated_by'];
                $cols=[]; $placeholders=[]; $values=[];
                foreach($spareFields as $f){$cols[]=$f; $placeholders[]=":$f"; $values[":$f"]=$spare[$f] ?? null;}
                $cols[]='updated_at'; $placeholders[]='NOW()';
                $sql="INSERT INTO spare_parts (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
                $stmt=$dbh->prepare($sql);
                foreach($values as $k=>$v) $stmt->bindValue($k,$v);
                $stmt->execute();
            }
        }
    }

    // Update/Insert Files
    if(!empty($input['files']) && is_array($input['files'])){
        foreach($input['files'] as $file){
            $file['equipment_id']=$equipment_id;
            $file['equip_type_name']=$file['equip_type_name']??null;
            $file['file_equip_name']=$file['file_equip_name']??null;
            $file['equip_url']=$file['equip_url']??null;

            if(!empty($file['file_equip_id'])){
                $file_id=$file['file_equip_id'];
                unset($file['file_equip_id']);
                $set=[]; $p=[':file_equip_id'=>$file_id];
                foreach($file as $k=>$v){$set[]="$k=:$k"; $p[":$k"]=$v;}
                $set[]="updated_at=NOW()";
                $sql="UPDATE file_equip SET ".implode(',',$set)." WHERE file_equip_id=:file_equip_id";
                $stmt=$dbh->prepare($sql);
                $stmt->execute($p);
            } else {
                $fileFields=['file_equip_name','equip_url','equip_type_name','equipment_id'];
                $cols=[]; $placeholders=[]; $values=[];
                foreach($fileFields as $f){$cols[]=$f; $placeholders[]=":$f"; $values[":$f"]=$file[$f] ?? null;}
                $cols[]='updated_at'; $placeholders[]='NOW()';
                $sql="INSERT INTO file_equip (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
                $stmt=$dbh->prepare($sql);
                foreach($values as $k=>$v) $stmt->bindValue($k,$v);
                $stmt->execute();
            }
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Update successfully","equipment_id"=>$equipment_id]);

}catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
