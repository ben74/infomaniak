<?php
/*
 * 1) First, Get your application token here : https://manager.infomaniak.com/v3/ng/accounts/token/list
 * 2) Then get your channel id here, while browsing your channel : vod/10xxx/browse
 * 3) Please note "apiv3" routes ( around line 162 ) for GET request are the fastest ever made for high concurrency querying
 *
 * Full Api Documentation : see https://developer.infomaniak.com/docs/api/get/1/vod/channel/%7Bchannel%7D/media/%7Bmedia%7D
 * + some video walkthrough : https://api.vod2.infomaniak.com/res/embed/1jhvl2uqbhqwv.html
 *
 * To get an encoding or video as soon as it's available ( especially after an upload via api ), please refer to callbacks : https://manager.infomaniak.com/v3/xx/vod/xx/plugin/callbacks and set video ready, encoding finished for your callback in order to be advised as soon as possible (line 516 )
 *
 * Getting the same results ? Delete your session cookie
 */
try {

$ip = $_SERVER['REMOTE_ADDR'];// <== Put your public ip while testing this script on localhost
//$ip = '93.10.248.62';
$secretKey = 'cryptoFunctionXYZ';
$cacheThumbnails = true;
$resize = [160, 120];// download main thumbnail and resizes it to 160x120 format
// throttle user token request in order to avoid programated distribution by your users .. 60 requests max per 1 hour time window ( increase if needed )
$limitTokensPerHour = 60;
$maxTokensTimeLimit = 3600;
$crsfTokenTimeout = 3600;// Lifetime of the crsfToken before requesting a new one

$ok = false;
$searchFor = 'software';
$thumbnailsDir = 'thumbnails';
if(!is_dir($thumbnailsDir))mkdir($thumbnailsDir,0777,true);
$statsFrom = strtotime('6 month ago');
$apiUrl = 'https://api.infomaniak.com/1/vod/channel/';
$mediaId = $encoding = $apiToken = $channel = $player = '';// to be filled on step 1
$innerHashes = [hash('crc32', $secretKey . date('YmdH', strtotime('1 hour ago'))), hash('crc32', $secretKey . date('YmdH'))];// inner communication for requesting new CSRF token -- this basically prevents the user from creating an automation for creating tokens
$mediasUuids = $requestToken = $files = $fileHandles = $multiCh = $stats = $autoPlayliste = $shares = $player = $folders = $medias = $playlists = $res2mk = [];

session_start();// Support for storing API auth informations and client CRSF token => shoud use something else in production such as a redis
ini_set('display_errors', 1);
ini_set('max_execution_time', 999999);
// '1: If token and channel are ok Query first folder: root and get it identifier') {
if ('log to the api with your credentials once => put theses values on your code' and !isset($_SESSION['apiToken']) and !isset($_SESSION['channel'])) {
    if (isset($_POST['apiToken']) and isset($_POST['channel']) and 'Step 1: validate token and channel against channel root folder') {
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . $_POST['channel'] . '/folder/root?with=encodings,effective_encodings', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $_POST['apiToken']]];

        $contents = json_decode(curlRequest($options), true);
        if ($contents['data']['id']) {
            $ok = true;
            $_SESSION['apiToken'] = $_POST['apiToken'];
            $_SESSION['channel'] = $_POST['channel'];
            $_SESSION['encoding'] = $contents['data']['encodings'][0]['id'];
            $_SESSION['rootFolderId'] = $contents['data']['id'];
        }

    }
    if (!$ok) {// Token and channel not correct ? Still diplay the form in order to check your parameters are correct
        ?>
        <form method=post>
            <table id="form">
                <tr>
                    <td>Token:</td>
                    <td><input id=token name=apiToken placeholder="zAx96g..." title="get yours here : https://manager.infomaniak.com/v3/ng/accounts/token/list"> <a target=token href="https://manager.infomaniak.com/v3/ng/accounts/token/list " class="b">Get your token here</a></td>
                </tr>
                <tr>
                    <td>Channel:</td>
                    <td><input type=number id=channel name=channel title="is within your channel url : /vod/10xxx/" placeholder="100xx"> Find it in url /vod/<b>10xxx</b>/browse</td>
                </tr>
                <tr>
                    <td colspan=2><input value=" => Explore Api Results Media & Generate share & tokens <=" type=submit style="width:100%"></td>
                </tr>
            </table>
        </form>
        <style>
            html {
                color: #BBB;
                background: #000;
                font-size: 10px;
            }

            body {
                font-size: 1.5rem;
                font-family: Avenir Next, Sans-Serif
            }

            b, .b {
                color: #F00;
                font-weight: bold;
            }
        </style>
        <?php
        die;
    }
}

