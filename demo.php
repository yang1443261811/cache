<?php
require_once './Utils.php';
require_once './Event/Select.php';
require_once './Event/CustomEvent.php';
require_once './Event/EventInterface.php';


class WebSocket
{
    /**
     * 监听的地址
     *
     * @var string
     */
    private $host;

    /**
     * 监听的端口号
     *
     * @var int
     */
    private $port;

    /**
     * socket链接池
     *
     * @var array
     */
    protected $sockets = [];

    /**
     * socket 主服务
     *
     * @var
     */
    protected $master;

    /**
     * 接入的用户表
     *
     * @var array
     */
    protected $users = [];

    /**
     * 事件对象
     *
     * @var object
     */
    public static $globalEvent;

    public $onConnect;

    public $onMessage;

    /**
     * Server constructor.
     * @param string $host
     * @param int $port
     */
    public function __construct($host = '127.0.0.1', $port = 9999)
    {
        $this->port = $port;
        $this->host = $host;
        static::$globalEvent = $this->getEventPollClass('event');
    }

    public function run()
    {
        //创建socket
        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        //返回bool.套接字resource,协议级别,可用的socket选项,值。
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);

        //将socket绑定到指定的IP:port上
        socket_bind($this->master, $this->host, $this->port);

        //开始监听
        socket_listen($this->master);

        //将服务器设置为非阻塞
        socket_set_nonblock($this->master);

        static::$globalEvent->add($this->master, [$this, 'connect'], EventInterface::EVENT_TYPE_READ);

        static::$globalEvent->loop();
    }

    public function getEventPollClass($type = null)
    {
        if ($type == 'event') {
            $instance = new CustomEvent();
        } else {
            $instance = new Select();
        }

        return $instance;
    }

    /**
     * 将socket加入链接池
     *
     * @param object $socket
     */
    public function connect($socket)
    {
        //接收一个链接
        $connection = socket_accept($socket);
        if (!$connection) {
            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);
            $this->error(['error_init_server', $err_code, $err_msg]);
            return;
        }

        // 将连接socket也设置为非阻塞模式
        socket_set_nonblock($connection);
        //获取连接ID
        $connect_id = $this->getClientId($connection);

        $this->sockets[$connect_id] = [
            'handshake' => 0,
            'resource'  => $connection,
        ];
        //添加事件处理
        static::$globalEvent->add($connection, [$this, 'reader'], EventInterface::EVENT_TYPE_READ);
        if (is_callable($this->onConnect)) {
            call_user_func($this->onConnect, $connection);
        }
    }

    /**
     * 读取客户端发来的数据
     *
     * @param $connect
     */
    public function reader($connect)
    {
        $bytes = @socket_recv($connect, $buffer, 2048, 0);
        if (!$bytes) {
            $this->close($connect);
            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);
            $this->error(['error_init_server', $err_code, $err_msg]);
            return;
        }

        $connectId = $this->getClientId($connect);

        if ($this->sockets[$connectId]['handshake'] == 1) {
            $data = Utils::decode($buffer);
            $content = Utils::encode(json_encode($data));
            socket_write($connect, $content, strlen($content));
            //执行事件回调
            if (is_callable($this->onMessage)) {
                call_user_func($this->onMessage, $data);
            }
        } else {
            $this->handshake($connect, $buffer);
            $this->sockets[$connectId]['handshake'] = 1;
        }
    }

    /**
     * 关闭链接
     *
     * @param $connect
     * @param $connectId
     */
    public function close($connect)
    {
        socket_close($connect);
//        unset($this->sockets[$connectId]);
        static::$globalEvent->del($connect);
    }

    /**
     * 获取客户端ID
     *
     * @param object $socket
     * @return string
     */
    public function getClientId($socket)
    {
        socket_getpeername($socket, $ip, $port);
        $connect_id = (int)$socket;
        $client_id = Utils::addressToClientId($ip, $port, $connect_id);

        return $client_id;
    }

    /**
     * 回应握手
     *
     * @param object $socket
     * @param string $buffer
     * @return bool
     */
    protected function handshake($socket, $buffer)
    {
        //对接收到的buffer处理,并回馈握手！！
        $buf = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);
        $key = trim(substr($buf, 0, strpos($buf, "\r\n")));
        $hash = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Sec-WebSocket-Version: 13\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: " . $hash . "\r\n\r\n";
        //回馈握手
        socket_write($socket, $response, strlen($response));
        //向客户端发送握手成功消息
        $msg = Utils::encode(json_encode([
            'content' => 'done',
            'type'    => 'handshake',
        ]));
        socket_write($socket, $msg, strlen($msg));

        return true;
    }

    /**
     * 记录debug信息
     *
     * @param array $info
     */
    private function error(array $info)
    {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);
        $info = array_map('json_encode', $info);
        file_put_contents('./websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }

}

$webSocket = new WebSocket();
$webSocket->run();