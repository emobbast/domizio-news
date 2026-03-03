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
