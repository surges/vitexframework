<?php
/**
 * Vitex 一个基于php5.5开发的 快速开发restful API的微型框架
 * @version  0.2.0
 *
 * @package Vitex
 *
 * @author  skipify <skipify@qq.com>
 * @copyright skipify
 * @license MIT
 */
namespace Vitex\Ext;

use Vitex\Core\Exception;
use Vitex\Vitex;

/**
 * 一个简单的Active record
 * knex.js
 * @method array getBy*(string $column,String $val)
 * @method array getAllBy*(string $column,String $val)
 */
class Model
{
    /**
     * 保存各种条件的数组.
     * @var array
     */
    private $_sql = [
        'where'     => [],
        'whereraw'  => [],
        'findinset' => [],
        'select'    => [],
        'distinct'  => [],
        'from'      => '',
        'limit'     => '',
        'offset'    => 0,
        'group'     => [],
        'having'    => [],
        'union'     => [],
        'join'      => [],
        'order'     => [],
    ];
    /**
     * 保存数据集合的数组
     * @var array
     */
    private $_post = [];
    /**
     * 保存新修改的数据
     * @var array
     */
    private $_setpost; //新修改的数据保存
    /*是否已经开启了一个事务*/
    private $_begintransaction = false;
    /**
     * 当前是否执行了查询主键的操作
     * @var boolean
     */
    private $isfind = false;
    /**
     * ORM修改数据时排除的字段
     * @var array
     */
    protected $exclude = []; //排除不修改的
    /**
     * 主键的名字
     * @var string
     */
    protected $pk = 'id';
    /**
     * 主键的值
     * @var mixed
     */
    private $pkval;
    /**
     * 当前默认的表名
     * @var string
     */
    protected $table;
    /**
     * 表前缀
     * @var string
     */
    protected $prefix = '';
    /**
     * 构造好的sql语句
     * @var string
     */
    public $sql;
    /**
     * @var Pdo
     */
    private $pdo;
    private $DB;

    public function __construct($table = '')
    {
        $this->vitex = Vitex::getInstance();
        try {
            $this->DB = $this->vitex->pdo;
            $this->pdo = $this->DB->pdo;
        } catch (Exception $e) {
            throw new \Error('使用ORM之前您必须要调用一个数据库连接的类返回一个PDO的变量,或者直接加载pdo中间件');
        }
        if ($table) {
            $this->from($table);
        }
        $class = explode('\\', get_class($this));
        $this->table = strtolower(end($class));
    }

    /**
     * 切换Model层使用的数据库连接
     * @param  array $setting 数据库链接信息
     * @return self
     */
    public function changeDatabase(array $setting)
    {
        $pdoCon = new Pdo($setting);
        $pdoCon->setVitex($this->vitex);
        $this->DB = $pdoCon;
        $this->pdo = $this->DB->pdo;
        return $this;
    }

    /**
     * 定义一个新的模型数据
     * @param array $arr 模型数据
     * @return self
     */
    public function def($arr = [])
    {
        $this->_post = $arr;
        return $this;
    }

    /**
     * 处理映射字段
     * @param string $key 键值
     * @return null
     */
    public function __get($key)
    {
        return isset($this->_post[$key]) ? $this->_post[$key] : null;
    }

    public function __set($key, $val)
    {
        if ($this->isfind) {
            $this->_setpost[$key] = $val;
        }
        $this->_post[$key] = $val;
    }

    public function __isset($key)
    {
        return isset($this->_post[$key]);
    }

    public function __unset($key)
    {
        if ($this->isfind) {
            try {
                unset($this->_setpost[$key]);
            } catch (Exception $e) {
            }
        }
        unset($this->_post[$key]);
    }

    /**
     * 负责执行一些未定义的内容
     * @param  string $method 方法名
     * @param  array  $args 数组
     * @return mixed  执行结果或者本身
     */
    public function __call($method, $args)
    {
        //getby
        if (substr($method, 0, 5) == 'getBy') {
            $field = str_replace('getBy', '', $method);
            $val = array_shift($args);
            $this->where($field, '=', $val);
            return $this->_get();
        }
        //getAllBy
        //
        if (substr($method, 0, 8) == 'getAllBy') {
            $field = str_replace('getAllBy', '', $method);
            $val = array_shift($args);
            $this->where($field, '=', $val);
            return $this->_getAll();
        }
    }


