<?php
/**
 * Created by PhpStorm.
 * User: albertqiu
 * Date: 2022/8/25
 * Time: 15:40
 */

namespace fycross\cache;

use fycross\redis\RedisPool;
use think\Exception;
use fycross\cache\CacheFactory;

class Cache
{
    /**
     * @var \Redis
     */
    private static $object = [];

    private static $factory = [];

    /**
     * @var 指定redis类型：0 非持久化(默认)， 1 持久化
     */
    protected $type = 0;

    /**
     * @var int 指定redis库，默认为0库
     */
    protected $db = 0;

    /**
     * 返回redis对象
     * @param bool|false $persist
     * @return \Redis
     */
    public static function handler(bool $persist = false, int $db = 0)
    {
        $self = new self();
        if ($db > 0) {
            $self->db = $db;
        }
        return $self->connect($persist ? 1 : 0);
    }

    /**
     * 获取连接
     * @return \Redis
     */
    public function connect($type = null)
    {
        $type = $type ?? $this->type;
        switch ($type) {
            case 0:
                // 非持久化
                $this->redis = RedisPool::createDriver('cache.stores.redis', $this->db);
                return $this->redis;
            case 1:
                // 持久化
                $this->persistRedis = RedisPool::createDriver('cache.stores.persistRedis', $this->db);
                return $this->persistRedis;
            default:
                throw new Exception('not defined type:' . $type);
        }
    }

    public static function getObject(string $class)
    {
        return self::$object[$class];
    }
    /**
     * @doc 获取一个缓存类对象
     * @param $name string 名称
     * @param $class string 缓存类
     * @param $bool boolean 是否重新实例化
     * @return object
     * @throws Exception
     */
    private static function instranceCacheObject(string $name, string $class, bool $newInstance)
    {
        if (! $class || ! class_exists($class)) {
            throw new Exception("The file ( $class ) is not found: ");
        }

        if (empty(self::$object[$name])) {
            self::$object[$name] = new $class();
        }
        if ($newInstance) {
            return new CacheFactory($class);
        }
        if (empty(self::$factory[$name])) {
            self::$factory[$name] = new CacheFactory($class);
        }
        return self::$factory[$name];
    }

    /**
     * 载入cache模型
     * @param string $name
     * @param bool $newInstance
     * @return CacheFactory|object
     * @throws Exception
     */
    public static function load(string $name, bool $newInstance = false)
    {
        if (false !== strpos($name, '\\')) {
            // 类名路径
            $className = $name;
        } else {
            $name = ucwords($name);
            $className = "\\app\\common\\cache\\{$name}Cache";
        }
        return static::instranceCacheObject($className, $className, $newInstance);
    }

    /**
     * 设置缓存内容
     * @param $key
     * @param $value
     * @param null $expire
     * @param bool $persist
     * @return bool
     */
    public static function set(string $key, string $value, int $expire = 0, bool $persist = false)
    {
        return self::handler($persist)->set($key, $value, $expire);
    }

    /**
     * 获取缓存内容
     * @param $key
     * @param bool|false $persist
     * @return bool
     */
    public static function get(string $key, bool $persist = false)
    {
        return self::handler($persist)->get($key);
    }

    /**
     * 设置 key 的过期时间
     * @param string $key 键
     * @param int $seconds 秒
     * @param bool|false $persist
     * @return boolean true|false
     */
    public static function expire(string $key, int $seconds, bool $persist = false)
    {
        return self::handler($persist)->expire($key, $seconds);
    }

    /**
     * 删除key
     * @param string $key 键
     * @param bool|false $persist
     */
    public static function del(string $key, bool $persist = false)
    {
        self::handler($persist)->del($key);
    }
}