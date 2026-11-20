# MySQL Helper Yii2

[![Latest Stable Version](https://img.shields.io/packagist/v/jcbowen/mysql-helper-yii2.svg)](https://packagist.org/packages/jcbowen/mysql-helper-yii2)
[![Total Downloads](https://img.shields.io/packagist/dt/jcbowen/mysql-helper-yii2.svg)](https://packagist.org/packages/jcbowen/mysql-helper-yii2)
[![License](https://img.shields.io/packagist/l/jcbowen/mysql-helper-yii2.svg)](https://packagist.org/packages/jcbowen/mysql-helper-yii2)

基于 Yii2 的 MySQL 维护助手，提供数据库表结构管理、差异对比、修复 SQL 生成等功能。支持多模块项目的模型目录配置，适用于复杂的 Yii2 高级模板项目。

## 功能特性

- 🔍 **数据库结构管理** - 获取、存储和比较数据库表结构
- 🔄 **差异检测** - 自动检测表结构变化并生成修复 SQL
- 📝 **SQL 生成** - 生成建表、修改、删除等 SQL 语句
- 🎯 **多目录支持** - 支持配置多个模型目录（适用于 Yii2 高级模板）
- 🛠️ **命令行工具** - 提供便捷的命令行操作接口
- 🔒 **向后兼容** - 保持与现有配置的兼容性

## 系统要求

- PHP >= 7.2.0 < 8.0.0
- Yii2 >= 2.0.11
- jcbase-yii2 >= 0.30.3

## 安装

```bash
composer require jcbowen/mysql-helper-yii2
```

## 快速开始

### 1. 基本使用

```php
use Jcbowen\MysqlHelperYii2\components\MysqlHelper;

// 获取数据库名称
$dbName = MysqlHelper::getDBName();

// 获取完整表名（自动处理表前缀）
$tableName = MysqlHelper::tableName('user');

// 获取表结构
$schema = MysqlHelper::getTableSchema('user');

// 获取所有表名
$tables = MysqlHelper::getAllTables();
```

### 2. 表结构比较和修复

```php
// 比较两个表结构
$diff = MysqlHelper::schemaCompare($table1Schema, $table2Schema);

// 生成修复 SQL
$fixSql = MysqlHelper::makeFixSql($currentSchema, $targetSchema, true);
```

### 3. 命令行工具

```bash
# 检查数据库表结构变化
php yii make/check

# 生成数据库基准文件
php yii make/db

# 使用非交互模式
php yii make/check --generate=y
php yii make/check --continues-if-empty=y
```

## 详细功能

### MysqlHelper 类

#### 数据库操作

```php
// 获取数据库名称
MysqlHelper::getDBName(?string $dsn = '', string $db = 'db'): string

// 获取所有表名
MysqlHelper::getAllTables(string $db = 'db'): array

// 获取完整表名（处理前缀）
MysqlHelper::tableName(string $tableName, string $db = 'db'): string
```

#### 表结构操作

```php
// 获取表结构
MysqlHelper::getTableSchema(
    string $tableName = '', 
    bool $getDefault = true, 
    bool $getComment = true, 
    array $options = []
): array

// 获取数据库所有表的序列化结构
MysqlHelper::getTableSerialize(string $dbname = '', string $db = 'db'): string

// 根据结构生成建表语句
MysqlHelper::makeCreateSql(array $schema): string

// 根据表名生成删除表语句
MysqlHelper::makeDropSql(string $tableName): string
```

#### 差异对比和修复

```php
// 比较两张表的结构
MysqlHelper::schemaCompare(array $table1, array $table2): array

// 创建修复两张差异表的语句
MysqlHelper::makeFixSql(
    array $schema1, 
    array $schema2, 
    bool $strict = false
): array
```

#### 数据操作

```php
// 获取指定表的 insert 语句
MysqlHelper::tableInsertSql(
    string $tableName, 
    array $options = [], 
    $db = null
): array|false
```

### MakeController 类

#### 配置选项

```php
class MakeController extends \Jcbowen\MysqlHelperYii2\controllers\MakeController
{
    /**
     * 是否过滤没有数据模型的表
     */
    public $filterNoModelTable = false;
    
    /**
     * 不跟踪的表名（同时匹配去前缀名与完整表名：模型写死 sl_client_version 时，配置 client_version 或 sl_client_version 均生效）
     */
    public $ignoreTables = ['migration', 'cache'];
    
    /**
     * 需要生成 insert 语句的表配置
     */
    public $insertTables = [
        'user' => [
            'truncate' => true, // 清空后插入
        ],
        'setting' => [
            'ignore' => true, // 存在就不插入
        ],
    ];
    
    /**
     * 数据库基准文件所在目录
     */
    public $dir = '@console/runtime/update/db';
    
    /**
     * 模型所在目录（支持多目录）
     */
    public $modelsDir = [
        '@common/models',      // 公共模型
        '@backend/models',     // 后台模型
        '@frontend/models',    // 前台模型
    ];
}
```

#### 多目录配置示例

```php
// 单目录配置（向后兼容）
public $modelsDir = '@common/models';

// 多目录配置（新功能）
public $modelsDir = [
    '@common/models',
    '@backend/models', 
    '@frontend/models',
    '@api/models',
];
```

## 使用场景

### 1. 数据库结构版本管理

```php
// 生成当前数据库结构的基准文件
$controller = new MakeController();
$controller->actionDb();

// 检查结构变化
$controller->actionCheck();
```

### 2. 多模块项目支持

```php
// 配置多个模型目录
public $modelsDir = [
    '@common/models',      // 公共模型
    '@backend/models',     // 后台模型
    '@frontend/models',    // 前台模型
    '@api/models',         // API 模型
];
```

### 3. 自定义数据插入

```php
// 配置需要生成 insert 语句的表
public $insertTables = [
    'router' => [
        'truncate' => true, // 清空后插入
    ],
    'vip' => [
        'ignore' => true, // 存在就不插入
        'getInsertDataQuery' => function ($query) {
            return $query->where(['status' => 1]);
        }
    ],
];
```

## 命令行选项

### make/check 命令

```bash
# 基本检查
php yii make/check

# 非交互模式
php yii make/check --generate=y --continues-if-empty=y

# 简写形式
php yii make/check -g y -c y

# 显示详细列表（无model表清单等，默认仅显示数量避免大项目刷屏）
php yii make/check -v y
```

选项说明：

| 选项 | 简写 | 说明 |
|------|------|------|
| `--generate` | `-g` | 检查完毕，如果存在差异，是否生成基准文件（`y` 跳过交互直接生成） |
| `--continues-if-empty` | `-c` | 如果基准文件为空，是否继续检查（`y` 跳过交互继续检查） |
| `--verbose` | `-v` | 是否显示详细列表（无model表清单等） |

### make/db 命令

```bash
# 生成数据库基准文件
php yii make/db
```

## 注意事项

1. **表前缀处理**：工具会自动处理 Yii2 的表前缀配置；基准文件的表键统一为去前缀名，`make/check` 对比时统一加前缀转换为完整表名，因此**同一数据库中即使存在与基准键同名的不带前缀表**（如库中同时有 `member` 与 `sl_member`），也不会误将基准键匹配到不带前缀的表而误报「基准缺失」
2. **字段默认值**：已有字段如果之前被设置为 NULL，后续在设置基准表时，一定不要设置为 NOT NULL，否则会报错
3. **目录验证**：多目录配置会自动验证目录存在性，不存在的目录会被跳过并显示警告
4. **性能考虑**：大量目录可能影响扫描性能，建议合理配置

## 错误处理

当遇到目录不存在时，会显示类似以下的警告信息：
```
模型目录不存在: @invalid/models (/path/to/invalid/models)
```

该警告不会中断程序执行，会继续扫描其他有效目录。

## 示例和文档

- [基本使用示例](examples/basic_usage.php) - PHP 代码使用示例
- [命令行使用示例](examples/console_usage.md) - 命令行工具使用指南
- [项目功能概览](PROJECT_OVERVIEW.md) - 详细的功能和架构说明

## 发版流程

发版脚本（`scripts/release.mjs`，方案参考 jcbaseGo）：打 `vX.Y.Z` 轻量 tag 并（可选）推送 origin，Packagist 自动同步新版本。

流程：选择版本（patch/minor/major × 正式/-alpha/-beta/-rc 两步菜单，或手动输入）→ 轻量门禁（php -l 检查 src + examples 全部文件 + composer validate --strict）→ 打 tag → 交互询问是否推送（默认推送，Esc/取消 = 仅保留本地）。

### VSCode 任务（推荐入口）

| 任务 | 说明 |
|------|------|
| 发版（打 tag / 交互选择是否推送） | 版本号留空时终端交互选择 |
| 发版演练（--dry-run） | 门禁照跑，tag 与推送只打印不执行 |
| 只跑发版门禁（--check） | 发版前单独自检，不创建 tag、不推送 |

### 终端用法

```bash
# 全交互发版（版本号留空时菜单选择）
node scripts/release.mjs

# 显式版本号（仍会询问是否推送）
node scripts/release.mjs v0.5.14

# 只跑门禁
node scripts/release.mjs --check

# 演练：门禁照跑，tag/推送只打印不执行
node scripts/release.mjs v0.5.14 --dry-run
```

### 口径说明

- 同一 core 版本号（vX.Y.Z）只允许出现一次（正式版或某个预发布阶段）；
- 历史 tag 兼容两位版本号（如 v0.2、v0.5），但新发版必须三位；
- 工作区有未提交改动时警告（不阻断）：tag 指向 HEAD，未提交改动不进入发版内容；
- 本地同名 tag 已存在且指向 HEAD 时视为上次未完成的发布，复用不重建；指向其他提交则报错；
- 非交互终端（CI/管道）未显式给版本号时报错并列出候选，不会隐式取默认。

## 贡献

欢迎提交 Issue 和 Pull Request 来改进这个项目。

## 许可证

本项目基于 MIT 许可证开源。

## 作者

- **Bowen** - [bowen@jiuchet.com](mailto:bowen@jiuchet.com)

## 更新日志

### v0.x-dev
- 🐛 修复 `make/check` 在同一数据库存在与基准键同名的不带前缀表（如库中同时有 `member` 与 `sl_member`）时，基准键被误匹配到不带前缀的表，导致 `sl_member` 等被误报「基准缺失」的问题；现基准键统一经 `tableName()` 转换为完整表名后再对比
- 🐛 修复 `-c` / `--continues-if-empty` 选项导致 `Getting unknown property` 崩溃的问题（options/optionAliases 统一返回驼峰属性名）
- ✨ `make/check` 输出优化：统计口径修正为真实待处理量（结构差异 + 基准缺失 + 已删除）；「基准缺失」拆分有model表（真信号）与无model表（已过滤，仅提示数量），无model表列表默认隐藏，`-v` 展示
- ✨ `make/check` / `make/db` 新增单行进度显示（\r 覆盖刷新），仅在真实终端显示，管道/CI/git hooks 自动隐藏；`make/db` 逐表「成功」输出默认收敛，`-v` 恢复；`make/db` 完成时输出汇总统计（成功/失败/复用上下文张数）
- ✨ 新增 `-v` / `--verbose` 选项
- 🐛 修复 `ignoreTables` 无法匹配写死完整表名（含前缀）模型的问题，现同时匹配去前缀名与完整表名
- ✨ 新增多模型目录支持功能
- 🔧 优化表结构扫描逻辑
- 📝 完善文档和注释
- 🐛 修复向后兼容性问题
- 📚 新增详细的使用示例和文档
