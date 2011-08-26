<?php

define('PIECRUST_BAKE_DIR', '_counter');
define('PIECRUST_BAKE_INDEX_DOCUMENT', 'index.html');
define('PIECRUST_BAKE_INFO_FILE', 'bakeinfo.json');

require_once 'PieCrust.class.php';
require_once 'Paginator.class.php';
require_once 'PageBaker.class.php';
require_once 'UriBuilder.class.php';
require_once 'FileSystem.class.php';
require_once 'BakeRecord.class.php';
require_once 'LinkCollector.class.php';
require_once 'DirectoryBaker.class.php';
require_once 'Combinatorics.inc.php';


/**
 * A class that 'bakes' a PieCrust website into a bunch of static HTML files.
 */
class PieCrustBaker
{
    protected $bakeRecord;
    
    protected $pieCrust;
    /**
     * Get the app hosted in the baker.
     */
    public function getApp()
    {
        return $this->pieCrust;
    }
    
    protected $parameters;
    /**
     * Gets the baking parameters.
     */
    public function getParameters()
    {
        return $this->parameters;
    }
    
    /**
     * Get a baking parameter's value.
     */
    public function getParameterValue($key)
    {
        return $this->parameters[$key];
    }
    
    /**
     * Sets a baking parameter's value.
     */
    public function setParameterValue($key, $value)
    {
        $this->parameters[$key] = $value;
    }
    
    protected $bakeDir;
    /**
     * Gets the bake (output) directory.
     */
    public function getBakeDir()
    {
        if ($this->bakeDir === null)
        {
            $defaultBakeDir = $this->pieCrust->getRootDir() . PIECRUST_BAKE_DIR;
            $this->setBakeDir($defaultBakeDir);
        }
        return $this->bakeDir;
    }
    
    /**
     * Sets the bake (output) directory.
     */
    public function setBakeDir($dir)
    {
        $this->bakeDir = rtrim(realpath($dir), '/\\') . DIRECTORY_SEPARATOR;
        if (is_writable($this->bakeDir) === false)
        {
            try
            {
                if (!is_dir($this->bakeDir))
                {
                    @mkdir($dir, 0777, true);
                }
                else
                {
                    @chmod($this->bakeDir, 0777);
                }
            }
            catch (Exception $e)
            {
                throw new PieCrustException('The bake directory must exist and be writable, and we can\'t create it or change the permissions ourselves: ' . $this->bakeDir);
            }
        }
    }
    
    /**
     * Creates a new instance of the PieCrustBaker.
     */
    public function __construct(array $appParameters = array(), array $bakerParameters = array())
    {
        $this->pieCrust = new PieCrust($appParameters);
        $this->pieCrust->setConfigValue('baker', 'is_baking', false);
        
        $bakerParametersFromApp = $this->pieCrust->getConfig('baker');
        if ($bakerParametersFromApp == null)
            $bakerParametersFromApp = array();
        $this->parameters = array_merge(array(
                                            'show_banner' => true,
                                            'smart' => true,
                                            'clean_cache' => false,
                                            'copy_assets' => true,
                                            'processors' => '*',
                                            'skip_patterns' => array('/^_/'),
                                            'tag_combinations' => array()
                                        ),
                                        $bakerParametersFromApp,
                                        $bakerParameters);
        
        // Validate and explode the tag combinations.
        $combinations = $this->parameters['tag_combinations'];
        if ($combinations)
        {
            if (!is_array($combinations))
                $combinations = array($combinations);
            $combinationsExploded = array();
            foreach ($combinations as $comb)
            {
                $combExploded = explode('/', $comb);
                if (count($combExploded) > 1)
                    $combinationsExploded[] = $combExploded;
            }
            $this->parameters['tag_combinations'] = $combinationsExploded;
        }
        
        // Validate skip patterns.
        if (!is_array($this->parameters['skip_patterns']))
        {
            $this->parameters['skip_patterns'] = array($this->parameters['skip_patterns']);
        }
        // Convert glob patterns to regex patterns.
        for ($i = 0; $i < count($this->parameters['skip_patterns']); ++$i)
        {
            $pattern = $this->parameters['skip_patterns'][$i];
            $pattern = PieCrustBaker::globToRegex($pattern);
            $this->parameters['skip_patterns'][$i] = $pattern;
        }
        // Add the default system skip pattern.
        $this->parameters['skip_patterns'][] = '/(\.DS_Store)|(Thumbs.db)|(\.git)|(\.hg)|(\.svn)/';
    }
    
