<?php

namespace Jcbowen\MysqlHelperYii2\components;

use Yii;
use yii\db\Connection;
use yii\db\Exception;
use yii\db\Query;

class MysqlHelper
{
    /**
     * 获取库名称
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string|null $dsn 数据库连接DSN
     * @return string
     * @lasttime: 2022/4/2 10:17 AM
     */
    public static function getDBName(?string $dsn = ''): string
    {
        $dsn      = $dsn ?: Yii::$app->db->dsn;
        $dsnParam = explode(';', $dsn);
        $items    = [];
        foreach ($dsnParam as $item) {
            $itemArr            = explode('=', $item);
            $items[$itemArr[0]] = $itemArr[1];
        }
        $data = array_filter($items, function ($key) {
            return 'dbname' == $key;
        }, ARRAY_FILTER_USE_KEY);

        return array_values($data)[0];
    }

    /**
     * 获取完整表名称
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string $tableName 表名称
     * @return string
     * @lasttime: 2022/4/2 1:05 PM
     */
    public static function tableName(string $tableName): string
    {
        if (empty($tableName)) return '';

        // 判断表名是否包含表前缀
        $dbPrefix = Yii::$app->db->tablePrefix;
        if (strpos($tableName, $dbPrefix) !== false) {
            return $tableName;
        } else {
            // 判断是否通过Yii的方法转化表名
            if (strpos($tableName, '{{') !== false) {
                return trim(Yii::$app->db->quoteSql($tableName), '`');
            } else {
                return $dbPrefix . $tableName;
            }
        }
    }

    /**
     * 获取表结构
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string $tableName 表名
     * @param bool $getDefault 是否获取字段默认值
     * @param bool $getComment 是否获取字段注释
     * @return array
     * @throws Exception
     * @lasttime: 2022/4/1 11:44 PM
     */
    public static function getTableSchema(string $tableName = '', bool $getDefault = true, bool $getComment = true): array
    {
        $tableName = self::tableName($tableName);
        $result    = Yii::$app->db->createCommand("SHOW TABLE STATUS LIKE '" . $tableName . "'")->queryOne();
        if (empty($result)) return [];
        $ret              = [];
        $ret['tableName'] = $result['Name'];
        $ret['charset']   = $result['Collation'];
        $ret['engine']    = $result['Engine'];
        $ret['increment'] = $result['Auto_increment'];
        $result           = Yii::$app->db->createCommand('SHOW FULL COLUMNS FROM ' . $tableName)->queryAll();
        foreach ($result as $value) {
            $temp           = [];
            $type           = explode(' ', $value['Type'], 2);
            $temp['name']   = $value['Field'];
            $pieces         = explode('(', $type[0], 2);
            $temp['type']   = $pieces[0];
            $temp['length'] = !empty($pieces[1]) ? rtrim($pieces[1], ')') : '';
            $temp['null']   = 'NO' != $value['Null'];
            if ($getDefault) $temp['default'] = $value['Default'];
            $temp['signed']    = empty($type[1]);
            $temp['increment'] = 'auto_increment' == $value['Extra'];
            if ($getComment) $temp['comment'] = $value['Comment'] ?: '';
            $ret['fields'][$value['Field']] = $temp;
        }
        $result = Yii::$app->db->createCommand('SHOW INDEX FROM ' . $tableName)->queryAll();
        foreach ($result as $value) {
            $ret['indexes'][$value['Key_name']]['name']     = $value['Key_name'];
            $ret['indexes'][$value['Key_name']]['type']     = ('PRIMARY' == $value['Key_name']) ? 'primary' : (0 == $value['Non_unique'] ? 'unique' : 'index');
            $ret['indexes'][$value['Key_name']]['fields'][] = $value['Column_name'];
        }

        return $ret;
    }

    /**
     * 获取数据库所有表的序列化结构
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string $dbname 数据库名称
     * @return string
     * @throws Exception
     * @lasttime: 2022/4/2 11:33 AM
     */
    public static function getTableSerialize(string $dbname = ''): string
    {
        $dbname = $dbname ?: self::getDBName();
        $tables = Yii::$app->db->createCommand("SHOW TABLES")->queryAll();
        if (empty($tables)) return '';
        $structs = [];
        foreach ($tables as $value) {
            $structs[] = self::getTableSchema(substr($value['Tables_in_' . $dbname], strpos($value['Tables_in_' . $dbname], '_') + 1));
        }
        return serialize($structs);
    }

