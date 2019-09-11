<?php
namespace common\modules\ajax\controllers\backend;

use backend\controllers\BaseController;
use common\models\Addresses;
use common\models\Cars;
use common\models\entities\Driver;
use common\models\Orders;
use common\models\OrdersStatuses;
use Yii;
use yii\web\Response;

class DefaultController extends BaseController
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => \yii\filters\AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['admin', 'booker', 'operator'],
                    ],
                    [
                        'allow' => true,
                        'actions' => [
                            'taxis-set-driver-car',
                            'taxis-unset-driver-car',
                            'whereis-driver',
                            'invoices-add-item',
                            'change-item',
                            'save-invoice',
                            'orders-market'
                        ],
                        'roles' => ['taxis', 'taxis-operator'],
                    ],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->request->enableCsrfValidation = false;

        return parent::beforeAction($action);
    }

    /**
     * Поиск водителей по позывному
     *
     * @return array
     */
    public function actionSearchDriverByCallsign()
    {
        $result = [];
        $query = Yii::$app->request->get('query');
        $drivers = Driver::find()
            ->where(['LIKE', 'callsign', $query])
            ->all();

        if (!empty($drivers)) {
            foreach ($drivers as $driver) {
                $result[] = [
                    'id' => $driver->id,
                    'callsign' => $driver->callsign,
                    'name' => $driver->rootEntity->fullName,
                ];
            }
        }
        return $result;
    }

    public function actionDriverCarForOrder()
    {
        $result = [];
        $orderId = Yii::$app->request->get('orderId');
        $driverId = Yii::$app->request->get('driverId');

        $order = Orders::findOne($orderId);
        $driver = Driver::findOne($driverId);
        $carsByClasses = $driver->carsByClasses;

        if (!empty($carsByClasses)) {
            /**
             * @var integer $classId
             * @var Cars $cars
             */
            foreach ($carsByClasses as $classId => $cars) {
                if (!empty($cars) && $classId >= $order->car_class_id) {
                    foreach ($cars as $car) {
                        $result[] = [
                            'id' => $car->id,
                            'modelName' => $car->modelName
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Назначение машины, водителю таксопарка
     *
     * @return array
     */
    public function actionTaxisSetDriverCar()
    {
        $driverId = (int)Yii::$app->request->post('driverId');
        $carId = (int)Yii::$app->request->post('carId');

        $driver = Driver::findOne(['id' => $driverId, 'taxis_id' => $this->taxis()->id]);
        $car = Cars::find()
            ->where(['id' => $carId, 'taxis_id' => $this->taxis()->id])
            ->andWhere(['OR', ['driver_id' => null], ['driver_id' => 0]])
            ->one();

        if (empty($driver)) {
            return [
                'success' => false,
                'error' => 'Нельзя назначить этого водителя',
            ];
        }

        if (empty($car)) {
            return [
                'success' => false,
                'error' => 'Нельзя назначить эту машину',
            ];
        }

        $car->driver_id = $driverId;

        return ['success' => $car->save()];
    }

    /**
     * Открепление водителя таксопарка от машины
     *
     * @return array
     */
    public function actionTaxisUnsetDriverCar()
    {
        $carId = (int)Yii::$app->request->post('carId');
        $car = Cars::findOne(['id' => $carId, 'taxis_id' => $this->taxis()->id]);

        if (empty($car)) {
            return [
                'success' => false,
                'error' => 'Нельзя открепить водителя от этой машины',
            ];
        }

        $car->driver_id = null;
        $result = $car->save();

        return ['success' => $result];
    }

    public function actionWhereisDriver()
    {
        $minutesBetweenRequest = 1;
        $driver = Driver::findOne(['id' => Yii::$app->request->get('driverId'), 'taxis_id' => $this->taxis()->id]);

        if (empty($driver)) {
            return [
                'success' => false,
                'error' => 'Водитель не найден',
            ];
        }

        $lastLocation = DriversGeo::find()->where(['driver_id' => $driver->id])->orderBy(['created_at' => SORT_DESC])->one();

        if (!empty($lastLocation) && time() - $lastLocation->created_at < $minutesBetweenRequest * 60) {
            return [
                'success' => false,
                'error' => 'Вы недавно уже запрашивали координаты этого водителя',
            ];
        }

        $gcmRegistration = GcmRegistrations::findOne(['userLogin', $driver->rootEntity->user->LOGIN]);
        if (!empty($gcmRegistration)) {
            $push = new Push();
            $result = $push->command(
                'getcoordinates',
                [$gcmRegistration->regId],
                ['orderId' => null]);

            for ($s = 0; $s < 30; $s++) {
                sleep(2);
                $newLastLocation = DriversGeo::find()->where(['driver_id' => $driver->id])->orderBy(['created_at' => SORT_DESC])->one();
                if ($newLastLocation->created_at > $lastLocation->created_at) {
                    return [
                        'success' => true,
                        'lat' => $newLastLocation->lat,
                        'lng' => $newLastLocation->lng,
                    ];
                }
            }
        } else {
            return [
                'success' => false,
                'error' => 'У водителя скорее всего не установлено приложение',
            ];
        }

        return [
            'success' => false,
            'error' => 'Не удалось определить координаты. Попробуйте позже',
        ];
    }

    public function actionInvoicesAddItem()
    {
        $invoiceId = Yii::$app->request->post('invoiceId');
        $itemId = Yii::$app->request->post('itemId');

        if (Yii::$app->user->can('admin') || Yii::$app->user->can('booker')) {
            $invoice = Invoices::findOne($invoiceId);
        }

        if (Yii::$app->user->can('taxis')) {
            $invoice = Invoices::findOne([
                'id' => $invoiceId,
                'entity_id' => $this->taxis()->id,
                'status_id' => InvoicesStatuses::NEW_ID
            ]);
        }

        if (empty($invoice)) {
            return [
                'success' => false,
                'errors' => ['Счет не найден'],
            ];
        }

        $item = PurchasedItems::findOne($itemId);

        if (empty($item)) {
            return [
                'success' => false,
                'errors' => ['Товар/услуга не найдена'],
            ];
        }

        if (in_array($invoice->entity->type_id, $item->entitiesTypesIds) == false) {
            return [
                'success' => false,
                'errors' => ['В этот счет нельзя добавить эту позицию'],
            ];
        }

        $invoiceItem = new InvoicesItems();
        $invoiceItem->purchased_item_id = $itemId;
        $invoiceItem->invoice_id = $invoiceId;
        $invoiceItem->price = $item->price;
        $invoiceItem->count = 1;
        if ($invoiceItem->save()) {
            return [
                'success' => true,
                'errors' => []
            ];
        } else {
            $modelErrors = $invoiceItem->getErrors();
            $errorMsg = [];
            foreach ($modelErrors as $attribute => $errors) {
                foreach ($errors as $error) {
                    $errorMsg[] = $error;
                }
            }
            return [
                'success' => false,
                'errors' => $errorMsg
            ];
        }
    }

    public function actionChangeItem()
    {
        $itemId = Yii::$app->request->post('itemId');
        $price = (float)Yii::$app->request->post('price');
        $count = (int)Yii::$app->request->post('count');

        $item = InvoicesItems::findOne($itemId);
        if (empty($item)) {
            return [
                'success' => false,
                'errors' => ['Запись не найдена'],
            ];
        }

        if (Yii::$app->user->can('taxis') && ($item->invoice->entity_id != $this->taxis()->id)) {
            return [
                'success' => false,
                'errors' => ['Счет не найден'],
            ];
        }

        if ($item->invoice->status_id != InvoicesStatuses::NEW_ID) {
            return [
                'success' => false,
                'errors' => ['Этот счет нельзя редактировать'],
            ];
        }

        if ($item->purchasedItem->fixed_price == true && $item->price != $price) {
            return [
                'success' => false,
                'errors' => ['Для этой записи нельзя изменить стоимость'],
            ];
        }

        $item->price = $price;
        $item->count = $count;
        if ($item->save()) {
            return [
                'success' => true,
                'errors' => []
            ];
        } else {
            $modelErrors = $item->getErrors();
            $errorMsg = [];
            foreach ($modelErrors as $attribute => $errors) {
                foreach ($errors as $error) {
                    $errorMsg[] = $error;
                }
            }
            return [
                'success' => false,
                'errors' => $errorMsg
            ];
        }
    }

    public function actionSaveInvoice()
    {
        $invoiceNum = Yii::$app->request->post('invoiceNum');
        if (empty($invoiceNum) || !preg_match('|^\d{1,10}-\d{1,10}$|i', $invoiceNum, $match)) {
            return [
                'success' => false,
                'errors' => ['Не указан № счета']
            ];
        }

        list($num, $entity_id) = explode('-', $invoiceNum);

        if (Yii::$app->user->can('taxis') && $entity_id != $this->taxis()->id) {
            return [
                'success' => false,
                'errors' => ['Счет не найден']
            ];
        }

        $invoice = Invoices::findOne([
            'num' => $num,
            'entity_id' => $entity_id,
            'status_id' => InvoicesStatuses::NEW_ID
        ]);
        if (empty($invoice)) {
            return [
                'success' => false,
                'errors' => ['Счет не найден']
            ];
        }

        if (empty($invoice->items)) {
            return [
                'success' => false,
                'errors' => ['Нельзя сохранить пустой счет']
            ];
        }

        $invoice->status_id = InvoicesStatuses::WAIT_FOR_PAYMENT;
        if ($invoice->save()) {
            return [
                'success' => true,
                'errors' => []
            ];
        } else {
            $result = [
                'success' => false,
                'errors' => []
            ];
            $modelErrors = $invoice->getErrors();
            if (!empty($modelErrors)) {
                foreach ($modelErrors as $attribute => $errors) {
                    foreach ($errors as $error) {
                        $result['errors'][] = $error;
                    }
                }
            }

            return $result;
        }
    }

    public function actionInvoiceSetPaid()
    {
        $invoice = Invoices::findOne([
            'id' => Yii::$app->request->post('invoiceId'),
            'status_id' => InvoicesStatuses::WAIT_FOR_PAYMENT
        ]);
        if (empty($invoice)) {
            return [
                'success' => false,
                'errors' => ['Счет не найден']
            ];
        }

        $invoice->paid_date = strtotime(Yii::$app->request->post('date'));
        $invoice->status_id = InvoicesStatuses::PAID_ID;
        if ($invoice->save()) {
            return [
                'success' => true,
                'errors' => []
            ];
        } else {
            $result = [
                'success' => false,
                'errors' => []
            ];
            $modelErrors = $invoice->getErrors();
            if (!empty($modelErrors)) {
                foreach ($modelErrors as $attribute => $errors) {
                    foreach ($errors as $error) {
                        $result['errors'][] = $error;
                    }
                }
            }

            return $result;
        }
    }

    public function actionOrdersMarket()
    {
        $orderBy = Yii::$app->request->get('orderby', 'datetime');
        $startDate = Yii::$app->request->get('startDate');
        $finishDate = Yii::$app->request->get('finishDate');

        $driverCarsClasses = array_keys($this->taxis()->carsClasses);
        $allowedClasses = [];
        if (!empty($driverCarsClasses)) {
            foreach ($driverCarsClasses as $driverCarClassId) {
                foreach (Orders::$allowedCarClasses as $classId => $linkedClassesId) {
                    if (in_array($driverCarClassId, $linkedClassesId) && !in_array($driverCarsClasses,
                            $allowedClasses)
                    ) {
                        $allowedClasses[] = $classId;
                    }
                }
            }
        }

        $ordersQuery = Orders::find()
            ->where([
                'status_id' => OrdersStatuses::STATUS_DRIVER_SEARCH_ID,
            ])
            ->andWhere(['>=', 'datetime', date('Y-m-d H:i:s', time() + 10 * 60)])
            ->andWhere(['IN', 'car_class_id', $allowedClasses])
            ->orderBy([isset($this->_orderByFields[$orderBy]) ? $this->_orderByFields[$orderBy] : 'datetime' => SORT_ASC]);

        /*
         * Фильтр по дате отправления
         */
        if (!empty($startDate) && !empty($finishDate)) {
            $ordersQuery->andWhere([
                'between',
                'datetime',
                date('Y-m-d H:i:s', strtotime($startDate)),
                date('Y-m-d H:i:s', strtotime($finishDate) + 86400)
            ]);
        }


        $result = ['type' => 'FeatureCollection', 'features' => []];
        $orders = $ordersQuery->all();
        if (!empty($orders)) {
            foreach ($orders as $order) {
                $startPoint = $order->route[0]->address_id == Addresses::UNKNOWN_ADDRESS_ID
                    ? $order->route[0]->city
                    : $order->route[0]->address;
                $result['features'][] = [
                    'type' => 'Feature',
                    'id' => $order->ID,
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$startPoint->lat, $startPoint->lng],
                        'properties' => [
                            'balloonContentHeader' => "<font size=3><b><a target='_blank' href='https://yandex.ru'>Здесь может быть ваша ссылка</a></b></font>",
                            'balloonContentBody' => 'balloonContentBody',
                            'balloonContentFooter' => 'balloonContentFooter',
                            'clusterCaption' => 'Метка ' . $order->slug,
                            'hintContent' => 'hintContent',
                        ]
                    ]
                ];
            }
        }

        return $result;
    }

    public function actionGetCByCityId(int $id)
    {
        $data = [];
        Yii::$app->response->format = Response::FORMAT_JSON;
        $city = Cities::findOne($id);

        if ($city) {
            $data = [
                'lat' => $city->lat,
                'lng' => $city->lng
            ];
        }

        return $data;
    }

    /**
     * @param integer $id
     * @param string $point ('city_a', 'city_b')
     */
    public function actionGetClearCities($id, $point)
    {
        $request = Yii::$app->request->get('searchString');
        
    }
}