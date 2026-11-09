<?php

/** @var yii\web\View $this */
/** @var string $token */

use yii\bootstrap5\Html;

$this->title = 'Confirm your email';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="site-verify-email">
    <p>Click the button below to confirm your email address and activate your account.</p>

    <?= Html::beginForm(['site/verify-email', 'token' => $token], 'post', ['id' => 'verify-email-form']) ?>
        <div class="form-group">
            <?= Html::submitButton('Confirm email', ['class' => 'btn btn-primary', 'name' => 'confirm-button']) ?>
        </div>
    <?= Html::endForm() ?>
</div>
