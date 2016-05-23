<?php

namespace Conjecto\Arachnid;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use Elastica\Exception\Connection\GuzzleException;

/**
 * Crawler
 *
 * This class will crawl all unique internal links found on a given website
 * up to a specified maximum page depth.
 *
 * This library is based on the original blog post by Zeid Rashwani here:
 *
 * <http://zrashwani.com/simple-web-spider-php-goutte>
 *
 * Josh Lockhart adapted the original blog post's code (with permission)
 * for Composer and Packagist and updated the syntax to conform with
 * the PSR-2 coding standard.
 *
 * @package Crawler
 * @author  Josh Lockhart <https://github.com/codeguy>
 * @author  Zeid Rashwani <http://zrashwani.com>
 * @author Nawerprod
 * @version 1.0.4
 */
class SpiceCrawler
{
    /**
     * The base URL from which the crawler begins crawling
     * @var string
     */
    protected $baseUrl;

    /**
     * The path where download file
     * @var string
     */
    protected $path;

    /**
     * The max depth the crawler will crawl
     * @var int
     */
    protected $maxDepth;

    /**
     * Use only cache to read websites
     * @var int
     */
    protected $useCacheOnly;

    /**
     * The blacklist the crawler will NOT crawl
     * @var int
     */
    protected $blacklist;

    /**
     * The start time of the thread
     * @var int
     */
    protected $startTime;

    /**
     * thread is finish
     * @var bool
     */
    protected $isFinish;

    /**
     * Array of links (and related data) found by the crawler
     * @var array
     */
    protected $links;

    /**
     * Constructor
     * @param string $baseUrl
     * @param string $path
     * @param int $maxDepth
     * @param bool $useCacheOnly
     */
    public function __construct($baseUrl,$path, $maxDepth = 3, $useCacheOnly = false)
    {
        $this->baseUrl = $baseUrl;
        $this->maxDepth = $maxDepth;
        $this->useCacheOnly = $useCacheOnly;
        $this->path = $path;
        $this->links = array();
        $this->blacklist = array();
        $this->startTime = time();
        $this->isFinish = false;
    }

    /**
     * Initiate the crawl
     * @param string $url
     */
    public function traverse($url = null)
    {
        if ($url === null) {
            $url = $this->baseUrl;
            $this->links[$url] = array(
                'links_text' => array('BASE_URL'),
                'absolute_url' => $url,
                'frequency' => 1,
                'visited' => false,
                'external_link' => false,
                'original_urls' => array($url)
            );
        }

        $this->traverseSingle($url, $this->path, $this->maxDepth);
    }