    /**
     * 设置表前缀
     * @param  string $prefix 前缀
     * @return self
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * 返回当前对象的实例，一般用于子查询实例化model
     * @param string $prefix 表名前缀
     * @return self
     */
    public static function sub($prefix='')
    {
        $self = new self;
        $self->setPrefix($prefix);
        return $self;
    }

    /**
     * 直接执行sql语句 @#_ 当做表前缀替换掉
     * @param  string $sql sql语句
     * @return mixed  执行结果
     */
    public function query($sql)
    {
        $sql = str_replace('@#_', $this->prefix, $sql);
        return $this->DB->query($sql);
    }

    /**
     * 返回子查询构造的字符串
     */
    public function __tostring()
    {
        return $this->buildSql();
    }

    /**
     * 选择要查询的字段名
     * @param  mixed $column 可以是字符串，多个字段用,分开，也可以是数组每个元素为一个字段，也可以是*
     * @return self
     */
    final public function select($column = '*')
    {
        if ($column == '*') {
            $this->_sql['select'][] = $column;
            return $this;
        }
        //处理字段名
        if (!is_array($column)) {
            $column = explode(',', $column);
        }
        $column = array_map([$this, 'formatColumn'], $column);
        $this->_sql['select'] = array_merge($this->_sql['select'], $column);
        return $this;
    }

    /**
     * 转义格式化字段名 主要用 `包括
     * table.field
     * table.*
     * table.field as _field
     * table.field _field
     * @param  string $column 字段名
     * @return string 新的字段名
     */
    private function formatColumn($column)
    {
        $column = trim($column);
        /*调用系统函数时不处理*/
        if (strpos($column, '(') !== false) {
            return $column;
        }
        if (strpos($column, '.') !== false) {
            list($table, $column) = explode('.', $column);
            $table = '`' . $table . '`.';
        } else {
            $table = '';
        }
        if ($column == '*') {
            return $table . $column;
        }
        if (strpos($column, ' ') === false) {
            return $table . '`' . $column . '`';
        }
        $column = preg_replace('/[ ]+/', ' ', $column);
        $columns = explode(' ', $column);
        if (count($columns) == 3) {
            list($column, $as, $alias) = $columns;
        } else {
            list($column, $alias) = $columns;
        }
        return $table . '`' . $column . '` as `' . $alias . '`';
    }

    /**
     * 基本的where查询条件,与前面的操作使用and连接
     * @param        string /array $key 条件列名
     * @param string $op 操作符 = != > like等
     * @param string $val 值
     * @return self
     */
    public function where($key, $op = '', $val = '')
    {
        return $this->_where("where", $key, $op, $val);
    }

    /**
     * 基本的or where查询条件,与前面的操作使用or连接
     * @param        string /array $key 条件列名
     * @param string $op 操作符 = != > like等
     * @param string $val 值
     * @return self
     */
    public function orWhere($key, $op = '', $val = '')
    {
        return $this->_where("orWhere", $key, $op, $val);
    }

    /**
     * 基本的whereIn查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function whereIn($key, $val)
    {
        return $this->_where("whereIn", $key, $val);
    }

    /**
     * 基本的whereNotIn查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function whereNotIn($key, $val)
    {
        return $this->_where("whereNotIn", $key, $val);
    }

    /**
     * 基本的or whereIn查询条件,与前面的操作使用or连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function orWhereIn($key, $val)
    {
        return $this->_where("orWhereIn", $key, $val);
    }

    /**
     * 基本的or whereNotIn查询条件,与前面的操作使用or连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function orWhereNotIn($key, $val)
    {
        return $this->_where("orWhereNotIn", $key, $val);
    }

    /**
     * 基本的where is null查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function whereNull($key, $val)
    {
        return $this->_where("whereNull", $key, $val);
    }

    /**
     * 基本的where is not null查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function whereNotNull($key, $val)
    {
        return $this->_where("whereNotNull", $key, $val);
    }

    /**
     * 基本的or where is null查询条件,与前面的操作使用or连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function orWhereNull($key, $val)
    {
        return $this->_where("orWhereNull", $key, $val);
    }

    /**
     * 基本的or where is not null查询条件,与前面的操作使用or连接
     * @param string $key 条件列名
     * @param string $val 值
     * @return self
     */
    public function orWhereNotNull($key, $val)
    {
        return $this->_where("orWhereNotNull", $key, $val);
    }

