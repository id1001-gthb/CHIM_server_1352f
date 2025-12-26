<?php

$enginePath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;


function logMessage(string|array $message, ?string $context = null, string $level = 'INFO', string $logFile = 'cache.log'): bool
{
    $timestamp = date('Y-m-d H:i:s');
    if ($message == null) {
            // Format the log entry: [YYYY-MM-DD HH:MM:SS] LEVEL: Your message
        $logEntry = "[{$timestamp}] {$level}: Null message\n";

        // Attempt to write the log entry to the file
        // FILE_APPEND ensures the content is added to the end of the file.
        // LOCK_EX acquires an exclusive lock, preventing race conditions during concurrent writes.
        $result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        return true;
    }
    // Get the current timestamp in a readable format
    $logFile = __DIR__ . "/../log/" . $logFile;
    $formattedMessage = '';

    // If the message is an array, convert it to a JSON string
    if (is_array($message)) {
        // Use JSON_PRETTY_PRINT for better readability in the log file,
        // or remove it for a single-line compact JSON string.
        $jsonMessage = json_encode($message, JSON_PRETTY_PRINT);
        if ($jsonMessage === false) {
            // Fallback if json_encode fails (e.g., circular reference)
            $jsonMessage = "Failed to encode array to JSON. Original: " . print_r($message, true);
        }

        // If a context string is provided, prepend it to the formatted message
        if ($context !== null && $context !== '') {
            $formattedMessage = "{$context} \n {$jsonMessage}";
        } else {
            $formattedMessage = $jsonMessage;
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
        // Note: Using error_log for internal errors to avoid recursion if logMessage fails
        error_log("Failed to write to log file: {$logFile}. Original message: " . (is_array($message) ? json_encode($message) : $message));
        return false;
    }

    return true;
}

function removeDuplicateMemories($array) {
    $seenMemories = [];
    $filteredArray = [];
    
    foreach ($array as $index => $item) {
        // Check if this is a memory entry
        if (isset($item['text']) && strpos($item['text'], '#MEMORY:') === 0) {
            $memoryText = $item['text'];
            
            // Normalize whitespace for comparison
            $normalizedMemory = preg_replace('/\s+/', ' ', trim($memoryText));
            
            // Only keep if we haven't seen this normalized memory before
            if (!isset($seenMemories[$normalizedMemory])) {
                $seenMemories[$normalizedMemory] = true;
                $filteredArray[] = $item;
            }
            // Skip duplicates
        } else {
            // Not a memory entry, always keep it
            $filteredArray[] = $item;
        }
    }
    
    logMessage("Memories:");
    logMessage($seenMemories);
    return $filteredArray;
}

function countTokensByWords($array) {
    $totalTokens = 0;
    
    foreach ($array as $item) {
        if (isset($item['text'])) {
            // Remove extra whitespace and split by spaces
            $words = preg_split('/\s+/', trim($item['text']), -1, PREG_SPLIT_NO_EMPTY);
            $totalTokens += count($words);
        }
    }
    
    return $totalTokens;
}

function getLastEntryByCharacter($array, $characterName) {
    // Loop through array in reverse order to find the last entry
    for ($i = count($array) - 1; $i >= 0; $i--) {
        $entry = $array[$i];
        
        // Skip if not a text entry
        if (!isset($entry['type']) || $entry['type'] !== 'text' || !isset($entry['text'])) {
            continue;
        }
        
        $text = $entry['text'];
        
        // Look for character dialogue pattern: "Character: [dialogue]"
        // This excludes action text like "Serana casts Ice Spike"
        if (preg_match('/^([^:]+):\s*(.+)/', $text, $matches)) {
            $speaker = trim($matches[1]);
            $dialogue = trim($matches[2]);
            
            // Skip if it's just an action (like "casts", "engages combat", etc.)
            if (!preg_match('/^(casts|engages|died|found|activates|has defeated)/i', $dialogue)) {
                // Check if this entry matches our target character
                if (strcasecmp($speaker, $characterName) === 0) {
                    return $entry;
                }
            }
        }
    }
    
    return ''; // Character not found
}

function writeArrayToFileWithCache($array, $filename, $cacheHours = 1)
{
    // Check if file exists and get its modification time
    $filename = __DIR__ . "/../temp/" . $filename;
    $directory = dirname($filename);
    // Create directory if it doesn't exist
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true)) {
            throw new Exception("Failed to create directory: " . $directory);
        }
    }
    if (file_exists($filename)) {
        $fileModTime = filemtime($filename);
        $currentTime = time();
        $cacheExpiry = $cacheHours * 3600; // Convert hours to seconds

        // If file is newer than the cache expiry time, read and return its contents
        if (($currentTime - $fileModTime) < $cacheExpiry) {
            $fileContents = file_get_contents($filename);
            if ($fileContents !== false) {
                // Attempt to unserialize the data
                $cachedArray = unserialize($fileContents);
                if ($cachedArray !== false) {
                    touch($filename);
                    logMessage("Return cached System entry.");
                    return $cachedArray;
                }
            }
        }
    }

    // File doesn't exist, is older than 1 hour, or couldn't be read properly
    // Serialize the array and write it to the file
    $serializedArray = serialize($array);
    $result = file_put_contents($filename, $serializedArray);

    if ($result === false) {
        throw new Exception("Failed to write array to file: " . $filename);
    }

    logMessage("Return un-cached System entry.");
    return $array;
}

