<?php
/**
 * Created by PhpStorm.
 * User: albertqiu
 * Date: 2022/8/26
 * Time: 15:29
 */

namespace fycross\cache;


use fycross\common\DataToObjArrService;
use think\Exception;

class CacheFactory
{
    private $object = null;

    private $autoConver = false;

    public function __construct(string $class)
    {
        $this->object = Cache::getObject($class);

    }

    protected function getCacheOption(string $key)
    {
        return $this->object->getOption($key);
    }

    public function __call(string $call, array $argvs = [])
    {
        if (method_exists($this->object, $call)) {
            $ret = call_user_func_array([$this->object, $call], $argvs);
            if ($ret && $this->autoConver) {
                $ret = new DataToObjArrService($ret);
            }
            return $ret;
        } else {
            $debug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $class = get_called_class();
            throw new Exception($debug[0]['file'] . $debug[0]['line'] ." call class:{$class} not defined method:{$call}");
        }
    }

    public function __isset(string $propery)
    {
        switch ($propery) {
            case 'persistRedis':
            case 'redis':
                return true;
            default:
                return false;
        }
    }

    public function __get(string $propery)
    {
        switch ($propery) {
            case 'persistRedis':
            case 'redis':
                return $this->object->$propery;
            default:
                return false;
        }
    }

    public function __destruct()
    {
        unset($this->object);
    }

}