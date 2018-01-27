<?php
/**
 * Created by PhpStorm.
 * User: 16020028
 * Date: 2018/1/23
 * Time: 9:49
 */
namespace tourze\swoole\yii2;

use Swoole\Client\RPC_Result;
use Swoole\Protocol\RPCServer;
use Swoole\Client\TCP;
use Swoole\Tool;
use Swoole;

class RPC extends \Swoole\Client\RPC
{
    public $connectionTimeout = 0.05;

    public $onConnectServerFailedFunction;

    public $getServerFunction;

    public $serName;

    private $_on;

    /**
     * 加入属性服务名称
     * RPC constructor.
     *
     * @param null $id
     */
    function __construct($id = null)
    {
        parent::__construct($id);
        $this->serName = $id;
    }

    /**
     * 绑定事件
     * @param $event
     * @param $callback
     */
    public function on($event, $callback)
    {
        $events = [
            'onConnectServerFailed' => '连接服务器失败事件,传入连接失败的服务[ip,port]',
            'getServer' => '获取可用服务器事件，传入serName',
        ];

        if(!array_key_exists($event, $events)){
            return;
        }

        $this->_on[$event] = $callback;
    }

    /**
     * 连接到服务器
     * @param RPC_Result $retObj
     * @return bool
     * @throws \Exception
     */
    protected function connectToServer($retObj)
    {

        if($this->_on['getServer']){
            $this->servers =  call_user_func_array($this->_on['getServer'], [$this->serName]);
        }

        return parent::connectToServer($retObj);
    }

    /**
     * 连接rpc服务器
     * @param $host
     * @param $port
     *
     * @return bool|\Swoole\Client\Stream|\Swoole\Client\TCP|\swoole_client|Stream|TCP
     */
    protected function getConnection($host, $port)
    {
        $ret = false;
        $conn_key = $host.':'.$port;
        if (isset($this->connections[$conn_key]))
        {
            return $this->connections[$conn_key];
        }
        //基于Swoole扩展
        if ($this->haveSwoole)
        {
            echo "--$conn_key++\n";
            $socket = new \swoole_client(SWOOLE_SOCK_TCP | SWOOLE_KEEP, SWOOLE_SOCK_SYNC);
            $socket->set(array(
                             'open_length_check' => true,
                             'package_max_length' => $this->packet_maxlen,
                             'package_length_type' => 'N',
                             'package_body_offset' => RPCServer::HEADER_SIZE,
                             'package_length_offset' => 0,
                         ));
            //$socket = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);

            /**
             * 尝试重连一次
             */
            for ($i = 0; $i < 2; $i++)
            {
                try{
                    $ret = $socket->connect($host, $port, $this->connectionTimeout);
                }catch (\Exception $e){
                    if($socket->errCode == 114 or $socket->errCode == 115){
                        continue;
                    }
                }
                break;
            }
        }
        //基于sockets扩展
        elseif ($this->haveSockets)
        {
            $socket = new TCP();
            $socket->try_reconnect = false;
            $ret = $socket->connect($host, $port, $this->timeout);
        }
        //基于stream
        else
        {
            $socket = new Stream();
            $ret = $socket->connect($host, $port, $this->timeout);
        }
        if ($ret)
        {
            $this->connections[$conn_key] = $socket;
            return $socket;
        }
        else
        {
            return false;
        }
    }

    /**
     * 连接服务器失败了,
     * @param $svr
     * @return bool
     */
    function onConnectServerFailed($svr)
    {
        parent::onConnectServerFailed($svr);

        //回调服务器连接失败方法
        if($this->_on['onConnectServerFailed']){
            call_user_func_array($this->_on['onConnectServerFailed'], [$svr]);
        }

        return false;
    }

    /**
     * @param $connection
     * @return bool|string
     */
    protected function recvPacket($connection)
    {
        if(!extension_loaded('pcntl')){
            return parent::recvPacket($connection);
        }

        declare(ticks = 1);  // 设置闹钟信号处理，抛异常退出循环
        pcntl_signal(SIGALRM, function(){throw new Exception('process_timeout');});
        pcntl_alarm(5);// 设置闹钟，5秒超时

        try {
            return parent::recvPacket($connection);
        } catch (Exception $e) {
            printf("Timeout: %s\n", $e->getMessage());
            return false;
        }
    }


    /**
     * 接收响应
     * @param $timeout
     * @return int
     */
    function wait($timeout = 0.5)
    {
        $st = microtime(true);
        $success_num = 0;

        while (count($this->waitList) > 0)
        {
            $write = $error = $read = array();
            foreach ($this->waitList as $obj)
            {
                /**
                 * @var $obj RPC_Result
                 */
                if ($obj->socket !== null)
                {
                    $read[] = $obj->socket;
                }
            }
            if (empty($read))
            {
                break;
            }
            //去掉重复的socket
            Tool::arrayUnique($read);
            //等待可读事件
            $n = $this->select($read, $write, $error, $timeout);
            if ($n > 0)
            {
                //可读
                foreach($read as $connection)
                {
                    $data = $this->recvPacket($connection);
                    //socket被关闭了
                    if ($data === "")
                    {
                        foreach($this->waitList as $retObj)
                        {
                            if ($retObj->socket == $connection)
                            {
                                $retObj->code = RPC_Result::ERR_CLOSED;
                                unset($this->waitList[$retObj->requestId]);
                                $this->closeConnection($retObj->server_host, $retObj->server_port);
                            }
                        }
                        continue;
                    }
                    elseif ($data === false)
                    {
                        continue;
                    }

                    $header = unpack(RPCServer::HEADER_STRUCT, substr($data, 0, RPCServer::HEADER_SIZE));
                    //不在请求列表中，错误的请求串号
                    if (!isset($this->waitList[$header['serid']]))
                    {
                        continue;
                    }
                    $retObj = $this->waitList[$header['serid']];
                    //成功处理
                    $this->finish(RPCServer::decode(substr($data, RPCServer::HEADER_SIZE), $header['type']), $retObj);
                    $success_num++;
                }
            }
            //发生超时
            if ((microtime(true) - $st) > $timeout)
            {
                foreach ($this->waitList as $obj)
                {
                    $obj->code = ($obj->socket->isConnected()) ? RPC_Result::ERR_TIMEOUT : RPC_Result::ERR_CONNECT;
                    //执行after钩子函数
                    $this->afterRequest($obj);
                }
                //清空当前列表
                $this->waitList = array();
                return $success_num;
            }
        }

        //未发生任何超时
        $this->waitList = array();
        $this->requestIndex = 0;
        return $success_num;
    }
}
