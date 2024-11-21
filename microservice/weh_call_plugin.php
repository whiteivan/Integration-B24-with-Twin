<?php
$start = microtime(true);
error_reporting(E_ALL);
ini_set("display_errors", 1);
require_once __DIR__ . '/../../vault/vault.php';
require_once __DIR__ . '/libs/tlogger.php';
require_once __DIR__ . '/libs/twin_api.php';
require_once __DIR__ . '/libs/b24_api_v1.php';
header('Content-Type: application/json');

$src_id = 1;
$log_trace_id = 'wcr-' . time() . '-' . rand(100, 999);
$log_name = 'weh_call_plugin';
$log_dir = __DIR__ . '/logs/weh/b24/';

tlogger($log_trace_id, 'start', "i", $log_name, $log_dir);

$request_body = file_get_contents('php://input');
$request_body_arr = json_decode($request_body, true);
$request_body_json = json_encode($request_body_arr, JSON_UNESCAPED_UNICODE);
$request_body_path = __DIR__ . '/logs/weh/b24/rq_body_' . date('Y-m-d') . "_" . $log_trace_id . ".json";
file_put_contents($request_body_path, $request_body_json, FILE_APPEND);
$headers = getallheaders();
$token = 'Bearer ' . CALLER_TOKEN;


$errors = [];
$status = true;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('HTTP/1.0 200 OK');
    exit;
}

foreach ($headers as $key => $value) {
    $lower_key = strtolower($key);
    if ($lower_key === 'authorization') {
        $user_token = $value;
        break;
    }
}

