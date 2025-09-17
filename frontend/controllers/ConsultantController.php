<?php

namespace frontend\controllers;

use frontend\models\Gender;
use Symfony\Component\VarDumper\VarDumper;
use yii\helpers\ArrayHelper;
use yii\web\Controller;
use frontend\models\Consultant;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use frontend\models\ConsultantSearch;
use yii\web\NotFoundHttpException;

use Yii;

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
                    'only' => [
                        'index',
                        'create',
                        'update',
                        'delete',
                        'view',
                        'verify'
                    ],
                    'rules' => [
                        [ // unauthenticated users
                            'actions' => ['signup'],
                            'allow' => true,
                            'roles' => ['?'],
                        ],
                        [ // logged in users
                            'actions' => ['index', 'create', 'update', 'delete', 'view'],
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

        if (!$model) {
            Yii::$app->session->setFlash('info', 'Client profiles are not yet available.');
            // Return to home
            return $this->goHome();
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
        $model->user_id = \Yii::$app->user->id;

        if ($this->request->isPost) {
            if ($model->load($this->request->post()) && $model->save()) {
                return $this->redirect(['view', 'id' => $model->id]);
            }
        } else {
            $model->loadDefaultValues();
        }

        Yii::$app->session->setFlash('info', 'Please correct the Names accordingly as you fill out this form.');
        return $this->render('create', [
            'model' => $model,
            'gender' => ArrayHelper::map(Gender::find()->all(), 'id', 'name'),
            'providers' => ArrayHelper::map(\app\models\Provider::find()->all(), 'id', 'provider'),
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
        $model = $this->findModel($id);
        if ($model->user_id == NULL) {
            $model->user_id = \Yii::$app->user->id;
        }

        if ($this->request->isPost && $model->load($this->request->post()) && $model->save()) {
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', [
            'model' => $model,
            'gender' => ArrayHelper::map(Gender::find()->all(), 'id', 'name'),
            'providers' => ArrayHelper::map(\app\models\Provider::find()->all(), 'id', 'provider'),
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
        $this->findModel($id)->delete();

        return $this->redirect(['index']);
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
