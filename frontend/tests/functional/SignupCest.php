<?php

namespace frontend\tests\functional;

use frontend\tests\FunctionalTester;

class SignupCest
{
    protected $formId = '#form-signup';

    /**
     * The signup form is reached by choosing a role on the role-choice screen.
     * Those buttons are data-method="post" links (JS), so the POST of the
     * top-level "role" is sent directly here via the postAsLink helper.
     */
    public function _before(FunctionalTester $I)
    {
        $I->amOnRoute('site/signup');
        $I->see('client account');
        $I->postAsLink(['/site/signup'], ['role' => 'client']);
        $I->seeElement($this->formId);
    }

    public function signupWithEmptyFields(FunctionalTester $I)
    {
        $I->submitForm($this->formId, []);
        $I->seeElement($this->formId);
        $I->seeValidationError('Username cannot be blank.');
        $I->seeValidationError('Email cannot be blank.');
        $I->seeValidationError('Password cannot be blank.');
    }

    public function signupWithWrongEmail(FunctionalTester $I)
    {
        $I->submitForm($this->formId, [
            'SignupForm[username]' => 'tester',
            'SignupForm[email]' => 'ttttt',
            'SignupForm[password]' => 'tester_password',
        ]);
        $I->dontSee('Username cannot be blank.', '.invalid-feedback');
        $I->dontSee('Password cannot be blank.', '.invalid-feedback');
        $I->see('Email is not a valid email address.', '.invalid-feedback');
    }

    /**
     * Regression: submitting the form (which posts SignupForm[role], not a
     * top-level role) used to bounce back to the role-choice screen.
     */
    public function signupSuccessfully(FunctionalTester $I)
    {
        $I->submitForm($this->formId, [
            'SignupForm[username]' => 'tester',
            'SignupForm[email]' => 'tester.email@example.com',
            'SignupForm[full_name]' => 'Test Er',
            'SignupForm[phone_number]' => '0712345678',
            'SignupForm[password]' => 'tester_password',
            'SignupForm[confirmPassword]' => 'tester_password',
            'SignupForm[consent_data]' => '1',
            'SignupForm[consent_reminders]' => '0',
        ]);

        $I->seeRecord('common\models\User', [
            'username' => 'tester',
            'email' => 'tester.email@example.com',
            'role' => 'client',
            'status' => \common\models\User::STATUS_INACTIVE,
        ]);

        $I->seeEmailIsSent();
        $I->see('Thank you for registration. Please check your inbox for verification email.');
    }
}
