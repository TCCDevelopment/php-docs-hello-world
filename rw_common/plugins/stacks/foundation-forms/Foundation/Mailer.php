<?php
namespace Foundation;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use League\CommonMark\CommonMarkConverter;

class Mailer
{
    private $adminTo;
    private $adminFrom;
    private $adminSubject;

    private $useSmtp;
    private $smtpHost;
    private $smtpUser;
    private $smtpPass;
    private $smtpPort;
    private $smtpSecure;

    private $fromName;
    private $fromAddress;
    private $replyName;
    private $replyAddress;

    private $toName;
    private $toAddress;
    private $ccName;
    private $ccAddress;
    private $bccName;
    private $bccAddress;

    private $subject;
    private $template;
    private $useTemplate;

    private $mail;
    private $mustache;
    private $attachments;
    private $acceptAttachments;

    public function __construct($options)
    {
        $options = array_merge(array(
            'adminTo'      => '',
            'adminFrom'    => '',
            'adminSubject' => '',

            'useSmtp'      => false,
            'smtpHost'     => '',
            'smtpUser'     => '',
            'smtpPass'     => '',
            'smtpPort'     => 587,
            'smtpSecure'   => 'none',

            'fromName'     => '',
            'fromAddress'  => '',
            'replyName'    => '',
            'replyAddress' => '',

            'toName'       => '',
            'toAddress'    => '',
            'ccName'       => '',
            'ccAddress'    => '',
            'bccName'      => '',
            'bccAddress'   => '',

            'subject'      => 'Email from website',
            'template'     => '',
            'useTemplate'  => false,

            'acceptAttachments' => true,
        ), $options);

        // Mustache Tempaltes
        \Mustache_Autoloader::register();
        $this->mustache = new \Mustache_Engine;

        // Process the $_POST data
        $this->postData = $this->postData();

        // Set up all of the variables
        $this->adminTo      = $this->templateString($options['adminTo']);
        $this->adminFrom    = $this->templateString($options['adminFrom']);
        $this->adminSubject = $this->templateString($options['adminSubject']);

        $this->fromName     = $this->templateString($options['fromName']);
        $this->fromAddress  = $this->templateString($options['fromAddress']);
        $this->replyName    = $this->templateString($options['replyName']);
        $this->replyAddress = $this->templateString($options['replyAddress']);

        $this->toName       = explode(',', $this->templateString($options['toName']));
        $this->toAddress    = explode(',', $this->templateString($options['toAddress']));
        $this->ccName       = explode(',', $this->templateString($options['ccName']));
        $this->ccAddress    = explode(',', $this->templateString($options['ccAddress']));
        $this->bccName      = explode(',', $this->templateString($options['bccName']));
        $this->bccAddress   = explode(',', $this->templateString($options['bccAddress']));

        $this->subject      = $this->templateString($options['subject']);
        $this->subject      = $this->templateString($this->subject); // process again in for template data to be inside of the passed data
        $this->template     = $options['template'];
        $this->useTemplate  = $options['useTemplate'];

        $this->useSmtp    = $options['useSmtp'];
        $this->smtpHost   = $options['smtpHost'];
        $this->smtpUser   = $options['smtpUser'];
        $this->smtpPass   = $options['smtpPass'];
        $this->smtpPort   = $options['smtpPort'];
        $this->smtpSecure = $options['smtpSecure'];

        $this->acceptAttachments = $options['acceptAttachments'];

        $this->attachments = [];

        // Create a new PHPMailer instance with Exceptions enabled
        $this->mail = new PHPMailer(true);
        $this->mail->CharSet = "UTF-8";
        $this->mail->UseSendmailOptions = !self::isSnuffleupagusEnabled();

        $this->configSMTP();
    }

    public function send()
    {
        try {
            //Set who the message is to be sent from
            $this->mail->setFrom($this->fromAddress, $this->fromName);
            if (!empty($this->replyAddress)) {
                $this->mail->addReplyTo($this->replyAddress, $this->replyName);
            }

            //Set the subject line
            $this->mail->Subject = $this->subject;

            $this->generateBody();
            $this->addRecipients();
            $this->addAttachments();

            //send the message, check for errors
            $this->mail->Send();

            $this->cleanAttachments();

            $this->returnSuccess("Mail Sent!");
        } catch (phpmailerException $e) {
            // Fallback to mail()
            if (mail($this->toAddress[0], $this->subject, $bodyText)) {
                $this->returnSuccess("Mail Sent Old School! PHPMailer error occurred: ".$e->errorMessage());
            }
            $this->returnError('Mailer Error: '.$e->errorMessage());
        } catch (\Exception $e) {
            $this->returnError('System Error: '.$e->getMessage());
        }
    }

    private function templateString($string)
    {
        return $this->mustache->render($this->decodeString($string), $this->postData);
    }

