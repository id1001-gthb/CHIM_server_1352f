<?php

$enginePath = dirname((__FILE__)) . DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR;
// require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."tokenizer_helper_functions.php"); // Optional: Only if needed elsewhere

// --- START: Standalone Cache Helper Functions ---

if(!function_exists('connector_cacheReadFromFile')) {
     function connector_cacheReadFromFile($fileName, $isUserAssistantCache = false) {
         $cacheFilePath = __DIR__."/../temp/".$fileName;
         if (!file_exists($cacheFilePath) || filesize($cacheFilePath) === 0) return 0;
         $file = @fopen($cacheFilePath, "r");
         if (!$file) { error_log("Failed to open cache file for reading: " . $fileName); return 0; }
         $timestampLine = trim(@fgets($file));
         if ($timestampLine === false || !is_numeric($timestampLine) || $timestampLine <= 0) { fclose($file); @unlink($cacheFilePath); error_log("Invalid or missing timestamp in cache file: " . $fileName); return 0; }
         $timestamp = (int)$timestampLine;
         $lastIndex = -1; $lastHash = null; $content = "";
         if ($isUserAssistantCache) {
             $lastIndexLine = trim(@fgets($file));
             if ($lastIndexLine === false || !is_numeric($lastIndexLine) || $lastIndexLine < -1) { fclose($file); @unlink($cacheFilePath); error_log("Invalid or missing lastIndex in cache file: " . $fileName); return 0; }
             $lastIndex = (int)$lastIndexLine;
             $lastHashLine = trim(@fgets($file));
             if ($lastHashLine === false || (empty($lastHashLine) && $lastIndex > -1) ) { fclose($file); @unlink($cacheFilePath); error_log("Invalid or missing lastHash in cache file: " . $fileName); return 0; }
             $lastHash = $lastHashLine;
             while (!feof($file)) { $line = @fgets($file); if ($line === false) break; $content .= $line; }
         } else { // System cache
             while (!feof($file)) { $line = @fgets($file); if ($line === false) break; $content .= $line; }
         }
         fclose($file);
         return array( 'timestamp' => $timestamp, 'lastIndex' => $lastIndex, 'lastHash'  => $lastHash, 'content'   => $content );
     }
}

if(!function_exists("connector_cacheWriteToFile")){
     function connector_cacheWriteToFile($fileName, $content, $timestamp, $lastIndexIncluded = null, $lastHashIncluded = null) {
         $filePath = __DIR__ . "/../temp/" . $fileName;
         $folderPath = dirname($filePath);
         if (!is_dir($folderPath)) { if (!@mkdir($folderPath, 0755, true)) { error_log("Failed to create cache directory: ".$folderPath); return false; } }
         $fileContent = $timestamp . "\n";
         if ($lastIndexIncluded !== null && is_numeric($lastIndexIncluded)) {
             $fileContent .= $lastIndexIncluded . "\n";
             $fileContent .= (isset($lastHashIncluded) ? $lastHashIncluded : '') . "\n";
         }
         $fileContent .= $content;
         $bytesWritten = @file_put_contents($filePath, $fileContent, LOCK_EX);
         if ($bytesWritten === false) { error_log("Failed to write cache file: " . $filePath); }
         return ($bytesWritten !== false);
     }
 }

// New function to build combined dialogue content
if (!function_exists('connector_buildCombinedDialogue')) {
     function connector_buildCombinedDialogue($contextData, $numMessagesToKeepUncached, $connectorName, $herikaName) {
        $n_ctxsize = count($contextData);
        $cacheThresholdIndex = max(0, $n_ctxsize - $numMessagesToKeepUncached);
        $lastAggregatedIndex = $cacheThresholdIndex - 1;
        $combinedContentToCache = "";
        $lastAggregatedElement = null;
        $lastAggregatedContentForHash = "";
        
        for ($n = 0; $n <= $lastAggregatedIndex; $n++) {
             if (!isset($contextData[$n])) continue;
             $element = $contextData[$n];
             if (isset($element["role"]) && $element["role"] != "system") {
                 $contentString = '';
                 if (is_string($element['content'])) { $contentString = $element['content']; }
                 elseif (is_array($element['content']) && isset($element['content'][0]['type']) && $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])) { $contentString = $element['content'][0]['text']; }
                 $trimmedContent = trim($contentString);
                 if (!empty($trimmedContent)) {
                     $sequencePrefix = "Seq[" . ($n + 1) . "]: ";
                     $lineContent = $sequencePrefix . $element['role'] . ": " . $trimmedContent . "\n";
                     $combinedContentToCache .= $lineContent;
                     $lastAggregatedElement = $element;
                     $lastAggregatedContentForHash = $trimmedContent;
                  }
             }
         }
        
        $finalLastAggregatedHash = ($lastAggregatedElement !== null) ? md5($lastAggregatedContentForHash) : '';
        
        return array(
            'content' => $combinedContentToCache,
            'lastAggregatedIndex' => $lastAggregatedIndex,
            'lastAggregatedHash' => $finalLastAggregatedHash,
            'startIndexForIndividual' => $cacheThresholdIndex
        );
    }
}
// --- END: Standalone Cache Helper Functions ---

