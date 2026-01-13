<?php
/**
 * MailProcessor - Email processing for mailing lists
 * 
 * IMPORTANT: This file preserves the exact logic from the original Listeproc.php
 * DO NOT modify the dispatch() or forward() logic without explicit user approval.
 */

declare(strict_types=1);

// Require PHPMailer and Fetch libraries
$libPath = ROOT_PATH . '/lib';
require_once $libPath . '/PHPMailer/class.phpmailer.php';
require_once $libPath . '/PHPMailer/class.smtp.php';
require_once $libPath . '/Fetch/Message.php';
require_once $libPath . '/Fetch/Server.php';
require_once $libPath . '/Fetch/MIME.php';
require_once $libPath . '/Fetch/Attachment.php';

use Fetch\Server;
use Fetch\Message;

class MailProcessor {
    public Database $db;
    private ListManager $listManager;
    private BlocklistManager $blocklistManager;
    
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
            $this->smtpConnection->isSMTP();
            $this->smtpConnection->Host = $this->smtpHost;
            $this->smtpConnection->Port = $this->smtpPort;
            $this->smtpConnection->SMTPAuth = true;
            $this->smtpConnection->Username = $this->username;
            $this->smtpConnection->Password = $this->db->getSetting('smtp_password', '');
            $this->smtpConnection->SMTPKeepAlive = true; // Keep connection alive
            $this->smtpConnection->isHTML = true;
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
     * Approve a pending message
     */
    public function approve(string $uid): bool {
        if (!$this->ensureConnection()) {
            $this->log('error', '❌ Cannot approve: no IMAP connection');
            return false;
        }
        
        $this->imap->setMailBox('PENDING');
        $messages = $this->imap->getMessages();
        
        foreach ($messages as $msg) {
            if ($uid == sha1($msg->getRawHeaders() . $msg->getMessageBody())) {
                $this->moveToMailBox($msg, 'APPROVED');
                $this->log('success', '✅ Message approved for distribution', ['uid' => substr($uid, 0, 8)]);
                
                // Process immediately
                $this->process();
                return true;
            }
        }
        
        $this->log('warning', '⚠️ Message not found in PENDING', ['uid' => substr($uid, 0, 8)]);
        return false;
    }

