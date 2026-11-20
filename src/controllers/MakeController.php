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
    /** @var string 检查完毕，如果存在差异，是否生成基准文件（'' 交互式确认 / 'y' 直接生成） */
    public $generate = '';
    /** @var string 如果基准文件为空，是否继续检查（'' 交互式确认 / 'y' 继续检查） */
    public $continuesIfEmpty = '';
    /** @var string 是否显示详细列表（无model表清单等）（'y' 显示，默认仅显示数量，避免大项目刷屏） */
    public $verbose = '';

    /**
     * @var bool 是否已完成环境准备（延迟加载，避免 --help 等场景触发重逻辑）
     */
    private $prepared = false;

    /**
     * @var bool|null STDOUT 是否为 TTY（终端）。null 表示尚未检测（延迟缓存）
     */
    private $isTty = null;

    /**
     * @var int 最近一次单行进度输出的字符宽度，用于 clearProgress 时清除残留
     */
    private $progressWidth = 0;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
    }

    /**
     * 环境准备：获取数据库表名、扫描模型目录、定位基准文件
     *
     * 重逻辑延迟到 action 内执行，避免控制器实例化（含查看 --help）时即连库并扫描模型。
     * 幂等，可重复调用。
     *
     * @return void
     */
    private function prepare()
    {
        if ($this->prepared) return;
        $this->prepared = true;

        // 连库与扫描模型目录耗时较长（慢库/大模型目录可达数秒），先给出提示避免被误认为卡死
        $this->stdout('正在连接数据库并扫描模型目录...' . PHP_EOL);

        // 获取数据库中的所有表名
        $tables = MysqlHelper::getAllTables();
        // 去除表前缀
        // foreach ($tables as &$table) {
        // $table = str_replace(Yii::$app->db->tablePrefix, '', $table);
        // }
        // 保持完整表名，不去除表前缀，便于后续精确对比
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

                // 从文件中提取类名
                $className = $this->getClassNameFromFile($file, $content);
                if (empty($className)) {
                    continue;
                }

                try {
                    // 检查类是否存在
                    if (!class_exists($className)) {
                        // 尝试自动加载类
                        spl_autoload_call($className);
                    }

                    if (class_exists($className)) {
                        // 检查类是否有tableName静态方法
                        if (method_exists($className, 'tableName')) {
                            // 直接调用静态方法获取表名
                            $tableName = $className::tableName();

                            // 提取实际的表名（去除{{%和}}）
                            if (preg_match('/{{%?([^}]+)}}/', $tableName, $matches)) {
                                $tableName = trim($matches[1]);
                            } else {
                                // 如果没有{{%前缀，直接使用表名
                                $tableName = $tableName;
                            }

                            // 验证表名有效性
                            if (empty($tableName)) {
                                $this->stderr("警告：在文件 {$file} 中发现空的表名定义" . PHP_EOL, Console::FG_YELLOW);
                                continue;
                            }

                            // 检查是否在忽略列表中
                            // 双口径匹配：模型若写死完整表名（含前缀），配置里写去前缀名也应生效；
                            // 反之模型返回 {{%xxx}} 格式（去前缀名）时，配置写完整表名也应生效
                            $ignoreList  = array_map(function ($v) {
                                return (string)$v;
                            }, $this->ignoreTables);
                            $prefixFree  = MysqlHelper::removeTablePrefix($tableName);
                            $isIgnored   = in_array($tableName, $ignoreList)
                                           || in_array($prefixFree, $ignoreList);
                            if (!$isIgnored) {
                                // 将模型表名转换为完整表名（MysqlHelper::tableName会自动处理前缀）
                                $fullTableName = MysqlHelper::tableName($tableName);
                                $tables[]      = $fullTableName;
                            }
                        }
                        // 跳过没有tableName方法的模型（如表单模型）
                    }
                } catch (\Throwable $e) {
                    // 捕获所有异常，避免单个模型错误影响整体执行
                    $this->stderr("警告：处理文件 {$file} 时出错: " . $e->getMessage() . PHP_EOL, Console::FG_YELLOW);
                    continue;
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
     * 检测 STDOUT 是否为 TTY（终端），结果缓存
     *
     * 非终端环境（管道重定向、CI、git hooks）下单行进度（\r 覆盖）会污染日志，
     * 各进度方法据此自动降级为普通输出。
     *
     * @author  Bowen
     * @email   bowen@jiuchet.com
     *
     * @return bool true 表示输出到真实终端
     */
    private function isTty(): bool
    {
        if ($this->isTty === null) {
            $this->isTty = function_exists('stream_isatty') && @stream_isatty(STDOUT);
        }
        return $this->isTty;
    }

    /**
     * 输出单行进度（\r 覆盖刷新），仅 TTY 终端生效
     *
     * 非终端环境静默跳过（阶段开始/结束已有普通输出兜底）。
     * 跨平台说明：\r 回车覆盖在 Windows cmd/PowerShell 与 macOS/Linux 终端均原生支持，
     * 不使用 ANSI 转义（旧版 Windows cmd 可能未启用 VT 序列），残留字符用空格填充清除。
     *
     * @author  Bowen
     * @email   bowen@jiuchet.com
     *
     * @param int    $current 当前序号（从 1 开始）
     * @param int    $total   总数
     * @param string $label   阶段描述，如 '对比中'、'获取表结构'
     * @param string $detail  可选的当前处理对象名（如表名）
     *
     * @return void
     */
    private function showProgress(int $current, int $total, string $label, string $detail = ''): void
    {
        if (!$this->isTty()) return;
        $text = sprintf('%s [%d/%d]', $label, $current, $total);
        if ($detail !== '') $text .= " {$detail}";
        $this->progressWidth = strlen($text);
        $this->stdout("\r{$text}");
    }

    /**
     * 清除当前单行进度（\r + 空格填充覆盖后回到行首），仅 TTY 终端生效
     *
     * 用于进度循环结束后清行，或在进度行上输出差异/错误明细前断行。
     * 填充宽度取最近进度行宽度 + 冗余（表名变短时旧行可能更长），多填的空格无视觉影响。
     *
     * @author  Bowen
     * @email   bowen@jiuchet.com
     *
     * @return void
     */
    private function clearProgress(): void
    {
        if (!$this->isTty() || $this->progressWidth === 0) return;
        $this->stdout("\r" . str_repeat(' ', $this->progressWidth + 40) . "\r");
        $this->progressWidth = 0;
    }

    /**
     * 从PHP文件中提取类名
     *
     * @author  Bowen
     * @email bowen@jiuchet.com
     * @lasttime: 2024/3/9 10:11 AM
     *
     * @param string $content 文件内容
     * @param string $file    文件路径
     *
     * @return string|bool 类名或false
     */
    private function getClassNameFromFile(string $file, string $content)
    {
        // 首先尝试使用token_get_all解析文件，更安全准确
        $tokens      = token_get_all($content);
        $namespace   = '';
        $className   = '';
        $inNamespace = false;
        $inClass     = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                list($id, $text, $line) = $token;

                // 处理命名空间
                if ($id === T_NAMESPACE) {
                    $inNamespace = true;
                    $namespace   = '';
                } elseif ($inNamespace) {
                    if ($id === T_STRING || $id === T_NS_SEPARATOR) {
                        $namespace .= $text;
                    } elseif ($id === ';' || $id === '{') {
                        // 命名空间声明结束
                        $inNamespace = false;
                    }
                } // 处理类名
                else if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE) {
                    // 匿名类（new class）不具备独立类名，跳过避免误取其父类名或成员名
                    $prev = $index - 1;
                    while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) $prev--;
                    $isNewClass = ($id === T_CLASS && $prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NEW);
                    if (!$isNewClass) {
                        $inClass = true;
                    }
                } else if ($inClass && $id === T_STRING) {
                    $className = $text;
                    break;
                }
            } else {
                // 处理字符串类型的token，比如分号、大括号等
                if ($inNamespace && ($token === ';' || $token === '{')) {
                    // 命名空间声明结束
                    $inNamespace = false;
                }
            }
        }

        if (!empty($className)) {
            return !empty($namespace) ? $namespace . '\\' . $className : $className;
        }

        // 如果token_get_all失败，尝试使用正则表达式
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+([^\s{]+)/', $content, $matches)) {
            $className = trim($matches[1]);
            return !empty($namespace) ? $namespace . '\\' . $className : $className;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * 注意：必须返回驼峰形式的属性名（如 continuesIfEmpty），而非 kebab 形式。
     * Yii2 在解析命令行时会把 kebab-case 输入（--continues-if-empty）自动转为驼峰后
     * 与此处的返回值匹配，并直接以属性名访问控制器；若此处返回 kebab 形式，
     * 会导致 Getting unknown property 异常（v0.5.x 实测 bug）。
     *
     * 使用示例：
     * - php yii make/check --generate=y --continues-if-empty=y
     * - php yii make/check -g y -c y        （optionAliases 简写）
     * - php yii make/check --verbose=y      （显示无model表等详细列表）
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'generate',
            'continuesIfEmpty',
            'verbose',
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * 返回值同样必须使用驼峰属性名，原因同 options()。
     */
    public function optionAliases(): array
    {
        return [
            'g' => 'generate',
            'c' => 'continuesIfEmpty',
            'v' => 'verbose',
            'h' => 'help',
        ];
    }

    /**
     * 格式化两个表结构的差异明细，用于终端输出
     *
     * 方向统一为：$baseSchema（基准文件结构）→ $currentSchema（当前数据库结构）。
     * 仅列出存在差异的项；字段修改逐属性对比；comment（base64）解码后输出明文；
     * int 系列类型忽略 length 差异（与 MysqlHelper::schemaCompare 的比较口径一致）。
     *
     * @author  Bowen
     * @email   bowen@jiuchet.com
     *
     * @param array $baseSchema    基准文件中的表结构
     * @param array $currentSchema 当前数据库中的表结构
     *
     * @return array 差异明细行数组，每行为一条人类可读的差异描述；无差异时返回空数组
     */
    private function formatSchemaDiff(array $baseSchema, array $currentSchema): array
    {
        $diff  = MysqlHelper::schemaCompare($baseSchema, $currentSchema);
        $lines = [];

        // 表属性差异
        if (!empty($diff['diffs']['charset'])) {
            $lines[] = "  [表属性] charset: {$baseSchema['charset']} → {$currentSchema['charset']}";
        }
        if (!empty($diff['diffs']['engine'])) {
            $baseEngine = $baseSchema['engine'] ?? '';
            $curEngine  = $currentSchema['engine'] ?? '';
            $lines[]    = "  [表属性] engine: {$baseEngine} → {$curEngine}";
        }

        // 字段差异（greater：仅基准存在；less：仅当前库存在；diff：两边都有但定义不同）
        if (!empty($diff['fields'])) {
            foreach (($diff['fields']['less'] ?? []) as $fieldName) {
                $lines[] = "  [字段新增] {$fieldName}（仅当前库存在）";
            }
            foreach (($diff['fields']['greater'] ?? []) as $fieldName) {
                $lines[] = "  [字段缺失] {$fieldName}（仅基准文件存在）";
            }
            foreach (($diff['fields']['diff'] ?? []) as $fieldName) {
                $lines[] = "  [字段修改] {$fieldName}";
                $lines   = array_merge($lines, $this->formatFieldDiff(
                    $baseSchema['fields'][$fieldName] ?? [],
                    $currentSchema['fields'][$fieldName] ?? []
                ));
            }
        }

        // 索引差异
        if (!empty($diff['indexes'])) {
            foreach (($diff['indexes']['less'] ?? []) as $indexName) {
                $fields  = '(' . implode(', ', $currentSchema['indexes'][$indexName]['fields'] ?? []) . ')';
                $lines[] = "  [索引新增] {$indexName} {$fields}（仅当前库存在）";
            }
            foreach (($diff['indexes']['greater'] ?? []) as $indexName) {
                $lines[] = "  [索引缺失] {$indexName}（仅基准文件存在）";
            }
            foreach (($diff['indexes']['diff'] ?? []) as $indexName) {
                $lines[] = "  [索引修改] {$indexName}";
                $baseIdx = $baseSchema['indexes'][$indexName] ?? [];
                $curIdx  = $currentSchema['indexes'][$indexName] ?? [];
                if (($baseIdx['type'] ?? '') !== ($curIdx['type'] ?? '')) {
                    $lines[] = "      " . str_pad('type', 10) . ": {$baseIdx['type']} → {$curIdx['type']}";
                }
                $baseFields = '(' . implode(', ', $baseIdx['fields'] ?? []) . ')';
                $curFields  = '(' . implode(', ', $curIdx['fields'] ?? []) . ')';
                if ($baseFields !== $curFields) {
                    $lines[] = "      " . str_pad('fields', 10) . ": {$baseFields} → {$curFields}";
                }
            }
        }

        return $lines;
    }

    /**
     * 格式化单个字段的属性级差异
     *
     * 逐属性对比基准与当前的定义，仅输出存在差异的属性行。
     * comment 为 base64 编码，输出时解码为明文并截断到 30 字符。
     *
     * @author  Bowen
     * @email   bowen@jiuchet.com
     *
     * @param array $base    基准文件中的字段定义
     * @param array $current 当前数据库中的字段定义
     *
     * @return array 差异属性行数组；无差异属性时返回空数组
     */
    private function formatFieldDiff(array $base, array $current): array
    {
        $lines = [];

        // 属性行生成器：[$标签, 基准显示值, 当前显示值]
        $rows = [
            ['type', $base['type'] ?? '', $current['type'] ?? ''],
            // int 系列类型忽略 length 差异（与 MysqlHelper::schemaCompare 的比较口径一致）
            ['length', $base['length'] ?? '', $current['length'] ?? ''],
            ['null', empty($base['null']) ? 'NOT NULL' : '允许 NULL', empty($current['null']) ? 'NOT NULL' : '允许 NULL'],
            ['signed', empty($base['signed']) ? 'unsigned' : '有符号', empty($current['signed']) ? 'unsigned' : '有符号'],
            ['default', $base['default'] ?? null, $current['default'] ?? null],
            ['increment', empty($base['increment']) ? '否' : '是', empty($current['increment']) ? '否' : '是'],
            ['comment', $base['comment'] ?? '', $current['comment'] ?? ''],
        ];

        foreach ($rows as [$label, $baseValue, $currentValue]) {
            // int 系列跳过 length，避免输出与 schemaCompare 口径不一致的伪差异
            if ($label === 'length' && (
                in_array($base['type'] ?? '', ['int', 'tinyint', 'smallint', 'bigint'])
                || in_array($current['type'] ?? '', ['int', 'tinyint', 'smallint', 'bigint'])
            )) {
                continue;
            }

            // comment 解码为明文并截断，其余属性转为可读字符串
            if ($label === 'comment') {
                $baseValue   = $this->decodeComment($baseValue);
                $currentValue = $this->decodeComment($currentValue);
            } elseif ($label === 'default') {
                $baseValue   = $baseValue === null ? 'NULL' : (string)$baseValue;
                $currentValue = $currentValue === null ? 'NULL' : (string)$currentValue;
            }

            if ((string)$baseValue === (string)$currentValue) continue;
            $lines[] = "      " . str_pad($label, 10) . ": {$baseValue} → {$currentValue}";
        }

        return $lines;
    }

    /**
     * 解码 base64 编码的字段注释为明文，并截断到 30 字符
     *
     * @author  Bowen
     * @email   bowen@jiuchet.com
     *
     * @param string $comment base64 编码（或明文）的注释内容
     *
     * @return string 明文注释；超过 30 字符时截断并追加省略号
     */
    private function decodeComment(string $comment): string
    {
        $decoded = @base64_decode($comment, true);
        if ($decoded !== false && $decoded !== '') {
            $comment = $decoded;
        }
        $comment = trim($comment);
        if (mb_strlen($comment) > 30) {
            $comment = mb_substr($comment, 0, 30) . '…';
        }
        return $comment === '' ? '(空)' : $comment;
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
        $this->prepare();

        $tables    = $this->tables_db;
        $addTables = $tables;
        $delTables = [];

        set_time_limit(0);

        $schemaFileData = [];
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

        $fixSql      = [];
        $diffDetails = []; // 需要修复的表差异明细，[table_name => 差异明细行数组]，聚合到末尾输出
        $locDbs      = []; // 缓存一份儿在下面遍历时获取过的表结构
        // 根据基准文件进行数据对比
        if (!empty($schemaFileData)) {
            $this->stdout('开始与基准数据对比...' . PHP_EOL);
            $compareIndex = 0;
            $compareTotal = count($schemaFileData);
            foreach ($schemaFileData as $table => $data) {
                $compareIndex++;
                // 单行进度：慢库下逐表查询可达数秒，让用户知道进行到哪了
                $this->showProgress($compareIndex, $compareTotal, '对比中', $table);

                // 兼容性处理：如果基准文件中的表名不包含前缀，则转换为完整表名
                $fullTableName = $table;
                if (!in_array($table, $this->tables_db)) {
                    // 尝试加上前缀进行匹配
                    $fullTableName = MysqlHelper::tableName($table);
                }

                // 需要添加的表
                $addTables = array_filter($addTables, function ($v) use ($fullTableName) {
                    return $v != $fullTableName;
                });

                // 需要删除的表
                if (!empty($tables) && !in_array($fullTableName, $tables))
                    $delTables[] = $fullTableName;

                try {
                    $locDbs[$fullTableName] = MysqlHelper::getTableSchema($fullTableName, true, true, [
                        'resetTableIncrement' => true
                    ]);

                    // 确保字段注释是base64编码的（兼容性处理）
                    if (!empty($locDbs[$fullTableName]['fields'])) {
                        foreach ($locDbs[$fullTableName]['fields'] as $fieldName => &$field) {
                            if (!empty($field['comment']) && !Util::is_base64($field['comment'])) {
                                $field['comment'] = base64_encode($field['comment']);
                            }
                        }
                    }
                } catch (Exception $e) {
                    // 清掉进度行再输出错误明细，避免错误信息与进度行混在一行
                    $this->clearProgress();
                    $this->stdout("获取 {$fullTableName} 表结构失败" . PHP_EOL, Console::FG_RED);
                    $this->stdout($e->getCode() . PHP_EOL, Console::FG_RED);
                    $this->stdout($e->getMessage() . PHP_EOL, Console::FG_RED);
                    continue;
                }
                // 以数据表结构为准，对比基准数据结构
                $allSql = MysqlHelper::makeFixSql($data, $locDbs[$fullTableName], true);
                if (!empty($allSql)) {
                    $fixSql[$fullTableName] = $allSql;
                    // 结构一致的表静默处理，差异明细聚合到末尾汇总区统一输出，避免刷屏
                    $diffDetails[$fullTableName] = $this->formatSchemaDiff($data, $locDbs[$fullTableName]);
                    $this->clearProgress();
                    $this->stdout("【{$fullTableName}】需要修复" . PHP_EOL, Console::FG_BLUE);
                }
            }
            $this->clearProgress();
        } else {
            $this->stdout('基准文件不存在，跳过对比' . PHP_EOL, Console::FG_YELLOW);
        }

        // ----- 统计对比数据 ----- /
        $noModelTables = array_filter($this->tables_db, function ($v) {
            return !in_array($v, $this->tables_model);
        });

        // 基准文件中缺失的表（数据库有、基准文件没有）拆分两类，避免无model表淹没真正需要关注的信号：
        // - 基准缺失且有model：基准落后，真正需要重新生成基准文件的表
        // - 基准缺失且无model：filterNoModelTable=true 时生成基准文件会主动跳过这些表，属正常现象
        $addNoModel = $this->filterNoModelTable
            ? array_values(array_filter($addTables, function ($v) use ($noModelTables) {
                return in_array($v, $noModelTables);
            }))
            : [];
        $addWithModel = array_values(array_diff($addTables, $addNoModel));

        $count           = count($fixSql);        // 结构差异需要修复的表
        $countAdd        = count($addWithModel);  // 基准文件缺失（filterNoModelTable=false 时含无model表）
        $countAddNoModel = count($addNoModel);    // 基准文件缺失且无model的表（已被过滤，属正常）
        $countDel        = count($delTables);     // 基准文件有但数据库已删除的表
        $countNoModel    = count($noModelTables); // 没有生成model的表
        $countNeed       = $count + $countAdd + $countDel;
        $showVerbose     = strtolower($this->verbose) === 'y';
        $this->stdout("对比完成，需要处理{$countNeed}张表（结构差异{$count} + 基准缺失{$countAdd} + 已删除{$countDel}）" . PHP_EOL, Console::FG_BLUE);

        if (!empty($countNoModel)) {
            // 无model表默认只报数量：大项目中可达数百张，且与基准缺失表高度重叠，全量输出会刷屏
            $this->stdout("有{$countNoModel}张表没有生成model" .
                ($showVerbose ? "：" . PHP_EOL . implode(PHP_EOL, $noModelTables) . PHP_EOL : '（--verbose=y 可查看列表）') . PHP_EOL, Console::FG_BLUE);
        } else {
            $this->stdout('Done：所有表都生成了model' . PHP_EOL, Console::FG_GREEN);
        }
        if (!empty($countDel)) {
            $this->stdout("基准文件中存在但数据库已删除{$countDel}张表：" . PHP_EOL, Console::FG_YELLOW);
            $this->stdout(implode(PHP_EOL, $delTables) . PHP_EOL);
        } else {
            $this->stdout('Done：没有删除表' . PHP_EOL, Console::FG_GREEN);
        }
        if (!empty($countAdd)) {
            $this->stdout("基准文件中缺少{$countAdd}张表：" . PHP_EOL, Console::FG_BLUE);
            $this->stdout(implode(PHP_EOL, $addWithModel) . PHP_EOL);
        } else {
            $this->stdout('Done：没有缺少基准的表' . PHP_EOL, Console::FG_GREEN);
        }
        if (!empty($countAddNoModel)) {
            $this->stdout("另有{$countAddNoModel}张无model的表未纳入基准（已按 filterNoModelTable 配置忽略）" . PHP_EOL, Console::FG_YELLOW);
        }
        if (!empty($count)) {
            $this->stdout(PHP_EOL . "── 以下 {$count} 张表存在结构差异（基准文件 → 当前数据库）──" . PHP_EOL, Console::FG_BLUE);
            foreach ($fixSql as $table => $sql) {
                $this->stdout(PHP_EOL . "【{$table}】" . PHP_EOL);
                foreach ($diffDetails[$table] ?? [] as $line) {
                    $this->stdout($line . PHP_EOL);
                }
                $this->stdout('  修复SQL：' . PHP_EOL);
                foreach ($sql as $s) {
                    $this->stdout("    {$s}" . PHP_EOL);
                }
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
        $this->prepare();

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

        // verbose 模式下保持旧的逐表「成功/失败」输出；默认输出单行进度，避免大项目数百行刷屏
        $showVerbose = strtolower($this->verbose) === 'y';

        $dbSchema      = [];
        $schemaOk      = 0;  // 成功获取的表数
        $schemaFail    = 0;  // 获取失败的表数
        $schemaContext = 0;  // 复用上下文表结构的表数
        $schemaTotal   = count($tables);
        $schemaIndex   = 0;
        foreach ($tables as $table) {
            $schemaIndex++;
            // 存储到基准文件时，表名键名去除前缀，保持原有约定
            $schemaKey = MysqlHelper::removeTablePrefix($table);
            if (!empty($dbSchemaContext[$table])) {
                $dbSchema[$schemaKey] = $dbSchemaContext[$table];
                $schemaContext++;
                if ($showVerbose) {
                    $this->stdout("获取【{$table}】表结构：复用上下文" . PHP_EOL, Console::FG_BLUE);
                } else {
                    $this->showProgress($schemaIndex, $schemaTotal, '获取表结构', "{$table}（复用上下文）");
                }
            } else {
                if ($showVerbose) {
                    $this->stdout('获取【' . $table . '】表结构：');
                } else {
                    $this->showProgress($schemaIndex, $schemaTotal, '获取表结构', $table);
                }
                try {
                    $dbSchema[$schemaKey] = MysqlHelper::getTableSchema($table, true, true, [
                        'resetTableIncrement' => true
                    ]);
                } catch (Exception $e) {
                    if ($showVerbose) $this->stdout('失败' . PHP_EOL, Console::FG_RED);
                    else $this->clearProgress();
                    $this->stdout("获取【{$table}】表结构失败: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);
                    $schemaFail++;
                    continue;
                }
                if (empty($dbSchema[$schemaKey])) {
                    if ($showVerbose) $this->stdout('失败' . PHP_EOL, Console::FG_RED);
                    else $this->clearProgress();
                    $this->stdout("获取【{$table}】表结构失败: 返回空结构" . PHP_EOL, Console::FG_RED);
                    $schemaFail++;
                    continue;
                }
                if ($showVerbose) {
                    $this->stdout('成功' . PHP_EOL, Console::FG_GREEN);
                }
                $schemaOk++;
            }
        }
        if (!$showVerbose) $this->clearProgress();

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
        $insertOk    = 0; // 成功获取insert的表数
        $insertFail  = 0; // 获取失败的表数
        $insertTotal = count($this->insertTables);
        $insertIndex = 0;
        foreach ($this->insertTables as $insertTable => $option) {
            $insertIndex++;
            // 统一转换为完整表名（包含前缀）用于显示与索引，保持与数据库一致
            $fullInsertTableName = MysqlHelper::tableName($insertTable);
            if ($showVerbose) {
                $this->stdout('获取【' . $fullInsertTableName . '】表insert语句：');
            } else {
                $this->showProgress($insertIndex, $insertTotal, '获取insert语句', $fullInsertTableName);
            }
            try {
                $data = MysqlHelper::tableInsertSql($insertTable, $option);
            } catch (Exception $e) {
                if ($showVerbose) $this->stdout('失败' . PHP_EOL, Console::FG_RED);
                else $this->clearProgress();
                $this->stdout("获取【{$fullInsertTableName}】insert语句失败: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);
                $insertFail++;
                continue;
            }
            if (empty($data)) {
                if ($showVerbose) $this->stdout('失败' . PHP_EOL, Console::FG_RED);
                else $this->clearProgress();
                $this->stdout("获取【{$fullInsertTableName}】insert语句失败: 无数据" . PHP_EOL, Console::FG_RED);
                $insertFail++;
                continue;
            }
            if ($showVerbose) {
                $this->stdout('成功' . PHP_EOL, Console::FG_GREEN);
            }
            // 使用完整表名作为索引键，便于阅读与一致性
            $dbInsertSql[$fullInsertTableName] = $data['sql'];
            $insertOk++;
        }
        if (!$showVerbose && !empty($this->insertTables)) $this->clearProgress();
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

        // 汇总统计：无论 TTY 还是管道模式都输出，便于快速判断本次生成的整体结果
        if (!empty($schemaTotal)) {
            $summary = "表结构获取完成：成功{$schemaOk}张";
            if ($schemaContext > 0) $summary .= "（其中复用上下文{$schemaContext}张）";
            if ($schemaFail > 0) $summary .= "，失败{$schemaFail}张";
            $this->stdout($summary . PHP_EOL, $schemaFail > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        }
        if (!empty($insertTotal)) {
            $summary = "insert语句获取完成：成功{$insertOk}张";
            if ($insertFail > 0) $summary .= "，失败{$insertFail}张";
            $this->stdout($summary . PHP_EOL, $insertFail > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        }

        $this->stdout('执行完毕' . PHP_EOL, Console::FG_BLUE);
    }
}
