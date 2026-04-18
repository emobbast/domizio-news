<?php
if (!defined('ABSPATH')) exit;

/**
 * Buffered logger.
 *
 * Entries accumulate in a per-request static buffer and are flushed to the
 * DNAP_LOG_KEY option once at shutdown. An import of 5 articles used to
 * produce 40+ DB writes per run; this collapses it to a single write.
 *
 * The file log (wp-content/uploads/dnap.log) stays immediate — append-only
 * with LOCK_EX, very cheap — so `tail -f` still works for live debugging.
 *
 * The DNAP_LOG_MAX cap is applied at flush time on the merged list.
 */
function dnap_log($message) {
    $buffer = &dnap_log_buffer();
    $buffer[] = [
        'time' => current_time('mysql'),
        'msg'  => $message,
    ];

    static $shutdown_registered = false;
    if (!$shutdown_registered) {
        register_shutdown_function('dnap_log_flush');
        $shutdown_registered = true;
    }

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
 * Shared per-request buffer, returned by reference so both dnap_log() and
 * dnap_log_flush() operate on the same array.
 */
function &dnap_log_buffer() {
    static $buffer = [];
    return $buffer;
}

/**
 * Flush the buffered log entries to the option in a single write.
 * Registered via register_shutdown_function on first dnap_log() call.
 * Wrapped in try/catch so a DB hiccup can't abort the response.
 */
function dnap_log_flush() {
    $buffer = &dnap_log_buffer();
    if (empty($buffer)) return;

    try {
        $existing = get_option(DNAP_LOG_KEY, []);
        if (!is_array($existing)) $existing = [];
        $merged = array_merge($existing, $buffer);
        if (count($merged) > DNAP_LOG_MAX) {
            $merged = array_slice($merged, -DNAP_LOG_MAX);
        }
        update_option(DNAP_LOG_KEY, $merged, false);
    } catch (\Throwable $e) {
        // Silenzioso: il log non deve mai rompere il request.
    }

    $buffer = [];
}

/**
 * One-time migration: ensure DNAP_LOG_KEY has autoload=no. The option is
 * only read from admin views, so loading it on every request is wasteful.
 * Guarded by dnap_log_schema_version so it runs once per install.
 */
add_action('init', 'dnap_log_ensure_not_autoloaded');
function dnap_log_ensure_not_autoloaded() {
    if ((int) get_option('dnap_log_schema_version', 0) >= 1) return;

    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            DNAP_LOG_KEY
        )
    );
    if ($row && $row->autoload !== 'no') {
        $existing = get_option(DNAP_LOG_KEY, []);
        delete_option(DNAP_LOG_KEY);
        add_option(DNAP_LOG_KEY, $existing, '', 'no');
    }

    update_option('dnap_log_schema_version', 1);
}

/**
 * Resetta il log (sia buffer in-memory sia opzione).
 */
function dnap_clear_log() {
    $buffer = &dnap_log_buffer();
    $buffer = [];
    delete_option(DNAP_LOG_KEY);
}

/**
 * Restituisce tutte le righe del log (dalla più recente).
 * Le admin view sono richieste HTTP separate: il flush è già avvenuto al
 * termine della request di import, quindi l'elenco è sempre aggiornato.
 */
function dnap_get_log() {
    $log = get_option(DNAP_LOG_KEY, []);
    return array_reverse($log);
}
