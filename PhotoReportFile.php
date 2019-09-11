<?php
namespace common\modules\api\models\frontend;

use yii\base\Model;

class PhotoReportFile extends Model {

    public $base64Data;

    public $filePath;

    public function rules()
    {
        return [
            ['base64Data', 'prepareImage']
        ];
    }

    public function prepareImage($attribute)
    {
        preg_match('|^data:image/([a-z]{3,4});|i', $this->base64Data, $fileInfo);

        if (empty($fileInfo)){
            $this->addError($attribute, 'Некорректный формат файла');
            return false;
        }

        $extension = $fileInfo[1];
        $image = str_replace('data:image/jpeg;base64,', '', $this->base64Data);
        $image = str_replace(' ', '+', $image);
        $data = base64_decode($image);
        $folder = rtrim(\Yii::getAlias('@carsPhotoFolder'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . date('d.m.Y');

        if (!file_exists($folder)){
            mkdir($folder, '0777', true);
        }

        $fileName = time() . '_' . rand(1, 9) . '.' . $extension;

        if (file_put_contents($folder . '/' . $fileName, $data) === false){
            $this->addError($attribute, 'Не удалось сохранить фаил на сервер');
            return false;
        }

        $this->filePath = '/photo-reports/' . date('d.m.Y') . '/' . $fileName;
        return true;
    }
}