    /**
     * 基本的where exists(select name form user where id=1)查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值,如说明,不要包含最外层的 ()
     * @return self
     */
    public function whereExists($key, $val)
    {
        return $this->_where("whereExists", $key, $val);
    }

    /**
     * 基本的where not exists(select name form user where id=1)查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值,如说明,不要包含最外层的 ()
     * @return self
     */
    public function whereNotExists($key, $val)
    {
        return $this->_where("whereNotExists", $key, $val);
    }

    /**
     * 基本的 or where exists(select name form user where id=1)查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值,如说明,不要包含最外层的 ()
     * @return self
     */
    public function orWhereExists($key, $val)
    {
        return $this->_where("orWhereExists", $key, $val);
    }

    /**
     * 基本的 or where not exists(select name form user where id=1)查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param string $val 值,如说明,不要包含最外层的 ()
     * @return  self
     */
    public function orWhereNotExists($key, $val)
    {
        return $this->_where("orWhereNotExists", $key, $val);
    }

    /**
     * 基本的where between a and b查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param array  $val ,这是一个数组,包含两个元素 between $val[0] and $val[1]
     * @return self
     */
    public function whereBetween($key, array $val)
    {
        return $this->_where("whereBetween", $key, $val);
    }

    /**
     * 基本的where not between a and b查询条件,与前面的操作使用and连接
     * @param string $key 条件列名
     * @param array  $val ,这是一个数组,包含两个元素 not between $val[0] and $val[1]
     * @return self
     */
    public function whereNotBetween($key, array $val)
    {
        return $this->_where("whereNotBetween", $key, $val);
    }

    /**
     * 基本的or where between a and b查询条件,与前面的操作使用or连接
     * @param string $key 条件列名
     * @param array  $val ,这是一个数组,包含两个元素 between $val[0] and $val[1]
     * @return  self
     */
    public function orWhereBetween($key, array $val)
    {
        return $this->_where("orWhereBetween", $key, $val);
    }

    /**
     * 基本的or where not between a and b查询条件,与前面的操作使用or连接
     * @param string $key 条件列名
     * @param array  $val ,这是一个数组,包含两个元素 not between $val[0] and $val[1]
     * @return self
     */
    public function orWhereNotBetween($key, array $val)
    {
        return $this->_where("orWhereNotBetween", $key, $val);
    }

    /**
     * where查询语句，支持子查询等
     * @param         $method
     * @param  string $key 键值
     * @param  string $op 操作符
     * @param string  $val
     * @return object 错误信息
     * @throws \Error
     * @internal param $string /array/callable $val    值
     */
    private function _where($method, $key, $op = '', $val = '')
    {
        $where = ['where' => '', 'whereIn' => 'in', 'whereNotIn' => 'not in', 'whereNull' => 'is', 'whereNotNull' => 'is not', 'whereExists' => 'exists', 'whereNotExists' => 'not exists', 'whereBetween' => 'between', 'whereNotBetween' => 'not between', 'orWhere' => '', 'orWhereIn' => 'in', 'orWhereNotIn' => 'not in', 'orWhereNull' => 'is', 'orWhereNotNull' => 'is not', 'orWhereExists' => 'exists', 'orWhereNotExists' => 'not exists', 'orWhereBetween' => 'between', 'orWhereNotBetween' => 'not between'];

        if (!isset($where[$method])) {
            throw new \Error('找不到您要执行的方法' . $method);
        }
        //兼容 where 直接传递一个关联数组的情况
        if (($method == 'where' || $method == 'orWhere') && is_array($key)) {
            foreach ($key as $k => $v) {
                $this->_where($method, $k, '=', $v);
            }
            return $this;
        }
        if (strpos($method, 'xists') === false) {
            $key = $this->formatColumn($key);
        }
        //非这俩方法时不需要指定操作符
        if ($method != 'where' && $method != 'orWhere') {
            $val = $op;
        }
        //设定操作符和连接符
        $type = strpos($method, 'or') !== false ? ' or ' : ' and ';
        $_op = $where[$method];
        $op = $_op ? $_op : $op;
        if ($op == 'is' || $op == 'is not') {
            $val = 'null';
        }
        $val = is_array($val) ? $val : (string)$val;

        $this->_sql['where'][] = [$key, $op, $val, $type];
        return $this;
    }

