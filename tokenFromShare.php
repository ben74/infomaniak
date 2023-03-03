<?php
//  data to fill here
$apiToken= $_SERVER['token'];
$channelId = 6349;

$idShare = '1jhvl2uqn0rn2';
$media='1jhvl2uqhym97';
$encoding='1jhvl2uqemvp2';
$encodedMedia='1jhvl2uqhym9o';
// run script then ..

$domain='https://play.vod2.infomaniak.com/';
$manifest=$domain.'_hls_/'.$media.'/'.$encoding.'/,'.$encodedMedia.',.urlset/manifest.m3u8';
$singleLink = $domain.'_single_/'.$media.'/'.$encoding.'/'.$encodedMedia.'.mp4';

$apiUrl = 'https://api.infomaniak.com/1/vod/channel/'.$channelId;

require_once './functions.php';

$options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/share/' . $idShare . '/token', CURLOPT_POST => 1];
$options[CURLOPT_POSTFIELDS] = json_encode([
    'window' => 3600// validity in seconds : 1 hour, default is 1800
    , 'strategy' => 'SINGLE'//  strategy:DASH|HLS|BEST|SINGLE => single for /_single_/ links, hls for /_hls_/ manifest links ... default is HLS
    /*
    , 'allowed_domains' => ['https://infomaniak.ch', 'http://other.ch']
    , 'restricted_domains' => ['http://win.ch', 'http://orange.ch']
    ,'attempts' => 5 // Number of allowed hits until token expires
    , 'ip'=>'127.0.0.1'// v4 adress, but one day its going to be ipv6, so you'll have to catch your user ipv4 or ipv6 using ajax requests : https://ipv4.infomaniak.com/ip.php , https://www.infomaniak.com/ip.php might return ipv6 if available , if both respond ipv4 its safe , yet the server only resolves as ipv4, but might resolve adresses as ipv6 in the future
    // validity here starts in the future, for 12 hours
     ,'start_time'=>date('Y-m-d H:i:s',strtotime('12 hours')), 'end_time'=>date('Y-m-d H:i:s',strtotime('24 hours'))//will be valid for 1 hour once consumed starting in 12 hours and ending in 24 hours in theses domains
    */
]);

$tokenSingle = json_decode(curlRequest($options), true)['data'];

$options[CURLOPT_POSTFIELDS] = json_encode(['strategy' => 'HLS']);// Switch to HLS then ( reduces bandwidth consumption, and faster play )
$tokenHls = json_decode(curlRequest($options), true)['data'];

echo '
Embed with token : https://player.vod2.infomaniak.com/embed/'.$idShare.'?'.$tokenHls.'
Hls link with token : ffplay '.$manifest.'?'.$tokenHls.'
Single Link   token :'.$singleLink.'?'.$tokenSingle.'
';
return;?>