function manageCharacterEventList($newList, $filename = 'conversation_list.json', $maxLength = 93, $maxAge = 3600)
{
    logMessage("Max length of cached event history: $maxLength");
    $filename = __DIR__ . "/../temp/" . $filename;
    // Load existing list from file
    $directory = dirname($filename);
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true)) {
            throw new Exception("Failed to create directory: " . $directory);
        }
    }
    $existingList = [];
    if (file_exists($filename)) {
        $fileContent = file_get_contents($filename);
        if ($fileContent !== false) {
            $decoded = json_decode($fileContent, true);
            if ($decoded !== null) {
                $existingList = $decoded;
            }
        }

        // Check if file is older than maxAge (default 1 hour)
        $fileModTime = filemtime($filename);
        $currentTime = time();
        $fileAge = $currentTime - $fileModTime;
        if ($fileAge >= $maxAge) {
            logMessage("cleared cache because it is older than one hour");
            $existingList = [];
        }
    }

    // Check if max length exceeded
    if (count($existingList) >= $maxLength) {
        if (file_exists($filename)) {
            unlink($filename);
        }
        logMessage("cleared cached dialogue");
        $existingList = []; // Clear the list
    }


    // Find new elements that don't exist in the original list
    $newElements = [];

    foreach ($newList as $newItem) {
        $found = false;
        foreach ($existingList as $existingItem) {
            if (arraysEqual($newItem, $existingItem)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $newElements[] = $newItem;
        }
    }

    // Add new elements to existing list

    $updatedList = array_merge($existingList, $newElements);

    // Remove neighboring duplicates
    $duplicatesRemoved = 0;
    $updatedList = removeNeighboringDuplicates($updatedList, $duplicatesRemoved);
    logMessage("Duplicates removed: $duplicatesRemoved");

    $updatedListCount = count($updatedList);

    // Save updated list back to file
    file_put_contents($filename, json_encode($updatedList, JSON_PRETTY_PRINT));
    logMessage("Current length of cached event history: $updatedListCount");
    return [
        'updated_list' => $updatedList,
        'existing_list' => $existingList,
        'new_elements' => $newElements,
        'duplicatesRemoved' => $duplicatesRemoved,
        'new_count' => count($newElements)
    ];
}

function removeNeighboringDuplicates($array, &$duplicatesRemoved)
{
    if (empty($array)) {
        return $array;
    }

    $result = [$array[0]]; // Always keep the first element
    $duplicatesRemoved = 0;

    for ($i = 1; $i < count($array); $i++) {
        // Compare current element with the previous one
        if (!arraysEqual($array[$i], $array[$i - 1])) {
            $result[] = $array[$i];
        } else {
            $duplicatesRemoved++;
        }
    }

    return $result;
}

function arraysEqual($array1, $array2)
{
    // Convert arrays to JSON strings for comparison
    return json_encode($array1) === json_encode($array2);
}

function extract_any_subsection(&$source, $subsectionName, $extractAll = false) {
    // Pattern to match ### level subsections with the specific name
    $pattern = '/(^### ' . preg_quote($subsectionName, '/') . '.*?)(?=^###|^##|^# |\z)/msi';

    if ($extractAll) {
        // Extract ALL instances of this subsection
        if (preg_match_all($pattern, $source, $matches)) {
            $extracted = [];
            foreach ($matches[1] as $match) {
                $extracted[] = trim($match);
            }
            // Remove all instances from the source
            $source = preg_replace($pattern, '', $source);

            // Combine with header
            $combinedContent = implode("\n\n", $extracted);
            return $subsectionName . ":\n" . $combinedContent;
        }
    } else {
        // Extract only the first instance (for single-occurrence sections)
        if (preg_match($pattern, $source, $matches)) {
            $extracted = trim($matches[1]);
            // Remove this subsection from the source
            $source = preg_replace($pattern, '', $source, 1);
            return $subsectionName . ":\n" . $extracted;
        }
    }
}

function containsOnlySymbols(string $str): bool
{
    // This regex allows newlines, tabs, and anything that is NOT
    // a letter (a-z, A-Z), a number (0-9), or a space.
    // So, it allows !@#$%^&*()_+-=[]{};':"|,./<>?\`~ and whitespace characters other than space
    return (bool) preg_match('/^[\n\t\r\f\v!@#$%^&*()_+\-=\[\]{};\':"|,.<>\/?`~]+$/', $str);
}


// --- END: Standalone Cache Helper Functions ---

function extractJson($text)
{
    // Find the starting position of JSON
    $start = strpos($text, '{');
    if ($start === false) {
        return $text;
    }

    $braceCount = 0;
    $inString = false;
    $escaped = false;

    for ($i = $start; $i < strlen($text); $i++) {
        $char = $text[$i];

        if (!$inString) {
            if ($char === '{') {
                $braceCount++;
            } elseif ($char === '}') {
                $braceCount--;
                if ($braceCount === 0) {
                    // Found the end of JSON
                    return substr($text, $start, $i - $start + 1);
                }
            } elseif ($char === '"') {
                $inString = true;
            }
        } else {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = false;
            }
        }
    }

    return $text;
}

function extract_specific_section(&$source, $sectionHeader)
{
    // Escape the header for regex and match any level of underlines if present
    $pattern = '/##+#\s*' . preg_quote($sectionHeader) . '\s*\R[\s\S]*?(?=\R#|$)/';

    if (preg_match($pattern, $source, $matches)) {
        $extracted = $matches[0];
        $source = preg_replace($pattern, '', $source, 1);
        return $extracted;
    }
    return null;
}

function extract_and_remove_section(&$text, $section_name)
{
    // Pattern includes section header and content, up to but not including next top-level header or end
    $pattern = '/(^# ' . preg_quote($section_name, '/') . '.*?)(?=^# |\z)/msi';

    if (preg_match($pattern, $text, $matches)) {
        // Remove section from $text by replacing with empty string
        $text = preg_replace($pattern, '', $text, 1);
        return trim($matches[1]);
    } else {
        return '';
    }
}

function lazyEmpty($string)
{

    if (empty(trim($string)))
        return true;

    if (trim($string) == "Null")
        return true;

    if (trim($string) == "null")
        return true;

    if (trim($string) == "None")
        return true;

    if (trim($string) == "none")
        return true;

}

class openrouterjsonanthropic
{
    // --- Properties ---
    public $primary_handler;
    public $finfo;
    public $name;
    private $_functionName;
    private $_parameterBuff;
    private $_commandBuffer;
    public $_extractedbuffer;
    private $_buffer;
    private $_dataSent;
    private $_rawbuffer;
    private $_forcedClose = false;
    public $_jsonResponsesEncoded = array();



    public function __construct()
    {
        $this->name = "openrouterjsonanthropic";
        $this->_commandBuffer = array();
        $this->_buffer = "";
        $this->_extractedbuffer = "";
        $this->_dataSent = null;
        $this->_rawbuffer = "";
        $this->_forcedClose = false;
        $this->_functionName = null;
        $this->_parameterBuff = "";
        $this->_jsonResponsesEncoded = array();
    }

