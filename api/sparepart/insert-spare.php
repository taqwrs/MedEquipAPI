<?php
include "../config/jwt.php";

$isJsonRequest = (strpos(strtolower(getenv("CONTENT_TYPE")), 'application/json') !== false);
$input = $isJsonRequest ? json_decode(file_get_contents('php://input'), true) : $_POST;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"POST method only"]); exit;
}

try {
    $dbh->beginTransaction();
    $userId = $decoded->user_id ?? null;

    // ------------------ CONFIG ------------------
    $requiredFields = ['name','asset_code'];
    $foreignKeys = [
        'manufacturer_company_id'=>['companies','company_id'],
        'supplier_company_id'=>['companies','company_id'],
        'maintainer_company_id'=>['companies','company_id'],
        'location_department_id'=>['departments','department_id'],
        'spare_subcategory_id'=>['spare_subcategories','spare_subcategory_id'],
        'import_type_id'=>['import_types','import_type_id']
    ];
    $allFields = [
    'name','brand','asset_code','model','serial_number','spec',
    'import_type_id','spare_subcategory_id','location_department_id','location_details',
    'production_year','price','contract','start_date','end_date','warranty_duration_days',
    'warranty_condition','maintainer_company_id','supplier_company_id','manufacturer_company_id',
    'record_status','details','status','active','user_id','updated_by','first_register'
];

    // ------------------ CHECK REQUIRED ------------------
    foreach($requiredFields as $f){
        if(empty($input[$f])) throw new Exception("Missing field: $f");
    }

    // ------------------ CHECK FK ------------------
    foreach($foreignKeys as $field=>$check){
        if(isset($input[$field])){
            $stmt=$dbh->prepare("SELECT COUNT(*) FROM {$check[0]} WHERE {$check[1]}=:val");
            $stmt->execute([':val'=>$input[$field]]);
            if($stmt->fetchColumn()==0) throw new Exception("Invalid FK: $field");
        }
    }

    // ------------------ CALCULATE WARRANTY ------------------
    if(!empty($input['start_date']) && !empty($input['end_date'])){
        $start=new DateTime($input['start_date']);
        $end=new DateTime($input['end_date']);
        $input['warranty_duration_days']=$start->diff($end)->days;
    } else $input['warranty_duration_days']=null;

    // ------------------ DEFAULTS ------------------
    $validStatuses=['draft','complete'];
    if(empty($input['record_status'])) $input['record_status']='complete';
    elseif(!in_array($input['record_status'],$validStatuses)) throw new Exception("Invalid record_status");

    // ------------------ BUILD INSERT ------------------
    $cols=[]; $placeholders=[]; $values=[];
    foreach($allFields as $f){
        if($f==='first_register'){ $cols[]=$f; $placeholders[]='NOW()'; continue;}
        $cols[]=$f; $placeholders[]=":$f";
        if($f==='active' && !isset($input['active'])) $values[":$f"]=1;
        elseif($f==='status' && !isset($input['status'])) $values[":$f"]='ใช้งาน';
        elseif($f==='user_id'||$f==='updated_by') $values[":$f"]=$input[$f]??$userId;
        else $values[":$f"]=$input[$f]??null;
    }
    $cols[]='updated_at'; $placeholders[]='NOW()';

    $sql="INSERT INTO spare_parts(".implode(',',$cols).") VALUES(".implode(',',$placeholders).")";
    $stmt=$dbh->prepare($sql);
    foreach($values as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $spareId = $dbh->lastInsertId();

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Spare part inserted","id"=>$spareId,"record_status"=>$input['record_status']]);
}
catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
