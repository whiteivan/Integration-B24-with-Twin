<?php
function tlogger($log_trace_id, $log_msg, $log_category, $log_name, $log_dir, $echo_msg = false)
{
    global $log_level;
    if (!isset($log_level)) {
        $log_level = 'i';
    }
    $log_level_arr['i'] = ['f', 'e', 'w', 'i'];
    $log_level_arr['d'] = ['f', 'e', 'w', 'i', 'd'];
    $bgc['red'] = '48;5;196m';
    $bgc['yellow'] = '48;5;229m';
    if (in_array($log_category, $log_level_arr[$log_level])) {
        $write_log = true;
    } else {
        $write_log = false;
    }
    if ($write_log) {
        $time = date('d-m-Y H:i:s');
        if (isset($_SERVER["HTTP_USER_AGENT"])) {
            $agent = $_SERVER["HTTP_USER_AGENT"] . ' - IP ' . $_SERVER["REMOTE_ADDR"];
        } else {
            $agent = PHP_VERSION;
        }
        $log_path = $log_dir . $log_name . '_' . date('Y-m-d') . ".log";
        $log_str = '[' . $time . '] ' . '[' . $log_category . '] ' . '[' . $log_trace_id . '] ' . '[' . $log_msg . '] ' . '[' . $agent . ']' . "\r\n";
        file_put_contents($log_path, $log_str, FILE_APPEND | LOCK_EX);
        if ($echo_msg) {
            switch ($log_category) {
                case 'f':
                    $log_msg_echo = "\033[" . $bgc['red'] . $log_msg . "\033[0m";
                    break;
                case 'e':
                    $log_msg_echo = "\033[" . $bgc['red'] . $log_msg . "\033[0m";
                    break;
                case 'w':
                    $log_msg_echo = "\033[" . $bgc['yellow'] . $log_msg . "\033[0m";
                    break;
                default:
                    $log_msg_echo = $log_msg;
            }
            echo $log_msg_echo . "\r\n";
        }
    }
}