    /**
     * 字符串形式的查询语句
     * @param  string $val 查询语句
     * @return self
     */
    public function whereRaw($val)
    {
        $this->_sql['whereraw'][] = $val;
        return $this;
    }

    /**
     * set数据查询
     * @param  string $column 字段名
     * @param  mixed  $val 查询值
     * @param  string $type 类型  默认and
     * @return self
     */
    public function findInSet($column, $val, $type = 'and')
    {
        $column = $this->formatColumn($column);
        $this->_sql['findinset'][] = [$column, $val, $type];
        return $this;
    }

    /**
     * or set查询
     * @param $column
     * @param $val
     * @return self
     */
    public function orFindInSet($column, $val)
    {
        return $this->findInSet($column, $val, 'or');
    }

    /**
     * Having分组操作条件
     * @param  string $key 键值
     * @param  string $op 操作符
     * @param         array /callable $val    操作值
     * @param  string $type 类型 and/or
     * @return self
     */
    public function having($key, $op, $val, $type = "AND")
    {
        $key = $this->formatColumn($key);
        $this->_sql['having'][] = [$key, $op, $val, $type];
        return $this;
    }

    /**
     * 要查询的表名
     * @param  string $table 表名
     * @return self
     */
    final public function from($table)
    {
        $table = (string)$table;
        $this->_sql['from'] = $table;
        return $this;
    }

    /**
     * 获取当前要查询的表名
     * @return string name
     */
    public function getTable()
    {
        if ($this->_sql['from']) {
            $table = $this->_sql['from'];
        } else {
            $table = $this->table;
        }

        $table = $this->prefix . $table;
        return $this->formatTable($table);
    }

    //提取出表名中的别名
    private function formatTable($table)
    {
        $alias = '';
        if (strpos($table, ' ') !== false) {
            list($table, $alias) = explode(' ', $table);
            $alias = ' as `' . $alias . '`';
        }
        return '`' . $table . '`' . $alias;
    }

    /**
     * 查询的条数
     * @param  string  $limit 要查询的条数
     * @param  integer $offset 偏移值 默认0
     * @return self
     */
    final public function limit($limit, $offset = 0)
    {
        $this->_sql['limit'] = $limit;
        $this->offset($offset);
        return $this;
    }

    /**
     * 单独设置的偏移数制
     * @param  integer $offset 偏移数值
     * @return self
     */
    final public function offset($offset)
    {
        $this->_sql['offset'] = $offset;
        return $this;
    }

    /**
     * 排序字段以及排序方式
     * @param  string $column 字段
     * @param  string $way 排序方式
     * @return self
     */
    final public function orderBy($column, $way = "DESC")
    {
        $column = $this->formatColumn($column);
        $this->_sql['order'][] = [$column, $way];
        return $this;
    }

    /**
     * group分组操作
     * @param  string $column 要分组的字段
     * @return self
     */
    final public function groupBy($column)
    {
        $column = $this->formatColumn($column);
        $this->_sql['group'][] = $column;
        return $this;
    }

    /**
     * 去重查询
     * @param  string /array $column 字段名
     * @return self
     */
    final public function distinct($column)
    {
        if (!is_array($column)) {
            $column = explode(',', $column);
        }
        $column = array_map([$this, 'formatColumn'], $column);

        $this->_sql['distinct'] = array_merge($this->_sql['distinct'], $column);
        return $this;
    }

    /**
     * join操作的集中执行方法
     * @param  string $type 各种不同的join操作
     * @param  string $table join的表明
     * @param  string $col 第一个字段
     * @param  string $col2 第二个字段
     * @return self
     */
    private function join($type, $table, $col, $op, $col2 = '')
    {
        $col = $this->formatColumn($col);
        $col2 = $this->formatColumn($col2);
        $this->_sql['join'][$type][] = [$table, $col, $op, $col2];
        return $this;
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function innerJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("inner join", $table, $col, $op, $col2);
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function leftJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("left join", $table, $col, $op, $col2);
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function leftOuterJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("left outer join", $table, $col, $op, $col2);
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function rightJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("right join", $table, $col, $op, $col2);
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function rightOuterJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("right outer join", $table, $col, $op, $col2);
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function outerJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("outer join", $table, $col, $op, $col2);
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function fullOuterJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("full outer join", $table, $col, $op, $col2);
    }

