<?php
/**
 * Created by PhpStorm.
 * User: albertqiu
 * Date: 2022/8/26
 * Time: 10:20
 */

namespace fycross\redis;


use think\facade\Config;
use think\facade\Log;

class RedisRw
{
    /** */
    public $redisParams = [];
    private static $cacheInstance = [];
    private static $persistInstance = [];
    private $instance  = [];
    private $readOp = [
        'bitcount' => 1,
        'getbit' => 1,
        'getrange' => 1,
        'mget' => 1,
        'getmultiple' => 1,
        'strlen' => 1,
        'dump' => 1,
        'exists' => 1,
        'keys' => 1,
        'getkeys' => 1,
        'object' => 1,
        'randomkey' => 1,
        'ttl' => 1,
        'pttl' => 1,
        'type' => 1,
        'sort' => 1,
        'scan' => 1,
        'hexists' => 1,
        'hget' => 1,
        'hgetall' => 1,
        'hkeys' => 1,
        'hlen' => 1,
        'hmget' => 1,
        'hvals' => 1,
        'hstrlen' => 1,
        //'lindex' => 1, 'lget' => 1, 'llen' => 1, 'lsize' => 1, 'lrange' => 1,
        'scard' => 1,
        'ssize' => 1,
        'sdiff' => 1,
        'sinter' => 1,
        'sismember' => 1,
        'scontains' => 1,
        'smembers' => 1,
        'sgetmembers' => 1,
        'srandmember' => 1,
        'sunion' => 1,
        'zcard' => 1,
        'zsize' => 1,
        'zcount' => 1,
        'zinter' => 1,
        'zrange' => 1,
        'zrangebylex' => 1,
        'zrank' => 1,
        'zrevrank' => 1,
        'zrevrange' => 1,
        'zscore' => 1
    ];

    protected $options = [
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'password'   => '',
        'select'     => 0,
        'timeout'    => 5,
        'expire'     => 0,
        'persistent' => false,
        'prefix'     => '',
        'inner'      => true,
        'ismaster'   => null
    ];

    public function __construct(array $redisParams = [], bool $inner = false, int $db = 0)
    {
        if ($redisParams) {
            // 从外部实例化该类
            $this->options['inner'] = $inner;
            $this->addServer($redisParams, $db);
        }
    }

    public function addServer($servername, $db = null)
    {
        if (! extension_loaded('redis')) {
            throw new \BadFunctionCallException('No redis extension installed');
        }
        $config = is_string($servername) ? Config::get($servername) : $servername;
        if (! empty($config['host'])) {
            $this->options = array_merge($this->options, $config);
        } else {
            throw new \Exception($servername .': the configuration does not exist');
        }
        // 此处进行分布式配置
        $params = [
            'hosts'    => explode(',', $this->options['host']),
            'ports'    => explode(',', $this->options['port']),
            'password' => explode(',', $this->options['password']),
            'select'   => explode(',', isset($db) ? $db : $this->options['select'])
        ];
        $hostsNum = count($params['hosts']);
        for ($i = 0; $i < $hostsNum; $i++) {
            $arr = [
                'host' => $params['hosts'][$i],
                'port' => $params['ports'][$i] ?? $params['ports'][0],
                'password' => $params['password'][$i] ?? $params['password'][0]
            ];
            empty($params['select'][0]) || ($arr['select'] = $params['select'][0]);
            $this->redisParams[$i] = $arr;
            $arr = null;
        }
        $params = null;
    }

    /**
     * Get current work id.
     */
    public static function getWorkId()
    {
        if (defined('RUNTIME_RPC')) {
            return \Swoole\Coroutine::getuid();
        } else {
            return getmypid();
        }
    }

    /**
     * 返回非持久化数据存储连接
     */
    public static function getInstance(string $servername = 'redis', $db = null)
    {
        $n = $servername . static::getWorkId();
        if (empty(self::$cacheInstance[$n])) {
            self::$cacheInstance[$n] = new self();
            self::$cacheInstance[$n]->addServer($servername, $db);
        } elseif (isset($db)) {
            self::$cacheInstance[$n]->mySelect($db);
        }
        return self::$cacheInstance[$n];
    }

    /**
     * 持久化
     */
    public static function getPersistInstance(string $servername = 'persistRedis', $db = null)
    {
        $n = $servername . static::getWorkId();
        if (empty(self::$persistInstance[$n])) {
            self::$persistInstance[$n] = new self();
            self::$persistInstance[$n]->addServer($servername, $db);
        } elseif (isset($db)) {
            self::$persistInstance[$n]->mySelect($db);
        }
        return self::$persistInstance[$n];
    }

