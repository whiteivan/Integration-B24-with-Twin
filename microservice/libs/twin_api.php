<?php
const TWIN_VAULT_PATH = __DIR__ . '/../../../vault';
const TWIN_ATTEMPT_LIMIT = 2;
function curl_send_to_twin(array $access_data, string $url, $data = false, $http_method = 'POST')
{
    global $log_trace_id;
    $log_name = 'twin_api';
    $log_dir = __DIR__ . '/logs/';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_USERAGENT, 'TWIN-API-client/1.0');
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

    if (!empty($access_data['token'])) {
        $headers[] = 'Authorization: Bearer ' . $access_data['token'];
    }

    if ($http_method == 'POST') {
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        if ($data) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $headers[] = 'Content-Type: application/json';

    if (!empty($headers)) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    }
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);

    $attempt_limit = TWIN_ATTEMPT_LIMIT;
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
            tlogger($log_trace_id, "{$http_code} attempt $attempt error - {$http_method}: $curl_url, execution time: $total_time, trying again", $log_category, $log_name, $log_dir);
            $attempt++;
            $delay = $delay + 10;
        } else {
            $next_try = false;
            tlogger($log_trace_id, "{$http_method}: $curl_url, execution time: $total_time", "i", $log_name, $log_dir);
        }
    }
    curl_close($curl);

    $f_code = $http_code;
    $expected_response_codes = [200, 204, 400, 401, 402, 403, 429];
    $expected_response_error_codes = [400, 401, 402, 403, 429];

    try {
        if (!in_array($http_code, $expected_response_codes)) throw new Exception($curl_out, $http_code);
        if ($http_code == 200) {
            $f_status = true;
            $f_result = json_decode($curl_out, true);
        } elseif ($http_code == 204) {
            $f_status = true;
            $f_result = [];
        } elseif (in_array($http_code, $expected_response_error_codes)) {
            $f_status = false;
            switch ($http_code) {
                case 400:
                    $f_error = 'Ошибка';
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

function twin_token_read_from_json($src_id)
{
    $twin_access_token_file_content = file_get_contents(TWIN_VAULT_PATH . "/twin-token-{$src_id}.json");
    $f_result = json_decode($twin_access_token_file_content, true);
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

function twin_token_update_to_json($src_id)
{
    $auth_data['email'] = TWIN_EMAIL;
    $auth_data['password'] = TWIN_PASSWORD;
    $auth_data['ttl'] = 86400;
    $url = 'https://iam.twin24.ai/api/v1/auth/login';
    $curl_send_to_twin_response = curl_send_to_twin([], $url, $auth_data);
    if ($curl_send_to_twin_response['f_status'] && !empty($curl_send_to_twin_response['f_result']['token'])) {
        $twin_access_token_arr['token'] = $curl_send_to_twin_response['f_result']['token'];
        $twin_access_token_arr['refresh_token'] = $curl_send_to_twin_response['f_result']['refreshToken'];
        $twin_access_token_json = json_encode($twin_access_token_arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(TWIN_VAULT_PATH . "/twin-token-{$src_id}.json", $twin_access_token_json, LOCK_EX);
        $f_status = true;
    } else {
        $f_error = "no $src_id token";
        $f_status = false;
    }
    $f_response['f_status'] = $f_status;
    if (!$f_status) {
        if (isset($f_error)) {
            $f_response['f_error'] = $f_error;
        }
    }
    return $f_response;
}

function create_twin_auto_call($access_data, $data)
{
    $url = 'https://cis.twin24.ai/api/v1/telephony/autoCall';
    return curl_send_to_twin($access_data, $url, $data);
}

function add_twin_auto_call_candidates($access_data, $data)
{
    $url = 'https://cis.twin24.ai/api/v1/telephony/autoCallCandidate/batch';
    return curl_send_to_twin($access_data, $url, $data);
}

function twin_auto_call_start($access_data, $data)
{
    $auto_call_id = $data['auto_call_id'];
    $url = "https://cis.twin24.ai/api/v1/telephony/autoCall/$auto_call_id/play";
    return curl_send_to_twin($access_data, $url);
}

function twin_delete_candidate($access_data,$data)
{
    $phone = $data['phone'];
    $auto_call_id = $data['auto_call_id'];
    $url = "https://cis.twin24.ai/api/v1/telephony/autoCallCandidate/cancel?autoCallId=$auto_call_id&phone=$phone";
    return curl_send_to_twin($access_data, $url);
}

function twin_auto_call_pause($access_data, $data)
{
    $auto_call_id = $data['auto_call_id'];
    $url = "https://cis.twin24.ai/api/v1/telephony/autoCall/$auto_call_id/pause";
    return curl_send_to_twin($access_data, $url);
}