if (isset($user_token) && $user_token == $token) {
    $status = true;
    $user_id = $request_body_arr["user_id"];
    $responsible_id = $request_body_arr["responsible_id"];
    $company = $request_body_arr["company"];
    $company_id = $request_body_arr["company_id"];
    $phones = $request_body_arr["phones"];
    $log_msg = "успешная авторизация " . print_r($request_body_arr,true);
    tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
} else {
    $log_msg = "Ошибка авторизации";
    tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
    $status = false;
    $errors[] = 'Ошибка авторизации';
    header("HTTP/1.1 401 Unauthorized");
}
$results = [];
tlogger($log_trace_id, $status, "i", $log_name, $log_dir);
if ($status) {
    $db = mysqli_connect(CALLER_DB_HOST, CALLER_DB_USER, CALLER_DB_PASSWORD, CALLER_DB_NAME);
    if (!$db) {
        $log_msg = "mysql: Невозможно установить соединение с DB_HOST error_code " . mysqli_connect_errno() . " error_message " . mysqli_connect_error();
        tlogger($log_trace_id, $log_msg, "f", $log_name, $log_dir);
        exit;
    }
    $i = 0;
    foreach ($phones as $phone_with_action) {
        ++$i;
        $phone = $phone_with_action['phone'];
        $action = $phone_with_action['action_id'];
        switch ($action) {
            case '1':
                $select_sql = "select ctq_call_task_id from call_task_queue where ctq_status = 'in_progress' and ctq_call_company_id = '{$company_id}'";
                $select_sql_query = mysqli_query($db, $select_sql);
                if (mysqli_num_rows($select_sql_query) != 0) {
                    $select_sql = "select ctq_status from call_task_queue where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                    $select_sql_query = mysqli_query($db, $select_sql);
                    if (mysqli_num_rows($select_sql_query) != 0) {
                        $row = mysqli_fetch_assoc($select_sql_query);
                        switch ($row['ctq_status']) {
                            case 'in_pause':
                                $update_sql = "update call_task_queue set ctq_status = 'in_progress', ctq_is_ready_to_call = 4, ctq_status_updated_at = now() where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                                $update_sql_query = mysqli_query($db, $update_sql);
                                $log_msg = "$phone возобновлен с паузы";
                                tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                                break;
                            case 'block':
                                $status = false;
                                $log_msg = "$phone заблокирован для обзвона";
                                tlogger($log_trace_id, $log_msg, "w", $log_name, $log_dir);
                                break 2;
                            default:
                                $update_sql = "update call_task_queue set ctq_status = 'in_queue', ctq_status_updated_at = now() where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                                $update_sql_query = mysqli_query($db, $update_sql);
                                break;
                        }
                    } else {
                        $insert_sql = "INSERT INTO call_task_queue (src_id, ctq_started_at, ctq_task_type, ctq_task_type_id, ctq_call_company_id,
                             ctq_call_company, ctq_call_phone, ctq_user_id, ctq_company_responsible_user_id, ctq_status,
                             ctq_status_updated_at,ctq_is_ready_to_call) VALUES ('{$src_id}', NOW(), 'call', 1, '{$company_id}', '{$company}', '{$phone}', '{$user_id}', '{$responsible_id}', 'in_queue', NOW(),0)";
                        $insert_sql_query = mysqli_query($db, $insert_sql);
                        $log_msg = "mysql: $phone будет запущен в очередь,в базе такого телефона не было";
                        tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                    }
                } else {
                    $select_sql = "select ctq_status from call_task_queue where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                    $select_sql_query = mysqli_query($db, $select_sql);
                    if (mysqli_num_rows($select_sql_query) != 0) {
                        $row = mysqli_fetch_assoc($select_sql_query);
                        switch ($row['ctq_status']) {
                            case 'in_pause':
                                $update_sql = "update call_task_queue set ctq_status = 'in_progress', ctq_is_ready_to_call = 4, ctq_status_updated_at = now() where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                                $update_sql_query = mysqli_query($db, $update_sql);
                                $log_msg = "$phone возобновлен с паузы";
                                tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                                break;
                            case 'block':
                                $status = false;
                                $log_msg = "$phone заблокирован для обзвона";
                                tlogger($log_trace_id, $log_msg, "w", $log_name, $log_dir);
                                //exit;//todo прописать нормальный выход и отдачу ответа
                                break 2;
                            default:
                                $update_sql = "update call_task_queue set ctq_status = 'in_progress', ctq_is_ready_to_call = 1, ctq_status_updated_at = now() where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                                $update_sql_query = mysqli_query($db, $update_sql);
                                $log_msg = "mysql: $phone будет запущен в обзвон немедленно, в базе такой телефон уже есть";
                                tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                                break 2;
                        }
                    } else {
                        $insert_sql = "INSERT INTO call_task_queue (src_id, ctq_started_at, ctq_task_type, ctq_task_type_id, ctq_call_company_id,
                             ctq_call_company, ctq_call_phone, ctq_user_id, ctq_company_responsible_user_id, ctq_status,
                             ctq_status_updated_at,ctq_is_ready_to_call) VALUES ('{$src_id}', NOW(), 'call', 1, '{$company_id}', '{$company}', '{$phone}', '{$user_id}', '{$responsible_id}', 'in_progress', NOW(),1)";
                        $insert_sql_query = mysqli_query($db, $insert_sql);
                        $log_msg = "mysql: $phone будет запущен в обзвон немедленно,в базе такого телефона не было";
                        tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                        break;
                    }
                }
                break;
            case '2':
                $select_sql = "select ctq_status,ctq_call_task_id,ctq_is_ready_to_call from call_task_queue where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                $select_sql_query = mysqli_query($db, $select_sql);
                $row = mysqli_fetch_assoc($select_sql_query);
                if (($row['ctq_status'] == 'in_progress' || $row['ctq_status'] == 'in_pause')  && $row['ctq_is_ready_to_call'] != 5) {
                    $call_task_id = $row['ctq_call_task_id'];
                    $update_sql = "update call_task_queue set ctq_status = 'block', ctq_is_ready_to_call = 2, ctq_status_updated_at = now() where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                    $update_sql_query = mysqli_query($db, $update_sql);
                    $log_msg = "номер {$phone} удален из обзвона и заблокирован";
                    tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                    $select_sql = "select ctq_call_phone from call_task_queue where ctq_call_company_id = '{$company_id}' and ctq_status = 'in_queue' order by ctq_status_updated_at desc limit 1";
                    $select_sql_query = mysqli_query($db, $select_sql);
                    if (mysqli_num_rows($select_sql_query)) {
                        $row = mysqli_fetch_assoc($select_sql_query);
                        $update_sql = "update call_task_queue set ctq_status = 'in_progress',ctq_is_ready_to_call = 5,ctq_call_task_id = '{$call_task_id}',ctq_status_updated_at = now() where ctq_call_phone = '{$row['ctq_call_phone']}' and ctq_call_company_id = '{$company_id}'";
                        $update_sql_query = mysqli_query($db, $update_sql);
                        $log_msg = "номер {$row['ctq_call_phone']} добавлен в обзвон";
                        tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                    }
                } elseif ($row['ctq_status'] == 'in_queue') {
                    $update_sql = "update call_task_queue set ctq_status = 'ready_to_call', ctq_is_ready_to_call = 0,ctq_call_task_id = null, ctq_status_updated_at = now() where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                    $update_sql_query = mysqli_query($db, $update_sql);
                    $log_msg = "номер {$phone} удален из очереди и готов к обзвону";
                    tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                } elseif ($row["ctq_is_ready_to_call"] == 5) {
                    $update_sql = "update call_task_queue set ctq_status = 'ready_to_call', ctq_is_ready_to_call = 0,ctq_call_task_id = null, ctq_status_updated_at = now() where ctq_call_phone = '{$phone}' and ctq_call_company_id = '{$company_id}'";
                    $update_sql_query = mysqli_query($db, $update_sql);
                    $log_msg = "номер {$phone} удален из очереди и готов к обзвону";
                    tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                }
                break;
            case '3':
                $update_sql = "update call_task_queue set ctq_status = 'in_pause', ctq_is_ready_to_call = 3, ctq_status_updated_at = now() where ctq_status = 'in_progress' and ctq_call_company_id = '{$company_id}'";
                $update_sql_query = mysqli_query($db, $update_sql);
                $log_msg = "номер {$phone} поставлен на паузу";
                tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                break 2;
        }
    }
    //twin logic
    // $status = false;//todo test data, remove after
    $select_sql = "select ctq_is_ready_to_call,ctq_call_phone,ctq_call_task_id,ctq_id,ctq_call_company from call_task_queue where ctq_is_ready_to_call != 0 and ctq_call_company_id = '{$company_id}'";
    $select_sql_query = mysqli_query($db, $select_sql);
    $twin_token_read_from_json_response = twin_token_read_from_json($src_id);
    if ($twin_token_read_from_json_response['f_status'] && !empty($twin_token_read_from_json_response['f_result']['token']) && $status) {
        $twin_access_token_arr['token'] = $twin_token_read_from_json_response['f_result']['token'];
        while ($rows = mysqli_fetch_assoc($select_sql_query)) {
            switch ($rows['ctq_is_ready_to_call']) {
                case 1:
                    $call_data['name'] = "Обзвон частный СПЗ $company_id";
                    $call_data['defaultExec'] = 'robot';
                    $call_data['defaultExecData'] = TWIN_SPZ_BOT_ID;
                    $call_data['secondExec'] = 'ignore';
                    $call_data['cidType'] = 'gornum';
                    $call_data['cidData'] = '3261eb5b-43d5-4819-97b0-521aaddcdff5';
                    $call_data['startType'] = 'manual';
                    $call_data['cps'] = 0.96;
                    $call_data['webhookUrls'][0] = 'https://link/weh_call_twin';
                    $call_data['additionalOptions']['fullListMethod'] = 'reject';
                    $call_data['additionalOptions']['fullListTime'] = 0;
                    $call_data['additionalOptions']['useTr'] = true; // ограничение по времени
                    $call_data['additionalOptions']['allowCallTimeFrom'] = 32400;
                    $call_data['additionalOptions']['allowCallTimeTo'] = 64800;
                    $call_data['additionalOptions']['recordCall'] = true;
                    $call_data['additionalOptions']['recTrimLeft'] = 0;
                    $call_data['additionalOptions']['detectRobot'] = false;
                    $call_data['redialStrategyOptions']['redialStrategyEn'] = true;
                    $call_data['redialStrategyOptions']['busy']['redial'] = true;
                    $call_data['redialStrategyOptions']['busy']['time'] = 1200;
                    $call_data['redialStrategyOptions']['busy']['count'] = 3;
                    $call_data['redialStrategyOptions']['noAnswer']['redial'] = true;
                    $call_data['redialStrategyOptions']['noAnswer']['time'] = 1200;
                    $call_data['redialStrategyOptions']['noAnswer']['count'] = 3;
                    $call_data['redialStrategyOptions']['answerMash']['redial'] = false;
                    $call_data['redialStrategyOptions']['congestion']['redial'] = true;
                    $call_data['redialStrategyOptions']['congestion']['time'] = 900;
                    $call_data['redialStrategyOptions']['congestion']['count'] = 2;
                    $call_data['redialStrategyOptions']['answerNoList']['redial'] = false;
                    $create_twin_auto_call_response = create_twin_auto_call($twin_access_token_arr, $call_data);
                    if ($create_twin_auto_call_response['f_status']) {
                        $auto_call_id = $create_twin_auto_call_response['f_result']['id']['identity'];
                        $log_msg = "company_id $company_id auto_call created $auto_call_id";
                        tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                        $status = true;
                        $update_sql = "update call_task_queue set ctq_call_task_id = '{$auto_call_id}', ctq_is_ready_to_call = 0 where ctq_status = 'in_progress' and ctq_call_company_id = '{$company_id}'";//TODO стоит ли сейчас обновлять ctq_is_ready_to_call
                        $update_sql_query = mysqli_query($db, $update_sql);
                    } else {
                        $log_msg = "company_id $company_id auto_call error";
                        tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
                        $status = false;
                    }

                    if ($status) {
                        $candidate = [];
                        $select_sql = "select ctq_id, ctq_call_phone,ctq_call_company,ctq_call_task_id,ctq_call_company_id from call_task_queue where ctq_call_company_id = '{$company_id}' and ctq_status = 'in_progress'";
                        $select_sql_query = mysqli_query($db, $select_sql);
                        $row = mysqli_fetch_assoc($select_sql_query);

                        $token_read_from_vault_response = token_read_from_vault();
                        $b24_access_token_arr = $token_read_from_vault_response['f_result'];

                        $params = [];
                        $fields = [];
                        $task_description = 'Предложить МО';
                        $task_subject = "Обзвон СПЗ";
                        $task_start_time = time();
                        $task_end_time = time() + 60 * 30;
                        $task_responsible_id = 6870;

                        $fields['OWNER_TYPE_ID'] = 4;
                        $fields['OWNER_ID'] = $row['ctq_call_company_id'];
                        $fields['DESCRIPTION'] = $task_description;
                        $fields['SUBJECT'] = $task_subject;
                        $fields['COMMUNICATIONS'][] = array("ENTITY_TYPE_ID" => 4, "ENTITY_ID" => $row['ctq_call_company_id'], "VALUE" => $row['ctq_call_phone']);
                        $fields['START_TIME'] = date("Y-m-dTH:i:s", $task_start_time);
                        $fields['END_TIME'] = date("Y-m-dTH:i:s", $task_end_time);
                        $fields['RESPONSIBLE_ID'] = $task_responsible_id;
                        $fields['AUTHOR_ID'] = $task_responsible_id;
                        $fields['TYPE_ID'] = 2;
                        $fields['DIRECTION'] = 2;
                        $params['fields'] = $fields;
                        $activity_add_response = activity_add($b24_access_token_arr, $params);

                        if ($activity_add_response['f_status']) {
                            $activity_add_result = $activity_add_response['f_result'];
                            if (!empty($activity_add_result['result'])) {
                                $ctq_b24_task_id = $activity_add_result['result'];
                            } else {
                                $log_msg = "activity_add: no result";
                                tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
                                $status = false;
                                $error = "activity_add: no result";
                            }

                        } else {
                            $log_error_description = print_r($activity_add_response['f_error_description'], true);
                            $log_msg = "activity_add f_error:{$activity_add_response['f_error']} f_error_description:\r\n{$log_error_description}";
                            tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
                            $status = false;
                            $error = "activity_add: f_error";
                        }

                        $update_sql = "UPDATE call_task_queue SET  ctq_b24_task_id = {$ctq_b24_task_id} WHERE ctq_id = {$row['ctq_id']};";
                        $update_sql_query = mysqli_query($db, $update_sql);

                        $candidate = [];
                        $candidate['phone'][] = (string)$row['ctq_call_phone'];
                        $candidate['variables']['kompany'] = $row['ctq_call_company'];
                        $candidate['variables']['kompanyId'] = (string)$company_id;
                        $candidate['variables']['fast_start'] = (string)1;
                        $candidate['callbackData'][0]['kompanyId'] =(string) $company_id;
                        $candidate['callbackData'][0]['ctq_id'] = (string)$row['ctq_id'];
                        $candidate['callbackData'][0]['b24TaskID'] = (string)$ctq_b24_task_id;

                        $candidate['autoCallId'] = (string)$row['ctq_call_task_id'];
                        //$candidates[0]['batch'][] = $candidate;
                        $candidates['batch'][] = $candidate;
                        $candidates['forceStart'] = true;
                        $add_twin_auto_call_candidates_response = add_twin_auto_call_candidates($twin_access_token_arr, $candidates);
                        $log_msg = print_r($add_twin_auto_call_candidates_response,true);
                        tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                        $log_msg = "phone '{$rows['ctq_call_phone']}' was added in task '{$row['ctq_call_task_id']}'";
                        tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                    }

                    break;
                case 2:
                    $data ['auto_call_id'] = $rows['ctq_call_task_id'];
                    $data['phone'] = $rows['ctq_call_phone'];
                    twin_delete_candidate($twin_access_token_arr, $data);
                    $update_sql = "update call_task_queue set ctq_call_task_id = '{$data['auto_call_id']}' where ctq_status = 'in_progress' and ctq_call_company = '{$company_id}'";
                    $update_sql_query = mysqli_query($db, $update_sql);
                    $update_sql = "update call_task_queue set ctq_is_ready_to_call = 0, ctq_call_task_id = null where ctq_id = '{$rows['ctq_id']}'";
                    $update_sql_query = mysqli_query($db, $update_sql);
                    break;
                case 3:
                    $data ['auto_call_id'] = $rows['ctq_call_task_id'];
                    twin_auto_call_pause($twin_access_token_arr, $data);
                    $update_sql = "update call_task_queue set ctq_is_ready_to_call = 0 where ctq_id = '{$rows['ctq_id']}'";
                    $update_sql_query = mysqli_query($db, $update_sql);
                    break;
                case 4:
                    $data ['auto_call_id'] = $rows['ctq_call_task_id'];
                    twin_auto_call_start($twin_access_token_arr, $data);
                    $update_sql = "update call_task_queue set ctq_is_ready_to_call = 0 where ctq_id = '{$rows['ctq_id']}'";
                    $update_sql_query = mysqli_query($db, $update_sql);
                    break;
                case 5:
                    $token_read_from_vault_response = token_read_from_vault();
                    $b24_access_token_arr = $token_read_from_vault_response['f_result'];

                    $params = [];
                    $fields = [];
                    $task_description = 'Предложить МО';
                    $task_subject = "Обзвон СПЗ";
                    $task_start_time = time();
                    $task_end_time = time() + 60 * 30;
                    $task_responsible_id = 6870;

                    $fields['OWNER_TYPE_ID'] = 4;
                    $fields['OWNER_ID'] = $company_id;
                    $fields['DESCRIPTION'] = $task_description;
                    $fields['SUBJECT'] = $task_subject;
                    $fields['COMMUNICATIONS'][] = array("ENTITY_TYPE_ID" => 4, "ENTITY_ID" => $company_id, "VALUE" => $rows['ctq_call_phone']);
                    $fields['START_TIME'] = date("Y-m-dTH:i:s", $task_start_time);
                    $fields['END_TIME'] = date("Y-m-dTH:i:s", $task_end_time);
                    $fields['RESPONSIBLE_ID'] = $task_responsible_id;
                    $fields['AUTHOR_ID'] = $task_responsible_id;
                    $fields['TYPE_ID'] = 2;
                    $fields['DIRECTION'] = 2;
                    $params['fields'] = $fields;
                    $activity_add_response = activity_add($b24_access_token_arr, $params);

                    if ($activity_add_response['f_status']) {
                        $activity_add_result = $activity_add_response['f_result'];
                        if (!empty($activity_add_result['result'])) {
                            $ctq_b24_task_id = $activity_add_result['result'];
                        } else {
                            $log_msg = "activity_add: no result";
                            tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
                            $status = false;
                            $error = "activity_add: no result";
                        }

                    } else {
                        $log_error_description = print_r($activity_add_response['f_error_description'], true);
                        $log_msg = "activity_add f_error:{$activity_add_response['f_error']} f_error_description:\r\n{$log_error_description}";
                        tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
                        $status = false;
                        $error = "activity_add: f_error";
                    }
                    if ($status) {
                        $update_sql = "UPDATE call_task_queue SET  ctq_b24_task_id = {$ctq_b24_task_id} WHERE ctq_id = {$rows['ctq_id']};";
                        $update_sql_query = mysqli_query($db, $update_sql);

                        $candidate = [];
                        $candidate['phone'][] = $rows['ctq_call_phone'];
                        $candidate['variables']['kompany'] = $rows['ctq_call_company'];
                        $candidate['variables']['kompanyId'] = $company_id;
                        $candidate['callbackData'][0]['kompanyId'] = $company_id;
                        $candidate['callbackData'][0]['ctq_id'] = $rows['ctq_id'];
                        $candidate['callbackData'][0]['b24_task_id'] = $ctq_b24_task_id;

                        $candidate['autoCallId'] = $rows['ctq_call_task_id'];
                        $candidates['batch'][] = $candidate;
                        $candidates['forceStart'] = true;
                        $add_twin_auto_call_candidates_response = add_twin_auto_call_candidates($twin_access_token_arr, $candidates);
                        $update_sql = "update call_task_queue set ctq_is_ready_to_call = 0 where ctq_id = '{$rows['ctq_id']}'";
                        $update_sql_query = mysqli_query($db, $update_sql);
                    }
                    break;
            }
        }
    }
}

