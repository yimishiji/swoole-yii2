<?php

namespace tourze\swoole\yii2\db;

use \Swoole\Table;
use yii\db\BaseActiveRecord;
use tourze\swoole\yii2\db\Filter;
use yii\debug\components\search\matchers;

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
    public static function findAll($params=[])
    {
        $filter = new Filter();

        if(isset($params['id'])) {
            $filter->addMatcher('id', new matchers\SameAs(['value' => $params['id'], 'partial' => false]));
        }
        if(isset($params['name'])) {
            $filter->addMatcher('name', new matchers\SameAs(['value' => $params['name'], 'partial' => true]));
        }


        return $filter->filter(static::$swooleTable);
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
    public static function exist($key)
    {
        return static::$swooleTable->exist($key);
    }
}