    private function cleanAttachments()
    {
        if (!$this->acceptAttachments) {
            return;
        }

        // Cleanup attachments
        foreach ($this->attachments as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function templateBody()
    {
        $data = $this->postData;

        // Add support for the {{fields}} macro and process mustache
        $data['fields'] = $this->defaultBody();
        $data['urlparams'] = $this->urlParams();
        $template = $this->mustache->render($this->template, $data);
        $template = $this->mustache->render($template, $data); // process again in for template data to be inside of the passed data

        return $this->markdownify($template);
    }

    private function newlineBreak($str)
    {
        // Convert newlines to HTML breaks
        return preg_replace("/\r*\n/", "<br>", $str);
    }

    private function generateBody()
    {
        $body = $this->useTemplate ? $this->templateBody() : $this->defaultBody();
        //Replace the plain text body with one created manually

        // don't process newlines for HTML email
        $this->mail->Body = $body;
        $this->mail->AltBody = strip_tags($body);
    }

    private function addAttachments()
    {
        if (!$this->acceptAttachments) {
            return;
        }

        if (!empty($_FILES)) {
            $dir = $_SERVER["DOCUMENT_ROOT"].'/form-uploads';
            if (!file_exists($dir)) {
                mkdir($dir);
            }

            foreach ($_FILES as $name => $value) {
                foreach ($_FILES[$name]['tmp_name'] as $index => $tmpName) {
                    if ($_FILES[$name]['error'][$index] != UPLOAD_ERR_OK) {
                        // Skip if there was an error
                        continue;
                    }
                    // check whether it's not empty, and whether it indeed is an uploaded file
                    if (!empty($tmpName) && is_uploaded_file($tmpName)) {
                        $fullpath = "$dir/".$_FILES[$name]['name'][$index];
                        move_uploaded_file($tmpName, $fullpath);
                        $this->mail->AddAttachment($fullpath);
                        $this->attachements[] = $fullpath;
                    }
                }
            }
        }
    }

    private function addRecipients()
    {
        // To
        for ($i = 0; $i < count($this->toAddress); ++$i) {
            $address = trim($this->toAddress[$i]);
            $name    = trim($this->toName[$i] ?? $address);
            $this->mail->addAddress($address, $name);
        }
        // CC
        for ($i = 0; $i < count($this->ccAddress); ++$i) {
            $address = trim($this->ccAddress[$i]);
            $name    = trim($this->ccName[$i] ?? $address);
            if (empty($address)) {
                continue;
            }
            $this->mail->addCC($address, $name);
        }
        // BCC
        for ($i = 0; $i < count($this->bccAddress); ++$i) {
            $address = trim($this->bccAddress[$i]);
            $name    = trim($this->bccName[$i] ?? $address);
            if (empty($address)) {
                continue;
            }
            $this->mail->addBCC($address, $name);
        }
    }

    private function configSMTP()
    {
        // Use SMTP?
        if ($this->useSmtp) {
            $this->mail->isSMTP();
            $this->mail->SMTPAuth = true;
            $this->mail->Host     = $this->smtpHost;
            $this->mail->Username = $this->smtpUser;
            $this->mail->Password = $this->smtpPass;
            $this->mail->Port     = intval($this->smtpPort);
            if ($this->smtpSecure !== "none") {
                $this->mail->SMTPSecure = $this->smtpSecure;
            }
        }
    }

    public function postData()
    {
        $data = array();
        unset($_POST["g-recaptcha-response"]);

        foreach ($_POST as $name => $value) {
            // Copy POST to data array and convert array values to strings
            $data[$name] = is_array($value) ? implode(", ", $value) : $value;
        }
        return $data;
    }

    private function urlParams()
    {
        $params = "";
        foreach ($this->postData as $name => $value) {
            $params .= empty($params) ? "$name=" : "&$name=";
            $params .= urlencode($value);
        }
        return $params;
    }

    private function defaultBody()
    {
        $body = "";
        foreach ($this->postData as $name => $value) {
            if (strpos($name, "formkey-stacks") === 0) {
                // Ignore formkey-stacks* fields
                continue;
            }
            $body .= "* **$name** : $value\n";
        }
        return $this->markdownify($body);
    }

    public function markdownify($text)
    {
        $converter = new CommonMarkConverter([
            'html_input'         => 'allow',
            'allow_unsafe_links' => false,
        ]);
        return $converter->convertToHtml($text);
    }

    public function returnError($msg)
    {
        $this->mail->setFrom($this->adminFrom);
        $this->mail->addAddress($this->adminTo);
        $this->mail->Subject = $this->adminSubject;
        $this->mail->Body    = $msg;
        // Send and fallback to mail() on failure
        if (!$this->mail->send()) {
            mail($this->adminTo, $this->adminSubject, $msg);
        }

        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json; charset=UTF-8');
        die(json_encode(array('message' => trim(strip_tags($msg)), 'code' => 500, 'post' => $_POST)));
    }

    public function returnSuccess($msg)
    {
        header('Content-Type: application/json');
        echo json_encode(array('message' => trim(strip_tags($msg)), 'code' => 200));
        exit();
    }

    private function decodeEntitiesFull($string, $quotes = ENT_COMPAT, $charset = 'ISO-8859-1')
    {
        $callback = preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]+);/', [$this, 'convertEntity'], $string);
        return html_entity_decode($callback, $quotes, $charset);
    }

