<?php

namespace Jcbowen\MysqlHelperYii2\controllers;

use Jcbowen\JcbaseYii2\base\ConsoleController;
use Jcbowen\JcbaseYii2\components\Util;
use Yii;
use yii\db\Exception;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use Jcbowen\MysqlHelperYii2\components\MysqlHelper;

/**
 * Class MakeController
 * 用于生成或检测数据表是否发生变化
 *
 * @author  Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2024/3/9 10:11 AM
 * @package Jcbowen\MysqlHelperYii2\controllers
 */
class MakeController extends ConsoleController
{
    // ----- 可配置部分 ----- /

    /**
     * @var bool 是否过滤没有数据模型的表(生成基准文件时)
     */
    public $filterNoModelTable = false;

    /**
     * @var array 不跟踪的表名
     */
    public $ignoreTables = [
        // 'users',
    ];

    /**
     * @var array 不跟踪的表名
     *
     * $insertTables = [
     *      'router' => [
     *          'truncate' => true, // 清空后插入
     *      ],
     *      'vip'    => [
     *          'ignore'             => true, // 存在就不插入
     *          // 获取“查询需要插入数据的query实例”
     *          'getInsertDataQuery' => function ($query) {
     *              return $query;
     *          }
     *      ],
     *  ]
     *
     *
     */
    public $insertTables = [];

    /**
     * @var string 数据库基准文件所在目录
     */
    public $dir = '@console/runtime/update/db';

    /**
     * @var string|array 模型所在目录，支持单个目录字符串或多个目录数组
     *
     * 示例：
     * - 单个目录：'@common/models'
     * - 多个目录：['@common/models', '@backend/models', '@frontend/models']
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

    // ----- 专属命令行options ----- /

    /** @var bool 检查完毕，如果存在差异，是否生成基准文件 */
    public $generate = '';
    /** @var bool 如果基准文件为空，是否继续检查 */
    public $continuesIfEmpty = '';

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
        $tables = [];

        // 确保modelsDir是数组格式
        $modelDirs = is_array($this->modelsDir) ? $this->modelsDir : [$this->modelsDir];

