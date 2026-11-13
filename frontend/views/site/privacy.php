<?php

/**
 * Privacy notice (F11, Kenya Data Protection Act 2019).
 *
 * DRAFT WORDING FOR LEGAL REVIEW. The controller's details come from .env (PRIVACY_* and
 * ODPC_REGISTRATION_NUMBER). When this text changes, change privacy.version in
 * common/config/params.php so signed-in patients are asked to accept it again.
 *
 * @var yii\web\View $this
 * @var string $version
 */

use yii\helpers\Html;

$p = Yii::$app->params;
$detail = function ($key) use ($p) {
    $value = trim((string) ($p[$key] ?? ''));
    return $value !== '' ? Html::encode($value) : Html::tag('em', 'to be provided', ['class' => 'text-muted']);
};
$clinic = Html::encode(Yii::$app->name);

$this->title = 'Privacy notice';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="site-privacy my-4" style="max-width: 50rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted small">Version <?= Html::encode($version) ?></p>

    <p>
        This notice explains what personal information <?= $clinic ?> collects when you book and attend
        appointments, why, who sees it, how long it is kept, and your rights under the Kenya Data
        Protection Act, 2019.
    </p>

    <h2 class="h5 fw-bold mt-4">Who is responsible for your information</h2>
    <dl class="row">
        <dt class="col-sm-4">Data controller</dt>
        <dd class="col-sm-8"><?= $detail('privacy.controllerName') ?></dd>
        <dt class="col-sm-4">Address</dt>
        <dd class="col-sm-8"><?= $detail('privacy.controllerAddress') ?></dd>
        <dt class="col-sm-4">Contact</dt>
        <dd class="col-sm-8"><?= $detail('privacy.contactEmail') ?> · <?= $detail('privacy.contactPhone') ?></dd>
        <dt class="col-sm-4">Data protection contact</dt>
        <dd class="col-sm-8"><?= $detail('privacy.dpoContact') ?></dd>
        <dt class="col-sm-4">ODPC registration</dt>
        <dd class="col-sm-8"><?= $detail('privacy.odpcRegistration') ?></dd>
    </dl>

    <h2 class="h5 fw-bold mt-4">What we collect</h2>
    <ul>
        <li>Your name, phone number, email address and, if you give it, your date of birth.</li>
        <li>Your appointments: the doctor, the facility (hospital or clinic) where you are seen, the date and time, the fee, whether you attended, and any cancellation note.</li>
        <li>What you tell us about the reason for your visit. This is health information, which the law treats as sensitive.</li>
        <li>Records of your sign-ins and of changes to your appointments and account, kept for security.</li>
    </ul>

    <h2 class="h5 fw-bold mt-4">Why we use it</h2>
    <ul>
        <li>To book, move and cancel your appointments, and to let your doctor and the clinic's front desk prepare for your visit.</li>
        <li>To send you confirmations and reminders by email, and, only if you agree, by WhatsApp or SMS.</li>
        <li>When you sign in with your phone number, to send you the one-time code you asked for, by WhatsApp or SMS.</li>
        <li>To keep a record of who changed what, to protect you and the clinic.</li>
    </ul>
    <p>
        We process your information with your consent. Because the reason for your visit is health
        information, we ask for your consent explicitly before you use the service. We do not use your
        information for marketing and we do not sell it.
    </p>

    <h2 class="h5 fw-bold mt-4">Who sees it</h2>
    <ul>
        <li>The doctor you book with. Doctors may work at several facilities; your doctor sees your appointments with them wherever they took place, but never your appointments with other doctors.</li>
        <li>The front-desk staff of the facility where your appointment takes place, named in your confirmation. They see your appointments at that facility only; front desk at other facilities see that the doctor is busy, not who with.</li>
        <li>The administrator of that facility, who manages its front desk and sees appointments at that facility only.</li>
        <li>Clinic administrators, to run the service.</li>
        <li>Companies that deliver our messages: our email provider, WhatsApp, an SMS provider and Google Firebase for app notifications. They receive only what is needed to deliver each message.</li>
        <li>Our hosting provider, which stores the information for us.</li>
    </ul>

    <h2 class="h5 fw-bold mt-4">How long we keep it</h2>
    <p>
        Appointment records are kept while your account is open and afterwards for as long as the law
        requires for health records. Security records of sign-ins and changes are kept for
        <?= (int) \common\models\AuditLog::retentionYears() ?> years and then deleted.
    </p>

    <h2 class="h5 fw-bold mt-4">Your rights</h2>
    <p>You have the right to:</p>
    <ul>
        <li>be told how your information is used (this notice);</li>
        <li>see the information we hold about you;</li>
        <li>have wrong information corrected;</li>
        <li>object to how your information is used, and withdraw your consent at any time;</li>
        <li>ask for your information to be deleted, where the law allows.</li>
    </ul>
    <p>
        Signed in, you can switch WhatsApp/SMS reminders off, or withdraw your consent and ask us to
        close your account, under <strong>Privacy &amp; messages</strong> in your account menu. You can
        also contact us using the details above. If you are not satisfied with our answer, you can
        complain to the Office of the Data Protection Commissioner (www.odpc.go.ke).
    </p>
</div>
