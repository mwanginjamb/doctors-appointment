<?php

namespace console\controllers;

use common\components\Mpesa;
use common\components\MpesaException;
use common\models\MpesaTransaction;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * M-Pesa (Daraja), T11: check the set-up, try payments on the sandbox, and settle STK payments
 * whose callback never came.
 */
class MpesaController extends Controller
{
    /**
     * Shows the settings (secrets hidden) and asks Daraja for an access token, which proves the
     * consumer key and secret.
     *
     * Usage: php yii mpesa/check
     */
    public function actionCheck()
    {
        $mpesa = $this->mpesa();
        $this->stdout("Environment:   {$mpesa->env} ({$mpesa->baseUrl()})\n");
        $this->stdout('Shortcode:     ' . ($mpesa->shortcode ?: '(not set)') . " ({$mpesa->transactionType})\n");
        $this->stdout('Passkey:       ' . ($mpesa->passkey ? 'set' : '(not set)') . "\n");
        $this->stdout('B2C:           ' . ($mpesa->isB2cConfigured() ? "set (shortcode {$mpesa->b2cShortcode}, initiator {$mpesa->initiatorName})" : 'not set') . "\n");
        try {
            $address = $mpesa->callbackAddress('stk');
            $this->stdout('Callbacks:     ' . str_replace($mpesa->callbackToken, '<MPESA_CALLBACK_TOKEN>', $address) . "\n");
        } catch (MpesaException $e) {
            $this->stdout('Callbacks:     ' . $e->getMessage() . "\n", Console::FG_YELLOW);
        }

        try {
            $mpesa->accessToken(true);
            $this->stdout("Access token:  OK, the consumer key and secret work\n", Console::FG_GREEN);
            return ExitCode::OK;
        } catch (MpesaException $e) {
            $this->stderr('Access token:  ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
    }

    /**
     * Sends an STK push to a phone, then follows it by status query until it ends (or 2 minutes
     * pass), then waits up to 15 seconds more for the receipt, which only the callback brings. On
     * the sandbox the prompt reaches the phone but no money moves.
     *
     * Usage: php yii mpesa/test-stk 0712345678 [amount=1]
     */
    public function actionTestStk($phone, $amount = 1)
    {
        $mpesa = $this->mpesa();
        try {
            $transaction = $mpesa->stkPush($phone, $amount, 'HealthFirst', 'Test payment', ['type' => 'test']);
        } catch (MpesaException | \InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        if ($transaction->isSettled()) {
            $this->stderr("Refused: {$transaction->result_desc}\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("Sent to {$transaction->phone} for KES {$transaction->amount} (transaction {$transaction->id}).\n");
        $this->stdout("Answer the prompt on the phone. Waiting");
        for ($i = 0; $i < 24; $i++) {
            sleep(5);
            $transaction->refresh();
            if ($transaction->isPending()) {
                try {
                    $mpesa->refreshStk($transaction);
                } catch (MpesaException $e) {
                    // the query failed this time; try again
                }
            }
            if ($transaction->isSettled()) {
                break;
            }
            $this->stdout('.');
        }
        $this->stdout("\n");

        // A status query can settle the payment before Safaricom's callback brings the receipt
        if ($transaction->status === MpesaTransaction::STATUS_SUCCEEDED && !$transaction->receipt) {
            $this->stdout('Paid; waiting for the receipt from the callback');
            for ($i = 0; $i < 15 && !$transaction->receipt; $i++) {
                sleep(1);
                $transaction->refresh();
                $this->stdout('.');
            }
            $this->stdout("\n");
            if (!$transaction->receipt) {
                $this->stdout("No callback yet; check later with: php yii mpesa/status {$transaction->id}\n", Console::FG_YELLOW);
            }
        }

        return $this->report($transaction);
    }

    /**
     * Pays a phone from the B2C shortcode. The result arrives at the b2c-result callback, so
     * MPESA_CALLBACK_URL must reach this site; see it with `php yii mpesa/status <id>`.
     *
     * Usage: php yii mpesa/test-b2c 0712345678 [amount=10]
     */
    public function actionTestB2c($phone, $amount = 10)
    {
        try {
            $transaction = $this->mpesa()->b2cPayment($phone, $amount, 'Test payout', 'Test', ['type' => 'test']);
        } catch (MpesaException | \InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        if ($transaction->isSettled()) {
            return $this->report($transaction);
        }
        $this->stdout("Accepted by Safaricom (transaction {$transaction->id}). The result comes by callback: php yii mpesa/status {$transaction->id}\n");

        return ExitCode::OK;
    }

    /**
     * One transaction's status.
     *
     * Usage: php yii mpesa/status <id>
     */
    public function actionStatus($id)
    {
        $transaction = MpesaTransaction::findOne((int) $id);
        if ($transaction === null) {
            $this->stderr("No transaction $id.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        return $this->report($transaction);
    }

    /**
     * Settles STK payments still pending after a minute, by status query, for when Safaricom's
     * callback did not reach us. Safe to run often; a timer can run it every few minutes. Queries are
     * spaced 2 seconds apart for Daraja's rate limit, so one run handles at most 60.
     *
     * Usage: php yii mpesa/reconcile
     */
    public function actionReconcile()
    {
        $mpesa = $this->mpesa();
        $pending = MpesaTransaction::find()
            ->where(['kind' => MpesaTransaction::KIND_STK, 'status' => MpesaTransaction::STATUS_PENDING])
            ->andWhere(['<', 'created_at', time() - 60])
            ->andWhere(['not', ['checkout_request_id' => null]])
            ->orderBy(['id' => SORT_ASC])
            ->limit(60)
            ->all();

        $settled = 0;
        foreach ($pending as $i => $transaction) {
            if ($i > 0) {
                sleep(2); // Daraja allows 30 queries a minute, at most 3 at once
            }
            try {
                if ($mpesa->refreshStk($transaction)) {
                    $settled++;
                } elseif ($problem = $mpesa->lastQueryProblem()) {
                    $this->stdout("Transaction {$transaction->id}: $problem\n");
                }
            } catch (MpesaException $e) {
                $this->stderr("Transaction {$transaction->id}: " . $e->getMessage() . "\n", Console::FG_YELLOW);
            }
        }
        $this->stdout(count($pending) . " pending, $settled settled.\n");

        return ExitCode::OK;
    }

    private function report(MpesaTransaction $transaction)
    {
        $colour = $transaction->status === MpesaTransaction::STATUS_SUCCEEDED ? Console::FG_GREEN
            : ($transaction->isPending() ? Console::FG_YELLOW : Console::FG_RED);
        $this->stdout(strtoupper($transaction->kind) . " {$transaction->id}: ");
        $this->stdout($transaction->status, $colour);
        $this->stdout(($transaction->receipt ? ", receipt {$transaction->receipt}" : '')
            . ($transaction->result_desc ? " ({$transaction->result_desc})" : '') . "\n");

        return $transaction->status === MpesaTransaction::STATUS_SUCCEEDED || $transaction->isPending()
            ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** @return Mpesa */
    private function mpesa()
    {
        return Yii::$app->mpesa;
    }
}
