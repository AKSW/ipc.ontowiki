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

    private   $preferences = array(
        'quality'   => 80,
        'debug'     => 1,
        'debugtype' => 'png'
    );

    /**
     * Constructor
     */
    public function init()
    {
        // this provides many std controller vars and other stuff ...
        parent::init();
        // init controller variables
        $this->store     = $this->_owApp->erfurt->getStore();
        $this->_config   = $this->_owApp->config;
        $this->response  = Zend_Controller_Front::getInstance()->getResponse();
        $this->request   = Zend_Controller_Front::getInstance()->getRequest();
        $this->cachePath = $this->_config->cache->path;
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
        // check for filter parameter
        $filter = (!isset($this->request->filter)) ? '' : $this->request->filter;

        //var_dump(preg_match ($this->filterPattern, $filter), $filter); exit;
        if (preg_match ($this->filterPattern, $filter) != 1){
            throw new OntoWiki_Exception("Given filter string is not valid: $filter");
        }
        // split filter string into different filter
        $filter = explode ('/', $filter);

        // download image and save to
        $this->downloadImage();

        // load base classes and functions
        require_once('instantpicture.inc.php');
        require_once('filters/Crop.filter.php');
        require_once('filters/Palette.filter.php');
        require_once('filters/Resize.filter.php');

        $cacheName   = cacheName($this->picture, $filter, 'mixed', false);
        $cacheFile   = $this->cachePath . $cacheName;

        $img = new InstantPicture($this->preferences);
        $img->apply($this->picture, $filter);
        $img->imageSaveToFile($cacheFile);
        $img->flush($cacheFile);
    }

    private function downloadImage()
    {
        $this->picture = '/tmp/ttt.png';
    }

}
