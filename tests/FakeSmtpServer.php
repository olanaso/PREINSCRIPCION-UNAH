<?php

$server = stream_socket_server('tcp://127.0.0.1:2526', $errorNumber, $errorMessage);
if (!$server) exit(1);
$client = stream_socket_accept($server, 10);
if (!$client) exit(2);

fwrite($client, "220 localhost test SMTP\r\n");
$dataMode = false;
$message = '';
while (($line = fgets($client)) !== false) {
    $command = rtrim($line, "\r\n");
    if ($dataMode) {
        if ($command === '.') {
            file_put_contents(sys_get_temp_dir() . '/unah-smtp-capture.eml', $message);
            fwrite($client, "250 queued\r\n");
            $dataMode = false;
        } else {
            $message .= $line;
        }
        continue;
    }
    if (stripos($command, 'EHLO ') === 0 || stripos($command, 'HELO ') === 0) {
        fwrite($client, "250-localhost\r\n250 SIZE 10485760\r\n");
    } elseif (stripos($command, 'MAIL FROM:') === 0 || stripos($command, 'RCPT TO:') === 0) {
        fwrite($client, "250 OK\r\n");
    } elseif ($command === 'DATA') {
        fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
        $dataMode = true;
    } elseif ($command === 'QUIT') {
        fwrite($client, "221 Bye\r\n");
        break;
    } else {
        fwrite($client, "500 Unsupported\r\n");
    }
}
fclose($client);
fclose($server);
