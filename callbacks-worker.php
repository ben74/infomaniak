<?php
$channelId=0;
$folder='callbacks';
if(!$channelId or !isset($_SERVER['token']))die('Please fill in these values');

while ('worker') {
    $callbacks = glob($folder . '/*.callback.log');// see callbacksExamples folder
    foreach ($callbacks as $callback) {
        echo "\nProcessing " . $callback . ':' . doStuff(file_get_contents($callback));
        unlink($callback);//remove data
    }
    sleep(10);
}
return;

function doStuff($data)
{
    global $channelId;
    $media = json_decode($data, true);
    if (in_array('ENCODING_FINISHED', $media['notification_event'])) {// Once the encoding is finished you can create an embed or direct playback link targeting this quality, with HLS standard quality you will get 4 of them
        $playerUuid = '1jhvl2upwockr';// default player
        $singleQualityPlaybacksReady = [];
        foreach ($media['encoded_medias'] as $encodedMedia) {
            if ($encodedMedia['file']['size'] and 'encodingh is finished, else this quality is pending') {
                $encoding = $encodedMedia['encoding_stream']['encoding']['id'];
                $singleQualityPlaybacksReady[] = 'https://play.vod2.infomaniak.com/single/' . $media['id'] . '/' . $encoding . '/' . $encodedMedia['id'];
            }
        }
/*
        $encoding = array_keys($media['playbacks'])[0];
        $singles=$media['playbacks'][$encoding]['single'];
        foreach($singles['url']as $url);
*/
        $post = json_encode(['validity' => 0/* never expires, if >0, will expire in that much seconds  */, 'target' => $media['id'], 'player' => $playerUuid, 'encoding' => $encoding]);
        $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $_SERVER['token'], 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => 'https://api.infomaniak.com/1/vod/channel/'.$channelId . '/share', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post];
        return curlRequest($options);
    }
}


function curlRequest($options)
{
    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $res = curl_exec($ch);
    $i = curl_getinfo($ch);

    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        $bt = debug_backtrace(-2)[0]['line'];
        throw new Exception('Error on line ' . $bt . ':' . json_encode($error));
    }

    if (strpos($res, '"error":')) {
        $json = json_decode($res, true);
        //if (isset($json['result']) and $json['result'] == 'error') {
        if (isset($json['error'])) {
            $bt = debug_backtrace(-2)[0]['line'];
            if ($json['error']['code'] == 'vod_not_authorized') {
                $json['error'] = 'bad token,vod_not_authorized';
            }// {"validity":0,"target":"1jhvl2uqe6vo2","player":"1jhvl2upwockr","encoding":"1jhvl2uq8p2rn"}
            throw new Exception('Error on line ' . $bt . ':<pre style="white-space: pre-wrap;">' . json_encode($json['error'])." with payload \n\n".$options[CURLOPT_URL]."\n\n".$options[CURLOPT_POSTFIELDS]);
        }
    }
    if ($i['http_code'] == 404) {
        $res = json_encode(['error' => '404 response for ' . $options[CURLOPT_URL]]);
    }
    return $res;
}
