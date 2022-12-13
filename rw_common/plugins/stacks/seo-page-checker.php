<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

//-------------------------------------------
// Return Data
//-------------------------------------------
function return_success($msg)
{
    header('Content-Type: application/json');
    echo json_encode(array(
        'code' => 200,
        'data' => $msg,
    ));
    exit();
}
function return_error($msg)
{
    $json = json_encode(array(
        'code' => 500,
        'data' => $msg,
    ));
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    die($json);
}

// LiteSpeed server hack. SCRIPT_NAME on shared hosting contains domain name
// This was on A2 hosting. Strip the domain out
$domain    = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
$script    = preg_replace("/http[s]*:\/\/$domain/", '', $_SERVER['SCRIPT_NAME']);
$site_root = realpath(preg_replace("!${script}$!", '', $_SERVER['SCRIPT_FILENAME']));

$method  = $_SERVER['REQUEST_METHOD'];

if (!isset($_GET["folder"])) {
    exit;
}

if ($method == 'GET') {
    $folder = $_GET["folder"];
    $html   = "$site_root/$folder/index.html";
    $php    = "$site_root/$folder/index.php";

    if (file_exists($html) && file_exists($php)) {
        return_error('duplicate');
    }
    return_success('no dups');
}

if ($method == 'POST') {
    $request = json_decode(file_get_contents('php://input'));

    if (!isset($request->cleanup)) {
        exit;
    }

    $folder  = $_GET["folder"];
    $type    = $request->cleanup;
    $html    = "$site_root/$folder/index.html";
    $php     = "$site_root/$folder/index.php";

    if ($type == 'html' && file_exists($html)) {
        if (unlink($html)) {
            return_success('complete');
        }
        return_error('unlink html failed');
    }
    if ($type == 'php' && file_exists($php)) {
        if (unlink($php)) {
            return_success('complete');
        }
        return_error('unlink php failed');
    }
}
