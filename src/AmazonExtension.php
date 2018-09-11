<?php

namespace Bolt\Extension\MichaelMezger\Amazon;

use Bolt\Extension\SimpleExtension;
use Silex\Application;
use Bolt\Extension\MichaelMezger\Amazon\Twig\Amazon;

class AmazonExtension extends SimpleExtension
{
    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        $amazon = new Amazon($this->container);

        return [
            'amazonsearch' => [[$amazon, 'getAmazonProductsByKeyword'], ['is_safe' => ['html']]],
            'amazonproduct' => [[$amazon, 'getAmazonProductByAsin'], ['is_safe' => ['html']]],
            'amazonbox' => [[$amazon, 'renderAmazonBox'], ['is_safe' => ['html'], 'needs_environment' => true]]
        ];
    }
}