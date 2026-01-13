<?php
/**
 * KXKM Mailing List Manager - Main Entry Point
 * Pure PHP version with SQLite database
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Check if application needs setup
if (!is_installed()) {
    handleSetup();
    exit;
}

// Simple routing
$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$name = $_GET['name'] ?? null;

// Public routes (no auth required)
$publicRoutes = ['login', 'cron'];

// Check authentication
if (!in_array($page, $publicRoutes)) {
    require_login();
}

// Route to appropriate handler
switch ($page) {
    case 'login':
        handleLogin();
        break;
    
    case 'logout':
        handleLogout();
        break;
    
    case 'dashboard':
        handleDashboard();
        break;
    
    case 'list':
        handleList($action, $id, $name);
        break;
    
    case 'settings':
        handleSettings();
        break;
    
    case 'export':
        handleExport($action, $id, $name);
        break;
    
    case 'subscribers':
        handleSubscribers();
        break;
    
    case 'moderation':
        handleModeration($action);
        break;
    
    case 'errors':
        handleErrors();
        break;
    
    default:
        header('Location: ' . url('?page=dashboard'));
        exit;
}

// ============================================================================
// Route Handlers
// ============================================================================

function handleSetup(): void {
    $errors = [];
    $formData = [
        'site_title' => 'Mailing List Manager',
        'domains' => '',
        'admin_email' => '',
        'admin_password' => '',
        'admin_password_confirm' => '',
        'imap_host' => '',
        'imap_port' => '993',
        'imap_user' => '',
        'imap_password' => '',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_user' => '',
        'smtp_password' => '',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Get form data
        foreach ($formData as $key => $default) {
            $formData[$key] = trim($_POST[$key] ?? $default);
        }

        // Validate required fields
        if (empty($formData['admin_email'])) {
            $errors[] = 'L\'email administrateur est requis.';
        } elseif (!filter_var($formData['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'L\'email administrateur n\'est pas valide.';
        }

        if (empty($formData['admin_password'])) {
            $errors[] = 'Le mot de passe administrateur est requis.';
        } elseif (strlen($formData['admin_password']) < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($formData['admin_password'] !== $formData['admin_password_confirm']) {
            $errors[] = 'Les mots de passe ne correspondent pas.';
        }

        if (empty($formData['domains'])) {
            $errors[] = 'Le domaine email est requis.';
        }

        if (empty($formData['imap_host']) || empty($formData['imap_user']) || empty($formData['imap_password'])) {
            $errors[] = 'Tous les champs IMAP sont requis.';
        }

        if (empty($formData['smtp_host']) || empty($formData['smtp_user']) || empty($formData['smtp_password'])) {
            $errors[] = 'Tous les champs SMTP sont requis.';
        }

        // If basic validation passes, test connections
        if (empty($errors)) {
            // Test IMAP connection
            $imapError = testImapConnection(
                $formData['imap_host'],
                (int)$formData['imap_port'],
                $formData['imap_user'],
                $formData['imap_password']
            );
            if ($imapError) {
                $errors[] = 'Connexion IMAP échouée : ' . $imapError;
            }
        }

        if (empty($errors)) {
            // Test SMTP by sending a test email
            $smtpError = testSmtpConnection(
                $formData['smtp_host'],
                (int)$formData['smtp_port'],
                $formData['smtp_user'],
                $formData['smtp_password'],
                $formData['admin_email']
            );
            if ($smtpError) {
                $errors[] = 'Connexion SMTP échouée : ' . $smtpError;
            }
        }

        // If all validations pass, create database
        if (empty($errors)) {
            try {
                Database::initialize([
                    'site_title' => $formData['site_title'],
                    'domains' => $formData['domains'],
                    'admin_email' => $formData['admin_email'],
                    'admin_password' => password_hash($formData['admin_password'], PASSWORD_DEFAULT),
                    'imap_host' => $formData['imap_host'],
                    'imap_port' => $formData['imap_port'],
                    'imap_user' => $formData['imap_user'],
                    'imap_password' => $formData['imap_password'],
                    'smtp_host' => $formData['smtp_host'],
                    'smtp_port' => $formData['smtp_port'],
                    'smtp_user' => $formData['smtp_user'],
                    'smtp_password' => $formData['smtp_password'],
                    'cron_key' => bin2hex(random_bytes(16)),
                ]);

                // Redirect to login
                header('Location: ' . url('?page=login'));
                exit;
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la création de la base de données : ' . $e->getMessage();
            }
        }
    }

    // Render setup template
    include TEMPLATES_PATH . '/setup.php';
}

/**
 * Test IMAP connection
 */