function logMessage(string|array $message, string $level = 'INFO', string $logFile = './application.log'): bool
    {
        // Get the current timestamp in a readable format
        $timestamp = date('Y-m-d H:i:s');

        // If the message is an array, convert it to a JSON string
        if (is_array($message)) {
            // Use JSON_PRETTY_PRINT for better readability in the log file,
            // or remove it for a single-line compact JSON string.
            $formattedMessage = json_encode($message, JSON_PRETTY_PRINT);
            if ($formattedMessage === false) {
                // Fallback if json_encode fails (e.g., circular reference)
                $formattedMessage = "Failed to encode array to JSON. Original: " . print_r($message, true);
            }
        } else {
            // If it's not an array, use the message as is
            $formattedMessage = (string) $message; // Ensure it's treated as a string
        }

        // Format the log entry: [YYYY-MM-DD HH:MM:SS] LEVEL: Your message
        $logEntry = "[{$timestamp}] {$level}: {$formattedMessage}\n";

        // Attempt to write the log entry to the file
        // FILE_APPEND ensures the content is added to the end of the file.
        // LOCK_EX acquires an exclusive lock, preventing race conditions during concurrent writes.
        $result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Check if the write operation was successful
        if ($result === false) {
            // If writing failed, log an error to PHP's default error log
            error_log("Failed to write to log file: {$logFile}. Original message: " . (is_array($message) ? json_encode($message) : $message));
            return false;
        }

        return true;
    }


class openrouteranthropic
{
    // --- Properties ---
    public $primary_handler;
    public $name;
    private $_functionName;
    private $_parameterBuff;
    private $_commandBuffer;
    public $_extractedbuffer;
    private $_buffer;
    private $_dataSent;
    private $_rawbuffer;
    private $_forcedClose=false;

    public function __construct()
    {
        $this->name="openrouteranthropic";
        $this->_commandBuffer=array();
        $this->_buffer = ""; $this->_extractedbuffer = ""; $this->_dataSent = null;
        $this->_rawbuffer = ""; $this->_forcedClose = false;
        $this->_functionName = null; $this->_parameterBuff = "";
    }

