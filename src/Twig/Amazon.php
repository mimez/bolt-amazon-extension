<?php

namespace Bolt\Extension\MichaelMezger\Amazon\Twig;

use ApaiIO\Configuration\GenericConfiguration;
use ApaiIO\Operations\Lookup;
use ApaiIO\Operations\Search;
use ApaiIO\ApaiIO;
use Silex\Application;

class Amazon
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var ApaiIO
     */
    protected $amazonEndpoint;

    /**
     * @var array
     */
    protected $config;

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return Application
     */
    public function getApp()
    {
        return $this->app;
    }

    /**
     * @param Application $app
     */
    public function setApp($app)
    {
        $this->app = $app;
    }

    public function __construct(Application $app, array $config)
    {
        $this->setApp($app);
        $this->setConfig($config);
    }

    protected function getAmazonEndpoint() {

        if (!isset($this->amazonEndpoint)) {
            $conf = new GenericConfiguration();
            $conf
                ->setCountry($this->getConfig()['country'])
                ->setAccessKey($this->getConfig()['access_key'])
                ->setSecretKey($this->getConfig()['secret'])
                ->setAssociateTag($this->getConfig()['associate_tag']);
            $this->amazonEndpoint = new ApaiIO($conf);
        }

        return $this->amazonEndpoint;

    }

    public function getAmazonProductsByKeyword($keyword, $options = array()) {

        $cacheKey = 'search.' . $keyword . json_encode($options);

        if (!($amazonProducts = $this->getAmazonResultsFromCache($cacheKey))) {
            $apaiIO = $this->getAmazonEndpoint();

            $search = new Search();
            $search->setCategory('All');
            $search->setKeywords($keyword);
            $search->setResponsegroup(array('Large', 'Images'));

            $formattedResponse = $apaiIO->runOperation($search);
            $xml = simplexml_load_string($formattedResponse);

            // teste auf fehler
            if (count($xml->Items->Request->Errors) || !strlen((string)$xml->Items->Item->DetailPageURL)) {
                return false;
            }

            $amazonProducts = array();
            foreach ($xml->Items->Item as $item) {
                $amazonProducts[] = array(
                    'url' => (string)$item->DetailPageURL,
                    'image_url' => (string)$item->LargeImage->URL,
                    'title' => (string)$item->ItemAttributes->Title,
                    'price' => (string)$item->OfferSummary->LowestNewPrice->FormattedPrice,
                );
            }

            if (isset($options['limit'])) {
                $amazonProducts = array_slice($amazonProducts, 0, (int)$options['limit']);
            }

            $this->cacheAmazonResults($cacheKey, $amazonProducts);
        }

        return $amazonProducts;
    }

    public function getAmazonProductByAsin($asin) {

        if (!($amazonProduct = $this->getAmazonResultsFromCache($asin))) {
            $conf = new GenericConfiguration();
            $conf
                ->setCountry('de')
                ->setAccessKey('AKIAJYGLXULKUUDGFOQA')
                ->setSecretKey('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')
                ->setAssociateTag('geldgeschenke-de-21');

            $apaiIO = new ApaiIO($conf);
            $search = new Lookup();
            $search->setResponseGroup(array('Large', 'Images'));
            $search->setItemId($asin);
            $formattedResponse = $apaiIO->runOperation($search);
            $xml = simplexml_load_string($formattedResponse);

            // teste auf fehler
            if (count($xml->Items->Request->Errors) || !strlen((string)$xml->Items->Item->DetailPageURL)) {
                return false;
            }

            $amazonProduct = array(
                'url' => (string)$xml->Items->Item->DetailPageURL,
                'image_url' => (string)$xml->Items->Item->LargeImage->URL,
                'title' => (string)$xml->Items->Item->ItemAttributes->Title,
                'price' => (string)$xml->Items->Item->OfferSummary->LowestNewPrice->FormattedPrice,
            );

            $this->cacheAmazonResults('asin.' . $asin, $amazonProduct);

            $amazonProduct = (array)$this->getAmazonResultsFromCache('asin.' . $asin);
        }

        return $amazonProduct;
    }

    protected function cacheAmazonResults($key, $value) {
        $cacheFile = $this->getAmazonCachePath() . md5($key) . '.json';
        file_put_contents($cacheFile, json_encode($value));

        return true;
    }

    protected function getAmazonResultsFromCache($key) {
        $cacheFile = $this->getAmazonCachePath() . md5($key) . '.json';

        // existiert die Datei?
        if (!file_exists($cacheFile)) {
            return false;
        }

        // cache invalidierung = 1 Tag
        if (time() - filemtime($cacheFile) > 60*60*24) {
            unlink($cacheFile);
            return false;
        }

        return json_decode(file_get_contents($cacheFile));
    }

    protected function getAmazonCachePath() {
        $paths = $this->app['resources']->getPaths();
        $cachepath = $paths['cachepath'] . '/amazon/';
        if (!file_exists($cachepath)) {
            $foo = mkdir($cachepath);
            if (!$foo) {
                throw new \Exception('creating amazon cache directory failed');
            }
        }

        return $cachepath;
    }

    public function renderAmazonBox(\Twig_Environment $env, $jsonString) {
        $options = (array)json_decode($jsonString);

        switch($options['type']) {
            case 'search':
                $options['products'] = $this->getAmazonProductsByKeyword($options['keywords'], $options);
                break;

            case 'products':
                foreach ($options['products'] as $key => $asin) {
                    $options['products'][$key] = $this->getAmazonProductByAsin($asin);
                }
                break;

            default:
                return false;
        }


        return $env->render('templates/helper/amazonbox.twig', $options);
    }

    public function amazonify(\Twig_Environment $env, $string)
    {

        $string = new \Twig_Markup(preg_replace_callback('#amazonbox\((.*)\)#Ui', function($matches) use ($env) {
            return $this->renderAmazonBox($env, $matches[1]);
        }, $string), 'utf-8');

        return $string;
    }
}