<?php
/**
 * TrackingSMTP - PHPMailer's SMTP client, keeping the outcome of the last send per recipient
 *
 * When the server refuses one recipient at RCPT TO, PHPMailer still sends the message to every
 * other recipient, then reports the whole send() as failed (smtpSend(): recipients_failed,
 * STOP_CONTINUE), keeping only the refusals' text. MailProcessor::sendMailWithRetry() reads this
 * record to tell "nobody got it" (retry) from "it went out, some were refused" (never send it
 * again to everyone): audit 2026-11-12, finding 2.
 *
 * The record covers one send() attempt: clearOutcome() before each one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/PHPMailer/load.php';

class TrackingSMTP extends \PHPMailer\PHPMailer\SMTP {
    /** True once the server accepted the message itself (the end of DATA). */
    public bool $dataAccepted = false;

    /** Recipients refused at RCPT TO: lowercased address => ['code' => 550, 'code_ex' => '5.1.1', 'detail' => '…']. */
    public array $refused = [];

    public function clearOutcome(): void {
        $this->dataAccepted = false;
        $this->refused = [];
    }

    public function recipient($address, $dsn = '') {
        $ok = parent::recipient($address, $dsn);
        if (!$ok) {
            $error = $this->getError();
            $this->refused[strtolower((string) $address)] = [
                'code' => (int) $error['smtp_code'],
                'code_ex' => (string) $error['smtp_code_ex'],
                'detail' => trim((string) $error['detail']),
            ];
        }
        return $ok;
    }

    public function data($msg_data) {
        $this->dataAccepted = parent::data($msg_data);
        return $this->dataAccepted;
    }
}
