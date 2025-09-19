<?php

use frontend\models\UserProfile;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\widgets\Pjax;
/** @var yii\web\View $this */
/** @var frontend\models\UserProfileSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('app', 'User Profiles');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="user-profile-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('app', 'Create User Profile'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?php Pjax::begin(); ?>
    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            //'id',
            [
                'attribute' => 'user_id',
                'value' => function ($model) {
                        return $model->user ? $model->user->full_name : 'Not Set';
                    },
                'filter' => \yii\helpers\ArrayHelper::map(\common\models\User::find()->asArray()->all(), 'id', 'full_name'),
                'label' => 'User Name'
            ],
            'phone',
            'email:email',
            ['attribute' => 'cash', 'value' => function ($model) {
                    return $model->cash ? 'Yes' : 'No'; }],
            [
                'attribute' => 'insurance',
                'value' => function ($model) {
                        return $model->insurance ? $model->insurance_names : 'Not Set';
                    },
                'filter' => \yii\helpers\ArrayHelper::map(\app\models\Provider::find()->asArray()->all(), 'id', 'provider'),
                'label' => 'Insurance Providers'
            ],
            //'dob',
            //'gender',
            //'created_at',
            //'updated_at',
            //'created_by',
            //'updated_by',
            [
                'class' => ActionColumn::className(),
                'urlCreator' => function ($action, UserProfile $model, $key, $index, $column) {
                        return Url::toRoute([$action, 'id' => $model->id]);
                    }
            ],
        ],
    ]); ?>

    <?php Pjax::end(); ?>

</div>