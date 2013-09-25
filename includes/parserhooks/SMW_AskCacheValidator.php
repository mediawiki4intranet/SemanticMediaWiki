<?php

class SWMAskCacheValidator
{
    /**
     * @var ParserOutput
     */
    protected $mOutput;

    /**
     * @var array
     */
    protected $properties;
    
    public static function update($propertyList = array())
    {
        foreach ($propertyList as $property)
        {
            $key = method_exists($property, 'getKey') ? $property->getKey() : $property;
            fclose(fopen(self::getPath($key), 'w'));
        }
    }
    
    protected static function getPath($key)
    {
        global $wgUploadDirectory;
        return $wgUploadDirectory . '/generated/' . __CLASS__ . '_' . $key;
    }
    

    public function __construct($mOutput, $time, $description)
    {
        $this->mOutput = $mOutput;
        $this->properties = array();
        foreach ($this->parseDescription($description) as $property)
        {
            $this->properties[$property] = $time;
        }
    }

    public function __wakeup()
    {
        $result = true;
        $time = time();
        foreach ($this->properties as $key => $curTime)
        {
            if (file_exists(self::getPath($key)))
            {
                $time = filemtime(self::getPath($key));
            }
            else
            {
                self::update(array($key));
            }
            if ($time > $curTime)
            {
                $this->properties[$key] = $time;
                $result = false;
            }
        }
        if (!$result)
        {
            $this->mOutput->mCacheExpiry = 0;
        }
    }
    
    protected function parseDescription($description)
    {
        $properties = array();
        if (method_exists($description, 'getProperty'))
        {
            $prop = $description->getProperty()->getKey();
            if (!isset($properties[$prop]))
            {
                $properties[$prop] = true;
            }
        }
        if (method_exists($description, 'getDescription'))
        {
            foreach ($this->parseDescription($description->getDescription()) as $prop)
            {
                if (!isset($properties[$prop]))
                {
                    $properties[$prop] = true;
                }
            }
        }
        if (method_exists($description, 'getDescriptions'))
        {
            foreach ($description->getDescriptions() as $desc)
            {
                foreach ($this->parseDescription($desc) as $prop)
                {
                    if (!isset($properties[$prop]))
                    {
                        $properties[$prop] = true;
                    }
                }
            }
        }
        return array_keys($properties);
    }
}