    /**
     * @param string $table 表名
     * @param string $col 一个连接表的列名
     * @param string $op 操作符  = !=
     * @param string $col2 另一个连接表的列明
     * @return self
     */
    public function crossJoin($table, $col, $op, $col2 = '')
    {
        return $this->join("cross join", $table, $col, $op, $col2);
    }

    /**
     * union操作
     * @param  string /callable $str    union字符串或者一个可以tostring的对象
     * @return self
     */
    final public function union($str)
    {
        $str = (string)$str;
        $this->_sql['union'][] = $str;
        return $this;
    }

    /**
     * 设置数据
     * @param         string /array $key    键值
     * @param  string $val 值
     * @return self
     */
    public function set($key, $val = null)
    {
        if (is_array($key)) {
            $this->_setpost = array_merge($this->_setpost, $key);
        } else {
            $this->_setpost[$key] = $val;
        }
        return $this;
    }

    /**
     * 构建sql语句
     * @param bool $iscount
     * @return string
     * @throws \Error
     */
    private function buildSql($iscount = false)
    {
        if (!$this->getTable()) {
            throw new \Error('您还没有指定要查询的表名');
        }
        $sql = "select ";

        if ($iscount) {
            $field = is_bool($iscount) ? '*' : $iscount;
            $field = $this->formatColumn($field);
            $sql .= 'count(' . $field . ') as num ';
        } else {
            if ($this->_sql['distinct']) {
                $sql .= 'distinct ' . implode(',', $this->_sql['distinct']) . ' ';
            }

            if (!$this->_sql['distinct']) {
                $field = $this->_sql['select'] ? implode(',', $this->_sql['select']) : '*';
                $sql .= $field . ' ';
            }
        }

        $sql .= " from " . $this->getTable() . ' ';

        $sql .= $this->buildWhere();
        //groupby
        if ($this->_sql['group']) {
            $groupby = array_map(function ($item) {
                return $this->formatColumn($item);
            }, $this->_sql['group']);
            $sql .= ' group by ' . implode(',', $groupby) . ' ';
        }

        //having
        if ($this->_sql['having']) {
            $sql .= ' having ';
            foreach ($this->_sql['having'] as $k => list($key, $op, $val, $type)) {
                if ($k != 0) {
                    $sql .= $type . ' ';
                }
                $sql .= ' ' . $key . ' ' . $op . ' ' . $val;
            }
        }

        //union
        if ($this->_sql['union']) {
            foreach ($this->_sql['union'] as $union) {
                $sql .= ' union (' . $union . ') ';
            }
        }
        //orderby
        if ($this->_sql['order']) {
            $sql .= ' order by ';
            $_order = [];
            foreach ($this->_sql['order'] as list($column, $way)) {
                $_order[] = $column . ' ' . $way;
            }
            $sql .= implode(',', $_order);
        }
        //limit
        if ($this->_sql['limit'] && !$iscount) {
            $sql .= ' limit ' . $this->_sql['offset'] . ',' . $this->_sql['limit'];
        }
        //重置各种条件
        $this->resetCon();
        $this->sql = $sql;
        return $sql;
    }

