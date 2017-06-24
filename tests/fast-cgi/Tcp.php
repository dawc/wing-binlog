<?php

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/3/13
 * Time: 07:46
 *
 * php tcp class
 * support libevent and select mode
 *
 */
class Tcp
{
    protected $socket;
    protected $clients = [];
    protected $buffers = [];

    const ON_RECEIVE = "on_read";
    const ON_WRITE   = "on_write";
    const ON_CONNECT = "on_connect";
    const ON_ERROR   = "on_error";
    const ON_CLOSE   = "on_close";

    protected $callbacks = [];

    protected $ip;
    protected $port;


    /**
     * construct
     *
     * @param string $ip
     * @param int $port
     */
    public function __construct($ip = "0.0.0.0", $port = 9998)
    {
        $this->ip         = $ip;
        $this->port       = $port;
        $this->start_time = time();

        $context      = stream_context_create(["socket" => ["so_reuseport" => 1]]);
        $this->socket = stream_socket_server(
            'tcp://' . $this->ip . ':' . $this->port,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->socket) {
            die("create socket error => ip:" . $this->ip . " port:" . $this->port."\r\n");
        }

        stream_set_blocking($this->socket, 0);
    }

    /**
     * on receive data, this will be call
     *
     * @param resource $client
     * @param resource $buffer
     * @params string $data
     */
    protected function onReceive($client, $buffer, $data)
    {
        $this->call(self::ON_RECEIVE,[$client, $buffer, $data]);
    }

    /**
     * this func will be call on send
     *
     * @param resource $client
     * @param resource $buffer 是event_buffer_new的返回值
     */
    protected function onWrite($buffer, $client = null)
    {
        $this->call(self::ON_WRITE,[$client, $buffer]);
    }

    /**
     * this func will be call on connect
     *
     * @param resource $client
     * @param resource $buffer
     */
    protected function onConnect($client, $buffer)
    {
        $this->call(self::ON_CONNECT, [$client, $buffer]);
    }

    /**
     * this func will be call on close
     *
     * @param resource $client
     * @param resource $buffer
     */
    protected function onClose($client, $buffer)
    {
        $this->call(self::ON_CLOSE,[$client, $buffer]);
    }

    /**
     * this func will be call on error happened
     *
     * @param resource $client
     * @param resource $buffer
     */
    protected function onError($client, $buffer, $error)
    {
        $this->call(self::ON_ERROR, [$client, $buffer, $error]);
    }


    /**
     * start tcp server
     */
    public function start()
    {
        //if libevent support, then use it
		$base  = event_base_new();
		$event = event_new();

		event_set($event, $this->socket, EV_READ | EV_PERSIST, [$this, 'accept'], $base);
		event_base_set($event, $base);
		event_add($event);
		event_base_loop($base);
    }

    /**
     * accept, only libevent
     *
     * @param resource $socket
     * @param mixed $flag
     * @param resource $base
     * @return bool
     */
    protected function accept($socket, $flag, $base)
    {
        try {
            if (!$socket) {
                return false;
            }
            $connection = @stream_socket_accept($socket);
            if (!$connection) {
                return false;
            }
            stream_set_blocking($connection, 0);

            $buffer = event_buffer_new($connection, [$this, 'read'], [$this, 'onWrite'], [$this, 'error'], $connection);
            if (!$buffer && !is_resource($buffer)) {
                return false;
            }
            event_buffer_base_set($buffer, $base);
            event_buffer_timeout_set($buffer, 30, 30);
            event_buffer_watermark_set($buffer, EV_READ, 0, 0xffffff);
            event_buffer_priority_set($buffer, 10);
            event_buffer_enable($buffer, EV_READ | EV_PERSIST);

            $this->clients[] = $connection;
            $this->buffers[] = $buffer;

            $this->onConnect($connection, $buffer);
        } catch(\Exception $e) {
            return false;
        }
        return true;
    }


    /**
     * send data to a client socket
     *
     * @param resource $socket
     * @param string $data
     *
     * @return int
     */
    public function sendSocket($socket, $data)
    {
        $byte = 0;
        try {
            $byte = fwrite($socket, $data);
        } catch(\Exception $e) {
            var_dump($e);
        }
        $this->onWrite(null, $socket);
        return $byte;
    }

    /**
     * bind event callback
     *
     * @param string $event
     * @param \Closure|array $callback
     */
    public function on($event, $callback)
    {
        if (!isset($this->callbacks[$event]))
            $this->callbacks[$event] = [];
        $this->callbacks[$event][] = $callback;
    }

