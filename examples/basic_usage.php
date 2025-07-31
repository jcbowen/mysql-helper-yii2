<?php
/**
 * MySQL Helper Yii2 基本使用示例
 * 
 * @author  Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2024/12/19
 */

use Jcbowen\MysqlHelperYii2\components\MysqlHelper;

// 示例1: 基本数据库操作
echo "=== 基本数据库操作 ===\n";

// 获取数据库名称
$dbName = MysqlHelper::getDBName();
echo "当前数据库: {$dbName}\n";

// 获取所有表名
$tables = MysqlHelper::getAllTables();
echo "数据库中的表: " . implode(', ', $tables) . "\n";

// 处理表名（自动添加前缀）
$tableName = MysqlHelper::tableName('user');
echo "完整表名: {$tableName}\n";

// 示例2: 表结构操作
echo "\n=== 表结构操作 ===\n";

// 获取表结构
$schema = MysqlHelper::getTableSchema('user');
if (!empty($schema)) {
    echo "表结构获取成功，字段数量: " . count($schema['fields']) . "\n";
    
    // 显示字段信息
    foreach ($schema['fields'] as $fieldName => $field) {
        echo "  - {$fieldName}: {$field['type']}";
        if (!empty($field['length'])) {
            echo "({$field['length']})";
        }
        if (!$field['null']) {
            echo " NOT NULL";
        }
        if ($field['increment']) {
            echo " AUTO_INCREMENT";
        }
        echo "\n";
    }
} else {
    echo "表结构获取失败\n";
}

// 示例3: 生成建表SQL
echo "\n=== 生成建表SQL ===\n";
if (!empty($schema)) {
    $createSql = MysqlHelper::makeCreateSql($schema);
    echo "建表SQL:\n{$createSql}\n";
}

// 示例4: 表结构比较
echo "\n=== 表结构比较 ===\n";

// 模拟两个不同的表结构
$schema1 = [
    'tableName' => 'user',
    'fields' => [
        'id' => ['name' => 'id', 'type' => 'int', 'length' => '11', 'null' => false, 'increment' => true],
        'name' => ['name' => 'name', 'type' => 'varchar', 'length' => '255', 'null' => false],
        'email' => ['name' => 'email', 'type' => 'varchar', 'length' => '255', 'null' => false],
    ],
    'indexes' => [
        'PRIMARY' => ['name' => 'PRIMARY', 'type' => 'primary', 'fields' => ['id']],
    ]
];

$schema2 = [
    'tableName' => 'user',
    'fields' => [
        'id' => ['name' => 'id', 'type' => 'int', 'length' => '11', 'null' => false, 'increment' => true],
        'name' => ['name' => 'name', 'type' => 'varchar', 'length' => '255', 'null' => false],
        'email' => ['name' => 'email', 'type' => 'varchar', 'length' => '255', 'null' => false],
        'phone' => ['name' => 'phone', 'type' => 'varchar', 'length' => '20', 'null' => true], // 新增字段
    ],
    'indexes' => [
        'PRIMARY' => ['name' => 'PRIMARY', 'type' => 'primary', 'fields' => ['id']],
        'email_unique' => ['name' => 'email_unique', 'type' => 'unique', 'fields' => ['email']], // 新增索引
    ]
];

// 比较表结构
$diff = MysqlHelper::schemaCompare($schema1, $schema2);
echo "表结构差异:\n";
if (!empty($diff['fields']['less'])) {
    echo "  新增字段: " . implode(', ', $diff['fields']['less']) . "\n";
}
if (!empty($diff['indexes']['less'])) {
    echo "  新增索引: " . implode(', ', $diff['indexes']['less']) . "\n";
}

// 生成修复SQL
$fixSql = MysqlHelper::makeFixSql($schema1, $schema2, true);
if (!empty($fixSql)) {
    echo "修复SQL:\n";
    foreach ($fixSql as $sql) {
        echo "  {$sql}\n";
    }
}

// 示例5: 数据插入SQL生成
echo "\n=== 数据插入SQL生成 ===\n";

// 配置插入选项
$insertOptions = [
    'truncate' => true,  // 清空表后插入
    'ignore' => false,   // 不使用 INSERT IGNORE
    'batchSize' => 100,  // 批量大小
];

// 注意：这里只是示例，实际使用时需要真实的表名
echo "插入SQL配置示例:\n";
echo "  - truncate: " . ($insertOptions['truncate'] ? 'true' : 'false') . "\n";
echo "  - ignore: " . ($insertOptions['ignore'] ? 'true' : 'false') . "\n";
echo "  - batchSize: {$insertOptions['batchSize']}\n";

echo "\n=== 示例完成 ===\n"; 