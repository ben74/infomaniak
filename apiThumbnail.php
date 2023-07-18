<?php
//  data to fill here
$apiToken= $_SERVER['token'];
$mediaId='1jhvl2uqjf3fk';
$channelId=6349;
$thumbnail=$argv[1];// chemin local fichier

$apiUrl = 'https://api.infomaniak.com/1/vod/channel/'.$channelId;

if(!is_file($thumbnail))die('!'.$thumbnail);
$post = [ 'file' => new \CURLFile($thumbnail, '', $thumbnail)];

require_once './functions.php';

$contents = json_decode(quickCurl($apiUrl . '/media/'.$mediaId.'/thumbnail',[CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post],['Content-Type: multipart/form-data']),true);
print_r($contents);



