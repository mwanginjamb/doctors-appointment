<?php

use frontend\models\PublicHoliday;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = 'Public Holidays';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="holiday-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        The clinic is closed on these dates: nobody can be booked. Add Eid al-Fitr, Eid al-Adha and any
        holiday gazetted during the year. When a holiday falls on a Sunday, add the Monday after it.
    </p>

    <p>
        <?= Html::a('Add Holiday', ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'rowOptions' => function (PublicHoliday $model) {
            return $model->date < date('Y-m-d') ? ['class' => 'text-muted'] : [];
        },
        'columns' => [
            [
                'attribute' => 'date',
                'value' => function (PublicHoliday $model) {
                    return Yii::$app->formatter->asDate(strtotime($model->date), 'php:D j M Y');
                },
            ],
            'name',
            [
                'class' => ActionColumn::class,
                'template' => '{update} {delete}',
                'urlCreator' => function ($action, PublicHoliday $model) {
                    return Url::toRoute([$action, 'id' => $model->id]);
                },
            ],
        ],
    ]); ?>

</div>
