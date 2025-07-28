<?php
class HttpParser
{
    /**
     * HTTP 响应编码，支持自定义状态码和响应头
     * @param string $content 响应内容
     * @param int $status 状态码
     * @param array $headers 额外响应头
     * @return string
     */
    public static function encode($content, $status = 200, $headers = [])
    {
        $statusText = [
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Payload Too Large',
            500 => 'Internal Server Error'
        ];
        $header = "HTTP/1.1 {$status} " . ($statusText[$status] ?? 'OK') . "\r\n";
        $defaultHeaders = [
            "Content-Type" => "text/html;charset=utf-8",
            "Connection" => "keep-alive",
            "Server" => "workerman/3.5.4",
            "Content-Length" => strlen($content)
        ];
        $headers = array_merge($defaultHeaders, $headers);
        foreach ($headers as $k => $v) {
            $header .= "{$k}: {$v}\r\n";
        }
        $header .= "\r\n";
        return $header . $content;
    }

    /**
     * HTTP请求解析
     * @param string $content 请求内容
     * @return array 解析后的数据
     */
    public function parse($buffer, $remoteAddress = null)
    {
        $result = [
            'raw' => $buffer,
            'method' => '',
            'path' => '',
            'protocol' => '',
            'headers' => [],
            'body' => '',
            'get' => [],
            'post' => [],
            'cookies' => [],
            'server' => []  // 添加此字段存储服务器信息
        ];
    
        // 空请求
        if (empty($buffer)) {
            return $result;
        }

        // 分离请求头和请求体
        $parts = explode("\r\n\r\n", $buffer, 2);
        $http_header = $parts[0] ?? '';
        $http_body = $parts[1] ?? '';
        $result['body'] = $http_body;

        // 解析请求行
        $headerLines = explode("\r\n", $http_header);
        $requestLine = array_shift($headerLines);
        
        // 提取 method, path, protocol
        if (preg_match('/^(\S+)\s+(\S+)\s+(\S+)/', $requestLine, $matches)) {
            $result['method'] = $matches[1];
            $fullPath = $matches[2];
            $result['protocol'] = $matches[3];
            
            // 解析路径和查询参数
            $pathParts = explode('?', $fullPath, 2);
            $result['path'] = $pathParts[0];
            
            // 解析查询参数
            if (isset($pathParts[1])) {
                parse_str($pathParts[1], $result['get']);
            }
        }

        // 解析请求头
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                $result['headers'][$key] = $value;
                
                // 同时设置 SERVER 信息
                $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
                $result['server'][$serverKey] = $value;
                
                // 如果是 Cookie
                if (strtolower($key) === 'cookie') {
                    $cookies = explode(';', $value);
                    foreach ($cookies as $cookie) {
                        if (strpos($cookie, '=') !== false) {
                            list($cookieKey, $cookieValue) = explode('=', trim($cookie), 2);
                            $result['cookies'][$cookieKey] = $cookieValue;
                        }
                    }
                }
            }
        }

        // 添加其他服务器信息
        $result['server']['REQUEST_METHOD'] = $result['method'];
        $result['server']['REQUEST_URI'] = $result['path'];
        $result['server']['SERVER_PROTOCOL'] = $result['protocol'];
        $result['server']['QUERY_STRING'] = isset($pathParts[1]) ? $pathParts[1] : '';
        $result['server']['REMOTE_ADDR'] = $remoteAddress ?? '';

        // 处理 POST/PUT 数据
        if (in_array($result['method'], ['POST', 'PUT', 'PATCH'])) 
        {
            // 支持 application/x-www-form-urlencoded
            if (isset($result['headers']['Content-Type']) && 
                stripos($result['headers']['Content-Type'], 'application/x-www-form-urlencoded') !== false) 
            {
                parse_str($http_body, $_POST);
            }
            // 支持 application/json
            elseif (isset($result['headers']['Content-Type']) &&
                stripos($result['headers']['Content-Type'], 'application/json') !== false) {
                $result['post'] = json_decode($http_body, true) ?: [];
            }
            // 支持 multipart/form-data
            // 文件内容保存在 $_FILES[$field]['content']，你可自行保存到磁盘
            // 仅支持单文件和简单字段，复杂嵌套表单需进一步扩展
            // 解析 multipart/form-data 时不会自动生成临时文件，需自行处理
            elseif (isset($result['headers']['Content-Type']) && 
                stripos($result['headers']['Content-Type'], 'multipart/form-data') !== false) {
                // 提取 boundary
                if (preg_match('/boundary=(.*)$/', $result['headers']['Content-Type'], $matches)) {
                    $boundary = '--' . $matches[1];
                    $blocks = explode($boundary, $http_body);
                    array_pop($blocks); // 去掉最后一个空块
                    array_shift($blocks); // 去掉第一个空块
                    foreach ($blocks as $block) {
                        if (empty(trim($block))) continue;
                        // 解析每个字段
                        if (preg_match('/name="([^"]+)"(; filename="([^"]+)")?/i', $block, $fieldMatch)) {
                            $fieldName = $fieldMatch[1];
                            $fileName = $fieldMatch[3] ?? null;
                            if ($fileName) {
                                // 文件上传
                                if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $block, $typeMatch)) {
                                    $fileType = trim($typeMatch[1]);
                                } else {
                                    $fileType = 'application/octet-stream';
                                }
                                // 文件内容
                                $fileContent = preg_replace('/.*?\r\n\r\n/s', '', $block, 1);
                                $fileContent = substr($fileContent, 0, -2); // 去掉结尾的 \r\n
                                $this->setMultipartValue($_FILES, $fieldName, [
                                    'name' => $fileName,
                                    'type' => $fileType,
                                    'size' => strlen($fileContent),
                                    'tmp_name' => '',
                                    'error' => 0,
                                    'content' => $fileContent
                                ]);
                            } else {
                                // 普通字段
                                $value = preg_replace('/.*?\r\n\r\n/s', '', $block, 1);
                                $value = substr($value, 0, -2); // 去掉结尾的 \r\n
                                // $_POST[$fieldName] = $value;
                                $this->setMultipartValue($_POST, $fieldName, $value);
                            }
                        }
                    }
                }
            }
            // 其他类型可扩展
        }

        // // 对 $_GET/$_POST/$_COOKIE 进行简单过滤
        // array_walk_recursive($result['get'], function(&$v) { $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); });
        // array_walk_recursive($result['post'], function(&$v) { $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); });
        // array_walk_recursive($result['cookie'], function(&$v) { $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); });

        // $result['request'] = array_merge($result['get'], $result['post']);

        return $result;
    }

    private function setMultipartValue(&$target, $name, $value)
    {
        if (!is_array($target)) 
        {
            $target = [];
        }
        if ($name === '') return;
        if (is_numeric($name)) $name = (string)$name;
        if (strpos($name, '[') !== false) 
        {
            $name = preg_replace('/\]$/', '', $name);
            $parts = explode('[', $name);
            $ref = &$target;
            foreach ($parts as $part) 
            {
                if ($part === '') 
                {
                    if (!isset($ref) || !is_array($ref)) $ref = [];
                    $ref[] = [];
                    end($ref);
                    $lastKey = key($ref);
                    $ref = &$ref[$lastKey];
                } 
                else 
                {
                    if (!isset($ref[$part]) || !is_array($ref[$part])) $ref[$part] = [];
                    $ref = &$ref[$part];
                }
            }
            $ref = $value;
        }
        else 
        {
            $target[$name] = $value;
        }
    }
}