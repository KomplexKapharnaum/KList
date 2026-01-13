<?php
/**
 * KXKM Mailing List Manager - Cron Endpoint
 * 
 * This script handles email processing when called by cron.
 * URL: /cron.php?key=YOUR_CRON_KEY
 * 
 * Actions:
 *   - (none): Process inbox and forward approved messages
 *   - approve: Approve a pending message (requires uid parameter)
 *   - discard: Discard a pending message (requires uid parameter)
 */

declare(strict_types=1);

// Error reporting (logged, not displayed in production)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '<div style="margin: 20px; padding: 20px; background: #3d1e1e; border: 2px solid #f44336; border-radius: 8px;">';
        echo '<h3 style="color: #f44336;">💥 FATAL ERROR</h3>';
        echo '<p style="color: #eee;"><strong>Message:</strong> ' . htmlspecialchars($error['message']) . '</p>';
        echo '<p style="color: #999;"><strong>File:</strong> ' . htmlspecialchars($error['file']) . ' (line ' . $error['line'] . ')</p>';
        echo '</div>';
        echo '</div></div></body></html>';
    }
});

require_once __DIR__ . '/config.php';

// Check if application is installed
if (!is_installed()) {
    http_response_code(503);
    die('Service unavailable: Application not installed');
}

// Get parameters
$key = $_GET['key'] ?? '';
$action = $_GET['action'] ?? '';
$uid = $_GET['uid'] ?? '';

// Verify cron key
$storedKey = $db->getSetting('cron_key');
if (empty($storedKey) || $key !== $storedKey) {
    http_response_code(403);
    die('Forbidden: Invalid cron key');
}

