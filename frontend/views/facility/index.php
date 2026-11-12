<?php

use frontend\models\Facility;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var int $pendingRequests facility requests waiting for an answer */

$this->title = 'Facilities';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        The hospitals, clinics and rooms where consultants see patients. Consultants add the ones they
        work at on their Availability page, each with its own hours, session length and fee. Patients get
        the address and map link with every confirmation and reminder.
    </p>

    <?php if ($pendingRequests): ?>
        <div class="alert alert-warning">
            <?= $pendingRequests === 1 ? '1 facility request is' : $pendingRequests . ' facility requests are' ?> waiting.
            <?= Html::a('Answer', ['requests'], ['class' => 'alert-link']) ?>
        </div>
    <?php endif; ?>

    <p>
        <?= Html::a('Add Facility', ['create'], ['class' => 'btn btn-success']) ?>
        <?= Html::a('Facility Requests', ['requests'], ['class' => 'btn btn-outline-secondary']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'rowOptions' => function (Facility $model) {
            return $model->isActive() ? [] : ['class' => 'text-muted'];
        },
        'columns' => [
            [
                'attribute' => 'name',
                'format' => 'raw',
                'value' => function (Facility $model) {
                    return '<span class="d-inline-block rounded-circle me-2 align-middle" style="width:.7rem;height:.7rem;background:' . Html::encode($model->getColor()) . '"></span>'
                        . Html::encode($model->name)
                        . ($model->isActive() ? '' : ' <span class="badge text-bg-secondary">Not taking bookings</span>');
                },
            ],
            [
                'attribute' => 'address',
                'value' => function (Facility $model) {
                    return $model->address ? preg_replace('/\s*\R\s*/', ', ', $model->address) : null;
                },
            ],
            [
                'attribute' => 'ownership',
                'value' => function (Facility $model) {
                    return $model->getOwnershipLabel();
                },
            ],
            'phone',
            [
                'label' => 'Consultants',
                'value' => function (Facility $model) {
                    $names = array_filter(array_map(function ($e) {
                        return $e->consultant->names ?? null;
                    }, $model->engagements));
                    return $names ? implode(', ', $names) : '—';
                },
            ],
            [
                'class' => ActionColumn::class,
                'template' => '{closures} {update} {delete}',
                'buttons' => [
                    'closures' => function ($url) {
                        return Html::a('Closed days', $url, ['class' => 'small me-1 text-nowrap', 'title' => 'Days this facility is shut']);
                    },
                ],
                'urlCreator' => function ($action, Facility $model) {
                    return Url::toRoute([$action, 'id' => $model->id]);
                },
            ],
        ],
    ]); ?>

</div>
