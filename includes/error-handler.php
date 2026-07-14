<?php
/**
 * EasyRide - Global error & exception handling
 *
 * Production previously ran with error_reporting(0) and no handlers, so any
 * uncaught exception produced a blank white page with no log trail. This file
 * guarantees: every uncaught exception/fatal is logged with a request id, and
 * the user sees a friendly 500 page (or JSON for AJAX) carrying that id so
 * reports can be matched to log lines.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

define('ERROR_REQUEST_ID', bin2hex(random_bytes(6)));

/**
 * Log a message tagged with the current request id.
 */
function logAppError(string $message): void {
    error_log('[EasyRide][' . ERROR_REQUEST_ID . '] ' . $message);
}

/**
 * Render a safe 500 response. Details are shown only in DEBUG_MODE.
 */
function renderErrorResponse(?Throwable $e = null): void {
    if (PHP_SAPI === 'cli') {
        return; // CLI callers read the log / stderr
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    $wantsJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success'    => false,
            'error'      => 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε ξανά.',
            'request_id' => ERROR_REQUEST_ID,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $detail = '';
    if (defined('DEBUG_MODE') && DEBUG_MODE && $e !== null) {
        $detail = '<pre style="text-align:left;background:#f8f9fa;padding:1rem;border-radius:6px;overflow:auto">'
            . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8')
            . '</pre>';
    }

    echo '<!DOCTYPE html><html lang="el"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Σφάλμα Συστήματος</title></head>'
        . '<body style="font-family:Arial,sans-serif;background:#f4f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
        . '<div style="text-align:center;max-width:560px;padding:2rem">'
        . '<h1 style="color:#2c3e50">Κάτι πήγε στραβά</h1>'
        . '<p style="color:#555">Παρουσιάστηκε απρόσμενο σφάλμα. Η ομάδα διαχείρισης έχει ενημερωθεί.</p>'
        . '<p style="color:#999;font-size:13px">Κωδικός αναφοράς: <code>' . ERROR_REQUEST_ID . '</code></p>'
        . $detail
        . '<p><a href="dashboard.php" style="color:#3498db">Επιστροφή στην αρχική</a></p>'
        . '</div></body></html>';
}

set_exception_handler(function (Throwable $e) {
    logAppError(sprintf(
        'Uncaught %s: %s in %s:%d%s',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        "\nStack trace:\n" . $e->getTraceAsString()
    ));
    renderErrorResponse($e);
    exit(1);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        logAppError(sprintf('Fatal: %s in %s:%d', $err['message'], $err['file'], $err['line']));
        // Only render if nothing has been flushed yet — otherwise we would
        // append a second document to a half-rendered page.
        if (!headers_sent()) {
            renderErrorResponse();
        }
    }
});