    /**
     * Get links (and related data) found by the crawler
     * @return array
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * Crawl single URL
     * @param string $url
     * @param string $path
     * @param int    $depth
     */
    protected function traverseSingle($url, $path, $depth)
    {
        try {
            $currentTime = time();
            if($currentTime - $this->startTime > (60*60)){
                if(!$this->isFinish) {
                    $h = fopen($path . '/../../site_timeout.txt', 'a');
                    fwrite($h, $path . " timeout \r\n");
                    fclose($h);
                }
                $this->isFinish = true;
                return;
            }

            $url = $this->cleanUpURL($url,$path);
            $client = new Client();
            $client->followRedirects();

            if(!file_exists($path)){
                mkdir($path,0777);
                // create blacklist file
                $h = fopen($path.'_blacklist','w');
                fclose($h);
            }
            else{
                if(!file_exists($path . '_blacklist')) {
                    $h = fopen($path . '_blacklist', 'w');
                    fclose($h);
                }
                $this->blacklist = file($path.'_blacklist');
            }

            $hashurl = md5($url);
            $curpath = $path;
            $crawler = new Crawler(null, $url);

            if(file_exists($curpath.$hashurl)){
                $data = file_get_contents($curpath.$hashurl);
                $crawler->addContent($data, '');
                $statusCode = 200;
            }
            else {
                if (!$this->useCacheOnly && !filter_var($url, FILTER_VALIDATE_URL) === false) {
                    $h = fopen($path . '/../../log.txt', 'a');
                    fwrite($h, $path . " begin " . $url." => ".microtime(true)."\r\n" );
                    fclose($h);
                    try {
                        $content = @file_get_contents($url);
                    }
                    catch(\Exception $e){
                        $h = fopen($path . '/../../log.txt', 'a');
                        fwrite($h, $path . " error " . $url." => ".$e.message()." ".microtime(true) );
                        fclose($h);
                    }
                    $h = fopen($path . '/../../log.txt', 'a');
                    fwrite($h, $path . " end " . $url." => ".microtime(true)."\r\n" );
                    fclose($h);
                    $crawler->addContent($content, '');
                    $statusCode = 200;
                    /*
                                        $guzzleClient = new \GuzzleHttp\Client(array(
                                            'curl' => array(
                                                CURLOPT_TIMEOUT => 25,
                                                CURLOPT_TIMEOUT_MS => 25000,
                                                CURLOPT_CONNECTTIMEOUT => 0,
                                                CURLOPT_RETURNTRANSFER => true
                                            ),
                                        ));
                                        $client->setClient($guzzleClient);
                                        $h = fopen($path . '/../log.txt', 'a');
                                        fwrite($h, $path . " downloading " . $url . "...");
                                        $crawler = $client->request('GET', $url);
                                        fwrite($h, " done\r\n");
                                        fclose($h);*/
                    usleep(500000);
                    //      $content = $client->getResponse()->getContent();
                    if ($content) {
                        file_put_contents($curpath . $hashurl, $content);
                    }
                    //   $statusCode = $client->getResponse()->getStatus();
                }
                else{
                    $statusCode=302;
                }
            }
            $hash = $this->getPathFromUrl($url);
            $this->links[$hash]['status_code'] = $statusCode;
            $this->links[$hash]['hash'] = $hashurl;

            if ($statusCode === 200) {
                $childLinks = array();
                if (isset($this->links[$hash]['external_link']) === true && $this->links[$hash]['external_link'] === false) {
                    $childLinks = $this->extractLinksInfo($crawler, $hash, $path);
                }
                $this->links[$hash]['visited'] = true;
                $this->traverseChildren($childLinks, $hash, $path, $depth - 1);
            }
        } catch (GuzzleException $e) {
            $h = fopen($path.'/../../log.txt','a');
            fwrite($h,$path." ".$e->getcode()." ". $e->getMessage()." "."\r\n");
            fclose($h);
            $this->links[$url]['status_code'] = '404';
            $this->links[$url]['error_code'] = $e->getCode();
            $this->links[$url]['error_message'] = $e->getMessage();
        } catch (\Exception $e) {
            $h = fopen($path.'/../../log.txt','a');
            fwrite($h,$path." ".$e->getcode()." ". $e->getMessage()." ".$e->getFile()." - line ".$e->getLine()."\r\n");
            fclose($h);
            $this->links[$url]['status_code'] = '404';
            $this->links[$url]['error_code'] = $e->getCode();
            $this->links[$url]['error_message'] = $e->getMessage();
        }
    }

    /**
     * Crawl child links
     * @param array $childLinks
     * @param $parent
     * @param string $path
     * @param int $depth
     */
    protected function traverseChildren($childLinks, $parent, $path, $depth)
    {
        if ($depth === 0) {
            return;
        }

        foreach ($childLinks as $url => $info) {

            $hash = $this->getPathFromUrl($url);

            if (isset($this->links[$hash]) === false) {
                $this->links[$hash] = $info;
            } else {
                $this->links[$hash]['original_urls'] = isset($this->links[$hash]['original_urls']) ? array_merge($this->links[$hash]['original_urls'], $info['original_urls']) : $info['original_urls'];
                $this->links[$hash]['links_text'] = isset($this->links[$hash]['links_text']) ? array_merge($this->links[$hash]['links_text'], $info['links_text']) : $info['links_text'];
                if (isset($this->links[$hash]['visited']) === true && $this->links[$hash]['visited'] === true) {
                    $oldFrequency = isset($info['frequency']) ? $info['frequency'] : 0;
                    $this->links[$hash]['frequency'] = isset($this->links[$hash]['frequency']) ? $this->links[$hash]['frequency'] + $oldFrequency : 1;
                }
            }

            if (isset($this->links[$hash]['parent']) === false) {
                $this->links[$hash]['parent'] = $parent;
            }

            if (isset($this->links[$hash]['visited']) === false) {
                $this->links[$hash]['visited'] = false;
            }

            if (empty($url) === false && $this->links[$hash]['visited'] === false && isset($this->links[$hash]['dont_visit']) === false) {
                if(isset($childLinks[$hash]['external_link']) === false || $childLinks[$hash]['external_link']===false){
                    $this->traverseSingle($this->normalizeLink($childLinks[$url]['absolute_url']), $path, $depth);
                }

            }
        }
    }