    /**
     * call event callback
     *
     * @param string $event
     * @param array $params
     */
    protected function call($event, array $params = [])
    {
        if (!isset($this->callbacks[$event])) {
            return;
        }

        if (!is_array($this->callbacks[$event])) {
            return;
        }

        if (count($this->callbacks[$event]) <= 0) {
            return;
        }

        foreach ($this->callbacks[$event] as $callback) {
            if (is_callable($callback)) {
                call_user_func_array($callback,$params);
            }
        }
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        fclose($this->socket);
    }

    /**
     * error happened,only libevent
     *
     * @param resource $buffer
     * @param string $error
     * @param array $params
     */
    protected function error($buffer, $error)
    {
        event_buffer_disable($buffer, EV_READ | EV_WRITE);
        event_buffer_free($buffer);

        $i = array_search($buffer, $this->buffers);
        $this->onError($this->clients[$i], $buffer, $error);
        fclose($this->clients[$i]);
        unset($this->clients[$i], $this->buffers[$i]);
    }


//    private function parse($data, $buffer){
//        $headerFormat = 'Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/x';
//
//        $record       = unpack($headerFormat, substr($data,0,8));
//        var_dump($record);
//
//        if ($record["type"] == 1)
//            return;
//
//        $content_len  = $record["contentLength"];
//        $start        = 8;
//
//        $back = "";
//        while ($start < $content_len)
//        {
//            $f = substr($data, $start, 1);
//
//            $flag = substr(sprintf("%08b",(ord($f))),0,1);
//            if ($flag == "0") {
//                $name_len = unpack("C", substr($data, $start, 1))[1];
//                $start += 1;
//            } else {
//                $temp  = unpack("C4", substr($data, $start, 4));
//                $B3 = $temp[1];
//                $B2 = $temp[2];
//                $B1 = $temp[3];
//                $B0 = $temp[4];
//                $name_len = (($B3 & 0x7f) << 24) + ($B2 << 16) + ($B1 << 8) + $B0;
//                $start +=4;
//            }
//
//            echo $name_len,"--->";
//
//            $key = substr($data, $start+1, $name_len);
//
//            // echo $key,"\r\n";
//            $f    = substr($data, $start, 1);
//            $flag = substr(sprintf("%08b",(ord($f))),0,1);
//            if ($flag == "0") {
//                //if (!$f) {
//                $value_len = unpack("C", substr($data, $start, 1))[1];
//                $start += 1;
//            } else {
//                $temp  = unpack("C4", substr($data, $start, 4));
//                $B3 = $temp[1];
//                $B2 = $temp[2];
//                $B1 = $temp[3];
//                $B0 = $temp[4];
//                $value_len = (($B3 & 0x7f) << 24) + ($B2 << 16) + ($B1 << 8) + $B0;
//                $start +=4;
//            }
//            echo $value_len,"\r\n";
//
//            $start += $name_len;
//            $value = substr($data, $start, $value_len);
//            $start += $value_len;
//
//            echo $key ,"===>" , $value,"\r\n";
//
//            $back .= $key."====>".$value."<br/>";
//            //exit;
//        }
//
//
//
//
//        //var_dump($record);
//        //var_dump(readNvpair(substr($data,9,strlen($data)-8)));
//
//
//
//        $headerData1 = "Status: 200 OK\r\nContent-Type: text/html\r\nContent-Length:".strlen($back)."\r\n\r\n".$back;
//
//        //$this->writeResponse($requestId, $headerData, $response->getBody());
//        $resquestid = $record["requestId"];
//        //event_buffer_write($buffer, "hello");// "HTTP/1.1 200 OK\n\nContent-type: text/html\n\nContent-length:5\n\n\n\nhello");
//
//
//        $contentLength = strlen($headerData1);
//        $headerData    = pack('CCnnxx', FCGI_VERSION_1, FCGI_STDOUT, $resquestid, $contentLength);
//
//        echo "发送响应数据\r\n";
//        echo $headerData.$headerData1,"\r\n\r\n";
//        event_buffer_write($buffer, $headerData.$headerData1);
//    }

    /**
     * libevent read wait
     *
     * @param resource $buffer
     */
    protected function read($buffer)
    {
        $i = array_search($buffer, $this->buffers);


        $headerFormat = 'Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/x';

        //第一次 取24字节
        //fastcgi的header占8个字节 填充8个字节 然后是16字节
        //取24字节是为了拿到第一个包的header


        while ($read = event_buffer_read($buffer, 10240)) {
			$this->onReceive($this->clients[$i], $buffer, $read);
//            $record      = unpack($headerFormat, $read);
//
//            $content_len = $left_read = $record["contentLength"];
//            echo "contentlen =================>",$content_len,"\r\n";
//
//            $content     = $read;
//            while ($read = event_buffer_read($buffer, $left_read)) {
//                $content  .= $read;
//                $left_read = $content_len - strlen($content);
//            }
//
//            var_dump($content);
//            $this->parse($content, $buffer);
        }

		$this->onClose($this->clients[$i], $buffer);
    }
}