    public function open($contextData, $customParms)
    {
        // --- Setup and Logging ---
        $start_time = microtime(true);
        require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "functions" . DIRECTORY_SEPARATOR . "json_response.php");

        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        $n_ctxsize = count($contextData);

        logMessage("[{$this->name}:{$herikaName}] OPEN START: Received contextData with {$n_ctxsize} elements.");

        // --- Config ---
        $url = isset($GLOBALS["CONNECTOR"][$this->name]["url"]) ? $GLOBALS["CONNECTOR"][$this->name]["url"] : '';
        $MAX_TOKENS = ((isset($GLOBALS["CONNECTOR"][$this->name]["max_tokens"]) ? $GLOBALS["CONNECTOR"][$this->name]["max_tokens"] : 4096) + 0);
        $model = (isset($GLOBALS["CONNECTOR"][$this->name]["model"])) ? $GLOBALS["CONNECTOR"][$this->name]["model"] : 'anthropic/claude-3-haiku-20240307';
        $max_dialogue_cache_size = ((isset($GLOBALS["CONNECTOR"][$this->name]["max_dialogue_cache_context_size"]) ? $GLOBALS["CONNECTOR"][$this->name]["max_dialogue_cache_context_size"] : $n_ctxsize * 4) + 0);
        $customInstruction = isset($GLOBALS["CONNECTOR"][$this->name]["custom_last_instruction"]) ? $GLOBALS["CONNECTOR"][$this->name]["custom_last_instruction"] : '';
        $toggleThinking = isset($GLOBALS["CONNECTOR"][$this->name]["toggle_thinking"]) ? $GLOBALS["CONNECTOR"][$this->name]["toggle_thinking"] : false;
        $thinkingTokens = isset($GLOBALS["CONNECTOR"][$this->name]["thinking_tokens"]) ? $GLOBALS["CONNECTOR"][$this->name]["thinking_tokens"] : 1000;
        $provider_caching = isset($GLOBALS["CONNECTOR"][$this->name]["provider_caching"]) ? $GLOBALS["CONNECTOR"][$this->name]["provider_caching"] : "Anthropic";
        $effort_level = isset($GLOBALS["CONNECTOR"][$this->name]["effort_level"]) ? $GLOBALS["CONNECTOR"][$this->name]["effort_level"] : "low";
        logMessage("provider caching: $provider_caching");
        $CONTEXTHISTORY = $GLOBALS['CONTEXT_HISTORY'];
        logMessage("CONTEXT HISTORY: $CONTEXTHISTORY");

        $lastCustomInstruction = isset($GLOBALS["CONNECTOR"][$this->name]["custom_last_user_instruction"]) ? $GLOBALS["CONNECTOR"][$this->name]["custom_last_user_instruction"] : '';

        // --- Caching file names ---
        $cacheSystemFile = "system_cache_json_{$herikaName}.tmp";
        $cacheCombinedDialogueFile = "combined_dialogue_cache_json_{$herikaName}.tmp";

        $cacheControlType = ["type" => "ephemeral", "ttl" => "1h"];

