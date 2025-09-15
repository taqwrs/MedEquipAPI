<?php
include "../config/jwt.php";
$input=json_decode(file_get_contents('php://input'),true);
$id=(int)($input['equipment_id']??0);
if($_SERVER['REQUEST_METHOD']!=='POST'){
    echo json_encode(["status"=>"error","message"=>"POST only"]);
    exit;
}
if(!$id){
    echo json_encode(["status"=>"error","message"=>"equipment_id required"]);
    exit;
}
try{
    $stmt=$dbh->prepare("DELETE FROM equipments WHERE equipment_id=:id");
    $stmt->bindValue(':id',$id,PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(["status"=>"success","message"=>"Delete successful"]);
}catch(Exception $e){
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