extract($_SESSION);//shortcut for populating variables
$apiUrl .= $channel;


if ('async actions') {
    if (isset($_POST['mediaStats'])) {
        $a=curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/viewers/medias?from=' . $statsFrom . '&medias=' . $_POST['mediaStats']]);
       die($a);
    }
    if (isset($_POST['getToken4share'])) {
        $now = time();

        if (!isset($_POST['uniqid']) or !isset($_POST['hash']) or !in_array($_POST['hash'], $innerHashes) or !isset($_SESSION['uniqid']) or !isset($_SESSION['expires']) or ($_SESSION['expires'] < $now) or $_POST['uniqid'] != $_SESSION['uniqid']) {
            die('{"error":"not allowed"}');
        }

// nb: shall use redis or memcach instead
        $requestsPerIp = (is_file($ip . '.requests') ? json_decode(file_get_contents($ip . '.requests'), true) : []);

        $min = $now - $maxTokensTimeLimit;
        foreach ($requestsPerIp as $k => &$v) {
            if ($k < $min) $v = null;// ne plus prendre en compte les hits < 1 heure
        }
        unset($v);
        $requestsPerIp = array_filter($requestsPerIp);
        $nbHits = array_sum($requestsPerIp);

        if ($nbHits > $limitTokensPerHour) die('{"toomuchtokenrequests":1}');

        if (!isset($requestsPerIp[$now])) $requestsPerIp[$now] = 0;
        $requestsPerIp[$now]++;

        file_put_contents($ip . '.requests', json_encode($requestsPerIp));

        $post = [];
        /**
         * options dans le body du post
         * strategy:DASH|HLS|BEST|SINGLE
         * start_time:2022-01-01 00:00:00   , if not specified = start time is now
         * end_time:2032-01-01 00:00:00     , if not specified = +30minutes
         * window:86000 ( how much seconds the token is valid if end time omited, default is 30 min ( 1800 seconds ) )
         *
         * ip : 127.0.0.1
         * $post = ['ip' => $ip]; # /!\ Might not work depending if the client ISP returns IP adress as v6 or dynamic  : aka a different ip than the one you got
         *
         * allowed_domains:['infomaniak.ch','other.ch']
         * restricted_domains:['notthisone.ch','neither.ch']
         *
         * $post = ['allowed_domains' => ['yourdomain.com']]; # /!\ Works only using the infomaniak player via en embed link
         */

        $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/share/' . $_POST['getToken4share'] . '/token', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($post)];
        die('{"token":"' . json_decode(curlRequest($options), true)['data'] . '"}');
    }
}

/**
 * THIS MECANIC IS FOR  INNER CSRF TOKEN DISTRIBUTION EVEN BEFORE OPENING API CALLS TO REAL VIDEO TOKEN GENERATION IN ORDER TO AVOID ANY USER POSSIBLE EXPLOIT OF THE FEATURE, PLEASE NOTE IT ALSO EXPIRES ...
 */

if ('refresh page token used for throttling token api queries, only for legitimate users') {
    if (!isset($_SESSION['expires']) or $_SESSION['expires'] < (time() + ($crsfTokenTimeout / 2))) {
        $_SESSION['expires'] = time() + $crsfTokenTimeout;
        $_SESSION['uniqid'] = \uniqid();// refresh token : add complexity
    }
    if (isset($_GET['refreshToken'])) {// async ajax refreshToken action
        if (!isset($_POST['hash']) or !in_array($_POST['hash'], $innerHashes)) {
            die('{"error":"not allowed 2"}');
        }
        die('{"uniqid":"' . $_SESSION['uniqid'] . '"}');
    }
}


