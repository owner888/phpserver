<?php
class HttpParser
{
    public function parse($content)
    {
        $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = $_FILES = [];
        $_SERVER = [
            'QUERY_STRING'         => '',
            'REQUEST_METHOD'       => '',
            'REQUEST_URI'          => '',
            'SERVER_PROTOCOL'      => '',
            'SERVER_NAME'          => '',
            'HTTP_HOST'            => '',
            'HTTP_USER_AGENT'      => '',
            'HTTP_ACCEPT'          => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_ACCEPT_ENCODING' => '',
            'HTTP_COOKIE'          => '',
            'HTTP_CONNECTION'      => '',
            'REMOTE_ADDR'          => '',
            'REMOTE_PORT'          => '0',
            'REQUEST_TIME'         => time()
        ];

        // 解析头部
        $parts = explode("\r\n\r\n", $content, 2);
        $http_header = $parts[0] ?? '';
        $http_body = $parts[1] ?? '';
        $header_data = explode("\r\n", $http_header);

        // 请求行解析
        if (isset($header_data[0])) 
	{
            $requestLine = explode(' ', $header_data[0], 3);
            $_SERVER['REQUEST_METHOD']  = $requestLine[0] ?? '';
            $_SERVER['REQUEST_URI']     = $requestLine[1] ?? '';
            $_SERVER['SERVER_PROTOCOL'] = $requestLine[2] ?? '';
        }
        unset($header_data[0]);

        // 头部解析
        foreach ($header_data as $line) 
        {
            if (empty($line) || strpos($line, ':') === false) continue;
            list($key, $value) = explode(':', $line, 2);
            $key = str_replace('-', '_', strtoupper(trim($key)));
            $value = trim($value);
            $_SERVER['HTTP_' . $key] = $value;
            if ($key === 'COOKIE') 
            {
                parse_str(str_replace('; ', '&', $value), $_COOKIE);
            }
        }

        // 查询字符串
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) 
        {
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        } 
        else 
        {
            $_SERVER['QUERY_STRING'] = '';
        }

        // 处理 POST/PUT 数据
        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) 
        {
            // 支持 application/x-www-form-urlencoded
            if (isset($_SERVER['HTTP_CONTENT_TYPE']) && 
                stripos($_SERVER['HTTP_CONTENT_TYPE'], 'application/x-www-form-urlencoded') !== false) 
            {
                parse_str($http_body, $_POST);
            }
            // 支持 application/json
            elseif (isset($_SERVER['HTTP_CONTENT_TYPE']) &&
                stripos($_SERVER['HTTP_CONTENT_TYPE'], 'application/json') !== false) {
                $_POST = json_decode($http_body, true) ?: [];
            }
            // 支持 multipart/form-data
            // 文件内容保存在 $_FILES[$field]['content']，你可自行保存到磁盘
            // 仅支持单文件和简单字段，复杂嵌套表单需进一步扩展
            // 解析 multipart/form-data 时不会自动生成临时文件，需自行处理
            elseif (isset($_SERVER['HTTP_CONTENT_TYPE']) && 
                stripos($_SERVER['HTTP_CONTENT_TYPE'], 'multipart/form-data') !== false) {
                // 提取 boundary
                if (preg_match('/boundary=(.*)$/', $_SERVER['HTTP_CONTENT_TYPE'], $matches)) {
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

        // 对 $_GET/$_POST/$_COOKIE 进行简单过滤
        array_walk_recursive($_GET, function(&$v) { $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); });
        array_walk_recursive($_POST, function(&$v) { $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); });
        array_walk_recursive($_COOKIE, function(&$v) { $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); });

        $_REQUEST = array_merge($_GET, $_POST);

        return [
            'get'    => $_GET,
            'post'   => $_POST,
            'cookie' => $_COOKIE,
            'server' => $_SERVER,
            'files'  => $_FILES,
            'body'   => $http_body
        ];
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