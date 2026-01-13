<?php
/**
 * Layout Template - Base wrapper for authenticated pages
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= e($pageTitle ?? 'Dashboard') ?> - <?= e($siteTitle) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <!-- Material Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1><?= e($siteTitle) ?></h1>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <ul class="nav-list">
                        <li class="nav-item <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>">
                            <a href="<?= url('?page=dashboard') ?>">
                                <span class="icon material-icons">dashboard</span>
                                <span>Accueil</span>
                            </a>
                        </li>
                        <li class="nav-item <?= ($currentPage ?? '') === 'subscribers' ? 'active' : '' ?>">
                            <a href="<?= url('?page=subscribers') ?>">
                                <span class="icon material-icons">search</span>
                                <span>Recherche abonné</span>
                            </a>
                        </li>
                        <li class="nav-item <?= ($currentPage ?? '') === 'moderation' ? 'active' : '' ?>">
                            <a href="<?= url('?page=moderation') ?>">
                                <span class="icon material-icons">pending_actions</span>
                                <span>Modération</span>
                            </a>
                        </li>
                        <li class="nav-item <?= ($currentPage ?? '') === 'errors' ? 'active' : '' ?>">
                            <a href="<?= url('?page=errors') ?>">
                                <span class="icon material-icons">error_outline</span>
                                <span>Erreurs</span>
                            </a>
                        </li>
                        <li class="nav-item <?= ($currentPage ?? '') === 'settings' ? 'active' : '' ?>">
                            <a href="<?= url('?page=settings') ?>">
                                <span class="icon material-icons">settings</span>
                                <span>Paramètres</span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Listes</div>
                    <ul class="nav-list">
                        <li class="nav-item <?= ($currentPage ?? '') === 'new' ? 'active' : '' ?>">
                            <a href="<?= url('?page=list&action=new') ?>">
                                <span class="icon material-icons">add_circle</span>
                                <span>Nouvelle liste</span>
                            </a>
                        </li>
                        <?php if (!empty($lists)): ?>
                        <?php foreach ($lists as $list): ?>
                        <li class="nav-item <?= ($currentPage ?? '') === $list['nom'] ? 'active' : '' ?>">
                            <a href="<?= url('?page=list&action=edit&name=' . urlencode($list['nom'])) ?>">
                                <span class="icon material-icons">mail</span>
                                <span><?= e($list['nom']) ?></span>
                                <span class="count"><?= $list['count'] ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>
            
            <div class="sidebar-footer">
                <a href="<?= url('?page=logout') ?>">
                    <span class="material-icons">logout</span>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
                <?= $flash['message'] ?>
            </div>
            <?php endif; ?>
            
            <?= $content ?? '' ?>
        </main>
    </div>
    
    <script>
        // Confirm delete dialogs
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', e => {
                if (!confirm(el.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
