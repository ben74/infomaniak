<?php

function curlHeaders($headers)
{
    global $apiToken;
    return ['Authorization: Bearer ' . $apiToken, 'User-Agent: api'] + $headers;
}

function quickCurl($url, $options = [], $headers = [])
{
    return curlRequest([CURLOPT_TIMEOUT => 3600, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => curlHeaders($headers), CURLOPT_URL => $url] + $options);
}

function curlRequest($options)
{
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_TIMEOUT => 3600] + $options);
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
            throw new Exception('Error on line ' . $bt . ':<pre style="white-space: pre-wrap;">' . json_encode($json['error']) . " with payload \n\n" . $options[CURLOPT_URL] . "\n\n" . $options[CURLOPT_POSTFIELDS]);
        }
    }
    if ($i['http_code'] == 404) {
        $res = json_encode(['error' => '404 response for ' . $options[CURLOPT_URL]]);
    }
    if (substr($i['http_code'], 0, 1) == 5) {// bad gateway or timeout
        $bt = debug_backtrace(-2)[0]['line'];
        throw new Exception('Bad response: ' . $i['http_code'] . ' : ' . $bt . ':<pre style="white-space: pre-wrap;">' . json_encode($json['error']) . " with payload \n\n" . $options[CURLOPT_URL] . "\n\n" . $options[CURLOPT_POSTFIELDS]);
    }
    return $res;
}
