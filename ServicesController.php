<?php
namespace frontend\controllers\books;

use frontend\models\Services\ServiceCostRoute;
use Yii;
use frontend\models\Services\ServiceCost;
use frontend\models\Services\Forms\ServiceCostForm;
use frontend\models\Services\Forms\ServiceCostSearch;
use common\models\Services\ServiceCostProduct;
use yii\web\NotFoundHttpException;
use app\modules\users\components\UsersController;

class ServicesController extends UsersController
{
	
	public function actions()
	{
		return \yii\helpers\ArrayHelper::merge(parent::actions(), [
			'products' => [
				'class' => \kartik\depdrop\DepDropAction::className(),
				'outputCallback' => function ($selectedId, $params) {
					$list = ServiceCostSearch::getProductsList($selectedId);
					$data = [];
					foreach ($list as $key=>$text) {
						$data[] = ['id'=>$key, 'name'=>$text];
					}
					return $data;
				}
			]
		]);
	}
    /**
     * @return string
     */
    public function actionIndex()
    {
        $searchModel = new ServiceCostSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * @return string
     * @throws NotFoundHttpException
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function actionCreate()
    {
        if (!Yii::$app->request->isAjax) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        $model = new ServiceCost();
        $form   = new ServiceCostForm();
        $form->initialization($model);
        $form->load(\Yii::$app->request->post());

        if ($form->load(\Yii::$app->request->post()) && $form->release()  && $form->save()) {
            $form->model->refresh();
            ServiceCostProduct::updateProductsLinks($form->model->cost_id, $form->productsIds);
            ServiceCostRoute::updateRoutesLinks($form->model->cost_id, $form->routesIds);

            return $this->renderAjax('view', [
                'model' => $form->model,
            ]);
        }

        $form->fixAttributes();

        return $this->renderAjax('create', [
            'action' => 'create',
            'model' => $form,
        ]);
    }

    /**
     * @param int $id
     * @return string
     * @throws NotFoundHttpException
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function actionUpdate($id)
    {
        if (!Yii::$app->request->isAjax) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        $model = $this->findModel($id);
        $form   = new ServiceCostForm();
        $form->initialization($model);

        if ($form->load(\Yii::$app->request->post()) && $form->release()  && $form->save()) {
            ServiceCostProduct::updateProductsLinks($id, $form->productsIds);
            ServiceCostRoute::updateRoutesLinks($id, $form->routesIds);
            $form->model->refresh();

            return $this->renderAjax('view', [
                'model' => $form->model,
            ]);
        }

        $form->fixAttributes();
        $form->setProductsList();
        $form->setRoutesList();

        return $this->renderAjax('update', [
            'action' => '/books/services/update?id='.$id,
            'model' => $form,
        ]);
    }

    /**
     * @param $id
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionView($id)
    {
        if (!Yii::$app->request->isAjax) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        $model = $this->findModel($id);
        return $this->renderAjax('view', [
            'model' => $model,
        ]);
    }

    /**
     * @param int $id
     * @return mixed
     * @throws NotFoundHttpException
     */
    protected function findModel($id)
    {
        if (($model = ServiceCost::findMany(['cost_id'=>$id])->joinWith('products')->addRoleConditions()->one()) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }
}