<?php // stores callback into file and return response 200 OK asap
// ( you wont receive any callbacks if you endpoint doesn't respond within 10 sec
// or responds another status code, such as 404, 500,502,503 etc ..
header('HTTP/1.1 200 OK',true,200);
@fastcgi_finish_request();// If Available
$folder='callbacks';
if(!is_dir($folder))mkdir($folder);
file_put_contents($folder.'/'.microtime(true).'.'.uniqid().'.callback.log',file_get_contents('php://input'));
die;

while('worker'){
    $callbacks=glob($folder.'/*.callback.log');// sorted by microtime asc
    foreach($callbacks as $callback){
        doStuff(file_get_contents($callback));
        unlink($callback);//remove data
    }
    sleep(10);
}

function doStuff($data){

}