    /**
     * Bakes the website.
     */
    public function bake()
    {
        $overallStart = microtime(true);
        
        if ($this->parameters['show_banner'])
        {
            echo "PieCrust Baker v." . PieCrust::VERSION . PHP_EOL . PHP_EOL;
            echo "  baking  :  " . $this->pieCrust->getRootDir() . PHP_EOL;
            echo "  into    :  " . $this->getBakeDir() . PHP_EOL;
            echo "  for url :  " . $this->pieCrust->getUrlBase() . PHP_EOL;
            echo PHP_EOL . PHP_EOL;
        }
        
        // Setup the PieCrust environment.
        LinkCollector::enable();
        $this->pieCrust->setConfigValue('baker', 'is_baking', true);
        
        // Create the bake record.
        $bakeInfoPath = $this->getBakeDir() . PIECRUST_BAKE_INFO_FILE;
        $this->bakeRecord = new BakeRecord($this->pieCrust, $bakeInfoPath);
        
        // Get the cache validity information.
        $cacheInfo = new PieCrustCacheInfo($this->pieCrust);
        $cacheValidity = $cacheInfo->getValidity(false);
        
        // Figure out if we need to clean the cache.
        $cleanCache = $this->parameters['clean_cache'];
        $cleanCacheReason = "ordered to";
        if (!$cleanCache)
        {
            if (!$cacheValidity['is_valid'])
            {
                $cleanCache = true;
                $cleanCacheReason = "not valid anymore";
            }
        }
        if (!$cleanCache)
        {
            if ($this->bakeRecord->shouldDoFullBake())
            {
                $cleanCache = true;
                $cleanCacheReason = "need bake info regen";
            }
        }
        // If any template file changed since last time, we also need to re-bake everything
        // (there's no way to know what weird conditional template inheritance/inclusion
        //  could be in use...).
        if (!$cleanCache)
        {
            $maxMTime = 0;
            foreach ($this->pieCrust->getTemplatesDirs() as $dir)
            {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $path)
                {
                    if ($path->isFile())
                    {
                        $maxMTime = max($maxMTime, $path->getMTime());
                    }
                }
            }
            if ($maxMTime >= $this->bakeRecord->getLast('time'))
            {
                $cleanCache = true;
                $cleanCacheReason = "templates modified";
            }
        }
        if ($cleanCache)
        {
            $start = microtime(true);
            FileSystem::deleteDirectoryContents($this->pieCrust->getCacheDir());
            file_put_contents($cacheValidity['path'], $cacheValidity['hash']);
            echo self::formatTimed($start, 'cleaned cache (reason: ' . $cleanCacheReason . ')') . PHP_EOL . PHP_EOL;
            
            $this->parameters['smart'] = false;
        }
        
        // Bake!
        $this->bakePosts();
        $this->bakePages();
        $this->bakeRecord->collectTagCombinations();
        $this->bakeTags();
        $this->bakeCategories();
        
        $dirBaker = new DirectoryBaker($this->pieCrust,
                                       $this->getBakeDir(),
                                       array(
                                            'skip_patterns' => $this->parameters['skip_patterns'],
                                            'processors' => $this->parameters['processors']
                                            )
                                       );
        $dirBaker->bake();
        
        // Save the bake record and clean up.
        $this->bakeRecord->saveBakeInfo($bakeInfoPath);
        $this->bakeRecord = null;
        
        $this->pieCrust->setConfigValue('baker', 'is_baking', false);
        LinkCollector::disable();
        
