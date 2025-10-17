<?php
include "../config/jwt.php";
include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id)
        throw new Exception("User ID not found");

    $ma_result_id = $_POST['ma_result_id'] ?? null;
    if (!$ma_result_id)
        throw new Exception("ma_result_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_ma_result/";
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0777, true);

    $files = $_FILES['file_ma_result'] ?? null;

    // ------------------ Upload จากเครื่อง ------------------
    if ($files && $files['name'][0] !== "") {
        if (!is_array($files['name'])) {
            $files = [
                'name' => [$files['name']],
                'type' => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'error' => [$files['error']],
                'size' => [$files['size']],
            ];
        }

        foreach ($files['name'] as $key => $name) {
            try {
                if ($files['error'][$key] !== UPLOAD_ERR_OK) continue;

                $tmp = $files['tmp_name'][$key];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp','pdf','docx'];
                if (!in_array($ext,$allowed)) continue;

                $newName = uniqid('MaResult_',true).'.'.$ext;
                $targetFile = $uploadDir.$newName;
                if (!move_uploaded_file($tmp,$targetFile)) continue;

                $url = "/file-upload/file_ma_result/$newName";
                $typeName = $_POST['ma_result_type_name'][$key] ?? "ไม่ระบุ";

                // Insert DB
                $stmt = $dbh->prepare("
                    INSERT INTO file_ma_result(ma_result_id,file_ma_name,file_ma_url,ma_type_name,upload_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$ma_result_id,$name,$url,$typeName]);

                // Log auto-generate จาก array
                $log->insertLog($user_id,'file_ma_result','INSERT',null,[
                    'ma_result_id'=>$ma_result_id,
                    'file_name'=>$name,
                    'file_url'=>$url,
                    'type'=>$typeName
                ]);

                $uploadedFiles[] = [
                    "name"=>$name,
                    "saved"=>$newName,
                    "url"=>$url,
                    "type"=>$typeName
                ];
            } catch (Exception $e) {
                continue;
            }
        }
    }

    // ------------------ เพิ่มจาก URL ------------------
    if (!empty($_POST['ma_result_url'])) {
        $urls = is_array($_POST['ma_result_url']) ? $_POST['ma_result_url'] : [$_POST['ma_result_url']];
        $fileCount = count($uploadedFiles);

        foreach ($urls as $key => $url) {
            try {
                if (!filter_var($url,FILTER_VALIDATE_URL)) continue;

                $customName = $_POST['ma_result_file_name'][$key] ?? "MaResult_".$ma_result_id."_".($key+1);
                $typeName = $_POST['ma_result_type_name'][$key+$fileCount] ?? "ลิงก์";

                $stmt = $dbh->prepare("
                    INSERT INTO file_ma_result(ma_result_id,file_ma_name,file_ma_url,ma_type_name,upload_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$ma_result_id,$customName,$url,$typeName]);

                $log->insertLog($user_id,'file_ma_result','INSERT',null,[
                    'ma_result_id'=>$ma_result_id,
                    'file_name'=>$customName,
                    'file_url'=>$url,
                    'type'=>$typeName
                ]);

                $uploadedFiles[] = [
                    "name"=>$customName,
                    "saved"=>null,
                    "url"=>$url,
                    "type"=>$typeName
                ];
            } catch (Exception $e) {
                continue;
            }
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","files"=>$uploadedFiles], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