function testImapConnection(string $host, int $port, string $user, string $password): ?string {
    try {
        // Load Fetch library
        require_once ROOT_PATH . '/lib/Fetch/Server.php';
        require_once ROOT_PATH . '/lib/Fetch/Message.php';
        require_once ROOT_PATH . '/lib/Fetch/MIME.php';
        require_once ROOT_PATH . '/lib/Fetch/Attachment.php';
        
        $server = new \Fetch\Server($host, $port);
        $server->setAuthentication($user, $password);
        
        // Try to check if INBOX exists (this will trigger connection)
        $server->hasMailBox('INBOX');
        
        return null; // Success
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

/**
 * Test SMTP connection by sending a test email
 */
function testSmtpConnection(string $host, int $port, string $user, string $password, string $adminEmail): ?string {
    try {
        // Load PHPMailer
        require_once ROOT_PATH . '/lib/PHPMailer/class.phpmailer.php';
        require_once ROOT_PATH . '/lib/PHPMailer/class.smtp.php';
        
        $mail = new \PHPMailer();
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $password;
        $mail->SMTPSecure = $port == 465 ? 'ssl' : 'tls';
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom($user, 'KList Setup');
        $mail->addAddress($adminEmail);
        $mail->Subject = '✅ KList - Configuration réussie';
        $mail->Body = "Félicitations !\n\nVotre serveur SMTP est correctement configuré.\n\nCet email confirme que KList peut envoyer des emails via votre serveur.\n\n--\nKList Mailing List Manager";
        $mail->isHTML(false);
        
        if (!$mail->send()) {
            return $mail->ErrorInfo;
        }
        
        return null; // Success
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function handleLogin(): void {
    // Already logged in?
    if (is_logged_in()) {
        header('Location: ' . url('?page=dashboard'));
        exit;
    }

    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        $auth = new Auth();
        $result = $auth->login($email, $password);
        
        if ($result === true) {
            header('Location: ' . url('?page=dashboard'));
            exit;
        } elseif (is_string($result)) {
            // Rate limiting message
            $error = $result;
        } else {
            $error = 'Email ou mot de passe incorrect.';
        }
    }

    render('login', ['error' => $error]);
}

function handleLogout(): void {
    $auth = new Auth();
    $auth->logout();
    header('Location: ' . url('?page=login'));
    exit;
}

function handleDashboard(): void {
    $listManager = new ListManager();
    $db = Database::getInstance();
    $lists = $listManager->getAll(); // Get full list data including last_used
    
    // Get last cron run time
    $lastCronRun = $db->getSetting('last_cron_run');
    $cronKey = $db->getSetting('cron_key');
    
    // Get unique subscriber count (not sum of each list)
    $uniqueSubscribers = $listManager->getUniqueSubscriberCount();
    
    render('dashboard', [
        'lists' => $lists,
        'lastCronRun' => $lastCronRun,
        'cronKey' => $cronKey,
        'uniqueSubscribers' => $uniqueSubscribers,
        'currentPage' => 'dashboard'
    ]);
}

function handleList(?string $action, ?int $id, ?string $name): void {
    $listManager = new ListManager();

    // Load list by name or ID
    $list = null;
    if ($name) {
        $list = $listManager->getByName($name);
        if ($list) {
            $id = $list['id'];
        }
    } elseif ($id) {
        $list = $listManager->getById($id);
    }

    // Handle actions
    switch ($action) {
        case 'new':
            handleListEdit($listManager, null);
            return;

        case 'edit':
            if (!$list && !$id) {
                handleListEdit($listManager, null);
            } else {
                handleListEdit($listManager, $list);
            }
            return;

        case 'delete':
            if ($list && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (verify_csrf($_POST['csrf_token'] ?? '')) {
                    $listManager->delete($id);
                    flash('success', 'Liste supprimée.');
                }
            }
            header('Location: ' . url('?page=dashboard'));
            exit;

        case 'add-subscribers':
            if ($list && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (verify_csrf($_POST['csrf_token'] ?? '')) {
                    $result = $listManager->addSubscribers($id, $_POST['emails'] ?? '');
                    if (empty($result['errors'])) {
                        flash('success', $result['added'] . ' abonné(s) ajouté(s).');
                    } else {
                        flash('error', implode('<br>', $result['errors']));
                    }
                }
            }
            header('Location: ' . url('?page=list&action=edit&name=' . urlencode($list['nom'])));
            exit;

        case 'remove-subscriber':
            if ($list && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (verify_csrf($_POST['csrf_token'] ?? '')) {
                    $email = $_POST['email'] ?? '';
                    $listManager->removeSubscriber($id, $email);
                    flash('success', 'Abonné supprimé.');
                }
            }
            header('Location: ' . url('?page=list&action=edit&name=' . urlencode($list['nom'])));
            exit;

        default:
            // Show list details or redirect
            if ($list) {
                header('Location: ' . url('?page=list&action=edit&name=' . urlencode($list['nom'])));
            } else {
                header('Location: ' . url('?page=dashboard'));
            }
            exit;
    }
}

function handleListEdit(ListManager $listManager, ?array $list): void {
    $errors = [];
    $formData = $list ?? [
        'nom' => '',
        'description' => '',
        'moderation' => 0,
        'reponse' => 0,
        'active' => 1
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
        if (!verify_csrf($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Token CSRF invalide.';
        } else {
            $formData = [
                'nom' => $_POST['nom'] ?? '',
                'description' => $_POST['description'] ?? '',
                'moderation' => isset($_POST['moderation']) ? 1 : 0,
                'reponse' => isset($_POST['reponse']) ? 1 : 0,
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            if ($list) {
                // Update existing
                $result = $listManager->update($list['id'], $formData);
            } else {
                // Create new
                $result = $listManager->create($formData);
            }

            if ($result['success']) {
                flash('success', $list ? 'Liste mise à jour.' : 'Liste créée.');
                $name = strtolower($formData['nom']);
                header('Location: ' . url('?page=list&action=edit&name=' . urlencode($name)));
                exit;
            } else {
                $errors = $result['errors'];
            }
        }
    }

    // Get subscribers if editing existing list
    $subscribers = [];
    if ($list) {
        $subscribers = $listManager->getSubscribers($list['id']);
        // Merge updated form data back
        $list = array_merge($list, $formData);
    }

    render('list-edit', [
        'list' => $list,
        'formData' => $formData,
        'subscribers' => $subscribers,
        'errors' => $errors,
        'lists' => $listManager->getAllLabels(),
        'currentPage' => $list ? $list['nom'] : 'new',
        'isNew' => $list === null
    ]);
}

function handleSettings(): void {
    $db = Database::getInstance();
    $auth = new Auth();
    $errors = [];
    $success = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Token CSRF invalide.';
        } else {
            $section = $_POST['section'] ?? '';

            switch ($section) {
                case 'general':
                    $db->setSettings([
                        'site_title' => $_POST['site_title'] ?? '',
                        'domains' => $_POST['domains'] ?? '',
                    ]);
                    flash('success', 'Paramètres généraux mis à jour.');
                    break;

                case 'admin':
                    $auth->updateCredentials(
                        $_POST['admin_email'] ?? '',
                        $_POST['admin_password'] ?? null
                    );
                    flash('success', 'Identifiants administrateur mis à jour.');
                    break;

                case 'imap':
                    // Test IMAP connection first
                    $imapError = testImapConnection(
                        $_POST['imap_host'] ?? '',
                        (int)($_POST['imap_port'] ?? 993),
                        $_POST['imap_user'] ?? '',
                        $_POST['imap_password'] ?? ''
                    );
                    
                    if ($imapError) {
                        $errors[] = 'Connexion IMAP échouée : ' . $imapError;
                    } else {
                        $db->setSettings([
                            'imap_host' => $_POST['imap_host'] ?? '',
                            'imap_port' => $_POST['imap_port'] ?? '993',
                            'imap_user' => $_POST['imap_user'] ?? '',
                            'imap_password' => $_POST['imap_password'] ?? '',
                        ]);
                        flash('success', 'Paramètres IMAP mis à jour et connexion validée.');
                    }
                    break;

                case 'smtp':
                    // Test SMTP by sending email to admin
                    $adminEmail = $db->getSetting('admin_email');
                    $smtpError = testSmtpConnection(
                        $_POST['smtp_host'] ?? '',
                        (int)($_POST['smtp_port'] ?? 587),
                        $_POST['smtp_user'] ?? '',
                        $_POST['smtp_password'] ?? '',
                        $adminEmail
                    );
                    
                    if ($smtpError) {
                        $errors[] = 'Connexion SMTP échouée : ' . $smtpError;
                    } else {
                        $db->setSettings([
                            'smtp_host' => $_POST['smtp_host'] ?? '',
                            'smtp_port' => $_POST['smtp_port'] ?? '587',
                            'smtp_user' => $_POST['smtp_user'] ?? '',
                            'smtp_password' => $_POST['smtp_password'] ?? '',
                        ]);
                        flash('success', 'Paramètres SMTP mis à jour. Un email de test a été envoyé à ' . $adminEmail);
                    }
                    break;

                case 'cron':
                    $db->setSetting('cron_key', $_POST['cron_key'] ?? bin2hex(random_bytes(16)));
                    flash('success', 'Clé cron mise à jour.');
                    break;
            }

            if (empty($errors)) {
                header('Location: ' . url('?page=settings'));
                exit;
            }
        }
    }

    $listManager = new ListManager();
    
    render('settings', [
        'settings' => $db->getSettings(),
        'errors' => $errors,
        'lists' => $listManager->getAllLabels(),
        'currentPage' => 'settings'
    ]);
}

function handleExport(?string $action, ?int $id, ?string $name): void {
    $listManager = new ListManager();

    if ($action === 'subscribers' && $name) {
        $list = $listManager->getByName($name);
        if ($list) {
            $csv = $listManager->exportSubscribersCSV($list['id']);
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $list['nom'] . '.csv"');
            echo $csv;
            exit;
        }
    }

    header('Location: ' . url('?page=dashboard'));
    exit;
}

function handleSubscribers(): void {
    $listManager = new ListManager();
    $query = $_GET['q'] ?? '';
    $results = [];
    
    // Handle unsubscribe action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsubscribe'])) {
        if (verify_csrf($_POST['csrf_token'] ?? '')) {
            $email = $_POST['email'] ?? '';
            $listeId = (int)($_POST['liste_id'] ?? 0);
            if ($email && $listeId) {
                $listManager->removeSubscriber($listeId, $email);
                flash('success', 'Abonné supprimé de la liste.');
            }
        }
        header('Location: ' . url('?page=subscribers&q=' . urlencode($query)));
        exit;
    }
    
    // Handle unsubscribe from ALL lists
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsubscribe_all'])) {
        if (verify_csrf($_POST['csrf_token'] ?? '')) {
            $email = $_POST['email'] ?? '';
            if ($email) {
                $count = $listManager->unsubscribeFromAll($email);
                flash('success', 'Abonné supprimé de ' . $count . ' liste(s).');
            }
        }
        header('Location: ' . url('?page=subscribers&q=' . urlencode($query)));
        exit;
    }
    
    // Search if query provided
    if (!empty($query)) {
        $results = $listManager->searchSubscriber($query);
    }
    
    render('subscribers', [
        'query' => $query,
        'results' => $results,
        'lists' => $listManager->getAllLabels(),
        'currentPage' => 'subscribers'
    ]);
}

