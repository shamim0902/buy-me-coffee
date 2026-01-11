<?php

namespace BuyMeCoffee\Classes;

use Exception;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Vite
{
    private static $instance = null;
    private string $viteHostProtocol = 'http://';
    private string $viteHost = 'localhost';
    private string $vitePort = '3000';
    private string $resourceDirectory = 'src/';
    private array $moduleScripts = [];
    private bool $isScriptFilterAdded = false;


    public static function __callStatic($method, $params)
    {
        if (static::$instance == null) {
            static::$instance = new static();
            if (!static::isDevMode()) {
                (static::$instance)->viteManifest();
            }
        }
        return call_user_func_array(array(static::$instance, $method), $params);
    }



    /***
     * @param $handle
     * @param $src string file path relative to resource/src directory before build
     * @param array $dependency
     * @param null $version
     * @param bool $inFooter
     * @return Vite
     *
     * @throws Exception If dev mode is on and file not found in manifest
     *
     */
    private function enqueueScript($handle, $src, $dependency = [], $version = null, $inFooter = false)
    {
        (static::$instance)->moduleScripts[] = $handle;

        if (!(static::$instance)->isScriptFilterAdded) {
            add_filter('script_loader_tag', function ($tag, $handle, $src) {
                return (static::$instance)->addModuleToScript($tag, $handle, $src);
            }, 10, 3);
            (static::$instance)->isScriptFilterAdded = true;
        }

        if (!static::isDevMode()) {
            $assetFile = (static::$instance)->getFileFromManifest($src);
            $srcPath = static::getProductionFilePath($assetFile);
        } else {
            $srcPath = static::getDevPath() . $src;
        }

        wp_enqueue_script(
            $handle,
            $srcPath,
            $dependency,
            $version,
            $inFooter
        );
        return $this;
    }

    private function enqueueStyle($handle, $src, $dependency = [], $version = null)
    {
        if (!static::isDevMode()) {
            $assetFile = (static::$instance)->getFileFromManifest($src);
            $srcPath = static::getProductionFilePath($assetFile);
        } else {
            $srcPath = static::getDevPath() . $src;
        }

        wp_enqueue_style(
            $handle,
            $srcPath,
            $dependency,
            $version
        );
    }

    private function staticPath(): string
    {
        if (!static::isDevMode()) {
            return static::getAssetPath();
        }
        return static::getDevPath();
    }

    private function viteManifest()
    {
        if (!empty( (static::$instance)->manifestData) ) {
            return;
        }

        $manifestPath = realpath(__DIR__) . '/../../assets/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new Exception('Vite Manifest Not Found. Run : npm run dev or npm run prod');
        }


        if (!function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $manifestData = '';
        if (!(false === ($credentials = request_filesystem_credentials(site_url())) || !WP_Filesystem($credentials))) {
            global $wp_filesystem;
            $manifestData = $wp_filesystem->get_contents($manifestPath);
        }

        (static::$instance)->manifestData = json_decode($manifestData, true);
    }

    /**
     * @throws Exception
     */
    private function getFileFromManifest($src)
    {
        if (!isset((static::$instance)->manifestData[(static::$instance)->resourceDirectory . $src]) && static::isDevMode()) {
            throw new Exception(esc_html($src) . "file not found in vite manifest, Make sure it is in rollupOptions input and build again");
        }
        return (static::$instance)->manifestData[(static::$instance)->resourceDirectory . $src];
    }

    private function addModuleToScript($tag, $handle, $src)
    {
        if (in_array($handle, (static::$instance)->moduleScripts)) {
            return wp_get_script_tag(
                [
                    'src'  => esc_url($src),
                    'type' => 'module',
                    'id'   => $handle . '-js'
                ]
            );
        }
        return $tag;
    }

    public static function isDevMode(): bool
    {
        return defined('BUYMECOFFEE_DEVELOPMENT') && BUYMECOFFEE_DEVELOPMENT === 'yes';
    }

    private static function getDevPath(): string
    {
        return (static::$instance)->viteHostProtocol . (static::$instance)->viteHost . ':' . (static::$instance)->vitePort . '/' . (static::$instance)->resourceDirectory;
    }

    private static function getAssetPath(): string
    {
        return BUYMECOFFEE_URL . 'assets/';
    }

    private static function getProductionFilePath($file): string
    {
        $assetPath = static::getAssetPath();
        if (isset($file['css']) && is_array($file['css'])) {
            foreach ($file['css'] as $key => $path) {
                wp_enqueue_style(
                    $file['file'] . '_' . $key . '_css',
                    $assetPath . $path,
                    [],
                    BUYMECOFFEE_VERSION
                );
            }
        }
        return ($assetPath . $file['file']);
    }
}
