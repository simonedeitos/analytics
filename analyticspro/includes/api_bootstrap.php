<?php

declare(strict_types=1);

/**
 * Call this as the first statement (after require bootstrap.php) in every API
 * endpoint.  It:
 *  1. Sends the JSON Content-Type header immediately so that even a fatal PHP
 *     error that short-circuits the normal response still has the right MIME
 *     type (browsers/fetch won't receive an HTML page).
 *  2. Registers a shutdown handler that converts any uncatchable fatal error
 *     (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR) into a valid JSON
 *     error response with HTTP 500 instead of the default HTML error page.
 */
function analyticspro_api_guard(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (
            $error !== null &&
            in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(
                ['ok' => false, 'error' => 'Errore interno del server: ' . $error['message']],
                JSON_UNESCAPED_UNICODE
            );
        }
    });
}
