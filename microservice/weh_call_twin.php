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
$log_name = 'weh_call_twin';
$log_dir = __DIR__ . '/logs/weh/twin/';
$status = false;
tlogger($log_trace_id, 'start', "i", $log_name, $log_dir);

$request_body = file_get_contents('php://input');
$request_body_arr = json_decode($request_body, true);
$request_body_json = json_encode($request_body_arr, JSON_UNESCAPED_UNICODE);
$request_body_path = __DIR__ . '/logs/weh/twin/tw_rq_body_' . date('Y-m-d') . "_" . $log_trace_id . ".json";
file_put_contents($request_body_path, $request_body_json, FILE_APPEND);

if (isset($request_body_arr['event']) && $request_body_arr['event'] == 'CALL_ENDED') {
    $status = true;
    tlogger($log_trace_id, 'event = CALL_ENDED', "i", $log_name, $log_dir);
    if (!empty($request_body_arr['variables']['kompanyId'])) {
        $ctq_id = $request_body_arr['callbackData'][0]['ctq_id'];
        $phone = $request_body_arr['callTo'];
        $company_id = $request_body_arr['variables']['kompanyId'];
        $db = mysqli_connect(CALLER_DB_HOST, CALLER_DB_USER, CALLER_DB_PASSWORD, CALLER_DB_NAME);
        if (!$db) {
            $log_msg = "mysql: Невозможно установить соединение с DB_HOST error_code " . mysqli_connect_errno() . " error_message " . mysqli_connect_error();
            tlogger($log_trace_id, $log_msg, "f", $log_name, $log_dir, true);
            exit;
        }
        $select_sql = "select ctq_status from call_task_queue where ctq_id = '{$ctq_id}'";
        $select_sql_query = mysqli_query($db, $select_sql);
        $row = mysqli_fetch_assoc($select_sql_query);
        if ($row['ctq_status'] == 'block') {
            tlogger($log_trace_id, "that phone ($phone) already block", "i", $log_name, $log_dir);
            $update_sql = "update call_task_queue set ctq_result_from_twin = '{$request_body_json}',ctq_status_updated_at = now() where ctq_id = '{$ctq_id}'";
            $update_sql_query = mysqli_query($db, $update_sql);
        } else {
            tlogger($log_trace_id, "that phone ($phone) is not block ", "i", $log_name, $log_dir);
            try {
                $safe_request_body_json = mysqli_real_escape_string($db, $request_body_json);
                $update_sql = "update call_task_queue set ctq_result_from_twin = '{$safe_request_body_json}' where ctq_id = '{$ctq_id}'";
                $update_sql_query = mysqli_query($db, $update_sql);
                tlogger($log_trace_id, "Обновили информацию из твина телефона $phone", "i", $log_name, $log_dir);
                $update_sql = "update call_task_queue set ctq_status = 'block', ctq_call_task_id = null, ctq_status_updated_at = now() where ctq_id = '{$ctq_id}'";
                $update_sql_query = mysqli_query($db, $update_sql);
                tlogger($log_trace_id, "Обновили статус телефона $phone", "i", $log_name, $log_dir);
            } catch (mysqli_sql_exception $e) {
                tlogger($log_trace_id, "{$e->getMessage()}", "e", $log_name, $log_dir);
            }
        }
        // $update_sql = "update call_task_queue set ctq_result_from_twin = '{$request_body_json}', ctq_status = 'block', ctq_call_task_id = null,ctq_status_updated_at = now() where ctq_id = '{$ctq_id}'";
        // $update_sql_query = mysqli_query($db, $update_sql);
        // tlogger($log_trace_id, "Обновили статус телефона $phone", "i", $log_name, $log_dir);
        if (!strpos($request_body_arr['result']['confirmation'], '$$$')) {
            tlogger($log_trace_id, 'результат не успешный', "i", $log_name, $log_dir);
            $select_sql = "select ctq_call_phone,ctq_id,ctq_call_company from call_task_queue where ctq_call_company_id = '{$company_id}' and ctq_status = 'in_queue' order by ctq_status_updated_at desc limit 1";
            $select_sql_query = mysqli_query($db, $select_sql);
            if (mysqli_num_rows($select_sql_query) != 0) {
                $row = mysqli_fetch_assoc($select_sql_query);
                $twin_token_read_from_json_response = twin_token_read_from_json($src_id);
                if ($twin_token_read_from_json_response['f_status'] && !empty($twin_token_read_from_json_response['f_result']['token'])) {
                    $twin_access_token_arr['token'] = $twin_token_read_from_json_response['f_result']['token'];
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
                    $fields['COMMUNICATIONS'][] = array("ENTITY_TYPE_ID" => 4, "ENTITY_ID" => $company_id, "VALUE" => $row['ctq_call_phone']);
                    $fields['START_TIME'] = date("Y-m-dTH:i:s", $task_start_time);
                    $fields['END_TIME'] = date("Y-m-dTH:i:s", $task_end_time);
                    $fields['RESPONSIBLE_ID'] = $task_responsible_id;
                    $fields['AUTHOR_ID'] = $task_responsible_id;
                    $fields['TYPE_ID'] = 2;
                    $fields['DIRECTION'] = 2;
                    $params['fields'] = $fields;
                    $activity_add_response = activity_add($b24_access_token_arr, $params);
                    tlogger($log_trace_id, "добавили дело на следующий телефон '{$row['ctq_call_phone']}' ", "i", $log_name, $log_dir);

                    if ($activity_add_response['f_status']) {
                        $activity_add_result = $activity_add_response['f_result'];
                        if (!empty($activity_add_result['result'])) {
                            $ctq_b24_task_id = $activity_add_result['result'];
                        } else {
                            $log_msg = "activity_add: no result";
                            tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir, true);
                            $status = false;
                            $error = "activity_add: no result";
                        }

                    } else {
                        $log_error_description = print_r($activity_add_response['f_error_description'], true);
                        $log_msg = "activity_add f_error:{$activity_add_response['f_error']} f_error_description:\r\n{$log_error_description}";
                        tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir, true);
                        $status = false;
                        $error = "activity_add: f_error";
                    }
                    if ($status) {
                        $update_sql = "UPDATE call_task_queue SET  ctq_b24_task_id = {$ctq_b24_task_id} WHERE ctq_id = {$row['ctq_id']};";
                        $update_sql_query = mysqli_query($db, $update_sql);
                        $candidate = [];
                        $candidate['phone'][] = $row['ctq_call_phone'];
                        $candidate['variables']['kompany'] = $row['ctq_call_company'];
                        $candidate['variables']['kompanyId'] = $company_id;
                        $candidate['variables']['fast_start'] = (string) 1;
                        $candidate['callbackData'][0]['kompanyId'] = $company_id;
                        $candidate['callbackData'][0]['ctq_id'] = $row['ctq_id'];
                        $candidate['callbackData'][0]['b24TaskID'] = $ctq_b24_task_id;

                        $candidate['autoCallId'] = $request_body_arr['taskId'];
                        $candidates['batch'][] = $candidate;
                        $add_twin_auto_call_candidates_response = add_twin_auto_call_candidates($twin_access_token_arr, $candidates);
                        $log_msg = "добавили телефон {$row['ctq_call_phone']} в твин     $add_twin_auto_call_candidates_response";
                        tlogger($log_trace_id, $log_msg, "i", $log_name, $log_dir);
                        if ($add_twin_auto_call_candidates_response['f_status']) {
                            $update_sql = "update call_task_queue set ctq_status = 'in_progress', ctq_status_updated_at = now(), ctq_call_task_id = '{$request_body_arr['taskId']}' where ctq_id = '{$row['ctq_id']}'";
                            $update_sql_query = mysqli_query($db, $update_sql);
                            $data['auto_call_id'] = $request_body_arr['taskId'];
                            twin_auto_call_start($twin_access_token_arr, $data);
                        }
                    }
                }
            }
        } else {
            $update_sql = "update call_task_queue set ctq_status = 'ready_to_call',ctq_call_task_id = null,ctq_status_updated_at = now() where ctq_call_company_id = '{$company_id}' and ctq_status = 'in_queue'";
            $update_sql_query = mysqli_query($db, $update_sql);
            tlogger($log_trace_id, 'результат успешный', "i", $log_name, $log_dir);
        }
    }
}


