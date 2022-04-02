<?php

namespace Jcbowen\MysqlHelperYii2\console\controllers;

use Jcbowen\MysqlHelperYii2\components\MysqlHelper;
use yii\console\Controller;

class TestController extends Controller
{
    public function actionIndex()
    {
        print_r((new MysqlHelper())->tableInsertSql('{{%page}}', 0, 2));
    }

}
