<?php
$soapUrl = 'https://api.vod2.infomaniak.com/v1soap/soap/wsdl2';
$user = 'user@pass.com';
$password = 'xx';
$channelSlug = 'xx';
/*
1) get your channel id : https://manager.infomaniak.com/v3/xxx/vod/{{ChannelId}}
2) get your application token here : https://manager.infomaniak.com/v3/ng/accounts/token/list
3) get your channel Slug :
curl -ks -H "Authorization: Bearer $token" https://api.infomaniak.com/1/vod/channel/$ChannelId | jq .data.slug
4) get or set your password here :
https://manager.infomaniak.com/v3/ng/profile/user/connection-history/application-password
 */

try{
    $ctx = \stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false], 'http' => ['timeout' => 999]]);
    $client = new \SoapClient($soapUrl, ['connection_timeout' => 999, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace' => 1, 'exceptions' => 1, 'encoding' => 'UTF-8', 'stream_context' => $ctx]);
    $availableMethods = $client->__getFunctions();
    print_r($availableMethods);

    $client->__setSoapHeaders(array(new \SoapHeader('urn:vod_soap', 'AuthenticationHeader', new \SoapAuthentificationHeader($user, $password, $channelSlug))));

    print_r($client->getLastImportation());
}catch(\Throwable $e){
    print_r($e);
}
return;

class SoapAuthentificationHeader
{
    public $sPassword, $sLogin, $sVod;

    public function __construct($sLogin, $sPassword, $sVod)
    {
        $this->sPassword = $sPassword;
        $this->sLogin = $sLogin;
        $this->sVod = $sVod;
    }
}
?>