if('apiv3 routes : in progress, those are the fastest ones '){/*    /([2-9]|[0-9]{2,})
    HOST : https://api.vod2.infomaniak.com/2/vod/
    GET|HEAD   accounts/{account}/channels
    GET|HEAD   browse/{folder}
    GET|HEAD   browse/{folder}/breadcrumb
    GET|HEAD   browse/{folder}/tree
    GET|HEAD   channels/{channel}
    GET|HEAD   channels/{channel}/browse
    GET|HEAD   channels/{channel}/browse/breadcrumb
    GET|HEAD   channels/{channel}/browse/trash
    GET|HEAD   channels/{channel}/browse/tree
    GET|HEAD   channels/{channel}/encodings
    GET|HEAD   channels/{channel}/folders
    GET|HEAD   channels/{channel}/media
    GET|HEAD   channels/{channel}/players
    GET|HEAD   chapters/{chapter}
    GET|HEAD   chapters/{chapter}.{format}
    GET|HEAD   encodings/p
    GET|HEAD   encodings/p/{profile}
    GET|HEAD   encodings/{encoding}
    GET|HEAD   folders/{folder}
    GET|HEAD   lang
    GET|HEAD   lang/{lang}
    GET|HEAD   media/{media}
    GET|HEAD   media/{media}/chapters
    GET|HEAD   media/{media}/chapters.{format}
    GET|HEAD   media/{media}/subtitles
    GET|HEAD   media/{media}/thumbnails
    GET|HEAD   players/{player}
    GET|HEAD   players/{player}.{image}.{format}
    GET|HEAD   subtitles/{subtitle}
    GET|HEAD   subtitles/{subtitle}.{format}
    GET|HEAD   thumbnails/{thumbnail}
    GET|HEAD   thumbnails/{thumbnail}.{format}
*/
}


if (!$player and '2:list available players - get first one available - in order to publish shares foreach found media') {
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/player', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken]];

    $contents = json_decode(curlRequest($options), true);
    $player = $_SESSION['player'] = $contents['data'][0]['id'];
}


if (!$autoPlayliste and '3 : list all playlists, then returns the share with the auto playlist videos') {
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/playlist?with=shares', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken]];

    $contents = json_decode(curlRequest($options), true);
    $autoPlaylisteShare = $_SESSION['autoPlaylisteShare'] = '';

    $playlists = $_SESSION['playlists'] = $contents['data'];
    foreach ($contents['data'] as $playlist) {
        if ($playlist['type'] == 'dynamic' and $playlist['name'] == 'autodyn') {

            if (isset($playlist['shares'][0])) {
                $_SESSION['autoPlaylisteShare'] = $autoPlaylisteShare = $playlist['shares'][0]['links']['embed']['html']['url'];//Skip
            }
            $_SESSION['autoPlayliste'] = $autoPlayliste = $contents['data'];
            break;
        }
    }


    if (!isset($_SESSION['autoPlayliste']) and 'create autodyn playlist with all previous folders') {
        if (!$folders and '3A : list all folders --> create an automated playlist with all channel contents') {
            $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/folder', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken]];

            $contents = json_decode(curlRequest($options), true);

            foreach ($contents['data'] as $folder) {
                $folders[] = $folder['id'];
            }
            $_SESSION['folders'] = $folders;
        }

        if ('3B : creates automatic channel contents playlist') {
            $post = ['name' => 'autodyn', 'type' => 'dynamic'];
            $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/playlist', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'], CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($post)];
            $contents = json_decode(curlRequest($options), true);

            $_SESSION['autoPlayliste'] = $autoPlayliste = $contents['data']['id'];
        }

        if ('3C : attach folders / medias to playlist') {
            $post = ['items' => implode(',', $folders)];
            $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/playlist/' . $autoPlayliste . '/attach', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'], CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($post)];

            $contents = json_decode(curlRequest($options), true);
        }

        if ('3D : get playlist share') {
            $post = json_encode(['target' => $autoPlayliste, 'player' => $player, 'encoding' => $encoding]);
            $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/share', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post];

            $contents = json_decode(curlRequest($options), true);

            $_SESSION['autoPlaylisteShare'] = $autoPlaylisteShare = $contents['data']['links']['embed']['html']['url'];
        }
    }
}

if (!$medias and '4 : list all media --> get thumbnails, creates shares if non existing with the first encoding found, then create foreach 1 token based on ip') {
    /***
     * state: 192 - media encoded and ready to play
     * validated : true -- so any share could playback the media like : aka public vs private
     * effective_encodings : different video qualities associated with the media
     */

    $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/media?per_page=35&page=1&order_by=created_at&order=desc&with=encodings,effective_encodings,shares,thumbnail,sample,playbacks'];//,thumbstrip,preview,sample,,scenes,encodings,progress,state

    $contents = json_decode(curlRequest($options), true);

//shares
    $medias = $contents['data'];
    foreach ($medias as &$media) {
        if (
            $media['playbacks'] or
            !$media['encodings'] or $media['id'] == '1jhvl2uqe6vo2' or $media['streams'] == [0 => 'audio']) {
            continue;//     Cant share a media which has non encodings ..
        }
        if (!$media['shares'] or $media['shares'][0]['validity']) {
            if ('4B: create a share of the uploaded media') {
               // $post = json_encode(['validity' => 0, 'target' => $media['id'], 'player' => $player, 'encoding' => $media['encodings'][0]['id']]);
                $post = json_encode(['validity' => 0, 'target' => $media['id'], 'player' => $player, 'encoding' => array_keys($media['playbacks'])[0]]);
                $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/share', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post];
//    {"validity":0,"target":"1jhvl2uqe6vo2","player":"1jhvl2upwockr","encoding":"1jhvl2uq8p2rs"}     {target: "1jhvl2uqbdrgq", encoding: "1jhvl2uq8p2rn"}
                $c1 = json_decode(curlRequest($options), true);

                $media['shares'] = [$c1['data']];
                $shares[] = $share = $c1['data']['id'];
            }
        } else {
            $share = $media['shares'][0]['id'];
        }
    }
    unset($media);
    $_SESSION['medias'] = $medias = $contents['data'];
}

