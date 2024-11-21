<?php
$start = microtime(true);
error_reporting(E_ALL);
ini_set("display_errors", 1);
require_once __DIR__ . '/../../vault/vault.php';
require_once __DIR__ . '/libs/tlogger.php';
//test
$rand = rand(100, 999);
$log_trace_id = 'wcr-' . time() . '-' . $rand;
$log_name = 'weh_call_info_' . $rand;
$log_dir = __DIR__ . '/logs/weh/';

tlogger($log_trace_id, 'start', "i", $log_name, $log_dir);

$request_body = file_get_contents('php://input');
$request_body_arr = json_decode($request_body, true);
$request_body_json = json_encode($request_body_arr, JSON_UNESCAPED_UNICODE);
$request_body_path = __DIR__ . '/logs/weh/b24/rq_body_' . date('Y-m-d') . "_" . $log_trace_id . ".json";
file_put_contents($request_body_path, $request_body_json, FILE_APPEND);

$errors = [];
$status = true;
//
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('HTTP/1.0 200 OK');
    exit;
}

header('Content-Type: application/json');
$headers = getallheaders();
foreach ($headers as $key => $value) {
    $lower_key = strtolower($key);
    if ($lower_key === 'authorization') {
        $user_token = $value;
        break;
    }
}

// $user_token = $headers['authorization'];
$token = 'Bearer ' . CALLER_TOKEN;
if (!isset($user_token) || $user_token != $token) {
    $log_msg = "wrong token - " . print_r($headers, true);
    tlogger($log_trace_id, $log_msg, "f", $log_name, $log_dir);
    header('HTTP/1.0 401');
    $ep_response['status'] = false;
    $ep_response['error'] = 'wrong auth token';
    echo json_encode($ep_response, JSON_UNESCAPED_UNICODE);
    exit;
}

$db = mysqli_connect(CALLER_DB_HOST, CALLER_DB_USER, CALLER_DB_PASSWORD, CALLER_DB_NAME);

if (!$db) {
    $log_msg = "mysql: Невозможно установить соединение с DB_HOST error_code " . mysqli_connect_errno() . " error_message " . mysqli_connect_error();
    tlogger($log_trace_id, $log_msg, "f", $log_name, $log_dir, true);
    $status = false;
}

$result_arr_phone = [];

if ($status) {
    $company_id = $request_body_arr['company_id'];
    $phones = $request_body_arr['phones'];
    $phone_list = implode("','", array_map(function ($phone) use ($db) {
        return mysqli_real_escape_string($db, $phone);
    }, $phones));
    $company_id = mysqli_real_escape_string($db, $company_id);

    $select_sql = "SELECT ctq_call_phone, ctq_status FROM call_task_queue 
                   WHERE ctq_call_phone IN ('{$phone_list}') 
                   AND ctq_call_company_id = '{$company_id}'";
    $select_sql_query = mysqli_query($db, $select_sql);
    if (!$select_sql_query) {
        $log_msg = "call_task_queue: Ошибка при выгрузке данных";
        $errors[] = 'db error';
        $status = false;
        tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
        $mysqli_error_list = print_r(mysqli_error_list($db), true);
        tlogger($log_trace_id, $mysqli_error_list, "e", $log_name, $log_dir);
    } else {
        if (mysqli_num_rows($select_sql_query) > 0) {
            while ($row = mysqli_fetch_assoc($select_sql_query)) {
                $phone_data = [
                    'phone' => $row['ctq_call_phone'],
                    'status_phone' => $row['ctq_status']
                ];
                $result_arr_phone[$row['ctq_call_phone']] = $phone_data;
                //                $result_arr_phone[$row['ctq_call_phone']] = $row['ctq_status'];
//                $result_arr_phone['phone'] = $row['ctq_call_phone'];
//                $result_arr_phone['status_phone'] = $row['ctq_status'];
            }
        }
        foreach ($phones as $phone) {
            if (!isset($result_arr_phone[$phone])) {
                $phone_data = [
                    'phone' => $phone,
                    'status_phone' => 'ready_to_call'
                ];
                $result_arr_phone[$phone] = $phone_data;
                //                $result_arr_phone['phone'] = $phone;
//                $result_arr_phone['status_phone'] = 'ready_to_call';
            }
        }
        $result_arr_phone = array_values($result_arr_phone);
    }
    if (!empty($result_arr_phone)) {
        $log_arr_phone = print_r($result_arr_phone, true);
        tlogger($log_trace_id, $log_arr_phone, "i", $log_name, $log_dir);
    }
}

if ($status) {
    $ep_response['status'] = true;
    $ep_response['phones'] = $result_arr_phone;
} else {
    $ep_response['status'] = false;
    $ep_response['errors'] = $errors;
}

echo json_encode($ep_response, JSON_UNESCAPED_UNICODE);

$finish = microtime(true);
$delta = $finish - $start;
if ($delta > 10) {
    tlogger($log_trace_id, 'done with overload, execution time: ' . $delta, "w", $log_name, $log_dir);
} else {
    tlogger($log_trace_id, 'done, execution time: ' . $delta, "i", $log_name, $log_dir);
}