<?php

class SMWAskCacheValidator
{
    /**
     * @var ParserOutput
     */
    protected $mOutput;

    /**
     * @var integer
     */
    protected $time;

    public static function update()
    {
        $cache = wfGetCache(CACHE_ANYTHING);
        $cache->set(self::getKey(), time());
    }

    protected static function getKey()
    {
        return wfMemcKey(__CLASS__);
    }

    public function __construct($mOutput, $time)
    {
        $this->mOutput = $mOutput;
        $this->time = $time;
    }

    public function __wakeup()
    {
        $cache = wfGetCache(CACHE_ANYTHING);
        $time = $cache->get(self::getKey());
        if ($time)
        {
            self::update();
            $time = $cache->get(self::getKey());
        }
        if ($time > $this->time)
        {
            $this->time = $time;
            $this->mOutput->mCacheExpiry = 0;
        }
    }
}
