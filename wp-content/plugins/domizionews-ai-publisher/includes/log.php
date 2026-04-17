<?php
if (!defined('ABSPATH')) exit;

/**
 * Aggiunge una riga al log interno del plugin.
 * Mantiene solo le ultime DNAP_LOG_MAX righe.
 */
function dnap_log($message) {
    $log   = get_option(DNAP_LOG_KEY, []);
    $log[] = [
        'time' => current_time('mysql'),
        'msg'  => $message,
    ];

    // Taglia le righe più vecchie
    if (count($log) > DNAP_LOG_MAX) {
        $log = array_slice($log, -DNAP_LOG_MAX);
    }

    update_option(DNAP_LOG_KEY, $log, false);

    // Log anche su file per permettere tail -f da SSH
    $log_file = WP_CONTENT_DIR . '/uploads/dnap.log';
    $line     = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    // Cap a ~500KB: mantieni solo le ultime 200 righe
    if (file_exists($log_file) && filesize($log_file) > 500 * 1024) {
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            $lines = array_slice($lines, -200);
            file_put_contents($log_file, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
        }
    }

    file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Resetta il log.
 */
function dnap_clear_log() {
    delete_option(DNAP_LOG_KEY);
}

/**
 * Restituisce tutte le righe del log (dalla più recente).
 */
function dnap_get_log() {
    $log = get_option(DNAP_LOG_KEY, []);
    return array_reverse($log);
}
