<?php

/** @var yii\web\View $this */
/** @var string $phone */
/** @var common\models\User[] $accounts */
/** @var int $unlinked patient accounts on the number registered with an email, not yet linked */

use common\helpers\Phone;
use frontend\services\PhoneSignIn;
use yii\bootstrap5\Html;

$this->title = $accounts ? 'Who is signing in?' : 'Create your account';
?>
<div class="phone-sign-in-choose">
    <p class="text-muted text-center">Your number <?= Html::encode(Phone::mask($phone)) ?> is confirmed.</p>

    <?php if ($accounts): ?>
        <div class="d-grid gap-2 mb-4" id="accounts">
            <?php foreach ($accounts as $account): ?>
                <?= Html::beginForm(['phone-sign-in/sign-in'], 'post', ['class' => 'account-choice']) ?>
                    <?= Html::hiddenInput('account', $account->id) ?>
                    <?= Html::submitButton(Html::encode(PhoneSignIn::shortName($account)), ['class' => 'btn btn-outline-primary btn-lg w-100']) ?>
                <?= Html::endForm() ?>
            <?php endforeach; ?>
        </div>
        <h2 class="h6 fw-bold">Someone else on this phone?</h2>
    <?php endif; ?>

    <?= Html::beginForm(['phone-sign-in/create'], 'post', ['id' => 'create-form']) ?>
        <div class="mb-3">
            <label class="form-label" for="full_name">Your full name</label>
            <?= Html::textInput('full_name', '', [
                'id' => 'full_name',
                'class' => 'form-control',
                'autocomplete' => 'name',
                'required' => true,
                'maxlength' => 255,
                'autofocus' => !$accounts,
            ]) ?>
        </div>
        <?= Html::submitButton($accounts ? 'Create a new account' : 'Create my account', ['class' => 'btn ' . ($accounts ? 'btn-outline-secondary' : 'btn-primary btn-lg w-100')]) ?>
    <?= Html::endForm() ?>

    <?php if ($unlinked): ?>
        <div class="alert alert-info small mt-4 mb-0" id="unlinked-note">
            This number is also on <?= $unlinked === 1 ? 'an account' : $unlinked . ' accounts' ?> created with an email
            address. To use your phone for it, <?= Html::a('sign in with your email', ['site/login']) ?> now, once:
            your number will then work for it too.
        </div>
    <?php endif; ?>
</div>
