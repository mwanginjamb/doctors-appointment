<?php

namespace frontend\controllers;

use common\authclient\GoogleIdToken;
use common\authclient\OauthProfile;
use frontend\services\SocialSignIn;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * Google One Tap: the "Continue as …" prompt on the login and signup pages hands the page a Google
 * ID token; the page posts it here (with our CSRF token), and a valid token signs in exactly as the
 * Google button does (same account linking, see SocialSignIn).
 */
class GoogleOneTapController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['?']]],
                'denyCallback' => function () {
                    return $this->goHome();
                },
            ],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['sign-in' => ['post']]],
        ];
    }

    /**
     * The Google client ID when One Tap should be offered, else null.
     */
    public static function clientId()
    {
        if (!(Yii::$app->params['googleOneTap'] ?? true) || !Yii::$app->has('authClientCollection')) {
            return null;
        }
        $collection = Yii::$app->authClientCollection;
        if (!$collection->hasClient('google')) {
            return null;
        }

        return $collection->getClient('google')->clientId ?: null;
    }

    public function actionSignIn()
    {
        $clientId = self::clientId();
        $claims = $clientId ? GoogleIdToken::verify(Yii::$app->request->post('credential'), $clientId) : null;
        if ($claims === null) {
            Yii::$app->session->setFlash('error', 'We could not sign you in with Google. Please try the Google button, or sign in with your password.');
            return $this->redirect(['site/login']);
        }

        $error = SocialSignIn::complete('google', 'Google', OauthProfile::fromAttributes('google', $claims));
        if ($error !== null) {
            Yii::$app->session->setFlash('error', $error);
            return $this->redirect(['site/login']);
        }

        return $this->goBack();
    }
}
