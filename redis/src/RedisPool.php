<?php
/**
 * Created by PhpStorm.
 * User: albertqiu
 * Date: 2022/8/26
 * Time: 10:19
 */

namespace fycross\redis;


use think\facade\Config;
use think\swoole\pool\Proxy;

class RedisPool extends Proxy
{
    protected static $driver = [];

    public static function createDriver(string $configName, int $db)
    {
        if (defined('RUNTIME_RPC')) {
            $key = $configName . $db;
            if (!isset(self::$driver[$key])) {
                self::$driver[$key] = new self(function () use ($configName, $db) {
                    return new RedisRw(Config::get($configName), true, $db);
                }, Config::get('swoole.pool.cache', []));
            }
            return self::$driver[$key];
        } else {
            return new class($configName, $db) {

                protected $configName;
                protected $db;

                public function __construct($configName, $db)
                {
                    $this->configName = $configName;
                    $this->db         = $db;
                }

                public function __call($method, $arguments)
                {
                    return RedisRw::getInstance($this->configName, $this->db)->{$method}(...$arguments);
                }
            };
        }
    }
}