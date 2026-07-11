<?php
/**
 * PHPMailer 7.x loader for KList.
 * Loads the namespaced library and aliases it to the legacy global class name
 * (\PHPMailer) so existing KList call sites (new PHPMailer(), \PHPMailer type
 * hints) keep working unchanged. See sec_cleanup incident report 2026-07-11.
 */
require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/PHPMailer.php';
require_once __DIR__ . '/src/SMTP.php';
if (!class_exists('PHPMailer', false)) {
    class_alias(\PHPMailer\PHPMailer\PHPMailer::class, 'PHPMailer');
}