if ($status) {
    $token_read_from_vault_response = token_read_from_vault();
    if ($token_read_from_vault_response['f_status'] && !empty($token_read_from_vault_response['f_result'])) {
        $b24_access_token_arr = $token_read_from_vault_response['f_result'];

        $cu_data = [];
        $cu_data['id'] = (int) $request_body_arr['variables']['kompanyId'];

        $cu_data['fields']['UF_CRM_1687848502'] = '';
        $messages_txt = '';
        if (!empty($request_body_arr['flow'])) {
            foreach ($request_body_arr['flow'][0]['messages'] as $message_id => $message_arr) {
                $message_txt = trim($message_arr['text']);
                $messages_txt .= "{$message_arr['author']}: {$message_txt}\r\n";
            }
            $cu_data['fields']['UF_CRM_1687848502'] = $messages_txt; // TWIN - расшифровка диалога
        }
        $cu_data['fields']['UF_CRM_1687938496'] = '';
        if (!empty($request_body_arr['result']['initialVariables']['recordPath'])) {
            $cu_data['fields']['UF_CRM_1687938496'] = str_replace('demidenok', 'tcl', $request_body_arr['result']['initialVariables']['recordPath']); // TWIN - запись разговора
        }
        $cu_data['fields']['UF_CRM_1687948983'] = '';
        if (!empty($request_body_arr['result']['confirmation'])) {
            $cu_data['fields']['UF_CRM_1687948983'] = $request_body_arr['result']['confirmation']; // Twin - результат звонка роботом
        }
        $cu_data['fields']['UF_CRM_1686724660'] = '';
        if (!empty($request_body_arr['status'])) {
            $cu_data['fields']['UF_CRM_1686724660'] = $request_body_arr['status']; // TWIN - Статус линии
        }
        $cu_data['fields']['UF_CRM_1712572186'] = '';
        if (!empty($request_body_arr['callTo'])) {
            $cu_data['fields']['UF_CRM_1712572186'] = $request_body_arr['callTo']; // TWIN - Вызываемый номер
        }
        if (!empty($request_body_arr['result']['clientNameB24'])) {
            $cu_data['fields']['UF_CRM_1708576376'] = $request_body_arr['result']['clientNameB24']; // TWIN - Имя ЛПР
        }
        if (!empty($request_body_arr['result']['clientSurname'])) {
            $cu_data['fields']['UF_CRM_1708576405'] = $request_body_arr['result']['clientSurname']; // TWIN - Отчество ЛПР
        }
        if (!empty($request_body_arr['result']['clientSecondName'])) {
            $cu_data['fields']['UF_CRM_1708576432'] = $request_body_arr['result']['clientSecondName']; // TWIN - Фамилия ЛПР
        }
        if (!empty($request_body_arr['result']['phoneLpr'])) {
            $cu_data['fields']['UF_CRM_1708576539'] = $request_body_arr['result']['phoneLpr']; // TWIN - Телефон для связи с ЛПР
        }
        if (!empty($request_body_arr['result']['postLpr'])) {
            $cu_data['fields']['UF_CRM_1708576511'] = $request_body_arr['result']['postLpr']; // TWIN - Должность ЛПРа
        }
        if (!empty($request_body_arr['finishedAt'])) {
            $finished_at_unix = strtotime($request_body_arr['finishedAt'] . '+2 hour');
            $finished_at_atom = date(DateTimeInterface::ATOM, $finished_at_unix);
            $cu_data['fields']['UF_CRM_1690191255'] = $finished_at_atom; // TWIN - Дата звонка роботом
        }
        $company_update_response = company_update($b24_access_token_arr, $cu_data);
        if ($company_update_response['f_status']) {
            $company_update_result = $company_update_response['f_result'];
            if (!empty($company_update_result['result']) && $company_update_result['result'] === true) {
                $status = true;
            } else {
                $status = false;
                $errors[] = 'company update error';
            }

        } else {
            $log_error_description = print_r($company_update_response['f_error_description'], true);
            $log_msg = "f_error:{$company_update_response['f_error']} f_error_description:\r\n{$log_error_description}";
            tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
            $status = false;
            $errors[] = 'company update error';
        }
        if (!empty($request_body_arr['callbackData'][0]['b24TaskID'])) {
            $b24_task_id = (int) $request_body_arr['callbackData'][0]['b24TaskID'];
            $au_data['id'] = $b24_task_id;
            $au_data['fields']['COMPLETED'] = 'Y';
            $au_data['fields']['DESCRIPTION'] = $messages_txt;

            $activity_update_response = activity_update($b24_access_token_arr, $au_data);
            if ($activity_update_response['f_status']) {
                $activity_update_result = $activity_update_response['f_result'];
                if (!empty($activity_update_result['result']) && $activity_update_result['result'] === true) {
                    $status = true;
                } else {
                    $status = false;
                    $errors[] = 'activity update error';
                }
            } else {
                $log_error_description = print_r($activity_update_response['f_error_description'], true);
                $log_msg = "f_error:{$activity_update_response['f_error']} f_error_description:\r\n{$log_error_description}";
                tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
                $status = false;
                $errors[] = 'activity update error';
            }
        }
        $b24_cm_id = (int) $request_body_arr['callbackData'][0]['kompanyId'];
        $b24_task_id = (int) $request_body_arr['callbackData'][0]['b24_task_id'];

        $tns_data['ownerTypeId'] = 4;
        $tns_data['ownerId'] = $b24_cm_id;
        $tns_data['itemType'] = 2;
        $tns_data['itemId'] = $b24_task_id;
        $tns_data['text'] = 'Result';

        $timeline_note_save_response = timeline_note_save($b24_access_token_arr, $tns_data);
        if ($timeline_note_save_response['f_status']) {
            $timeline_note_save_result = $timeline_note_save_response['f_result'];
            if (!empty($timeline_note_save_result['result']) && $timeline_note_save_result['result'] === true) {
                $status = true;
            } else {
                $status = false;
                $errors[] = 'timeline note save error';
            }
        } else {
            $log_error_description = print_r($timeline_note_save_response['f_error_description'], true);
            $log_msg = "f_error:{$timeline_note_save_response['f_error']} f_error_description:\r\n{$log_error_description}";
            tlogger($log_trace_id, $log_msg, "e", $log_name, $log_dir);
            $status = false;
            $errors[] = 'timeline note save error';
        }
    }
}

if ($status) {
    $ep_response['status'] = true;
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