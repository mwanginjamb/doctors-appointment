<?php

use yii\base\View;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var frontend\models\SearchForm $model */
/** @var frontend\models\Consultant[] $results */
/** @var bool $searched */
/** @var array $catalogue from Specialization::catalogue() */

[$mainItems, $mainOptions] = \frontend\components\SpecializationPicker::mainOptions($catalogue, $model->speciality);
[$subItems, $subOptions] = \frontend\components\SpecializationPicker::subOptions($catalogue, $model->sub_speciality);
\frontend\components\SpecializationPicker::register($this, '#searchform-speciality', '#searchform-sub_speciality', [
    'mainPlaceholder' => 'e.g. Dentistry, heart, skin…',
    'subPlaceholder' => 'e.g. Orthodontics…',
]);

$this->title = 'Find a Doctor';
?>
<div class="site-index">

    <div class="body-content py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">

                <!-- Search Card -->
                <div class="card shadow-sm border-0 mb-5">
                    <div class="card-body p-4 p-md-5">
                        <h1 class="card-title h2 mb-4">Find a Physician</h1>
                        <!-- Search form -->
                        <div class="search g-3">
                            <?php $form = ActiveForm::begin(); ?>
                            <div class="row">
                                <div class="col-md-4 py-3">
                                    <?= $form->field($model, 'speciality')->dropDownList($mainItems, ['prompt' => '', 'options' => $mainOptions]) ?>
                                </div>
                                <div class="col-md-4 py-3">
                                    <?= $form->field($model, 'sub_speciality')->dropDownList($subItems, ['prompt' => '', 'options' => $subOptions]) ?>
                                </div>
                                <div class="col-md-4 py-3">
                                    <?= $form->field($model, 'physical_address')->textInput(['placeholder' => 'County, Town, Building']) ?>
                                </div>
                            </div>
                            <div class="col-12 d-md-flex justify-content-md-end mt-4">

                                <button type="submit"
                                    class="btn btn-primary w-100 w-md-auto d-flex align-items-center justify-content-center gap-2"><i
                                        class="bi bi-search me-2"></i>Search</button>

                            </div>
                            <?php ActiveForm::end(); ?>
                        </div>
                    </div>
                </div>


                <!-- Search results -->
                <?php if (!empty($results)): ?>
                    <h2 class="h3 mb-4">Search Results</h2>
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-4">
                            <div class="table-responsive">
                                <table class="table table-hover table-borderless mb-0" id="results">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="p-3" scope="col">Consultant</th>
                                            <th class="p-3" scope="col">Specialialization</th>
                                            <th class="p-3" scope="col">Physical Address</th>
                                            <!-- <th class="p-3" scope="col">Rating</th> -->
                                            <th class="p-3" scope="col">Gender</th>
                                            <th class="p-3" scope="col">Facility</th>
                                            <th class="p-3" scope="col">Practice</th>
                                            <th class="p-3" scope="col"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results as $res): ?>
                                            <tr class="border-top">
                                                <td class=" p-3 align-middle fw-medium"><?= Html::encode(ucwords($res->names)) ?>
                                                </td>
                                                <td class="p-3 align-middle text-muted">
                                                    <?= Html::encode($res->speciality) ?>
                                                    <?php if ($res->sub_speciality): ?>
                                                        <small class="d-block"><?= Html::encode($res->sub_speciality) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-3 align-middle text-muted"><?= Html::encode($res->physical_address) ?></td>
                                                <!-- <td class="p-3 align-middle">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div>
                                                            <span class="material-symbols-outlined star-filled fs-5">star</span>
                                                            <span class="material-symbols-outlined star-filled fs-5">star</span>
                                                            <span class="material-symbols-outlined star-filled fs-5">star</span>
                                                            <span class="material-symbols-outlined star-filled fs-5">star</span>
                                                            <span class="material-symbols-outlined star-empty fs-5">star</span>
                                                        </div>
                                                        <span class="fw-medium text-muted">4.0</span>
                                                    </div>
                                                </td> -->
                                                <td><?= Html::encode($res->genderIdentity->name ?? '') ?></td>
                                                <td><?= Html::encode($res->facility ?? '') ?></td>
                                                <td><?= Html::encode($res->practice_name) ?></td>
                                                <td>
                                                    <!-- make the buttons responsive -->
                                                    <div class="d-flex align-items-center gap-2">
                                                        <?= Html::a('View Profile', Url::toRoute(['consultant/view', 'id' => $res->id, 'consultant' => 1]), ['class' => 'btn btn-sm btn-outline-primary text-decoration-none fw-semibold', 'target' => '_blank']) ?>
                                                        <?= Html::a('Book Appointment', Url::toRoute(['appointments/calendar', 'cid' => $res->id]), ['class' => 'btn btn-sm btn-primary text-decoration-none fw-semibold']) ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php elseif (!empty($searched)): ?>
                    <div class="alert alert-light border text-center">
                        No consultants match your search yet. Try a broader area of specialization or clear the sub-speciality.
                    </div>
                <?php endif ?>
                <!-- Results -->
            </div>
        </div>

    </div>
</div>

<?php
# Attach data tables to results table
$script = <<<JS
    $('#results').DataTable();
JS;
$this->registerJs($script, \yii\web\View::POS_READY);
?>