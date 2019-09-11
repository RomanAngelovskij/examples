<?php
use frontend\models\Places\Place;

use Yii;

class Token extends \common\models\API\Access\Token
{

    public function init()
    {
        parent::init();
        $this->placeClass = Place::className();
    }

    /**
     * @inheritdoc
     */
    public function getAttributeLabel($attr)
    {
        return Yii::t('base', parent::getAttributeLabel($attr));
    }

    /**
     * @inheritdoc
     * @return TokenQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new TokenQuery(get_called_class());
    }
}