<?php
namespace modules\payment\controllers\frontend;

use modules\payment\service\promo\BasePromo;
use modules\payment\service\promo\Promo4ThMonthFree;
use modules\payment\service\RateAvailabilityChecker;
use modules\payment\service\rateConnectStrategy\DefaultStrategy;
use yii;
use yii\helpers\Html;
use yii\web\Controller;
use modules\payment\Module;
use yii\filters\VerbFilter;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use modules\payment\models\frontend\Rate;
use modules\account\models\frontend\Account;

/**
 * RateController implements the CRUD actions for Rate model.
 */
class RateController extends Controller
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
                    'index' => [Yii::$app->request->get('rate_id') ? 'POST' : 'GET'],
                ],
            ],
        ];
    }

    /**
     * @param $rate_id integer
     *
     * @return yii\web\Response
     */
    public function actionIndex($rate_id = 0)
    {
        $model = null;
        $rate = Rate::find()
            ->where(['hide' => 0])
            ->orderBy(['sort' => SORT_ASC])
            ->all();
        $dataProvider = new ActiveDataProvider([
            'query' => Account::find()->where([
                'hide' => 0,
                'user_id' => Yii::$app->user->id,
            ]),
            'sort' => [
                'defaultOrder' => ['id' => SORT_DESC],
            ],
            'pagination' => false,
        ]);

        if ($rate_id) {
            $model = $this->findModel($rate_id);
            $model->setMonthDuration((int) Yii::$app->request->get('duration'));
            $model->setConnectStrategy(new DefaultStrategy());
            $model->save_accounts = Yii::$app->request->post('selection');

            BasePromo::connectPromoToRateByAlias(Yii::$app->request->get('promo', ''), $model);

            if ($model->connect()) {
                Yii::$app->session->setFlash('success', Module::t('rate', 'MESSAGE_SUCCESS_CONNECT_{name}', [
                    'name' => Html::encode($model->name),
                ]));

                return $this->redirect(['/payment']);
            } elseif ($model->error) {
                Yii::$app->session->setFlash('error', $model->error);

                return $this->redirect(['index']);
            } elseif ($model->warning) {
                Yii::$app->session->setFlash('rate-connect-error', $model->warning);
            }
        }

        return $this->render('index', [
            'rate' => $rate,
            'rateModel' => $model,
            'dataProvider' => $dataProvider,
            'totalAccount' => $dataProvider->totalCount,
            'rateAvailabilityChecker' => new RateAvailabilityChecker(),
            'is4thMonthFreePromoAvailable' => (new Promo4ThMonthFree())->isAvailable(),
        ]);
    }

    /**
     * @param $id
     *
     * @return Rate
     *
     * @throws NotFoundHttpException
     */
    protected function findModel($id)
    {
        $model = Rate::findOne([
            'id' => $id,
            'hide' => 0,
        ]);

        if (null !== $model) {
            return $model;
        } else {
            throw new NotFoundHttpException(Yii::t('app', 'ERROR_404'));
        }
    }
}
