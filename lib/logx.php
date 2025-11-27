<?php

$GLOBALS['logx_json_flags'] = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

function getDbgBacktrace() {
    $dbg_trace = debug_backtrace();
    $s_dbg_msg = "";
    foreach($dbg_trace as $dbg_index => $dbg_info) {
        $s_dbg_msg .= " {$dbg_index} ".
            str_replace('/var/www/html/HerikaServer/','',$dbg_info['file']).
            " [{$dbg_info['line']}]->{$dbg_info['function']}(".
            json_encode($dbg_info['args'],$GLOBALS['logx_json_flags']). //PHP Warning:  Array to string conversion in /var/www/html/HerikaServer/lib/data_functions.php on line 49 [12:34:58 10.11.25] [warn]
            ")\n";
    }
    return $dbg_msg;
}

function log0($message="", $s_file="") {

    $s_msg = $message ?? '';

    if (strlen($s_file) > 0)
        $file_name = '/var/www/html/HerikaServer/log/{$s_file}.log';
    else
        $file_name = '/var/www/html/HerikaServer/log/_ac.log';
    
    $p_file = fopen($file_name, "a");
    
    if (($p_file !== false) && (strlen($s_msg) > 0) ) {
        $s_dbg_msg = $s_msg."\n";
        $b_write = fwrite($p_file, $s_dbg_msg);
        if ($b_write === false) 
            error_log(" err writing in $file_name");
    } else error_log(" err opening $file_name");

    fflush($p_file);
    fclose($p_file);
}

function log1($message="", $s_file="") {
    $s_msg = "";
    $s_type = "";
    if (isset($message)) {
        $s_type = gettype($message);
        if (is_array($message)) {
            $s_msg = json_encode($message,$GLOBALS['logx_json_flags']);
        } elseif (is_object($message)) {
            $s_msg = json_encode($message,$GLOBALS['logx_json_flags']);
        } elseif (is_bool($message)) {
            $s_msg = $message ? 'true' : 'false';
        } elseif (is_float($message)) {
            $s_msg = number_format($message);
        } elseif (is_int($message)) {
            $s_msg = strval($message);
        } elseif (is_string($message)) {
            $s_msg = $message;
        } else {
            $s_msg = strval($message);
        }
    } else $s_type = "undefined";
    
    if (strlen($s_file) > 0)
        $file_name = '/var/www/html/HerikaServer/log/{$s_file}.log';
    else
        $file_name = '/var/www/html/HerikaServer/log/_ac.log';
    
    $p_file = fopen($file_name, "a");
    
    if (($p_file !== false) && (strlen($s_msg) > 0) ) {
        $s_dbg_msg = $s_msg." [{$s_type}] |exec trace\n";
        $b_write = fwrite($p_file, $s_dbg_msg);
        if ($b_write === false) 
            error_log(" err writing in $file_name");
    } else error_log(" err opening $file_name");

    fflush($p_file);
    fclose($p_file);
}

function log2($message, $n_trace=99, $s_file="") {
    
    $s_msg = "";
    $s_type = "";
    if (isset($message)) {
        $s_type = gettype($message);
        if (is_array($message)) {
            $s_msg = json_encode($message,$GLOBALS['logx_json_flags']);
        } elseif (is_object($message)) {
            $s_msg = json_encode($message,$GLOBALS['logx_json_flags']);
        } elseif (is_bool($message)) {
            $s_msg = $message ? 'true' : 'false';
        } elseif (is_float($message)) {
            $s_msg = number_format($message);
        } elseif (is_int($message)) {
            $s_msg = strval($message);
        } elseif (is_string($message)) {
            $s_msg = $message;
        } else {
            $s_msg = strval($message);
        }
    } else $s_type = "undefined";
    
    if (strlen($s_file) > 0)
        $file_name = '/var/www/html/HerikaServer/log/{$s_file}.log';
    else
        $file_name = '/var/www/html/HerikaServer/log/_ac.log';
    
    $p_file = fopen($file_name, "a");
    
    if (($p_file !== false) && ($n_trace >= 0)) {
        $s_dbg_msg = $s_msg." [{$s_type}] |exec trace\n";
        $dbg_trace = debug_backtrace();
        foreach($dbg_trace as $dbg_index => $dbg_info) {
            if ($dbg_index > $n_trace) continue;
            if ($dbg_index > -1) {
                $s_dbg_msg .= " {$dbg_index} ".
                    str_replace('/var/www/html/HerikaServer/','',$dbg_info['file']).
                    " [{$dbg_info['line']}]->{$dbg_info['function']}(".
                    json_encode($dbg_info['args'],$GLOBALS['logx_json_flags']). //PHP Warning:  Array to string conversion in /var/www/html/HerikaServer/lib/data_functions.php on line 49 [12:34:58 10.11.25] [warn]
                    ")\n";
            }
        }
       
        $b_write = fwrite($p_file, $s_dbg_msg);
        if ($b_write === false) 
            error_log(" err writing in $file_name");

    } else error_log(" err opening $file_name");

    fflush($p_file);
    fclose($p_file);
}


?>