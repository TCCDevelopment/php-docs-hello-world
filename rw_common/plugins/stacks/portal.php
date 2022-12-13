<?php
namespace Portal;

//---------------------------------------------------------------------------------
// Portal class
//---------------------------------------------------------------------------------
class Portal
{
    private $localAssetPath;
    private $siteAssetPath;
    private $pageDir;

    public function __construct(string $path, string $pageDir)
    {
        // __DIR__ of the Portal In page
        $this->pageDir = $pageDir;

        // Hijack the fact that RW inserts the theme css path at /head
        preg_match("/href=['\"](.*?)rw_common/", $path, $matches);
        $siteRoot = realpath($this->pageDir.'/'.$matches[1]);

        // Cannot trust $_SERVER["DOCUMENT_ROOT"] on shared hosting
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $docRoot = realpath(preg_replace("!${scriptName}$!", '', $_SERVER['SCRIPT_FILENAME']));

        // Setup the path for the local and site wide assets
        $this->localAssetPath = str_replace($docRoot, "", $this->pageDir);
        $this->siteAssetPath  = str_replace($docRoot, "", $siteRoot);
    }

    public function scriptTemplates(string $content)
    {
        // Add scripts into templates so that they can be
        // added to the bottom of the page in Portal Out
        $templateStart = "<template class=\"portal-script\">";
        $templateEnd   = "</template>";
        $content = str_replace("<script", "$templateStart<script", $content);
        $content = str_replace("script>", "script>$templateEnd", $content);
        return $content;
    }

    public function fixResourcePaths(string $content)
    {
        $content = preg_replace("/((href|poster|src|srcset|src-\w+|data-\w+)=['\"])\s*(\w*files)/", "$1$this->localAssetPath/$3", $content);
        $content = preg_replace("/(url\s*\(['\"])\s*(\w*files)/", "$1$this->localAssetPath/$2", $content);
        $content = preg_replace("/(srcset=\S+\s1x,\s*)(\w*files)/", "$1$this->localAssetPath/$2", $content);
        $content = preg_replace("/\[(\w*files)\/((small|medium|large)[-_]\w+)/", "[$this->localAssetPath/$1/$2", $content);
        return $content;
    }

    public function processContent(string $content)
    {
        if (strpos($content, '<?php') !== false) {
            ob_start();
            eval(" ?>".$content."<?php ");
            $content = ob_get_clean();
        }
        return $content;
    }

    public function processHeader(string $header)
    {
        $header = $this->portalJS($header);
        $header = $this->portalCSS($header);

        // Strip out the stacks.css and jquery files
        $header = preg_replace("/<link.+stacks\.css.+?>/", "", $header);
        $header = preg_replace("/<script.+jquery.+?\.min\.js.+?script>/", "", $header);

        // Correct the asset paths in the plugin header
        $header = preg_replace("/((href|src)=['\"]).*?rw_common/", "$1$this->siteAssetPath/rw_common", $header);
        $header = $this->fixResourcePaths($header);

        // Script to templates
        $header = $this->scriptTemplates($header);

        return $header;
    }

    private function portalCSS(string $content)
    {
        // Hack the stacks CSS page
        preg_match('/href=.(\S+stacks_page_page\d+\.css)/', $content, $matches);
        $cssPage = $matches[1] ?? false;
        if ($cssPage) {
            $filePath = $this->pageDir."/$cssPage";
            $cachekey = filemtime($filePath);
            $newCSS   = str_replace(".css", ".css?portalcache=$cachekey", $cssPage);
            $content  = str_replace($cssPage, $newCSS, $content);
        }
        return $content;
    }

    private function portalJS(string $content)
    {
        // Hack the stacks JS page
        preg_match('/src=.(\S+stacks_page_page\d+\.js)/', $content, $matches);
        $jsPage = $matches[1] ?? false;
        if ($jsPage) {
            $newJS   = $this->createPortalJS($jsPage);
            $content = str_replace($jsPage, $newJS, $content);
        }
        return $content;
    }

    private function createPortalJS(string $file)
    {
        $filePath    = $this->pageDir."/$file";
        $newFile     = str_replace(".js", ".portal.js", $file);
        $newFilePath = $this->pageDir."/$newFile";
        if (!file_exists($newFilePath) || filemtime($newFilePath) < filemtime($filePath)) {
            // Get old JS
            $code = file_get_contents($filePath);
            // Ditch jQuery Migrate
            // $code = preg_replace('/jQuery\.migrateMute===.+/', "", $code);
            // Ditch the stacks variable definition
            $code = preg_replace('/var stacks\s*=\s*\{\}\;/', "", $code);
            // Ditch the jQuery definition
            $code = preg_replace('/stacks\.jQuery\s*=\s*jQuery\.noConflict\(true\)\;/', "", $code);
            // Create jQuery reference to existing stacks jQuery at the top
            $code = "var jQuery=stacks.jQuery,$=stacks.jQuery;".$code;
            // Asset Paths
            $code = $this->fixResourcePaths($code);
            // Output new JS file
            file_put_contents($newFilePath, $code);
        }
        $cachekey = filemtime($filePath);
        $newFile  = str_replace(".js", ".js?portalcache=$cachekey", $newFile);
        return $newFile;
    }
}
