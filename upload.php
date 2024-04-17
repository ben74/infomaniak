<?php
/*
 * https://raw.githubusercontent.com/ben74/infomaniak/main/upload.php
 * More complete api usage : https://raw.githubusercontent.com/ben74/infomaniak/main/apiVod.php
 * usage : uploads a distant media ( by url ) into this folder'
*/
if('variables to fill for your personnal api usage'){
    $channelId=10000;
    $folderIdentifier='1jhvl2...mca';

    $filePath='/Users/JohnDoe/file.mp4';
    $fileName='MediaName';

    $videoUrl = 'https://domain.ch/externalPublicAccessibleVideo.mp4';

    $token='a.....z';// <== create a vod token here : https://manager.infomaniak.com/v3/ng/accounts/token/list
}

try {
    if ('    >> upload an external self hosted video    ') {
        $post = ['folder' => $folderIdentifier, 'filename' => $fileName, 'url' => $videoUrl];
    } elseif ('     or a locally stored one ?    ') {
        $post = ['folder' => $folderIdentifier, 'name' => $fileName, 'file' => new \CURLFile($filePath, '', $fileName)];
    }


    $contents = json_decode(curlRequest([CURLOPT_TIMEOUT => 99999/* larger timeout for large files */, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'User-Agent: client', 'Content-Type: multipart/form-data'/*,'Digest: sha256='.$sha256ofTheFile*/], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => 'https://api.infomaniak.com/1/vod/channel/' . $channelId . '/upload', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post]), true);
    $uploadedMediaId = $contents['data']['id'];
    echo "\nUpload ok, media id is : " . $uploadedMediaId;


} catch (\throwable $e) {
    echo $e->getMessage();
}












function curlRequest($options)
{
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_TIMEOUT => 99999] + $options);
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
    if (substr($i['http_code'], 0, 1) == 5) {// bad gateway or timeout
        $bt = debug_backtrace(-2)[0]['line'];
        throw new Exception('Bad response: ' . $i['http_code'] . ' : ' . $bt . ':<pre style="white-space: pre-wrap;">' . json_encode($json['error']) . " with payload \n\n" . $options[CURLOPT_URL] . "\n\n" . $options[CURLOPT_POSTFIELDS]);
    }
    return $res;
}

return;?>


As commandline :

channelId=10000;
folderUuid=1jhvl2...mca;
filePath=/chemindufichier.mp4;
nomDuFichier=nomDuFichier;
token=a.....z;

curl --progress-bar -v --http1.1 -kL -H 'Content-Type: multipart/form-data' -H "Authorization: Bearer $token" https://api.infomaniak.com/1/vod/channel/$channelId/upload -F "file=@$filePath" -F "folder=$folderIdentifierUuid" -F "name=$nomDuFichier";
