<?php
/**
 * MailProcessor - Email processing for mailing lists
 * 
 * IMPORTANT: DO NOT modify the dispatch() or forward() logic without explicit user approval.
 * forward() relays the original message since 2026-11-12 (approved: "relay, don't rebuild"), and
 * dispatch() decides who may post with ListIntake since then too (approved with it: "intake +
 * moderation"); blocklist, bounces and round-back are still the original Listeproc.php's.
 */

declare(strict_types=1);

// Require PHPMailer and Fetch libraries
$libPath = ROOT_PATH . '/lib';
require_once $libPath . '/PHPMailer/load.php';
require_once $libPath . '/Fetch/Message.php';
require_once $libPath . '/Fetch/Server.php';
require_once $libPath . '/Fetch/MIME.php';
require_once $libPath . '/Fetch/Attachment.php';
require_once __DIR__ . '/ListRelay.php';
require_once __DIR__ . '/RelayJournal.php';
require_once __DIR__ . '/ListIntake.php';

use Fetch\Server;
use Fetch\Message;

class MailProcessor {
    public Database $db;
    private ListManager $listManager;
    private BlocklistManager $blocklistManager;
    private RelayJournal $journal;
    
    // Settings loaded from database
    private string $mailbox;
    private string $username;
    private string $password;
    private string $smtpHost;
    private int $smtpPort;
    private string $adminmail;
    private array $domains;
    
    // Runtime data
    private array $boxes = ['PENDING', 'APPROVED', 'DISCARDED', 'DONE', 'ARCHIVE', 'ERRORS', 'OTHERS'];
    private array $listes = [];
    private array $allAbonnes = [];
    private array $blocklist = [];
    private $imap;
    private $smtpConnection = null; // Reusable SMTP connection
    private array $blockedUnknown = []; // refused as unknown at RCPT TO this run, for the admin notice
    private ?string $linkSecret = null; // signs the moderation links (ListIntake::token)
    
    private array $log = [];
    private array $stats = [
        'inbox_processed' => 0,
        'approved_forwarded' => 0,
        'pending' => 0,
        'discarded' => 0,
        'blocked' => 0,
        'errors' => 0,
        'sent_messages' => 0,
        'sent_emails' => 0
    ];
    private float $startTime;
    private float $lastLogTime;
    
    // Performance limits
    private int $maxMessagesPerRun = 50; // Prevent timeout with too many messages
    private int $maxExecutionTime = 55; // Stay under 60s PHP timeout
    private int $processedCount = 0;
    
    // Retry configuration
    private int $maxSendRetries = 3;
    private float $retryDelay = 1.0; // seconds between retries

    public function __construct() {
        $this->startTime = microtime(true);
        $this->lastLogTime = $this->startTime;
        $this->db = Database::getInstance();
        $this->listManager = new ListManager();
        $this->blocklistManager = new BlocklistManager();
        $this->journal = new RelayJournal($this->db->getPdo());
        
        // Load settings from database
        $this->loadSettings();
        
        // Load lists and subscribers
        $this->loadListes();
        
        // Load blocklist
        $this->blocklist = $this->blocklistManager->getAllAsMap();
    }

    private function loadSettings(): void {
        $this->mailbox = $this->db->getSetting('imap_host', 'localhost');
        $this->username = $this->db->getSetting('imap_user', '');
        $this->password = $this->db->getSetting('imap_password', '');
        $this->smtpHost = $this->db->getSetting('smtp_host', 'localhost');
        $this->smtpPort = (int)$this->db->getSetting('smtp_port', '587');
        $this->adminmail = $this->db->getSetting('admin_email', '');
        
        // Parse domains (comma-separated)
        $domainsStr = $this->db->getSetting('domains', '');
        $this->domains = array_filter(array_map('trim', explode(',', $domainsStr)));
    }

    private function loadListes(): void {
        $this->listes = [];
        $this->allAbonnes = [];
        
        $lists = $this->listManager->getAllWithSubscribers();
        
        foreach ($lists as $row) {
            // List all Abo from liste de Discussion (to allow cross-list send)
            if (!$row['moderation']) {
                foreach ($row['abonnes'] as $ab) {
                    $abLower = strtolower($ab);
                    if (!in_array($abLower, $this->allAbonnes)) {
                        $this->allAbonnes[] = $abLower;
                    }
                }
            }
            
            // Set email for list
            $row['email'] = $row['nom'] . '@' . ($this->domains[0] ?? 'localhost');
            
            // Index by all domain variants
            foreach ($this->domains as $dom) {
                $this->listes[$row['nom'] . '@' . $dom] = $row;
            }
        }
    }

