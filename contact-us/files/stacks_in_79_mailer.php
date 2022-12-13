<?php

ini_set('display_errors', '0');
register_shutdown_function(function () {
    $lastError = error_get_last();

    if (!empty($lastError) && $lastError['type'] == E_ERROR) {
        http_response_code(500);
        echo $lastError["message"];
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    count($_POST) === 0 ||
    !key_exists("formkey-stacks_in_79", $_POST) ||
    $_POST["formkey-stacks_in_79"] !== "stacks_in_61") {
    http_response_code(500);
    echo "Error: Invalid request";
    exit;
}
unset($_POST["formkey-stacks_in_79"]);
require '../../rw_common/plugins/stacks/foundation-forms/foundation-autoload.php';

$templateFile = 'stacks_in_79_template.txt';
$template = file_exists($templateFile) ? file_get_contents($templateFile) : '';

$cmsTemplate = boolval("0");
if ($cmsTemplate) {
    require '../../rw_common/plugins/stacks/total-cms/totalcms.php';
    $totaltext = new \TotalCMS\Component\Text('cmsid');
    $template = $totaltext->get_contents();
}

$useTemplate = boolval("0")||boolval("0");

$mailer = new \Foundation\Mailer([
    'adminTo'      => 'webmaster@partnersinunity.com',
    'adminFrom'    => 'no-reply@partnersinunity.com',
    'adminSubject' => 'An error occurred with your PiU partner contact form',

    'fromName'     => 'Partners In Unity',
    'fromAddress'  => 'no-reply@partnersinunity.com',
    'replyName'    => '',
    'replyAddress' => '',

    'toName'       => 'Director of Donor and Partner Relations',
    'toAddress'    => 'partners@partnersinunity.com',
    'ccName'       => '',
    'ccAddress'    => '',
    'bccName'      => '',
    'bccAddress'   => '',

    'useSmtp'      => boolval("0"),
    'smtpHost'     => 'smtp.example.com',
    'smtpUser'     => 'user@example.com',
    'smtpPass'     => 'secret',
    'smtpPort'     => intval("587"),
    'smtpSecure'   => 'tls',

    'subject'      => 'Message from {{full_name}} on PiU Site',
    'useTemplate'  => $useTemplate,
    'template'     => $template,

    'acceptAttachments' => boolval("1")
]);

$mailer->send();