    /**
     * 重新构建where条件 join/where
     * @return string 构建好的where条件
     */
    private function buildWhere()
    {
        $sql = ' ';
        //join
        if ($this->_sql['join']) {
            foreach ($this->_sql['join'] as $key => $join) {
                foreach ($join as list($table, $con1, $op, $con2)) {
                    $table = $this->prefix . $table;
                    $table = $this->formatTable($table);
                    $sql .= $key . ' ' . $table . ' on ' . $con1 . ' ' . $op . ' ' . $con2 . ' ';
                }
            }
        }
        //where
        if ($this->_sql['where'] || $this->_sql['whereraw']) {
            $sql .= 'where ';
            $haswhere = false;
            foreach ($this->_sql['where'] as $k => list($column, $op, $val, $type)) {
                $haswhere = true;
                if ($k !== 0) {
                    $sql .= $type . ' ';
                }
                switch ($op) {
                    case 'in':
                    case 'not in':
                        if (!is_array($val) && strpos(',', $val) !== false) {
                            $val = explode(',', $val);
                        }
                        if (is_array($val)) {
                            $val = array_map(function ($v) {
                                if (is_numeric($v)) {
                                    return $v;
                                } else {
                                    return "'" . $v . "'";
                                }
                            }, $val);
                            $val = implode(',', $val);
                        }
                        $sql .= $column . ' ' . $op . ' (' . $val . ') ';
                        break;
                    case 'exists':
                    case 'not exists':
                        $sql .= ' ' . $op . "(" . $column . ') ';
                        break;
                    case 'not between':
                    case 'between':
                        $sql .= ' ' . $op . ' ' . $val[0] . ' and ' . $val[1];
                        break;
                    default:
                        if (!is_numeric($val)) {
                            $val = "'" . $val . "'";
                        }
                        $sql .= $column . ' ' . $op . ' ' . $val . ' ';
                }
            }
            //find_in_set值
            foreach ($this->_sql['findinset'] as $k => list($column, $val, $type)) {
                if ($haswhere) {
                    $sql .= $type . ' ';
                    $haswhere = true;
                }
                $sql .= " find_in_set('$val',$column) ";
            }
            //where raw
            foreach ($this->_sql['whereraw'] as $raw) {
                $sql .= ' ' . $raw . ' ';
            }
        }
        return $sql;
    }

    /**
     * 根据where条件修改内容
     * @param  array $arr 要修改的数据 关联数组
     * @return mixed 执行结果
     */
    final public function update(array $arr)
    {
        //修改
        $sql = "update " . $this->getTable() . " set ";
        $sets = [];
        foreach ($arr as $key => $val) {
            $sets[] = $this->formatColumn($key) . ' = :' . $key;
        }
        $sql .= implode(',', $sets);
        $sql .= $this->buildWhere();
        $sth = $this->pdo->prepare($sql);
        $ret = $sth->execute($arr);
        $this->resetCon();
        return $ret;
    }

    /**
     * 要插入数据库的数据，可以是多维数组
     * 当为二维数组的时候插入多条数据
     * @param  array $arr 关联数组或者二维数组
     * @return integer 成功则返回最后插入的ID
     */
    final public function insert($arr = [])
    {
        $keys = [];
        if (count($arr) === 0) {
            return false;
        }
        $temp = $arr;
        $ele = array_pop($temp);
        if (is_array($ele)) {
            $keys = array_keys($ele);
        } else {
            $keys = array_keys($arr);
            $arr = [$arr];
        }
        //整理keys
        $params = array_map(function ($item) {
            return ':' . $item;
        }, $keys);
        $keys = array_map(function ($item) {
            return $this->formatColumn($item);
        }, $keys);

        $sql = "insert into " . $this->getTable() . " (" . implode(',', $keys) . ") values (" . implode(',', $params) . ")";
        $sth = $this->pdo->prepare($sql);
        $lastid = null;
        !$this->_begintransaction && count($arr) > 1 && $this->pdo->beginTransaction();
        foreach ($arr as $val) {
            $sth->execute($val);
            $lastid = $this->pdo->lastInsertId();
        }
        !$this->_begintransaction && count($arr) > 1 && $this->pdo->commit();
        return $lastid;
    }

    /**
     * 事务开始启动事务
     * @return self
     * @throws \Vitex\Core\Exception
     */
    final public function begin()
    {
        if ($this->_begintransaction) {
            throw new Exception("已经开启了一个事务，请勿重新开启");
        }
        $this->pdo->beginTransaction();
        $this->_begintransaction = true;
        return $this;
    }

    /**
     * 提交事务
     * @return self
     */
    final public function commit()
    {
        $this->pdo->commit();
        $this->_begintransaction = false;
        return $this;
    }

