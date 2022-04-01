<?php

namespace Jcbowen\MysqlHelperYii2\console\controllers;

use Jcbowen\MysqlHelperYii2\components\MysqlHelper;
use yii\console\Controller;

class TestController extends Controller
{
    public function actionIndex()
    {
        (new MysqlHelper())->getTableSchema('{{%page}}');
    }
}
