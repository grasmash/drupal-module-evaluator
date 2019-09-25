<?php

$url = "https://git.drupalcode.org/project/lightning_media/raw/8.x-3.x/.travis.yml";
echo downloadFile($url);

function downloadFile($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // We be spoofin.
    curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

    if (curl_exec($ch) === FALSE) {
        throw new Exception("Error: " . curl_error($ch));
        } else {
        return curl_exec($ch);
    }

    curl_close($ch);
}
