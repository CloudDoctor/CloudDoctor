<?php

namespace CloudDoctor\Cache;

use CloudDoctor\Common\ComputeGroup;
use phpseclib\Net\SFTP;

class Cache
{
    const MAX_AGE_SECONDS = 60*60*24;
    static private $instance;
    private $cacheFile = "/cache/cache.dat";
    private $cacheData = [];

    public function __construct()
    {
        if(file_exists($this->cacheFile)){
            $this->cacheData = unserialize(file_get_contents($this->cacheFile));
        }
    }

    private function _generateKey()
    {
        $caller = debug_backtrace()[3];
        $key = implode("|", [
            $caller['class'],
            $caller['function'],
            implode(",", $caller['args'])
        ]);
        //$key = crc32($key);
        return $key;
    }

    public function _write($value) : void
    {
        $key = $this->_generateKey();
        $this->cacheData[$key] = $value;
        $this->cacheData['_ages'][$key] = microtime(true);
    }

    public function _read()
    {
        $key = $this->_generateKey();
        return $this->cacheData[$key];
    }

    public function _has()
    {
        $key = $this->_generateKey();

        return isset($this->cacheData[$key]);
    }

    public function __destruct()
    {
        $this->_save();
    }

    public function _save() : void
    {
        $this->_cleanAged();
        file_put_contents($this->cacheFile, serialize($this->cacheData));
    }

    public function _cleanAged() : void
    {
        foreach($this->cacheData as $key => $value){
            if($key != '_ages'){
                if(!isset($this->cacheData['_ages'][$key])){
                    unset($this->cacheData[$key]);
                }
                $expiry = $this->cacheData['_ages'][$key];
                $age = microtime(true) - $expiry;
                if($age > Cache::MAX_AGE_SECONDS){
                    unset($this->cacheData[$key], $this->cacheData['_ages'][$key]);
                }
            }
        }
    }

    static public function Instance() : Cache
    {
        if(!self::$instance){
            self::$instance = new self();
        }
        return self::$instance;
    }

    static public function Write($value) : void
    {
        self::Instance()->_write($value);
    }

    static public function Read()
    {
        return self::Instance()->_has() ? self::Instance()->_read() : null;
    }
}
