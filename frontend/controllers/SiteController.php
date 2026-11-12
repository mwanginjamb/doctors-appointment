<?php

namespace frontend\controllers;

use Yii;
use common\models\User;
use yii\web\Controller;
use common\components\RequestContext;
use frontend\models\SearchForm;
use frontend\components\AdminAccessControl;
use yii\filters\VerbFilter;
use common\models\LoginForm;
use common\authclient\OauthProfile;
use yii\authclient\AuthAction;
use yii\filters\AccessControl;
use frontend\models\SignupForm;
use frontend\models\ContactForm;
use yii\authclient\ClientInterface;
use frontend\models\VerifyEmailForm;
use yii\web\BadRequestHttpException;
use frontend\models\ResetPasswordForm;
use yii\base\InvalidArgumentException;
use frontend\models\PasswordResetRequestForm;
use frontend\models\ResendVerificationEmailForm;

/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'adminAccess' => [
                'class' => AdminAccessControl::class,
                'only' => ['settings'],
            ],
            'usersAccess' => [
                'class' => AdminAccessControl::class,
                'only' => ['users', 'deactivate-user', 'reactivate-user'],
                'permission' => \common\components\RbacHelper::PERM_MANAGE_USERS,
            ],
            'access' => [
                'class' => AccessControl::class,
                'only' => ['logout', 'signup'],
                'rules' => [
                    [
                        'actions' => ['signup'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                    'deactivate-user' => ['post'],
                    'reactivate-user' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => \yii\web\ErrorAction::class,
            ],
            'captcha' => [
                'class' => \yii\captcha\CaptchaAction::class,
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
            'auth' => [
                'class' => AuthAction::class,
                'successCallback' => [$this, 'onAuthSuccess'],
            ],
        ];
    }



    public function onAuthSuccess(ClientInterface $client)
    {
        $profile = OauthProfile::fromAttributes($client->getId(), $client->getUserAttributes());
        if (!$profile->isUsable()) {
            Yii::warning('OAuth login without email or id from client ' . $client->getId(), __METHOD__);
            Yii::$app->session->setFlash('error', 'We could not get your email address from ' . $client->getTitle() . '. Please sign up with email instead.');
            return $this->redirect(['site/login']);
        }

        // Find, link or create user
        $user = User::createOrUpdateFromOauth($client->getId(), $profile->id, $profile->email, $profile->name, $profile->emailVerified);
        if ($user && (int) $user->status !== User::STATUS_ACTIVE) {
            Yii::$app->session->setFlash('error', 'Your account is not active yet. Please verify your email address first.');
            return $this->redirect(['site/login']);
        }
        if (!$user || !Yii::$app->user->login($user)) {
            Yii::$app->session->setFlash('error', 'We could not sign you in with ' . $client->getTitle() . '. If you already have an account, sign in with your password.');
            return $this->redirect(['site/login']);
        }

        return null;
    }

    /**
     * Displays homepage.
     *
     * @return mixed
     */
    public function actionIndex()
    {
        $model = new SearchForm();
        $request = Yii::$app->request;
        $results = [];
        $searched = false;
        // Load the form so the chosen filters stay filled in after searching
        if ($model->load($request->post()) && $model->validate()) {
            $searched = true;
            $results = $model->findConsultants([$model->formName() => $model->getAttributes(['speciality', 'sub_speciality', 'physical_address'])], $model->formName());
        }

        return $this->render('index', [
            'model' => $model,
            'results' => $results,
            'searched' => $searched,
            'catalogue' => \frontend\models\Specialization::catalogue(),
        ]);
    }

    /**
     * Logs in a user.
     *
     * @return mixed
     */
    public function actionLogin()
    {
        $this->layout = 'guest';
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }

        $model->password = '';

        return $this->render('login', [
            'model' => $model,
        ]);
    }

    /**
     * Logs out the current user.
     *
     * @return mixed
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    /**
     * Displays contact page.
     *
     * @return mixed
     */
    public function actionContact()
    {
        $model = new ContactForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail(Yii::$app->params['adminEmail'])) {
                Yii::$app->session->setFlash('success', 'Thank you for contacting us. We will respond to you as soon as possible.');
            } else {
                Yii::$app->session->setFlash('error', 'There was an error sending your message.');
            }

            return $this->refresh();
        }

        return $this->render('contact', [
            'model' => $model,
        ]);
    }

    /**
     * Displays about page.
     *
     * @return mixed
     */
    public function actionAbout()
    {
        return $this->render('about');
    }

    /**
     * The privacy notice (F11). Public: patients read it before signing up or booking through a link.
     */
    public function actionPrivacy()
    {
        return $this->render('privacy', ['version' => \common\models\Consent::noticeVersion()]);
    }

    /**
     * Signs user up.
     *
     * @return mixed
     */
    public function actionSignup()
    {
        $this->layout = 'guest';
        $request = Yii::$app->request;
        // The role-choice buttons post a top-level "role"; the signup form itself
        // posts it as SignupForm[role]. Reading only the former sent every form
        // submission back to the role-choice screen and discarded the input.
        $formData = $request->post('SignupForm');
        $role = $request->post('role') ?: (is_array($formData) ? ($formData['role'] ?? null) : null);
        if (!$role) {
            return $this->render('role-choice');
        }
        $model = new SignupForm();
        $model->role = $role;
        if ($model->load($request->post())) {
            if ($model->signup()) {
                Yii::$app->session->setFlash('success', 'Thank you for registration. Please check your inbox for verification email.');
                return $this->goHome();
            }

            if ($model->failureReason !== SignupForm::FAILURE_VALIDATION) {
                // Validation errors render inline; anything else needs a visible
                // message plus a reference that matches the log lines.
                $ref = RequestContext::id();
                $message = $model->failureReason === SignupForm::FAILURE_MAIL
                    ? 'We could not send your verification email, so your account was not created. Please try again shortly.'
                    : 'We could not create your account. Please try again shortly.';
                Yii::$app->session->setFlash('error', $message . ' (Reference: ' . $ref . ')');
            }
        }

        return $this->render('signup', [
            'model' => $model,
        ]);
    }

    /**
     * Requests password reset.
     *
     * @return mixed
     */
    public function actionRequestPasswordReset()
    {

        $this->layout = 'guest';
        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', 'Check your email for further instructions.');

                return $this->goHome();
            }

            Yii::$app->session->setFlash('error', 'Sorry, we are unable to reset password for the provided email address.');
        }

        return $this->render('requestPasswordResetToken', [
            'model' => $model,
        ]);
    }

    /**
     * Resets password.
     *
     * @param string $token
     * @return mixed
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token)
    {
        $this->layout = 'guest';
        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
            Yii::$app->session->setFlash('success', 'New password saved.');

            return $this->goHome();
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    /**
     * Verify email address.
     *
     * GET only renders a confirmation page; the account is activated on POST.
     * Email security scanners and link prefetchers (e.g. Outlook Safe Links)
     * issue GET requests to every link in a message, which previously consumed
     * the token before the user ever clicked it. They do not submit forms.
     *
     * @param string $token
     * @throws BadRequestHttpException
     * @return yii\web\Response|string
     */
    public function actionVerifyEmail($token)
    {
        try {
            $model = new VerifyEmailForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if (!Yii::$app->request->isPost) {
            $this->layout = 'guest';
            return $this->render('verifyEmail', ['token' => $token]);
        }

        if (($user = $model->verifyEmail()) && Yii::$app->user->login($user)) {
            Yii::$app->session->setFlash('success', 'Your email has been confirmed!');
            return $this->goHome();
        }

        Yii::$app->session->setFlash('error', 'Sorry, we are unable to verify your account with provided token.');
        return $this->goHome();
    }

    /**
     * Resend verification email
     *
     * @return mixed
     */
    public function actionResendVerificationEmail()
    {
        $this->layout = 'guest';
        $model = new ResendVerificationEmailForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', 'Check your email for further instructions.');
                return $this->goHome();
            }
            Yii::$app->session->setFlash('error', 'Sorry, we are unable to resend verification email for the provided email address.');
        }

        return $this->render('resendVerificationEmail', [
            'model' => $model
        ]);
    }

    // Settings route

    public function actionSettings($facility = null, $month = null)
    {
        $this->layout = 'admin';
        // F8 phase 2: the numbers for one facility, and a month's sessions and fees per facility
        $facility = ctype_digit((string) $facility) ? \frontend\models\Facility::findOne((int) $facility) : null;
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month) ? $month : date('Y-m');

        return $this->render('settings', [
            'stats' => new \frontend\models\ClinicStats(['facilityId' => $facility->id ?? null]),
            'facility' => $facility,
            'month' => $month,
        ]);
    }

    /**
     * Lists all system users (consultancy admin).
     */
    public function actionUsers($q = null, $type = null)
    {
        $this->layout = 'admin';

        $query = \frontend\models\User::find()->orderBy(['id' => SORT_DESC]);
        if ($q !== null && $q !== '') {
            $query->andWhere(['or', ['like', 'username', $q], ['like', 'email', $q], ['like', 'full_name', $q], ['like', 'phone_number', $q]]);
        }
        if ($type !== null && $type !== '') {
            $query->andWhere(['role' => $type]);
        }

        $dataProvider = new \yii\data\ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
            'sort' => ['attributes' => ['id', 'username', 'email', 'role', 'status', 'created_at']],
        ]);

        return $this->render('users', [
            'dataProvider' => $dataProvider,
            'q' => $q,
            'type' => $type,
        ]);
    }

    /**
     * Deactivates an account: they can no longer sign in (and are signed out on their next request),
     * a deactivated consultant can no longer be booked and disappears from patients' search, and
     * patients can no longer be booked by staff. Appointments already booked are kept; the admin is
     * told how many are coming up so someone can follow them up.
     */
    public function actionDeactivateUser($id)
    {
        $user = $this->findAccount($id);
        if ((int) $user->id === (int) Yii::$app->user->id) {
            Yii::$app->session->setFlash('error', 'You cannot deactivate your own account.');
            return $this->redirect(Yii::$app->request->referrer ?: ['users']);
        }

        $user->status = \common\models\User::STATUS_DELETED;
        $user->save(false);
        $name = $user->full_name ?: $user->username;
        Yii::$app->session->setFlash('success', "$name is deactivated and can no longer sign in.");

        $column = $user->isConsultantAccount() ? 'consultant_id' : 'patient_id';
        $upcoming = \frontend\models\Appointments::find()
            ->where([$column => $user->id])
            ->andWhere(['>=', 'date', date('Y-m-d')])
            ->andWhere(['or', ['status' => null], ['status' => [\frontend\models\Appointments::STATUS_SCHEDULED, \frontend\models\Appointments::STATUS_CONFIRMED]]])
            ->count();
        if ($upcoming > 0) {
            // Flashes are shown escaped in the admin layout, so this stays plain text
            $where = $user->isConsultantAccount()
                ? " Filter the Appointments Board by $name to find them."
                : '';
            Yii::$app->session->addFlash('warning', "$upcoming upcoming appointment(s) are kept; cancel or move them as needed.$where");
        }

        return $this->redirect(Yii::$app->request->referrer ?: ['users']);
    }

    public function actionReactivateUser($id)
    {
        $user = $this->findAccount($id);
        $user->status = \common\models\User::STATUS_ACTIVE;
        $user->save(false);
        Yii::$app->session->setFlash('success', ($user->full_name ?: $user->username) . ' is active again.');

        return $this->redirect(Yii::$app->request->referrer ?: ['users']);
    }

    /**
     * @return \common\models\User
     */
    private function findAccount($id)
    {
        $user = \common\models\User::findOne($id);
        if ($user === null) {
            throw new \yii\web\NotFoundHttpException('Account not found.');
        }

        return $user;
    }

    public function actionTemplate()
    {
        return $this->render('template');
    }
}
