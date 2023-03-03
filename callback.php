<?php // stores callback into file and return response 200 OK asap
// ( you wont receive any callbacks if you endpoint doesn't respond within 10 sec
// or responds another status code, such as 404, 500,502,503 etc ..
$started = microtime(true);
header('HTTP/1.1 200 OK', true, 200);
// If Available and there's a need to process the response asap ( if you have enough fpm slots for this otherwise consider using callbacks-worker.php )
if (function_exists('fastcgi_finish_request')) {// Tells the sender the response is taken into consideration, so he might send you the next one asap
    @fastcgi_finish_request();// otherwise, throws 500 Error when not available ..
}

if (isset($_SERVER['CONTENT_LENGTH'])) {// request has a body : delay your processing into a separate worker
    chdir(__DIR__);
    $folder = 'callbacks';
    if (!is_dir($folder)) mkdir($folder);
    $u = microtime(true) . '.' . uniqid();
    file_put_contents($folder . '/' . $u . '.callback.log', file_get_contents('php://input'));// then use a worker in order to process this data
}

return;?>
NOTIFICATION_EVENT :

- MEDIA_READY : all encodings and thumbnails are generated ( last thumbnails might take a while ... )
- ENCODING_FINISHED : media is ready for playback with at least one quality available
