<?php
namespace app\models;

use chemexsol\libs\jobException\NotFoundHttpException;
use chemexsol\libs\uuidBehavior\UuidBehavior;
use thamtech\uuid\validators\UuidValidator;
use Yii;

/**
 * This is the model class for table "dictionary".
 *
 * @property string               $id
 * @property string               $type
 * @property string               $title
 * @property bool                 $has_problem_locale
 * @property bool                 $has_problem_link
 * @property string               $system_name
 * @property string               $sync_class_name
 * @property int                  $sync_locales
 * @property string               $sync_version
 * @property string               $sync_status
 * @property array                $sync_params
 * @property int                  $synced_at
 * @property string               $last_user_id
 * @property int                  $updated_at
 *
 * @property DictionaryItem[]     $items
 * @property DictionaryItemType[] $itemTypes
 */
class Dictionary extends \yii\db\ActiveRecord
{
    const TYPE_NORMAL = 'normal';
    const TYPE_IMPORT = 'import';
    const TYPE_SYSTEM = 'system';

    const DICTIONARY_LOCALE = 'locale';

    public static $typeLabels = [
        self::TYPE_NORMAL => 'Обычный справочник',
        self::TYPE_IMPORT => 'Импортируемый справочник',
        self::TYPE_SYSTEM => 'Системный справочник',
    ];

    const SYNC_STATUS_SUCCESS     = 'success';
    const SYNC_STATUS_IN_PROGRESS = 'in_progress';
    const SYNC_STATUS_FAILED      = 'failed';

    const SYNC_COUNTER_UPDATE_DELAY = 5; // sec

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'dictionary';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            UuidBehavior::class
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'type', 'title'], 'required'],
            [['synced_at', 'sync_version', 'sync_status', 'updated_at'], 'default', 'value' => null],
            [['synced_at', 'updated_at'], 'integer'],
            [['id', 'last_user_id'], UuidValidator::class],
            [['type', 'title', 'system_name', 'sync_class_name', 'sync_locales', 'sync_version', 'sync_status'], 'string', 'max' => 255],
            [['title'], 'trim'],
            [['id', 'system_name'], 'unique'],
            [['has_problem_locale', 'has_problem_link'], 'boolean'],
            [['has_problem_locale', 'has_problem_link'], 'default', 'value' => false],
            [['sync_params'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'type' => 'Type',
            'title' => 'Title',
            'has_problem_locale' => 'Has Problem Locale',
            'has_problem_link' => 'Has Problem Link',
            'system_name' => 'System Name',
            'sync_class_name' => 'Sync Class Name',
            'sync_locales' => 'Sync Locales',
            'sync_version' => 'Sync Version',
            'sync_status' => 'Sync Status',
            'sync_params' => 'Sync Params',
            'synced_at' => 'Synced At',
            'last_user_id' => 'Last User ID',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getItems()
    {
        return $this->hasMany(DictionaryItem::class, ['dictionary_sn' => 'system_name'])->andWhere(['dictionary_item.deleted_at' => null]);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getItemTypes()
    {
        return $this->hasMany(DictionaryItemType::class, ['dictionary_sn' => 'system_name']);
    }

    /**
     * @param $system_name
     * @param bool $throw
     *
     * @return string|false
     * @throws NotFoundHttpException
     */
    public static function IdBySystemName($system_name, $throw = true)
    {
        $cacheKey = 'DictionaryIdBySystemName_' . $system_name;

        $id = Yii::$app->cache->get($cacheKey);

        if ($id) {
            return $id;
        }

        $id = static::find()->select(['id'])->where(['system_name' => $system_name])->scalar();

        if (empty($id)) {
            if (!$throw) {
                return false;
            }

            throw new NotFoundHttpException('Dictionary not found!');
        }

        Yii::$app->cache->set($cacheKey, $id, 60);

        return $id;
    }
}
