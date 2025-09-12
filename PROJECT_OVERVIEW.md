# MySQL Helper Yii2 项目功能概览

## 项目简介

MySQL Helper Yii2 是一个专为 Yii2 框架设计的 MySQL 数据库维护助手，提供完整的数据库结构管理解决方案。该项目特别适用于需要频繁进行数据库结构变更和版本管理的 Yii2 项目。

## 核心组件

### 1. MysqlHelper 类 (`src/components/MysqlHelper.php`)

这是项目的核心工具类，提供所有数据库操作的基础功能。

#### 主要功能模块：

**数据库信息获取**
- `getDBName()` - 获取数据库名称
- `getAllTables()` - 获取所有表名
- `tableName()` - 处理表名和前缀
- `removeTablePrefix()` - 安全地去除表前缀

**表结构管理**
- `getTableSchema()` - 获取完整的表结构信息
- `getTableSerialize()` - 序列化数据库结构
- `makeCreateSql()` - 生成建表 SQL
- `makeDropSql()` - 生成删表 SQL

**差异对比和修复**
- `schemaCompare()` - 比较两个表结构
- `makeFixSql()` - 生成修复 SQL 语句

**数据操作**
- `tableInsertSql()` - 生成数据插入 SQL

### 2. MakeController 类 (`src/controllers/MakeController.php`)

提供命令行工具，用于自动化数据库结构管理。

#### 主要功能：

**配置管理**
- 支持多模型目录配置
- 可配置忽略的表
- 支持自定义数据插入配置

**命令操作**
- `actionCheck()` - 检查数据库结构变化
- `actionDb()` - 生成数据库基准文件

## 功能特性详解

### 1. 多目录模型支持

```php
// 支持配置多个模型目录
public $modelsDir = [
    '@common/models',      // 公共模型
    '@backend/models',     // 后台模型
    '@frontend/models',    // 前台模型
];

// 也支持单个目录字符串配置
public $modelsDir = '@common/models';
```

**优势：**
- 适用于 Yii2 高级模板项目
- 自动扫描所有配置的目录
- 智能去重和排序
- 向后兼容单目录配置

### 2. 智能表结构比较

```php
// 比较表结构差异
$diff = MysqlHelper::schemaCompare($table1, $table2);

// 生成修复 SQL
$fixSql = MysqlHelper::makeFixSql($current, $target, true);
```

**支持比较的内容：**
- 字段差异（新增、删除、修改）
- 索引差异（主键、唯一索引、普通索引）
- 表属性差异（引擎、字符集等）
- 字段注释处理（支持 Base64 编码存储）

### 3. 灵活的配置选项

```php
// 忽略特定表
public $ignoreTables = ['migration', 'cache'];

// 自定义数据插入
public $insertTables = [
    'user' => [
        'truncate' => true,
        'ignore' => false,
        'getInsertDataQuery' => function($query) {
            return $query->where(['status' => 1]);
        }
    ]
];
```

### 4. 表前缀智能处理

```php
// 自动处理表前缀
$fullTableName = MysqlHelper::tableName('user'); // 自动添加前缀
$simpleName = MysqlHelper::removeTablePrefix('jc_user'); // 安全去除前缀

// 支持多种表名格式
$table1 = MysqlHelper::tableName('{{%user}}'); // Yii2 格式
$table2 = MysqlHelper::tableName('user'); // 简单格式
```

**特性：**
- 自动识别和添加表前缀
- 安全地去除表前缀
- 支持 Yii2 表名格式 `{{%table}}`
- 向后兼容旧版本基准文件

### 5. 命令行工具集成

```bash
# 检查结构变化
php yii make/check

# 生成基准文件
php yii make/db

# 非交互模式
php yii make/check --generate=y --continues-if-empty=y
```

## 技术架构

### 设计模式

1. **单例模式** - MysqlHelper 使用静态方法，避免重复实例化
2. **策略模式** - 支持不同的表结构比较策略
3. **配置模式** - 灵活的配置选项支持

### 核心算法

1. **表结构解析算法**
   - 解析 MySQL SHOW 命令结果
   - 处理字段类型和约束
   - 提取索引信息

2. **差异检测算法**
   - 递归比较数组结构
   - 智能处理字段类型差异
   - 生成最小化修复 SQL

3. **SQL 生成算法**
   - 构建标准 SQL 语句
   - 处理特殊字符和编码
   - 优化 SQL 执行顺序
   - 智能处理字段注释（Base64 编码兼容）

## 使用场景

### 1. 开发环境管理

- 开发人员修改表结构后，自动生成修复 SQL
- 团队协作时，统一数据库结构版本
- 快速回滚数据库变更

### 2. 生产环境部署

- 自动化数据库迁移
- 安全的结构变更验证
- 备份和恢复支持

### 3. 多环境同步

- 开发、测试、生产环境结构同步
- 跨项目数据库结构复用
- 版本控制和审计

## 性能优化

### 1. 缓存机制

- 数据库连接缓存
- 表结构信息缓存
- 文件扫描结果缓存

### 2. 批量处理

- 批量表结构获取
- 批量 SQL 生成
- 批量数据插入

### 3. 内存管理

- 大文件分块处理
- 及时释放内存
- 避免内存泄漏

## 扩展性设计

### 1. 插件化架构

- 支持自定义比较策略
- 支持自定义 SQL 生成器
- 支持自定义数据处理器

### 2. 配置驱动

- 基于配置的功能开关
- 灵活的目录配置
- 可扩展的选项系统

### 3. 事件系统

- 支持操作前/后事件
- 支持错误处理事件
- 支持日志记录事件

## 安全考虑

### 1. SQL 注入防护

- 使用参数化查询
- 输入验证和过滤
- 安全的字符串处理

### 2. 权限控制

- 数据库权限验证
- 文件系统权限检查
- 操作权限控制

### 3. 错误处理

- 友好的错误信息
- 详细的日志记录
- 优雅的异常处理

## 未来规划

### 1. 功能增强

- 支持更多数据库类型
- 增强的差异检测算法
- 可视化界面支持

### 2. 性能优化

- 异步处理支持
- 分布式处理能力
- 更高效的缓存策略

### 3. 生态集成

- 与更多 Yii2 扩展集成
- CI/CD 流程集成
- 云服务集成

## 系统要求

### 依赖关系

- **PHP**: >= 7.2.0 < 8.0.0
- **Yii2**: >= 2.0.11
- **jcbase-yii2**: >= 0.30.1

### 扩展要求

- **PDO**: MySQL 数据库驱动
- **ctype**: 字符类型检测扩展