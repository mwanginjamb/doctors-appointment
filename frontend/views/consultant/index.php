<?php

use app\models\Consultant;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\ActionColumn;
use yii\grid\GridView;

/** @var yii\web\View $this */
/** @var app\models\ConsultantSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('app', 'Consultants');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="consultant-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('app', 'Create Consultant'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            // 'id',
            'names',
            'license_number',
            'speciality:ntext',
            'sub_speciality:ntext',
            //'kmpdc_registration_number',
            //'user_id',
            //'facility',
            //'physical_address:ntext',
            //'created_at',
            //'updated_at',
            //'created_by',
            //'updated_by',
            //'consultant_id',
            [
                'class' => ActionColumn::className(),
                'urlCreator' => function ($action, Consultant $model, $key, $index, $column) {
                        return Url::toRoute([$action, 'id' => $model->id]);
                    }
            ],
        ],
    ]); ?>


</div>