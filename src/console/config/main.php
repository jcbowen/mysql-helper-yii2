<?php

defined('IN_JC') or exit('Access Denied');

$params = array_merge(
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

return [
    'id'                  => 'app-console',
    'language'            => 'zh-CN',
    'timeZone'            => 'Asia/Shanghai',
    'vendorPath'          => dirname(__DIR__, 2) . '/vendor',
    'basePath'            => dirname(__DIR__),
    'controllerNamespace' => 'Jcbowen\MysqlHelperYii2\console\controllers',
    'aliases'             => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'defaultRoute'        => 'index',
    'components'          => [
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        /*'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                "<controller:\w+>/<action:\w+>/<id:\d+>"=>"<controller>/<action>",
            ],
        ],*/
    ],
    'params'              => $params,
];
