<?php
function return_error($results = '')
{
    header("HTTP/1.1 403 Unauthorized");
    echo $results;
}
function return_success()
{
    echo 'You have passed the test!';
}

if (empty($_POST["g-recaptcha-response"])) return_error();

// handling the captcha and checking if it's ok
$url = "https://www.google.com/recaptcha/api/siteverify";
$fields = array(
    'secret'   => urlencode(""),
    'response' => urlencode($_POST["g-recaptcha-response"])
);

// post
$ch = curl_init();
curl_setopt($ch,CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_POST, count($fields));
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
$results = curl_exec($ch);
curl_close($ch);

$response = json_decode($results);

if ($response->success == true) {
    return_success();
} else {
    return_error($results);
}