    private function generateRedis($redisParams, $linkNum = 0, $reconnect = null)
    {
        if (isset($this->instance[$linkNum])) {
            if ($reconnect) {
                try {
                    $this->instance[$linkNum]->close();
                    $this->instance[$linkNum] = null;
                } catch (\Throwable $e) {}
            }
        }
        if (empty($this->instance[$linkNum])) {
            $this->instance[$linkNum] = new \Redis();
            if ($this->options['persistent']) {
                $this->instance[$linkNum]->pconnect($redisParams['host'], $redisParams['port'], $this->options['timeout']);
            } else {
                $this->instance[$linkNum]->connect($redisParams['host'], $redisParams['port'], $this->options['timeout']);
            }
            if (! empty($redisParams['password'])) {
                $this->instance[$linkNum]->auth($redisParams['password']);
            }
            if (! empty($redisParams['select']) || (! $reconnect && isset($redisParams['select']))) {
                $this->instance[$linkNum]->select($redisParams['select']);
            }
        }
        return $this->instance[$linkNum];
    }

    /**
     * 判断是否master/slave,调用不同的master或者slave实例
     *
     */
    protected function isMaster($master = true, $reconnect = null)
    {
        $count = count($this->redisParams);
        if ($this->options['ismaster']) {
            $master = true;
            $this->options['ismaster'] = null;
        }
        $i = ($master || 1 == $count) ? 0 : mt_rand(1, $count - 1);
        return $this->generateRedis($this->redisParams[$i], $i, $reconnect);
    }

    /**
     * 获取实际的缓存标识
     * @access public
     * @param string $name 缓存名
     * @return string
     */
    protected function getCacheKey($name)
    {
        return $this->options['prefix'] . $name;
    }

    protected function recordLog(\Throwable $e)
    {
        Log::write('error:' . $e->getMessage() .'; '. $e->getTraceAsString(), 'redisrw', []);
    }

    public function mySelect(int $db)
    {
        foreach ($this->redisParams as $num => &$params) {
            if (isset($params['select'])) {
                if ($params['select'] == $db) continue;
                $params['select'] = $db;
            } else {
                $params['select'] = $db;
                if (0 == $db) continue;
            }

            if (isset($this->instance[$num])) {
                try {
                    $this->instance[$num]->select($db);
                } catch (\RedisException $e) {
                    $this->generateRedis($params, $num, true);
                }
            }
        }
    }

