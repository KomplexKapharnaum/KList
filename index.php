<?php
/**
 * KXKM Mailing List Manager - Main Entry Point
 * Pure PHP version with SQLite database
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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
                    $db->setSettings([
                        'imap_host' => $_POST['imap_host'] ?? '',
                        'imap_port' => $_POST['imap_port'] ?? '993',
                        'imap_user' => $_POST['imap_user'] ?? '',
                        'imap_password' => $_POST['imap_password'] ?? '',
                    ]);
                    flash('success', 'Paramètres IMAP mis à jour.');
                    break;

                case 'smtp':
                    $db->setSettings([
                        'smtp_host' => $_POST['smtp_host'] ?? '',
                        'smtp_port' => $_POST['smtp_port'] ?? '587',
                        'smtp_user' => $_POST['smtp_user'] ?? '',
                        'smtp_password' => $_POST['smtp_password'] ?? '',
                    ]);
                    flash('success', 'Paramètres SMTP mis à jour.');
                    break;

                case 'cron':
                    $db->setSetting('cron_key', $_POST['cron_key'] ?? bin2hex(random_bytes(16)));
                    flash('success', 'Clé cron mise à jour.');
                    break;
            }

            header('Location: ' . url('?page=settings'));
            exit;
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
