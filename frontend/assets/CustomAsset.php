<?php

namespace frontend\assets;

use yii\web\AssetBundle;

/**
 * Main application asset bundle.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class CustomAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';

    public $css = [
        'css/site.css',
    ];

    public $js = [
        'Js/site.js',
        'Js/custom.js',
    ];

    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
        'yii\bootstrap5\BootstrapPluginAsset',
    ];

    public function init()
    {
        parent::init();

        // Register Google Fonts
        $this->css[] = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap';
        // material font
        $this->css[] = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined';
        // Use Bootstrap Icons for better native integration
        $this->css[] = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css';
        //Register Calendar Js
        $this->js[] = 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.15/index.global.js';
    }
}