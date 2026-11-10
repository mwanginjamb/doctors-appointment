<?php

namespace frontend\components;

use common\models\Consent;
use Yii;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Behavior;
use yii\web\ForbiddenHttpException;

/**
 * Keeps a signed-in patient on the consent page until they have agreed to the current privacy
 * notice (F11): patients who signed up with Google/Microsoft/LinkedIn, existing patients, and
 * everyone after the notice changes.
 *
 * Attached to the application in frontend/config/main.php. Staff are not affected; neither are pages
 * opened from a link without signing in (booking links, the self-service link).
 */
class ConsentGate extends Behavior
{
    /** Routes a patient without consent can still open: route prefixes (controller/) or exact routes. */
    public $allow = [
        'consent/',
        'site/privacy',
        'site/logout',
        'site/error',
        'book/',
        'manage/',
        'debug/',
        'gii/',
    ];

    public function events()
    {
        return [Application::EVENT_BEFORE_ACTION => 'check'];
    }

    public function check(ActionEvent $event)
    {
        $user = Yii::$app->user;
        if ($user->isGuest || !Consent::isEnabled()) {
            return;
        }
        $identity = $user->identity;
        if (!$identity->isPatientAccount() || Consent::hasCurrentDataConsent($identity->id)) {
            return;
        }

        $route = $event->action->getUniqueId();
        foreach ($this->allow as $allowed) {
            if ($route === $allowed || (substr($allowed, -1) === '/' && strpos($route, $allowed) === 0)) {
                return;
            }
        }

        $event->isValid = false;
        $request = Yii::$app->request;
        if ($request->isAjax || strpos($route, 'api/') === 0) {
            throw new ForbiddenHttpException(Yii::t('app', 'Please agree to the privacy notice first.'));
        }
        if ($request->isGet) {
            $user->setReturnUrl($request->url);
        }
        Yii::$app->response->redirect(['/consent/index']);
    }
}
