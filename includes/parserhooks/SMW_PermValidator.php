<?php

class SMWPermValidator
{
    protected $output, $pages, $key;

    /**
     * @param $output ParserOutput
     * @param $pages array('namespace_id:db_key' => true|false) Readability flags for verification
     */
    function __construct($output, $pages)
    {
        $this->output = $output;
        $this->pages = $pages;
    }

    function check($pages)
    {
        $lb = new LinkBatch;
        foreach ($pages as $p => $canRead)
        {
            $p = explode(':', $p, 2);
            $lb->add($p[0], $p[1]);
        }
        $lb->execute();
        foreach ($pages as $p => $canRead)
        {
            $p = explode(':', $p, 2);
            $t = Title::makeTitle($p[0], $p[1]);
            if ($t->userCanRead() != $canRead)
            {
                return false;
            }
        }
        return true;
    }

    function __wakeup()
    {
        if (!$this->check($this->pages))
        {
            // Flush parser cache
            $this->output->mCacheExpiry = 0;
        }
    }
}