if ('5:display') {
    echo '<div id=play class=hide></div><h1>Infomaniak Vod Api</h1><a href="#" onclick=popup(\'' . $autoPlaylisteShare . '\')">Dynamic Channel Playlist with all medias</a><div class=row>';
    foreach ($medias as $mk => &$media) {

        if (!is_array($media)) {
            $uuidOnly = 'mauvaise formation du cache';
        }
        $protected = false;
        $share = (isset($media['shares']) && isset($media['shares'][0])) ? $media['shares'][0]['id'] : 0;
        if (is_array($media) && isset($media['key_restricted'])) {
            $protected = $media['key_restricted'];
        }


        if (0 and 'perform check if Media has one encoding and is ready to play ?') {
            $isReadyToPlay = false;

            $encodings = $media['encodings'];
            $progress = $media['progress'];
            $state = $media['state'];
            $readyForPlayback = ($state >= 64 && (($state & 64) === 64)) || ($state >= 128 && (($state & 128) === 128));
            /* state is bitwise of  IDLE = 1;   DELETED = 2;    TRANSFERRING = 4;   INITIALIZING = 8;   AWAIT_ENCODING = 16;    ENCODING = 32;  AVAILABLE = 64; READY = 128;    E_TRANSIENT = 256;  E_FATAL = 512; */

            if ($progress == 100 and count($encodings) and $readyForPlayback) {
                $isReadyToPlay = true;
            }
        }

        $tnFile = (isset($media['thumbnail']['url'])) ? $media['thumbnail']['url'] : '';
        $previewFile = (isset($media['preview'])) ? $media['preview']['video']['url'] : '';
        $thumbstrip = $thumbstripFile = (isset($media['thumbstrip'])) ? 'http://api.vod2.infomaniak.com/res/thumb/' . $media['thumbstrip']['id'] : '';

        if ($cacheThumbnails and 'put thumbnails into local file cache ( faster display )') {
            if (!is_dir($thumbnailsDir)) {
                mkdir($thumbnailsDir);
            }
            if (!isset($multi)) {
                $multi = curl_multi_init();
            }
            $thumbnail = $tnFile;

            if ($thumbnail) {
                $found = false;
                $end = explode('/', $thumbnail);
                $media['thumbnail'] = $tnFile = $thumbnailsDir . '/' . end($end) . '.jpg';

                if ($resize) {
                    $wp = $tnFile . '-' . $resize[0] . '-' . $resize[1] . '.webp';
                    $jpg = $tnFile . '-' . $resize[0] . '-' . $resize[1] . '.jpg';
                    if (is_file($wp)) {
                        $found = true;
                        $media['thumbnail'] = $tnFile = $wp;
                    } elseif (is_file($jpg)) {
                        $found = true;
                        $media['thumbnail'] = $tnFile = $jpg;
                    }
                }

                if (!$found && !is_file($tnFile)) {
                    $lastCurl = $multiCh[] = curl_init($thumbnail);
                    $resId = (int)$lastCurl;
                    $files[$resId] = $tnFile;
                    $res2mk[$resId] = $mk;
                    $fileHandles[$resId] = $fh = fopen($tnFile, 'w');
                    if (!$fh) {
                        throw new Exception('Cant write file:' . __line__);
                    }
                    curl_setopt_array($lastCurl, [CURLOPT_URL => $thumbnail . '?id=' . $resId, CURLOPT_HEADER => false, CURLINFO_HEADER_OUT => false, CURLOPT_VERBOSE => false, CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_NOPROGRESS => 0, CURLOPT_BUFFERSIZE => CURL_MAX_READ_SIZE, CURLOPT_TIMEOUT => 9999]);//CURLOPT_PROGRESSFUNCTION => 'progress',
                    curl_multi_add_handle($multi, $lastCurl);
                }
            }


            if (isset($media['sample']['video']['url']) and $media['sample']['video']['url']) {
                $url = $media['sample']['video']['url'];
                $end = explode('/', $media['sample']['video']['url']);
                $media['sample'] = $tnFile = $thumbnailsDir . '/' . end($end) . '.jpg';
                if (!is_file($tnFile)) {
                    $lastCurl = $multiCh[] = curl_init($url);
                    $resId = (int)$lastCurl;
                    $files[$resId] = $tnFile;
                    $res2mk[$resId] = $mk;
                    $fileHandles[$resId] = $fh = fopen($tnFile, 'w');
                    if (!$fh) {
                        throw new Exception('Cant write file:' . __line__);
                    }
                    curl_setopt_array($lastCurl, [CURLOPT_URL => $url . '?id=' . $resId, CURLOPT_HEADER => false, CURLINFO_HEADER_OUT => false, CURLOPT_VERBOSE => false, CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_NOPROGRESS => 0, CURLOPT_BUFFERSIZE => CURL_MAX_READ_SIZE, CURLOPT_TIMEOUT => 9999]);//CURLOPT_PROGRESSFUNCTION => 'progress',
                    curl_multi_add_handle($multi, $lastCurl);
                }
            }
        } else {//no thumbnail caching
            $media['thumbnail'] = (isset($media['thumbnail']['url'])) ? $media['thumbnail']['url'] : '';
            $media['sample'] = (isset($media['sample']['url'])) ? $media['sample']['video']['url'] : '';
        }
    }

    unset($media);

    if (isset($multi)) {
        do {
            $r = curl_multi_exec($multi, $active);
            if (\curl_multi_select($multi) == -1) {
                usleep(100000);
            } else {
                $ir = \curl_multi_info_read($multi);
                if ($ir) {//msg:1,result:3
                    $resId = intval($ir['handle']);
                    $info = curl_getinfo($ir['handle']);
                    if ($info['http_code'] == 200) {
                        fclose($fileHandles[$resId]);
                        $quality = 70;
                        if ($resize && 'want to resize the thumbnail to specific dimensions ?' && function_exists('imagecreatefromjpeg')) {
                            $path = $files[$resId] . '-' . $resize[0] . '-' . $resize[1];
                            [$w, $h, $mime] = getimagesize($files[$resId]);
                            if (!$w or !$h) {
                                continue;// error with downloading the image
                            }
                            if ($mime == 2) {
                                $image = imagecreatefromjpeg($files[$resId]);
                            } elseif ($mime == 4) {
                                $image = imagecreatefrompng($files[$resId]);
                            } elseif ($mime == 32) {
                                $image = imagecreatefromwebp($files[$resId]);
                            }
                            $tmp = imagecreatetruecolor($resize[0], $resize[1]);
                            imagecopyresampled($tmp, $image, 0, 0, 0, 0, $resize[0], $resize[1], $w, $h);
                            if (function_exists('imagewebp')) {// faster, better than jpeg
                                $path .= '.webp';
                                imagewebp($tmp, $path, $quality);
                            } else {
                                $path .= '.jpg';
                                imagejpeg($tmp, $path, $quality);
                            }
                            if (isset($res2mk[$resId])) {
                                $mk = $res2mk[$resId];
                                $media[$mk]['thumbnail'] = $path;
                            }
                        }


                    } elseif ('cant download thumbnail file: 404 error') {
                        fclose($fileHandles[$resId]);
                        touch($files[$resId]);
                        // put file 404.png instead, let them anyways
                    }
                    unset($fileHandles[$resId], $files[$resId]);
                    curl_multi_remove_handle($multi, $ir['handle']);
                }
            }
        } while ($r == CURLM_CALL_MULTI_PERFORM || $active);

        curl_multi_close($multi);

        foreach ($fileHandles as $fileHandle) {
            fclose($fileHandle);
        }

        if (0 and $r != CURLM_OK) {
            throw new Exception("Curl multi read error $r");
        }

    }


    if ('print html for display') {
        foreach ($medias as $media) {

            $tnFile = $media['thumbnail'] ?? '';
            $previewFile = $media['preview'] ?? '';
            $protected = $media['key_restricted'] ?? '';
            $share = $media['shares'][0] ?? '';
            if (!isset($share['id'])) {
                continue;// Anomalie
                $a = 1;
            } else {
                $link = $share['links']['embed']['html']['url'];
            }

            if (isset($media['sample']['audio']['url'])) {//is audio, no previews
                $media['sample'] = '';
            }
            if ($protected) {
                $requestToken[$media['id']] = $share['id'];
            }

            $mediasUuids[]=$media['id'];
            echo "<div class=c id='m" . $media['id'] . "'><div class=media><a h='$link' share='" . $share['id'] . "' title='" . strip_tags($media['name'])
                . "' href='#' onclick='popup(\"" . $link . "\"" . (($protected) ? ',this,"' . $share['id'] . '"' : '') . ");'"
                . "><div class=img media='" . $media['id']
                . "' play=0 origin='$tnFile' preview='" . $media['sample']
                . "' style='background-image:url(" . $media['thumbnail']
                . ")'></div>" . (($protected) ? 'protected<br>' : '') .
                substr(str_replace('_', ' ', strip_tags($media['name'])), 0, 15) . " <i class=stats></i></a><br>";

            echo "</div></div>";
        }
    }

    echo "</div>";

}
?>
<script>var uniqid = '<?php echo $_SESSION['uniqid'];?>', z, final, ch, w = 172, h = 129, fps = 10, columns = 1, rows = 72, locks = {}, intervals = {}, img, play, origin, preview, els, play;
    getStats('<?php echo implode(',',$mediasUuids);?>',function(resp){
        keys=[];
        resp=JSON.parse(resp);
        for(var i in resp.data){
            if(resp.data.hasOwnProperty(i)){
                keys.push(i);
                if(i != 'time'){
                    sel='#m'+i+' i.stats';
                    el=document.querySelector(sel);
                    if(!el){console.error('nf',sel);continue;}
                    el.innerHTML=resp.data[i].value+' views';
                }
            }else{ console.error(i); }
        }
        //console.error(keys);
    });
    window.onload = function () {
        play = document.getElementById('play');
        play.onclick = function () {
            play.className = 'hide';
            play.innerHTML = '';
        };
        window.addEventListener('keypress', function (e) {
            if (e.key == "Escape" || e.keyCode === 27) {
                play.className = 'hide';
                play.innerHTML = '';
            }
        });

        resize();
        els = document.querySelectorAll('.img');
        for (var i in els) {
            if (isNaN(i)) continue;

            els[i].addEventListener('mouseout', function (ev) {
                if (!locks[media]) return;
                locks[media] = false;
                this.style.backgroundImage = 'url(' + this.getAttribute('origin') + ')';
                //z=this;console.error(this.getAttribute('preview'));
                return;

                var media = this.getAttribute('media');
                locks[media] = false;
                clearInterval(intervals[media]);
                intervals[media] = null;
                this.removeAttribute('playing');

            });

            els[i].addEventListener('mouseover', function (e) {//fires once
                if (locks[media]) return;
                locks[media] = true;
                this.style.backgroundImage = 'url(' + this.getAttribute('preview') + ')';
                //z=this;console.error(this.getAttribute('preview'));
                return;


                var el = this, media = el.getAttribute('media');

                intervals[media] = setInterval(function () {
                    onmove(el);
                }, 1000 / fps);
            });
        }
    };

    window.onresize = resize;

    function resize() {
        ch = document.querySelector('.img').clientHeight;
        final = ch * rows - 17;//0.357*ch;// 35 // Dépend surtout du format de la vidéo !!!
        document.querySelector('#style').innerHTML = '@keyframes preview { 0% {background-position-x: 0px;background-position-y: 0} 100% {background-position-x: 0px;background-position-y: -' + final + 'px;}}';
    }

    function onmove(el) {
        var img, preview, w = el.getAttribute('w');
        if (!w) {
            preview = el.getAttribute('preview');
            img = new Image();
            img.onload = function () {
                el.setAttribute('w', img.width);
                el.setAttribute('h', img.height);
                el.setAttribute('rw', img.width / el.clientWidth);
                el.setAttribute('rh', img.height / el.clientHeight);
                img.remove();
            };
            img.src = preview;
            img.className = 'hidden';
            document.body.append(img);
            return;//not loaded yet
        }

        if (!el.getAttribute('playing')) {
            el.setAttribute('playing', 1);
            el.style.backgroundImage = el.getAttribute('preview');
        }

        var nbrow, nbcol, x, y, w = w / columns,
            media = el.getAttribute('media'),
            h = el.getAttribute('h') / rows,
            play = el.getAttribute('play');

        play++;//1720,1290
        if (play >= (columns * rows)) play = 0;
        el.setAttribute('play', play);
        rw = el.getAttribute('rw');
        rh = el.getAttribute('rh');

        nbrow = Math.floor(play / columns);
        nbcol = play - (nbrow * columns);
        x = nbcol * w;
        y = nbrow * h;
        x = 0;

        el.style.backgroundPosition = x + 'px ' + y + 'px';
    }

    function popup(link, el, share) {
        var el = el || null, share = share || null;
        if (el && share) {//refresh token
            getToken(el, share, function (tokenQS) {
                play.className = 'show';
                play.innerHTML = '<iframe class=if allowfullscreen src="' + link + tokenQS + '"></iframe>';
            });
        } else {//direct
            play.className = 'show';
            play.innerHTML = '<iframe class=if allowfullscreen src="' + link + '"></iframe>';
        }
        return false;
    }

    function getToken(el, share, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '?gettoken=1', true);
        xhr.onreadystatechange = function () {
            if (this.readyState != 4) return;
            if (this.status == 200) {
                response = JSON.parse(this.responseText);

                if (!response.token) {// if an error encountered ( crsf token not fresh, requests a new one )
                    var xhr2 = new XMLHttpRequest();
                    xhr2.onreadystatechange = function () {
                        if (this.readyState != 4) return;
                        if (this.status == 200) {
                            response = JSON.parse(this.responseText);
                            if (response.error) {
                                console.error('refresh token error', response.error);
                                return;
                            }
                            uniqid = response.uniqid;
                            getToken(el, share, callback);//then recurses
                        }
                    }
                    xhr2.open('POST', '?refreshToken=1', true);
                    var data = new FormData();
                    data.append('hash', '<?php echo end($innerHashes);?>');
                    xhr2.send(data);
                    return;
                }
                callback('?' + response.token);
            }
        };

        var data = new FormData();
        data.append('uniqid', uniqid);
        data.append('hash', '<?php echo end($innerHashes);?>');
        data.append('getToken4share', share);
        xhr.send(data);
        return;
    }

    function getStats(mediaUuids, callback) {

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '?mediaStats=1', true);
        xhr.onreadystatechange = function () {
            if (this.readyState != 4) return;
            if (this.status == 200) {
                callback(this.responseText);
            }
        };

        var data = new FormData();
        data.append('mediaStats', mediaUuids);
        xhr.send(data);
        return;
    }

    <?php
    echo '</script>';


    if (0 and 'other usefull ( yet disabled in this script ) functions') {
        if ('6 : search for a media by name') {
            $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/browse?page=1&per_page=35&with=shares,thumbnail&search=' . $searchFor];
            $searchResults = json_decode(curlRequest($options), true)['data'];
            $sharesForMedias = [];
            foreach ($searchResults as $searchResult) {
                /* other with : thumbnail,sample,preview,thumbstrip,manifests */
                $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/media/' . $searchResult['id'] . '?with=shares'];
                $res = json_decode(curlRequest($options), true)['data'];
                if (isset($res['shares']) and $res['shares']) {
                    foreach ($res['shares'] as $share) {
                        $sharesForMedias[$searchResult['id']] = 'https://player.vod2.infomaniak.com/embed/' . $share['id'];
                    }
                }
            }
            print_r(compact('searchResults', 'sharesForMedias'));
        }

        if ('7: query video statistics') {

            if ('viewing') {
                $stats['viewing'] = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/media/' . $mediaId . '/viewing?from=' . $statsFrom]), true)['data']['value'];
            }
            if ('viewers') {
                $stats['viewers'] = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/media/' . $mediaId . '/viewers?from=' . $statsFrom]), true)['data']['value'];
            }
            if ('unique viewers') {
                $stats['unique_viewers'] = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/media/' . $mediaId . '/viewers/uniques?from=' . $statsFrom]), true)['data']['value'];

            }
            if ('average viewing time on channel') {
                $res = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/avg_time?from=' . $statsFrom]))['data'];
                $stats['number_ips'] = $res['number_ips'];
                $stats['average_time'] = $res['average_time'];
            }


            if ('countries in csv format') {
                $stats['countries:csv'] = curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/geolocation/countries?from=' . $statsFrom.'&format=csv'])['data'];
            }

            if ('cities') {
                $stats['cities:json']  = curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/geolocation/cities?from=' . $statsFrom])['data'];
            }

            if ('location : lat, lon clusters') {
                $stats['latLonClusters'] = curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/geolocation/clusters?from=' . $statsFrom])['data'];
            }

            if ('location : lat, lon clusters, groupés par pays') {
                $stats['latLonByCountry'] = curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/geolocation/clusterspercountry?from=' . $statsFrom])['data'];
            }
            //$mediasUuids
            if ('bulk : multiple medias viewers') {
                $stats['viewers'] = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/statistics/viewers/medias?from=' . $statsFrom . '&medias=' . implode(',', $mediasUuids)]), true)['data'];
            }
        }

        print_r($stats);

        $videoFileName = '';
        $file2uploadLocalPath = '';
        $videoUrl = 'https://www.youtube.com/watch?v=uSce2_Te6Dc';

        if ('8: upload media into this folder' && is_file($file2uploadLocalPath)) {
            $post = ['folder' => $folder, 'name' => 'videoName', 'file' => new \CURLFile($file2uploadLocalPath, '', $videoFileName)];
            $contents = json_decode(curlRequest([CURLOPT_TIMEOUT => 360000/* large timeout for large files */, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'User-Agent: client', 'Content-Type: multipart/form-data'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/upload', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post]),true);
            $uploadedMediaId = $contents['data']['id'];
            echo "\nUploaded media id: " . $uploadedMediaId;
        }

        if ('9: upload a distant media ( by url ) into this folder') {
            $post = ['folder' => $folder, 'filename' => 'videoName', 'url' => $videoUrl];
            $contents = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'User-Agent: client', 'Content-Type: multipart/form-data'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/upload', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $post]),true);
            $uploadedMediaId = $contents['data']['id'];
            echo "\nUploaded media id: " . $uploadedMediaId;
        }

        if ('10: set a callback if none present') {
            $callbacks = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'User-Agent: client', 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/callback']),true);

            if (!$callbacks or !$callbacks['data']) {
                $contents = json_decode(curlRequest([CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'User-Agent: client', 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_URL => $apiUrl . '/callback', CURLOPT_POST => 1, CURLOPT_POSTFIELDS => '{"name":"callback","events": ["media_ready","encoding_finished"],"url":"http://yourdomain.com/callbackHandler.php","response":"json","auth":"none","active": true}']),true);
            }
            echo "\nCallback id: " . $contents['data']['id'];
        }
    }
    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . ':' . $e->getLine();// . ':' . json_encode($e);
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

    ?>
    <style id="style"></style>
    <style>
    #play {
        position: fixed;
        width: 99vw;
        height: 100vh;
        background: #000B;
        display: flex;
        cursor: pointer;
    }

    #play.hide {
        display: none;
        z-index: -1;
    }

    #play.show {
        display: flex;
        z-index: 99;
    }

    .if {
        margin: auto;
        width: 99vw;
        aspect-ratio: 16/9;
    }

    .media {
        display: inline-block;
    }

        .img {
        width: 12vw;
        height: 9vw;
        margin: auto;
        background-size: cover;
        /*border:1px dashed #0F0C;*/
    }

    html {
        color: #BBB;
        background: #000;
        font-size: 10px;
    }

    body {
        font-size: 1.5rem;
        font-family: Avenir Next, Sans-Serif
    }

        b {
        color: #F00
    }

        #result {
        border: 2px dashed #D00;
    }

        .error {
        border: 2px dashed #D00;
    }

        h1 {
        margin: auto;
        color: #07F;
    }

        a {
        color: #07F;
    }


        .row {
        display: flex;
        flex-wrap: wrap;
        max-width: 99vw
    }

        .c {
        flex: 1;
        border: 1px dashed #999;
        margin: auto;
        text-align: center;
    }

        .hidden {
        display: none;
    }

        a, img {
        cursor: pointer
    }

        .media:hover .img {
        animation: preview 3s steps(72) 1 forwards;
    }

    </style>
<?php return;?>


curl -ks -H "Authorization: Bearer $token" -H "Content-type: application/json" 1/channel/xXx/browse/update -X PUT --data '{ "targets": ["1jhvl2uqh41ll","1jhvl2uqh3yl8"], "published": true, "validated": true }'
curl -ks -H "Authorization: Bearer $token" -H "Content-type: application/json" 1/channel/xXx/browse/trash -X DELETE --data '{"targets": ["1jhvl2xxx4wzm"]}'; # permanently delete file from trash
curl -ks -H "Authorization: Bearer $token" -H "Content-type: application/json" 1/channel/XxX/player/1jhvlxXxf5inu -X PUT --data '{"name":"name","slug":"slut"}';# etc pour tous les attributs
curl -ks -H "Authorization: Bearer $token" 1/channel/XxX/media/YyY/chapter/ZzZ

POST /channel/xxx/browse/move --data '{targets: ["1jhvl2uqhksoy"], destination: "1jhvl2uq8p2r0"}';# medias Uuid to folder, caution, it deletes previous shares
POST : /channel/{channel}/browse/copy --data '{name: "mediaNameInFolder", destination: "1jhvl2uq8p2r0"}'
