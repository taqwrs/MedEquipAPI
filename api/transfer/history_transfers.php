<?php
/**
 * history_transfers.php
 * ฟังก์ชันสำหรับบันทึกประวัติการโอนย้ายเครื่องมือแพทย์
 * ต้องส่ง PDO object $dbh และ array ข้อมูล $data
 * ไม่ต้อง include jwt.php ในไฟล์นี้
 */

/**
 * insertHistory
 * บันทึกข้อมูลลงตาราง equipment_transfers_history
 *
 * @param PDO $dbh - PDO connection object
 * @param array $data - ข้อมูลสำหรับ insert
 *      keys: transfer_id, transfer_type, equipment_id, from_department_id,
 *            to_department_id, transfer_date, install_location_dep_id,
 *            reason, transfer_user_id, recipient_user_id, relation_group_id,
 *            detail_trans
 */
function insertHistory($dbh, $data) {
    $query = "INSERT INTO history_equipment_transfers 
                (transfer_id, transfer_type, equipment_id, from_department_id, to_department_id, transfer_date, install_location_dep_id, reason, transfer_user_id, recipient_user_id, relation_group_id, detail_trans)
              VALUES 
                (:transfer_id, :transfer_type, :equipment_id, :from_department_id, :to_department_id, :transfer_date, :install_location_dep_id, :reason, :transfer_user_id, :recipient_user_id, :relation_group_id, :detail_trans)";
    
    $stmt = $dbh->prepare($query);
    $stmt->execute([
        ':transfer_id' => $data['transfer_id'],
        ':transfer_type' => $data['transfer_type'],
        ':equipment_id' => $data['equipment_id'],
        ':from_department_id' => $data['from_department_id'],
        ':to_department_id' => $data['to_department_id'],
        ':transfer_date' => $data['transfer_date'],
        ':install_location_dep_id' => $data['install_location_dep_id'],
        ':reason' => $data['reason'],
        ':transfer_user_id' => $data['transfer_user_id'],
        ':recipient_user_id' => $data['recipient_user_id'],
        ':relation_group_id' => $data['relation_group_id'],
        ':detail_trans' => $data['detail_trans']
    ]);
}
?>