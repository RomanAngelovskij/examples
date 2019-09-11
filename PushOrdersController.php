<?php
namespace frontend\controllers\api;
use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\Controller;
use yii\db\Expression;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\helpers\Url;
use yii\data\ActiveDataProvider;
use yii\web\ServerErrorHttpException;

use common\models\API\ApiLog;
use common\models\API\Status;

use lib\based\Messenger;
use lib\based\Digits;

class PushOrdersController extends Controller
{
    private $messenger;
//*
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => [],
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['index'],
                        'roles' => ['?', '@'],
                    ],
                ],
            ],
        ];
    }
//*/
    public function init()
    {
        $this->messenger = new Messenger(1,false, 'txt');
        $this->enableCsrfValidation = false;
    }

    public function actionIndex()
    {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        if (!\Yii::$app->request->isPost) {
            throw new ServerErrorHttpException('Not exists.');
        }
        $orders = \Yii::$app->request->post();
        Yii::warning($orders, 'api/push-orders');
        return ['message' => 'Ok.'];
    }
}