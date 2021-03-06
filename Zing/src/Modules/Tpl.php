<?php

namespace Modules;

use Exception;

class Tpl extends Module{

    protected $engines       = array();
    protected $engine        = null;
    protected $tplEngine     = null;
    protected $fileExtention = "tpl";

    public function __construct($config = array()){
        $engines = glob(__DIR__ . "/TemplateEngines/*", GLOB_ONLYDIR);
        foreach($engines as $engine){
            $this->engines[] = basename($engine);
        }

        parent::__construct($config);
    }

    /**
     * Loads a template engine
     * @param type $engine
     * @return Tpl
     */
    public function getEngine($engine){
        $engineName      = "\\Modules\\TemplateEngines\\$engine\\ZingTemplateLoader";
        $this->tplEngine = new $engineName();
        $this->engine    = $this->tplEngine->init();
        return $this;
    }

    public function setFileExtension($extention){
        $this->fileExtention = $extention;
    }

    public function getFileExtention(){
        return $this->fileExtention;
    }

    public function assign($key, $value = ""){
        if($this->tplEngine == null){
            if(!$this->setDefaultEngine()){
                throw new Exception("Template Engine Not Set");
            }
        }
        $this->tplEngine->assign($key, $value);
    }

    public function append($key, $value = ""){
        if($this->tplEngine == null){
            if(!$this->setDefaultEngine()){
                throw new Exception("Template Engine Not Set");
            }
        }
        $this->tplEngine->append($key, $value);
    }

    public function parseTpl($tpl, $data){
        if($this->tplEngine == null){
            if(!$this->setDefaultEngine()){
                throw new Exception("Template Engine Not Set");
            }
        }
        return $this->tplEngine->parseTpl($tpl, $data);
    }

    public function display($filename){
        if($this->tplEngine == null){
            if(!$this->setDefaultEngine()){
                throw new Exception("Template Engine Not Set");
            }
        }
        $this->tplEngine->render($filename);
    }

    private function setDefaultEngine(){
        if(isset($this->config["tplEngine"])){
            $this->getEngine($this->config["tplEngine"]);
            return true;
        }
        return false;
    }

}