    /**
     * 根据结构生成建表语句
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param array $schema 表结构
     * @return string
     * @lasttime: 2022/4/2 11:06 PM
     */
    public static function makeCreateSql($schema): string
    {
        $pieces  = explode('_', $schema['charset']);
        $charset = $pieces[0];
        $engine  = $schema['engine'];

        $schema['tableName'] = str_replace('jc_', Yii::$app->db->tablePrefix, $schema['tableName']);

        $sql = "CREATE TABLE IF NOT EXISTS `{$schema['tableName']}` (\n";
        foreach ($schema['fields'] as $value) {
            $piece = self::buildFieldSql($value);
            $sql   .= "`{$value['name']}` {$piece},\n";
        }
        foreach ($schema['indexes'] as $value) {
            $fields = implode('`,`', $value['fields']);
            if ('index' == $value['type']) {
                $sql .= "KEY `{$value['name']}` (`{$fields}`),\n";
            }
            if ('unique' == $value['type']) {
                $sql .= "UNIQUE KEY `{$value['name']}` (`{$fields}`),\n";
            }
            if ('primary' == $value['type']) {
                $sql .= "PRIMARY KEY (`{$fields}`),\n";
            }
        }
        $sql = rtrim($sql);
        $sql = rtrim($sql, ',');

        $sql .= "\n) ENGINE=$engine DEFAULT CHARSET=$charset;\n\n";

        return $sql;
    }

    /**
     * 比较两张表的结构
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param array $table1 表1的结构
     * @param array $table2 表2的结构，基准表
     * @return array
     * @lasttime: 2022/4/2 11:05 PM
     */
    public static function schemaCompare(array $table1, array $table2): array
    {
        $ret['diffs']['charset'] = $table1['charset'] != $table2['charset'];

        $fields1 = array_keys($table1['fields']);
        $fields2 = array_keys($table2['fields']);
        $diffs   = array_diff($fields1, $fields2);
        if (!empty($diffs)) {
            $ret['fields']['greater'] = array_values($diffs);
        }
        $diffs = array_diff($fields2, $fields1);
        if (!empty($diffs)) {
            $ret['fields']['less'] = array_values($diffs);
        }
        $diffs      = [];
        $intersects = array_intersect($fields1, $fields2);
        if (!empty($intersects)) {
            foreach ($intersects as $field) {
                if (in_array($table2['fields'][$field]['type'], ['int', 'tinyint', 'smallint', 'bigint'])) {
                    unset($table1['fields'][$field]['length']);
                    unset($table2['fields'][$field]['length']);
                }
                if ($table1['fields'][$field] != $table2['fields'][$field]) {
                    $diffs[] = $field;
                }
            }
        }
        if (!empty($diffs)) $ret['fields']['diff'] = array_values($diffs);

        $indexes1 = is_array($table1['indexes']) ? array_keys($table1['indexes']) : [];
        $indexes2 = is_array($table2['indexes']) ? array_keys($table2['indexes']) : [];

        $diffs = array_diff($indexes1, $indexes2);
        if (!empty($diffs)) $ret['indexes']['greater'] = array_values($diffs);

        $diffs = array_diff($indexes2, $indexes1);
        if (!empty($diffs)) $ret['indexes']['less'] = array_values($diffs);

        $diffs      = [];
        $intersects = array_intersect($indexes1, $indexes2);
        if (!empty($intersects)) {
            foreach ($intersects as $index) {
                if ($table1['indexes'][$index] != $table2['indexes'][$index]) $diffs[] = $index;
            }
        }
        if (!empty($diffs)) $ret['indexes']['diff'] = array_values($diffs);

        return $ret;
    }

