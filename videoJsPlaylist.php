<body>
<div class='container'>
    <video id="content_video" class="video-js vjs-default-skin vjs-16-9" controls preload="auto">
        <source src="https://play.vod2.infomaniak.com/hls/##MediaUUID##/##EncodingUUID##/,a,b,c,d,.urlset/manifest.m3u8" type="application/x-mpegURL" />
    </video>
</div>
</body>

<link href="https://vjs.zencdn.net/7.17.0/video-js.css" rel="stylesheet" />
<script src="https://vjs.zencdn.net/7.17.0/video.min.js"></script><script>
    player = videojs('content_video',{},function(){}); player.on('ready', function() {  player.vhs = null;  });
    player.play();
</script>