    /**
     * Extract links information from url
     * @param  Crawler $crawler
     * @param  string $url
     * @param $path
     * @return array
     */
    protected function extractLinksInfo(Crawler $crawler, $url, $path)
    {
        $childLinks = array();
        $crawler->filter('a')->each(function (Crawler $node, $i) use (&$childLinks, $path) {
            $node_text = trim($node->text());
            $node_url = $node->attr('href');
            $node_url_is_crawlable = $this->checkIfCrawlable($node_url, $path);
            $hash = $this->normalizeLink($node_url);

            if (isset($this->links[$hash]) === false) {
                $childLinks[$hash]['original_urls'][$node_url] = $node_url;
                $childLinks[$hash]['links_text'][$node_text] = $node_text;

                if ($node_url_is_crawlable === true) {
                    // Ensure URL is formatted as absolute

                    if (preg_match("@^http(s)?@", $node_url) == false) {
                        if (strpos($node_url, '/') === 0) {
                            $parsed_url = parse_url($this->baseUrl);
                            $childLinks[$hash]['absolute_url'] = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $node_url;
                        } else {
                            $childLinks[$hash]['absolute_url'] = $this->baseUrl . '/' . $node_url;
                        }
                    } else {
                        $childLinks[$hash]['absolute_url'] = $node_url;
                    }



                    // Is this an external URL?
                    $childLinks[$hash]['external_link'] = $this->checkIfExternal($childLinks[$hash]['absolute_url']);

                    // Additional metadata
                    $childLinks[$hash]['visited'] = false;
                    $childLinks[$hash]['frequency'] = isset($childLinks[$hash]['frequency']) ? $childLinks[$hash]['frequency'] + 1 : 1;
                } else {
                    $childLinks[$hash]['dont_visit'] = true;
                    $childLinks[$hash]['external_link'] = false;
                }
            }
        });
        // Avoid cyclic loops with pages that link to themselves
        if (isset($childLinks[$url]) === true) {
            $childLinks[$url]['visited'] = true;
        }

        return $childLinks;
    }

    /**
     * Populate links
     * @param array $links
     * @param $config
     * @return array
     */
    public function PopulateLinks($links, $config)
    {
        foreach($links as $k => $link) {
            if (isset($link['links_text'])) {
                foreach ($link['links_text'] as $link_text) {
                    $text = strtolower($link_text);

                    foreach($config as $conf) {
                        if(!isset($links[$k]['documents'][$conf['type']])){
                            $links[$k]['documents'][$conf['type']]['ranking'] = 0;
                            $links[$k]['documents'][$conf['type']]['name'] = $conf['type'];
                        }
                        foreach ($conf['include'] as $ptrn) {
                            if (preg_match($ptrn[0], $text) == true) {
                                $links[$k]['documents'][$conf['type']]['ranking'] += $ptrn[1];
                            }
                        }
                    }
                }
            }
            if (isset($link['original_urls'])) {
                foreach ($links[$k]['original_urls'] as $original_url) {
                    foreach($config as $conf) {
                        if(!isset($links[$k]['documents'][$conf['type']])){
                            $links[$k]['documents'][$conf['type']]['ranking'] = 0;
                            $links[$k]['documents'][$conf['type']]['name'] = $conf['type'];
                        }
                        foreach ($conf['url'] as $ptrn) {
                            if (preg_match($ptrn[0], $original_url) == true) {
                                $links[$k]['documents'][$conf['type']]['ranking'] += $ptrn[1];
                            }
                        }
                    }
                }
            }
        }
        return $links;
    }
    /**
     * Extract title information from url
     * @param Crawler $crawler
     * @param string                                $url
     */
    protected function extractTitleInfo(Crawler $crawler, $url)
    {
        $this->links[$url]['title'] = trim($crawler->filterXPath('html/head/title')->text());

        $h1_count = $crawler->filter('h1')->count();
        $this->links[$url]['h1_count'] = $h1_count;
        $this->links[$url]['h1_contents'] = array();

        if ($h1_count > 0) {
            $crawler->filter('h1')->each(function (Crawler $node, $i) use ($url) {
                $this->links[$url]['h1_contents'][$i] = trim($node->text());
            });
        }
    }

