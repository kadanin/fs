<?php
/**
 * Created by PhpStorm.
 * User: Kadanin Artyom
 * Date: 19.09.17
 * Time: 19:23
 */

namespace kadanin\fs;

use yii\base\Exception;

class FileSystemException extends Exception
{
    /**
     * Constructor.
     *
     * @param string     $message   Error message
     * @param array      $errorInfo Error info
     * @param int        $code      Error code
     * @param \Exception $previous  The previous exception used for the exception chaining.
     */
    public function __construct($message, $errorInfo = [], $code = 0, \Exception $previous = null)
    {
        $this->errorInfo = $errorInfo;
        parent::__construct($message, $code, $previous);
    }

    public $errorInfo = [];

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'File System Exception';
    }

    /**
     * @inheritdoc
     */
    public function __toString()
    {
        return parent::__toString() . PHP_EOL
            . 'Additional Information:' . PHP_EOL . print_r($this->errorInfo, true);
    }
}
