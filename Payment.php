<?php

namespace modules\payment\models\backend;

use yii;
use modules\users\models\backend\Users;
use modules\payment\models\BasePayment;

/**
 * Class Payment.
 */
class Payment extends BasePayment
{
    /**
     * Типы действия.
     */
    public function valuesType()
    {
        return [
            self::TYPE_PLUS => 'Прибыль',
            self::TYPE_MINUS => 'Расход',
            self::TYPE_BOOSTER => 'Бустеры',
        ];
    }

    /**
     * Получаем общий баланс пользователей.
     *
     * @return int
     */
    public function countBalance()
    {
        $sum = Users::find()
            ->filterWhere(['NOT IN', 'id', [1, 2, 3]])
            ->sum('balance');

        return null === $sum ? 0 : $sum;
    }

    /**
     * Получаем сумму пополнений за последние 30 дней.
     *
     * @return int
     */
    public function countSumPayment()
    {
        $time = time() - (86400 * 30);
        $sum = self::find()
            ->where(['type' => self::TYPE_PLUS])
            ->andFilterWhere(['>', 'created_at', $time])
            ->sum('sum');

        return $sum > 0 ? $sum : 0;
    }

    /**
     * Получаем сумму потраченную на бустеры за 30 дней.
     *
     * @return int
     */
    public function countSumPaymentBooster()
    {
        $time = time() - (86400 * 30);
        $sum = self::find()
            ->where(['type' => self::TYPE_MINUS])
            ->andFilterWhere(['>', 'created_at', $time])
            ->andFilterWhere(['=', 'message', self::MESSAGE_BOOSTER])
            ->sum('sum');

        return $sum > 0 ? $sum : 0;
    }

    /**
     * Получаем статистику операций по дням
     *
     * @param $interval
     *
     * @return mixed
     */
    public function getStat($interval)
    {
        $values = [];
        $sql =
            'SELECT
              DATE(FROM_UNIXTIME(`created_at`)) as `date`,
              SUM(if (`type` = :plus, `sum`,0)) as `plus`,
              SUM(if (`message` = \'PAY_BOOSTER_{name}\', `sum`,0)) as `plus_booster`
            FROM
              '.self::tableName().' 
            WHERE
                `created_at` > UNIX_TIMESTAMP(DATE_SUB(CURRENT_DATE, INTERVAL :interval DAY))
            GROUP BY `date`';
        $result = Yii::$app
            ->db
            ->createCommand($sql, [
                ':plus' => self::TYPE_PLUS,
                ':interval' => $interval,
            ])
            ->queryAll();
        for ($i = $interval; $i > 0; --$i) {
            $date = (new \DateTime("now + 1 day - $i day"))->format('Y-m-d');

            $values[$date] = [
                'date' => $date,
                'plus' => 0,
                'plus_booster' => 0,
            ];
        }

        foreach ($result as $item) {
            $date = $item['date'];

            $values[$date] = [
                'date' => $date,
                'plus' => $item['plus'],
                'plus_booster' => $item['plus_booster'],
            ];
        }

        return array_values($values);
    }
}
