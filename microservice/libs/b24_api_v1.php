<?php
const B24_ATTEMPT_LIMIT = 2;
function curl_send_to_b24(array $access_data, string $path, $data = false, $post_method = 'json')
{
    global $log_trace_id;
    $log_name = 'b24_api';
    $log_dir = __DIR__ . '/logs/';
    $url = 'https://' . $access_data['b24_domain'] . '/rest/' . $access_data['b24_access_token'] . $path;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_USERAGENT, 'Bitrix-API-client/1.0');
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');

    if (!empty($data)) {
        if ($post_method == 'params') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        } elseif ($post_method == 'json') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
    }

    if (!empty($headers)) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    }

    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);

    $attempt_limit = B24_ATTEMPT_LIMIT;
    $attempt = 1;
    $next_try = true;
    $delay = 0;
    while ($next_try) {
        sleep($delay);
        $curl_out = curl_exec($curl);
        $http_code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $total_time = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
        $curl_url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        if ($curl_out === false || ($http_code >= 500 && $http_code <= 526)) {
            if ($attempt < $attempt_limit) {
                $log_category = 'w';
                $next_try = true;
            } else {
                $log_category = 'e';
                $next_try = false;
            }
            tlogger($log_trace_id, "{$http_code} attempt $attempt error - POST: $curl_url, execution time: $total_time, trying again", $log_category, $log_name, $log_dir);
            $attempt++;
            $delay = $delay + 10;
        } else {
            $next_try = false;
            tlogger($log_trace_id, "POST: $curl_url, execution time: $total_time", "i", $log_name, $log_dir);
        }
    }
    curl_close($curl);

    $f_code = $http_code;

    $expected_response_codes = [200, 400];
    $expected_response_error_codes = [400];

    try {
        if (!in_array($http_code, $expected_response_codes)) throw new Exception($curl_out, $http_code);
        if ($http_code == 200) {
            $f_status = true;
            $f_result = json_decode($curl_out, true);
        } elseif (in_array($http_code, $expected_response_error_codes)) {
            $f_status = false;
            switch ($http_code) {
                case 400:
                    $f_error = 'Ошибка запроса';
                    break;
                case 401:
                    $f_error = 'Ошибка авторизации';
                    break;
                default:
                    $f_error = 'Другая ошибка';
            }
            $f_error_description = json_decode($curl_out, true);
        }
    } catch (Exception $E) {
        $f_status = false;
        $error_http_code = $E->getCode();
        $error_full_message = $E->getMessage();
        tlogger($log_trace_id, "error_http_code - $error_http_code : error_full_message - $error_full_message execution time: $total_time", "e", $log_name, $log_dir);
        $f_error = 'unexpected response codes';
        $f_error_description = 'unexpected response codes';
    }
    $f_response['f_status'] = $f_status;
    $f_response['f_code'] = $f_code;
    if ($f_status) {
        $f_response['f_result'] = $f_result;
    } else {
        if (isset($f_error)) {
            $f_response['f_error'] = $f_error;
            $f_response['f_error_description'] = $f_error_description;
        }
    }
    return $f_response;
}

function token_read_from_vault()
{
    $b24_access_token_arr['b24_domain'] = B24_DOMAIN;
    $b24_access_token_arr['b24_access_token'] = B24_TWIN_ACCESS_TOKEN;
    $f_result = $b24_access_token_arr;

    $f_status = true;

    $f_response['f_status'] = $f_status;
    if ($f_status) {
        $f_response['f_result'] = $f_result;
    } else {
        if (isset($f_error)) {
            $f_response['f_error'] = $f_error;
        }
    }
    return $f_response;
}

function company_update($access_data, $params = false)
{
    $data = [];
    if ($params) {
        if (isset($params['id'])) {
            $data['id'] = $params['id'];
        }
        if (isset($params['fields'])) {
            $data['fields'] = $params['fields'];
        }
        if (isset($params['params'])) {
            $data['params'] = $params['params'];
        }
    }
    $path = '/crm.company.update';
    return curl_send_to_b24($access_data, $path, $data);
}

function company_get($access_data, $params = false)
{
    $data = [];
    if ($params) {
        if (isset($params['id'])) {
            $data['id'] = $params['id'];
        }
        if (isset($params['select'])) {
            $data['select'] = $params['select'];
        }
    }
    $path = '/crm.company.get';
    return curl_send_to_b24($access_data, $path, $data);
}

function activity_add($access_data, $params = false)
{
    $data = [];
    if ($params) {
        if (isset($params['fields'])) {
            $data['fields'] = $params['fields'];
        }
    }
    $path = '/crm.activity.add';
    return curl_send_to_b24($access_data, $path, $data);
}

function activity_update($access_data, $params = false)
{
    $data = [];
    if ($params) {
        if (isset($params['id'])) {
            $data['id'] = $params['id'];
        }
        if (isset($params['fields'])) {
            $data['fields'] = $params['fields'];
        }
    }
    $path = '/crm.activity.update';
    return curl_send_to_b24($access_data, $path, $data);
}

function timeline_note_save($access_data, $params = false)
{
    $data = [];
    if ($params) {
        if (isset($params['ownerTypeId'])) {
            $data['ownerTypeId'] = $params['ownerTypeId'];
        }
        if (isset($params['ownerId'])) {
            $data['ownerId'] = $params['ownerId'];
        }
        if (isset($params['itemType'])) {
            $data['itemType'] = $params['itemType'];
        }
        if (isset($params['itemId'])) {
            $data['itemId'] = $params['itemId'];
        }
        if (isset($params['text'])) {
            $data['text'] = $params['text'];
        }
    }
    $path = '/crm.timeline.note.save';
    return curl_send_to_b24($access_data, $path, $data);
}