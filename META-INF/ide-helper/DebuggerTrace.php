<?php

namespace Zan\Framework\Sdk\Trace;


use Zan\Framework\Contract\Network\Request;
use Zan\Framework\Utilities\DesignPattern\Context;


class DebuggerTrace
{
    public static function make(Request $request, Context $context)
    {

    }

    private function __construct($host, $port, $path, array $args = [])
    {

    }

    public function getKey()
    {
    }

    public function beginTransaction($traceType, $name, $req)
    {

    }

    public function commit($logType, $res = [])
    {

    }

    public function trace($logType, $traceType, $name, $detail)
    {
    }

    public function report()
    {

    }

    public static function convert($var)
    {
    }

    public static function convertHelper($object, $processed = [])
    {

    }
}