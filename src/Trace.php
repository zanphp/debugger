<?php

namespace ZanPHP\Debugger;

use ZanPHP\Contracts\Debugger\Tracer;
use ZanPHP\Support\LZ4;

class Trace implements Tracer
{
    private $hostInfo;

    private $traceHost;
    private $tracePort;
    private $tracePath = "/";
    private $traceArgs;

    private $tid;
    private $stack;
    private $json;

    public function __construct()
    {
        $this->tid = -1;
        $this->stack = [];

        $this->hostInfo = [
            "app" => getenv("appname"),
            "host" => gethostname(),
            "ip" => nova_get_ip(),
            "port" => getenv("port"),
            "pid" => getmypid(),
        ];
    }

    public function parseTraceURI(array $ctx)
    {
        $ctx = array_change_key_case($ctx, CASE_LOWER);
        $key = strtolower(Tracer::KEY);

        if (isset($ctx[$key])) {
            $url = $ctx[$key];
            if (is_array($url) && $url) {
                $url = $url[0];
            }
            return $this->parseUrl($url);
        }
        return false;
    }

    public function beginRequest($type, $name, $req)
    {
        $this->beginTransaction($type, "self-$name", $req);
    }

    public function endRequest($exception = null)
    {
        if ($exception) {
            $this->commit(0, "error", $exception);
        } else {
            $this->commit(0, "info");
        }

        $this->report();
    }

    public function beginTransaction($traceType, $name, $req)
    {
        list($usec, $sec) = explode(' ', microtime());
        $begin = $sec + $usec;
        $ts = date("Y-m-d H:i:s", $sec) . substr($usec, 1, 4);

        $trace = [$begin, $ts, $traceType, $name, $req];

        $this->stack[] = $trace;
        return ++$this->tid;
    }

    public function commit($tid, $logType, $res = [])
    {
        if (!isset($this->stack[$tid])) {
            return;
        }

        list($begin, $ts, $traceType, $name, $req) = $this->stack[$tid];

        list($usec, $sec) = explode(' ', microtime());
        $end = $sec + $usec;

        $info = [
            "ts" => $ts,
            "cost" => ceil(($end - $begin) * 1000) . "ms",
            // "req" => self::convert($req),
            // "res" => self::convert($res),
            "req" => \json_encode($req),
            "res" => \json_encode($res),
        ];

        $this->trace($logType, $traceType, $name, $info);
        unset($this->stack[$tid]);
    }

    public function trace($logType, $traceType, $name, $detail)
    {
        $this->json['traces'][] = [$logType, $traceType, $name, $detail];
    }

    private function parseUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $path = parse_url($url, PHP_URL_PATH) ?: "/";
        $query = parse_url($url, PHP_URL_QUERY) ?: "";
        parse_str($query, $args);

        if (empty($host) || empty($port)) {
            return false;
        }

        if (!isset($args["id"])) {
            $args["id"] = $this->generateId();
        }

        $this->json = $this->hostInfo;
        $this->json["trace_id"] = $args["id"];
        $this->json["traces"] = [];

        $this->traceHost = $host;
        $this->tracePort = $port;
        $this->tracePath = $path;
        $this->traceArgs = $args;

        return true;
    }

    private function generateId()
    {
        $appName = getenv("appname");
        $ip = nova_get_ip();

        $hexIp = dechex(ip2long($ip));
        $zeroLen = strlen($hexIp);
        if ($zeroLen < 8) {
            $hexIp = "0$hexIp";
        }
        $microtime = str_replace('.', '', microtime(true));
        return implode('-', [
            $appName,
            $hexIp,
            $microtime,
            rand(100000, 999999)
        ]);
    }

    public function getKey()
    {
        return self::buildKey($this->traceHost, $this->tracePort, $this->tracePath, $this->traceArgs);
    }

    private static function buildKey($host, $port, $path, $args)
    {
        return "{$host}:{$port}{$path}?" . http_build_query($args);
    }

    private function report()
    {
        foreach ($this->stack as $tid => $trace) {
            $this->commit($tid, "missing commit", []);
        }

        /** @noinspection PhpUnusedParameterInspection */
        swoole_async_dns_lookup($this->traceHost, function($host, $ip) {
            $cli = new \swoole_http_client($ip, intval($this->tracePort));
            $cli->setHeaders([
                "Connection" => "Closed",
                "Content-Type" => "application/json;charset=utf-8",
            ]);
            $timeout = isset($this->traceArgs["timeout"]) ? intval($this->traceArgs["timeout"]) : 5000;
            $timerId = swoole_timer_after($timeout, function() use($cli) {
                if ($cli->isConnected()) {
                    $cli->close();
                }
            });

            $body = json_encode($this->json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"err":"json_encode fail"}';
            $query = http_build_query($this->traceArgs);
            $uri = "{$this->tracePath}?{$query}";
            $cli->post($uri, $body, function(\swoole_http_client $cli) use($timerId) {
                swoole_timer_clear($timerId);
                $cli->close();
            });
        });
    }

    public static function convert($var)
    {
        $var = is_array($var) ? $var : [ $var ];
        return array_map(["self", "convertHelper"], $var);
    }

    public static function convertHelper($object, $processed = [])
    {
        $type = gettype($object);
        switch ($type) {
            case "string":
                $lz4 = LZ4::getInstance();
                if ($lz4->isLZ4($object)) {
                    $object = $lz4->decode($object);
                }
                return mb_convert_encoding($object, 'UTF-8', 'UTF-8');
            case "array":
                return array_map(["self", "convertHelper"], $object);
            case "object":
                if ($object instanceof \Throwable || $object instanceof \Exception) {
                    return [
                        "class" => get_class($object),
                        "msg" => $object->getMessage(),
                    ];
                }
                $processed[] = $object;
                $kv = [ "class" => get_class($object) ];
                $reflect = new \ReflectionClass($object);
                foreach ($reflect->getProperties() as $prop) {
                    // 2017-08-09忽略非public属性
                    if (!$prop->isPublic()) {
                        continue;
                    }

                    $prop->setAccessible(true);
                    $value = $prop->getValue($object);
                    if ($value === $object || in_array($value, $processed, true)) {
                        $value = '*recursion* - parent object [' . get_class($value) . ']';
                    }
                    $accessModifier = self::getAccessModifier($prop);
                    $kv[$accessModifier] = self::convertHelper($value);
                }
                return $kv;

            case "boolean":
            case "integer":
            case "double":
            case "resource":
            case "NULL":
            case "unknown type":
            default:
                return $object;
        }
    }

    private static function getAccessModifier(\ReflectionProperty $prop)
    {
        $static = $prop->isStatic() ? ' static' : '';

        if ($prop->isPublic()) {
            return 'public' . $static . ' ' . $prop->getName();
        } else if ($prop->isProtected()) {
            return 'protected' . $static . ' ' . $prop->getName();
        } else if ($prop->isPrivate()) {
            return 'private' . $static . ' ' . $prop->getName();
        } else {
            return 'unknown';
        }
    }
}