    /**
     * 创建修复两张差异表的语句
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param array $schema1 表1的结构,需要修复的表
     * @param array $schema2 表2的结构,基准表
     * @param bool $strict 使用严格模式, 严格模式将会把表2完全变成表1的结构, 否则将只处理表2种大于表1的内容(多出的字段和索引)
     * @return array
     * @lasttime: 2022/4/2 11:08 PM
     */
    public static function makeFixSql(array $schema1, array $schema2, bool $strict = false): array
    {
        if (empty($schema1)) {
            return [self::makeCreateSql($schema2)];
        }
        $diff = self::schemaCompare($schema1, $schema2);
        if (!empty($diff['diffs']['tableName'])) {
            return [self::makeCreateSql($schema2)];
        }
        $sqls = [];
        if (!empty($diff['diffs']['engine'])) {
            $sqls[] = "ALTER TABLE `{$schema1['tableName']}` ENGINE = {$schema2['engine']}";
        }

        if (!empty($diff['diffs']['charset'])) {
            $pieces  = explode('_', $schema2['charset']);
            $charset = $pieces[0];
            $sqls[]  = "ALTER TABLE `{$schema1['tableName']}` DEFAULT CHARSET = {$charset}";
        }

        if (!empty($diff['fields'])) {
            if (!empty($diff['fields']['less'])) {
                foreach ($diff['fields']['less'] as $fieldName) {
                    $field = $schema2['fields'][$fieldName];
                    $piece = self::buildFieldSql($field);
                    if (!empty($field['rename']) && !empty($schema1['fields'][$field['rename']])) {
                        $sql = "ALTER TABLE `{$schema1['tableName']}` CHANGE `{$field['rename']}` `{$field['name']}` {$piece}";
                        unset($schema1['fields'][$field['rename']]);
                    } else {
                        if ($field['position']) {
                            $pos = ' ' . $field['position'];
                        }
                        $sql = "ALTER TABLE `{$schema1['tableName']}` ADD `{$field['name']}` {$piece}{$pos}";
                    }
                    $primary     = [];
                    $isIncrement = [];
                    // 判断sql语句中是否含有AUTO_INCREMENT
                    if (strpos($sql, 'AUTO_INCREMENT') !== false) {
                        $isIncrement = $field;
                        $sql         = str_replace('AUTO_INCREMENT', '', $sql);
                        foreach ($schema1['fields'] as $field) {
                            if (1 == $field['increment']) {
                                $primary = $field;
                                break;
                            }
                        }
                        if (!empty($primary)) {
                            $piece = self::buildFieldSql($primary);
                            if (!empty($piece)) {
                                $piece = str_replace('AUTO_INCREMENT', '', $piece);
                            }
                            $sqls[] = "ALTER TABLE `{$schema1['tableName']}` CHANGE `{$primary['name']}` `{$primary['name']}` {$piece}";
                        }
                    }
                    $sqls[] = $sql;
                }
            }
            if (!empty($diff['fields']['diff'])) {
                foreach ($diff['fields']['diff'] as $fieldName) {
                    $field = $schema2['fields'][$fieldName];
                    $piece = self::buildFieldSql($field);
                    if (!empty($schema1['fields'][$fieldName])) {
                        $sqls[] = "ALTER TABLE `{$schema1['tableName']}` CHANGE `{$field['name']}` `{$field['name']}` {$piece}";
                    }
                }
            }
            if ($strict && !empty($diff['fields']['greater'])) {
                foreach ($diff['fields']['greater'] as $fieldName) {
                    if (!empty($schema1['fields'][$fieldName])) {
                        $sqls[] = "ALTER TABLE `{$schema1['tableName']}` DROP `{$fieldName}`";
                    }
                }
            }
        }

        if (!empty($diff['indexes'])) {
            if (!empty($diff['indexes']['less'])) {
                foreach ($diff['indexes']['less'] as $indexname) {
                    $index  = $schema2['indexes'][$indexname];
                    $piece  = self::buildIndexSql($index);
                    $sqls[] = "ALTER TABLE `{$schema1['tableName']}` ADD {$piece}";
                }
            }
            if (!empty($diff['indexes']['diff'])) {
                foreach ($diff['indexes']['diff'] as $indexname) {
                    $index = $schema2['indexes'][$indexname];
                    $piece = self::buildIndexSql($index);

                    $sqls[] = "ALTER TABLE `{$schema1['tableName']}` DROP " . ('PRIMARY' == $indexname ? ' PRIMARY KEY ' : "INDEX {$indexname}") . ", ADD {$piece}";
                }
            }
            if ($strict && !empty($diff['indexes']['greater'])) {
                foreach ($diff['indexes']['greater'] as $indexname) {
                    $sqls[] = "ALTER TABLE `{$schema1['tableName']}` DROP `{$indexname}`";
                }
            }
        }
        if (!empty($isIncrement)) {
            $piece  = self::buildFieldSql($isIncrement);
            $sqls[] = "ALTER TABLE `{$schema1['tableName']}` CHANGE `{$isIncrement['name']}` `{$isIncrement['name']}` {$piece}";
        }

        return $sqls;
    }

