<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */

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
                                <div class="col-md-6 py-3">
                                    <?= $form->field($model, 'speciality')->textInput(['placeholder' => 'Enter a speciality']) ?>
                                </div>
                                <div class="col-md-6 py-3">
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
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-borderless mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="p-3" scope="col">Specialialization</th>
                                            <th class="p-3" scope="col">Consultant</th>
                                            <th class="p-3" scope="col">Physical Address</th>
                                            <th class="p-3" scope="col">Rating</th>
                                            <th class="p-3" scope="col"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results as $res): ?>
                                            <tr class="border-top">
                                                <td class=" p-3 align-middle fw-medium"><?= ucwords($res->names) ?>
                                                </td>
                                                <td class="p-3 align-middle text-muted"><?= $res->speciality ?></td>
                                                <td class="p-3 align-middle text-muted"><?= $res->physical_address ?></td>
                                                <td class="p-3 align-middle">
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
                                                </td>
                                                <td>
                                                    <?= Html::a('View Profile', Url::toRoute(['consultant/view', 'id' => $res->id]), ['class' => 'btn btn-sm btn-outline-primary text-decoration-none fw-semibold', 'target' => '_blank']) ?>
                                                    <?= Html::a('Book Appointment', Url::toRoute(['appointments/calendar', 'cid' => $res->id]), ['class' => 'btn btn-sm btn-primary text-decoration-none fw-semibold']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php endif ?>
                <!-- Results -->
            </div>
        </div>

    </div>
</div>