<?php
// frontend/controllers/DeviceController.php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\models\UserProfile;
use Yii;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use frontend\models\Patients;
use frontend\models\Consultants;

class DeviceController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'], // Only authenticated users
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'register' => ['POST'],
                    'update-token' => ['POST'],
                    'unregister' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Register new device token
     */
    public function actionRegister()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $deviceToken = Yii::$app->request->post('device_token');
        $deviceType = Yii::$app->request->post('device_type', 'web');

        if (empty($deviceToken)) {
            return [
                'success' => false,
                'message' => 'Device token is required'
            ];
        }

        try {
            $user = Yii::$app->user->identity;
            $model = $this->getUserModel($user);

            if (!$model) {
                return [
                    'success' => false,
                    'message' => 'User profile not found'
                ];
            }

            // Update device token
            $model->device_token = $deviceToken;
            $model->device_type = $deviceType;
            $model->token_updated_at = date('Y-m-d H:i:s');

            if ($model->save(false)) {
                Yii::info("Device token registered for user: {$user->id}", __METHOD__);

                return [
                    'success' => true,
                    'message' => 'Device token registered successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to save device token'
            ];

        } catch (\Exception $e) {
            Yii::error("Error registering device token: " . $e->getMessage(), __METHOD__);

            return [
                'success' => false,
                'message' => 'An error occurred while registering device token'
            ];
        }
    }

    /**
     * Update token when it refreshes
     */
    public function actionUpdateToken()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $oldToken = Yii::$app->request->post('old_token');
        $newToken = Yii::$app->request->post('new_token');

        if (empty($oldToken) || empty($newToken)) {
            return [
                'success' => false,
                'message' => 'Both old and new tokens are required'
            ];
        }

        try {
            $user = Yii::$app->user->identity;
            $model = $this->getUserModel($user);

            if (!$model) {
                return [
                    'success' => false,
                    'message' => 'User profile not found'
                ];
            }

            // Verify old token matches
            if ($model->device_token !== $oldToken) {
                Yii::warning("Token mismatch for user: {$user->id}", __METHOD__);
            }

            // Update to new token
            $model->device_token = $newToken;
            $model->token_updated_at = date('Y-m-d H:i:s');

            if ($model->save(false)) {
                Yii::info("Device token updated for user: {$user->id}", __METHOD__);

                return [
                    'success' => true,
                    'message' => 'Device token updated successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to update device token'
            ];

        } catch (\Exception $e) {
            Yii::error("Error updating device token: " . $e->getMessage(), __METHOD__);

            return [
                'success' => false,
                'message' => 'An error occurred while updating device token'
            ];
        }
    }

    /**
     * Unregister device token (on logout)
     */
    public function actionUnregister()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        try {
            $user = Yii::$app->user->identity;
            $model = $this->getUserModel($user);

            if ($model) {
                $model->device_token = null;
                $model->device_type = null;
                $model->token_updated_at = null;
                $model->save(false);

                // Clear from localStorage on client side too
                return [
                    'success' => true,
                    'message' => 'Device token unregistered successfully'
                ];
            }

            return [
                'success' => false,
                'message' => 'User profile not found'
            ];

        } catch (\Exception $e) {
            Yii::error("Error unregistering device token: " . $e->getMessage(), __METHOD__);

            return [
                'success' => false,
                'message' => 'An error occurred'
            ];
        }
    }

    /**
     * Get user model based on user type
     */
    private function getUserModel($user)
    {
        // Assuming your User model has a 'type' or 'role' attribute
        // Adjust this logic based on your actual implementation

        // Option 1: If you have a user type field
        if (isset($user->role)) {
            if ($user->role === 'patient') {
                return UserProfile::findOne(['user_id' => $user->id]);
            } elseif ($user->type === 'consultant') {
                return Consultant::findOne(['user_id' => $user->id]);
            }
        }
    }
}