//$status = true;
// $update_sql = "update call_task_queue set ctq_is_ready_to_call = 0";
// $update_sql_query = mysqli_query($db, $update_sql);
if ($status) {
    if ($i > 1) {
        $select_sql = "select ctq_call_phone,ctq_status from call_task_queue where ctq_call_company_id = '{$company_id}'";
        $select_sql_query = mysqli_query($db, $select_sql);
        $results = [];
        while ($row = mysqli_fetch_assoc($select_sql_query)) {
            $results[] = [
                'phone' => $row['ctq_call_phone'],
                'status' => $row['ctq_status']
            ];
        }
    } else {
        $select_sql = "select ctq_call_phone,ctq_status from call_task_queue where ctq_call_company_id = '{$company_id}' order by ctq_status_updated_at desc limit 1 ";
        $select_sql_query = mysqli_query($db, $select_sql);
        $row = mysqli_fetch_assoc($select_sql_query);
        $results[] = [
            'phone' => $row['ctq_call_phone'],
            'status' => $row['ctq_status']
        ];
    }
}
if ($status) {
    $ep_response['status'] = true;
    $ep_response['phones'] = $results;

} else {
    $ep_response['status'] = false;
    $ep_response['errors'] = $errors;
}

//header('Access-Control-Allow-Origin: *');
echo json_encode($ep_response, JSON_UNESCAPED_UNICODE);

$finish = microtime(true);
$delta = $finish - $start;
if ($delta > 10) {
    tlogger($log_trace_id, 'done with overload, execution time: ' . $delta, "w", $log_name, $log_dir);
} else {
    tlogger($log_trace_id, 'done, execution time: ' . $delta, "i", $log_name, $log_dir);
}