<?php

namespace frontend\tests\Helper;

use Codeception\Module;
use yii\helpers\Json;
use yii\helpers\Url;

/**
 * Functional-suite helpers for the JSON endpoints the calendar uses.
 */
class Functional extends Module
{
    /**
     * POSTs a raw JSON body with the CSRF header jQuery/yii.js would send, and returns the decoded response.
     */
    public function sendJsonPost($route, array $data)
    {
        return Json::decode($this->sendCsrfPost($route, Json::encode($data)), true);
    }

    /**
     * Follows a data-method="post" link the way yii.js would: POSTs the given
     * params plus the CSRF token from the current page's meta tag, and loads the
     * response as the current page (so seeElement/submitForm work on it).
     */
    public function postAsLink($route, array $params = [])
    {
        $yii2 = $this->getModule('Yii2');
        $csrf = $yii2->grabAttributeFrom('meta[name="csrf-token"]', 'content');
        $yii2->_loadPage('POST', Url::to($route), $params + [\Yii::$app->request->csrfParam => $csrf]);
    }

    /**
     * POSTs to a route with a valid CSRF header, as the site's own JavaScript would, and returns the raw response.
     */
    public function sendCsrfPost($route, $body = '')
    {
        $yii2 = $this->getModule('Yii2');

        $csrf = Json::decode($yii2->_request('GET', Url::to(['/fcm/csrf-token'])));

        return $yii2->_request('POST', Url::to($route), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf['token'],
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], $body);
    }
}
