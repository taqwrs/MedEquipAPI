<?php
class LogModel {
    private $dbh;
    private $defaultLogTable = 'transaction_logs';

    public function __construct($dbh) {
        $this->dbh = $dbh;
    }

    /**
     * @param string $user_id
     * @param string $table - ตารางหลักที่มีการกระทำ
     * @param string $action - INSERT/UPDATE/DELETE
     * @param array|null $oldData
     * @param array|null $newData
     * @param string|null $logTable - ตารางที่จะเก็บ log (ถ้าไม่กำหนดใช้ default)
     */
    public function insertLog($user_id, $table, $action, $oldData = null, $newData = null, $logTable = null) {
        $logTable = $logTable ?? $this->defaultLogTable;

        $sql = "INSERT INTO {$logTable} (user_id, table_name, action, old_data, new_data, created_at)
                VALUES (:user_id, :table_name, :action, :old_data, :new_data, NOW())";
        $stmt = $this->dbh->prepare($sql);
        $stmt->execute([
            ':user_id' => $user_id,
            ':table_name' => $table,
            ':action' => $action,
            ':old_data' => $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
            ':new_data' => $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null
        ]);
    }

    /**
     * คืนค่าเฉพาะ field ที่มีการเปลี่ยนแปลง
     * @param array|null $oldData
     * @param array|null $newData
     * @return array|null
     */
    public function filterChangedFields($oldData, $newData) {
        if (!$oldData || !$newData) return $newData;

        $changed = [];
        foreach ($newData as $key => $value) {
            // ถ้า oldData ไม่มี field นี้ หรือ value แตกต่างกัน → เก็บ
            if (!array_key_exists($key, $oldData) || $oldData[$key] !== $value) {
                $changed[$key] = $value;
            }
        }
        return !empty($changed) ? $changed : null;
    }
}
