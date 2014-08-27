<?php
require_once 'phpthumb/ThumbLib.inc.php';
ini_set('allow_url_fopen', 1);

/* Basic settings
   ==========================================================================
*/
$downloadTempFolder = 'downloads'; //folder to put temporary downloaded images and json feeds from social services - remember to contact your social worker first and get credentials for their services.
$resizedImagesFolder = 'resizes'; //folder to put temporary downloaded images and json feeds from social services - remember to contact your social worker first and get credentials for their services.
$downloadItemsCount = 20; //Amount of posts, tweets, photos to get from each service.
$DEBUG_MODE = false; //Will output the raw json feed from the services in $downloadTempFolder/feeds/
$totalPostsToSave = 30; //Limit the total amount of posts sorted by newest post across the different services.
$thumbnailOptions = array('resizeUp' => true, 'jpegQuality' => 90);
$blockedItems = json_decode(file_get_contents("blocked.json")); //a JSON file you can put post id's in to block them from the generated list, useful for filtering out similar posts published on multiple social sites.
$imageResizeWidth = 880;
$imageResizeHeight = 880;

/* Social services configuration

   ==========================================================================
   Getting a JSON Facebook Feed
   ==========================================================================

   1. Sign in as a developer at https://developers.facebook.com/

   2. Click "Create New App" at https://developers.facebook.com/apps

   3. Under Apps Settings, find the App ID and App Secret

   4. Find the desired feed ID at http://findmyfacebookid.com/
*/

$facebookAppID = '';
$facebookAppSecret = '';
$facebook_id = "";

/* Getting a JSON Twitter Feed
   ==========================================================================

   1. Sign in as a developer at https://dev.twitter.com/

   2. Click "Create a new application" at https://dev.twitter.com/apps

   3. Under Application Details, find the OAuth settings and the access token

   4. Find the desired twitter username
*/

$twitterConsumerKey = '';
$twitterConsumerSecret = '';
$twitterAccessToken = '';
$twitterAccessTokenSecret = '';
$twitterUsername = '';

/* Getting a JSON Instagram Feed
   ==========================================================================

   1. Sign in as a developer at https://instagram.com/accounts/login/?next=%2Fdeveloper%2F

   2. Register your application / client.

   3. In client / application settings uncheck "Disable implicit OAuth" and "Enforced signed header" checkboxes.

   4. Generate an Instagram access token: http://jelled.com/instagram/access-token

   5. Find your instagram username
*/
$instagram_access_token = "";
$instagram_username = "";


/* Helper functions
   ==========================================================================
*/

/**
 * Checks if a post id is in the $blockedItems list.
 * @param String $id
 * @param Array $blockedItems
 */
function checkIfBlocked($id, $blockedItems)
{
    if (in_array((string)$id, $blockedItems)) {
        return true;
    } else {
        return false;
    }
}

/**
 * create file with content, and create folder structure if doesn't exist
 * @param String $filepath
 * @param String $message
 */
function forceFilePutContents($filepath, $message)
{
    try {
        $isInFolder = preg_match("/^(.*)\/([^\/]+)$/", $filepath, $filepathMatches);
        if ($isInFolder) {
            $folderName = $filepathMatches[1];
            $fileName = $filepathMatches[2];
            if (!is_dir($folderName)) {
                mkdir($folderName, 0777, true);
            }
        }
        file_put_contents($filepath, $message);
    } catch (Exception $e) {
        echo "ERR: error writing '$message' to '$filepath', " . $e->getMessage();
    }
}

/**
 * load url over ssl
 * @param String $curl_option optional
 * @param String $option_value optional
 */
