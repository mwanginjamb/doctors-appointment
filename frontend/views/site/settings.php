<?php

use yii\helpers\Url;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var app\models\ProviderSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = 'Settings';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="body-content py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- content -->
            <div class="card mb-5">
                <div class="card-body text-center">
                    <h2 class="card-title h2 mb-4">Settings</h2>
                    <div class="border mx-auto"></div>
                    <div class="row">
                        <div class="col-12">
                            <div
                                class="d-flex gap-4 flex-column flex-md-row align-items-center justify-content-evenly my-5">
                                <?= Html::a('RBAC', Url::toRoute(['rbac/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Gender', Url::toRoute(['gender/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Specializations', Url::toRoute(['specialization/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>