    /**
     * 构造索引sql语句
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param array $index 索引信息
     * @return string
     * @lasttime: 2022/4/2 10:46 PM
     */
    public static function buildIndexSql(array $index): string
    {
        $piece  = '';
        $fields = implode('`,`', $index['fields']);
        if ('index' == $index['type']) {
            $piece .= " INDEX `{$index['name']}` (`{$fields}`)";
        }
        if ('unique' == $index['type']) {
            $piece .= "UNIQUE `{$index['name']}` (`{$fields}`)";
        }
        if ('primary' == $index['type']) {
            $piece .= "PRIMARY KEY (`{$fields}`)";
        }

        return $piece;
    }

    /**
     * 构造完整字段的SQL语句.
     *
     * @param array $field 字段信息
     * @return string
     */
    public static function buildFieldSql(array $field): string
    {
        $length = !empty($field['length']) ? "({$field['length']})" : '';
        if (false !== strpos(strtolower($field['type']), 'int') || in_array(strtolower($field['type']), [
                'decimal',
                'float',
                'dobule'
            ])) {
            $signed = empty($field['signed']) ? ' unsigned' : '';
        } else {
            $signed = '';
        }
        $null      = empty($field['null']) ? ' NOT NULL' : '';
        $default   = isset($field['default']) ? " DEFAULT '" . $field['default'] . "'" : '';
        $increment = $field['increment'] ? ' AUTO_INCREMENT' : '';
        $comment   = !empty($field['comment']) ? " COMMENT '{$field['comment']}'" : '';

        return "{$field['type']}{$length}{$signed}{$null}{$default}{$increment}{$comment}";
    }

    /**
     * 根据表名生成建表语句
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string $tableName 表名
     * @return string
     * @throws Exception
     * @lasttime: 2022/4/2 10:37 PM
     */
    public static function tableSchemas(string $tableName): string
    {
        $tableName = self::tableName($tableName);

        $dump = "DROP TABLE IF EXISTS {$tableName};\n";
        $sql  = "SHOW CREATE TABLE {$tableName}";
        $row  = Yii::$app->db->createCommand($sql)->queryOne();
        $dump .= $row['Create Table'];
        $dump .= ";\n\n";

        return $dump;
    }

    /**
     * 获取指定表的insert语句
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string $tableName 表名
     * @param int $batchSize 每次查询的条数
     * @param Connection|null $db 数据库连接
     * @return array|false
     * @lasttime: 2022/12/14 13:53
     */
    public static function tableInsertSql(string $tableName, int $batchSize = 100, Connection $db = null)
    {
        $tableName = self::tableName($tableName);

        $data      = [];
        $insertSql = '';
        $tmp       = '';

        $query = (new Query())->from($tableName);
        foreach ($query->each($batchSize, $db) as $row) {
            $data[] = $row;
            $tmp    .= '(';
            foreach ($row as $v) {
                $value = str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array(
                    '\\\\',
                    '\\0',
                    '\\n',
                    '\\r',
                    "\\'",
                    '\\"',
                    '\\Z'
                ), $v);
                $tmp   .= "'" . $value . "',";
            }
            $tmp = rtrim($tmp, ',');
            $tmp .= "),\n";
        }

        if (empty($data))
            return false;

        $tmp       = rtrim($tmp, ",\n");
        $insertSql .= "INSERT INTO $tableName VALUES \n$tmp;\n";

        return [
            'sql'  => $insertSql,
            'data' => $data,
        ];
    }
}