        if ($this->parameters['show_banner'])
        {
            echo PHP_EOL;
            echo self::formatTimed($overallStart, 'done baking') . PHP_EOL;
        }
    }
    
    protected function bakePages()
    {
        if ($this->bakeRecord == null) throw new PieCrustException("Can't bake pages without a bake-record active.");
        
        $pagesDir = $this->pieCrust->getPagesDir();
        $directory = new RecursiveDirectoryIterator($pagesDir);
        $iterator = new RecursiveIteratorIterator($directory);
        foreach ($iterator as $path)
        {
            if ($iterator->isDot()) continue;
            $this->bakePage($path->getPathname());
        }
    }
    
    protected function bakePage($path)
    {
        $path = realpath($path);
        $pagesDir = $this->pieCrust->getPagesDir();
        $relativePath = str_replace('\\', '/', substr($path, strlen($pagesDir)));
        $relativePathInfo = pathinfo($relativePath);
        if ($relativePathInfo['filename'] == PIECRUST_CATEGORY_PAGE_NAME or
            $relativePathInfo['filename'] == PIECRUST_TAG_PAGE_NAME or
            $relativePathInfo['extension'] != 'html')
        {
            return false;
        }

        // Don't bake this file if it is up-to-date and is not using any posts (if any was rebaked).
        if (!$this->shouldRebakeFile($path) and 
                (!$this->bakeRecord->wasAnyPostBaked() or 
                 !$this->bakeRecord->isPageUsingPosts($relativePath))
           )
        {
            return false;
        }
        
        $start = microtime(true);
        $page = PageRepository::getOrCreatePage(
                $this->pieCrust,
                Page::buildUri($relativePath),
                $path
            );
        $baker = new PageBaker($this->pieCrust, $this->getBakeDir(), $this->getPageBakerParameters());
        $baker->bake($page);
        if ($baker->wasPaginationDataAccessed())
        {
            $this->bakeRecord->addPageUsingPosts($relativePath);
        }
        
        echo self::formatTimed($start, $relativePath) . PHP_EOL;
        return true;
    }
    
    protected function bakePosts()
    {
        if (!$this->hasPosts()) return;
        if ($this->bakeRecord == null) throw new PieCrustException("Can't bake posts without a bake-record active.");
        
        $blogKeys = $this->pieCrust->getConfigValueUnchecked('site', 'blogs');
        foreach ($blogKeys as $blogKey)
        {
            $fs = FileSystem::create($this->pieCrust, $blogKey);
            $postInfos = $fs->getPostFiles();
            
            $postUrlFormat = $this->pieCrust->getConfigValue($blogKey, 'post_url');
            foreach ($postInfos as $postInfo)
            {
                $uri = UriBuilder::buildPostUri($postUrlFormat, $postInfo);
                $page = PageRepository::getOrCreatePage(
                    $this->pieCrust,
                    $uri,
                    $postInfo['path'],
                    PIECRUST_PAGE_POST,
                    $blogKey
                );
                $page->setDate($postInfo);
                
                $pageWasBaked = false;
                if ($this->shouldRebakeFile($postInfo['path']))
                {
                    $start = microtime(true);
                    $baker = new PageBaker($this->pieCrust, $this->getBakeDir(), $this->getPageBakerParameters());
                    $baker->bake($page);
                    $pageWasBaked = true;
                    $hasBaked = true;
                    echo self::formatTimed($start, $postInfo['name']) . PHP_EOL;
                }
                
                $postInfo['blogKey'] = $blogKey;
                $postInfo['tags'] = $page->getConfigValue('tags');
                $postInfo['category'] = $page->getConfigValue('category');
                $this->bakeRecord->addPostInfo($postInfo, $pageWasBaked);
            }
        }
    }
    
    protected function bakeTags()
    {
        if (!$this->hasPosts()) return;
        if ($this->bakeRecord == null) throw new PieCrustException("Can't bake tags without a bake-record active.");
        
        $blogKeys = $this->pieCrust->getConfigValueUnchecked('site', 'blogs');
        foreach ($blogKeys as $blogKey)
        {
            $prefix = '';
            if ($blogKey != PIECRUST_DEFAULT_BLOG_KEY)
                $prefix = $blogKey . DIRECTORY_SEPARATOR;
            
            $tagPagePath = $this->pieCrust->getPagesDir() . $prefix . PIECRUST_TAG_PAGE_NAME . '.html';
            if (!is_file($tagPagePath)) return;
            
            // Get single and multi tags to bake.
            $tagsToBake = $this->bakeRecord->getTagsToBake($blogKey);
            $combinations = $this->parameters['tag_combinations'];
            if ($blogKey != PIECRUST_DEFAULT_BLOG_KEY)
            {
                if (array_key_exists($blogKey, $combinations))
                    $combinations = $combinations[$blogKey];
                else
                    $combinations = array();
            }
            $lastKnownCombinations = $this->bakeRecord->getLast('knownTagCombinations');
            if (array_key_exists($blogKey, $lastKnownCombinations))
            {
                $combinations = array_merge($combinations, $lastKnownCombinations[$blogKey]);
                $combinations = array_unique($combinations);
            }
            if (count($combinations) > 0)
            {
                // Filter combinations that contain tags that got invalidated.
                $combinationsToBake = array();
                foreach ($combinations as $comb)
                {
                    $explodedComb = explode('/', $comb);
                    if (count(array_intersect($explodedComb, $tagsToBake)) > 0)
                        $combinationsToBake[] = $explodedComb;
                }
                $tagsToBake = array_merge($combinationsToBake, $tagsToBake);
            }
            
            // Bake!
            foreach ($tagsToBake as $tag)
            {
                $start = microtime(true);
                
                $formattedTag = $tag;
                if (is_array($tag)) $formattedTag = implode('+', $tag);
                
                $postInfos = $this->bakeRecord->getPostsTagged($blogKey, $tag);
                if (count($postInfos) > 0)
                {
                    $uri = UriBuilder::buildTagUri($this->pieCrust->getConfigValue($blogKey, 'tag_url'), $tag);
                    $page = PageRepository::getOrCreatePage(
                        $this->pieCrust,
                        $uri,
                        $tagPagePath,
                        PIECRUST_PAGE_TAG,
                        $blogKey,
                        $tag
                    );
                    $baker = new PageBaker($this->pieCrust, $this->getBakeDir(), $this->getPageBakerParameters());
                    $baker->bake($page, $postInfos);
                    echo self::formatTimed($start, $formattedTag . ' (' . count($postInfos) . ' posts, '. sprintf('%.1f', (microtime(true) - $start) * 1000.0 / $baker->getPageCount()) .' ms/page)') . PHP_EOL;
                }
            }
        }
    }
    
    protected function bakeCategories()
    {
        if (!$this->hasPosts()) return;
        if ($this->bakeRecord == null) throw new PieCrustException("Can't bake categories without a bake-record active.");
        
        $blogKeys = $this->pieCrust->getConfigValueUnchecked('site', 'blogs');
        foreach ($blogKeys as $blogKey)
        {
            $prefix = '';
            if ($blogKey != PIECRUST_DEFAULT_BLOG_KEY)
                $prefix = $blogKey . DIRECTORY_SEPARATOR;
                
            $categoryPagePath = $this->pieCrust->getPagesDir() . $blogKey . PIECRUST_CATEGORY_PAGE_NAME . '.html';
            if (!is_file($categoryPagePath)) return;
            
            foreach ($this->bakeRecord->getCategoriesToBake($blogKey) as $category)
            {
                $start = microtime(true);
                $postInfos = $this->getPostsInCategory($blogKey, $category);
                $uri = UriBuilder::buildCategoryUri($this->pieCrust->getConfigValue($blogKey, 'category_url'), $category);
                $page = PageRepository::getOrCreatePage(
                    $this->pieCrust, 
                    $uri, 
                    $categoryPagePath,
                    PIECRUST_PAGE_CATEGORY,
                    $blogKey,
                    $category
                );
                $baker = new PageBaker($this->pieCrust, $this->getBakeDir(), $this->getPageBakerParameters());
                $baker->bake($page, $postInfos);
                echo self::formatTimed($start, $category . ' (' . count($postInfos) . ' posts, '. sprintf('%.1f', (microtime(true) - $start) * 1000.0 / $baker->getPageCount()) .' ms/page)') . PHP_EOL;
            }
        }
    }
    
    protected function hasPosts()
    {
        try
        {
            $dir = $this->pieCrust->getPostsDir();
            return true;
        }
        catch (Exception $e)
        {
            return false;
        }
    }
    
    protected function shouldRebakeFile($path)
    {
        if ($this->parameters['smart'])
        {
            if (filemtime($path) < $this->bakeRecord->getLast('time'))
            {
                return false;
            }
        }
        return true;
    }
    
    protected function getPageBakerParameters()
    {
        return array('copy_assets' => $this->parameters['copy_assets']);
    }
    
    public static function formatTimed($startTime, $message)
    {
        $endTime = microtime(true);
        return sprintf('[%8.1f ms] ', ($endTime - $startTime)*1000.0) . $message;
    }
    
    public static function globToRegex($pattern)
    {
        if (substr($pattern, 0, 1) == "/" and
            substr($pattern, -1) == "/")
        {
            // Already a regex.
            return $pattern;
        }
        
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\\*', '.*', $pattern);
        $pattern = str_replace('\\?', '.', $pattern);
        return '/'.$pattern.'/';
    }
}
