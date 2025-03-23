<?php

/**
 * Created by PhpStorm.
 * User: HP ELITEBOOK 840 G5
 * Date: 3/10/2020
 * Time: 2:27 PM
 */



namespace common\Library;

use yii;
use yii\base\Component;
use yii\helpers\FileHelper;


class UtilityComponent extends Component
{
    public function absoluteUrl()
    {
        return \yii\helpers\Url::home(true);
    }


    public function printrr($var)
    {
        print '<pre>';
        print_r($var);
        print '<br>';
        exit('It\'s Gumzo Systems');
    }

    function currentCtrl($ctrl)
    {
        $controller = Yii::$app->controller->id;

        if (is_array($ctrl) && in_array($controller, $ctrl)) {
            return true;
        } else if ($controller == $ctrl) {
            return true;
        } else {
            return false;
        }
    }

    public function currentaction($ctrl, $actn)
    { //modify it to accept an array of controllers as an argument--> later please
        $controller = Yii::$app->controller->id;
        $action = Yii::$app->controller->action->id;

        if ($controller == $ctrl && is_array($actn) && in_array($action, $actn)) {
            return true;
        } else if (is_array($ctrl) && in_array($controller, $ctrl)) {
            return true;
        } else if ($controller == $ctrl && $action == $actn) {
            return true;
        } else {
            return false;
        }
    }

    public function log($message, $name = null)
    {
        $message = print_r($message, true);
        if ($name) {
            $filename = 'log/' . $this->processPath($name) . '.log';
        } else {
            $filename = 'log/file.log';
        }
        $req_dump = print_r($message, TRUE);
        $fp = fopen($filename, 'a');
        fwrite($fp, $req_dump);
        fclose($fp);
    }

    public function processPath($path)
    {
        $trim = trim($path);
        $dashed = str_replace(' ', '_', $trim);
        $sanitized = str_replace('.', '', $dashed);
        $decommad = str_replace(',', '', $sanitized);
        $sanitized_path = str_replace("'", '', $decommad);
        return strtolower(mb_substr($sanitized_path, 0, 10));
    }

    public function createDir($targetPath)
    {
        if (!is_dir(dirname($targetPath))) {
            FileHelper::createDirectory(dirname($targetPath));
            chmod(dirname($targetPath), 0755);
        }
    }

    public function webroot()
    {
        return Yii::getAlias(@$webroot);
    }

}