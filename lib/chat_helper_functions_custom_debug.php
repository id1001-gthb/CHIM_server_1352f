<?php

$s_rsp = str_replace(
	["\0", "‐", "‑", "—	",  "—",   "‘", "’", "‚", "‛" ],
	[''  , "-", "-", " - ", " - ", "'", "'", "'", "'" ],
	$responseTextUnmooded);

$s_listener = $GLOBALS["SCRIPTLINE_LISTENER"] ?? '?';
$s_crt_con = $GLOBALS["CURRENT_CONNECTOR"] ?? '?';
if ($s_crt_con != '?') {
	$s_model = $GLOBALS["CONNECTOR"][$GLOBALS["CURRENT_CONNECTOR"]]['model'] ?? '?';
} else {
	$s_model = '?';
}

$s_txt = "{$outBuffer["actor"]}:\t{$s_rsp} \t{$s_listener} \t {$s_crt_con} \t {$s_model} \r\n";
file_put_contents(__DIR__."/../log/_dialogue.csv", $s_txt, FILE_APPEND | LOCK_EX); 
$s_txt = "{$outBuffer["actor"]}:{$s_model}:{$s_rsp} - {$s_listener} - {$s_crt_con}";
error_log("{$s_txt} = msg =");

?>