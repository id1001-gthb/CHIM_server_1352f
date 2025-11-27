<?php

function npcNameToCodename($npcName) {
    $codename=mb_convert_encoding($npcName, 'UTF-8', mb_detect_encoding($npcName));
    $codename=strtr(strtolower(trim($codename)),[" "=>"_","'"=>"+"]);
    $codename=preg_replace('/[^\w+-]/u', '', $codename);
    return $codename;
}

function isNonEmptyArray($var) {
    return is_array($var) && count($var) > 0;
}

function strtr_ci($s_input, $replace_array) {
// i.e. instead of $s_res = strtr($s_input,$replace_array);
    $s_res = $s_input;
    if ((strlen($s_input) > 0) && (isset($replace_array)) && (count($replace_array) > 0)) {
        foreach($replace_array as $s_key=>$s_value) 
            $s_res=str_ireplace($s_key,$s_value,$s_input);
    }
    return $s_res;
}

function clean_string_np($s_input, $s_replace_with=' ') {
/* clean non-printable chars from string 
leave only characters not listed within the brackets: 
 - \r\n\t carriage-return, newline, and tab characters 
 - hexcode 20 through FF 
 - hexcode 7F through 9F 
*/
	return preg_replace('/[^\r\n\t\x20-\x7E\xA0-\xFF]/', $s_replace_with, $s_input);
}

?>