        foreach ($modelDirs as $modelDir) {
            $modelDirPath = Yii::getAlias($modelDir);

            // 验证目录是否存在
            if (!is_dir($modelDirPath)) {
                $this->stderr("模型目录不存在: {$modelDir} ({$modelDirPath})" . PHP_EOL, Console::FG_YELLOW);
                continue;
            }

            $files = FileHelper::findFiles($modelDirPath, [
                'only'      => ['*.php'],
                'recursive' => true,
            ]);

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (preg_match('/return \'{{%(.*)}}\';/', $content, $matches)) {
                    if (!in_array($matches[1], $this->ignoreTables))
                        $tables[] = $matches[1];
                }
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
     * {@inheritdoc}
     */
    public function options($actionID): array
    {
        return [
            'generate',
            'continues-if-empty',
            'help'
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function optionAliases(): array
    {
        return [
            'g' => 'generate',
            'c' => 'continues-if-empty',
            'h' => 'help',
        ];
    }

    /**
     * 检查数据表结构是否发生变化
     *
     * @author  Bowen
     * @email   3308725087@qq.com
     *
     * @lasttime: 2023/1/29 2:28 PM
     */
    public function actionCheck()
    {
        $tables    = $this->tables_db;
        $addTables = $tables;
        $delTables = [];

        set_time_limit(0);

        if (file_exists($this->dbSchemaFile)) {
            $this->stdout('开始获取数据库基准文件...' . PHP_EOL);
            $schemaFileData = @file_get_contents($this->dbSchemaFile);
            $schemaFileData = Util::unserializer($schemaFileData);
        }

        if (empty($schemaFileData) && empty($this->continuesIfEmpty)) {
            $this->stdout('本地数据表基准文件不存在，是否继续？[Y/N]' . PHP_EOL);
            $this->stdout("  输入[Y]将继续检查，输入任意其他字符将退出" . PHP_EOL, Console::FG_YELLOW);
            $answer           = strtolower(trim(fgets(STDIN)));
            $continuesIfEmpty = $answer === 'y';
            if (!$continuesIfEmpty)
                $this->stdout('退出差异检查' . PHP_EOL);
        } else {
            $continuesIfEmpty = strtolower($this->continuesIfEmpty) === 'y';
        }
        if (empty($schemaFileData) && !$continuesIfEmpty)
            return;

        $fixSql = [];
        $locDbs = []; // 缓存一份儿在下面遍历时获取过的表结构
        // 根据基准文件进行数据对比
        if (!empty($schemaFileData)) {
            $this->stdout('开始与基准数据对比...' . PHP_EOL);
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
                    $locDbs[$table] = MysqlHelper::getTableSchema($table, true, true, [
                        'resetTableIncrement' => true
                    ]);
                } catch (Exception $e) {
                    $this->stdout("获取 $table 表结构失败" . PHP_EOL, Console::FG_RED);
                    $this->stdout($e->getCode() . PHP_EOL, Console::FG_RED);
                    $this->stdout($e->getMessage() . PHP_EOL, Console::FG_RED);
                    continue;
                }
                // 以数据表结构为准，对比基准数据结构
                $allSql = MysqlHelper::makeFixSql($data, $locDbs[$table], true);
                if (!empty($allSql)) {
                    $fixSql[$table] = $allSql;
                    $this->stdout('需要修复' . PHP_EOL, Console::FG_BLUE);
                } else {
                    $this->stdout('结构一致，无需修复' . PHP_EOL, Console::FG_GREEN);
                }
            }
        } else {
            $this->stdout('基准文件不存在，跳过对比' . PHP_EOL, Console::FG_YELLOW);
        }

        // ----- 统计对比数据 ----- /
        $noModelTables = array_filter($this->tables_db, function ($v) {
            return !in_array($v, $this->tables_model);
        });
        $count         = count($fixSql); // 需要修复的表
        $countAdd      = count($addTables); // 新增表
        $countDel      = count($delTables); // 删除表
        $countNoModel  = count($noModelTables); // 没有生成model的表
        $this->stdout('对比完成，共计' . ($count + $countAdd + $countDel) . '张表需要处理' . PHP_EOL, Console::FG_BLUE);

        if (!empty($countNoModel)) {
            $this->stdout("有{$countNoModel}张表没有生成model：" . PHP_EOL, Console::FG_BLUE);
            $this->stdout(implode(PHP_EOL, $noModelTables) . PHP_EOL);
        } else {
            $this->stdout('Done：所有表都生成了model' . PHP_EOL, Console::FG_GREEN);
        }
        if (!empty($countDel)) {
            $this->stdout("删除了{$countDel}张表：" . PHP_EOL, Console::FG_YELLOW);
            $this->stdout(implode(PHP_EOL, $delTables) . PHP_EOL);
        } else {
            $this->stdout('Done：没有删除表' . PHP_EOL, Console::FG_GREEN);
        }
        if (!empty($countAdd)) {
            $this->stdout("新增了{$countAdd}张表：" . PHP_EOL, Console::FG_BLUE);
            $this->stdout(implode(PHP_EOL, $addTables) . PHP_EOL);
        } else {
            $this->stdout('Done：没有新增表' . PHP_EOL, Console::FG_GREEN);
        }
        if (!empty($count)) {
            $this->stdout('修复sql语句如下：' . PHP_EOL);
            foreach ($fixSql as $table => $sql) {
                $this->stdout("【{$table}】" . PHP_EOL);
                $this->stdout(implode(PHP_EOL, $sql) . PHP_EOL);
            }
        } else {
            $this->stdout('Done：没有需要修复的数据' . PHP_EOL, Console::FG_GREEN);
        }

        // 没有差异就结束
        if (empty($count) && empty($countAdd) && empty($countDel))
            return;

        $this->stdout(PHP_EOL);

        if (empty($this->generate)) {
            $this->stdout('存在差异，是否生成新的基准文件？[Y/N]' . PHP_EOL);
            $this->stdout("  输入[Y]将生成新的基准文件，输入任意其他字符将退出" . PHP_EOL, Console::FG_YELLOW);
            $answer = strtolower(trim(fgets(STDIN)));
            $make   = $answer === 'y';
            if (!$make)
                $this->stdout('退出基准文件生成' . PHP_EOL);
        } else {
            $make = strtolower($this->generate) === 'y';
        }

        if ($make)
            $this->actionDb($locDbs);
    }

    /**
     * 生成数据库基准文件及insert文件
     *
     * @author  Bowen
     * @email bowen@jiuchet.com
     *
     * @param array $dbSchemaContext 上下文中获取过的表结构， [table_name=>table_schema]格式
     *
     * @lasttime: 2023/1/29 2:28 PM
     */
    public function actionDb(array $dbSchemaContext = [])
    {
        // 删除原本的文件
        if (is_file($this->dbSchemaFile)) unlink($this->dbSchemaFile);
        if (is_file($this->dbInsertFile)) unlink($this->dbInsertFile);

        // 取消响应超时(有上下文，说明已经取消过了，不需要再执行)
        if (empty($dbSchemaContext))
            set_time_limit(0);

        $this->stdout('开始执行数据表基准文件生成' . PHP_EOL, Console::FG_BLUE);

        // 过滤掉没有数据模型的表
        $filterNoModelTable = array_filter($this->tables_db, function ($v) {
            return in_array($v, $this->tables_model);
        });

        // 根据传入参数决定是否只遍历处理有模型的表
        $tables = $this->filterNoModelTable ? $filterNoModelTable : $this->tables_db;

        $dbSchema = [];
        foreach ($tables as $table) {
            if (!empty($dbSchemaContext[$table])) {
                $dbSchema[$table] = $dbSchemaContext[$table];
            } else {
                try {
                    $this->stdout('获取【' . $table . '】表结构：');
                    $dbSchema[$table] = MysqlHelper::getTableSchema($table, true, true, [
                        'resetTableIncrement' => true
                    ]);
                } catch (Exception $e) {
                    $this->stdout($e->getMessage() . PHP_EOL, Console::FG_RED);
                    continue;
                }
                if (empty($dbSchema[$table])) {
                    $this->stdout('失败' . PHP_EOL, Console::FG_RED);
                    continue;
                }
                $this->stdout('成功' . PHP_EOL, Console::FG_GREEN);
            }
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
        foreach ($this->insertTables as $insertTable => $option) {
            $this->stdout('获取【' . $insertTable . '】表insert语句：');
            try {
                $data = MysqlHelper::tableInsertSql($insertTable, $option);
            } catch (Exception $e) {
                $this->stdout($e->getMessage() . PHP_EOL, Console::FG_RED);
                continue;
            }
            if (empty($data)) {
                $this->stdout('失败' . PHP_EOL, Console::FG_RED);
                continue;
            }
            $dbInsertSql[$insertTable] = $data['sql'];
            $this->stdout('成功' . PHP_EOL, Console::FG_GREEN);
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

        $this->stdout('执行完毕' . PHP_EOL, Console::FG_BLUE);
    }
}
