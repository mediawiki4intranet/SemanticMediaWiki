<?php

/**
 * Cache validator for printing correct query results based on per-page user permissions.
 * The object is saved into ParserOutput and re-checks permissions when it is unserialized.
 * If cached permission check results aren't equal to actual ones, the ParserOutput is flushed.
 */

class SMWPermValidator
{
    protected $output, $pages, $key;

    /**
     * @param $output ParserOutput
     * @param $pages array('namespace_id:db_key' => true|false) Readability flags for verification
     */
    function __construct($pageid, $output, $pages)
    {
        $this->pageid = $pageid;
        $this->output = $output;
        $this->pages = $pages;
        // Save permission dependencies for article
        $cache = wfGetCache(CACHE_ANYTHING);
        $key = wfMemcKey('smw-perms', $pageid);
        $deps = $cache->get($key);
        $deps = $deps ? array_merge($deps, $pages) : $pages;
        $cache->set($key, $deps);
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
            if ($t->userCan('read') != $canRead)
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