    /**
     * Is a given URL crawlable?
     * @param  string $uri
     * @param $path
     * @return bool
     */
    protected function checkIfCrawlable($uri, $path)
    {
        if (empty($uri) === true) {
            return false;
        }
        $stop_links = array(
            '@^javascript\:.*$@i',
            '@^#.*@',
            '@iccal@i',
            '@demarches-en-ligne@i',
            '@demarches_en_ligne@i',
            '@droits-demarches-particuliers@i',
            '@module\=Calendrier@i',
            '@module-Calendrier@i',
            '@listevents@i',
            '@icalrepeat@i',
            '@month.calendar@i',
            '@mailto\:@i',
            '@spip.php\?page\=login@i',
            //   '@^.*\.pdf@i',
            '@^.*\.docx@i',
            '@^.*\.doc@i',
            '@^.*\.jpg@i',
            '@^.*\.gif@i',
            '@^.*\.png@i',
            '@^.*\.zip@i',
            '@^.*\.bmp@i'
        );

        foreach($this->blacklist as $bl){
            $stop_links[]= trim($bl);
        }

        foreach ($stop_links as $ptrn) {
            if (preg_match($ptrn, $uri) == true) {
                return false;
            }
        }

        return true;
    }

    /**
     * cleaning up url from session?
     * @param  string $uri
     * @param $path
     * @return string
     */
    protected function cleanUpURL($uri, $path)
    {
        if (empty($uri) === true) {
            return '';
        }

        $cleaning_links = array(
            '@PHPSESSID=[a-z0-9]+@i' => 'PHPSESSID=el4ukv0kqbvoirg7nkp4dncpk3',
            '@_tsel=[0-9]+@i' => '_tsel=1400000000'
        );

        foreach ($cleaning_links as $pattern => $replace) {
            $uri = preg_replace($pattern, $replace, $uri);
        }

        return $uri;
    }

    /**
     * Is URL external?
     * @param  string $url An absolute URL (with scheme)
     * @return bool
     */
    protected function checkIfExternal($url)
    {
        $base_url_trimmed = str_replace(array('http://', 'https://'), '', $this->baseUrl);

        return preg_match("@^http(s)?\://$base_url_trimmed@", $url) == false;
    }

    /**
     * Normalize link (remove hash, etc.)
     * @param $uri
     * @return string
     * @internal param string $url
     */
    protected function normalizeLink($uri)
    {
        return preg_replace('@#.*$@', '', $uri);
    }

    /**
     * extrating the relative path from url string
     * @param  string $url
     * @return string
     */
    protected function getPathFromUrl($url)
    {
        if (strpos($url, $this->baseUrl) === 0 && $url !== $this->baseUrl) {
            $url = str_replace($this->baseUrl,'', $url);
            if (strpos($url, '/') === 0) {
                $url = substr($url,1);
            }
            return $url;
        } else {
            if (strpos($url, '/') === 0) {
                $url = substr($url,1);
            }
            return $url;
        }
    }

}
