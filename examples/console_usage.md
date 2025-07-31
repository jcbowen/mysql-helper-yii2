# MySQL Helper Yii2 命令行使用示例

## 基本命令

### 1. 检查数据库结构变化

```bash
# 基本检查（交互模式）
php yii make/check

# 非交互模式
php yii make/check --generate=y --continues-if-empty=y

# 简写形式
php yii make/check -g y -c y
```

### 2. 生成数据库基准文件

```bash
# 生成基准文件
php yii make/db
```

## 配置示例

### 1. 基本配置

```php
<?php
// 在控制器中配置
class MakeController extends \Jcbowen\MysqlHelperYii2\controllers\MakeController
{
    // 是否过滤没有数据模型的表
    public $filterNoModelTable = false;
    
    // 不跟踪的表名
    public $ignoreTables = ['migration', 'cache', 'session'];
    
    // 数据库基准文件所在目录
    public $dir = '@console/runtime/update/db';
    
    // 模型所在目录（单目录）
    public $modelsDir = '@common/models';
}
```

### 2. 多目录配置

```php
<?php
// 支持多个模型目录
class MakeController extends \Jcbowen\MysqlHelperYii2\controllers\MakeController
{
    // 多目录配置
    public $modelsDir = [
        '@common/models',      // 公共模型
        '@backend/models',     // 后台模型
        '@frontend/models',    // 前台模型
        '@api/models',         // API 模型
    ];
    
    // 其他配置...
}
```

### 3. 数据插入配置

```php
<?php
// 配置需要生成 insert 语句的表
class MakeController extends \Jcbowen\MysqlHelperYii2\controllers\MakeController
{
    public $insertTables = [
        // 用户表：清空后插入
        'user' => [
            'truncate' => true,
        ],
        
        // 设置表：存在就不插入
        'setting' => [
            'ignore' => true,
        ],
        
        // 路由表：自定义查询条件
        'router' => [
            'truncate' => true,
            'getInsertDataQuery' => function ($query) {
                return $query->where(['status' => 1]);
            }
        ],
        
        // VIP表：复杂配置
        'vip' => [
            'ignore' => true,
            'batchSize' => 50,
            'getInsertDataQuery' => function ($query) {
                return $query->where(['type' => 'premium'])
                            ->andWhere(['>', 'expire_time', time()]);
            }
        ],
    ];
}
```

## 使用场景示例

### 1. 开发环境日常使用

```bash
# 检查当前数据库结构是否有变化
php yii make/check

# 如果有变化，生成新的基准文件
php yii make/check --generate=y
```

### 2. 生产环境部署

```bash
# 检查生产环境数据库结构
php yii make/check --continues-if-empty=y

# 生成生产环境的基准文件
php yii make/db
```

### 3. 团队协作

```bash
# 团队成员A：修改表结构后生成基准文件
php yii make/db

# 团队成员B：检查是否有新的结构变化
php yii make/check
```

## 输出示例

### 检查命令输出

```
开始获取数据库基准文件...
开始与基准数据对比...
对比【user】结果：结构一致，无需修复
对比【order】结果：需要修复
对比【product】结果：结构一致，无需修复

对比完成，共计1张表需要处理
Done：所有表都生成了model
Done：没有删除表
Done：没有新增表
修复sql语句如下：
【order】
ALTER TABLE `jc_order` ADD `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '订单状态';

存在差异，是否生成新的基准文件？[Y/N]
```

### 生成命令输出

```
开始执行数据表基准文件生成
获取【user】表结构：成功
获取【order】表结构：成功
获取【product】表结构：成功
所有表结构获取成功，正在生成基准文件
数据表结构基准文件生成成功！

获取【user】表insert语句：成功
获取【setting】表insert语句：成功
所有insert语句获取成功，正在生成基准文件
数据表insert语句基准文件生成成功！

执行完毕
```

## 错误处理

### 1. 目录不存在警告

```
模型目录不存在: @invalid/models (/path/to/invalid/models)
```

### 2. 表结构获取失败

```
获取【invalid_table】表结构：
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'database.invalid_table' doesn't exist
```

### 3. 文件权限错误

```
数据表结构基准文件生成失败！
```

## 最佳实践

### 1. 配置管理

- 将配置放在独立的配置文件中
- 使用环境变量控制不同环境的配置
- 定期备份基准文件

### 2. 团队协作

- 在版本控制中包含基准文件
- 团队成员修改表结构后及时更新基准文件
- 使用统一的配置标准

### 3. 生产环境

- 在生产环境使用前充分测试
- 备份重要数据
- 使用非交互模式避免人工干预

### 4. 性能优化

- 合理配置忽略的表
- 避免扫描不必要的目录
- 使用批量处理减少内存占用 