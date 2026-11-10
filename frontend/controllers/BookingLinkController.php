<?php

namespace frontend\controllers;

use frontend\components\FrontDesk;
use frontend\models\BookingLink;
use frontend\models\BookingLinkForm;
use frontend\models\Consultant;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Consultants and front desk send booking links ("remote walk-ins") and see the ones they sent.
 * A consultant sends links for themselves; front desk for the consultants assigned to them.
 */
class BookingLinkController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function () {
                        return Yii::$app->user->can(\common\components\RbacHelper::PERM_SEND_BOOKING_LINKS);
                    },
                ]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['revoke' => ['POST']],
            ],
        ];
    }

    /** Links I sent, newest first. */
    public function actionIndex()
    {
        $links = BookingLink::find()
            ->where(['created_by' => Yii::$app->user->id])
            ->with(['consultant', 'appointment'])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(50)
            ->all();

        return $this->render('index', ['links' => $links, 'consultants' => $this->bookableConsultants()]);
    }

    /**
     * @param int|null $cid consultant.id; a consultant always sends for themselves
     */
    public function actionCreate($cid = null)
    {
        $form = new BookingLinkForm(['consultant' => $this->findBookableConsultant($cid)]);

        if ($form->load($this->request->post()) && $form->send()) {
            $channels = array_filter([
                $form->link->recipient_phone ? 'WhatsApp' : null,
                $form->link->recipient_email ? 'email' : null,
            ]);
            Yii::$app->session->setFlash('success', Yii::t('app', 'Booking link sent to {name} by {channels}. It works until {time}.', [
                // The main layout shows flashes unescaped
                'name' => \yii\helpers\Html::encode($form->recipient_name),
                'channels' => implode(' and ', $channels),
                'time' => date('H:i', $form->link->expires_at),
            ]));
            // The plain link only exists now (only its hash is stored): show it once so staff can copy it
            return $this->render('sent', ['form' => $form]);
        }

        return $this->render('create', ['form' => $form]);
    }

    public function actionRevoke($id)
    {
        $link = BookingLink::findOne(['id' => $id, 'created_by' => Yii::$app->user->id]);
        if ($link === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Booking link not found.'));
        }
        if ($link->isUsable()) {
            $link->updateAttributes(['revoked_at' => time()]);
            Yii::$app->session->setFlash('success', Yii::t('app', 'The link to {name} no longer works.', ['name' => \yii\helpers\Html::encode($link->recipient_name)]));
        }

        return $this->redirect(['index']);
    }

    /**
     * @return Consultant[] who the current user may send links for
     */
    private function bookableConsultants()
    {
        // Candidates: a consultant's own profile, or the consultants on the user's board ...
        $candidates = Yii::$app->user->identity->isConsultantAccount()
            ? Consultant::find()->where(['user_id' => Yii::$app->user->id])->all()
            : Consultant::find()->andFilterWhere(['user_id' => FrontDesk::consultantUserIds() ?? null])->orderBy(['names' => SORT_ASC])->all();

        // ... that RBAC lets them book for
        return array_values(array_filter($candidates, function (Consultant $consultant) {
            return $consultant->user_id !== null && FrontDesk::canBookFor($consultant->user_id);
        }));
    }

    private function findBookableConsultant($cid)
    {
        $consultants = $this->bookableConsultants();
        if (!$consultants) {
            throw new ForbiddenHttpException(Yii::t('app', 'You have no consultant to send booking links for.'));
        }
        if ($cid === null) {
            return $consultants[0];
        }
        foreach ($consultants as $consultant) {
            if ((int) $consultant->id === (int) $cid) {
                return $consultant;
            }
        }

        throw new ForbiddenHttpException(Yii::t('app', 'You can only send booking links for your own consultants.'));
    }
}
