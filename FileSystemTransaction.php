<?php
/**
 * Created by PhpStorm.
 * User: Kadanin Artyom
 * Date: 19.09.17
 * Time: 19:16
 */

namespace kadanin\fs;

use Yii;
use yii\base\InvalidConfigException;
use yii\base\Object;

/**
 * @property-read int  $level
 * @property-read bool $isActive
 */
class FileSystemTransaction extends Object
{
    /**
     * @var FileSystemConnection the database connection that this transaction is associated with.
     */
    public $db;

    /**
     * @var int the nesting level of the transaction. 0 means the outermost level.
     */
    private $_level = 0;

    public function begin()
    {
        if ($this->db === null) {
            throw new InvalidConfigException(static::class . '::db must be set.');
        }

        if ($this->_level === 0) {
            Yii::trace('Begin files transaction', __METHOD__);

            $db = $this->db;

            $db->trigger($db::EVENT_BEGIN_TRANSACTION);

            $this->_level = 1;

            return;
        }

        $this->beginNested();
    }

    private function beginNested()
    {
        Yii::info('Transaction not started: nested transaction not supported', __METHOD__);
    }

    public function rollBack()
    {
        if (!$this->getIsActive()) {
            // do nothing if transaction is not active: this could be the transaction is committed
            // but the event handler to "commitTransaction" throw an exception
            return;
        }

        $this->_level--;
        if ($this->_level === 0) {
            Yii::trace('Roll back transaction', __METHOD__);

            $db = $this->db;

            $db->trigger($db::EVENT_ROLLBACK_TRANSACTION);

            $db->rollBackDeleteContainer->process();
            $db->commitDeleteContainer->reset();

            return;
        }

        $this->rollBackNested();
    }

    /**
     * Returns a value indicating whether this transaction is active.
     * @return bool whether this transaction is active. Only an active transaction
     * can [[commit()]] or [[rollBack()]].
     */
    public function getIsActive()
    {
        return $this->_level > 0 && $this->db;
    }

    private function rollBackNested()
    {
        Yii::info('Transaction not rolled back: nested file transaction not supported', __METHOD__);
        throw new FileSystemException('Roll back failed: nested file transaction not supported.');
    }

    public function commit()
    {
        if (!$this->getIsActive()) {
            throw new FileSystemException('Failed to commit file transaction: transaction was inactive.');
        }

        $this->_level--;
        if ($this->_level === 0) {
            Yii::trace('Commit file transaction', __METHOD__);
            $db = $this->db;
            $db->trigger($db::EVENT_COMMIT_TRANSACTION);

            $db->commitDeleteContainer->process();
            $db->rollBackDeleteContainer->reset();

            return;
        }

        $this->commitNested();
    }

    private function commitNested()
    {
        Yii::info('Transaction not committed: nested transaction not supported', __METHOD__);
        throw new FileSystemException('Commit failed: nested file transaction not supported.');
    }

    /**
     * @see level
     * @see [[level]]
     *
     * @return int
     */
    public function getLevel()
    {
        return $this->_level;
    }
}
