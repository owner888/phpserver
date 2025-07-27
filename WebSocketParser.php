<?php
class WebSocketParser
{
    const OPCODE_CONTINUATION = 0x0;
    const OPCODE_TEXT = 0x1;
    const OPCODE_BINARY = 0x2;
    const OPCODE_CLOSE = 0x8;
    const OPCODE_PING = 0x9;
    const OPCODE_PONG = 0xA;
    
    /**
     * 生成WebSocket握手响应
     * @param array $request HTTP请求数据
     * @return string|false 握手响应或失败返回false
     */
    public static function generateHandshakeResponse(array $request)
    {
        if (!isset($request['server']['HTTP_SEC_WEBSOCKET_KEY'])) {
            return false;
        }
        
        $secKey = $request['server']['HTTP_SEC_WEBSOCKET_KEY'];
        $secAccept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        
        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: $secAccept\r\n";
        if (isset($request['server']['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
            $response .= "Sec-WebSocket-Protocol: " . $request['server']['HTTP_SEC_WEBSOCKET_PROTOCOL'] . "\r\n";
        }
        $response .= "\r\n";
        
        return $response;
    }
    
    /**
     * 判断请求是否为WebSocket握手请求
     * @param array $request HTTP请求数据
     * @return bool
     */
    public static function isWebSocketHandshake(array $request)
    {
        return isset($request['server']['HTTP_UPGRADE']) && 
               strtolower($request['server']['HTTP_UPGRADE']) === 'websocket' &&
               isset($request['server']['HTTP_CONNECTION']) && 
               stripos($request['server']['HTTP_CONNECTION'], 'upgrade') !== false &&
               isset($request['server']['HTTP_SEC_WEBSOCKET_KEY']);
    }
    
    /**
     * 解码WebSocket数据帧
     * @param string $data 二进制数据
     * @return array|false 解析后的数据帧，失败返回false
     */
    public static function decode($data)
    {
        if (strlen($data) < 2) {
            return false;
        }
        
        $result = [];
        $bytes = $data;
        $dataLength = strlen($bytes);
        $masks = [];
        
        $result['FIN'] = (ord($bytes[0]) & 0x80) >> 7;
        $result['RSV1'] = (ord($bytes[0]) & 0x40) >> 6;
        $result['RSV2'] = (ord($bytes[0]) & 0x20) >> 5;
        $result['RSV3'] = (ord($bytes[0]) & 0x10) >> 4;
        $result['opcode'] = ord($bytes[0]) & 0x0F;
        
        // 是否有掩码
        $result['mask'] = (ord($bytes[1]) & 0x80) >> 7;
        
        // 数据长度
        $payloadLen = ord($bytes[1]) & 0x7F;
        $headerSize = 2;
        
        if ($payloadLen === 126) {
            if ($dataLength < 4) {
                return false;
            }
            $payloadLen = unpack('n', substr($bytes, 2, 2))[1];
            $headerSize += 2;
        } elseif ($payloadLen === 127) {
            if ($dataLength < 10) {
                return false;
            }
            $payloadLen = unpack('J', substr($bytes, 2, 8))[1];
            $headerSize += 8;
        }
        
        // 读取掩码
        if ($result['mask']) {
            if ($dataLength < $headerSize + 4) {
                return false;
            }
            $masks = substr($bytes, $headerSize, 4);
            $headerSize += 4;
        }
        
        // 读取数据
        $dataStart = $headerSize;
        if ($dataStart + $payloadLen > $dataLength) {
            return false;
        }
        
        $data = substr($bytes, $dataStart, $payloadLen);
        
        // 解码数据
        if ($result['mask']) {
            $decoded = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $decoded .= $data[$i] ^ $masks[$i % 4];
            }
            $data = $decoded;
        }
        
        $result['payload'] = $data;
        $result['length'] = $headerSize + $payloadLen;
        
        var_dump($result);
        return $result;
    }
    
    /**
     * 编码数据为WebSocket数据帧
     * @param string $data 要发送的数据
     * @param int $opcode 操作码，默认为文本
     * @param bool $masked 是否掩码，客户端发送需要掩码，服务器不需要
     * @return string 二进制数据帧
     */
    public static function encode($data, $opcode = self::OPCODE_TEXT, $masked = false)
    {
        $length = strlen($data);
        $head = chr(0x80 | $opcode); // FIN为1，表示是消息的最后一个帧
        
        if ($length < 126) {
            $head .= chr($masked ? 0x80 | $length : $length);
        } elseif ($length < 65536) {
            $head .= chr($masked ? 0x80 | 126 : 126);
            $head .= pack('n', $length);
        } else {
            $head .= chr($masked ? 0x80 | 127 : 127);
            $head .= pack('J', $length);
        }
        
        if ($masked) {
            $mask = random_bytes(4);
            $head .= $mask;
            
            $encoded = '';
            for ($i = 0; $i < $length; $i++) {
                $encoded .= $data[$i] ^ $mask[$i % 4];
            }
            
            return $head . $encoded;
        }
        
        return $head . $data;
    }
    
    /**
     * 创建关闭帧
     * @param int $code 关闭代码
     * @param string $reason 关闭原因
     * @return string 二进制数据
     */
    public static function close($code = 1000, $reason = '')
    {
        $data = pack('n', $code) . $reason;
        return self::encode($data, self::OPCODE_CLOSE);
    }
    
    /**
     * 创建Ping帧
     * @param string $data 可选数据
     * @return string 二进制数据
     */
    public static function ping($data = '')
    {
        return self::encode($data, self::OPCODE_PING);
    }
    
    /**
     * 创建Pong帧
     * @param string $data 可选数据，通常与收到的Ping中的数据相同
     * @return string 二进制数据
     */
    public static function pong($data = '')
    {
        return self::encode($data, self::OPCODE_PONG);
    }
}