<?php
/**
 * Created by PhpStorm.
 * User: albertqiu
 * Date: 2022/8/25
 * Time: 18:56
 */

namespace fycross\captcha;


use think\Config;

use think\facade\Cache;
use think\Session;

class Captcha extends \think\captcha\Captcha
{
    private $codeHash;
    protected $codeExpire = 180;
    private $session;

    public function __construct(Config $config, Session $session)
    {
        parent::__construct($config, $session);
        $this->session = $session;
    }

    /**
     * 创建验证码
     * @return array
     * @throws Exception
     */
    protected function generate(): array
    {
        $bag = '';

        if ($this->math) {
            $this->useZh = false;
            $num = rand(1, 3);
            switch ($num){
                case 1:
                    $num1  = mt_rand(1, 20);
                    $num2  = mt_rand(1, $num1);
                    $key  = $num1 + $num2;
                    $bag = "{$num1} + {$num2} = ";
                    break;
                case 2:
                    $num1  = mt_rand(1, 20);
                    $num2  = mt_rand(1, $num1);
                    $num1 == $num2 && ($num1 = $num1 + mt_rand($num1 + 1, 25));
                    $key  = $num1 - $num2;
                    $bag = "{$num1} - {$num2} = ";
                    break;
                case 3:
                default :
                    $num1  = mt_rand(1, 9);
                    $num2  = mt_rand(1, $num1);
                    $key  = $num1 * $num2;
                    $bag = "{$num1} X {$num2} = ";
                    break;
            }
        } else {
            if ($this->useZh) {
                $characters = preg_split('/(?<!^)(?!$)/u', $this->zhSet);
            } else {
                $characters = str_split($this->codeSet);
            }

            for ($i = 0; $i < $this->length; $i++) {
                $bag .= $characters[rand(0, count($characters) - 1)];
            }

            $key = mb_strtolower($bag, 'UTF-8');
        }

        $hash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]);
        $uuid = $this->createUuid();
        $this->save($hash, $uuid);
        header("Captcha:{$uuid}");
        header("Access-Control-Expose-Headers:Captcha");

        return [
            'value' => $bag,
            'key'   => $hash,
        ];
    }

    public function verify($code)
    {
        $uuid     = $this->session->getId();
        $cacheKey = $this->getCacheKey($uuid);
        $codeHash = Cache::store('redis')->get($cacheKey);
        $code = mb_strtolower($code, 'UTF-8');
        $result = password_verify($code, $codeHash);
        if ($result) {
            Cache::store('redis')->delete($cacheKey);
        }
        return $result;
    }

    /**
     * 验证验证码是否正确
     * @access public
     * @param string $code 用户验证码
     * @return bool 用户验证码是否正确
     */
    public function check(string $code): bool
    {
        $code = mb_strtolower($code, 'UTF-8');

        $res = password_verify($code, $this->codeHash);

        return $res;
    }

    protected function createUuid()
    {
        return $this->session->getId();
    }

    private function getCacheKey($uuid)
    {
        return $cacheKey = "common:captcha:{$uuid}";
    }

    private function save($hash, $uuid)
    {
        $cacheKey = $this->getCacheKey($uuid);
        Cache::store('redis')->setex($cacheKey, $this->codeExpire, $hash);
    }
}