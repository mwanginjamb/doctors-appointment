<?php

namespace frontend\controllers;

use frontend\models\Provider;
use Yii;
use yii\helpers\Url;
use yii\helpers\Html;
use common\models\User;
use frontend\components\AdminAccessControl;
use yii\web\Controller;
use frontend\models\Gender;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\filters\AccessControl;
use frontend\models\Consultant;
use frontend\models\Specialization;

use yii\web\NotFoundHttpException;
use frontend\models\ConsultantSearch;


/**
 * ConsultantController implements the CRUD actions for Consultant model.
 */
class ConsultantController extends Controller
{
    /**
     * @inheritDoc
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                'verbs' => [
                    'class' => VerbFilter::class,
                    'actions' => [
                        'delete' => ['POST'],
                    ],
                ],
                'access' => [
                    'class' => AccessControl::class,
                    'rules' => [
                        [ // only consultants manage consultant profiles
                            'actions' => ['create', 'update', 'delete'],
                            'allow' => true,
                            'roles' => ['@'],
                            'matchCallback' => function () {
                                return Yii::$app->user->identity->role === 'consultant';
                            },
                        ],
                        [ // admins edit any consultant's profile
                            'actions' => ['update'],
                            'allow' => true,
                            'roles' => ['@'],
                            'matchCallback' => function () {
                                return AdminAccessControl::isAdmin();
                            },
                        ],
                        [ // logged in users
                            'actions' => ['index', 'view'],
                            'allow' => true,
                            'roles' => ['@'],
                        ],
                    ],
                ]
            ]
        );
    }

    /**
     * Lists all Consultant models.
     *
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new ConsultantSearch();
        $dataProvider = $searchModel->search($this->request->queryParams);
        $dataProvider->query->andWhere(['user_id' => Yii::$app->user->id]);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Consultant model.
     * @param int $id ID
     * @return string
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        $model = Consultant::findOne($id);



        if (Yii::$app->user->identity->role === 'client' && !Yii::$app->request->get('consultant')) {
            $userId = Yii::$app->user->id;

            // check if user profile exists for this user
            $userProfile = \frontend\models\UserProfile::findOne(['user_id' => $userId]);

            if ($userProfile) {
                Yii::$app->session->setFlash('info', 'Please view your profile.');
                return $this->redirect(Url::toRoute(['user-profile/view', 'id' => $userProfile->id]), 302);
            } else { // create a new user profile
                Yii::$app->session->setFlash('info', 'Please create your profile.');
                return $this->redirect(Url::toRoute(['user-profile/create']), 302);
            }
        } elseif ($model === null && Yii::$app->user->identity->role === 'client') { // a client has not found a consultant profile
            // email the consultant via identity email address, then redirect to home page
            Yii::$app->session->setFlash('error', 'The requested profile does not exist, we have notified the consultant.');
            return $this->redirect(Url::toRoute(['site/index']), 302);
        }

        // consultant view
        if (!$model && Yii::$app->user->identity->role === 'consultant') {

            // Attempt to find a consultant profile for this user
            $user = User::findIdentity($id);
            if ($user && $user->consultancy) {
                return $this->redirect(Url::toRoute(['view', 'id' => $user->consultancy->id]));
            }

            Yii::$app->session->setFlash('error', 'The requested profile does not exist, proceed to create one.');
            return $this->redirect(Url::toRoute(['create']));
        }

        return $this->render('view', [
            'model' => $model,
        ]);
    }

    /**
     * Creates a new Consultant model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return string|\yii\web\Response
     */
    public function actionCreate()
    {
        $model = new Consultant();
        $model->user_id = Yii::$app->user->id;
        $model->sub_specialities = [];

        if ($this->request->isPost) {
            if ($model->load($this->request->post()) && $model->save()) {
                return $this->redirect(['view', 'id' => $model->id]);
            }
        } else {
            $model->loadDefaultValues();
            // Prefill from the logged-in account; the consultant can still edit them
            $model->consultant_email = Yii::$app->user->identity->email;
        }

        Yii::$app->session->setFlash('info', 'Please correct the Names accordingly as you fill out this form.');
        return $this->render('create', [
            'model' => $model,
            'gender' => ArrayHelper::map(Gender::find()->all(), 'id', 'name'),
            'providers' => ArrayHelper::map(Provider::find()->all(), 'id', 'provider'),
            'catalogue' => Specialization::catalogue(),
        ]);
    }

    /**
     * Updates an existing Consultant model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param int $id ID
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $profile = $this->findModel($id);
        // An unclaimed legacy profile is "own" only for a consultant, who claims it by editing it
        $ownProfile = $profile->user_id === null
            ? Yii::$app->user->identity->role === 'consultant'
            : (int) $profile->user_id === (int) Yii::$app->user->id;
        // Admins edit anyone's profile; everyone else only their own
        $model = !$ownProfile && AdminAccessControl::isAdmin() ? $this->findModel($id) : $this->findOwnModel($id);
        $model->loadSubSpecialities();

        if ($this->request->isPost && $model->load($this->request->post()) && $model->save()) {
            if (!$ownProfile) {
                Yii::$app->session->setFlash('success', Html::encode($model->names) . "'s profile saved.");
                return $this->redirect(['manage-consultants/index']);
            }
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', [
            'model' => $model,
            'gender' => ArrayHelper::map(Gender::find()->all(), 'id', 'name'),
            'providers' => ArrayHelper::map(\frontend\models\Provider::find()->all(), 'id', 'provider'),
            'catalogue' => Specialization::catalogue(),
        ]);
    }

    /**
     * Deletes an existing Consultant model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param int $id ID
     * @return \yii\web\Response
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $this->findOwnModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * Finds a Consultant profile owned by the current user.
     * Unclaimed legacy profiles (no user_id) are claimed by the consultant who edits them.
     * @param int $id ID
     * @return Consultant the loaded model
     * @throws NotFoundHttpException if the model cannot be found or belongs to someone else
     */
    protected function findOwnModel($id)
    {
        $model = $this->findModel($id);
        if ($model->user_id === null) {
            $model->user_id = Yii::$app->user->id;
        }
        if ((int) $model->user_id !== (int) Yii::$app->user->id) {
            throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        }

        return $model;
    }

    /**
     * Finds the Consultant model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param int $id ID
     * @return Consultant the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Consultant::findOne(['id' => $id])) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
