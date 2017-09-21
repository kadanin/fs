<?php
/**
 * Created by PhpStorm.
 * User: Kadanin Artyom
 * Date: 21.09.17
 * Time: 13:57
 */

namespace kadanin\fs;

use yii\base\Object;

class OperationsOptions extends Object
{
    /**
     * @var bool
     */
    public $copyOnLinkFail = true;
    /**
     * @var bool
     */
    public $throwUnlinkingNotExistingFile = false;
    public $throwNotWritable              = true;
    public $throwFailures                 = true;
}