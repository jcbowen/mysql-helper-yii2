# MySQL Helper Yii2 命令行使用示例

## 基本命令

### 1. 检查数据库结构变化

```bash
# 基本检查（交互模式）
php yii make/check

# 非交互模式（存在差异时直接重新生成基准文件）
php yii make/check --generate=y --continues-if-empty=y

# 简写形式
php yii make/check -g y -c y

# 显示详细列表（无model表清单等，默认仅显示数量避免大项目刷屏）
php yii make/check -v y
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

    // 不跟踪的表名（同时匹配去前缀名与完整表名，如模型写死 sl_client_version，配置写 client_version 或 sl_client_version 均生效）
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

结构一致的表静默处理；无model表、基准缺失表等分类统计在对比完成后输出；存在结构差异的表聚合到末尾汇总区，逐表输出「差异明细 + 修复SQL」：

```
正在连接数据库并扫描模型目录...
开始获取数据库基准文件...
开始与基准数据对比...
对比中 [123/184] sl_order        ← 终端（TTY）下单行进度刷新，管道/CI 自动隐藏
【jc_order】需要修复
对比完成，需要处理2张表（结构差异1 + 基准缺失1 + 已删除0）
有13张表没有生成model（--verbose=y 可查看列表）
Done：没有删除表
基准文件中缺少1张表：
jc_setting
另有13张无model的表未纳入基准（已按 filterNoModelTable 配置忽略）

── 以下 1 张表存在结构差异（基准文件 → 当前数据库）──

【jc_order】
  [字段新增] status（仅当前库存在）
  修复SQL：
    ALTER TABLE `jc_order` ADD `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '订单状态';

存在差异，是否生成新的基准文件？[Y/N]
```

**输出说明：**

- 「正在连接数据库并扫描模型目录...」为准备阶段提示，避免慢库/大模型目录时被误认为卡死
- 「对比中 [i/N]」为单行进度（\r 覆盖刷新），仅在真实终端显示；管道重定向、CI、git hooks 等非终端环境自动隐藏，失败与差异明细始终输出
- 「需要处理N张表」仅统计真实信号：结构差异 + 基准缺失 + 已删除，不含无model表
- 「没有生成model」默认仅显示数量，加 `-v y` / `--verbose=y` 展开列表
- 「基准文件中缺少N张表」为基准落后于数据库的表（有model），是重新生成基准文件的主要信号；若 `filterNoModelTable=true`，无model表的缺失会单独归入「未纳入基准」提示
- 「结构差异」汇总区方向统一为「基准文件 → 当前数据库」，便于判断差异是否应当被接纳（重新生成基准文件）

### 生成命令输出

默认模式逐表「成功」输出收敛为单行进度，完成时输出汇总统计；`-v y` 恢复逐表输出：

```
开始执行数据表基准文件生成
获取表结构 [123/184] sl_order    ← 终端（TTY）下单行进度刷新
数据表结构基准文件生成成功！
数据表insert语句基准文件生成成功！
表结构获取完成：成功184张
insert语句获取完成：成功7张
执行完毕
```

若从 `make/check` 的确认流程进入（存在差异并选择重新生成），对比阶段已获取过的表结构会直接复用，汇总中体现为「复用上下文N张」：

```
表结构获取完成：成功14张（其中复用上下文170张）
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