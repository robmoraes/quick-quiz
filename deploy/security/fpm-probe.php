<?php
// Test-only CLI client. Never copied into images or served through HTTP.
$path = '/tmp/security-probe-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($path, '<?php echo json_encode(['
    .'"php"=>PHP_VERSION,"upload_progress"=>ini_get("session.upload_progress.enabled"),'
    .'"memory_limit"=>ini_get("memory_limit"),"post_max_size"=>ini_get("post_max_size"),'
    .'"upload_max_filesize"=>ini_get("upload_max_filesize")]);');

function record(int $type, string $body): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
}

function readExact($socket, int $length): string
{
    $value = '';
    while (strlen($value) < $length) {
        $chunk = fread($socket, $length - strlen($value));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete FastCGI response.');
        }
        $value .= $chunk;
    }
    return $value;
}

try {
    $socket = stream_socket_client('tcp://127.0.0.1:9000', $code, $error, 10);
    if ($socket === false) {
        throw new RuntimeException('Test FPM socket unavailable.');
    }
    stream_set_timeout($socket, 10);
    $params = '';
    foreach (['SCRIPT_FILENAME' => $path, 'SCRIPT_NAME' => '/security-probe.php',
        'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1',
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'REDIRECT_STATUS' => '200'] as $name => $value) {
        $params .= chr(strlen($name)).chr(strlen($value)).$name.$value;
    }
    fwrite($socket, record(1, pack('nCxxxxx', 1, 0)).record(4, $params).record(4, '').record(5, ''));
    $stdout = '';
    do {
        $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', readExact($socket, 8));
        $body = $header['length'] ? readExact($socket, $header['length']) : '';
        if ($header['padding']) {
            readExact($socket, $header['padding']);
        }
        if ($header['type'] === 6) {
            $stdout .= $body;
        }
    } while ($header['type'] !== 3);
    fclose($socket);
    $parts = explode("\r\n\r\n", $stdout, 2);
    echo json_encode(json_decode($parts[1] ?? '', true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
} finally {
    unlink($path);
}
