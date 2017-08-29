<?php

namespace tourze\swoole\yii2\db;

use \Swoole\Table;
use yii\db\BaseActiveRecord;
use tourze\swoole\yii2\db\Filter;
use yii\debug\components\search\matchers;
use yii\helpers\ArrayHelper;

class SwooleTableActiveRecord extends BaseActiveRecord
{
    //swoole表
    protected static $swooleTable;

    //同步时间
    public static $atomicSyncTime;

    //
    public $_id;

    /**
     * 创建内存表
     */
    public static function buildTable()
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * 取得内存表所有区块配置信息
     * @return array
     */
    public static function findAll($params=[], $orders=[])
    {
        $filter = new Filter();

        foreach ($params as $key=>$value){
            if(is_array($value) && count($value)==3){
                list($compare, $attribute, $v) = $value;
                switch (strtolower($compare)){
                    case "like":
                        $filter->addMatcher($attribute, new matchers\SameAs(['value' => $v, 'partial' => true]));
                        break;
                    case ">":
                        $filter->addMatcher($attribute, new matchers\GreaterThan(['value' => $v]));
                        break;
                    case "<":
                        $filter->addMatcher($attribute, new matchers\LowerThan(['value' => $v]));
                        break;
                    default:
                        break;
                }
            }elseif(!is_array($value)){
                $filter->addMatcher($key, new matchers\SameAs(['value' => $value, 'partial' => false]));
            }
        }

        //筛选
        $list = $filter->filter(static::$swooleTable);
        //排序
        ArrayHelper::multisort($list, array_keys($orders), array_values($orders));
        return $list;
    }

    public static function primaryKey()
    {
        return ['_id'];
    }

    /**
     * @inheritdoc
     * @return ActiveQuery the newly created [[ActiveQuery]] instance.
     */
    public static function find()
    {
        return Yii::createObject(SwooleTableActiveRecord::className(), [get_called_class()]);
    }

    /**
     * Returns the database connection used by this AR class.
     * By default, the "db" application component is used as the database connection.
     * You may override this method if you want to use a different database connection.
     * @return Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return self::$swooleTable;
    }

    /**
     * Inserts a row into the associated Mongo collection using the attribute values of this record.
     * @param bool $runValidation
     * @param null $attributes
     *
     * @return bool
     */
    public function insert($runValidation = true, $attributes = null)
    {
        if ($runValidation && !$this->validate($attributes)) {
            return false;
        }
        $result = $this->insertInternal($attributes);

        return $result;
    }

    /**
     * @see ActiveRecord::insert()
     */
    protected function insertInternal($attributes = null)
    {
        if (!$this->beforeSave(true)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $currentAttributes = $this->getAttributes();
            foreach ($this->primaryKey() as $key) {
                if (isset($currentAttributes[$key])) {
                    $values[$key] = $currentAttributes[$key];
                }
            }
        }

        static::$swooleTable->set($this->_id, $values);

        $changedAttributes = array_fill_keys(array_keys($values), null);
        $this->setOldAttributes($values);
        $this->afterSave(true, $changedAttributes);

        return true;
    }

    /**
     * @param Table $table设置表
     */
    protected static function setTable(Table $table)
    {
        static::$swooleTable = $table;
    }

    /**
     * 判断key是否存在
     * @param $key
     *
     * @return mixed
     */
    public static function existByKey(string $key)
    {
        return static::$swooleTable->exist($key);
    }

    /**
     * 增加计数器
     * @param string $key
     * @param string $column
     * @param int $incrby
     *
     * @return mixed
     */
    public static function updateCountersByKey(string $key, string $column, $incrby=1)
    {
        if($incrby < 0){
            return static::$swooleTable->decr($key, $column, $incrby);
        }else{
            return static::$swooleTable->incr($key, $column, abs($incrby));
        }
    }
}