        // build actions and json instruction
        if (isset($GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]) && $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]) {
            $prefix = "{$GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]}";
        } else {
            $prefix = "";
        }
        if (strpos($GLOBALS["HERIKA_PERS"], "#SpeechStyle") !== false) {
            $speechReinforcement = "Use #SpeechStyle.";
        } else
            $speechReinforcement = "";

        $zonosTones = $GLOBALS["TTSFUNCTION"] == "zonos_gradio" ? " (Response tones are mandatory in the response)" : "";

        $jsonResponseInstruction = "{$prefix} $speechReinforcement $customInstruction Use ONLY this JSON object to give your answer. Do not send any other characters outside of this JSON structure$zonosTones: " . json_encode($GLOBALS["responseTemplate"]);

        // remove dynamic targets, that might be shitty when we cache them
        $availableActions = preg_replace('/\(available targets:[^\n]*/', '', $GLOBALS["COMMAND_PROMPT"]);

        $actionsText = "\n" .
            $availableActions .
            "\n" .
            $jsonResponseInstruction;

        $dynamicEnvironment = "";
        // Collect all system prompts
        $systemEntries = [];
        foreach ($contextData as $n => $element) {
            if (isset($element["role"]) && $element["role"] == "system") {
                $systemContentString = '';
                if (is_string($element['content'])) {
                    $systemContentString = $element['content'];
                } elseif (is_array($element['content']) && isset($element['content'][0]['type']) && $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])) {
                    $systemContentString = $element['content'][0]['text'];
                }
                $trimmedSystemContent = trim($systemContentString);
                $systemContentOriginal = $trimmedSystemContent;
                $systemContentCurrent = $systemContentOriginal;


                // extract dynamic relevant parts
                $environmental = extract_and_remove_section($systemContentCurrent, 'Environmental Context');
                $additional = extract_and_remove_section($systemContentCurrent, 'Additional Information');

                $equipment = extract_any_subsection($systemContentCurrent, 'Equipment', true); // extractAll = true
                $appearance = extract_any_subsection($systemContentCurrent, 'Physical Appearance', false); // single instance
                $cleanliness = extract_any_subsection($systemContentCurrent, 'Cleanliness', true); // extractAll = true

                $additionalCharacter = extract_specific_section($systemContentCurrent, 'Additional Character Information');
                $combatStatus = extract_specific_section($systemContentCurrent, 'Combat Vitals');
                $arousal = extract_specific_section($systemContentCurrent, sectionHeader: 'Arousal Status');

                $dynamicEnvironment = $environmental . "\n\n" . $additional . "\n\n" . $additionalCharacter . "\n\n" . $combatStatus . "\n\n" . $arousal . "\n\n" . $equipment . "\n\n" . $appearance . "\n\n" . $cleanliness;

                $finalSend = $systemContentCurrent . "\n" . $actionsText;

                $content = ['type' => 'text', 'text' => $finalSend];
                if ($provider_caching !== "OpenAI") {
                    $content['cache_control'] = $cacheControlType;
                }
                $systemEntries[] = array("role" => "system", "content" => array($content));
            }
        }

        $finalMessagesToSend = writeArrayToFileWithCache($systemEntries, $cacheSystemFile);
        // --- End System Processing ---
        $characters = DataBeingsInRange();
        logMessage("nearby character: $characters");
        // --- Step 2: Process Combined Dialogue History ---

        // Add individual messages
        $contentTextToSend = [];
        foreach ($contextData as $n => $element) {
            if (!isset($element))
                continue;

            if (isset($element["role"]) && $element["role"] != "system") {
                $contentString = '';
                if (is_string($element['content'])) {
                    $contentString = $element['content'];
                } elseif (
                    is_array($element['content']) && isset($element['content'][0]['type']) &&
                    $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])
                ) {
                    $contentString = $element['content'][0]['text'];
                }
                if (containsOnlySymbols($contentString)) {
                    continue;
                }
                if (!empty(trim($contentString))) {
                    if (
                        is_array($element['content']) && isset($element['content'][0]['type']) &&
                        $element['content'][0]['type'] === 'text'
                    ) {
                        $contentString = $element['content'][0]['text'];
                    }
                    $contentTextToSend[] = array('type' => 'text', 'text' => "$contentString");
                }
            }
        }
        // remove unnecessary stuff for caching
        if (count($contentTextToSend) > 4) {
            $contentTextToSend = array_slice($contentTextToSend, 4);
        }
        // remove instruction to add back later
        $instruction = array_pop($contentTextToSend);

        // do caching stuff
        $completeEventList = manageCharacterEventList($contentTextToSend, $cacheCombinedDialogueFile, $max_dialogue_cache_size);

        logMessage("New elements added to cache: {$completeEventList['new_count']}");

        $completeEventList = $completeEventList['updated_list'];

        $addToIndex = 0;
        if (!empty($lastCustomInstruction)) {
            $addToIndex = 1;
            $completeEventList[] = ['type' => 'text', 'text' => $lastCustomInstruction];
        }
        
        $completeEventList[] = $instruction;
        // Get the index of the last element
        $lastIndex = count($completeEventList) - (3+$addToIndex);

        // Make sure the array is not empty before trying to access the last element
        if ($lastIndex >= 0) {
            if ($provider_caching == "Gemini") {
                logMessage("Using gemini caching");
                $offset = 10;
                $elements = count($completeEventList);
                $batchSize = $CONTEXTHISTORY - $offset;

                $batchNumber = floor($elements / $batchSize);

                logMessage("elements: $elements, batchsize: $batchSize, batchnumber: $batchNumber");

                $indexToCache = max(0, ($batchNumber * $CONTEXTHISTORY) - $offset);

                // Bounds check
                if ($indexToCache >= $elements) {
                    logMessage("index bigger or equal then elements size.");
                    $indexToCache = $elements - 1;
                }

                if ($indexToCache == 0) {
                    $indexToCache = 33;
                }

                logMessage("Index to Cache: $indexToCache");

                // Verify key exists
                if (isset($completeEventList[$indexToCache]) && $provider_caching != "OpenAI") {
                    $completeEventList[$indexToCache]["cache_control"] = $cacheControlType;
                } else {
                    logMessage("Warning: Index $indexToCache not found in array");
                }
            } else {
                if (isset($completeEventList[$lastIndex]) && $provider_caching != "OpenAI") {
                    $completeEventList[$lastIndex]["cache_control"] = $cacheControlType;
                } else {
                    logMessage("Warning: Index $lastIndex not found in array for non gemini");
                }
            }   
        }
        
        if (!containsOnlySymbols($dynamicEnvironment)) {
            // Remove headlines
            $text = preg_replace('/^\s*#+.*$/m', '', $dynamicEnvironment);
            
            // Remove bullet points and dashes
            $text = preg_replace('/^\s*[-•]\s*/', '', $text);
            
            // Remove multiple spaces and newlines
            $text = preg_replace('/\s+/', ' ', $text);
            
            // Remove extra punctuation
            $text = preg_replace('/[.]{2,}/', '.', $text);
            
            $dynamicEnvironment = trim("ASSISTANT: Environmental Context: $text");

            array_splice($completeEventList, count($completeEventList) - 2, 0, [array('type' => 'text', 'text' => $dynamicEnvironment)]);
        }

        $completeEventList = removeDuplicateMemories($completeEventList);

        $finalMessagesToSend[] = array('role' => 'user', 'content' => $completeEventList);
        $tokenCount = countTokensByWords($completeEventList);
        logMessage($tokenCount);

        // --- End Dialogue Processing ---

        // Payload Construction
        $reasoning = [
            "enabled" => $toggleThinking,
        ];

        // Only add effort if the boolean condition is true
        if ($provider_caching === "OpenAI") {
            $reasoning["effort"] = $effort_level;
        } else {
            $reasoning["max_tokens"] = intval($thinkingTokens);
        }


        // Payload Construction
        $data = array(
            'model' => $model,
            'messages' => $finalMessagesToSend,
            'stream' => true,
            'temperature' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["temperature"])) ? $GLOBALS["CONNECTOR"][$this->name]["temperature"] : 1),
            'top_k' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_k"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_k"] : 0),
            'top_p' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_p"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_p"] : 1),
            'frequency_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["frequency_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["frequency_penalty"] : 0),
            'presence_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["presence_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["presence_penalty"] : 0),
            'repetition_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["repetition_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["repetition_penalty"] : 1),
            'min_p' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["min_p"])) ? $GLOBALS["CONNECTOR"][$this->name]["min_p"] : 0),
            'top_a' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_a"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_a"] : 0),
            'reasoning' => $reasoning,
            "cache_control" => [
                "enabled" => True,
                "ttl" => "1h"  # Cache for 5 minutes, or 1h for 1 hour.
            ]
        );

        // Prepare tool definition for JSON mode
        $tools = array();
        if (isset($GLOBALS["CONNECTOR"][$this->name]["tools"]) && is_array($GLOBALS["CONNECTOR"][$this->name]["tools"]) && !empty($GLOBALS["CONNECTOR"][$this->name]["tools"])) {
            $tools = $GLOBALS["CONNECTOR"][$this->name]["tools"];
            $data['tools'] = $tools;

            // Only add tool_choice if tools are provided and non-empty
            if (isset($GLOBALS["CONNECTOR"][$this->name]["tool_choice"]) && is_string($GLOBALS["CONNECTOR"][$this->name]["tool_choice"])) {
                $data['tool_choice'] = $GLOBALS["CONNECTOR"][$this->name]["tool_choice"];
            }
        }

        // Additional settings (stop sequences, max tokens, etc.)
        if (isset($GLOBALS["CONNECTOR"][$this->name]["stop"]) && is_array($GLOBALS["CONNECTOR"][$this->name]["stop"])) {
            $validStop = array();
            foreach ($GLOBALS["CONNECTOR"][$this->name]["stop"] as $stopSeq) {
                if (is_string($stopSeq) && !empty($stopSeq)) {
                    $validStop[] = $stopSeq;
                }
            }
            if (!empty($validStop)) {
                $data["stop_sequences"] = $validStop;
            }
        } else {
            // Default stop sequence if not specified
            $data["stop_sequences"] = array("USER");
        }

        $effectiveMaxTokens = null;
        if (isset($customParms["MAX_TOKENS"])) {
            $maxTokensValue = $customParms["MAX_TOKENS"] + 0;
            if ($maxTokensValue >= 0) {
                $effectiveMaxTokens = $maxTokensValue;
            }
        } else {
            if (isset($MAX_TOKENS))
                $effectiveMaxTokens = $MAX_TOKENS;
        }
        if (isset($GLOBALS["FORCE_MAX_TOKENS"])) {
            $forceMaxTokensValue = $GLOBALS["FORCE_MAX_TOKENS"] + 0;
            if ($forceMaxTokensValue >= 0) {
                $effectiveMaxTokens = $forceMaxTokensValue;
            }
        }
        if ($effectiveMaxTokens !== null) {
            if ($effectiveMaxTokens > 0) {
                $data["max_tokens"] = (int) $effectiveMaxTokens;
            } else {
                unset($data["max_tokens"]);
            }
        } else {
            if (isset($data["max_tokens"]))
                unset($data["max_tokens"]);
        }

        // Add provider information if available
        if (!empty($GLOBALS["CONNECTOR"][$this->name]["PROVIDER"])) {
            $providers = explode(",", $GLOBALS["CONNECTOR"][$this->name]["PROVIDER"]);
            $data["provider"] = array("order" => $providers);
        } else {
            $data["provider"] = array("order" => array("Anthropic"));
        }

        // Add transforms (empty array as in openrouterjson.php)
        $data["transforms"] = array();

        // Debug & Request Prep
        $GLOBALS["DEBUG_DATA"]["full"] = ($data);
        $this->_dataSent = json_encode($data, JSON_PRETTY_PRINT);

        try {
            $finalMsgCount = isset($finalMessagesToSend) ? count($finalMessagesToSend) : 0;
            $logEntry = sprintf(
                "[%s] [%s:%s]\nPayload (%d msgs):\n%s\n---\n",
                date(DATE_ATOM),
                $this->name,
                $herikaName,
                $finalMsgCount,
                var_export($data, true)
            );
            @file_put_contents(__DIR__ . "/../log/context_sent_to_llm.log", $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            logMessage("{$logPrefix} Context Log Err: " . $e->getMessage());
        }

        // API Request Preparation
        $apiKey = isset($GLOBALS["CONNECTOR"][$this->name]["API_KEY"]) ? $GLOBALS["CONNECTOR"][$this->name]["API_KEY"] : '';
        if (empty($apiKey)) {
            logMessage("{$logPrefix} API Key missing!");
            return null;
        }
        $headers = array(
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
            "HTTP-Referer: https://www.nexusmods.com/skyrimspecialedition/mods/126330",
            "X-Title: CHIM",
            "anthropic-beta: extended-cache-ttl-2025-04-11" // Keep cache header
        );

        $timeout = isset($GLOBALS["HTTP_TIMEOUT"]) ? (int) $GLOBALS["HTTP_TIMEOUT"] : 60;
        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($data),
                'timeout' => $timeout,
                'ignore_errors' => true
            )
        );

        $context = stream_context_create($options);

        // Stream Opening
        $this->primary_handler = null;
        $this->_rawbuffer = "";
        $this->_buffer = "";
        $this->_forcedClose = false;
        $this->_jsonResponsesEncoded = array();

        $end_time = microtime(true);
        $execution_time = $end_time - $start_time;
        logMessage("Time for preparing cached request: $execution_time seconds");

        try {
            $this->primary_handler = $this->send($url, $context);
        } catch (Exception $e) {
            logMessage("fopen Exception [{$this->name}:{$herikaName}]: " . $e->getMessage());
            return null;
        }

        if (!$this->primary_handler) {
            $error = error_get_last();
            $errMsg = isset($error['message']) ? $error['message'] : 'fopen returned false';
            logMessage("Stream Open Fail [{$this->name}:{$herikaName}]: {$errMsg}");
            if (isset($http_response_header) && is_array($http_response_header)) {
                logMessage("HTTP Headers on fail: " . implode("\n", $http_response_header));
            }
            return null;
        }

        return true;
    }

    // The remaining methods are unchanged from the original JSON connector
    public function send($url, $context)
    {
        if (isset($GLOBALS['mockConnectorSend']) && is_callable($GLOBALS['mockConnectorSend'])) {
            return call_user_func($GLOBALS['mockConnectorSend'], $url, $context);
        }
        return @fopen($url, 'r', false, $context);
    }

    public function process()
    {
        global $alreadysent;
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        if ($this->isDone())
            return "";
        $line = @fgets($this->primary_handler);
        if ($line === false) {
            if (feof($this->primary_handler)) {
                return "";
            } else {
                $error = error_get_last();
                $errMsg = isset($error['message']) ? $error['message'] : 'fgets error';
                logMessage("Read Err [{$this->name}:{$herikaName}]: {$errMsg}");
                $this->_rawbuffer .= "\nRead Err: {$errMsg}\n";
                $this->_forcedClose = true;
                return $errMsg;
            }
        }

        try {
            @file_put_contents(__DIR__ . "/../log/debugStream.log", $line, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
        }

        $this->_rawbuffer .= $line;
        $buffer = "";

        if (strpos($line, 'data: ') === 0) {
            $jsonData = trim(substr($line, 6));
            if ($jsonData === '[DONE]') {
                return "";
            }
            if (!empty($jsonData)) {
                $data = json_decode($jsonData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    // Handle Anthropic and OpenAI formats
                    if (isset($data['type'])) { // Anthropic Format
                        switch ($data['type']) {
                            // Handle text delta content
                            case 'content_block_delta':
                                if (isset($data['delta']['type']) && $data['delta']['type'] === 'text_delta' && isset($data['delta']['text'])) {
                                    $buffer = $data['delta']['text'];
                                    $this->_buffer .= $buffer;
                                }
                                break;

                            // Handle JSON tool calls in Anthropic format
                            case 'content_block_start':
                                if (isset($data['content_block']['type']) && $data['content_block']['type'] === 'tool_use') {
                                    // Process tool_use start
                                    $this->_jsonResponsesEncoded[] = json_encode($data);
                                }
                                break;

                            case 'content_block_delta':
                                if (isset($data['delta']['type']) && $data['delta']['type'] === 'tool_use_delta') {
                                    // Accumulate tool_use delta parts
                                    $this->_jsonResponsesEncoded[] = json_encode($data);
                                }
                                break;

                            case 'content_block_stop':
                                // End of a tool_use block
                                // We'll process this complete tool use in processActions
                                break;

                            // Handle stop signals
                            case 'message_delta':
                                if (isset($data['delta']['stop_reason']) && $data['delta']['stop_reason'] !== null) {
                                    logMessage("[{$this->name}:{$herikaName}] Stop (delta): " . $data['delta']['stop_reason']);
                                    $this->_forcedClose = true;
                                }
                                break;

                            case 'message_stop':
                                logMessage("[{$this->name}:{$herikaName}] Stop (message_stop). Usage:" .
                                    (isset($data['message']['usage']) ? json_encode($data['message']['usage']) : 'N/A'));

                                // Log cache efficiency metrics if available
                                if (isset($data['message']['usage'])) {
                                    $usage = $data['message']['usage'];
                                    $cacheRead = isset($usage['cache_read_input_tokens']) ? $usage['cache_read_input_tokens'] : 0;
                                    $cacheCreate = isset($usage['cache_creation_input_tokens']) ? $usage['cache_creation_input_tokens'] : 0;
                                    $normalInput = isset($usage['input_tokens']) ? $usage['input_tokens'] : 0;
                                    $totalConsideredInput = $cacheRead + $cacheCreate + $normalInput;
                                    $efficiency = ($totalConsideredInput > 0) ? round(($cacheRead / $totalConsideredInput * 100), 1) : 0;
                                    $logPerfEntry = sprintf(
                                        "[%s] ANTHROCACHE_JSON %s: Read:%d Create:%d New:%d TotalIn:%d Efficiency:%.1f%%\n",
                                        date(DATE_ATOM),
                                        $herikaName,
                                        $cacheRead,
                                        $cacheCreate,
                                        $normalInput,
                                        $totalConsideredInput,
                                        $efficiency
                                    );
                                    @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . "_anthropic_cache_perf.log", $logPerfEntry, FILE_APPEND);
                                }

                                $this->_forcedClose = true;
                                break;

                            case 'error':
                                $eM = print_r((isset($data['error']) ? $data['error'] : $data), true);
                                logMessage("Stream Err (Anthropic): {$eM}");
                                $this->_rawbuffer .= "\nErr (Anthropic):{$eM}\n";
                                $this->_forcedClose = true;
                                return $eM;

                            case 'ping':
                                // Ignore ping events
                                break;

                            default:
                                logMessage("[{$this->name}:{$herikaName}] Unhandled Anthropic Type: " . $data['type']);
                                break;
                        }
                    } elseif (isset($data["choices"][0]["delta"])) { // OpenAI Format
                        // Handle OpenAI format text content
                        if (isset($data["choices"][0]["delta"]["content"])) {
                            $buffer = $data["choices"][0]["delta"]["content"];
                            $this->_buffer .= $buffer;
                        }

                        // Handle OpenAI tool_calls in delta format
                        if (isset($data["choices"][0]["delta"]["tool_calls"])) {
                            $this->_jsonResponsesEncoded[] = json_encode($data);
                        }

                        // Handle OpenAI finish_reason
                        if (isset($data["choices"][0]["finish_reason"]) && $data["choices"][0]["finish_reason"] !== null) {
                            logMessage("[{$this->name}:{$herikaName}] Stop (choice): " . $data["choices"][0]["finish_reason"]);
                            $this->_forcedClose = true;
                        }
                    } elseif (isset($data['error'])) { // Generic Error
                        $eM = print_r($data['error'], true);
                        logMessage("Stream Err (Generic): {$eM}");
                        $this->_rawbuffer .= "\nErr (Generic):{$eM}\n";
                        $this->_forcedClose = true;
                        return $eM;
                    }
                } else {
                    logMessage("JSON Decode Err [{$this->name}:{$herikaName}]: " . json_last_error_msg() . " Data: " . substr($jsonData, 0, 150) . "...");
                }
            }
        } elseif (trim($line) === "event: message_stop") {
            logMessage("[{$this->name}:{$herikaName}] Explicit stream end event received.");
            $this->_forcedClose = true;
        } elseif (!empty(trim($line))) {
            // Log unexpected non-SSE line
            logMessage("Unexpected non-SSE line [{$this->name}:{$herikaName}]: " . substr(trim($line), 0, 150) . "...");
            $errorData = @json_decode(trim($line), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($errorData) && isset($errorData['error'])) {
                $eM = print_r($errorData['error'], true);
                logMessage("Non-stream Err: {$eM}");
                $this->_rawbuffer .= "\nNon-stream Err:{$eM}\n";
                $this->_forcedClose = true;
                return $eM;
            }
        }

        // Return Empty or Minimal Content: Avoid returning full JSON to prevent duplication in output
        // Extract message snippet if possible for real-time display
        if (!empty($buffer)) {
            $extracted_json_or_text = extractJson($this->_buffer);
            $tempJson = json_decode($extracted_json_or_text, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($tempJson['message']) && !empty($tempJson['message'])) {
                $GLOBALS["SCRIPTLINE_ANIMATION"] = GetAnimationHex($tempJson["mood"]);
                $GLOBALS["SCRIPTLINE_EXPRESSION"] = GetExpression($tempJson["mood"]);
                if (isset($tempJson["listener"])) {
                    if (isset($tempJson["action"]) && ($tempJson["action"] == "Talk") && lazyEmpty($tempJson["listener"]) && !lazyEmpty($tempJson["target"])) {
                        $GLOBALS["SCRIPTLINE_LISTENER"] = $tempJson["target"];
                    } else {
                        $GLOBALS["SCRIPTLINE_LISTENER"] = $tempJson["listener"];
                    }
                }
                return $tempJson['message'];
            }
        }
        return ""; // Return empty string if no message to display yet, commands handled in processActions()
    }




    public function close()
    {
        if ($this->primary_handler) {
            @fclose($this->primary_handler);
            $this->primary_handler = null;
        }
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';

        try {
            $raw = isset($this->_rawbuffer) ? $this->_rawbuffer : '<empty>';
            $proc = isset($this->_buffer) ? $this->_buffer : '<empty>';

            // Track JSON responses for later processing
            $jsonResponses = isset($this->_jsonResponsesEncoded) && is_array($this->_jsonResponsesEncoded) ?
                implode("\n", $this->_jsonResponsesEncoded) : "<no JSON responses>";

            $logContent = sprintf(
                "Processed Text:\n%s\n\nJSON Responses:\n%s\n\n[%s] [%s:%s] END STREAM\n==\n",
                $proc,
                $jsonResponses,
                date(DATE_ATOM),
                $this->name,
                $herikaName
            );

            @file_put_contents(__DIR__ . "/../log/output_from_llm.log", $logContent, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            logMessage("[{$this->name}:{$herikaName}] Close Log Err: " . $e->getMessage());
        }

        // Do NOT return the full buffer to avoid duplicating JSON output; commands are handled via processActions()
        $this->_rawbuffer = "";
        $this->_functionName = null;
        $this->_parameterBuff = "";
        $this->_forcedClose = false;
        // Don't clear _buffer or _jsonResponsesEncoded as they may be needed by processActions()

        return ""; // Return empty string to prevent duplication of full JSON response
    }



    public function processActions()
    {
        global $alreadysent;
        $this->_commandBuffer = array();
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        logMessage("start process actions");
        // First, attempt to parse JSON directly from the buffer (for responses not using tool calls)
        if (!empty($this->_buffer)) {
            // Attempt to extract valid JSON from buffer (handle partial or malformed JSON)
            $jsonStart = strpos($this->_buffer, '{');
            $jsonEnd = strrpos($this->_buffer, '}');
            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $possibleJson = substr($this->_buffer, $jsonStart, $jsonEnd - $jsonStart + 1);
                $parsedResponse = json_decode($possibleJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsedResponse)) {
                    logMessage("[{$this->name}:{$herikaName}] Parsed JSON directly from buffer: " . json_encode($parsedResponse));
                    //if (isset($parsedResponse["mood"])) {
                    //    logMessage($parsedResponse);
                    //    $GLOBALS["SCRIPTLINE_ANIMATION"]=GetAnimationHex($parsedResponse["mood"]);
                    //    $GLOBALS["SCRIPTLINE_EXPRESSION"]=GetExpression($parsedResponse["mood"]);
                    //}
                    if (isset($parsedResponse['action']) && !empty($parsedResponse['action'])) {
                        $target = isset($parsedResponse['target']) ? $parsedResponse['target'] : '';
                        $character = isset($parsedResponse['character']) ? $parsedResponse['character'] : $herikaName;
                        $commandKey = md5("{$character}|command|{$parsedResponse['action']}@{$target}\r\n");
                        if (!isset($alreadysent[$commandKey]) || empty($alreadysent[$commandKey])) {
                            $functionCodeName = function_exists('getFunctionCodeName') ? getFunctionCodeName($parsedResponse['action']) : $parsedResponse['action'];
                            $functionCodeName = empty($functionCodeName) ? $parsedResponse['action'] : $functionCodeName;
                            logMessage("FunctionCodeName: {$functionCodeName}");
                            logMessage("actionName: {$parsedResponse['action']}");
                            $commandString = "{$character}|command|{$functionCodeName}@{$target}\r\n";
                            $this->_commandBuffer[] = $commandString;
                            $alreadysent[$commandKey] = $commandString;
                            logMessage("[{$this->name}:{$herikaName}] Generated command from buffer JSON: {$commandString}");
                            if (ob_get_level()) {
                                @ob_flush();
                                @flush();
                            }
                        } else {
                            logMessage("[{$this->name}:{$herikaName}] Command already sent (skipped from buffer JSON): {$character}|command|{$parsedResponse['action']}@{$target}");
                        }
                    } else {
                        logMessage("[{$this->name}:{$herikaName}] No action field found in parsed JSON from buffer.");
                    }
                } else {
                    logMessage("[{$this->name}:{$herikaName}] Failed to parse JSON from buffer: " . json_last_error_msg() . " Buffer excerpt: " . substr($possibleJson, 0, 150));
                }
            } else {
                logMessage("[{$this->name}:{$herikaName}] No valid JSON boundaries found in buffer: " . substr($this->_buffer, 0, 150));
            }
        } else {
            logMessage("[{$this->name}:{$herikaName}] Buffer is empty, no JSON to parse for actions.");
        }

        // Also process tool calls if any (from Anthropic or OpenAI format) as a fallback
        if (!empty($this->_jsonResponsesEncoded)) {
            try {
                $anthropicToolData = $this->processAnthropicToolUse();
                if (!empty($anthropicToolData)) {
                    foreach ($anthropicToolData as $toolAction) {
                        if (isset($toolAction['name']) && isset($toolAction['input'])) {
                            $this->addCommandFromTool($toolAction['name'], $toolAction['input'], $alreadysent);
                        }
                    }
                }
            } catch (Exception $e) {
                logMessage("[{$this->name}:processActions] Error processing Anthropic tools: " . $e->getMessage());
            }

            try {
                $openaiToolData = $this->processOpenAIToolCalls();
                if (!empty($openaiToolData)) {
                    foreach ($openaiToolData as $toolAction) {
                        if (isset($toolAction['function']['name']) && isset($toolAction['function']['arguments'])) {
                            $this->addCommandFromTool($toolAction['function']['name'], $toolAction['function']['arguments'], $alreadysent);
                        }
                    }
                }
            } catch (Exception $e) {
                logMessage("[{$this->name}:processActions] Error processing OpenAI tools: " . $e->getMessage());
            }
        }

        $this->_jsonResponsesEncoded = array(); // Clear after processing

        // Log the final command buffer for debugging
        if (!empty($this->_commandBuffer)) {
            logMessage("[{$this->name}:{$herikaName}] Final Command Buffer: " . implode(", ", $this->_commandBuffer));
        } else {
            logMessage("[{$this->name}:{$herikaName}] No commands generated in this cycle.");
        }

        return empty($this->_commandBuffer) ? array() : $this->_commandBuffer;
    }



    // Helper methods for processing JSON tool responses
    private function processAnthropicToolUse()
    {
        $toolUses = array();
        $currentTool = null;

        foreach ($this->_jsonResponsesEncoded as $jsonStr) {
            $data = @json_decode($jsonStr, true);
            if (json_last_error() !== JSON_ERROR_NONE)
                continue;

            // Start a new tool_use
            if (
                isset($data['type']) && $data['type'] === 'content_block_start' &&
                isset($data['content_block']['type']) && $data['content_block']['type'] === 'tool_use'
            ) {

                $currentTool = array(
                    'name' => isset($data['content_block']['name']) ? $data['content_block']['name'] : null,
                    'input' => isset($data['content_block']['input']) ? $data['content_block']['input'] : array()
                );
            }

            // Update tool_use with deltas
            if (
                isset($data['type']) && $data['type'] === 'content_block_delta' &&
                isset($data['delta']['type']) && $data['delta']['type'] === 'tool_use_delta'
            ) {

                if (isset($data['delta']['name']) && $currentTool !== null) {
                    $currentTool['name'] = $data['delta']['name'];
                }

                if (isset($data['delta']['input']) && is_array($data['delta']['input']) && $currentTool !== null) {
                    $currentTool['input'] = array_merge($currentTool['input'], $data['delta']['input']);
                }
            }

            // Finalize tool_use
            if (isset($data['type']) && $data['type'] === 'content_block_stop' && $currentTool !== null) {
                if (!empty($currentTool['name'])) {
                    $toolUses[] = $currentTool;
                }
                $currentTool = null;
            }
        }

        return $toolUses;
    }

    private function processOpenAIToolCalls()
    {
        $toolCalls = array();
        $pendingToolCalls = array();

        foreach ($this->_jsonResponsesEncoded as $jsonStr) {
            $data = @json_decode($jsonStr, true);
            if (json_last_error() !== JSON_ERROR_NONE)
                continue;

            if (isset($data['choices'][0]['delta']['tool_calls'])) {
                foreach ($data['choices'][0]['delta']['tool_calls'] as $toolCall) {
                    $id = isset($toolCall['id']) ? $toolCall['id'] : null;
                    if ($id === null)
                        continue;

                    if (!isset($pendingToolCalls[$id])) {
                        $pendingToolCalls[$id] = array(
                            'id' => $id,
                            'type' => isset($toolCall['type']) ? $toolCall['type'] : 'function',
                            'function' => array(
                                'name' => isset($toolCall['function']['name']) ? $toolCall['function']['name'] : '',
                                'arguments' => isset($toolCall['function']['arguments']) ? $toolCall['function']['arguments'] : ''
                            )
                        );
                    } else {
                        // Update existing tool call
                        if (isset($toolCall['function']['name'])) {
                            $pendingToolCalls[$id]['function']['name'] .= $toolCall['function']['name'];
                        }
                        if (isset($toolCall['function']['arguments'])) {
                            $pendingToolCalls[$id]['function']['arguments'] .= $toolCall['function']['arguments'];
                        }
                    }
                }
            }
        }

        // Process completed tool calls
        foreach ($pendingToolCalls as $id => $toolCall) {
            if (!empty($toolCall['function']['name'])) {
                // Try to parse arguments as JSON
                $arguments = $toolCall['function']['arguments'];
                try {
                    // If not valid JSON, wrap in quotes
                    $decoded = @json_decode($arguments, true);
                    if (json_last_error() !== JSON_ERROR_NONE && !empty($arguments)) {
                        $arguments = json_encode($arguments); // Convert to valid JSON string
                    }
                } catch (Exception $e) {
                    logMessage("[{$this->name}:processOpenAIToolCalls] Error parsing arguments: " . $e->getMessage());
                }

                $toolCall['function']['arguments'] = $arguments;
                $toolCalls[] = $toolCall;
            }
        }

        return $toolCalls;
    }

    private function addCommandFromTool($name, $arguments, &$alreadysent)
    {
        if (empty($name))
            return;

        // Convert arguments to string if it's an array or object
        $parameterAsString = is_scalar($arguments) ? $arguments : json_encode($arguments);

        // Generate a unique hash for this command to avoid duplicates
        $commandKey = md5("Herika|command|{$name}@{$parameterAsString}\r\n");

        if (!isset($alreadysent[$commandKey])) {
            $functionCodeName = function_exists('getFunctionCodeName') ? getFunctionCodeName($name) : $name;
            $commandString = "Herika|command|{$functionCodeName}@{$parameterAsString}\r\n";
            $this->_commandBuffer[] = $commandString;
            $alreadysent[$commandKey] = $commandString;

            logMessage("[{$this->name}:processActions] Generated command: {$commandString}");
            if (ob_get_level()) {
                @ob_flush();
                @flush();
            }
        } else {
            logMessage("[{$this->name}:processActions] Command already sent (skipped): Herika|command|{$name}@{$parameterAsString}");
        }
    }

    public function isDone()
    {
        if ($this->_forcedClose) {
            if ($this->primary_handler) {
                @fclose($this->primary_handler);
                $this->primary_handler = null;
            }
            return true;
        }
        return !$this->primary_handler || feof($this->primary_handler);
    }
}
?>