# Infomaniak Vod Api
> How to use vod infomaniak api with curl requests made easy ( no dependencies, just simple php, curl and javascripts )
* First, Get your application token here : https://manager.infomaniak.com/v3/ng/accounts/token/list
* Then run apiVod.php in your browser or edit the first part of the file in order to enter your token and channel Id
> Full documentation here : https://developer.infomaniak.com/docs/api/#Vod


> Featuring :
> - caching results in session ( for quick demo purprosees )
> - caching thumbnails
> - on the fly secure token creation
> - statistics and ajax actions
> - Callbacks handler and worker ( see callbacksExamples folder for json responses structure )

> Please : 
> - follow callback recommandation ( which allows your host to quickly receive them, then send the response ASAP ), if your host takes more than 10s to send the 200 OK header, then your host will be blacklisted for 2 hours ( you wont receive any callbacks in that period of time)
