<?php

/** @var yii\web\View $this */
/** @var string $phone */
/** @var string|null $error */

use yii\bootstrap5\Html;

$this->title = 'Sign in with your phone';
?>
<div class="phone-sign-in">
    <p class="text-muted text-center">We'll send a 6-digit code to your phone by WhatsApp (or SMS).</p>

    <?= Html::beginForm(['phone-sign-in/index'], 'post', ['id' => 'phone-form']) ?>
        <div class="mb-3">
            <label class="form-label" for="phone">Phone number</label>
            <?= Html::input('tel', 'phone', $phone, [
                'id' => 'phone',
                'class' => 'form-control form-control-lg' . ($error ? ' is-invalid' : ''),
                'placeholder' => '0712 345 678',
                'autocomplete' => 'tel',
                'inputmode' => 'tel',
                'required' => true,
                'autofocus' => true,
                'maxlength' => 20,
            ]) ?>
            <?php if ($error): ?>
                <div class="invalid-feedback d-block"><?= Html::encode($error) ?></div>
            <?php endif; ?>
        </div>
        <?= Html::submitButton('Send me a code', ['class' => 'btn btn-primary btn-lg w-100']) ?>
    <?= Html::endForm() ?>

    <p class="small text-muted mt-3 mb-0">
        New here? Use your number all the same: after the code you can create your account.
        By continuing you agree to our <?= Html::a('privacy notice', ['site/privacy'], ['target' => '_blank']) ?>.
    </p>

    <div class="d-flex justify-content-between mt-5">
        <?= Html::a('Sign in with email instead', ['site/login'], ['class' => 'link-secondary text-decoration-none']) ?>
    </div>
</div>
