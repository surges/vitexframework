<?php
/**
 * Vitex 一个基于php8.0开发的 快速开发restful API的微型框架
 * @version  2.0.0
 *
 * @package vitex\service\model
 *
 * @author  skipify <skipify@qq.com>
 * @copyright skipify
 * @license MIT
 */

namespace vitex\service\model;

use vitex\core\Exception;
use vitex\service\log\LogUtil;
use vitex\Vitex;

/**
 * PDO链接
 * @package vitex\service\model
 */
class Pdo
{
    public $engine = 'mysql';
    public $pdo = null;
    public $debugSql = '';
    public $debugData = [];

    /**
     * @var \PDOStatement
     */
    public $sth = null;
    public $error; //错误信息

    public function __construct($setting, $username = '', $password = '')
    {
        if (!$setting) {
            throw new \PdoException('DataBase Connect Config Missing', Exception::CODE_PARAM_NUM_ERROR);
        }
        if (is_resource($setting)) {
            $this->pdo = $setting;
        } else if (is_array($setting)) {
            $username = $username ?: ($setting['username'] ?? '');
            $password = $password ?: ($setting['password'] ?? '');
            $this->pdo = new \Pdo($this->getDsn($setting), $username, $password);
        } else {
            $this->pdo = new \Pdo($setting, $username, $password);
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * 获取DSN连接字符
     * @param array $p 包含database、host、charset的链接字符
     * @return string dsn
     */
    public function getDsn($p)
    {
        // see：pgsql:dbname=database;host=localhost;port=5866
        if (!empty($p['dsn']) && is_string($p['dsn'])){

            $this->engine = substr($p['dsn'],0, strpos($p['dsn'], ":"));

            if(empty($this->engine)){
                throw new \PdoException("The database connection is configured in DSN mode, but the connection mode is incorrectly configured. see 'pgsql:dbname=database;host=localhost;port=5866'");
            }

            return $p['dsn'];
        }

        $this->engine = $p['engine'] ?? $this->engine;
        return $this->engine . ':dbname=' . $p['database'] . ';host=' . $p['host'] . ';charset=' . ($p['charset'] ?? 'utf8');
    }

    /**
     * 执行sql语句，支持预处理语句
     * @param string $sql sql语句
     * @param array $arr 预处理时传递的参数
     * @return int    影响的行数
     */
    public function execute($sql, $arr = [])
    {
        $this->debugSql = $sql;
        $this->debugData = $arr;
        $this->sth = $this->pdo->prepare($sql);
        try {
            $this->sth->execute($arr);
        } catch (\PDOException $e) {
            $this->errorInfo($sql, $e->getMessage());
            throw $e;
        }
        $count = $this->sth->rowCount();
        return $count;
    }

    /**
     * 执行sql语句返回statement 支持预处理
     * @param string $sql sql语句
     * @param array $arr 预处理参数
     * @return object PDOStatement的实例
     */
    public function query($sql, $arr = [])
    {
        $this->debugSql = $sql;
        $this->debugData = $arr;
        $this->sth = $this->pdo->prepare($sql);
        try {
            $data = [];
            foreach ($arr as $key => $val) {
                if ($key[0] != ':') {
                    $data[':' . $key] = $val;
                } else {
                    $data[$key] = $val;
                }
            }
            $this->sth->execute($data);
        } catch (\PDOException $e) {
            $this->sth->debugDumpParams();
            $this->errorInfo($sql, "RunSql:" . $this->debugSql . "Error:" . $e->getMessage());
            throw $e;
        }
        return $this->sth;
    }

    /**
     * 打印执行的一些调试信息
     */
    public function debugDumpParams()
    {
        if ($this->sth) {
            $this->sth->debugDumpParams();
        } else {
            //打印
            echo "SQL:".$this->debugSql;
            echo "DATA:";
            print_r($this->debugData);
        }
    }

    /**
     * 执行返数据，生成器数据
     * @param int|string $mode 返回的数据模式，默认为class模式
     * @return \Generator 一个信息对象
     */
    public function fetch($mode = \PDO::FETCH_CLASS)
    {
        $run = null;
        try {
            $run = $this->sth->fetch($mode);
        } catch (\PDOException $e) {
            $this->errorInfo('', $e->getMessage());
            throw $e;
        }
        yield $run;
    }

    /**
     * 返回全部的复合查询的数据
     * @param int|string $mode 返回的数据模式，默认为class模式
     * @return array      一个包含对象的数组
     */
    public function fetchAll($mode = \PDO::FETCH_CLASS)
    {
        try {
            $rows = $this->sth->fetchAll($mode);
        } catch (\PDOException $e) {
            $this->errorInfo('', $e->getMessage());
            throw $e;
        }
        return $rows;
    }

    /**
     * 返回刚才操作的行的id 对auto_increment的值有效
     * @return int
     * @author skipify
     */
    public function lastId()
    {
        return $this->pdo->lastInsertId();
    }

    public function close()
    {
        $this->pdo = null;
        $this->sth = null;
    }

    /**
     * 错误信息
     *
     * @param $sql
     * @param $error
     * @author skipify
     *
     */
    public function errorInfo($sql, $error)
    {
        $vitex = Vitex::getInstance();
        if ($vitex->getConfig('debug')) {

            $msg = "<p style='color:red;font-weight:bold'>" . $sql . "<p>";
            $msg .= "<p>" . $error . "</p>";
        } else {
            $msg = 'SQL:' . $sql . '  Error: ' . $error;
            LogUtil::instance()->error($msg);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
