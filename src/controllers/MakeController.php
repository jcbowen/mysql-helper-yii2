<?php

namespace Jcbowen\MysqlHelperYii2\controllers;

use Jcbowen\JcbaseYii2\components\Util;
use Yii;
use yii\console\Controller;
use yii\db\Exception;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use Jcbowen\MysqlHelperYii2\components\MysqlHelper;

/**
 * Class MakeController
 * 用于生成或检测数据表是否发生变化
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2024/3/9 10:11 AM
 * @package Jcbowen\MysqlHelperYii2\controllers
 */
class MakeController extends Controller
{
    // ----- 可配置部分 ----- /

    /**
     * @var array 不跟踪的表名
     */
    public $ignoreTables = [
        // 'users',
    ];

    /**
     * @var array 不跟踪的表名
     */
    public $insertTables = [
        // 'router' => ['truncate' => true], // 清空后插入
        // 'vip'    => ['ignore' => true], // 存在就不插入
    ];

    /**
     * @var string 数据库基准文件所在目录
     */
    public $dir = '@console/runtime/update/db';

    /**
     * @var string 模型所在目录
     */
    public $modelsDir = '@common/models';

    // ----- 生成变量 ----- /

    /**
     * @var string 数据库结构基准文件
     */
    protected $dbSchemaFile = '';

    /**
     * @var string 插入sql文件
     */
    protected $dbInsertFile = '';

    // 数据表中的所有表名
    protected $tables_db = [];

    // models中的所有表名
    protected $tables_model = [];

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        // 获取数据库中的所有表名
        $tables = MysqlHelper::getAllTables();
        // 去除表前缀
        foreach ($tables as &$table) {
            $table = str_replace(Yii::$app->db->tablePrefix, '', $table);
        }
        $this->tables_db = $tables;