    /**
     * ORM似的保存
     * 保存当前模型，如果存在主键则尝试修改，如果不存在主键则尝试新建
     * @param  string $id 主键的值
     * @return mixed  执行结果
     */
    final public function save($id = '')
    {
        $pkval = $id ?: $this->pkval;
        //有修改的数据
        if ($this->_setpost && $pkval) {
            //排除不修改的字段
            foreach ($this->exclude as $key => $exclude) {
                if (isset($this->_setpost[$key]) || $key == $this->pk) {
                    unset($this->_setpost[$key]);
                }
            }
            return $this->where($this->pk, '=', $pkval)->update($this->_setpost);
        }
        //保存新数据
        if ($this->_post) {
            return $this->insert($this->_post);
        }
        return null;
    }

    /**
     * 删除数据
     * @return boolean 删除的数据结果
     * @throws \Error
     */
    final public function delete()
    {
        $sql = "delete from " . $this->getTable() . " ";
        $where = $this->buildWhere();
        //条件判断
        //没有外部条件的时候查询当前对象是否有查询过的模型
        if (!$where && $this->isfind) {
            $this->where($this->pk, '=', $this->pkval);
            $where = $this->buildWhere();
        }
        if (!$where) {
            throw new \Error('删除全部数据请使用truncate方法');
        }
        $sql .= $where;
        $ret = $this->DB->execute($sql);
        $this->resetCon();
        return $ret;
    }

    /**
     * 清空当前指定的表
     * @return boolean
     */
    final public function truncate()
    {
        $table = $this->getTable();
        $sql = "truncate table " . $table;
        return $this->DB->execute($sql);
    }

    /**
     * 自增一个字段
     * @param  mixed $column 字段名,可以使用一个数组传递多个字段
     * @param  mixed $amount 自增的数制默认为1，如果是一个数组则对应前面的字段也必须为数组，如果column为数组此参数不为数组则默认所有字段增加相同的值
     * @return bool  执行sql的结果
     */
    final public function increment($column, $amount = 1)
    {
        return $this->stepField($column, $amount);
    }

    /**
     * 自减一个字段
     * @param  mixed $column 字段名,可以使用一个数组传递多个字段
     * @param  mixed $amount 自增的数制默认为1，如果是一个数组则对应前面的字段也必须为数组，如果column为数组此参数不为数组则默认所有字段增加相同的值
     * @return bool  执行sql的结果
     */
    final public function decrement($column, $amount = 1)
    {
        if (is_array($amount)) {
            $amount = array_map(function ($item) {
                return (0 - $item);
            }, $amount);
        } else {
            $amount = 0 - $amount;
        }
        return $this->stepField($column, $amount);
    }

    /**
     * 自减/增一个字段
     * @param  mixed $column 字段名,可以使用一个数组传递多个字段
     * @param  mixed $amount 自增的数制默认为1，如果是一个数组则对应前面的字段也必须为数组，如果column为数组此参数不为数组则默认所有字段增加相同的值
     * @return bool 执行sql的结果
     * @throws \Vitex\Core\Exception
     */
    private function stepField($column, $amount)
    {
        if (is_array($column)) {
            foreach ($column as &$val) {
                $this->formatColumn($val);
            }
            if (is_array($amount) && count($amount) != count($column)) {
                throw new Exception("传递的字段与自增值无法对应，请查看数量");
            }
        } else {
            $column = [$this->formatColumn($column)];
        }

        $sql = "update " . $this->getTable() . " set ";
        foreach ($column as $key => $val) {
            if (is_array($amount)) {
                $num = $amount[$key];
            } else {
                $num = $amount;
            }
            $sql .= $val . " = (" . $val . " + " . $num . ") ";
        }
        $sql .= $this->buildWhere();
        $ret = $this->DB->execute($sql);

        $this->resetCon();
        return $ret;
    }

    /**
     * 统计数量
     * @param  string $column 字段名
     * @return int    数量
     */
    public function count($column = '*')
    {
        $sql = $this->buildSql($column);
        $info = $this->DB->query($sql)->fetch(\PDO::FETCH_ASSOC);
        return isset($info['num']) ? $info['num'] : 0;
    }

    /**
     * 一个简化的array_map操作，可以按照指定的字段返回一个仅包含该字段的数组
     * @param  string $column 字段名
     * @return array  返回数组
     */
    final public function pluck($column)
    {
        $infos = $this->getAll();
        $info = array_map(function ($val) use ($column) {
            return $val[$column];
        }, $infos);
        return $info;
    }