    public function connect(): bool {
        try {
            $this->imap = new Server($this->mailbox, 993);
            $this->imap->setAuthentication($this->username, $this->password);
            
            // Prepare boxes
            foreach ($this->boxes as $box) {
                if (!$this->imap->hasMailBox($box)) {
                    $this->imap->createMailBox($box);
                    $this->log('info', '📁 Created mailbox: ' . $box, ['box' => $box]);
                }
            }
            
            $this->log('success', '✅ IMAP connection established', ['host' => $this->mailbox]);
            return true;
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->log('error', 'IMAP connection failed: ' . $e->getMessage(), ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Safely move message to mailbox with validation
     */
    private function moveToMailBox($msg, string $mailbox): bool {
        try {
            // Validate mailbox exists
            if (!$this->imap->hasMailBox($mailbox)) {
                $this->log('warning', '⚠️ Mailbox does not exist, creating: ' . $mailbox, ['box' => $mailbox]);
                $this->imap->createMailBox($mailbox);
            }
            
            $msg->moveToMailBox($mailbox);
            return true;
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->log('error', 'Failed to move message to ' . $mailbox . ': ' . $e->getMessage(), [
                'mailbox' => $mailbox,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check if we should continue processing (time/message limits)
     */
    private function shouldContinueProcessing(): bool {
        $elapsed = microtime(true) - $this->startTime;
        
        if ($elapsed >= $this->maxExecutionTime) {
            $this->log('warning', '⏱️ Time limit reached (' . round($elapsed, 2) . 's), stopping processing', [
                'elapsed' => round($elapsed, 2),
                'limit' => $this->maxExecutionTime
            ]);
            return false;
        }
        
        if ($this->processedCount >= $this->maxMessagesPerRun) {
            $this->log('warning', '📊 Message limit reached (' . $this->processedCount . '), stopping processing', [
                'processed' => $this->processedCount,
                'limit' => $this->maxMessagesPerRun
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate IMAP connection is still alive
     */
    private function ensureConnection(): bool {
        try {
            if (!$this->imap) {
                throw new Exception('IMAP connection not initialized');
            }
            // Try a simple operation to check connection
            $this->imap->hasMailBox('INBOX');
            return true;
        } catch (Exception $e) {
            $this->log('warning', '⚠️ Connection lost, attempting reconnect...', ['error' => $e->getMessage()]);
            return $this->connect();
        }
    }

    private function log(string $level, string $message, array $context = []): void {
        $now = microtime(true);
        $elapsed = round($now - $this->lastLogTime, 3);
        $totalElapsed = round($now - $this->startTime, 3);
        $this->lastLogTime = $now;
        
        $logEntry = [
            'level' => $level,
            'message' => $message,
            'time' => date('H:i:s'),
            'elapsed' => $elapsed,
            'total_elapsed' => $totalElapsed,
            'context' => $context
        ];
        $this->log[] = $logEntry;
        
        // Color-coded output for browser
        $colors = [
            'success' => '#4caf50',
            'info' => '#2196f3',
            'warning' => '#ff9800',
            'error' => '#f44336',
            'debug' => '#9e9e9e'
        ];
        $color = $colors[$level] ?? '#ffffff';
        $icon = match($level) {
            'success' => '✅',
            'info' => 'ℹ️',
            'warning' => '⚠️',
            'error' => '❌',
            'debug' => '🔍',
            default => '•'
        };
        
        echo '<div style="margin: 5px 0; padding: 8px; background: rgba(0,0,0,0.3); border-left: 3px solid ' . $color . ';">';
        echo '<span style="color: ' . $color . '; font-weight: bold;">' . $icon . ' ' . strtoupper($level) . '</span> ';
        echo '<span style="color: #aaa; font-size: 0.9em;">[' . $logEntry['time'] . ' +' . $elapsed . 's]</span> ';
        echo '<span style="color: #eee;">' . htmlspecialchars($message) . '</span>';
        if (!empty($context)) {
            echo ' <span style="color: #999; font-size: 0.9em;">' . json_encode($context) . '</span>';
        }
        echo '</div>';
    }

    public function getLog(): array {
        return $this->log;
    }
    
    public function getStats(): array {
        return array_merge($this->stats, [
            'total_time' => round(microtime(true) - $this->startTime, 3)
        ]);
    }

    /**
     * Get or create reusable SMTP connection
     */
    private function getSmtpConnection(): \PHPMailer {
        if ($this->smtpConnection === null) {
            $startTime = microtime(true);
            $this->smtpConnection = new \PHPMailer();
            $this->smtpConnection->setSMTPInstance(new TrackingSMTP()); // per-recipient outcome, see sendMailWithRetry()
            $this->smtpConnection->isSMTP();
            $this->smtpConnection->Host = $this->smtpHost;
            $this->smtpConnection->Port = $this->smtpPort;
            $this->smtpConnection->SMTPAuth = true;
            $this->smtpConnection->Username = $this->username;
            $this->smtpConnection->Password = $this->db->getSetting('smtp_password', '');
            $this->smtpConnection->SMTPKeepAlive = true; // Keep connection alive
            $this->smtpConnection->isHTML(true);
            $this->smtpConnection->Sender = $this->username;
            
            $elapsed = round(microtime(true) - $startTime, 3);
            $this->log('debug', '🔌 SMTP connection established', [
                'host' => $this->smtpHost,
                'port' => $this->smtpPort,
                'time' => $elapsed . 's'
            ]);
        }
        return $this->smtpConnection;
    }
    
    /**
     * Create a new PHPMailer instance configured with SMTP settings
     */
    private function mail($subject = null, $dest = null, $charset = 'UTF-8') {
        // Use shared connection instead of creating new one
        $mail = $this->getSmtpConnection();
        
        // Clear previous recipients and reset
        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearCustomHeaders();
        $mail->clearReplyTos();
        
        $mail->CharSet = $charset;

        if ($subject) $mail->Subject = $subject;
        if ($dest) {
            $mail->addAddress($dest);
            $mail->setFrom($this->username, 'Listes ' . strtoupper(explode('.', $this->domains[0] ?? 'MAIL')[0]));
        }

        return $mail;
    }
    
    /**
     * Send email with retry mechanism
     */
    private function sendMailWithRetry(\PHPMailer $mail, string $context = ''): bool {
        $attempts = 0;
        $lastError = '';
        
        while ($attempts < $this->maxSendRetries) {
            $attempts++;
            
            $smtp = $mail->getSMTPInstance();
            if ($smtp instanceof TrackingSMTP) {
                $smtp->clearOutcome();
            }

            try {
                if ($mail->send()) {
                    if ($attempts > 1) {
                        $this->log('success', '✅ Email sent successfully after ' . $attempts . ' attempt(s)', [
                            'attempts' => $attempts,
                            'context' => $context
                        ]);
                    }
                    return true;
                }
                $lastError = $mail->ErrorInfo;
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }

            // DATA accepted = sent. A recipient refused at RCPT TO fails send() although the message
            // went out to all the others: sending it again would give each of them another copy
            // (audit 2026-11-12, finding 2). Unless only our own address took it (a broadcast
            // list's To), it is sent: the refusals are logged and never retried.
            if ($smtp instanceof TrackingSMTP && $smtp->dataAccepted) {
                $accepted = array_diff_key($mail->getAllRecipientAddresses(), $smtp->refused);
                unset($accepted[strtolower($mail->From)]);
                if (!empty($accepted)) {
                    $this->recipientsRefused($smtp->refused, $context);
                    return true;
                }
            }
            
            if ($attempts < $this->maxSendRetries) {
                $this->log('warning', '⚠️ Email send failed (attempt ' . $attempts . '/' . $this->maxSendRetries . '), retrying...', [
                    'attempt' => $attempts,
                    'error' => $lastError,
                    'context' => $context
                ]);
                usleep((int)($this->retryDelay * 1000000)); // Convert to microseconds
            }
        }
        
        $this->stats['errors']++;
        $this->log('error', '❌ Failed to send email after ' . $this->maxSendRetries . ' attempts', [
            'attempts' => $this->maxSendRetries,
            'error' => $lastError,
            'context' => $context
        ]);
        return false;
    }

    /**
     * A message went out, but these recipients were refused at RCPT TO. Each one is logged; one the
     * server does not know (550, status 5.1.x) is blocked, as a 550 bounce is in dispatch(), and the
     * admin is told at the end of the run. Any other refusal (a policy, a limit) blocks nobody.
     */
    private function recipientsRefused(array $refused, string $context): void {
        foreach ($refused as $email => $r) {
            $unknown = $r['code'] === 550 && str_starts_with($r['code_ex'], '5.1.');
            $this->log('warning', '⚠️ Recipient refused, message sent to the others: ' . $email, [
                'email' => $email,
                'code' => trim($r['code'] . ' ' . $r['code_ex']),
                'detail' => $r['detail'],
                'blocked' => $unknown,
                'context' => $context
            ]);
            if ($unknown && !isset($this->blocklist[$email])) {
                $this->blocklistManager->block($email, 550);
                $this->blocklist[$email] = 550;
                $this->blockedUnknown[] = $email;
                $this->stats['blocked']++;
            }
        }
    }

    /**
     * The admin notice for each address recipientsRefused() blocked, worded as dispatch()'s for a
     * bounce. Sent once the run's sends are over: mail() reuses the one connection's message.
     */
    private function notifyBlockedRecipients(): void {
        foreach ($this->blockedUnknown as $email) {
            $this->notify('[Listes] adresse inconnue', $this->adminmail,
                "Bonjour,<br />l'adresse <strong>" . htmlspecialchars($email) . "</strong> a été désactivée,<br />internet ne la connait pas..",
                'unknown recipient notification');
        }
        $this->blockedUnknown = [];
    }

    /**
     * Find matching lists from message destinations
     * PRESERVED FROM ORIGINAL
     */
    private function matchListes($msg): array {
        $msgdests = [];
        foreach ($msg->getAddresses('to') as $d) {
            $msgdests[] = strtolower($d['address']);
        }
        if (is_array($msg->getAddresses('cc'))) {
            foreach ($msg->getAddresses('cc') as $d) {
                $msgdests[] = strtolower($d['address']);
            }
        }
        if (is_array($msg->getAddresses('bcc'))) {
            foreach ($msg->getAddresses('bcc') as $d) {
                $msgdests[] = strtolower($d['address']);
            }
        }
        $msgdests = array_unique($msgdests);

        $listes = [];
        foreach ($msgdests as $dest) {
            if (isset($this->listes[$dest])) {
                $listes[$dest] = $this->listes[$dest];
            }
        }

        return $listes;
    }

    /**
     * Main processing loop
     */
    public function process(): void {
        $this->log('info', '🚀 Starting email processing cycle');
        $this->log('info', '⚙️ Limits: max ' . $this->maxMessagesPerRun . ' messages, ' . $this->maxExecutionTime . 's timeout', [
            'max_messages' => $this->maxMessagesPerRun,
            'max_time' => $this->maxExecutionTime
        ]);
        
        // Ensure connection before processing
        if (!$this->ensureConnection()) {
            $this->stats['errors']++;
            $this->log('error', '❌ Cannot establish IMAP connection, aborting');
            return;
        }
        
        // CHECK INBOX and DISPATCH
        $this->log('info', '📥 Checking INBOX for new messages...');
        try {
            $this->imap->setMailBox('INBOX');
            // Get message UIDs only - memory efficient
            $uids = $this->imap->getMessageUids();
            $inboxCount = count($uids);
            $this->log('info', 'Found ' . $inboxCount . ' message(s) in INBOX', ['count' => $inboxCount]);
            
            $inboxProcessed = 0;
            foreach ($uids as $uid) {
                if (!$this->shouldContinueProcessing()) {
                    $remaining = $inboxCount - $inboxProcessed;
                    $this->log('warning', '⚠️ Stopped processing INBOX, ' . $remaining . ' message(s) remaining', ['remaining' => $remaining]);
                    break;
                }
                
                // Check memory before loading each message
                $memNow = memory_get_usage(true);
                $memLimit = $this->getMemoryLimitBytes();
                if ($memNow > $memLimit * 0.7) {
                    $this->log('warning', '⚠️ Memory limit approaching, stopping INBOX processing', [
                        'used' => round($memNow / 1024 / 1024, 1) . 'MB',
                        'percentage' => round($memNow / $memLimit * 100, 1) . '%'
                    ]);
                    break;
                }
                
                try {
                    // Load ONE message at a time
                    $msg = $this->imap->getMessageByUid($uid);
                    
                    if ($msg === false) {
                        $this->log('warning', '⚠️ Could not load message UID ' . $uid, ['uid' => $uid]);
                        continue;
                    }
                    
                    $this->dispatch($msg);
                    $this->stats['inbox_processed']++;
                    $inboxProcessed++;
                    $this->processedCount++;
                    
                    // Cleanup
                    unset($msg);
                    gc_collect_cycles();
                    
                } catch (\Error $e) {
                    $this->stats['errors']++;
                    $this->log('error', '❌ Fatal error processing INBOX message UID ' . $uid . ': ' . $e->getMessage(), [
                        'uid' => $uid,
                        'error' => $e->getMessage()
                    ]);
                } catch (\Exception $e) {
                    $this->stats['errors']++;
                    $this->log('error', '❌ Error processing message from INBOX: ' . $e->getMessage(), [
                        'error' => $e->getMessage(),
                        'uid' => $uid
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->log('error', '❌ Error accessing INBOX: ' . $e->getMessage(), ['error' => $e->getMessage()]);
        }

        // CHECK APPROVED and FORWARD (only if we haven't hit limits)
        if ($this->shouldContinueProcessing()) {
            $this->log('info', '📤 Checking APPROVED for messages to forward...');
            try {
                // Ensure connection before second phase
                if (!$this->ensureConnection()) {
                    $this->log('error', '❌ Lost connection before APPROVED processing');
                    return;
                }
                
                // Log memory status before fetching
                $memBefore = memory_get_usage(true);
                $this->log('debug', '💾 Memory before APPROVED fetch', [
                    'used' => round($memBefore / 1024 / 1024, 1) . 'MB',
                    'limit' => round($this->getMemoryLimitBytes() / 1024 / 1024, 1) . 'MB'
                ]);
                
                $this->imap->setMailBox('APPROVED');
                
                // Get message UIDs only (no message body loading) - memory efficient!
                $uids = $this->imap->getMessageUids();
                $totalMessages = count($uids);
                
                $this->log('info', 'Found ' . $totalMessages . ' message(s) in APPROVED', [
                    'total' => $totalMessages,
                    'memory_used' => round(memory_get_usage(true) / 1024 / 1024, 1) . 'MB'
                ]);
                
                // Process messages ONE AT A TIME to avoid memory exhaustion
                $approvedProcessed = 0;
                foreach ($uids as $uid) {
                    if (!$this->shouldContinueProcessing()) {
                        $remaining = $totalMessages - $approvedProcessed;
                        $this->log('warning', '⚠️ Stopped processing APPROVED, ' . $remaining . ' message(s) remaining', ['remaining' => $remaining]);
                        break;
                    }
                    
                    // Check memory before loading each message
                    $memNow = memory_get_usage(true);
                    $memLimit = $this->getMemoryLimitBytes();
                    if ($memNow > $memLimit * 0.7) { // Stop at 70% memory usage (safer threshold)
                        $this->log('warning', '⚠️ Memory limit approaching, stopping processing', [
                            'used' => round($memNow / 1024 / 1024, 1) . 'MB',
                            'limit' => round($memLimit / 1024 / 1024, 1) . 'MB',
                            'percentage' => round($memNow / $memLimit * 100, 1) . '%'
                        ]);
                        break;
                    }
                    
                    try {
                        // Load ONE message at a time
                        $this->log('debug', '📧 Loading message UID ' . $uid, ['uid' => $uid]);
                        $msg = $this->imap->getMessageByUid($uid);
                        
                        if ($msg === false) {
                            $this->log('warning', '⚠️ Could not load message UID ' . $uid, ['uid' => $uid]);
                            continue;
                        }
                        
                        // Forward the message
                        $this->forward($msg);
                        $this->stats['approved_forwarded']++;
                        $approvedProcessed++;
                        $this->processedCount++;
                        
                        // Explicit cleanup - destroy message object
                        unset($msg);
                        
                        // Force garbage collection after each message
                        gc_collect_cycles();
                        
                        $memAfter = memory_get_usage(true);
                        $this->log('debug', '💾 Memory after message ' . $approvedProcessed, [
                            'used' => round($memAfter / 1024 / 1024, 1) . 'MB'
                        ]);
                        
                    } catch (\Error $e) {
                        // Catch PHP 8 Error types too (e.g., memory issues)
                        $this->stats['errors']++;
                        $this->log('error', '❌ Fatal error forwarding message UID ' . $uid . ': ' . $e->getMessage(), [
                            'uid' => $uid,
                            'error' => $e->getMessage()
                        ]);
                    } catch (\Exception $e) {
                        $this->stats['errors']++;
                        $this->log('error', '❌ Error forwarding message UID ' . $uid . ': ' . $e->getMessage(), [
                            'uid' => $uid,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->log('error', '❌ Error accessing APPROVED: ' . $e->getMessage(), ['error' => $e->getMessage()]);
            }
        } else {
            $this->log('info', '⏸️ Skipping APPROVED processing due to limits');
        }

        // CLEAR TEMP
        $this->log('info', '🧹 Cleaning temporary files...');
        try {
            $this->clearTemp();
        } catch (Exception $e) {
            $this->log('warning', '⚠️ Error cleaning temp files: ' . $e->getMessage());
        }
        
        // Tell the admin about recipients blocked as unknown during the sends above
        $this->notifyBlockedRecipients();

        // Close SMTP connection if it was used
        if ($this->smtpConnection !== null) {
            try {
                $this->smtpConnection->smtpClose();
                $this->log('debug', '🔌 SMTP connection closed');
            } catch (Exception $e) {
                $this->log('debug', '⚠️ Error closing SMTP: ' . $e->getMessage());
            }
        }

        $this->log('success', '✨ Processing cycle completed successfully');
    }

    /**
     * The PENDING message a moderation id names (ListIntake::moderationId of its Message-ID), or
     * null. The content hash the links carried before 2026-11-12 still finds its message, so a
     * moderation mail sent before the update keeps working from the admin pages.
     */
    private function findPending(string $id) {
        $this->imap->setMailBox('PENDING');
        foreach ($this->imap->getMessages() as $msg) {
            if (hash_equals(ListIntake::moderationId(ListRelay::messageKey($msg->getRawMessage())), $id)
                || hash_equals(sha1($msg->getRawHeaders() . $msg->getMessageBody()), $id)) {
                return $msg;
            }
        }
        return null;
    }

    /**
     * Approve a pending message
     */
    public function approve(string $uid): bool {
        if (!$this->ensureConnection()) {
            $this->log('error', '❌ Cannot approve: no IMAP connection');
            return false;
        }
        
        $msg = $this->findPending($uid);
        if ($msg === null) {
            $this->log('warning', '⚠️ Message not found in PENDING', ['uid' => substr($uid, 0, 8)]);
            return false;
        }
        $this->moveToMailBox($msg, 'APPROVED');
        $this->log('success', '✅ Message approved for distribution', ['uid' => substr($uid, 0, 8)]);

        // Process immediately
        $this->process();
        return true;
    }

    /**
     * Discard a pending message. The sender hears of it only if the provider verified them.
     */
    public function discard(string $uid): bool {
        if (!$this->ensureConnection()) {
            $this->log('error', '❌ Cannot discard: no IMAP connection');
            return false;
        }
        
        $msg = $this->findPending($uid);
        if ($msg === null) {
            $this->log('warning', '⚠️ Message not found in PENDING', ['uid' => substr($uid, 0, 8)]);
            return false;
        }
        $this->moveToMailBox($msg, 'DISCARDED');
        $this->log('info', '🗑️ Message discarded by moderator', ['uid' => substr($uid, 0, 8)]);

        try {
            $from = strtolower($msg->getAddresses('from')['address'] ?? '');
            $listes = array_unique(array_column($this->matchListes($msg), 'nom'));
            if ($listes && ListIntake::dmarcPass($msg->getRawMessage(), $this->authservId(), $from)) {
                $this->notify('[Listes] Envoi refusé par le modérateur', $from,
                    "Bonjour,<br />vous avez envoyé un mail à la liste <strong>" . htmlspecialchars(implode(', ', $listes)) . "</strong>.
                      <br />Le modérateur de la liste a refusé la transmission de votre mail.",
                    'discard notification');
            }
        } catch (Exception $e) {
            $this->log('warning', '⚠️ Could not notify sender of discarded message: ' . $e->getMessage());
        }
        return true;
    }

    /** One PENDING message, for the moderation page; null when it is no longer there. */
    public function getPendingMessage(string $id): ?array {
        if (!$this->ensureConnection()) {
            return null;
        }
        $msg = $this->findPending($id);
        return $msg === null ? null : $this->pendingSummary($msg);
    }

    private function pendingSummary($msg): array {
        $from = $msg->getAddresses('from');
        $raw = $msg->getRawMessage();
        return [
            'uid' => ListIntake::moderationId(ListRelay::messageKey($raw)),
            'from' => $from['address'] ?? 'unknown',
            'from_name' => $from['name'] ?? '',
            'verified' => ListIntake::dmarcPass($raw, $this->authservId(), strtolower($from['address'] ?? '')),
            'subject' => $msg->getSubject(),
            'date' => $msg->getDate(),
            'body_preview' => self::cut(strip_tags((string) $msg->getMessageBody()), 2000),
            'lists' => array_values(array_unique(array_column($this->matchListes($msg), 'nom'))),
        ];
    }

    /** The first $chars characters of UTF-8 text (bytes, if it is not UTF-8). */
    public static function cut(string $text, int $chars): string {
        if (preg_match('/^.{0,' . $chars . '}/us', $text, $m)) {
            return $m[0];
        }
        return substr($text, 0, $chars);
    }

    /**
     * DISPATCH - Handle incoming messages
     *
     * Blocklist, bounces and our own copy coming back as in the original Listeproc.php. Who may
     * post is ListIntake::decide() since 2026-11-12 (#t-073, approved with the relay: "intake +
     * moderation"): a sender is trusted on the provider's DMARC stamp, never on From: alone; an
     * outside participant may answer a thread the list relayed; automatic mail is filed silently;
     * STOP works on every list; and no notice ever goes to a sender the stamp did not verify.
     */
    private function dispatch($msg): void {
        // Validate message structure
        try {
            $from = $msg->getAddresses('from');
            if (empty($from) || !isset($from['address'])) {
                $this->stats['errors']++;
                $this->log('error', '❌ Malformed message: missing sender address');
                $this->moveToMailBox($msg, 'ERRORS');
                return;
            }
            $fromAddress = strtolower($from['address']);
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->log('error', '❌ Error reading message headers: ' . $e->getMessage());
            $this->moveToMailBox($msg, 'ERRORS');
            return;
        }
        
        // CHECK BLOCKLIST EARLY - before any processing
        if (isset($this->blocklist[$fromAddress])) {
            $this->stats['blocked']++;
            $this->log('warning', '🚫 Sender is blocked: ' . $fromAddress, ['email' => $fromAddress]);
            $this->moveToMailBox($msg, 'DISCARDED');
            return;
        }

        // ERROR MAIL - Check for bounce messages (before the automatic-mail filter: a bounce is one)
        $mailgex = "[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})";
        preg_match('/550 \d\.\d\.\d <(' . $mailgex . ')>/', $msg->getMessageBody(), $matches);
        
        if (count($matches) > 1 && $matches[1]) {
            // Block the bouncing email
            $this->blocklistManager->block($matches[1], 550);
            $this->stats['blocked']++;
            $this->log('warning', '🚫 Blocked bouncing email: ' . $matches[1], ['email' => $matches[1], 'reason' => 'bounce']);
            
            // Notify admin
            $this->notify('[Listes] adresse inconnue', $this->adminmail,
                "Bonjour,<br />l'adresse <strong>" . htmlspecialchars($matches[1]) . "</strong> a été désactivée,<br />internet ne la connait pas..",
                'bounce notification');
            $this->moveToMailBox($msg, 'ERRORS');
            return;
        }

        $matchedListes = $this->matchListes($msg);

        // ROUND BACK => archive (our own copy of a list, come back)
        foreach ($matchedListes as $liste) {
            if (stripos($msg->getRawHeaders(), 'X-list-relay: ' . $liste['email']) !== false) {
                $this->moveToMailBox($msg, 'ARCHIVE');
                return;
            }
        }

        $lists = [];
        foreach ($matchedListes as $liste) {
            $lists[$liste['nom']] = $liste;     // the same list named on two of its domains: once
        }
        $raw = $msg->getRawMessage();
        $verified = ListIntake::dmarcPass($raw, $this->authservId(), $fromAddress);
        $thread = ListIntake::threadIds($raw);
        $v = ListIntake::decide(
            $fromAddress,
            $verified,
            ListIntake::automatic($raw, $this->ownAddresses()),
            ListIntake::isStop((string) $msg->getSubject()),
            array_values($lists),
            $this->allAbonnes,
            fn(string $nom): bool => $thread !== [] && $this->journal->relayedAny($thread, $nom)
        );
        $context = [
            'from' => $fromAddress,
            'lists' => array_keys($lists),
            'verified' => $verified,
            'why' => $v['why'],
            'subject' => $msg->getSubject(),
        ];

        // UNSUBSCRIBE - a verified STOP leaves every list named that has the sender
        foreach ($v['unsubscribe'] as $nom) {
            $this->listManager->removeSubscriberFromList($nom, $fromAddress);
            $this->log('success', '👋 Unsubscribed from list: ' . $nom, ['email' => $fromAddress, 'list' => $nom]);
            $this->notify('[Desinscription] ' . $nom, $this->adminmail,
                "Bonjour,<br />" . htmlspecialchars($fromAddress) . " s'est désinscrit de la liste [" . htmlspecialchars($nom) . '].<br />',
                'unsubscribe notification');
        }
        // A STOP the provider did not verify removes nobody: anyone can write that From:
        foreach ($v['stopUnverified'] as $nom) {
            $this->log('warning', '⚠️ STOP from an unverified sender, nobody removed: ' . $nom, ['email' => $fromAddress, 'list' => $nom]);
            $this->notify('[Desinscription non vérifiée] ' . $nom, $this->adminmail,
                "Bonjour,<br />un message STOP pour la liste [" . htmlspecialchars($nom) . "] dit venir de <strong>" . htmlspecialchars($fromAddress)
                . "</strong>, mais son adresse n'a pas pu être vérifiée (DMARC) : personne n'a été désinscrit."
                . "<br />Si c'est bien cette personne, désinscrivez-la depuis l'interface.",
                'unverified unsubscribe notification');
        }

        switch ($v['folder']) {
            case 'OTHERS':
                $this->log('debug', '📋 Message moved to OTHERS (' . $v['why'] . ')', $context);
                break;
            case 'DISCARDED':
                $this->stats['discarded']++;
                $this->log('warning', '⛔ Message refused (' . $v['why'] . ')', $context);
                break;
            case 'PENDING':
                $this->stats['pending']++;
                $this->log('info', '⏳ Message pending moderation (' . $v['why'] . ')', $context);
                $this->askModerator($msg, $raw, $fromAddress, array_keys($lists), $v['why']);
                break;
            case 'APPROVED':
                $this->log('success', '✓ Message approved for list(s): ' . implode(', ', array_keys($lists)), $context);
                break;
        }

        // INFORM SENDER - only a verified one: a notice to a forged From is spam we send
        if ($v['notify'] === 'not-subscriber') {
            $this->notify('[Listes] envoi non autorisé', $fromAddress,
                "Bonjour,<br />vous avez envoyé un mail à la liste <strong>" . htmlspecialchars(implode(', ', array_keys($lists))) . "</strong>
                      depuis l'adresse <strong>" . htmlspecialchars($fromAddress) . "</strong>.
                      <br />Cette adresse n'est pas autorisée à envoyer des mails sur cette liste.
                      <br />Votre email ne pourra donc pas être transmis.",
                'unauthorized notification');
        } elseif ($v['notify'] === 'inactive') {
            $this->notify('[Listes] liste inactive', $fromAddress,
                "Bonjour,<br />vous avez envoyé un mail à la liste <strong>" . htmlspecialchars(implode(', ', $v['inactive'])) . "</strong>.
                      <br />Cette liste est actuellement désactivée, votre email ne pourra donc pas être transmis.",
                'inactive list notification');
        }

        $this->moveToMailBox($msg, $v['folder']);
    }

    /** What the moderator reads for each reason ListIntake::decide() holds a message. */
    public const PENDING_LABELS = [
        'moderated'          => 'la liste est modérée',
        'unverified'         => "l'adresse de l'expéditeur n'a pas pu être vérifiée (DMARC) : n'importe qui peut l'écrire",
        'outside-broadcast'  => "une personne extérieure répond à une annonce de la liste",
        'outside-other-list' => "une personne extérieure répond à un fil, mais écrit aussi à une liste qui n'en fait pas partie",
    ];

    /**
     * The moderation mail: who, what, why it is held, and ONE link. The link opens a page; only
     * the button on that page (a POST) acts, so a mail scanner that opens links decides nothing.
     * The link carries an HMAC of the message's id, never the cron key.
     */
    private function askModerator($msg, string $raw, string $from, array $lists, string $why): void {
        $link = $this->moderationLink(ListRelay::messageKey($raw));
        $preview = self::cut(trim((string) $msg->getMessageBody()), 2000);
        $this->notify('[Moderation] ' . implode(', ', $lists), $this->adminmail,
            "Bonjour,<br />"
            . htmlspecialchars($from) . ' a envoyé un message sur la liste [' . htmlspecialchars(implode(', ', $lists)) . '].<br />'
            . 'Il attend votre décision : ' . htmlspecialchars(self::PENDING_LABELS[$why] ?? $why) . '.<br /><br />'
            . '<em><strong>Sujet: </strong></em>' . htmlspecialchars((string) $msg->getSubject()) . '<br /><br />'
            . '<em><strong>Message: </strong></em><br /><br />' . nl2br(htmlspecialchars($preview)) . '<br /><br />'
            . '<a href="' . htmlspecialchars($link) . '">Ouvrir le message pour l\'accepter ou le refuser</a><br />',
            'moderation notification');
    }

    /** The moderation page's address for one message: its id and a token only we can make. */
    public function moderationLink(string $messageKey): string {
        $id = ListIntake::moderationId($messageKey);
        return $this->getBaseUrl() . 'moderate.php?id=' . $id . '&t=' . ListIntake::token($this->linkSecret(), 'moderate', $id);
    }

    public function checkModerationToken(string $id, string $token): bool {
        return ListIntake::checkToken($this->linkSecret(), $token, 'moderate', $id);
    }

    /** Our own addresses: every list's, on every domain, and the mailbox. */
    private function ownAddresses(): array {
        return array_merge(array_keys($this->listes), [strtolower($this->username)]);
    }

    private function authservId(): string {
        return $this->db->getSetting('authserv_id', ListIntake::AUTHSERV_ID) ?: ListIntake::AUTHSERV_ID;
    }

    private function linkSecret(): string {
        return $this->linkSecret ??= ListIntake::secret($this->db);
    }

    /** A mail from the robot mailbox to one address (the admin, or a verified sender). */
    protected function notify(string $subject, string $to, string $html, string $context): void {
        $mail = $this->mail($subject, $to);
        $mail->msgHTML($html);
        $this->sendMailWithRetry($mail, $context);
    }

    /**
     * FORWARD - relay an approved message to each list it names
     *
     * Each list gets the ORIGINAL message, headers rewritten and body untouched (ListRelay; decided
     * 2026-11-12, "relay, don't rebuild"), sent to its own envelope: the list's subscribers minus
     * the sender, whoever the original To/Cc reached, the blocklist, and anyone another list of the
     * same message already tried. The journal records each list as it goes out; the message moves
     * to DONE only once every active list it names is sent, and a retry skips what already went.
     */
    private function forward($msg): void {
        $forwardStartTime = microtime(true);
        
        // Validate message structure
        try {
            $from = $msg->getAddresses('from');
            if (empty($from) || !isset($from['address'])) {
                $this->stats['errors']++;
                $this->log('error', '❌ Cannot forward malformed message: missing sender');
                $this->moveToMailBox($msg, 'ERRORS');
                return;
            }
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->log('error', '❌ Error reading message for forward: ' . $e->getMessage());
            $this->moveToMailBox($msg, 'ERRORS');
            return;
        }
        
        $matchedListes = $this->matchListes($msg);
        if (empty($matchedListes)) {
            $this->log('warning', '⚠️ No matching list for approved message, moving to OTHERS');
            $this->moveToMailBox($msg, 'OTHERS');
            return;
        }

        $raw = $msg->getRawMessage();
        $key = ListRelay::messageKey($raw);
        $sender = strtolower($from['address']);
        $original = ListRelay::originalRecipients($raw);
        $own = array_merge(array_keys($this->listes), [strtolower($this->username)]);

        $active = 0;
        $complete = true;
        $done = [];
        foreach ($matchedListes as $liste) {
            if (isset($done[$liste['nom']])) {
                continue;   // the same list named on two of its domains
            }
            $done[$liste['nom']] = true;

            if (!$liste['active']) {
                $this->log('debug', '⏸️ Skipping inactive list', ['list' => $liste['nom']]);
                continue;
            }
            $active++;

            if ($this->journal->isSent($key, $liste['nom'])) {
                $this->log('info', '↪️ Already sent to this list, skipped', ['list' => $liste['nom'], 'message' => $key]);
                continue;
            }

            try {
                [$rcpts, $skipped] = ListRelay::recipients(
                    $liste['abonnes'], $sender, $original, $this->blocklist, $this->journal->reached($key), $own
                );
                $copy = ListRelay::copy($raw, $liste['nom'], $liste['email'], (bool) $liste['reponse']);
                $this->log('info', '📧 Relaying message to list: ' . $liste['email'], [
                    'list' => $liste['email'],
                    'recipients' => count($rcpts),
                    'skipped' => array_count_values($skipped),
                ]);

                $error = '';
                $outcome = $this->relayWithRetry($copy, $rcpts, 'relay to ' . $liste['email'], $error);
                if ($outcome === null) {
                    $this->journal->markFailed($key, $liste['nom'], $error);
                    $complete = false;
                    $this->log('error', '❌ Message not sent, keeping in APPROVED for retry', ['list' => $liste['email']]);
                    continue;
                }

                [$accepted, $refused] = $outcome;
                $this->journal->markSent($key, $liste['nom'], $accepted, array_map(
                    fn($r) => trim($r['code'] . ' ' . $r['code_ex'] . ' ' . $r['detail']), $refused
                ));
                $this->stats['sent_messages']++;
                $this->stats['sent_emails'] += count($accepted);
                $this->log('success', '✅ Message relayed to ' . count($accepted) . ' recipient(s)', [
                    'list' => $liste['email'],
                    'recipients' => count($accepted),
                    'refused' => count($refused),
                    'sample' => array_slice($accepted, 0, 3)
                ]);
                $this->listManager->markAsUsedByName($liste['nom']);
            } catch (\Throwable $e) {
                $complete = false;
                $this->stats['errors']++;
                $this->log('error', '❌ Error during list relay: ' . $e->getMessage(), [
                    'list' => $liste['nom'],
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500)
                ]);
            }
        }

        if ($active === 0) {
            $this->log('warning', '⏸️ Every list this message names is inactive, keeping it in APPROVED', ['message' => $key]);
        } elseif ($complete) {
            $this->moveToMailBox($msg, 'DONE');
        }

        $forwardTime = round(microtime(true) - $forwardStartTime, 3);
        $this->log('debug', '✓ Forward complete', ['total_time' => $forwardTime . 's', 'lists' => count($done), 'complete' => $complete]);
    }

    /**
     * Send one list copy, as is, to these recipients: up to maxSendRetries attempts, each a whole
     * SMTP transaction. Returns [accepted, refused] once the provider took it — or once every
     * recipient was refused for good (5xx: nothing a resend would change) — and null when it did
     * not go out ($error says why). Refusals are never a reason to send again (#t-071).
     *
     * @return array{0: string[], 1: array<string,array{code:int,code_ex:string,detail:string}>}|null
     */
    private function relayWithRetry(string $copy, array $rcpts, string $context, string &$error = ''): ?array {
        if (empty($rcpts)) {
            return [[], []];
        }
        for ($attempt = 1; $attempt <= $this->maxSendRetries; $attempt++) {
            $outcome = $this->relayOnce($copy, $rcpts, $error);
            if ($outcome !== null) {
                if (!empty($outcome[1])) {
                    $this->recipientsRefused($outcome[1], $context);
                }
                return $outcome;
            }
            if ($attempt < $this->maxSendRetries) {
                $this->log('warning', '⚠️ Relay failed (attempt ' . $attempt . '/' . $this->maxSendRetries . '), retrying...', [
                    'error' => $error,
                    'context' => $context
                ]);
                usleep((int)($this->retryDelay * 1000000));
            }
        }
        $this->stats['errors']++;
        $this->log('error', '❌ Failed to relay after ' . $this->maxSendRetries . ' attempts', [
            'error' => $error,
            'context' => $context
        ]);
        return null;
    }

    /** One SMTP transaction on the shared connection: MAIL FROM the mailbox, one RCPT each, DATA. */
    private function relayOnce(string $copy, array $rcpts, string &$error): ?array {
        $mail = $this->getSmtpConnection();
        $smtp = $mail->getSMTPInstance();
        try {
            if (!$mail->smtpConnect()) {
                $error = 'SMTP connect failed';
                return null;
            }
        } catch (\Exception $e) {
            $error = 'SMTP connect failed: ' . $e->getMessage();
            return null;
        }
        if ($smtp instanceof TrackingSMTP) {
            $smtp->clearOutcome();
        }

        if (!$smtp->mail($this->username)) {
            $error = 'MAIL FROM refused: ' . implode(' ', $smtp->getError());
            $smtp->reset() || $smtp->close();
            return null;
        }
        $accepted = [];
        $refused = [];
        foreach ($rcpts as $address) {
            if ($smtp->recipient($address)) {
                $accepted[] = $address;
            } else {
                $e = $smtp->getError();
                $refused[$address] = [
                    'code' => (int) $e['smtp_code'],
                    'code_ex' => (string) $e['smtp_code_ex'],
                    'detail' => trim((string) $e['detail']),
                ];
            }
        }

        if (empty($accepted)) {
            $smtp->reset() || $smtp->close();
            foreach ($refused as $r) {
                if ($r['code'] < 500) {
                    $error = 'every recipient refused, some for now';
                    return null;
                }
            }
            return [[], $refused];
        }

        // SMTP::data() ends the last line itself: one trailing line end less keeps the bytes as they were.
        $data = str_ends_with($copy, "\r\n") ? substr($copy, 0, -2) : rtrim($copy, "\n");
        if (!$smtp->data($data)) {
            $error = 'DATA refused: ' . implode(' ', $smtp->getError());
            $smtp->reset() || $smtp->close();
            return null;
        }
        $smtp->reset() || $smtp->close();
        return [$accepted, $refused];
    }

    /**
     * Clear temporary files
     */
    private function clearTemp(): void {
        $tmpDir = ROOT_PATH . '/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
            return;
        }
        
        $files = glob($tmpDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Get base URL for approve/discard links
     */
    private function getBaseUrl(): string {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        return $protocol . '://' . $host . rtrim($path, '/') . '/';
    }
    
    /**
     * Get PHP memory limit in bytes
     */
    private function getMemoryLimitBytes(): int {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX; // No limit
        }
        
        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }

    /**
     * Get pending messages (for moderation UI)
     */
    public function getPendingMessages(): array {
        $messages = [];
        
        try {
            $this->imap->setMailBox('PENDING');
            $uids = $this->imap->getMessageUids(20); // Limit to 20
            
            foreach ($uids as $uid) {
                try {
                    $msg = $this->imap->getMessageByUid($uid);
                    if ($msg === false) continue;
                    
                    $summary = $this->pendingSummary($msg);
                    $summary['body_preview'] = self::cut($summary['body_preview'], 200);
                    $messages[] = $summary;
                    
                    unset($msg);
                    gc_collect_cycles();
                } catch (Exception $e) {
                    continue;
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }
        
        return $messages;
    }

    /**
     * Get error messages (for errors UI)
     */
    public function getErrorMessages(): array {
        return $this->getMessagesFromFolder('ERRORS', 20);
    }

    /**
     * Get discarded messages (for errors UI)
     */
    public function getDiscardedMessages(): array {
        return $this->getMessagesFromFolder('DISCARDED', 20, true);
    }

    /** What the Refusés page says for each reason refusalReason() returns. */
    public const REFUSAL_LABELS = [
        'blocked'        => 'expéditeur bloqué',
        'not-subscriber' => "l'expéditeur n'est abonné à aucune liste de discussion",
        'inactive'       => 'liste inactive',
        'moderator'      => 'refusé par le modérateur',
        'unknown'        => 'raison inconnue (les listes ont changé depuis ?)',
    ];

    /**
     * Why dispatch() would have put this message in DISCARDED, read again from TODAY's
     * blocklist, lists and subscribers (nothing stores the reason when the message moves).
     * Same order as dispatch(): blocked sender first, then per list named — a sender on no
     * discussion list, an inactive list; a moderated list sends to PENDING, so a message of
     * one that sits in DISCARDED was refused by the moderator.
     */
    public static function refusalReason(string $from, array $matchedListes, array $blocklist, array $allAbonnes): string {
        $from = strtolower($from);
        if (isset($blocklist[$from])) return 'blocked';
        if (empty($matchedListes)) return 'unknown';
        if (!in_array($from, $allAbonnes, true)) return 'not-subscriber';
        $reason = null;
        foreach ($matchedListes as $liste) {
            if (!$liste['active']) {
                $reason = $reason ?? 'inactive';
            } elseif ($liste['moderation']) {
                return 'moderator';
            } else {
                $reason = 'unknown';    // dispatch() would approve it today
            }
        }
        return $reason ?? 'unknown';
    }

    /**
     * Helper to get messages from a folder
     */
    private function getMessagesFromFolder(string $folder, int $limit = 20, bool $withReason = false): array {
        $messages = [];
        
        try {
            $this->imap->setMailBox($folder);
            $uids = $this->imap->getMessageUids($limit, true);
            
            foreach ($uids as $uid) {
                try {
                    $msg = $this->imap->getMessageByUid($uid);
                    if ($msg === false) continue;
                    
                    $from = $msg->getAddresses('from');
                    $item = [
                        'uid' => $uid,
                        'from' => $from['address'] ?? 'unknown',
                        'from_name' => $from['name'] ?? '',
                        'subject' => $msg->getSubject(),
                        'date' => $msg->getDate(),
                        'body_preview' => substr(strip_tags($msg->getMessageBody()), 0, 200)
                    ];
                    if ($withReason) {
                        $matched = $this->matchListes($msg);
                        $item['lists'] = array_values(array_unique(array_column($matched, 'nom')));
                        $item['reason'] = self::refusalReason($from['address'] ?? '', $matched, $this->blocklist, $this->allAbonnes);
                    }
                    $messages[] = $item;
                    
                    unset($msg);
                    gc_collect_cycles();
                } catch (Exception $e) {
                    continue;
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }
        
        return self::newestFirst($messages);
    }

    /**
     * Sort folder messages by date, newest first (an unknown date sorts last).
     */
    public static function newestFirst(array $messages): array {
        usort($messages, function ($a, $b) {
            return ((int)($b['date'] ?? 0) <=> (int)($a['date'] ?? 0))
                ?: (($b['uid'] ?? 0) <=> ($a['uid'] ?? 0));
        });
        return $messages;
    }
}
