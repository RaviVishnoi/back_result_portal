<?php

if (!function_exists('log_email_attempt')) {
    function log_email_attempt(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        $logDirs = [
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'back_result_portal_logs',
        ];

        foreach ($logDirs as $logDir) {
            if (!is_dir($logDir) && !@mkdir($logDir, 0750, true) && !is_dir($logDir)) {
                continue;
            }

            $logFile = $logDir . DIRECTORY_SEPARATOR . 'email.log';
            if (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        }

        error_log('Unable to write email log: ' . trim($logEntry));
    }
}