    /**
     * 根据主键获取值
     * @param  string $id ID
     * @return mixed  返回值
     */
    final public function get($id = null)
    {
        if ($id !== null) {
            $this->where($this->pk, '=', $id);
            $this->pkval = $id;
        }

        return $this->_get();
    }

    /**
     * 根据查询条件返回数组
     * @return array
     */
    final public function getAll()
    {
        return $this->_getAll();
    }

    //统计查询

    private function _maxMinSumAvg($method, $field)
    {
        $field = $this->formatColumn($field);
        $this->_sql['select'] = [$method . "(" . $field . ") as info"];
        $sql = $this->limit(1)->buildSql();
        $info = $this->DB->query($sql)->fetch(\PDO::FETCH_ASSOC);
        return isset($info['info']) ? $info['info'] : 0;
    }

    /**
     * 查询指定字段的最大值
     * @param  string $field 字段名
     * @return number 返回最大值
     */
    final public function max($field)
    {
        return $this->_maxMinSumAvg('max', $field);
    }

    /**
     * 查询指定字段的最小值
     * @param  string $field 字段名
     * @return number 返回最小值
     */
    final public function min($field)
    {
        return $this->_maxMinSumAvg('min', $field);
    }

    /**
     * 查询指定字段的平均值
     * @param  string $field 字段名
     * @return number 返回平均值
     */
    final public function avg($field)
    {
        return $this->_maxMinSumAvg('avg', $field);
    }

    /**
     * 查询指定字段的和值
     * @param  string $field 字段名
     * @return number 返回和值
     */
    final public function sum($field)
    {
        return $this->_maxMinSumAvg('sum', $field);
    }

    /**
     * 直接按照分页查询相关的信息，包括总页数以及当前分页的内容
     * @param          integer $page  当前要查询的页码
     * @param  integer $num 每页的信息条数 默认10条
     * @return array   $info 返回值，第一个元素是包含的信息，第二个元素是总的行数
     */
    final public function page($page = 1, $num = 10)
    {
        $start = ($page - 1) * $num;
        $this->limit($num, $start);
        $bak = $this->_sql;
        $infos = $this->_getAll();
        $this->_sql = $bak;
        $total = $this->count();
        return [$infos, $total];
    }

    /**
     * 执行指定的select类型sql
     * @param  string $sql sql语句
     * @param  int    $type 返回类型，默认为关联数组，可以指定其他类型具体查看PDO文档
     * @return array  返回值，多维数组
     */
    final public function fetchAll($sql, $type = \PDO::FETCH_ASSOC)
    {
        $sth = $this->DB->query($sql);
        return $this->DB->fetchAll($type);
    }

    /**
     * 执行指定的select类型sql
     * @param  string $sql sql语句
     * @param  int    $type 返回类型，默认为关联数组，可以指定其他类型具体查看PDO文档
     * @return array  返回值，一维数组
     */
    final public function fetch($sql, $type = \PDO::FETCH_ASSOC)
    {
        $info = $this->DB->query($sql)->fetch($type);
        return $info;
    }

    /**
     * 执行一个没有返回值的sql语句
     * @param  string $sql sql语句
     * @return int    执行是否成功
     */
    final public function execute($sql)
    {
        return $this->DB->execute($sql);
    }

    private function _get()
    {
        $sql = $this->limit(1)->buildSql();
        $info = $this->DB->query($sql)->fetch(\PDO::FETCH_ASSOC);
        $this->_post = $info;
        $this->isfind = true;
        return $info;
    }

    private function _getAll()
    {
        $sql = $this->buildSql();
        $sth = $this->DB->query($sql);
        return $this->DB->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 重置各种查询条件
     * @return void
     */
    private function resetCon()
    {
        $this->_sql = [
            'where'     => [],
            'whereraw'  => [],
            'findinset' => [],
            'select'    => [],
            'distinct'  => [],
            'from'      => '',
            'limit'     => '',
            'offset'    => 0,
            'group'     => [],
            'having'    => [],
            'union'     => [],
            'on'        => [],
            'join'      => [],
            'order'     => [],
        ];
    }
}
