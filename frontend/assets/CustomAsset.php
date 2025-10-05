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
        'Js/custom.js'
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
        // use select2 css
        $this->css[] = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
        // use data tables css
        $this->css[] = 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css';



        //Register Calendar Js
        $this->js[] = 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.15/index.global.js';
        // Register select 2
        $this->js[] = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
        // register data tables js
        $this->js[] = 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js';

        $this->js[] = 'Js/firebase-messaging.js';

        $this->jsOptions['position'] = \yii\web\View::POS_END;
    }


}