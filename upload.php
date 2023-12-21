<?php
//upload a distant media ( by url ) into this folder'
$channelId=10000;
$folder='1jhvl2...mca';
$filePath='/chemindufichier.mp4';
$nomDuFichier='nomDuFichier';
$token='a.....z';// à récupérer ici : https://manager.infomaniak.com/v3/ng/accounts/token/list

$post = ['folder' => $folder, 'name' => 'videoName', 'file' => new \CURLFile($filePath, '', $nomDuFichier)];

$contents = json_decode(curlRequest( [CURLOPT_TIMEOUT => 99999/* larger timeout for large files */, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'User-Agent: client', 'Content-Type: multipart/form-data'/*,'Digest: sha256='.$sha256ofTheFile*/], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => 'https://api.infomaniak.com/1/vod/channel/' . $channelId . '/upload', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post]),true);
$uploadedMediaId = $contents['data']['id'];
echo "\nUpload ok, media id is : " . $uploadedMediaId;

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

en ligne de commandes

channelId=10000;
folderUuid=1jhvl2...mca;
filePath=/chemindufichier.mp4;
nomDuFichier=nomDuFichier;
token=a.....z;# à récupérer ici : https://manager.infomaniak.com/v3/ng/accounts/token/list

curl --progress-bar -v --http1.1 -kL -H 'Content-Type: multipart/form-data' -H "Authorization: Bearer $token" https://api.infomaniak.com/1/vod/channel/$channelId/upload -F "file=@$filePath" -F "folder=$folderUuid" -F "name=$nomDuFichier";
