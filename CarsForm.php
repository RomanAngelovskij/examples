<?php
namespace common\modules\api\models\frontend;

use common\models\Cars;
use common\models\CarsBrands;
use common\models\CarsModels;
use common\models\CarsTypes;
use common\models\entities\Driver;
use common\models\CarsClasses;
use yii\base\Model;

class CarsForm extends Model{

    public $carClassId;

    public $saloon;

    public $brand;

    public $model;

    public $number;

    public $year;

    public $color;

    public $modelId;

    public $driverId;

    public $carId;

    public function scenarios()
    {
        return [
            'add' => ['saloon', 'brand', 'model', 'number', 'driverId', 'year', 'color'],
            'update' => ['carClassId', 'saloon', 'number', 'driverId'],
        ];
    }

    public function rules()
    {
        return [
            [['saloon', 'brand', 'model', 'number', 'year', 'color'], 'required', 'on' => 'add'],
            ['driverId', 'validateDriver', 'on' => 'add'],
            ['carClassId', 'default', 'value' => CarsClasses::ECONOM_ID],
            ['carClassId', 'exist', 'targetClass' => CarsClasses::class, 'targetAttribute' => 'id'],
            ['saloon', 'exist', 'targetClass' => CarsTypes::class, 'targetAttribute' => 'id'],
            [['brand', 'model', 'year', 'color'], 'trim'],
            ['year', 'integer', 'min' => 2000, 'max' => date('Y')],
            ['model', 'processModel'],
        ];
    }

    public function processModel($attribute)
    {
        if (!$this->hasErrors()){
            $brand = CarsBrands::findOne(['name' => $this->brand]);

            if (empty($brand)) {
                $this->addError('brand', 'В нашем списке нет такого производителя');
                return false;
            }

            $model = CarsModels::findOne(['brand_id' => $brand->id, 'name' => $this->model]);

            if (empty($model)){
                $this->addError('model', 'В нашем списке нет такой марки автомобился');
            } else {
                $this->modelId = $model->id;
            }
        }
    }

    public function validateDriver($attribute){
        if (!$this->hasErrors()){
            $driver = Driver::findOne($this->driverId);
            if (empty($driver)){
                $this->addError('driverId', 'Водитель не найден');
                return false;
            }

            if ($driver->inTaxis()){
                $this->addError('driverId', 'Водитель таксопарка не может добавлять (редактировать) авто');
                return false;
            }
        }
    }

    public function save()
    {
        if ($this->validate() === false){
            return false;
        }

        if ($this->scenario == 'add'){
            $carModel = new Cars();
            $carModel->model_id = $this->modelId;
            $carModel->type_id = $this->saloon;
            $carModel->class_id = $this->carClassId;
            $carModel->number = $this->number;
            $carModel->color = $this->color;
            $carModel->year = $this->year;
            $carModel->driver_id = $this->driverId;
            $carModel->taxis_id = null;
            $carModel->active = true;

            if ($carModel->save() == true){
                $this->carId = $carModel->id;
                return true;
            } else {
                $this->addErrors($carModel->getErrors());
                return false;
            }
        }

        return false;
    }
}