// Set content type for HTML output
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KXKM Mailing List - Cron Processing</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #e0e0e0;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #1e1e1e;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #2d7d3a 0%, #1f5a2a 100%);
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        .header .subtitle {
            opacity: 0.9;
            font-size: 1.1em;
        }
        .content {
            padding: 30px;
        }
        .section {
            background: #252525;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #333;
        }
        .section-title {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #4a9d58;
            border-bottom: 2px solid #2d7d3a;
            padding-bottom: 10px;
        }
        .log-container {
            background: #1a1a1a;
            border-radius: 6px;
            padding: 15px;
            /* max-height: 600px; */
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.95em;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .stat-card {
            background: #2a2a2a;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            border: 1px solid #404040;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #4a9d58;
        }
        .stat-value {
            font-size: 2.5em;
            font-weight: bold;
            color: #4a9d58;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 0.9em;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .footer {
            padding: 20px 30px;
            background: #252525;
            text-align: center;
            color: #666;
            border-top: 1px solid #333;
        }
        .error-box {
            background: #3d1e1e;
            border: 2px solid #f44336;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .error-box h3 {
            color: #f44336;
            margin-bottom: 10px;
        }
        ::-webkit-scrollbar {
            width: 10px;
        }
        ::-webkit-scrollbar-track {
            background: #1a1a1a;
        }
        ::-webkit-scrollbar-thumb {
            background: #4a9d58;
            border-radius: 5px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #2d7d3a;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 KXKM Mailing List Manager</h1>
            <div class="subtitle">Email Processing - Cron Execution</div>
        </div>
        <div class="content">
<?php

try {
    $startTime = microtime(true);
    
    // Record cron execution start time
    $db->setSetting('last_cron_run', date('Y-m-d H:i:s'));
    
    echo '<div class="section">';
    echo '<h2 class="section-title">📋 Processing Log</h2>';
    echo '<div class="log-container">';
    
    $processor = new MailProcessor();
    
    if (!$processor->connect()) {
        throw new Exception('Failed to connect to IMAP server');
    }
    
    switch ($action) {
        case 'approve':
            if (empty($uid)) {
                echo '<div style="color: #f44336;">❌ Error: Missing uid parameter</div>';
            } elseif ($processor->approve($uid)) {
                echo '<div style="color: #4caf50;">✅ Message approved and processed</div>';
            } else {
                echo '<div style="color: #ff9800;">⚠️ Message not found or already processed</div>';
            }
            break;
            
        case 'discard':
            if (empty($uid)) {
                echo '<div style="color: #f44336;">❌ Error: Missing uid parameter</div>';
            } elseif ($processor->discard($uid)) {
                echo '<div style="color: #4caf50;">✅ Message discarded</div>';
            } else {
                echo '<div style="color: #ff9800;">⚠️ Message not found or already processed</div>';
            }
            break;
            
        default:
            // Normal processing
            $processor->process();
            break;
    }
    
    echo '</div>'; // log-container
    echo '</div>'; // section
    
    // Display statistics
    $stats = $processor->getStats();
    $hasErrors = $stats['errors'] > 0;
    
    echo '<div class="section">';
    echo '<h2 class="section-title">📊 Statistics</h2>';
    echo '<div class="stats-grid">';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value">' . $stats['inbox_processed'] . '</div>';
    echo '<div class="stat-label">Inbox Processed</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value">' . $stats['sent_messages'] . '</div>';
    echo '<div class="stat-label">Messages Sent</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value">' . $stats['sent_emails'] . '</div>';
    echo '<div class="stat-label">Total Recipients</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value">' . $stats['pending'] . '</div>';
    echo '<div class="stat-label">Pending Moderation</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value">' . $stats['blocked'] . '</div>';
    echo '<div class="stat-label">Blocked</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value">' . $stats['discarded'] . '</div>';
    echo '<div class="stat-label">Discarded</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value" style="color: ' . ($hasErrors ? '#f44336' : '#4caf50') . ';">' . $stats['errors'] . '</div>';
    echo '<div class="stat-label">Errors</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-value">' . $stats['total_time'] . 's</div>';
    echo '<div class="stat-label">Execution Time</div>';
    echo '</div>';
    
    echo '</div>'; // stats-grid
    echo '</div>'; // section
    
    // Send error notification email if errors occurred
    if ($hasErrors) {
        $adminEmail = $processor->db->getSetting('admin_email');
        if ($adminEmail) {
            $errorLog = array_filter($processor->getLog(), fn($entry) => $entry['level'] === 'error');
            if (!empty($errorLog)) {
                $mail = new PHPMailer();
                $mail->isSMTP();
                $mail->Host = $processor->db->getSetting('smtp_host');
                $mail->Port = (int)$processor->db->getSetting('smtp_port', '587');
                $mail->SMTPAuth = true;
                $mail->Username = $processor->db->getSetting('imap_user');
                $mail->Password = $processor->db->getSetting('smtp_password');
                $mail->setFrom($processor->db->getSetting('imap_user'), 'KXKM Listes - Error Reporter');
                $mail->addAddress($adminEmail);
                $mail->Subject = '[KXKM Listes] Processing Errors - ' . date('Y-m-d H:i:s');
                $mail->isHTML(true);
                
                $errorHtml = '<h2 style="color: #f44336;">Email Processing Errors</h2>';
                $errorHtml .= '<p><strong>Time:</strong> ' . date('Y-m-d H:i:s') . '</p>';
                $errorHtml .= '<p><strong>Total Errors:</strong> ' . $stats['errors'] . '</p>';
                $errorHtml .= '<hr><h3>Error Details:</h3>';
                foreach ($errorLog as $error) {
                    $errorHtml .= '<div style="background: #ffe6e6; padding: 10px; margin: 10px 0; border-left: 3px solid #f44336;">';
                    $errorHtml .= '<strong>[' . $error['time'] . ']</strong> ' . htmlspecialchars($error['message']);
                    if (!empty($error['context'])) {
                        $errorHtml .= '<br><small>' . json_encode($error['context']) . '</small>';
                    }
                    $errorHtml .= '</div>';
                }
                
                $mail->msgHTML($errorHtml);
                $mail->send();
                
                echo '<div class="error-box">';
                echo '<h3>⚠️ Error Notification Sent</h3>';
                echo '<p>An error report has been sent to the administrator at: ' . htmlspecialchars($adminEmail) . '</p>';
                echo '</div>';
            }
        }
    }
    
} catch (Exception $e) {
    echo '<div class="error-box">';
    echo '<h3>❌ Fatal Error</h3>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<pre style="color: #999; margin-top: 10px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</div>';
    
    // Send critical error email
    $adminEmail = $db->getSetting('admin_email');
    if ($adminEmail) {
        $mail = new PHPMailer();
        $mail->isSMTP();
        $mail->Host = $db->getSetting('smtp_host');
        $mail->Port = (int)$db->getSetting('smtp_port', '587');
        $mail->SMTPAuth = true;
        $mail->Username = $db->getSetting('imap_user');
        $mail->Password = $db->getSetting('smtp_password');
        $mail->setFrom($db->getSetting('imap_user'), 'KXKM Listes - Error Reporter');
        $mail->addAddress($adminEmail);
        $mail->Subject = '[KXKM Listes] CRITICAL ERROR - ' . date('Y-m-d H:i:s');
        $mail->isHTML(true);
        $mail->msgHTML('<h2 style="color: #f44336;">CRITICAL ERROR</h2>' .
            '<p><strong>Time:</strong> ' . date('Y-m-d H:i:s') . '</p>' .
            '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>' .
            '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>');
        @$mail->send();
    }
}

?>
        </div>
        <div class="footer">
            <p>Processed at: <?= date('Y-m-d H:i:s') ?></p>
            <p style="margin-top: 5px; font-size: 0.9em;">KXKM Mailing List Manager v2.0</p>
        </div>
    </div>
</body>
</html>