    /**
     * @param String $key hash键
     * @param Long $it 迭代的游标
     * @return mixed
     */
    public function hScan($key, &$it, $pattern = null, $count = null)
    {
        $num = 0;
        $result = null;
        $key   = $this->getCacheKey($key);
        try {
            REDO:
            $redis = $this->isMaster(false, $num > 0);
            $result = $redis->hScan($key, $it, $pattern, $count);
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    /**
     * @param String $key hash键
     * @param Long $it 迭代的游标
     * @return mixed
     */
    public function sScan($key, &$it, $pattern = null, $count = null)
    {
        $num = 0;
        $result = null;
        $key   = $this->getCacheKey($key);
        try {
            REDO:
            $redis = $this->isMaster(false, $num > 0);
            $result = $redis->sScan($key, $it, $pattern, $count);
        } catch (\Exception $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    /**
     * @param String $key hash键
     * @param Long $it 迭代的游标
     * @return mixed
     */
    public function zScan($key, &$it, $pattern = null, $count = null)
    {
        $num = 0;
        $result = null;
        $key   = $this->getCacheKey($key);
        try {
            REDO:
            $redis = $this->isMaster(false, $num > 0);
            $result = $redis->zScan($key, $it, $pattern, $count);
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    public function lPushRemove($key, $value, $max = null)
    {
        $num = 0;
        $result = null;
        try {
            REDO:
            $redis = $this->isMaster(true, $num > 0);
            if ($max) {
                if ($redis->lLen($key) > $max) {
                    $redis->rPop($key);
                }
            }
            $result = $redis->lPush($key, $value);
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    public function rPushRemove($key, $value, $max = null)
    {
        $num = 0;
        $result = null;
        try {
            REDO:
            $redis = $this->isMaster(true, $num > 0);
            if ($max) {
                if ($redis->lLen($key) > $max) {
                    $redis->lPop($key);
                }
            }
            $result = $redis->rPush($key, $value);
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    /**
     * 读取缓存
     * @access public
     * @param string $name 缓存变量名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get($name, $default = false)
    {
        $num = 0;
        $value = null;
        try {
            REDO:
            $redis = $this->isMaster(false, $num > 0);
            $value = $redis->get($this->getCacheKey($name));
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        if ($this->options['inner']) {
            return $value;
        } else {
            if (is_null($value)) {
                return $default;
            }
            $jsonData = json_decode($value, true);
            // 检测是否为JSON数据 true 返回JSON解析数组, false返回源数据
            return (null === $jsonData) ? $value : $jsonData;
        }
    }

    /**
     * 写入缓存
     * @access public
     * @param string    $name 缓存变量名
     * @param mixed     $value  存储数据
     * @param integer   $expire  有效时间（秒）
     * @return boolean
     */
    public function set($name, $value, $expire = null)
    {
        $num = 0;
        $result = null;
        try {
            REDO:
            $redis = $this->isMaster(true, $num > 0);
            $key = $this->getCacheKey($name);
            if ($this->options['inner']) {
                $result = $redis->set($key, $value, $expire);
            } else {
                //对数组/对象数据进行缓存处理，保证数据完整性
                $value = (is_object($value) || is_array($value)) ? json_encode($value) : $value;
                $result = $redis->set($key, $value, $expire);
            }
        } catch (\RedisException $e) {
            $num++;
            if($num < 2) goto REDO;
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    /**
     * 判断缓存
     * @param string $name 缓存变量名
     * @return bool
     */
    public function has($name)
    {
        $num = 0;
        $result = null;
        try {
            REDO:
            $redis = $this->isMaster(false, $num > 0);
            $result = $redis->exists($this->getCacheKey($name)) ? true : false;
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    /**
     * 自增缓存（针对数值缓存）
     * @param string    $name 缓存变量名
     * @param int       $step 步长
     * @return false|int
     */
    public function inc($name, $step = 1)
    {
        $redis = $this->isMaster();
        return $redis->incrby($this->getCacheKey($name), $step);
    }

    /**
     * 自减缓存（针对数值缓存）
     * @param string    $name 缓存变量名
     * @param int       $step 步长
     * @return false|int
     */
    public function dec($name, $step = 1)
    {
        $redis = $this->isMaster();
        return $redis->decrby($this->getCacheKey($name), $step);
    }

    /**
     * 删除缓存
     * @param string $name 缓存变量名
     * @return boolean
     */
    public function rm($name)
    {
        $num = 0;
        $result = null;
        try {
            REDO:
            $redis = $this->isMaster(true, $num > 0);
            $result = $redis->del($this->getCacheKey($name));
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }
        return $result;
    }

    public function delete($name)
    {
        return $this->rm($name);
    }

    public function flushAll()
    {
        throw new \Exception('Not allowed to using flushAll');
    }

    public function flushDb()
    {
        throw new \Exception('Not allowed to using flushDb');
    }

    /**
     * 设置读主库
     **/
    public function master()
    {
        $this->options['ismaster'] = true;
        return $this;
    }

    /**
     * Redis的统一调用
     */
    public function __call(string $name, $arguments)
    {
        $result = null;
        $num = 0;
        $command = strtolower($name);

        try {
            if (empty($arguments)) {
                if ($command == 'flushall' || $command == 'flushdb') {
                    throw new \Exception('Not allowed to using ' . $name);
                }
            }
            REDO:
            $redis  = isset($this->readOp[$command]) ? $this->isMaster(false, $num > 0) : $this->isMaster(true, $num > 0);
            $result = call_user_func_array([$redis, $name], $arguments);
        } catch (\RedisException $e) {
            $num++;
            if ($num < 2) {
                goto REDO;
            }
            $this->recordLog($e);
        } catch (\Throwable $e) {
            $this->recordLog($e);
        }

        return $result;
    }

    /**
     * 关闭连接
     * @access public
     */
    public function close()
    {
        foreach ($this->instance as $redis) {
            $redis->close();
        }
        $this->instance = [];
        self::$persistInstance = null;
        self::$cacheInstance = null;
    }

    /**
     * 禁止克隆
     */
    private function __clone()
    {
    }

    public function __destruct()
    {
        $this->close();
    }
}