    /**
     * Discard a pending message
     */
    public function discard(string $uid): bool {
        if (!$this->ensureConnection()) {
            $this->log('error', '❌ Cannot discard: no IMAP connection');
            return false;
        }
        
        $this->imap->setMailBox('PENDING');
        $messages = $this->imap->getMessages();
        
        foreach ($messages as $msg) {
            if ($uid == sha1($msg->getRawHeaders() . $msg->getMessageBody())) {
                $this->moveToMailBox($msg, 'DISCARDED');
                $this->log('info', '🗑️ Message discarded by moderator', ['uid' => substr($uid, 0, 8)]);
                
                // Find the list to inform sender
                try {
                    foreach ($this->matchListes($msg) as $liste) {
                        // INFORM SENDER
                        $mail = $this->mail('[Listes] Envoi refusé par le modérateur', $msg->getAddresses('from')['address']);
                        $mail->msgHTML("Bonjour,<br />vous avez envoyé un mail à la liste <strong>" . $liste['nom'] . "</strong>.
                              <br />Le modérateur de la liste a refusé la transmission de votre mail.");
                        $this->sendMailWithRetry($mail, 'discard notification');
                        break;
                    }
                } catch (Exception $e) {
                    $this->log('warning', '⚠️ Could not notify sender of discarded message: ' . $e->getMessage());
                }
                
                return true;
            }
        }
        
        $this->log('warning', '⚠️ Message not found in PENDING', ['uid' => substr($uid, 0, 8)]);
        return false;
    }

    /**
     * DISPATCH - Handle incoming messages
     * =====================================================
     * PRESERVED FROM ORIGINAL - DO NOT MODIFY WITHOUT APPROVAL
     * =====================================================
     */
    private function dispatch($msg): void {
        $dispatch = null;
        
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
            $dispatch = 'DISCARDED';
            $this->stats['blocked']++;
            $this->log('warning', '🚫 Sender is blocked: ' . $fromAddress, ['email' => $fromAddress]);
            $this->moveToMailBox($msg, $dispatch);
            return;
        }

        // ERROR MAIL - Check for bounce messages
        $mailgex = "[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,})";
        preg_match('/550 \d\.\d\.\d <(' . $mailgex . ')>/', $msg->getMessageBody(), $matches);
        
        if (count($matches) > 1 && $matches[1]) {
            // Block the bouncing email
            $this->blocklistManager->block($matches[1], 550);
            $dispatch = 'ERRORS';
            $this->stats['blocked']++;
            $this->log('warning', '🚫 Blocked bouncing email: ' . $matches[1], ['email' => $matches[1], 'reason' => 'bounce']);
            
            // Notify admin
            $mail = $this->mail('[Listes] adresse inconnue', $this->adminmail);
            $mail->msgHTML("Bonjour,<br />l'adresse <strong>" . $matches[1] . "</strong> a été désactivée,<br />internet ne la connait pas..");
            $this->sendMailWithRetry($mail, 'bounce notification');
        }
        // FIND LIST IN DESTS
        else {
            $matchedListes = $this->matchListes($msg);
            
            // No matching list found
            if (empty($matchedListes)) {
                $dispatch = 'OTHERS';
                $this->log('debug', '📋 No matching list found for message', [
                    'from' => $fromAddress,
                    'subject' => $msg->getSubject()
                ]);
            } else {
                foreach ($matchedListes as $liste) {
                    // ROUND BACK => archive
                    if (stripos($msg->getRawHeaders(), 'X-list-relay: ' . $liste['email']) > 0) {
                    $dispatch = 'ARCHIVE';
                    break;
                }
                // AUTHORIZED SENDER (abonné à UNE liste n'importe laquelle)
                else if (!in_array(strtolower($msg->getAddresses('from')['address']), $this->allAbonnes)) {
                    if (!$dispatch) {
                        $dispatch = 'DISCARDED';
                        $this->stats['discarded']++;
                        $this->log('warning', '⛔ Unauthorized sender to list: ' . $liste['nom'], [
                            'from' => $msg->getAddresses('from')['address'],
                            'list' => $liste['nom']
                        ]);
                    }
                    
                    // INFORM SENDER
                    $mail = $this->mail('[Listes] envoi non autorisé', strtolower($msg->getAddresses('from')['address']));
                    $mail->msgHTML("Bonjour,<br />vous avez envoyé un mail à la liste <strong>" . $liste['nom'] . "</strong>
                              depuis l'adresse <strong>" . strtolower($msg->getAddresses('from')['address']) . "</strong>.
                              <br />Cette adresse n'est pas autorisée à envoyer des mails sur cette liste.
                              <br />Votre email ne pourra donc pas être transmis.");
                    $this->sendMailWithRetry($mail, 'unauthorized notification');
                }
                // UNSUBSCRIBE
                else if (trim($msg->getSubject()) == 'STOP') {
                    $this->listManager->removeSubscriberFromList($liste['nom'], strtolower($msg->getAddresses('from')['address']));
                    $dispatch = 'DONE';
                    $this->log('success', '👋 Unsubscribed from list: ' . $liste['nom'], [
                        'email' => $msg->getAddresses('from')['address'],
                        'list' => $liste['nom']
                    ]);
                    
                    // Notify admin
                    $mail = $this->mail('[Desinscription] ' . $liste['nom'], $this->adminmail);
                    $mail->msgHTML("Bonjour,<br />"
                            . $msg->getAddresses('from')['address'] . ' s\'est désinscrit de la liste [' . $liste['nom'] . '].<br />');
                    $this->sendMailWithRetry($mail, 'unsubscribe notification');
                    break;
                }
                // LIST enabled check
                else if (!$liste['active']) {
                    if (!$dispatch) {
                        $dispatch = 'DISCARDED';
                        $this->stats['discarded']++;
                        $this->log('warning', '⏸️ List is inactive: ' . $liste['nom'], ['list' => $liste['nom']]);
                    }
                    
                    // INFORM SENDER
                    $mail = $this->mail('[Listes] liste inactive', $msg->getAddresses('from')['address']);
                    $mail->msgHTML("Bonjour,<br />vous avez envoyé un mail à la liste <strong>" . $liste['nom'] . "</strong>.
                              <br />Cette liste est actuellement désactivée, votre email ne pourra donc pas être transmis.");
                    $this->sendMailWithRetry($mail, 'inactive list notification');
                }
                // MODERATION required
                else if ($liste['moderation']) {
                    $dispatch = 'PENDING';
                    $this->stats['pending']++;
                    $uid = sha1($msg->getRawHeaders() . $msg->getMessageBody());
                    $this->log('info', '⏳ Message pending moderation for list: ' . $liste['nom'], [
                        'list' => $liste['nom'],
                        'from' => $msg->getAddresses('from')['address'],
                        'subject' => $msg->getSubject(),
                        'uid' => substr($uid, 0, 8)
                    ]);
                    
                    // Generate approve/discard URLs
                    $baseUrl = $this->getBaseUrl();
                    
                    $mail = $this->mail('[Moderation] ' . $liste['nom'], $this->adminmail);
                    $mail->msgHTML("Bonjour,<br />"
                            . $msg->getAddresses('from')['address'] . ' a envoyé un message sur la liste [' . $liste['nom'] . '].<br />'
                            . 'Vous devez modérer ce message pour en accepter ou refuser la diffusion.<br /><br />'
                            . '<em><strong>Sujet: </strong></em>' . $msg->getSubject() . '<br /><br />'
                            . '<em><strong>Message: </strong></em><br /><br />' . $msg->getMessageBody(true) . '<br /><br />'
                            . '<a href="' . $baseUrl . 'cron.php?action=approve&uid=' . $uid . '&key=' . $this->db->getSetting('cron_key') . '">Accepter la diffusion</a>  /  '
                            . '<a href="' . $baseUrl . 'cron.php?action=discard&uid=' . $uid . '&key=' . $this->db->getSetting('cron_key') . '">Refuser la diffusion</a><br />');
                    $this->sendMailWithRetry($mail, 'moderation notification');
                    break;
                }
                // APPROVED - ready to forward
                else {
                    $dispatch = 'APPROVED';
                    $this->log('success', '✓ Message approved for list: ' . $liste['nom'], [
                        'list' => $liste['nom'],
                        'from' => $msg->getAddresses('from')['address'],
                        'subject' => $msg->getSubject()
                    ]);
                }
                } // end foreach matchedListes
            } // end else (has matched listes)
        } // end else (not error mail)

        if (!$dispatch) {
            $dispatch = 'OTHERS';
            $this->log('debug', '📋 Message moved to OTHERS (no matching list)', [
                'from' => $fromAddress ?? 'unknown',
                'subject' => $msg->getSubject()
            ]);
        }
        $this->moveToMailBox($msg, $dispatch);
    }

    /**
     * FORWARD - Send approved messages to subscribers
     * =====================================================
     * PRESERVED FROM ORIGINAL - DO NOT MODIFY WITHOUT APPROVAL
     * =====================================================
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
        
        $matchStartTime = microtime(true);
        $matchedListes = $this->matchListes($msg);
        $matchTime = round(microtime(true) - $matchStartTime, 3);
        $this->log('debug', '🔍 matchListes completed', ['time' => $matchTime . 's', 'found' => count($matchedListes)]);
        
        if (empty($matchedListes)) {
            $this->log('warning', '⚠️ No matching list for approved message, moving to OTHERS');
            $this->moveToMailBox($msg, 'OTHERS');
            return;
        }
        
        $listCount = 0;
        foreach ($matchedListes as $liste) {
            $listCount++;
            $listStartTime = microtime(true);
            $this->log('debug', '📋 Processing list ' . $listCount . '/' . count($matchedListes) . ': ' . $liste['nom'], ['list' => $liste['nom']]);
            
            if (!$liste['active']) {
                $this->log('debug', '⏸️ Skipping inactive list', ['list' => $liste['nom']]);
                continue;
            }
            
            try {
                $buildStartTime = microtime(true);
                $mail = $this->mail();

                // WATERMARK (to handle round back)
                $mail->addCustomHeader('X-list-relay', $liste['email']);

                // FROM (liste)
                $mail->setFrom($liste['email'], ucfirst($liste['nom']) . ' ' . strtoupper(explode('.', $this->domains[0] ?? 'MAIL')[0]));

                // REPLY (to original sender)
                $namefrom = '';
                if (isset($msg->getAddresses('from')['name'])) {
                    $namefrom = $msg->getAddresses('from')['name'];
                }
                $mail->AddReplyTo($msg->getAddresses('from')['address'], $namefrom);

                // DEST - filter blocked emails
                $cc = [];
                foreach ($liste['abonnes'] as $to) {
                    if (!isset($this->blocklist[$to])) {
                        $cc[] = $to;
                    } else {
                        $this->stats['blocked']++;
                        $this->log('debug', '🚫 Recipient blocked: ' . $to, ['email' => $to]);
                    }
                }

                // Liste Discussion : expose destinataires
                if ($liste['reponse']) {
                    foreach ($cc as $to) {
                        $mail->addAddress($to);
                    }
                }
                // Liste de Diffusion : hide destinataires (expose liste to handle reply-to-all)
                else {
                    $mail->addAddress($liste['email'], ucfirst($liste['nom']) . ' ' . strtoupper(explode('.', $this->domains[0] ?? 'MAIL')[0]));
                    foreach ($cc as $to) {
                        $mail->addBCC($to);
                    }
                }

                // SUBJECT
                $subject = explode($liste['nom'] . '] ', $msg->getSubject());
                $subject = end($subject);
                $mail->Subject = '[' . $namefrom . ' sur ' . $liste['nom'] . '] ' . $subject;

                // SENDER info
                $sender = 'Message envoyé par: ' . $msg->getAddresses('from')['name'] . ' ' . $msg->getAddresses('from')['address'];

                // UNSUBSCRIBE link
                $unsub = 'Pour vous désabonner de cette liste,
                    <a href="mailto:' . $liste['email'] . '?subject=STOP"> envoyez lui un mail </a>
                    avec STOP comme sujet.';

                // BODY
                $bodyHTML = $msg->getMessageBody(true);
                $bodyNOHTML = $msg->getMessageBody(false);
                $mail->msgHTML('<small><em>' . $sender . '</em></small><br /><br />' . $bodyHTML . '<br /><br /><small><em>' . $unsub . '</em></small>');
                $mail->AltBody = $sender . '\n\n' . $bodyNOHTML . '\n\n' . $unsub;

                // ATTACHMENT - with size limit to prevent memory exhaustion
                $attachStartTime = microtime(true);
                $attach = $msg->getAttachments();
                $maxAttachmentSize = 10 * 1024 * 1024; // 10MB per attachment
                $maxTotalSize = 25 * 1024 * 1024; // 25MB total
                $totalAttachSize = 0;
                $skippedAttachments = 0;
                
                if (is_array($attach) && count($attach) > 0) {
                    foreach ($attach as $key => $piece) {
                        try {
                            // Check memory before processing
                            $memoryUsed = memory_get_usage(true);
                            $memoryLimit = $this->getMemoryLimitBytes();
                            $memoryAvailable = $memoryLimit - $memoryUsed;
                            
                            // Need at least 50MB free for safe processing
                            if ($memoryAvailable < 50 * 1024 * 1024) {
                                $this->log('warning', '⚠️ Low memory, skipping remaining attachments', [
                                    'used' => round($memoryUsed / 1024 / 1024, 1) . 'MB',
                                    'available' => round($memoryAvailable / 1024 / 1024, 1) . 'MB'
                                ]);
                                $skippedAttachments++;
                                continue;
                            }
                            
                            $piece->saveToDirectory(ROOT_PATH . '/tmp/');
                            $filePath = ROOT_PATH . '/tmp/' . $piece->getFileName();
                            
                            if (file_exists($filePath)) {
                                $fileSize = filesize($filePath);
                                
                                // Check individual file size
                                if ($fileSize > $maxAttachmentSize) {
                                    $this->log('warning', '⚠️ Attachment too large, skipping', [
                                        'file' => $piece->getFileName(),
                                        'size' => round($fileSize / 1024 / 1024, 2) . 'MB',
                                        'max' => round($maxAttachmentSize / 1024 / 1024, 2) . 'MB'
                                    ]);
                                    unlink($filePath);
                                    $skippedAttachments++;
                                    continue;
                                }
                                
                                // Check total size
                                if ($totalAttachSize + $fileSize > $maxTotalSize) {
                                    $this->log('warning', '⚠️ Total attachment size exceeded, skipping', [
                                        'file' => $piece->getFileName(),
                                        'total' => round($totalAttachSize / 1024 / 1024, 2) . 'MB',
                                        'max' => round($maxTotalSize / 1024 / 1024, 2) . 'MB'
                                    ]);
                                    unlink($filePath);
                                    $skippedAttachments++;
                                    continue;
                                }
                                
                                $mail->addAttachment($filePath);
                                $totalAttachSize += $fileSize;
                            }
                        } catch (Exception $e) {
                            $this->log('warning', '⚠️ Error processing attachment: ' . $e->getMessage());
                            $skippedAttachments++;
                        }
                    }
                    $attachTime = round(microtime(true) - $attachStartTime, 3);
                    $this->log('debug', '📎 Attachments processed', [
                        'count' => count($attach) - $skippedAttachments,
                        'skipped' => $skippedAttachments,
                        'total_size' => round($totalAttachSize / 1024 / 1024, 2) . 'MB',
                        'time' => $attachTime . 's'
                    ]);
                }
                
                $buildTime = round(microtime(true) - $buildStartTime, 3);
                $this->log('debug', '📝 Email built', ['time' => $buildTime . 's', 'attachments' => is_array($attach) ? count($attach) : 0]);

                // SEND
                $sendStartTime = microtime(true);
                $this->log('info', '📧 Sending message to list: ' . $liste['email'], ['list' => $liste['email'], 'recipients' => count($cc)]);
                
                if (!$this->sendMailWithRetry($mail, 'forward to ' . $liste['email'])) {
                    // Don't move to DONE if send failed - will retry next run
                    $this->log('error', '❌ Message not sent, keeping in APPROVED for retry', ['list' => $liste['email']]);
                    continue;
                }
                
                $sendTime = round(microtime(true) - $sendStartTime, 3);
                $this->stats['sent_messages']++;
                $this->stats['sent_emails'] += count($cc);
                $this->log('success', '✅ Message sent successfully to ' . count($cc) . ' recipient(s)', [
                    'list' => $liste['email'],
                    'recipients' => count($cc),
                    'send_time' => $sendTime . 's',
                    'sample' => array_slice($cc, 0, 3)
                ]);
                
                // Mark list as used (update last_used timestamp)
                $this->listManager->markAsUsedByName($liste['nom']);
                
                $this->moveToMailBox($msg, 'DONE');

                // CLEAR TEMP after each message
                $this->clearTemp();
                
                $listTime = round(microtime(true) - $listStartTime, 3);
                $this->log('debug', '✓ List processing complete', ['list' => $liste['nom'], 'total_time' => $listTime . 's']);
                
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->log('error', '❌ Error during list forward: ' . $e->getMessage(), [
                    'list' => $liste['nom'],
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500)
                ]);
            } catch (\Error $e) {
                $this->stats['errors']++;
                $this->log('error', '❌ Fatal error during list forward: ' . $e->getMessage(), [
                    'list' => $liste['nom'],
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500)
                ]);
            }
        }
        
        $forwardTime = round(microtime(true) - $forwardStartTime, 3);
        $this->log('debug', '✓ Forward complete', ['total_time' => $forwardTime . 's', 'lists_processed' => $listCount]);
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
                    
                    $from = $msg->getAddresses('from');
                    $messages[] = [
                        'uid' => sha1($msg->getRawHeaders() . $msg->getMessageBody()),
                        'from' => $from['address'] ?? 'unknown',
                        'from_name' => $from['name'] ?? '',
                        'subject' => $msg->getSubject(),
                        'date' => $msg->getDate(),
                        'body_preview' => substr(strip_tags($msg->getMessageBody()), 0, 200),
                        'lists' => array_keys($this->matchListes($msg))
                    ];
                    
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
        return $this->getMessagesFromFolder('DISCARDED', 20);
    }

    /**
     * Helper to get messages from a folder
     */
    private function getMessagesFromFolder(string $folder, int $limit = 20): array {
        $messages = [];
        
        try {
            $this->imap->setMailBox($folder);
            $uids = $this->imap->getMessageUids($limit);
            
            foreach ($uids as $uid) {
                try {
                    $msg = $this->imap->getMessageByUid($uid);
                    if ($msg === false) continue;
                    
                    $from = $msg->getAddresses('from');
                    $messages[] = [
                        'uid' => $uid,
                        'from' => $from['address'] ?? 'unknown',
                        'from_name' => $from['name'] ?? '',
                        'subject' => $msg->getSubject(),
                        'date' => $msg->getDate(),
                        'body_preview' => substr(strip_tags($msg->getMessageBody()), 0, 200)
                    ];
                    
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
}
