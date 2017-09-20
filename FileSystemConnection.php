<?php
/**
 * Created by PhpStorm.
 * User: Kadanin Artyom
 * Date: 19.09.17
 * Time: 19:14
 */

namespace kadanin\fs;

use Yii;
use yii\base\Component;
use yii\helpers\FileHelper;
use yii\helpers\StringHelper;

/**
 * @property-read FileSystemTransaction  $transaction
 * @property-read FilesToDeleteContainer $commitDeleteContainer
 * @property-read FilesToDeleteContainer $rollBackDeleteContainer
 * @property-read bool                   $isInTransaction
 */
class FileSystemConnection extends Component
{
    const EVENT_BEGIN_TRANSACTION    = 'beginTransaction';
    const EVENT_COMMIT_TRANSACTION   = 'commitTransaction';
    const EVENT_ROLLBACK_TRANSACTION = 'rollbackTransaction';

    public $registerShutdownRollBack = true;
    /**
     * @var bool
     */
    public $throwAliasException = false;
    /**
     * @var bool
     */
    public $checkIsAlias = true;
    /**
     * @var FileSystemTransaction the currently active transaction
     */
    private $_transaction;
    /**
     * @var FilesToDeleteContainer
     */
    private $_commitDeleteContainer;
    /**
     * @var FilesToDeleteContainer
     */
    private $_rollBackDeleteContainer;

    public function unlink($fileName)
    {
        $fileName = $this->prepareFileName($fileName);

        if ($this->isInTransaction) {
            $this->commitDeleteAdd($fileName);
        } else {
            @unlink($fileName);
        }
    }

    private function prepareFileName($fileName)
    {
        if ($this->checkIsAlias && (false !== ($newFileName = Yii::getAlias($fileName, $this->throwAliasException)))) {
            return $newFileName;
        }

        return $fileName;
    }

    private function commitDeleteAdd($fileName)
    {
        $fileName = $this->prepareFileName($fileName);
        $this->commitDeleteContainer->add($fileName);
    }

    public function link($target, $original)
    {
        $target   = $this->prepareFileName($target);
        $original = $this->prepareFileName($original);

        $this->ensureDirectory($target);

        if (!@link($target, $original)) {
            return;
        }

        if ($this->isInTransaction) {
            $this->rollBackDeleteAdd($target);
        }
    }

    private function ensureDirectory($fileName)
    {
        return FileHelper::createDirectory(StringHelper::dirname($fileName));
    }

    private function rollBackDeleteAdd($fileName)
    {
        $fileName = $this->prepareFileName($fileName);
        $this->rollBackDeleteContainer->add($fileName);
    }

    public function move($target, $original)
    {
        $target   = $this->prepareFileName($target);
        $original = $this->prepareFileName($original);

        if ($this->isInTransaction) {
            if (!@link($target, $original) && !@copy($original, $target)) {
                return;
            }
            $this->rollBackDeleteAdd($target);
        } else {
            rename($original, $target);
        }
    }

    public function moveUploaded($target, $original)
    {
        $target   = $this->prepareFileName($target);
        $original = $this->prepareFileName($original);

        if ($this->isInTransaction) {
            if (!is_uploaded_file($original)) {
                return;
            }
            if (!@rename($original, $target)) {
                return;
            }
            $this->commitDeleteAdd($original);
            $this->rollBackDeleteAdd($target);
        } else {
            move_uploaded_file($original, $target);
        }
    }

    public function init()
    {
        parent::init();

        if ($this->registerShutdownRollBack && $this->beforeShutdownRegister()) {
            register_shutdown_function(function () {
                if (null === ($transaction = $this->getTransaction())) {
                    return;
                }
                try {
                    $transaction->rollBack();
                } catch (\Exception $exception) {
                    unset($exception);
                }
            });
        }
    }

    protected function beforeShutdownRegister()
    {
        return true;
    }

    /**
     * @see transaction
     * @see [[transaction]]
     *
     * @return FileSystemTransaction
     */
    public function getTransaction()
    {
        return $this->_transaction && $this->_transaction->getIsActive() ? $this->_transaction : null;
    }

    public function transaction(callable $callback)
    {
        $transaction = $this->beginTransaction();
        $level       = $transaction->level;

        try {
            $result = $callback($this);
            if ($transaction->isActive && $transaction->level === $level) {
                $transaction->commit();
            }
        } catch (\Exception $e) {
            $this->rollbackTransactionOnLevel($transaction, $level);
            throw $e;
        } catch (\Throwable $e) {
            $this->rollbackTransactionOnLevel($transaction, $level);
            throw $e;
        }

        return $result;
    }

    public function beginTransaction()
    {
        if (($transaction = $this->getTransaction()) === null) {
            $transaction = $this->_transaction = Yii::createObject([
                'class' => FileSystemTransaction::class,
                'db'    => $this,
            ]);
        }
        $transaction->begin();

        return $transaction;
    }

    private function rollbackTransactionOnLevel(FileSystemTransaction $transaction, $level)
    {
        if ($transaction->isActive && $transaction->level === $level) {
            // https://github.com/yiisoft/yii2/pull/13347
            try {
                $transaction->rollBack();
            } catch (\Exception $e) {
                Yii::error($e, __METHOD__);
                // hide this exception to be able to continue throwing original exception outside
            }
        }
    }

    /**
     * @see rollBackDeleteContainer
     * @see [[rollBackDeleteContainer]]
     *
     * @return FilesToDeleteContainer
     */
    public function getRollBackDeleteContainer()
    {
        if (null === $this->_rollBackDeleteContainer) {
            $this->_rollBackDeleteContainer = Yii::createObject([
                'class' => FilesToDeleteContainer::class,
            ]);
        }

        return $this->_rollBackDeleteContainer;
    }

    /**
     * @see commitDeleteContainer
     * @see [[commitDeleteContainer]]
     * @return FilesToDeleteContainer
     */
    public function getCommitDeleteContainer()
    {
        if (null === $this->_commitDeleteContainer) {
            $this->_commitDeleteContainer = Yii::createObject([
                'class' => FilesToDeleteContainer::class,
            ]);
        }

        return $this->_commitDeleteContainer;
    }

    /**
     * @see isInTransaction
     * @see [[isInTransaction]]
     *
     * @return bool
     */
    public function getIsInTransaction()
    {
        return (null !== ($transaction = $this->getTransaction())) && $transaction->isActive;
    }
}
