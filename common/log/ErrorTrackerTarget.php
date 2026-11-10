<?php

namespace common\log;

use Sentry\Event;
use Sentry\Severity;
use Sentry\State\Scope;
use Yii;
use yii\log\Logger;
use yii\log\Target;

/**
 * Sends errors to a Sentry-compatible server (self-hosted GlitchTip, T2): uncaught exceptions and
 * Yii::error() calls from the website, console commands and the queue worker. Errors are grouped
 * there, with stack traces, the environment and the release.
 *
 * Off unless SENTRY_DSN is set. Personal data never leaves the server: see scrub().
 */
class ErrorTrackerTarget extends Target
{
    /** @var string[] */
    public $levels = ['error'];

    /** @var string[] noisy categories that are not errors worth tracking */
    public $except = ['yii\web\HttpException:4*'];

    /** @var bool whether the SDK was initialised in this process */
    private static $started = false;

    public function init()
    {
        parent::init();
        $dsn = (string) (Yii::$app->params['monitoring.dsn'] ?? '');
        if ($dsn === '') {
            $this->enabled = false;
            return;
        }
        if (!self::$started) {
            \Sentry\init(self::options($dsn));
            self::$started = true;
        }
        // Send each error as it happens: a crash later in the request must not lose it
        $this->exportInterval = 1;
    }

    /**
     * SDK settings. No automatic integrations: Yii's error handler already logs every uncaught
     * exception, so they reach export() once. Short timeouts so a slow tracker never slows pages.
     */
    public static function options($dsn)
    {
        $params = Yii::$app->params;
        return [
            'dsn' => $dsn,
            'environment' => (string) ($params['monitoring.environment'] ?? 'prod'),
            'release' => ((string) ($params['monitoring.release'] ?? '')) ?: null,
            'default_integrations' => false,
            'send_default_pii' => false,
            'max_request_body_size' => 'none',
            'http_timeout' => 2,
            'http_connect_timeout' => 1,
            'before_send' => [self::class, 'scrub'],
        ];
    }

    public function export()
    {
        foreach ($this->messages as [$message, $level, $category]) {
            if ($level !== Logger::LEVEL_ERROR) {
                continue;
            }
            \Sentry\withScope(function (Scope $scope) use ($message, $category) {
                $scope->setTag('app', Yii::$app->id);
                $scope->setTag('category', (string) $category);
                self::addRequest($scope);
                if ($message instanceof \Throwable) {
                    \Sentry\captureException($message);
                } else {
                    \Sentry\captureMessage(is_string($message) ? $message : json_encode($message), Severity::error());
                }
            });
        }
    }

    /**
     * Who and where, without personal data: the account id and type, the HTTP method and the
     * route. Links carry tokens in their path (booking links, self-service links), so the URL
     * itself is not sent.
     */
    private static function addRequest(Scope $scope)
    {
        $app = Yii::$app;
        // Console runs (and the queue worker) have no user component
        $user = $app->get('user', false);
        if ($user && !$user->isGuest) {
            $identity = $user->identity;
            $scope->setUser(['id' => (string) $user->id]);
            if (method_exists($identity, 'isPatientAccount')) {
                $scope->setTag('account', $identity->isPatientAccount() ? 'patient' : 'staff');
            }
        }
        if ($app->requestedRoute !== null && $app->requestedRoute !== '') {
            $scope->setTag('route', $app->requestedRoute);
        }
        $request = $app->get('request', false);
        if ($request instanceof \yii\web\Request) {
            $scope->setTag('method', $request->getMethod());
        }
    }

    /**
     * before_send: removes personal data before an event leaves the server. Request data, cookies,
     * headers, extra data and breadcrumbs are dropped; email addresses, phone numbers and link
     * tokens in messages and exception texts are masked; only the user's id is kept.
     */
    public static function scrub(Event $event)
    {
        $event->setRequest([]);
        $event->setExtra([]);
        $event->setBreadcrumb([]);
        $event->setServerName(null);
        $user = $event->getUser();
        if ($user !== null) {
            $event->setUser(\Sentry\UserDataBag::createFromUserIdentifier($user->getId() ?? 'unknown'));
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(self::mask($event->getMessage()), [], self::mask((string) $event->getMessageFormatted()));
        }
        $exceptions = $event->getExceptions();
        foreach ($exceptions as $exception) {
            $exception->setValue(self::mask($exception->getValue()));
            // Function arguments in the stack trace can hold form data or patient details
            if ($stacktrace = $exception->getStacktrace()) {
                foreach ($stacktrace->getFrames() as $frame) {
                    $frame->setVars([]);
                }
            }
        }
        $event->setExceptions($exceptions);

        return $event;
    }

    /**
     * Masks email addresses, phone numbers and link tokens in a text.
     */
    public static function mask($text)
    {
        $text = (string) $text;
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
        // Kenyan and international numbers: 07.., 01.., 2547.., +2547.. (with spaces or dashes)
        $text = preg_replace('/(?<![\w])(?:\+?254|0)[17](?:[\s\-]?\d){8}(?!\d)/', '[phone]', $text);
        // Booking links (/book/<token>) and self-service links (/manage/<id>/<signature>)
        $text = preg_replace('#/book/[A-Za-z0-9_\-]{8,}#', '/book/[token]', $text);
        $text = preg_replace('#/manage/(\d+)/[A-Za-z0-9_\-]{8,}#', '/manage/$1/[signature]', $text);

        return $text;
    }
}
