# mysql-helper-yii2
<p>
  基于yii2的mysql维护类
</p>

[![Latest Stable Version](https://img.shields.io/packagist/v/jcbowen/mysql-helper-yii2.svg)](https://packagist.org/packages/jcbowen/mysql-helper-yii2)
[![Total Downloads](https://img.shields.io/packagist/dt/jcbowen/mysql-helper-yii2.svg)](https://packagist.org/packages/jcbowen/mysql-helper-yii2)

基本要求
------------

- yii 2.x
- PHP版本为7.x

安装
-------------
- 直接在根目录执行下方composer命令
```
composer require jcbowen/mysql-helper-yii2
```

使用
-------------
- 获取库名称

  @param $dsn 如果为空，则读取配置中的dsn
```
Jcbowen\MysqlHelperYii2\MysqlHelperYii2::getDbName(?string $dsn = '');
```
- 获取表名称
```
Jcbowen\MysqlHelperYii2\MysqlHelperYii2::tableName($tableName);
```
- 获取表结构
```
Jcbowen\MysqlHelperYii2\MysqlHelperYii2::getTableSchema($tableName);
```
- 创建修复两张差异表的语句

  @param array $schema1 表结构,需要修复的表 
  @param array $schema2 表结构,基准表
  @param bool $strict 使用严格模式, 严格模式将会把表1完全变成表2的结构, 否则将只处理表2种大于表1的内容(多出的字段和索引)
```
Jcbowen\MysqlHelperYii2\MysqlHelperYii2::makeFixSql(array $schema1, array $schema2, bool $strict = false);
```