function getSslPage($url, $curl_option = null, $option_value = null)
{
    $ch = curl_init();
    if (isset($curl_option) && isset($option_value)) {
        curl_setopt($ch, $curl_option, $option_value);
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_REFERER, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

/**
 * Array sort function for filtering by date->timestamp.
 * @param Date $a
 * @param Date $b
 */
function datesort($a, $b)
{
    return intval($b->timestamp) - intval($a->timestamp);
}

/**
 * Thumbnail function
 * @param String $id
 * @param Object $feed_item
 * @param String $downloadTempFolder
 * @param Number $imageResizeWidth
 * @param Number $imageResizeHeight
 * @param Array $thumbnailOptions
 * @param String $resizedImagesFolder
 */
function downloadAndCreateThumbnail($id, &$feed_item, $downloadTempFolder, $imageResizeWidth, $imageResizeHeight, $thumbnailOptions, $resizedImagesFolder)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_URL, $feed_item->image);
    curl_setopt($ch, CURLOPT_REFERER, $feed_item->image);

    $fp = fopen($downloadTempFolder . '/images/' . $id . '.jpg', 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    $thumb = PhpThumbFactory::create($downloadTempFolder . '/images/' . $id . '.jpg', $thumbnailOptions);
    $thumb->adaptiveResize($imageResizeWidth, $imageResizeHeight);
    $thumb->cropFromCenter($imageResizeWidth, $imageResizeHeight);
    $thumb->save($resizedImagesFolder . '/' . $id . '.jpg', "jpg");
    $feed_item->image = '/socialfeed/' . $resizedImagesFolder . '/' . $id . '.jpg';
}

/* Create directories
   ==========================================================================
*/

if (!is_dir($downloadTempFolder . "/images")) {
    mkdir($downloadTempFolder . "/images", 0777, true);
}
if (!is_dir($resizedImagesFolder)) {
    mkdir($resizedImagesFolder, 0777, true);
}

/* Loading and parsing of feeds
   ==========================================================================
   Basically each feed service needs to be authenticated against, loaded and then parsed - each with small variations.
*/


//Holds parsed feed items for later sorting / filtering:
$feed_items = array();

/* Load and parse Twitter feed
   ==========================================================================
*/
$filetime = time() - 1;
$url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
$base = 'GET&' . rawurlencode($url) . '&' . rawurlencode("count={$downloadItemsCount}&oauth_consumer_key={$twitterConsumerKey}&oauth_nonce={$filetime}&oauth_signature_method=HMAC-SHA1&oauth_timestamp={$filetime}&oauth_token={$twitterAccessToken}&oauth_version=1.0&screen_name={$twitterUsername}");
$key = rawurlencode($twitterConsumerSecret) . '&' . rawurlencode($twitterAccessTokenSecret);
$signature = rawurlencode(base64_encode(hash_hmac('sha1', $base, $key, true)));
$oauth_header = "oauth_consumer_key=\"{$twitterConsumerKey}\", oauth_nonce=\"{$filetime}\", oauth_signature=\"{$signature}\", oauth_signature_method=\"HMAC-SHA1\", oauth_timestamp=\"{$filetime}\", oauth_token=\"{$twitterAccessToken}\", oauth_version=\"1.0\", ";

$response = getSslPage($url . "?screen_name={$twitterUsername}&count={$downloadItemsCount}", CURLOPT_HTTPHEADER, array("Authorization: Oauth {$oauth_header}", 'Expect:'));

if ($DEBUG_MODE) {
    $filename = $downloadTempFolder . "/feeds/twitter_feed.json";
    forceFilePutContents($filename, $response);
}

$twitter_response = json_decode($response);
foreach ($twitter_response as $key => $value) {
    if (checkIfBlocked($value->id, $blockedItems) || strlen($value->in_reply_to_status_id_str) > 0) {
        continue;
    }
    $feed_item = new stdClass();
    $feed_item->id = strval($value->id);
    $feed_item->timestamp = intval(strtotime($value->created_at));
    $feed_item->message = $value->text;
    $feed_item->image = "";
    $feed_item->link = "https://twitter.com/{$twitterUsername}/status/" . $feed_item->id;
    $feed_item->source = "twitter";
    array_push($feed_items, $feed_item);
}


/* Load and parse Facebook feed
   ==========================================================================
*/
$authentication = getSslPage("https://graph.facebook.com/oauth/access_token?grant_type=client_credentials&client_id={$facebookAppID}&client_secret={$facebookAppSecret}");

$response = getSslPage("https://graph.facebook.com/{$facebook_id}/feed?{$authentication}&limit={$downloadItemsCount}");

if ($DEBUG_MODE) {
    $filename = $downloadTempFolder . "/feeds/facebook_feed.json";
    forceFilePutContents($filename, $response);
}

$facebook_response = json_decode($response);
foreach ($facebook_response->data as $key => $value) {
    if (checkIfBlocked($value->id, $blockedItems)) {
        continue;
    }
    if ($value->from->id != $facebook_id || $value->type == "status") { //Filter out comments from own facebook id, and filter out posts on facebook id's timeline that are poster by other users.
        continue;
    }
    $feed_item = new stdClass();
    $feed_item->id = strval($value->id);
    $feed_item->timestamp = intval(strtotime($value->created_time));
    $feed_item->message = isset($value->message) ? $value->message : "";
    $feed_item->link = $value->link;
    $feed_item->source = "facebook";

    //Get image / thumbnail for post of type photo / video.
    $isVideo = isset($value->application) && $value->application->namespace == "video";
    if (($value->type == "photo" || $isVideo) && isset($value->object_id)) {
        $feed_item->image = $value->picture;
        if (property_exists($value, "story")) {
            $feed_item->message = $value->story;
        }
        if (!$isVideo) {
            //Video objects seem to have a picture value which is a high-res (or as high-res as the source video is) dump, so no reason to do another fetch.
            $imageObject = json_decode(getSslPage("https://graph.facebook.com/" . $value->object_id));
            $feed_item->image = $imageObject->images[0]->source;
        }
        //Create thumbnail:
        downloadAndCreateThumbnail("facebook_" . $value->object_id, $feed_item, $downloadTempFolder, $imageResizeWidth, $imageResizeHeight, $thumbnailOptions, $resizedImagesFolder);
    } else {
        $feed_item->image = "";
    }
    array_push($feed_items, $feed_item);
}

/* Load and parse Instagram feed
   ==========================================================================
*/
$response = getSslPage("https://api.instagram.com/v1/users/self/feed?count={$downloadItemsCount}&access_token={$instagram_access_token}");

if ($DEBUG_MODE) {
    $filename = $downloadTempFolder . "/feeds/instagram_feed.json";
    forceFilePutContents($filename, $response);
}

$instagram_response = json_decode($response);
foreach ($instagram_response->data as $key => $value) {
    if (checkIfBlocked($value->id, $blockedItems) || $value->user->username != $instagram_username) {
        continue;
    }
    $feed_item = new stdClass();
    $feed_item->id = strval($value->id);
    $feed_item->timestamp = intval($value->created_time);
    $feed_item->message = isset($value->caption->text) ? $value->caption->text : "";
    $feed_item->image = $value->images->standard_resolution->url; //Other resolutions are available here, the standard_resolution is the highest resolution.

    downloadAndCreateThumbnail("instagram_" . $value->id, $feed_item, $downloadTempFolder, $imageResizeWidth, $imageResizeHeight, $thumbnailOptions, $resizedImagesFolder);

    $feed_item->link = $value->link;
    $feed_item->source = "instagram";
    array_push($feed_items, $feed_item);
}

/* Save, sort and filter the posts
   ==========================================================================
*/
usort($feed_items, "datesort"); //Sort posts by date.
$feed_items = array_slice($feed_items, 0, $totalPostsToSave); //Limit amount of saved posts
forceFilePutContents('combined_feed.json', json_encode($feed_items)); //Write the combined feed to disk