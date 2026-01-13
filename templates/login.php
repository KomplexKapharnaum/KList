<?php
/**
 * Login Template
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?= e($siteTitle) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body>
    <div class="login-wrapper">
        <div class="card login-card">
            <div class="login-header">
                <h1><?= e($siteTitle) ?></h1>
                <p>Gestion des listes de diffusion</p>
            </div>
            
            <div class="card-body">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?= e($error) ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="<?= url('?page=login') ?>">
                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               class="form-control" 
                               placeholder="admin@example.com"
                               required 
                               autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Mot de passe</label>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control" 
                               placeholder="••••••••"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <span class="material-icons">login</span>
                            Connexion
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