        // 读取models中的所有表名
        $files  = FileHelper::findFiles(Yii::getAlias($this->modelsDir), [
            'only'      => ['*.php'],
            'recursive' => true,
        ]);
        $tables = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/return \'{{%(.*)}}\';/', $content, $matches)) {
                if (!in_array($matches[1], $this->ignoreTables))
                    $tables[] = $matches[1];
            }
        }
        $tables = array_unique($tables);
        sort($tables);
        $this->tables_model = $tables;

        $this->dir = Yii::getAlias($this->dir);
        if (!is_dir($this->dir)) try {
            FileHelper::createDirectory($this->dir);
        } catch (\yii\base\Exception $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            exit;
        }

        $this->dbSchemaFile = $this->dir . '/db_schema.txt';
        $this->dbInsertFile = $this->dir . '/db_insert.sql';
    }

    /**
     * 生成数据库基准文件及insert文件
     *
     * @author Bowen
     * @email 3308725087@qq.com
     *
     * @return bool|int
     * @lasttime: 2023/1/29 2:28 PM
     */
    public function actionDb()
    {
        // 删除原本的文件
        if (is_file($this->dbSchemaFile)) unlink($this->dbSchemaFile);
        if (is_file($this->dbInsertFile)) unlink($this->dbInsertFile);

        // 取消响应超时
        set_time_limit(0);

        $this->stdout('开始执行数据表基准文件生成' . PHP_EOL, Console::FG_BLUE);

        $dbSchema = [];
        foreach ($this->tables_db as $table) {
            $this->stdout('正在获取【' . $table . '】表结构' . PHP_EOL);
            try {
                $dbSchema[$table] = MysqlHelper::getTableSchema($table, true, true, [
                    'resetTableIncrement' => true
                ]);
            } catch (Exception $e) {
                $this->stdout($e->getMessage() . PHP_EOL, Console::FG_RED);
                continue;
            }
            if (empty($dbSchema[$table])) {
                $this->stdout('获取【' . $table . '】表结构失败' . PHP_EOL, Console::FG_RED);
                continue;
            }
            $this->stdout('获取【' . $table . '】表结构成功' . PHP_EOL);
        }
        if (!empty($dbSchema)) {
            $this->stdout('所有表结构获取成功，正在生成基准文件' . PHP_EOL, Console::FG_BLUE);
            $res = file_put_contents($this->dbSchemaFile, serialize($dbSchema));
            if ($res) {
                $this->stdout('数据表结构基准文件生成成功！' . PHP_EOL, Console::FG_GREEN);
            } else {
                $this->stdout('数据表结构基准文件生成失败！' . PHP_EOL, Console::FG_RED);
            }
        } else {
            $this->stdout('没有需要生成的结构基准表数据' . PHP_EOL, Console::FG_YELLOW, Console::UNDERLINE);
        }

        $dbInsertSql = [];
        foreach ($this->insertTables as $table => $option) {
            $this->stdout('正在获取【' . $table . '】表insert语句' . PHP_EOL);
            try {
                $data = MysqlHelper::tableInsertSql($table, $option);
            } catch (Exception $e) {
                $this->stdout($e->getMessage() . PHP_EOL, Console::FG_RED);
                continue;
            }
            if (empty($data)) {
                $this->stdout('获取【' . $table . '】表insert语句失败' . PHP_EOL, Console::FG_RED);
                continue;
            }
            $dbInsertSql[$table] = $data['sql'];
            $this->stdout('获取【' . $table . '】表insert语句成功' . PHP_EOL);
        }
        if (!empty($dbInsertSql)) {
            $this->stdout('所有insert语句获取成功，正在生成基准文件' . PHP_EOL, Console::FG_BLUE);
            $res = file_put_contents($this->dbInsertFile, implode("\n-- --------------------------------------------------------\n\n", $dbInsertSql));
            if ($res) {
                $this->stdout('数据表insert语句基准文件生成成功！' . PHP_EOL, Console::FG_GREEN);
            } else {
                $this->stdout('数据表insert语句基准文件生成失败！' . PHP_EOL, Console::FG_RED);
            }
        } else {
            $this->stdout('没有需要生成的insert基准表数据' . PHP_EOL, Console::FG_YELLOW, Console::UNDERLINE);
        }

        return $this->stdout('执行完毕' . PHP_EOL, Console::FG_BLUE);
    }

    /**
     * 检查数据库结构是否发生变化
     *
     * @author Bowen
     * @email 3308725087@qq.com
     *
     * @lasttime: 2023/1/29 2:28 PM
     */
    public function actionCheck()
    {
        $tables    = $this->tables_db;
        $addTables = $tables;
        $delTables = [];
        if (file_exists($this->dbSchemaFile)) {
            set_time_limit(0);

            $this->stdout('开始获取数据库基准文件...' . PHP_EOL);
            $schemaFileData = file_get_contents($this->dbSchemaFile);
            $schemaFileData = Util::unserializer($schemaFileData);

            if (!empty($schemaFileData)) {
                $this->stdout('开始与基准数据对比...' . PHP_EOL);
                $fixSql = [];
                foreach ($schemaFileData as $table => $data) {
                    // 需要添加的表
                    $addTables = array_filter($addTables, function ($v) use ($table) {
                        return $v != $table;
                    });

                    // 需要删除的表
                    if (!empty($tables) && !in_array($table, $tables))
                        $delTables[] = $table;

                    $this->stdout("对比【{$table}】结果：");
                    try {
                        $loc_info = MysqlHelper::getTableSchema($table, true, true, [
                            'resetTableIncrement' => true
                        ]);
                    } catch (Exception $e) {
                        $this->stdout("获取 $table 表结构失败" . PHP_EOL, Console::FG_RED);
                        $this->stdout($e->getCode() . PHP_EOL, Console::FG_RED);
                        $this->stdout($e->getMessage() . PHP_EOL, Console::FG_RED);
                        continue;
                    }
                    // 以数据表结构为准，对比基准数据结构
                    $allSql = MysqlHelper::makeFixSql($data, $loc_info, true);
                    if (!empty($allSql)) {
                        $fixSql[$table] = $allSql;
                        $this->stdout('需要修复' . PHP_EOL, Console::FG_BLUE);
                    } else {
                        $this->stdout('结构一致，无需修复' . PHP_EOL, Console::FG_GREEN);
                    }
                }
                // 统计没有生成model的表
                $noModelTables = array_filter($this->tables_db, function ($v) {
                    return !in_array($v, $this->tables_model);
                });
                $count         = count($fixSql); // 需要修复的表
                $countAdd      = count($addTables); // 新增表
                $countDel      = count($delTables); // 删除表
                $countNoModel  = count($noModelTables); // 没有生成model的表
                $this->stdout('对比完成，共计' . ($count + $countAdd + $countDel) . '张表需要处理' . PHP_EOL, Console::FG_BLUE);
                if (!empty($count)) {
                    $this->stdout('修复sql语句如下：' . PHP_EOL);
                    foreach ($fixSql as $table => $sql) {
                        $this->stdout("【{$table}】" . PHP_EOL);
                        $this->stdout(implode(PHP_EOL, $sql) . PHP_EOL);
                    }
                } else {
                    $this->stdout('Done：没有需要修复的数据' . PHP_EOL, Console::FG_GREEN);
                }
                if (!empty($countAdd)) {
                    $this->stdout("新增了{$countAdd}张表：" . PHP_EOL, Console::FG_BLUE);
                    $this->stdout(implode(PHP_EOL, $addTables) . PHP_EOL);
                } else {
                    $this->stdout('Done：没有新增表' . PHP_EOL, Console::FG_GREEN);
                }
                if (!empty($countDel)) {
                    $this->stdout("删除了{$countDel}张表：" . PHP_EOL, Console::FG_YELLOW);
                    $this->stdout(implode(PHP_EOL, $delTables) . PHP_EOL);
                } else {
                    $this->stdout('Done：没有删除表' . PHP_EOL, Console::FG_GREEN);
                }
                if (!empty($countNoModel)) {
                    $this->stdout("有{$countNoModel}张表没有生成model：" . PHP_EOL, Console::FG_BLUE);
                    $this->stdout(implode(PHP_EOL, $noModelTables) . PHP_EOL);
                } else {
                    $this->stdout('Done：所有表都生成了model' . PHP_EOL, Console::FG_GREEN);
                }
            } else {
                $this->stdout('没有数据库基准数据，不做修复' . PHP_EOL, Console::FG_YELLOW);
            }
        } else {
            $this->stdout('数据库基准文件不存在，不做检查' . PHP_EOL, Console::FG_YELLOW);
        }
    }
}