function handleModeration(?string $action): void {
    $db = Database::getInstance();
    $listManager = new ListManager();
    
    // Handle approve/discard actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'approve' || $action === 'discard')) {
        if (verify_csrf($_POST['csrf_token'] ?? '')) {
            $uid = $_POST['uid'] ?? '';
            $cronKey = $db->getSetting('cron_key');
            
            // Call the cron endpoint to process
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
            
            $url = $baseUrl . 'cron.php?key=' . urlencode($cronKey) . '&action=' . $action . '&uid=' . urlencode($uid);
            
            // Use file_get_contents with context to call the cron
            $context = stream_context_create(['http' => ['timeout' => 30]]);
            @file_get_contents($url, false, $context);
            
            flash('success', $action === 'approve' ? 'Message approuvé.' : 'Message refusé.');
        }
        header('Location: ' . url('?page=moderation'));
        exit;
    }
    
    // Get pending messages from IMAP
    $messages = [];
    $error = null;
    
    try {
        $processor = new MailProcessor();
        if ($processor->connect()) {
            $messages = $processor->getPendingMessages();
        }
    } catch (Exception $e) {
        $error = 'Erreur de connexion IMAP: ' . $e->getMessage();
    }
    
    render('moderation', [
        'messages' => $messages,
        'error' => $error,
        'lists' => $listManager->getAllLabels(),
        'currentPage' => 'moderation'
    ]);
}

function handleErrors(): void {
    $listManager = new ListManager();
    
    // Get error and discarded messages from IMAP
    $errors = [];
    $discarded = [];
    $errorMsg = null;
    
    try {
        $processor = new MailProcessor();
        if ($processor->connect()) {
            $errors = $processor->getErrorMessages();
            $discarded = $processor->getDiscardedMessages();
        }
    } catch (Exception $e) {
        $errorMsg = 'Erreur de connexion IMAP: ' . $e->getMessage();
    }
    
    render('errors', [
        'errors' => $errors,
        'discarded' => $discarded,
        'errorMsg' => $errorMsg,
        'lists' => $listManager->getAllLabels(),
        'currentPage' => 'errors'
    ]);
}

// ============================================================================
// Template Rendering
// ============================================================================

function render(string $template, array $data = []): void {
    global $settings;
    
    $data['siteTitle'] = setting('site_title', 'Mailing List Manager');
    $data['flash'] = get_flash();
    
    extract($data);
    
    // Load template
    $templateFile = TEMPLATES_PATH . '/' . $template . '.php';
    if (file_exists($templateFile)) {
        include $templateFile;
    } else {
        echo "Template not found: {$template}";
    }
}
