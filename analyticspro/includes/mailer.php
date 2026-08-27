<?php

declare(strict_types=1);

function analyticspro_smtp_settings(): array
{
    return [
        'host' => analyticspro_system_config('smtp_host', analyticspro_env('SMTP_HOST', '')),
        'port' => analyticspro_system_config('smtp_port', analyticspro_env('SMTP_PORT', '587')),
        'user' => analyticspro_system_config('smtp_user', analyticspro_env('SMTP_USER', '')),
        'pass' => analyticspro_system_config('smtp_pass', analyticspro_env('SMTP_PASS', '')),
        'security' => analyticspro_system_config('smtp_security', analyticspro_env('SMTP_SECURITY', 'tls')),
        'from_email' => analyticspro_system_config('smtp_from_email', analyticspro_env('SMTP_FROM_EMAIL', '')),
        'from_name' => analyticspro_system_config('smtp_from_name', analyticspro_env('SMTP_FROM_NAME', 'AnalyticsPRO')),
    ];
}

function analyticspro_test_smtp_connection(): array
{
    $settings = analyticspro_smtp_settings();
    if ($settings['host'] === '') {
        return ['ok' => false, 'message' => 'Host SMTP non configurato.'];
    }

    $transportHost = $settings['security'] === 'ssl' ? 'ssl://' . $settings['host'] : $settings['host'];
    $stream = @fsockopen($transportHost, (int) $settings['port'], $errno, $errstr, 10);
    if (!$stream) {
        return ['ok' => false, 'message' => sprintf('Connessione fallita: %s (%s)', $errstr, $errno)];
    }

    stream_set_timeout($stream, 10);
    $banner = analyticspro_smtp_read($stream);
    analyticspro_smtp_expect($banner, [220], 'Banner SMTP non valido.');
    fwrite($stream, "EHLO analyticspro\r\n");
    $response = analyticspro_smtp_read($stream);
    analyticspro_smtp_expect($response, [250], 'EHLO non accettato dal server SMTP.');

    if ($settings['security'] === 'tls') {
        fwrite($stream, "STARTTLS\r\n");
        $startTls = analyticspro_smtp_read($stream);
        analyticspro_smtp_expect($startTls, [220], 'STARTTLS non accettato dal server SMTP.');
        @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        fwrite($stream, "EHLO analyticspro\r\n");
        $response = analyticspro_smtp_read($stream);
        analyticspro_smtp_expect($response, [250], 'EHLO dopo STARTTLS non accettato.');
    }

    fwrite($stream, "QUIT\r\n");
    fclose($stream);

    return ['ok' => true, 'message' => 'Connessione SMTP riuscita.'];
}

function analyticspro_send_email(string $to, string $subject, string $html, ?string $text = null): bool
{
    $settings = analyticspro_smtp_settings();
    if ($settings['host'] === '' || $settings['from_email'] === '') {
        return false;
    }

    $boundary = 'analyticspro-' . bin2hex(random_bytes(8));
    $plainText = $text ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
    $message = implode("\r\n", [
        sprintf('From: %s <%s>', $settings['from_name'], $settings['from_email']),
        sprintf('To: <%s>', $to),
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        '',
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $plainText,
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $html,
        '--' . $boundary . '--',
    ]);

    try {
        $transportHost = $settings['security'] === 'ssl' ? 'ssl://' . $settings['host'] : $settings['host'];
        $stream = @fsockopen($transportHost, (int) $settings['port'], $errno, $errstr, 10);
        if (!$stream) {
            return false;
        }

        stream_set_timeout($stream, 10);
        analyticspro_smtp_expect(analyticspro_smtp_read($stream), [220], 'Banner SMTP non valido.');
        fwrite($stream, "EHLO analyticspro\r\n");
        analyticspro_smtp_expect(analyticspro_smtp_read($stream), [250], 'EHLO non accettato.');

        if ($settings['security'] === 'tls') {
            fwrite($stream, "STARTTLS\r\n");
            analyticspro_smtp_expect(analyticspro_smtp_read($stream), [220], 'STARTTLS non accettato.');
            if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Handshake TLS SMTP fallito.');
            }
            fwrite($stream, "EHLO analyticspro\r\n");
            analyticspro_smtp_expect(analyticspro_smtp_read($stream), [250], 'EHLO dopo STARTTLS non accettato.');
        }

        if ($settings['user'] !== '') {
            fwrite($stream, "AUTH LOGIN\r\n");
            analyticspro_smtp_expect(analyticspro_smtp_read($stream), [334], 'AUTH LOGIN non accettato.');
            fwrite($stream, base64_encode($settings['user']) . "\r\n");
            analyticspro_smtp_expect(analyticspro_smtp_read($stream), [334], 'Username SMTP non accettato.');
            fwrite($stream, base64_encode($settings['pass']) . "\r\n");
            analyticspro_smtp_expect(analyticspro_smtp_read($stream), [235], 'Password SMTP non accettata.');
        }

        fwrite($stream, sprintf("MAIL FROM:<%s>\r\n", $settings['from_email']));
        analyticspro_smtp_expect(analyticspro_smtp_read($stream), [250], 'MAIL FROM rifiutato.');
        fwrite($stream, sprintf("RCPT TO:<%s>\r\n", $to));
        analyticspro_smtp_expect(analyticspro_smtp_read($stream), [250, 251], 'RCPT TO rifiutato.');
        fwrite($stream, "DATA\r\n");
        analyticspro_smtp_expect(analyticspro_smtp_read($stream), [354], 'DATA non accettato.');
        fwrite($stream, $message . "\r\n.\r\n");
        analyticspro_smtp_expect(analyticspro_smtp_read($stream), [250], 'Invio SMTP fallito.');
        fwrite($stream, "QUIT\r\n");
        fclose($stream);
        return true;
    } catch (Throwable $exception) {
        if (isset($stream) && is_resource($stream)) {
            fwrite($stream, "QUIT\r\n");
            fclose($stream);
        }
        return false;
    }
}

function analyticspro_smtp_read($stream): string
{
    $data = '';
    while (($line = fgets($stream)) !== false) {
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    return $data;
}

function analyticspro_smtp_expect(string $response, array $codes, string $message): void
{
    $statusCode = (int) substr(trim($response), 0, 3);
    if (!in_array($statusCode, $codes, true)) {
        throw new RuntimeException($message . ' Risposta: ' . trim($response));
    }
}
