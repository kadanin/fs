<?php
/**
 * Created by PhpStorm.
 * User: Kadanin Artyom
 * Date: 21.09.17
 * Time: 13:24
 */

namespace kadanin\fs;

use Yii;

class FS
{
    /**
     * @param string                        $message   Error message
     * @param \kadanin\fs\OperationsOptions $operationsOptions
     * @param array                         $errorInfo Error info
     * @param int                           $code      Error code
     * @param \Exception                    $previous  The previous exception used for the exception chaining.
     *
     * @return bool
     *
     * @throws \kadanin\fs\FileSystemException
     */
    public static function fail($message, OperationsOptions $operationsOptions, $errorInfo = [], $code = 0, \Exception $previous = null)
    {
        if ($operationsOptions->throwFailures) {
            throw static::e($message, $errorInfo, $code, $previous);
        }

        return false;
    }

    /**
     * @param string     $message   Error message
     * @param array      $errorInfo Error info
     * @param int        $code      Error code
     * @param \Exception $previous  The previous exception used for the exception chaining.
     *
     * @return FileSystemException
     */
    public static function e($message, $errorInfo = [], $code = 0, \Exception $previous = null)
    {
        return Yii::createObject(FileSystemException::class, [$message, $errorInfo, $code, $previous]);
    }
}