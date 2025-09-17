<?php

namespace frontend\assets;

use yii\web\AssetBundle;

/**
 * Admin Asset Bundle for HealthAdmin interface
 */
class AdminAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';

    public $css = [
        'css/admin.css',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined',
    ];

    public $js = [
        //'js/admin.js',
    ];

    public $depends = [
        'yii\bootstrap5\BootstrapAsset',
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapPluginAsset',
    ];
}