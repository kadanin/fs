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
use yii\di\Instance;
use yii\helpers\FileHelper;
use yii\helpers\StringHelper;
use yii\i18n\PhpMessageSource;

/**
 * @property OperationsOptions           $operationsOptions
 *
 * @property-read FileSystemTransaction  $transaction
 * @property-read FilesToDeleteContainer $commitDeleteContainer
 * @property-read FilesToDeleteContainer $rollBackDeleteContainer
 * @property-read bool                   $isInTransaction
 */
class FileSystem extends Component
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
    public $checkFileNameIsAlias = true;
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
    /**
     * @var OperationsOptions
     */
    private $_operationsOptions;

    /**
     * @param \Closure $closure
     *
     * @return bool
     */
    public function isSuccessful(\Closure $closure)
    {
        try {
            return $closure();
        } catch (NonExistingFileDeletionException $nonExistingFileDeletionException) {
            return true;
        } catch (FileSystemException $exception) {
            Yii::warning($exception->getMessage(), __METHOD__);
            unset($exception);
        }

        return false;
    }

    /**
     * @param string                       $fileName
     * @param OperationsOptions|array|null $operationsOptions
     *
     * @return bool
     *
     * @throws \kadanin\fs\NonExistingFileDeletionException
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     * @throws \kadanin\fs\FileSystemException
     *
     */
    public function unlink($fileName, OperationsOptions $operationsOptions = null)
    {
        $fileName = $this->prepareFileName($fileName);

        $operationsOptions = $this->ensureOptions($operationsOptions);

        if (!is_file($fileName)) {
            if ($operationsOptions->throwFailures && $operationsOptions->throwUnlinkingNotExistingFile) {
                throw Yii::createObject(NonExistingFileDeletionException::class, [Yii::t('kadanin/fs/errors', 'Deleting not existing file {fileName}', ['fileName' => $fileName])]);
            }

            return true;
        }

        if (!is_writable($fileName)) {
            if ($operationsOptions->throwNotWritable) {
                throw FS::e(Yii::t('kadanin/fs/errors', 'File not writable: {fileName}', ['fileName' => $fileName]));
            }

            return false;
        }

        if ($this->isInTransaction) {
            $this->commitDeleteAdd($fileName);
        } else {
            try {
                if (!unlink($fileName) && is_file($fileName)) {
                    return FS::fail(Yii::t('kadanin/fs/errors', 'Unknown error when deleting file {fileName}', ['fileName' => $fileName]), $operationsOptions);
                }

                return true;
            } catch (\Exception $exception) {
                return FS::fail(Yii::t('kadanin/fs/errors', 'Unknown error when deleting file {fileName}', ['fileName' => $fileName]), $operationsOptions, [], 0, $exception);
            }
        }

        return true;
    }

    /**
     * @param $fileName
     *
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    private function prepareFileName($fileName)
    {
        if ($this->checkFileNameIsAlias && (false !== ($newFileName = Yii::getAlias($fileName, $this->throwAliasException)))) {
            return $newFileName;
        }

        return $fileName;
    }

    /**
     * @param OperationsOptions|array|null $operationsOptions
     *
     * @return OperationsOptions
     * @throws \yii\base\InvalidConfigException
     */
    private function ensureOptions($operationsOptions)
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return (null === $operationsOptions) ? $this->operationsOptions : Instance::ensure($operationsOptions, OperationsOptions::class);
    }

    private function commitDeleteAdd($fileName)
    {
        $fileName = $this->prepareFileName($fileName);
        $this->commitDeleteContainer->add($fileName);
    }

    /**
     * @param string                       $original
     * @param string                       $target
     * @param OperationsOptions|array|null $operationsOptions
     *
     * @return bool
     * @throws \kadanin\fs\FileSystemException
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     */
    public function link($original, $target, OperationsOptions $operationsOptions = null)
    {
        return $this->copyInternal($original, $target, $operationsOptions, true);
    }

    /**
     * @param string                       $original
     * @param string                       $target
     * @param OperationsOptions|array|null $operationsOptions
     * @param bool                         $doLink
     *
     * @return bool
     * @throws \kadanin\fs\FileSystemException
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     */
    private function copyInternal($original, $target, OperationsOptions $operationsOptions = null, $doLink)
    {
        $operationsOptions = $this->ensureOptions($operationsOptions);

        $target   = $this->prepareFileName($target);
        $original = $this->prepareFileName($original);

        if (!is_file($original)) {
            return FS::fail(Yii::t('kadanin/fs/errors', 'Tying to link non existing file: {fileName}', ['fileName' => $original]), $operationsOptions);
        }

        $this->ensureDirectory($target);

        $e = null;

        if ($doLink) {
            try {
                if (link($target, $original)) {
                    if ($this->isInTransaction) {
                        $this->rollBackDeleteAdd($target);
                    }

                    return true;
                }
            } catch (\Exception $e) {
            }

            if (!$operationsOptions->copyOnLinkFail) {
                return FS::fail(Yii::t('kadanin/fs/errors', 'Fail to link file: {original} -> {target}', [
                    'original' => $original,
                    'target'   => $target,
                ]), $operationsOptions, [], 0, $e);
            }
        }

        try {
            if (copy($original, $target)) {
                if ($this->isInTransaction) {
                    $this->rollBackDeleteAdd($target);
                }

                return true;
            }
        } catch (\Exception $e) {
        }

        $message = $doLink
            ? Yii::t('kadanin/fs/errors', 'Fail to link and copy file: {original} -> {target}', ['original' => $original, 'target' => $target])
            : Yii::t('kadanin/fs/errors', 'Fail to copy file: {original} -> {target}', ['original' => $original, 'target' => $target]) //
        ;

        return FS::fail($message, $operationsOptions, [], 0, $e);
    }

    private function ensureDirectory($fileName)
    {
        return FileHelper::createDirectory($dirName = StringHelper::dirname($fileName)) && is_writable($dirName);
    }

    private function rollBackDeleteAdd($fileName)
    {
        $fileName = $this->prepareFileName($fileName);
        $this->rollBackDeleteContainer->add($fileName);
    }

    /**
     * @param string                       $original
     * @param string                       $target
     * @param OperationsOptions|array|null $operationsOptions
     *
     * @return bool
     * @throws \kadanin\fs\FileSystemException
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     */
    public function copy($original, $target, OperationsOptions $operationsOptions = null)
    {
        return $this->copyInternal($original, $target, $operationsOptions, false);
    }

    /**
     * @param string                       $original
     * @param string                       $target
     * @param OperationsOptions|array|null $operationsOptions
     *
     * @return bool
     *
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     * @throws \kadanin\fs\FileSystemException
     */
    public function move($original, $target, OperationsOptions $operationsOptions = null)
    {
        return $this->moveInternal($original, $target, $operationsOptions, false);
    }

    /**
     * @param string                       $original
     * @param string                       $target
     * @param OperationsOptions|array|null $operationsOptions
     * @param bool                         $uploaded
     *
     * @return bool
     *
     * @throws \yii\base\Exception
     * @throws \kadanin\fs\FileSystemException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidParamException
     */
    private function moveInternal($original, $target, OperationsOptions $operationsOptions = null, $uploaded)
    {
        $uploaded = (bool)$uploaded;

        $operationsOptions = $this->ensureOptions($operationsOptions);

        $target   = $this->prepareFileName($target);
        $original = $this->prepareFileName($original);

        if (!is_file($original)) {
            return FS::fail(Yii::t('kadanin/fs/errors', 'Tying to move non existing file: {fileName}', ['fileName' => $original]), $operationsOptions);
        }

        if ($uploaded && !is_uploaded_file($original)) {
            return FS::fail(Yii::t('kadanin/fs/errors', 'File is not uploaded: {fileName}', ['fileName' => $original]), $operationsOptions);
        }

        $targetDir = StringHelper::dirname($target);

        if (!FileHelper::createDirectory($targetDir)) {
            return FS::fail(Yii::t('kadanin/fs/errors', 'Failed to create directory: {directory}', ['directory' => $targetDir]), $operationsOptions);
        }

        if (!is_writable($targetDir)) {
            return FS::fail(Yii::t('kadanin/fs/errors', 'Directory is not writable: {directory}', ['directory' => $targetDir]), $operationsOptions);
        }

        $e = null;


        if (!$this->isInTransaction) {
            try {
                if ($uploaded ? move_uploaded_file($original, $target) : rename($original, $target)) {
                    return true;
                }
            } catch (\Exception $e) {
            }

            $message = $uploaded
                ? Yii::t('kadanin/fs/errors', 'Failed to move uploaded file: {original} -> {target}', ['original' => $original, 'target' => $target])
                : Yii::t('kadanin/fs/errors', 'Failed to rename/move: {original} -> {target}', ['original' => $original, 'target' => $target]) //
            ;

            return FS::fail($message, $operationsOptions, [], 0, $e);
        }


        $triedToCopy = false;

        try {
            if (link($target, $original) || ($triedToCopy = false) || copy($original, $target)) {
                $this->commitDeleteAdd($original);
                $this->rollBackDeleteAdd($target);

                return true;
            }
        } catch (\Exception $e) {
        }

        $message = $this->moveMessage($uploaded, $triedToCopy, $original, $target);

        return FS::fail($message, $operationsOptions, [], 0, $e);
    }

    /**
     * @param bool   $uploaded
     * @param bool   $triedToCopy
     * @param string $original
     * @param string $target
     *
     * @return string
     */
    private function moveMessage($uploaded, $triedToCopy, $original, $target)
    {
        $uploaded    = (bool)$uploaded;
        $triedToCopy = (bool)$triedToCopy;

        switch ([$uploaded, $triedToCopy]) {
            case [true, true]:
                return Yii::t('kadanin/fs/errors', 'Failed to copy uploaded file (transactional moving): {original} -> {target}', ['original' => $original, 'target' => $target]);
            case [true, false]:
                return Yii::t('kadanin/fs/errors', 'Failed to link uploaded file (transactional moving): {original} -> {target}', ['original' => $original, 'target' => $target]);
            case [false, true]:
                return Yii::t('kadanin/fs/errors', 'Failed to copy (transactional moving): {original} -> {target}', ['original' => $original, 'target' => $target]);
            case [false, false]:
                return Yii::t('kadanin/fs/errors', 'Failed to link (transactional moving): {original} -> {target}', ['original' => $original, 'target' => $target]);
        }

        return 'WTF IN ' . __METHOD__;
    }

    /**
     * @param string                       $original
     * @param string                       $target
     * @param OperationsOptions|array|null $operationsOptions
     *
     * @return bool
     *
     * @throws \yii\base\Exception
     * @throws \kadanin\fs\FileSystemException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidParamException
     */
    public function moveUploaded($original, $target, OperationsOptions $operationsOptions = null)
    {
        return $this->moveInternal($original, $target, $operationsOptions, true);
    }

    public function init()
    {
        parent::init();

        $this->registerTranslations();

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

    private function registerTranslations()
    {
        if (isset(Yii::$app->i18n->translations['kadanin/fs'])) {
            return;
        }

        Yii::$app->i18n->translations['kadanin/fs'] = [
            'class'          => PhpMessageSource::class,
            'sourceLanguage' => 'en-US',
            'basePath'       => __DIR__ . '/messages',
        ];
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
     *
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

    /**
     * @see operationsOptions
     * @see [[operationsOptions]]
     *
     * @return OperationsOptions
     */
    public function getOperationsOptions()
    {
        if (null === $this->_operationsOptions) {
            $this->_operationsOptions = Yii::createObject(OperationsOptions::class);
        }

        return $this->_operationsOptions;
    }

    /**
     * @see operationsOptions
     * @see [[operationsOptions]]
     *
     * @param OperationsOptions|array $newValue
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function setOperationsOptions($newValue)
    {
        $this->_operationsOptions = Instance::ensure($newValue, OperationsOptions::class);
    }
}
