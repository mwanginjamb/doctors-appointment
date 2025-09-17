<?php

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var frontend\models\Specialization $model */
/** @var yii\widgets\ActiveForm $form */
$this->title = Yii::t('app', 'Specializations');
$this->params['breadcrumbs'][] = $this->title;
?>


<main class="flex-grow-1 p-4 p-md-5">
    <div class="container-fluid">
        <header class="mb-5">
            <h1 class="h2 fw-bold text-dark">Manage Specializations</h1>
            <p class="text-muted">Add, edit, or remove specializations offered by the medical center.</p>
        </header>
        <div class="card border-0 shadow-sm mb-5">
            <div class="card-body p-4">
                <h2 class="h5 fw-semibold text-dark mb-4">Add New Specialization</h2>
                <?php $form = ActiveForm::begin(); ?>
                <!-- <form class="row g-3"> -->
                <div class="col-md-12">
                    <?= $form->field($model, 'specialization')->textInput(['maxlength' => true]) ?>
                </div>
                <div class="col-md-12">
                    <?= $form->field($model, 'description')->textarea(['rows' => 6]) ?>
                </div>
                <div class="col-12">
                    <div class="form-group">
                        <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-success']) ?>
                    </div>
                </div>
                <!-- </form> -->
                <?php ActiveForm::end(); ?>
            </div>
        </div>
        <div>
            <h2 class="h4 fw-semibold text-dark">Existing Specializations</h2>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="py-3 px-3" scope="col">Name</th>
                                    <th class="py-3 px-3" scope="col">Description</th>
                                    <th class="py-3 px-3 text-end" scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($specializations)): ?>
                                    <?php foreach ($specializations as $specialization): ?>
                                        <tr>
                                            <td class="py-3 px-3 fw-medium"><?= $specialization->specialization ?></td>
                                            <td class="py-3 px-3 text-muted w-50"><?= $specialization->description ?></td>
                                            <td class="py-3 px-3 text-end">

                                                <?= Html::a('Edit', ['update', 'id' => $specialization->id], ['class' => 'text-primary text-decoration-none']) ?>
                                                <span class="mx-2 text-muted">|</span>

                                                <?= Html::a('Delete', ['delete', 'id' => $specialization->id], [
                                                    'class' => 'text-danger text-decoration-none',
                                                    'data' => [
                                                        'confirm' => Yii::t('app', 'Are you sure you want to delete this item?'),
                                                        'method' => 'post',
                                                    ],
                                                ]) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="py-3 px-3 fw-medium">No specializations found.</td>
                                    </tr>

                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>