<?php
/**
 * Created by PhpStorm.
 * User: Kadanin Artyom
 * Date: 20.09.17
 * Time: 10:16
 */

namespace kadanin\fs;

use yii\base\Object;

class FilesToDeleteContainer extends Object
{
    /**
     * @var array
     */
    private $_fileNames = [];

    public function add($fileName)
    {
        if (is_file($fileName) && is_writable($fileName)) {
            $this->_fileNames[] = $fileName;
        }
    }

    public function process()
    {
        foreach ($this->_fileNames as $fileName) {
            @unlink($fileName);
        }
    }

    public function reset()
    {
        $this->_fileNames = [];
    }
}