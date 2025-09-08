<?php
include "../config/jwt.php";
$input=json_decode(file_get_contents('php://input'),true);
if($_SERVER['REQUEST_METHOD']!=='POST'){
    echo json_encode(["status"=>"error","message"=>"POST only"]);
    exit;
}
if(empty($input['equipment_id'])){
    echo json_encode(["status"=>"error","message"=>"equipment_id required"]);
    exit;
}
$equipment_id=$input['equipment_id'];

$fields=[
    'name','asset_code','serial_number','brand','model',
    'import_type_id','subcategory_id','location_department_id','manufacturer_company_id',
    'supplier_company_id','maintainer_company_id','group_user_id','group_responsible_id',
    'user_id','status','record_status','first_register'
];

$setParts=[]; $params=[];
foreach($fields as $f){
    if(isset($input[$f])){
        $setParts[]="$f=:$f";
        $params[":$f"]=$input[$f];
    }
}
$setParts[]="updated_by=:updated_by";
$setParts[]="updated_at=NOW()";
$params[":updated_by"]=$input['updated_by']??1;
$params[":equipment_id"]=$equipment_id;

if(empty($setParts)){
    echo json_encode(["status"=>"error","message"=>"No valid fields"]);
    exit;
}

try{
    $sql="UPDATE equipments SET ".implode(',',$setParts)." WHERE equipment_id=:equipment_id";
    $stmt=$dbh->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["status"=>"success","message"=>"Update successful","equipment_id"=>$equipment_id]);
}catch(Exception $e){
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