    public function open($contextData, $customParms)
    {
        // --- Setup and Logging ---
        $herikaNameForLog = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        $herikaName = $herikaNameForLog;
        $incoming_ctx_size = count($contextData);
        error_log("[{$this->name}:{$herikaName}] OPEN START: Received contextData with {$incoming_ctx_size} elements.");
        $connectorDir = __DIR__;
        $cacheDebugLogFile = $connectorDir . DIRECTORY_SEPARATOR . '_cache_hit_debug.log';

        // --- Config ---
        $url = isset($GLOBALS["CONNECTOR"][$this->name]["url"]) ? $GLOBALS["CONNECTOR"][$this->name]["url"] : '';
        $MAX_TOKENS = ((isset($GLOBALS["CONNECTOR"][$this->name]["max_tokens"]) ? $GLOBALS["CONNECTOR"][$this->name]["max_tokens"] : 4096)+0);
        $model = (isset($GLOBALS["CONNECTOR"][$this->name]["model"])) ? $GLOBALS["CONNECTOR"][$this->name]["model"] : 'anthropic/claude-3-haiku-20240307';

        // --- Configurable Cache Settings Reading ---
        $toggleThinking = isset($GLOBALS["CONNECTOR"][$this->name]["toggle_thinking"]) ? $GLOBALS["CONNECTOR"][$this->name]["toggle_thinking"] : false;
        $thinkingTokens = isset($GLOBALS["CONNECTOR"][$this->name]["thinking_tokens"]) ? $GLOBALS["CONNECTOR"][$this->name]["thinking_tokens"] : 1000;

        $sysCacheStrategy = 'ttl';
        if (isset($GLOBALS["CONNECTOR"][$this->name]["system_cache_strategy"]) && in_array($GLOBALS["CONNECTOR"][$this->name]["system_cache_strategy"], array('content', 'ttl'))) {
            $sysCacheStrategy = $GLOBALS["CONNECTOR"][$this->name]["system_cache_strategy"];
        }
        $sysCacheTTL = 900;
        if (isset($GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"]) && is_numeric($GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"]) && $GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"] >= 0) {
            $sysCacheTTL = (int)$GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"];
        }
        $dialogueCacheTTL = 900;
        if (isset($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"]) && is_numeric($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"]) && $GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"] >= 0) {
            $dialogueCacheTTL = (int)$GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"];
        }
        $numMessagesToKeepUncached = 5;
        if (isset($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"]) && is_numeric($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"]) && $GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"] >= 0) {
            $numMessagesToKeepUncached = (int)$GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"];
        }
        $logPrefix = "[{$this->name}:{$herikaName}]";
        error_log("{$logPrefix} Cache Config: SysStrategy={$sysCacheStrategy}, SysTTL={$sysCacheTTL}, DialogueTTL={$dialogueCacheTTL}, UncachedCount={$numMessagesToKeepUncached}");
        // --- End Cache Settings Reading ---

        // --- Context Preprocessing ---
        $contextDataCopyPreCache = array(); $skipped_count = 0;
        foreach ($contextData as $n => $element) {
           if (is_array($element) && isset($element['role']) && isset($element['content'])) {
               $contentCheck = null;
               if (is_string($element['content'])) { $contentCheck = trim($element["content"]); if (empty($contentCheck)) { $contentCheck = null; } else { $element['content'] = $contentCheck; } }
               elseif (is_array($element['content']) && isset($element['content'][0]['type']) && $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])) { $contentCheck = trim($element['content'][0]['text']); if (empty($contentCheck)) { $contentCheck = null; } else { $element['content'][0]['text'] = $contentCheck; } }
               if ($contentCheck !== null) { $contextDataCopyPreCache[] = $element; } else { $skipped_count++; }
           } else { error_log("{$logPrefix} OPEN PREPROCESS: Skipped malformed element at index {$n}."); $skipped_count++; }
        }
        $contextDataOrig = $contextDataCopyPreCache; $n_ctxsize = count($contextDataOrig);
        error_log("{$logPrefix} OPEN PREPROCESS: Processed contextDataOrig has {$n_ctxsize} elements. Skipped {$skipped_count}.");
        // --- End Preprocessing ---

        // --- Caching Setup with 2-cache approach ---
        $cacheSystemFile = "system_cache_{$herikaName}.tmp";
        $cacheCombinedDialogueFile = "combined_dialogue_cache_{$herikaName}.tmp"; // Single file for dialogue
        $cacheSystemMessages = connector_cacheReadFromFile($cacheSystemFile, false);
        $cacheCombinedDialogueMessages = connector_cacheReadFromFile($cacheCombinedDialogueFile, true); // Use the same format with lastIndex/hash

        $currentTime = time(); 
        $isCombinedDialogueCacheValid = false;
        
        // Check if combined dialogue cache is valid
        if (is_array($cacheCombinedDialogueMessages) && isset($cacheCombinedDialogueMessages["timestamp"])) {
             if (($currentTime - $cacheCombinedDialogueMessages["timestamp"]) <= $dialogueCacheTTL) {
                 $isCombinedDialogueCacheValid = true;
             }
        }
        
        // --- Build Final Message List ---
        $finalMessagesToSend = array(); 
        $processedFirstSystem = false; 
        $systemContentForCacheFile = null;

        $cacheControl = ["type"=>"ephemeral", "ttl" => "1h"];

        // Step 1: Process System Messages (unchanged from original)
        foreach ($contextDataOrig as $n => $element) {
            if (isset($element["role"]) && $element["role"] == "system") {
                 $systemContentString = '';
                 if (is_string($element['content'])) { $systemContentString = $element['content']; }
                 elseif (is_array($element['content']) && isset($element['content'][0]['type']) && $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])) { $systemContentString = $element['content'][0]['text']; }
                 $trimmedSystemContent = trim($systemContentString);
                 if (!$processedFirstSystem && !empty($trimmedSystemContent)) {
                     $processedFirstSystem = true;
                     $systemContentOriginal = $trimmedSystemContent;
                     $seqExplanation = " "; // Shortened for brevity
                     $systemContentCurrent = $systemContentOriginal . $seqExplanation;

                     // Conditional System Cache Check
                     $isSystemCacheValid = false;
                     if (is_array($cacheSystemMessages) && isset($cacheSystemMessages['timestamp'])) {
                         if ($sysCacheStrategy === 'ttl') {
                             if (($currentTime - $cacheSystemMessages['timestamp']) <= $sysCacheTTL) { $isSystemCacheValid = true; error_log("{$logPrefix} System cache valid based on TTL (Strategy B)."); }
                             else { error_log("{$logPrefix} System cache expired based on TTL (Strategy B)."); }
                         } else {
                             if (isset($cacheSystemMessages['content']) && $cacheSystemMessages['content'] === $systemContentCurrent) { $isSystemCacheValid = true; error_log("{$logPrefix} System cache valid based on content match (Strategy A)."); }
                             else { error_log("{$logPrefix} System cache invalid based on content mismatch (Strategy A)."); }
                         }
                     } else { error_log("{$logPrefix} System cache miss (file invalid or missing)."); }

                     if ($isSystemCacheValid) {
                        $finalMessagesToSend[] = array( "role"=>"system", "content"=>array(array("type"=>"text", "text"=>$cacheSystemMessages["content"], "cache_control"=>$cacheControl)) );
                        $systemContentForCacheFile = null;
                     } else {
                         $cToSend = array(array('type'=>'text', 'text'=>$systemContentCurrent));
                         $finalMessagesToSend[] = array("role"=>"system", "content"=>$cToSend, "cache_control"=>$cacheControl);
                         $systemContentForCacheFile = $systemContentCurrent;
                     }
                 } elseif ($processedFirstSystem && !empty($trimmedSystemContent)) {
                    $cToSend = array(array('type'=>'text', 'text'=>$trimmedSystemContent));
                    $finalMessagesToSend[] = array('role'=>'system', 'content'=>$cToSend);
                 }
            }
        }
        if ($systemContentForCacheFile !== null) {
             connector_cacheWriteToFile($cacheSystemFile, $systemContentForCacheFile, $currentTime, null, null);
             error_log("{$logPrefix} System cache updated.");
        }
        // --- End System Processing ---

        // --- Step 2: Process Combined Dialogue History ---
        $needsRebuild = false; 
        $startIndexForAddingIndividualMessages = 0;
        $hitDebugInfo = array('status' => '', 'reason' => '', 'lastCachedIndex' => 'N/A', 'lastCachedHash' => 'N/A', 'foundIndex' => -1, 'addedCount' => 0);

        if (!$isCombinedDialogueCacheValid) {
            error_log("{$logPrefix} Combined dialogue cache miss/expired/invalid. Triggering rebuild.");
            $hitDebugInfo['status'] = 'MISS'; 
            $hitDebugInfo['reason'] = 'Expired/Invalid'; 
            $needsRebuild = true;
        } else {
             error_log("{$logPrefix} Combined dialogue cache hit detected. Validating anchor...");
             $lastCachedIndex = isset($cacheCombinedDialogueMessages['lastIndex']) ? $cacheCombinedDialogueMessages['lastIndex'] : -1;
             $lastCachedHash = isset($cacheCombinedDialogueMessages['lastHash']) ? $cacheCombinedDialogueMessages['lastHash'] : null;
             $hitDebugInfo['lastCachedIndex'] = $lastCachedIndex; 
             $hitDebugInfo['lastCachedHash'] = (!empty($lastCachedHash)) ? $lastCachedHash : 'N/A'; //PHP 5 empty check

             if (isset($cacheCombinedDialogueMessages["content"]) && !empty(rtrim($cacheCombinedDialogueMessages["content"],"\n"))) { 
                 $finalMessagesToSend[] = array(
                     'role' => "user", 
                     "content" => array(array(
                         "type" => "text", 
                         "text" => rtrim($cacheCombinedDialogueMessages["content"], "\n"), 
                         "cache_control" => $cacheControl
                     ))
                 ); 
             }

             $foundMatchIndex = -1;
             if ($lastCachedIndex > -1 && !empty($lastCachedHash)) { // PHP 5 empty check
                 for ($idx = 0; $idx < $n_ctxsize; $idx++) {
                      if (!isset($contextDataOrig[$idx])) continue;
                      $element = $contextDataOrig[$idx];
                      if (isset($element["role"]) && ($element["role"] == "user" || $element["role"] == "assistant")) {
                          $contentString = '';
                          if(is_string($element['content'])){ 
                              $contentString = $element['content']; 
                          } elseif(is_array($element['content']) && isset($element['content'][0]['type']) && 
                                  $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])){ 
                              $contentString = $element['content'][0]['text']; 
                          }
                          
                          if (md5(trim($contentString)) === $lastCachedHash) {
                              $foundMatchIndex = $idx; 
                              error_log("{$logPrefix} Cache Anchor FOUND: Index {$foundMatchIndex} matches hash '{$lastCachedHash}'.");
                              $hitDebugInfo['status'] = 'HIT'; 
                              $hitDebugInfo['reason'] = 'Anchor Found'; 
                              $hitDebugInfo['foundIndex'] = $foundMatchIndex; 
                              break;
                          }
                      }
                 }
                 
                 if ($foundMatchIndex == -1) {
                      error_log("CRITICAL: Cache Anchor Hash '{$lastCachedHash}' (from file index {$lastCachedIndex}) NOT FOUND... Forcing REBUILD.");
                      $hitDebugInfo['status'] = 'MISS'; 
                      $hitDebugInfo['reason'] = 'Anchor Hash Not Found - Forced Rebuild'; 
                      $needsRebuild = true;
                      // Keep only system messages
                      $systemMessagesBackup = array(); 
                      foreach ($finalMessagesToSend as $msg) { 
                          if (isset($msg['role']) && $msg['role'] === 'system') { 
                              $systemMessagesBackup[] = $msg; 
                          } 
                      } 
                      $finalMessagesToSend = $systemMessagesBackup;
                 } else { 
                     $startIndexForAddingIndividualMessages = $foundMatchIndex + 1; 
                 }
             } elseif ($lastCachedIndex == -1) {
                 error_log("{$logPrefix} Cache hit on empty cache (index {$lastCachedIndex}). Proceeding without anchor search.");
                 $hitDebugInfo['status'] = 'HIT'; 
                 $hitDebugInfo['reason'] = 'Cache Empty (Index -1)'; 
                 $startIndexForAddingIndividualMessages = 0;
             } else {
                 error_log("WARNING: Cache inconsistency detected (Index {$lastCachedIndex}, Hash '{$lastCachedHash}'). Forcing REBUILD.");
                 $hitDebugInfo['status'] = 'MISS'; 
                 $hitDebugInfo['reason'] = 'Cache Inconsistency - Forced Rebuild'; 
                 $needsRebuild = true;
                 // Keep only system messages
                 $systemMessagesBackup = array(); 
                 foreach ($finalMessagesToSend as $msg) { 
                     if (isset($msg['role']) && $msg['role'] === 'system') { 
                         $systemMessagesBackup[] = $msg; 
                     } 
                 } 
                 $finalMessagesToSend = $systemMessagesBackup;
             }
        }

        if ($needsRebuild) {
             // Build combined dialogue cache using the helper function
             $combinedDialogueResult = connector_buildCombinedDialogue($contextDataOrig, $numMessagesToKeepUncached, $this->name, $herikaName);
             
             // Keep system messages from before
             $systemMessagesBackup = array(); 
             foreach ($finalMessagesToSend as $msg) { 
                 if (isset($msg['role']) && $msg['role'] === 'system') { 
                     $systemMessagesBackup[] = $msg; 
                 } 
             } 
             $finalMessagesToSend = $systemMessagesBackup;
             
             // Add the combined content as a user message
             if (isset($combinedDialogueResult['content']) && !empty(rtrim($combinedDialogueResult['content'], "\n"))) { 
                 $finalMessagesToSend[] = array(
                     'role' => 'user', 
                     'content' => array(array(
                         'type' => 'text', 
                         'text' => rtrim($combinedDialogueResult['content'], "\n"), 
                         'cache_control' => $cacheControl
                     ))
                 ); 
             }
             
             // Write the combined cache file
             $writeResult = connector_cacheWriteToFile(
                 $cacheCombinedDialogueFile, 
                 $combinedDialogueResult['content'], 
                 $currentTime, 
                 $combinedDialogueResult['lastAggregatedIndex'], 
                 $combinedDialogueResult['lastAggregatedHash']
             );
             
             if ($writeResult) {
                 error_log("[{$this->name}:{$herikaName}] Cache Rebuild SUCCESS: Wrote index {$combinedDialogueResult['lastAggregatedIndex']}, hash '{$combinedDialogueResult['lastAggregatedHash']}'.");
             } else {
                error_log("[{$this->name}:{$herikaName}] Cache Rebuild FAIL: Failed writing cache file.");
             }
             
             $startIndexForAddingIndividualMessages = isset($combinedDialogueResult['startIndexForIndividual']) ? 
                                                     $combinedDialogueResult['startIndexForIndividual'] : 0;
             $hitDebugInfo['lastCachedIndex'] = isset($combinedDialogueResult['lastAggregatedIndex']) ? 
                                               $combinedDialogueResult['lastAggregatedIndex'] : -1;
             $rebuiltHash = isset($combinedDialogueResult['lastAggregatedHash']) ? 
                           $combinedDialogueResult['lastAggregatedHash'] : null;
             $hitDebugInfo['lastCachedHash'] = (!empty($rebuiltHash)) ? $rebuiltHash : 'N/A'; // PHP 5 empty check
        }

        // Add individual messages after the cached portion
        $individualMessagesAddedCount = 0;
        $contentTextToSend = [];
        error_log("{$logPrefix} Building final payload: Adding individual messages from original context index {$startIndexForAddingIndividualMessages} onwards.");
        for ($sendIdx = $startIndexForAddingIndividualMessages; $sendIdx < $n_ctxsize; $sendIdx++) {
             if (!isset($contextDataOrig[$sendIdx])) continue;
             $element = $contextDataOrig[$sendIdx];
             if (isset($element["role"]) && $element["role"] != "system") {
                 $contentToSend = null; 
                 $contentString = '';
                 if(is_string($element['content'])){ 
                     $contentString = $element['content']; 
                 } elseif(is_array($element['content']) && isset($element['content'][0]['type']) && 
                         $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])){ 
                     $contentString = $element['content'][0]['text']; 
                 }
                 
                 if (!empty(trim($contentString))) {
                     if(is_array($element['content']) && isset($element['content'][0]['type']) && 
                        $element['content'][0]['type'] === 'text'){ 
                        $contentTextToSend[] = ['type' => $element['content'][0]['type'], 'text' => $element['content'][0]['text']];
                        $contentToSend = $element['content']; 
                     } else { 
                        $contentTextToSend[] = array('type' => 'text', 'text' => $contentString);
                        $contentToSend = array(array('type' => 'text', 'text' => $contentString)); 
                     }
                     
                     if ($contentToSend !== null) {
                         $individualMessagesAddedCount++;
                     }
                 }
             }
        }
        //array_pop($contentTextToSend);

        // Get the index of the last element
        $lastIndex = count($contentTextToSend) - 2;

        // Make sure the array is not empty before trying to access the last element
        if ($lastIndex >= 0) {
            // Add a new field to the last entry
            // Let's say you want to add a field called "speaker"
            $contentTextToSend[$lastIndex]["cache_control"] = $cacheControl;
        }

        $finalMessagesToSend[] = array('role' => 'user', 'content' => $contentTextToSend);
        $hitDebugInfo['addedCount'] = $individualMessagesAddedCount;
        error_log("{$logPrefix} Added {$individualMessagesAddedCount} individual messages after cache/anchor point.");
        // --- End Dialogue Processing ---

        // --- Final Debug Logging for Cache Hit/Miss ---
        try {
             $logTimestamp = date(DateTime::ATOM); 
             $status = isset($hitDebugInfo['status']) ? $hitDebugInfo['status'] : 'UNKNOWN'; 
             $reason = isset($hitDebugInfo['reason']) ? $hitDebugInfo['reason'] : 'Unknown';
             $cIdx = isset($hitDebugInfo['lastCachedIndex']) ? $hitDebugInfo['lastCachedIndex'] : 'N/A'; 
             $cHash = isset($hitDebugInfo['lastCachedHash']) ? $hitDebugInfo['lastCachedHash'] : 'N/A';
             $fIdx = isset($hitDebugInfo['foundIndex']) ? $hitDebugInfo['foundIndex'] : -1; 
             $addC = isset($hitDebugInfo['addedCount']) ? $hitDebugInfo['addedCount'] : 0;
             
             $debugMsg = sprintf("[%s] CACHE_SUMMARY [%s:%s] System: %s. Dialogue: %s (Reason: %s). OrigProcCtxSize: %d, UncachedMsgsSent: %d, LastCachedIdx: %d\n", 
                $logTimestamp, $this->name, $herikaName,
                (is_array($cacheSystemMessages) && isset($cacheSystemMessages['content']) && $systemContentForCacheFile === null ? 'HIT' : 'MISS/WRITE'),
                $status, $reason,
                $n_ctxsize, $addC, $cIdx);
                
             @file_put_contents($cacheDebugLogFile, $debugMsg, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) { error_log("{$logPrefix} Cache Debug Log Err: ".$e->getMessage()); }
        // --- END Logging ----

        // --- Payload Construction ---
        $data = array(
            'model' => $model, 
            'messages' => $finalMessagesToSend,
            'stream' => true,
            'temperature' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["temperature"])) ? $GLOBALS["CONNECTOR"][$this->name]["temperature"] : 1),
            'top_k' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_k"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_k"] : 0),
            'top_p' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_p"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_p"] : 1),
            'presence_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["presence_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["presence_penalty"] : 0),
            'frequency_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["frequency_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["frequency_penalty"] : 0),
            'repetition_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["repetition_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["repetition_penalty"] : 1.15),
            'min_p' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["min_p"])) ? $GLOBALS["CONNECTOR"][$this->name]["min_p"] : 0.1),
            'top_a' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_a"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_a"] : 0),
            'transforms' => array(),
            'reasoning' => [
                "enabled" => $toggleThinking,
                "max_tokens" => $thinkingTokens,
            ],
            "cache_control" => [
                "enabled" => True,
                "ttl" => "1h"  # Cache for 5 minutes, or 1h for 1 hour.
            ]
        );
        unset($data["stop"]);
        if (isset($GLOBALS["CONNECTOR"][$this->name]["stop"]) && is_array($GLOBALS["CONNECTOR"][$this->name]["stop"])) {
             $validStop = array();
             foreach ($GLOBALS["CONNECTOR"][$this->name]["stop"] as $stopSeq) { if (is_string($stopSeq) && !empty($stopSeq)) { $validStop[] = $stopSeq; } }
             if (!empty($validStop)) { $data["stop_sequences"] = $validStop; }
        }
        $effectiveMaxTokens = null;
        if (isset($customParms["MAX_TOKENS"])) { $maxTokensValue = $customParms["MAX_TOKENS"] + 0; if ($maxTokensValue >= 0) { $effectiveMaxTokens = $maxTokensValue; } }
        else { if (isset($MAX_TOKENS)) $effectiveMaxTokens = $MAX_TOKENS; }
        if (isset($GLOBALS["FORCE_MAX_TOKENS"])) { $forceMaxTokensValue = $GLOBALS["FORCE_MAX_TOKENS"] + 0; if ($forceMaxTokensValue >= 0) { $effectiveMaxTokens = $forceMaxTokensValue; } }
        if ($effectiveMaxTokens !== null) { if ($effectiveMaxTokens > 0) { $data["max_tokens"] = (int)$effectiveMaxTokens; } else { unset($data["max_tokens"]); } }
        else { if(isset($data["max_tokens"])) unset($data["max_tokens"]); }

        $GLOBALS["FUNCTIONS_ARE_ENABLED"]=false; // Force functions off
        if (isset($GLOBALS["FUNCTIONS_ARE_ENABLED"]) && $GLOBALS["FUNCTIONS_ARE_ENABLED"]) { /* ... Function logic (now unreachable) ... */ }
        // --- End Payload Construction ---

        // --- Debugging & Request Prep ---
        $GLOBALS["DEBUG_DATA"]["full"] = ($data);
        $this->_dataSent = json_encode($data, JSON_PRETTY_PRINT);
        try {
             $sysCacheLog = 'Sys:'.(is_array($cacheSystemMessages) && isset($cacheSystemMessages['content']) && $systemContentForCacheFile === null ? 'MATCH' : 'MISMATCH/WRITE');
             $uaStatus=isset($hitDebugInfo['status'])?$hitDebugInfo['status']:'UNKNOWN'; $uaReason=isset($hitDebugInfo['reason'])?$hitDebugInfo['reason']:'';
             $uaCacheLog="UA:".$uaStatus; if($uaStatus==='MISS'&&!empty($uaReason)){$uaCacheLog.='('.$uaReason.')';}
             $cacheStatus=$sysCacheLog.'/'.$uaCacheLog;
             $finalMsgCount = isset($finalMessagesToSend)?count($finalMessagesToSend):0;
             $logEntry=sprintf("[%s] [%s:%s] [%s]\nPayload (%d msgs):\n%s\n---\n", date(DATE_ATOM), $this->name, $herikaName, $cacheStatus, $finalMsgCount, var_export($data, true));
             @file_put_contents(__DIR__."/../log/context_sent_to_llm.log", $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) { error_log("{$logPrefix} Context Log Err: ".$e->getMessage()); }

        $apiKey=isset($GLOBALS["CONNECTOR"][$this->name]["API_KEY"])?$GLOBALS["CONNECTOR"][$this->name]["API_KEY"]:'';
        if (empty($apiKey)) { error_log("{$logPrefix} API Key missing!"); return null; }
        $headers=array( 'Content-Type: application/json', "Authorization: Bearer {$apiKey}", "HTTP-Referer: https://www.nexusmods.com/skyrimspecialedition/mods/126330", "X-Title: CHIM", "anthropic-beta: prompt-caching-2024-07-31" );
        $timeout=isset($GLOBALS["HTTP_TIMEOUT"])?(int)$GLOBALS["HTTP_TIMEOUT"]:60;
        $options=array('http'=>array('method'=>'POST', 'header'=>implode("\r\n", $headers), 'content'=>json_encode($data), 'timeout'=>$timeout, 'ignore_errors'=>true ));
        $context=stream_context_create($options);
        // --- End Request Prep ---

        // --- Stream Opening ---
        $this->primary_handler = null; $this->_rawbuffer = ""; $this->_buffer = ""; $this->_forcedClose = false;
        try { $this->primary_handler = $this->send($url, $context); }
        catch (Exception $e) { error_log("fopen Exception [{$this->name}:{$herikaName}]: ".$e->getMessage()); return null; }
        if (!$this->primary_handler) {
            $error=error_get_last(); $errMsg=isset($error['message'])?$error['message']:'fopen returned false';
            error_log("Stream Open Fail [{$this->name}:{$herikaName}]: {$errMsg}");
            if(isset($http_response_header) && is_array($http_response_header)) { error_log("HTTP Headers on fail: ".implode("\n",$http_response_header)); }
            return null;
         }
        try { $logStartMsg = "\n== ".date(DATE_ATOM)." [{$this->name}:{$herikaName}] STREAM START\n\n"; @file_put_contents(__DIR__."/../log/output_from_llm.log", $logStartMsg , FILE_APPEND | LOCK_EX); }
        catch (Exception $e) { error_log("{$logPrefix} Output Log Start Err: ".$e->getMessage()); }
        // --- End Stream Opening ---

        return true;
    }

    public function send($url, $context) {
        if (isset($GLOBALS['mockConnectorSend']) && is_callable($GLOBALS['mockConnectorSend'])) { return call_user_func($GLOBALS['mockConnectorSend'], $url, $context); }
        return @fopen($url, 'r', false, $context);
    }

     public function process() {
        global $alreadysent;
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        if ($this->isDone()) return "";
        $line = @fgets($this->primary_handler);
        if ($line === false) {
            if (feof($this->primary_handler)){ return ""; }
            else { $error=error_get_last(); $errMsg=isset($error['message'])?$error['message']:'fgets error'; error_log("Read Err [{$this->name}:{$herikaName}]: {$errMsg}"); $this->_rawbuffer.="\nRead Err: {$errMsg}\n"; $this->_forcedClose=true; return -1; }
        }
        try { @file_put_contents(__DIR__."/../log/debugStream.log", $line, FILE_APPEND | LOCK_EX); } catch(Exception $e){}
        $this->_rawbuffer .= $line;
        $buffer = "";
        if (strpos($line, 'data: ') === 0) {
            $jsonData = trim(substr($line, 6));
            if ($jsonData === '[DONE]') { return ""; }
            if (!empty($jsonData)) {
                $data = json_decode($jsonData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $chunkContent = null; $isStop = false;
                    if (isset($data['type'])) { // Anthropic Format
                       switch ($data['type']) {
                           case 'content_block_delta': if (isset($data['delta']['type']) && $data['delta']['type']==='text_delta' && isset($data['delta']['text'])) { $chunkContent=$data['delta']['text']; } break;
                           case 'message_delta': if (isset($data['delta']['stop_reason']) && $data['delta']['stop_reason']!==null) { error_log("[{$this->name}:{$herikaName}] Stop (delta): ".$data['delta']['stop_reason']); $isStop=true; } break;
                           case 'message_stop': 
                               error_log("[{$this->name}:{$herikaName}] Stop (message_stop). Usage:".(isset($data['message']['usage'])?json_encode($data['message']['usage']):'N/A')); 
                               if (isset($data['message']['usage'])) {
                                   $usage = $data['message']['usage'];
                                   $cacheRead = isset($usage['cache_read_input_tokens']) ? $usage['cache_read_input_tokens'] : 0;
                                   $cacheCreate = isset($usage['cache_creation_input_tokens']) ? $usage['cache_creation_input_tokens'] : 0;
                                   $normalInput = isset($usage['input_tokens']) ? $usage['input_tokens'] : 0; 
                                   $totalConsideredInput = $cacheRead + $cacheCreate + $normalInput;
                                   $efficiency = ($totalConsideredInput > 0) ? round(($cacheRead / $totalConsideredInput * 100),1) : 0;
                                   $logPerfEntry = sprintf("[%s] ANTHROCACHE %s: Read:%d Create:%d New:%d TotalIn:%d Efficiency:%.1f%%\n",
                                      date(DATE_ATOM), $herikaName,
                                      $cacheRead, $cacheCreate, $normalInput, $totalConsideredInput, $efficiency
                                   );
                                   @file_put_contents(__DIR__.DIRECTORY_SEPARATOR."_anthropic_cache_perf.log", $logPerfEntry, FILE_APPEND);
                               }
                               $isStop=true; 
                               break;
                           case 'error': $eM=print_r((isset($data['error'])?$data['error']:$data),true); error_log("Stream Err (Anthropic): {$eM}"); $this->_rawbuffer.="\nErr (Anthropic):{$eM}\n"; $this->_forcedClose=true; return -1;
                           case 'ping': break; // Ignore
                           default: error_log("[{$this->name}:{$herikaName}] Unhandled Anthropic Type: ".$data['type']); break;
                       }
                    } elseif (isset($data["choices"][0]["delta"]["content"])) { // OpenAI Format
                         $chunkContent=$data["choices"][0]["delta"]["content"];
                         if(isset($data["choices"][0]["finish_reason"]) && $data["choices"][0]["finish_reason"]!==null){ error_log("[{$this->name}:{$herikaName}] Stop (choice): ".$data["choices"][0]["finish_reason"]); $isStop = true; }
                    } elseif (isset($data['error'])) { // Generic Error
                        $eM=print_r($data['error'],true); error_log("Stream Err (Generic): {$eM}"); $this->_rawbuffer.="\nErr (Generic):{$eM}\n"; $this->_forcedClose=true; return -1;
                    }
                    if ($chunkContent !== null && is_string($chunkContent)) { $buffer.=$chunkContent; $this->_buffer.=$chunkContent; }
                    if (isset($data["choices"][0]["delta"]["function_call"])) { // Function Call Buffering
                        $fC=$data["choices"][0]["delta"]["function_call"];
                        if(isset($fC["name"])){ $this->_functionName=$fC["name"]; }
                        if(isset($fC["arguments"])){ $this->_parameterBuff.=$fC["arguments"]; }
                    }
                    if (isset($data["choices"][0]["finish_reason"]) && $data["choices"][0]["finish_reason"] == "function_call") { error_log("[{$this->name}:{$herikaName}] finish_reason: function_call detected. Buffered for processActions()."); }
                    
                    // Stop processing if we received a stop signal
                    if ($isStop) {
                        $this->_forcedClose = true;
                    }
                } else { error_log("JSON Decode Err [{$this->name}:{$herikaName}]: ".json_last_error_msg()." Data: ".substr($jsonData,0,150)."..."); }
            }
        } elseif (trim($line) === "event: message_stop") {
            error_log("[{$this->name}:{$herikaName}] Explicit stream end event received.");
            $this->_forcedClose = true;
        } elseif (!empty(trim($line))) {
             // Log unexpected non-SSE line including OpenRouter preamble
             error_log("Unexpected non-SSE line [{$this->name}:{$herikaName}]: ". substr(trim($line), 0, 150)."...");
             $errorData=@json_decode(trim($line), true); // Check if it's a non-stream JSON error
             if (json_last_error()===JSON_ERROR_NONE && is_array($errorData) && isset($errorData['error'])) { $eM=print_r($errorData['error'],true); error_log("Non-stream Err: {$eM}"); $this->_rawbuffer.="\nNon-stream Err:{$eM}\n"; $this->_forcedClose=true; return -1; }
        }
        return $buffer;
    }

    public function close() {
        if ($this->primary_handler) { @fclose($this->primary_handler); $this->primary_handler = null; }
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        try {
            $raw=isset($this->_rawbuffer)?$this->_rawbuffer:'<empty>'; $proc=isset($this->_buffer)?$this->_buffer:'<empty>';
            $logContent=sprintf("Raw Stream Data:\n%s\n\nProcessed Text:\n%s\n\n[%s] [%s:%s] END STREAM\n==\n", $raw, $proc, date(DATE_ATOM), $this->name, $herikaName);
            @file_put_contents(__DIR__."/../log/output_from_llm.log", $logContent, FILE_APPEND | LOCK_EX);
         } catch (Exception $e) { error_log("[{$this->name}:{$herikaName}] Close Log Err: ".$e->getMessage()); }
        $finalBuffer = $this->_buffer;
        $this->_buffer = ""; $this->_rawbuffer = ""; $this->_functionName = null; $this->_parameterBuff=""; $this->_forcedClose = false;
        return $finalBuffer;
    }

     public function processActions() {
        global $alreadysent;
        $this->_commandBuffer = array();
        if ($this->_functionName && $this->_parameterBuff !== null) {
             $parameterArr = @json_decode($this->_parameterBuff, true);
             if (is_array($parameterArr)) {
                 $parameter = current($parameterArr); $parameterAsString = is_scalar($parameter) ? (string)$parameter : json_encode($parameter);
                 $commandKey = md5("Herika|command|{$this->_functionName}@{$parameterAsString}\r\n");
                 if (!isset($alreadysent[$commandKey])) {
                     $functionCodeName = function_exists('getFunctionCodeName') ? getFunctionCodeName($this->_functionName) : $this->_functionName;
                     $commandString = "Herika|command|{$functionCodeName}@{$parameterAsString}\r\n"; $this->_commandBuffer[] = $commandString; $alreadysent[$commandKey] = $commandString;
                     error_log("[{$this->name}:processActions] Generated command: {$commandString}"); if (ob_get_level()) { @ob_flush(); @flush(); }
                 } else { error_log("[{$this->name}:processActions] Command already sent (skipped): Herika|command|{$this->_functionName}@{$parameterAsString}"); }
                 $this->_functionName = null; $this->_parameterBuff = "";
             } elseif ($this->_parameterBuff === '{}') {
                 $parameterStr = '{}'; $commandKey = md5("Herika|command|{$this->_functionName}@{$parameterStr}\r\n");
                 if (!isset($alreadysent[$commandKey])) {
                     $functionCodeName = function_exists('getFunctionCodeName') ? getFunctionCodeName($this->_functionName) : $this->_functionName;
                     $commandString = "Herika|command|{$functionCodeName}@{$parameterStr}\r\n"; $this->_commandBuffer[] = $commandString; $alreadysent[$commandKey] = $commandString;
                     error_log("[{$this->name}:processActions] Generated command (empty params): {$commandString}"); if (ob_get_level()) { @ob_flush(); @flush(); }
                 } else { error_log("[{$this->name}:processActions] Command already sent (skipped): Herika|command|{$this->_functionName}@{}"); }
                 $this->_functionName = null; $this->_parameterBuff = "";
             } else {
                 if(!empty($this->_parameterBuff)) { error_log("[{$this->name}:processActions] Failed func param decode/not array. Content: ".$this->_parameterBuff); }
                 else { error_log("[{$this->name}:processActions] Func call detected, param buffer empty."); }
                 $this->_functionName = null; $this->_parameterBuff = "";
             }
        }
        return isset($this->_commandBuffer) ? $this->_commandBuffer : array();
    }

     public function isDone() {
        if ($this->_forcedClose) { if ($this->primary_handler) { @fclose($this->primary_handler); $this->primary_handler = null; } return true; }
        return !$this->primary_handler || feof($this->primary_handler);
    }
}
?>
