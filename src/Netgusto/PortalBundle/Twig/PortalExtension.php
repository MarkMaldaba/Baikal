<?php

namespace Netgusto\PortalBundle\Twig;

use Symfony\Bundle\FrameworkBundle\Routing\Router,
    Symfony\Component\DependencyInjection\ContainerInterface;

class PortalExtension extends \Twig_Extension {

    protected $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }
    
    public function getName() {
        return 'portal';
    }

    public function getFunctions() {
        return array(
            'application' => new \Twig_SimpleFunction('application', array($this, 'application'), array('is_safe' => array('html'))),
        );
    }

    public function application($name, array $options = array()) {
        $config = $this->container->getParameter('portal_config');

        if(!array_key_exists('applications', $config)) {
            throw new \Exception('Required an undefined application :' . $name);
        }

        $found = FALSE;
        foreach($config['applications'] as $app) {
            if($app['name'] === $name) {
                $found = TRUE;
                break;
            }
        }

        if(!$found) {
            throw new \Exception('Required an undefined application :' . $name);
        }

        switch($app['type']) {
            case 'ember-cli': {
                $content = $this->embercli($app, $options);
                break;
            }
            case 'yo-react-webpack': {
                $content = $this->yoreactwebpack($app, $options);
                break;
            }
            default: {
                throw new \Exception('Application ' . $name . ' requires an unknown application type.');
            }
        }

        return $content;
    }

    protected function embercli($app, $options = array()) {

        $sfdir = realpath($this->container->getParameter('kernel.root_dir') . '/../');
        $apppath = realpath($sfdir . '/' . $app['path']);
        $webdir = realpath($sfdir . '/web');
        $debug = $this->container->getParameter('kernel.debug');

        if($debug) {
            $url = 'http://0.0.0.0:' . (array_key_exists('port', $app) ? $app['port'] : '4200');
            $content = file_get_contents($url);
            $assetprefix = $url;
        } else {
            $content = file_get_contents($apppath . '/dist/index.html');
            $relapppath = preg_replace('%^' . preg_quote($webdir) . '%', '', $apppath);
            $assetprefix = $relapppath . '/dist';
        }

        $assetprefix = rtrim($assetprefix, '/') . '/';

        # On récupère la balise meta config/environment
        $kept = array();
        $kept[] = $this->produceConfigurationMeta($content, $options, function($config) {
            $config['locationType'] = 'hash';
            return $config;
        });

        $parts = $this->extractAppAndRewriteAssetsUrl($content, $assetprefix, $kept);
        return new PortalApplicationResponse($parts['assets'], $parts['html']);
    }

    protected function yoreactwebpack($app, $options = array()) {

        $sfdir = realpath($this->container->getParameter('kernel.root_dir') . '/../');
        $apppath = realpath($sfdir . '/' . $app['path']);
        $webdir = realpath($sfdir . '/web');
        $debug = $this->container->getParameter('kernel.debug');

        if($debug) {
            $url = 'http://0.0.0.0:' . (array_key_exists('port', $app) ? $app['port'] : '4200');
            $content = file_get_contents($url);
            $assetprefix = $url;
        } else {
            $index = $apppath . '/dist/index.html';
            if(!file_exists($index)) throw new \RuntimeException('Portal: index.html not found for ' . $app['name']);

            $content = file_get_contents($index);
            $relapppath = preg_replace('%^' . preg_quote($webdir) . '%', '', $apppath);
            $assetprefix = $relapppath . '/dist';
        }

        $assetprefix = rtrim($assetprefix, '/') . '/';

        $kept = array();
        $kept[] = $this->produceConfigurationMeta($content, $options);

        # On récupère les balises script et link, et on les réécrit
        $parts = $this->extractAppAndRewriteAssetsUrl($content, $assetprefix, $kept);
        return new PortalApplicationResponse($parts['assets'], $parts['html']);
    }

    private function produceConfigurationMeta($content, $options, $cbk = null) {
        
        if(!$cbk) { $cbk = function($config) { return $config; }; }

        $matches = array();
        if(
            preg_match('%
            <meta\s+
                name\s*=\s*
                ([\'|"])
                    (?P<name>
                        (?:(?!\1).)*?
                        config/environment
                    )
                \1
                \s+
                content\s*=\s*
                ([\'|"])
                    (?P<value>(?:(?!\2).)*?)
                \3
                [^>]*?
            >
            %smixu', $content, $matches)
        ) {
            $name = $matches['name'];
            $config = json_decode(urldecode($matches['value']), TRUE);
        } else {
            $name = 'config/environment';
            $config = array();
        }

        $config = $cbk(array_merge_recursive($config, $options));

        return '<meta name="' . $name . '" content="' . urlencode(json_encode($config)) . '" />';
    }

    private function extractAppAndRewriteAssetsUrl($content, $assetprefix, $kept=array()) {
         # On récupère les balises script et link, et on les réécrit
        $parts = array();
        preg_match('%<head>(?P<head>.*?)</head>.*?<body>(?P<body>.*?)</body>%smixu', $content, $parts);

        /*
        preg_replace_callback('%<link\s+.*?>%smix', function($match) use (&$kept) {
            $kept[] = $match[0] . '</link>';
        }, $parts['head']);

        preg_replace_callback('%<script\s+.*?>%smix', function($match) use (&$kept) {
            $kept[] = $match[0]  . '</script>';
        }, $parts['head']);
        */

        $parts['body'] = preg_replace_callback('%<script\s+.*?>%smix', function($match) use (&$kept) {
            $kept[] = $match[0]  . '</script>';
        }, $parts['body']);

        $processUrls = function($content, $assetprefix) {
            return preg_replace_callback('%(?P<attr>(src|href))\s*?=\s*?(?P<quote>\'|")(?P<value>.*?)\3%smix', function($match) use ($assetprefix) {
                if(preg_match('%^(//|https?://)%', $match['value'])) { return $match[0]; }
                return $match['attr'] . "=" . $match['quote'] . $assetprefix . ltrim($match['value'], '/') . $match['quote'];
            }, $content);
        };

        return array(
            'html' => $processUrls($parts['body'], $assetprefix),
            'assets' => $processUrls(implode("\n", $kept), $assetprefix)
        );
    }
}

class PortalApplicationResponse {

    protected $assets;
    protected $html;

    public function __construct($assets, $html) {
        $this->assets = $assets;
        $this->html = $html;
    }

    public function getHtml() { return $this->html; }
    public function getAssets() { return $this->assets; }

    public function __toString() {
        return $this->getAssets() . $this->getHtml();
    }
}