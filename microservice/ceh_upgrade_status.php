<?php
$start = microtime(true);
error_reporting(E_ALL);
ini_set("display_errors", 1);
require_once __DIR__ . '/../../vault/vault.php';
require_once __DIR__ . '/libs/tlogger.php';

$rand = rand(100,999);
$log_trace_id = 'wcr-' . time() . '-' . $rand;
$log_name = 'ceh_upgrade_status_' . $rand;
$log_dir = __DIR__ . '/logs/ceh/';

$db = mysqli_connect(CALLER_DB_HOST, CALLER_DB_USER, CALLER_DB_PASSWORD, CALLER_DB_NAME);
    if (!$db) {
        $log_msg = "mysql: Невозможно установить соединение с DB_HOST error_code " . mysqli_connect_errno() . " error_message " . mysqli_connect_error();
        tlogger($log_trace_id, $log_msg, "f", $log_name, $log_dir, true);
        exit;
    }

$select_sql = "select ctq_id from call_task_queue where ctq_status_updated_at < now() - interval 7 day and ctq_status = 'block'";
$select_sql_query = mysqli_query($db, $select_sql);
while ($row = mysqli_fetch_assoc($select_sql_query)){
    $update_sql = "update call_task_queue set ctq_status = 'ready_to_call', ctq_status_updated_at = now() where ctq_id = '{$row['ctq_id']}'";
    $update_sql_query = mysqli_query($db, $update_sql);
}

$select_sql = "select ctq_id, ctq_call_company_id from call_task_queue where ctq_status_updated_at < now() - interval 1 day and ctq_status = 'in_pause'";
$select_sql_query = mysqli_query($db, $select_sql);
while ($row = mysqli_fetch_assoc($select_sql_query)){
    $update_sql = "update call_task_queue set ctq_status = 'block', ctq_status_updated_at = now(), ctq_call_task_id = null where ctq_id = '{$row['ctq_id']}'";
    $update_sql_query = mysqli_query($db, $update_sql);
    $update_sql = "update call_task_queue set ctq_status = 'ready_to_call', ctq_status_updated_at = now() where ctq_call_company_id = '{$row['ctq_call_company_id']}' and ctq_status = 'in_queue'";
    $update_sql_query = mysqli_query($db, $update_sql);
}

$finish = microtime(true);
$delta = $finish - $start;
if ($delta > 10) {
    tlogger($log_trace_id, 'done with overload, execution time: ' . $delta, "w", $log_name, $log_dir);
} else {
    tlogger($log_trace_id, 'done, execution time: ' . $delta, "i", $log_name, $log_dir);
}