    private function convertEntity($matches, $destroy = true)
    {
        static $table = array('quot'=>'&#34;','amp'=>'&#38;','lt'=>'&#60;','gt'=>'&#62;','OElig'=>'&#338;','oelig'=>'&#339;', 'Scaron'=>'&#352;','scaron'=>'&#353;','Yuml'=>'&#376;','circ'=>'&#710;','tilde'=>'&#732;', 'ensp'=>'&#8194;','emsp'=>'&#8195;','thinsp'=>'&#8201;','zwnj'=>'&#8204;','zwj'=>'&#8205;', 'lrm'=>'&#8206;','rlm'=>'&#8207;','ndash'=>'&#8211;','mdash'=>'&#8212;','lsquo'=>'&#8216;', 'rsquo'=>'&#8217;','sbquo'=>'&#8218;','ldquo'=>'&#8220;','rdquo'=>'&#8221;','bdquo'=>'&#8222;', 'dagger'=>'&#8224;','Dagger'=>'&#8225;','permil'=>'&#8240;','lsaquo'=>'&#8249;','rsaquo'=>'&#8250;', 'euro'=>'&#8364;','fnof'=>'&#402;','Alpha'=>'&#913;','Beta'=>'&#914;','Gamma'=>'&#915;', 'Delta'=>'&#916;','Epsilon'=>'&#917;','Zeta'=>'&#918;','Eta'=>'&#919;','Theta'=>'&#920;', 'Iota'=>'&#921;','Kappa'=>'&#922;','Lambda'=>'&#923;','Mu'=>'&#924;','Nu'=>'&#925;','Xi'=>'&#926;', 'Omicron'=>'&#927;','Pi'=>'&#928;','Rho'=>'&#929;','Sigma'=>'&#931;','Tau'=>'&#932;', 'Upsilon'=>'&#933;','Phi'=>'&#934;','Chi'=>'&#935;','Psi'=>'&#936;','Omega'=>'&#937;', 'alpha'=>'&#945;','beta'=>'&#946;','gamma'=>'&#947;','delta'=>'&#948;','epsilon'=>'&#949;', 'zeta'=>'&#950;','eta'=>'&#951;','theta'=>'&#952;','iota'=>'&#953;','kappa'=>'&#954;', 'lambda'=>'&#955;','mu'=>'&#956;','nu'=>'&#957;','xi'=>'&#958;','omicron'=>'&#959;', 'pi'=>'&#960;','rho'=>'&#961;','sigmaf'=>'&#962;','sigma'=>'&#963;','tau'=>'&#964;', 'upsilon'=>'&#965;','phi'=>'&#966;','chi'=>'&#967;','psi'=>'&#968;','omega'=>'&#969;', 'thetasym'=>'&#977;','upsih'=>'&#978;','piv'=>'&#982;','bull'=>'&#8226;','hellip'=>'&#8230;', 'prime'=>'&#8242;','Prime'=>'&#8243;','oline'=>'&#8254;','frasl'=>'&#8260;','weierp'=>'&#8472;', 'image'=>'&#8465;','real'=>'&#8476;','trade'=>'&#8482;','alefsym'=>'&#8501;','larr'=>'&#8592;', 'uarr'=>'&#8593;','rarr'=>'&#8594;','darr'=>'&#8595;','harr'=>'&#8596;','crarr'=>'&#8629;', 'lArr'=>'&#8656;','uArr'=>'&#8657;','rArr'=>'&#8658;','dArr'=>'&#8659;','hArr'=>'&#8660;','forall'=>'&#8704;','part'=>'&#8706;','exist'=>'&#8707;','empty'=>'&#8709;','nabla'=>'&#8711;','isin'=>'&#8712;','notin'=>'&#8713;','ni'=>'&#8715;','prod'=>'&#8719;','sum'=>'&#8721;','minus'=>'&#8722;','lowast'=>'&#8727;','radic'=>'&#8730;','prop'=>'&#8733;','infin'=>'&#8734;','ang'=>'&#8736;','and'=>'&#8743;','or'=>'&#8744;','cap'=>'&#8745;','cup'=>'&#8746;','int'=>'&#8747;','there4'=>'&#8756;','sim'=>'&#8764;','cong'=>'&#8773;','asymp'=>'&#8776;','ne'=>'&#8800;','equiv'=>'&#8801;','le'=>'&#8804;','ge'=>'&#8805;','sub'=>'&#8834;','sup'=>'&#8835;','nsub'=>'&#8836;','sube'=>'&#8838;','supe'=>'&#8839;','oplus'=>'&#8853;','otimes'=>'&#8855;','perp'=>'&#8869;','sdot'=>'&#8901;','lceil'=>'&#8968;','rceil'=>'&#8969;','lfloor'=>'&#8970;','rfloor'=>'&#8971;','lang'=>'&#9001;','rang'=>'&#9002;','loz'=>'&#9674;','spades'=>'&#9824;','clubs'=>'&#9827;','hearts'=>'&#9829;','diams'=>'&#9830;','nbsp'=>'&#160;','iexcl'=>'&#161;','cent'=>'&#162;','pound'=>'&#163;','curren'=>'&#164;','yen'=>'&#165;','brvbar'=>'&#166;','sect'=>'&#167;','uml'=>'&#168;','copy'=>'&#169;','ordf'=>'&#170;','laquo'=>'&#171;','not'=>'&#172;','shy'=>'&#173;','reg'=>'&#174;','macr'=>'&#175;','deg'=>'&#176;','plusmn'=>'&#177;','sup2'=>'&#178;','sup3'=>'&#179;','acute'=>'&#180;','micro'=>'&#181;','para'=>'&#182;','middot'=>'&#183;','cedil'=>'&#184;','sup1'=>'&#185;','ordm'=>'&#186;','raquo'=>'&#187;','frac14'=>'&#188;','frac12'=>'&#189;','frac34'=>'&#190;','iquest'=>'&#191;','Agrave'=>'&#192;','Aacute'=>'&#193;','Acirc'=>'&#194;','Atilde'=>'&#195;','Auml'=>'&#196;','Aring'=>'&#197;','AElig'=>'&#198;','Ccedil'=>'&#199;','Egrave'=>'&#200;','Eacute'=>'&#201;','Ecirc'=>'&#202;','Euml'=>'&#203;','Igrave'=>'&#204;','Iacute'=>'&#205;','Icirc'=>'&#206;','Iuml'=>'&#207;','ETH'=>'&#208;','Ntilde'=>'&#209;','Ograve'=>'&#210;','Oacute'=>'&#211;','Ocirc'=>'&#212;','Otilde'=>'&#213;','Ouml'=>'&#214;','times'=>'&#215;','Oslash'=>'&#216;','Ugrave'=>'&#217;','Uacute'=>'&#218;','Ucirc'=>'&#219;','Uuml'=>'&#220;','Yacute'=>'&#221;','THORN'=>'&#222;','szlig'=>'&#223;','agrave'=>'&#224;','aacute'=>'&#225;','acirc'=>'&#226;','atilde'=>'&#227;','auml'=>'&#228;','aring'=>'&#229;','aelig'=>'&#230;','ccedil'=>'&#231;','egrave'=>'&#232;','eacute'=>'&#233;','ecirc'=>'&#234;','euml'=>'&#235;','igrave'=>'&#236;','iacute'=>'&#237;','icirc'=>'&#238;','iuml'=>'&#239;','eth'=>'&#240;','ntilde'=>'&#241;','ograve'=>'&#242;','oacute'=>'&#243;','ocirc'=>'&#244;','otilde'=>'&#245;','ouml'=>'&#246;','divide'=>'&#247;','oslash'=>'&#248;','ugrave'=>'&#249;','uacute'=>'&#250;','ucirc'=>'&#251;','uuml'=>'&#252;','yacute'=>'&#253;','thorn'=>'&#254;','yuml'=>'&#255;');
        if (isset($table[$matches[1]])) {
            return $table[$matches[1]];
        }
        return $destroy ? '' : $matches[0];
    }

    private function unicodeDecode($str)
    {
        return preg_replace_callback("/[\\%]u([0-9A-F]{4})/i", function ($match) {
            return iconv('utf-16', 'utf-8', $this->hex2str($match[1]));
        }, $str);
    }

    private function hex2str($hex)
    {
        $r = '';
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $r .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }
        return $r;
    }

    public function decodeString($str)
    {
        return $this->unicodeDecode($this->decodeEntitiesFull($str, ENT_COMPAT, "utf-8"));
    }

    private function break2newline($str)
    {
        return preg_replace("/<\s*br\s*[\/]*>/i", "\r\n", $str);
    }

    private function newline2break($str)
    {
        return preg_replace("/\r*\n/", "<br>", $str);
    }

    public static function isSnuffleupagusEnabled()
    {
        return !empty(@ini_get('sp.configuration_file'));
    }
}
