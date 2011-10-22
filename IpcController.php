<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2011, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * The main IPC controller provides get action
 *
 * @category   OntoWiki
 * @package    OntoWiki_extensions_components_ipc
 * @subpackage component
 */
class IpcController extends OntoWiki_Controller_Component
{
    /** @var Zend_Controller_Response_Abstract */
    protected $response = null;

    protected $urlPattern = '/^.*\.(gif|jpg|jpeg|png|GIF|JPG|JPEG|PNG)$/';

    protected $filterPattern = '/^[a-zA-Z]+(-[a-zA-Z0-9]+)*(\/[a-zA-Z]+(-[a-zA-Z0-9]+)*)*$/';


    private $cacheDirectory = null;
    private $imgFile        = null;
    private $imgUrl         = null;

    /**
     * Constructor
     */
    public function init()
    {
        // this provides many std controller vars and other stuff ...
        parent::init();
        // init controller variables
        $this->store     = $this->_owApp->erfurt->getStore();
        $this->config   = $this->_owApp->config;
        $this->response  = Zend_Controller_Front::getInstance()->getResponse();
        $this->request   = Zend_Controller_Front::getInstance()->getRequest();
    }

    /**
     * get action
     */
    public function getAction()
    {
        // this action needs no view
        $this->_helper->viewRenderer->setNoRender();

        // disable layout
        $this->_helper->layout()->disableLayout();

        // download the image and save it to a proxy file
        $this->processImgUrl();

        /*
         * from here it is reused IPC code
         */

        // load base classes and functions
        require_once('instantpicture.inc.php');
        require_once('filters/Crop.filter.php');
        require_once('filters/Palette.filter.php');
        require_once('filters/Resize.filter.php');

        // split filter string into different filter
        $filter = $this->getFilterArray();

        $cacheName   = cacheName($this->getImgFile(), $filter, 'mixed', false);
        $cacheFile   = $this->getCacheDirectory() . $cacheName;

        $preferences = array(
            'quality' => 80,
            'debug'   => 1,
            'debugtype' => 'png'
        );
        $img = new InstantPicture($preferences);
        $img->apply($this->getImgFile(), $filter);
        $img->imageSaveToFile($cacheFile);
        $img->flush($cacheFile);
    }

    /*
     * returns the filter description as an array
     */
    private function getFilterArray()
    {
        // check for filter parameter
        if (!isset($this->request->filter)) {
            // empty filter -> empty array
            return array();
        } else {
            if (preg_match ($this->filterPattern, $this->request->filter) != 1){
                throw new OntoWiki_Exception("Given filter string is not valid: $filter");
            } else {
                return explode ('/', $this->request->filter);
            }
        }
    }

    /*
     * downloads the image (or just reuses the cached file)
     */
    private function processImgUrl()
    {
        // how long to we save without even test for a new version
        if (isset($this->_privateConfig->livetime)) {
            $livetime = $this->_privateConfig->livetime;
        } else {
            $livetime = 86400; // default livetime is one day
        }
        // this is the latest timestamp we allow to exist on a saved image
        $latest = new DateTime("now");
        $latest = (int) $latest->format ("U") - $livetime;

        if (file_exists($this->getImgFile())) {
            // if cache exists and is older (lower int value) -> download
            if ($this->getImgFileModification() > $latest) {
                // do nothing
            } elseif ($this->getImgUrlModification() > $this->getImgFileModification() ) {
                $this->downloadImgUrl();
            }
            // if cache exists and is newer (higher int value) -> do nothing
        } else {
            // if no cached file exists -> download
            $this->downloadImgUrl();
        }
    }

    /*
     * the real download of the image
     */
    private function downloadImgUrl()
    {
        $curl = curl_init($this->getImgUrl());
        $file = fopen($this->getImgFile(), "wb");

        // set URL and other appropriate options
        $options = array(
            CURLOPT_FILE => $file,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_TIMEOUT => 20
        );

        curl_setopt_array($curl, $options);
        curl_exec($curl);

        curl_close($curl);
        fclose($file);
    }

    /*
     * returns the imgFile modification timestamp as int
     * http://stackoverflow.com/questions/845220/
     */
    private function getImgFileModification()
    {
        return (int) filemtime($this->getImgFile());
    }

    /*
     * returns the imgUrl modification timestamp as int (or now if not available)
     * http://stackoverflow.com/questions/845220/
     */
    private function getImgUrlModification()
    {
        $curl = curl_init($this->getImgUrl());
        // don't fetch the actual page, you only want headers
        curl_setopt($curl, CURLOPT_NOBODY, true);
        // stop it from outputting stuff to stdout
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // attempt to retrieve the modification date
        curl_setopt($curl, CURLOPT_FILETIME, true);
        // run curl
        $result = curl_exec($curl);

        if ($result === false) {
            die (curl_error($curl));
        }

        $timestamp = curl_getinfo($curl, CURLINFO_FILETIME);
        if ($timestamp != -1) { //otherwise unknown -> now
            return (int) $timestamp;
        } else {
            $timestamp = new DateTime("now");
            return (int) $timestamp->format ("U");;
        }
    }

    /*
     * returns the image cache path as string
     */
    private function getCacheDirectory()
    {
        if ($this->cacheDirectory == null) {
            if (is_writable ($this->config->cache->path)) {
                $this->cacheDirectory = $this->config->cache->path;
            } elseif (is_writable (sys_get_temp_dir())) {
                $this->cacheDirectory = sys_get_temp_dir() . '/';
            } else {
                throw new OntoWiki_Exception('No writeable cache directory available');
                exit;
            }
        }
        return $this->cacheDirectory;
    }

    /*
     * returns the downloaded "proxy image" filename as string
     */
    private function getImgFile()
    {
        if ($this->imgFile == null) {
            $this->imgFile = $this->getCacheDirectory() . 'ipc-' . md5($this->getImgUrl());
        }
        return $this->imgFile;
    }

    /*
     * returns the requested img url as string
     */
    private function getImgUrl()
    {
        if ($this->imgUrl == null) {
            // check for image url
            if (!isset($this->request->img)) {
                throw new OntoWiki_Exception('No img url given.');
                exit;
            }
            $this->imgUrl = $this->request->img;
            // check for general uri syntax
            if (!Erfurt_Uri::check($this->imgUrl)) {
                throw new OntoWiki_Exception('Given img url is not a valid url.');
                exit;
            }
            // check for specific image uri syntax
            if (preg_match ($this->urlPattern, $this->imgUrl) != 1){
                throw new OntoWiki_Exception('Given img url is not valid');
                exit;
            }
        }
        return (string) $this->imgUrl;
    }
}
