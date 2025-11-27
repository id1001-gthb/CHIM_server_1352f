<?php

// API-DOC https://developers.deepgram.com/docs/getting-started-with-pre-recorded-audio

$localPath = dirname(__FILE__) . '/../';
require_once($localPath . "conf/conf.php"); // API KEY must be there
require_once($localPath . "lib/{$GLOBALS["DBDRIVER"]}.class.php");
require_once($localPath . "lib/chat_helper_functions.php");


function stt($filePath)
{

    if (!isset($GLOBALS["db"]) || !$GLOBALS["db"])
        $GLOBALS["db"] = new sql();

    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) {
        error_log("STT Deepgram: warning - input file missing or unreadable! {$filePath}");
        return null;
    }
    
    $i_sz = filesize($filePath);
    if ($i_sz == 144046) {
        error_log("STT Deepgram: warning input file {$filePath} probably contains silence. "); //debug
        //STT Deepgram: input file /var/www/html/HerikaServer/soundcache/_stt_81d57911eefb2057476acd0dc7ddc0cb.wav - silence: 144046 bytes
        //return null;
    }
    
    $stt_model = $GLOBALS["STT"]["DEEPGRAM"]["MODEL"] ?? "none";
    $stt_lang = $GLOBALS["STT"]["DEEPGRAM"]["LANG"] ?? "en";

    if (!(strpos($stt_model, "nova-3") === false)) { // nova-3 need keyterm not keywords
        $keywords = lastKeyWordsNew(30);
        $url = "";
        foreach ($keywords as $keyword)
            $url .= "&keyterm=" . urlencode($keyword) . "%3A1";
        if (stripos("|multi|en|en-US|de|nl|sv|sv-SE|da|da-DK|es|es-419|fr|fr-CA|pt|pt-BR|pt-PT|it|tr|no|id", $stt_lang) === false) { //es, es-419, fr, fr-CA, pt, pt-BR, pt-PT, it, tr, no, id
            $stt_lang = 'en';
        }
    } elseif (!(strpos($stt_model, "flux-general") === false)) {// flux-general-en !!! TODO: update web-ui with this new option
        $keywords = lastKeyWordsNew(30);
        $url = "";
        $stt_lang = 'en'; // flux is only EN at this moment
        foreach ($keywords as $keyword)
            $url .= "&keyterm=" . urlencode($keyword) . "%3A1";
    } elseif (stripos($stt_model, "whisper") === false) {   //WHISPER MODELS DONT SUPPORT KEYWORDS
        $keywords = lastKeyWordsNew(30);
        foreach ($keywords as $keyword)
            $url .= "&keywords=" . urlencode($keyword) . "%3A1";
    }

    //$url = "https://api.deepgram.com/v1/listen?smart_format=false&language={$GLOBALS["STT"]["DEEPGRAM"]["LANG"]}&model=whisper-medium";
    $url = "https://api.deepgram.com/v1/listen?punctuate=true&filler_words=true&utterances=true&language={$stt_lang}&model={$stt_model}";

    // print_r($keywords);
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Token ' . $GLOBALS['STT']['DEEPGRAM']['API_KEY'],
        'Content-Type: audio/wav'
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute cURL request
    $response = curl_exec($ch);

    if ($response === false)
    {
        // Handle error
        error_log("STT Deepgram: warning - no response!");
        return null;
    }

    $responseParsed = json_decode($response, true);
    //error_log("STT Deepgram: " . $response); //debug
    //STT Deepgram: {"metadata":{"transaction_key":"deprecated","request_id":"5a5fee1d-87a0-4098-82d4-a320873b8b5f","sha256":"725f51cf674e6c69bcfd55cd46e55a25f764ad973d6ab3a3156f5c4ef4a385ea","created":"2025-10-07T23:37:48.984Z","duration":4.5,"channels":1,"models":["2187e11a-3532-4498-b076-81fa530bdd49"],"model_info":{"2187e11a-3532-4498-b076-81fa530bdd49":{"name":"general-nova-3","version":"2025-07-31.0","arch":"nova-3"}}},"results":{"channels":[{"alternatives":[{"transcript":"","confidence":0.0,"words":[]}]}],"utterances":[]}} [02:37:48 08.10.25] [notice]
    //always return "transaction_key":"deprecated", this is normal behavior
    return $responseParsed['results']['channels'][0]['alternatives'][0]['transcript'];
}
