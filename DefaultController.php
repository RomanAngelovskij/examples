<?php

namespace modules\payment\controllers\frontend;

use modules\payment\service\RateAvailabilityChecker;
use modules\payment\service\TinkoffPayment;
use modules\payment\service\YandexPayment;
use yii;
use yii\web\Controller;
use modules\payment\Module;
use yii\filters\VerbFilter;
use yii\data\ActiveDataProvider;
use modules\payment\models\frontend\Payment;
use modules\payment\models\frontend\forms\WebMoney;
use modules\payment\models\frontend\forms\WalletOne;
use modules\payment\models\frontend\forms\Interkassa;
use modules\payment\models\frontend\Rate;
use modules\account\models\frontend\Account;

/**
 * DefaultController implements the CRUD actions for Payment model.
 */
class DefaultController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'pay-interkassa' => ['POST'],
                    'pay-webmoney' => ['POST'],
                    'pay-wallet-one' => ['POST'],
                    'pay-tinkoff' => ['POST'],
                    'pay-yandex' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function beforeAction($action)
    {
        if ($action->id == in_array($action->id, ['pay-interkassa', 'pay-webmoney', 'pay-wallet-one', 'pay-tinkoff', 'pay-yandex'])) {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * @return string|yii\web\Response
     *
     * @throws yii\web\BadRequestHttpException
     */
    public function actionIndex()
    {
        $payAmount = Yii::$app->request->get('amount', 850);

        $webMoney = new WebMoney(['sum' => $payAmount]);
        $interkassa = new Interkassa(['sum' => $payAmount]);
        $walletOne = new WalletOne(['sum' => $payAmount]);

        $paymentHistoryDataProvider = new ActiveDataProvider([
            'query' => Payment::find()->where(['user_id' => Yii::$app->user->id]),
            'sort' => [
                'defaultOrder' => ['id' => SORT_DESC],
            ],
            'pagination' => [
                'pageSize' => 5,
            ],
        ]);

        $accountDataProvider = new ActiveDataProvider([
            'query' => Account::find()->where([
                'hide' => 0,
                'user_id' => Yii::$app->user->id,
            ]),
            'sort' => [
                'defaultOrder' => ['id' => SORT_DESC],
            ],
            'pagination' => false,
        ]);

        $ratesList = Rate::find()
            ->where(['hide' => 0])
            ->orderBy(['sort' => SORT_ASC])
            ->all();

        if ($walletOne->load(Yii::$app->request->post()) && $walletOne->pay()) {
            return $this->redirect($walletOne->url);
        } elseif ('success' == Yii::$app->request->get('type')) {
            /*
             * Отправляем цель в метрику
             */
            if (Yii::$app->user->identity->balance > 0) {
                Yii::$app->session->setFlash('goal_pay_balance');
            }

            Yii::$app->session->setFlash('success', Module::t('main', 'PAYMENT_SUCCESS'));

            return $this->redirect(['index']);
        } elseif ('error' == Yii::$app->request->get('type')) {
            Yii::$app->session->setFlash('error', Module::t('main', 'PAYMENT_ERROR'));

            return $this->redirect(['index']);
        } elseif ('wait' == Yii::$app->request->get('type')) {
            Yii::$app->session->setFlash('success', Module::t('main', 'PAYMENT_WAIT'));

            return $this->redirect(['index']);
        }

        return $this->render('index', [
            'webMoney' => $webMoney,
            'walletOne' => $walletOne,
            'interkassa' => $interkassa,
            'paymentHistoryDataProvider' => $paymentHistoryDataProvider,
            'payAmount' => $payAmount,
            'accountDataProvider' => $accountDataProvider,
            'rateModel' => null,
            'totalAccount' => $accountDataProvider->totalCount,
            'rateAvailabilityChecker' => new RateAvailabilityChecker(),
            'rates' => $ratesList,
        ]);
    }

    /**
     * Подтверждение оплаты Interkassa.
     *
     * @param $key
     *
     * @return string
     */
    public function actionPayInterkassa($key)
    {
        $secret = 'daGe52Fs321cd';
        $model = new Interkassa();

        if ($key != $secret) {
            return 'Неверный секретный ключ!';
        } else {
            $model->save(Yii::$app->request->post());
        }

        return $model->message;
    }

    /**
     * Подтверждение оплаты WebMoney.
     *
     * @param $key
     *
     * @return string
     */
    public function actionPayWebmoney($key)
    {
        $secret = 'sd3tsfdgRE4Dsdf';
        $model = new WebMoney();

        if ($key != $secret) {
            return 'Неверный секретный ключ!';
        } else {
            $model->save(Yii::$app->request->post());
        }

        return $model->message;
    }

    /**
     * Подтверждение оплаты WalletOne.
     *
     * @param $key
     *
     * @return string
     */
    public function actionPayWalletOne($key)
    {
        $secret = 'f5Bsdg57SDvk';
        $model = new WalletOne();

        if ($key != $secret) {
            Yii::error('Не указан секетный ключ!', 'payment');

            return 'WMI_RESULT=RETRY&WMI_DESCRIPTION='.urlencode('Не указан секетный ключ!');
        } elseif ($model->save(Yii::$app->request->post())) {
            return 'WMI_RESULT=OK&WMI_DESCRIPTION='.urlencode($model->message);
        }

        return 'WMI_RESULT=RETRY&WMI_DESCRIPTION='.urlencode($model->message);
    }

    /**
     * @return string
     */
    public function actionPayTinkoff()
    {
        $paymentProcess = new TinkoffPayment(Yii::$app->params['tinkoffPayment'], Yii::$app->request);

        if ($paymentProcess->processCallback()) {
            return TinkoffPayment::RESPONSE_STATUS_PROCESSED;
        }
    }

    /**
     * @return string
     */
    public function actionPayYandex()
    {
        $paymentProcess = new YandexPayment(Yii::$app->params['yandexPayment'], Yii::$app->request);

        if ($paymentProcess->processCallback()) {
            return YandexPayment::RESPONSE_STATUS_PROCESSED;
        }
    }
}
