<?php

/**
 * Google One Tap: Google's "Continue as …" prompt, shown when the browser is signed in to Google.
 * Choosing an account hands this page an ID token, which the hidden form posts (with our CSRF
 * token) to google-one-tap/sign-in.
 *
 * @var yii\web\View $this
 * @var string $clientId
 * @var string $context signin|signup
 */

use yii\bootstrap5\Html;

$this->registerJsFile('https://accounts.google.com/gsi/client', ['async' => true, 'defer' => true, 'position' => \yii\web\View::POS_END]);
?>
<div id="g_id_onload"
     data-client_id="<?= Html::encode($clientId) ?>"
     data-callback="googleOneTapSignIn"
     data-context="<?= Html::encode($context) ?>"
     data-auto_select="false"
     data-cancel_on_tap_outside="true"
     data-itp_support="true"
     data-use_fedcm_for_prompt="true"></div>
<?= Html::beginForm(['google-one-tap/sign-in'], 'post', ['id' => 'google-one-tap-form', 'class' => 'd-none']) ?>
    <?= Html::hiddenInput('credential', '') ?>
<?= Html::endForm() ?>
<script>
    window.googleOneTapSignIn = function (response) {
        var form = document.getElementById('google-one-tap-form');
        form.elements.credential.value = response.credential;
        form.submit();
    };
</script>
