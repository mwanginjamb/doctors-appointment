<?php

namespace frontend\tests\functional;

use frontend\tests\FunctionalTester;

class HomeCest
{
    public function homePageShowsTheDoctorSearch(FunctionalTester $I)
    {
        $I->amOnRoute('site/index');
        $I->seeResponseCodeIs(200);
        $I->see('Find a Physician', 'h1');
        $I->seeElement('form button[type=submit]');
    }

    public function aboutPageOpens(FunctionalTester $I)
    {
        // The About link is not in the navigation; the page is still served
        $I->amOnRoute('site/about');
        $I->see('This is the About page.');
    }
}
