<?php
/**
 * Settings Template
 */

$pageTitle = 'Paramètres';

ob_start();
?>

<h1 class="mb-3">Paramètres</h1>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul style="margin: 0; padding-left: 1.25rem;">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- General Settings -->
<div class="card">
    <div class="card-header">
        <h2>Paramètres généraux</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="section" value="general">
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="site_title">Titre du site</label>
                    <input type="text" 
                           id="site_title" 
                           name="site_title" 
                           class="form-control" 
                           value="<?= e($settings['site_title'] ?? '') ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="domains">Domaine email</label>
                    <input type="text" 
                           id="domains" 
                           name="domains" 
                           class="form-control" 
                           value="<?= e($settings['domains'] ?? '') ?>"
                           placeholder="example.com"
                           required>
                    <small class="text-muted">Domaine pour les adresses de listes (liste@domaine.com)</small>
                </div>
            </div>
            
            <div class="text-right">
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">save</span>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Admin Credentials -->
<div class="card">
    <div class="card-header">
        <h2>Identifiants administrateur</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="section" value="admin">
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="admin_email">Email administrateur</label>
                    <input type="email" 
                           id="admin_email" 
                           name="admin_email" 
                           class="form-control" 
                           value="<?= e($settings['admin_email'] ?? '') ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="admin_password">Nouveau mot de passe</label>
                    <input type="password" 
                           id="admin_password" 
                           name="admin_password" 
                           class="form-control" 
                           placeholder="Laisser vide pour ne pas changer">
                </div>
            </div>
            
            <div class="text-right">
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">save</span>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- IMAP Settings -->
<div class="card">
    <div class="card-header">
        <h2>Configuration IMAP (réception)</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="section" value="imap">
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="imap_host">Serveur IMAP</label>
                    <input type="text" 
                           id="imap_host" 
                           name="imap_host" 
                           class="form-control" 
                           value="<?= e($settings['imap_host'] ?? '') ?>"
                           placeholder="mail.example.com"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="imap_port">Port</label>
                    <input type="number" 
                           id="imap_port" 
                           name="imap_port" 
                           class="form-control" 
                           value="<?= e($settings['imap_port'] ?? '993') ?>"
                           required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="imap_user">Utilisateur IMAP</label>
                    <input type="text" 
                           id="imap_user" 
                           name="imap_user" 
                           class="form-control" 
                           value="<?= e($settings['imap_user'] ?? '') ?>"
                           placeholder="listes@example.com"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="imap_password">Mot de passe IMAP</label>
                    <input type="password" 
                           id="imap_password" 
                           name="imap_password" 
                           class="form-control" 
                           value="<?= e($settings['imap_password'] ?? '') ?>"
                           placeholder="••••••••">
                </div>
            </div>
            
            <div class="text-right">
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">save</span>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- SMTP Settings -->
<div class="card">
    <div class="card-header">
        <h2>Configuration SMTP (envoi)</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="section" value="smtp">
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="smtp_host">Serveur SMTP</label>
                    <input type="text" 
                           id="smtp_host" 
                           name="smtp_host" 
                           class="form-control" 
                           value="<?= e($settings['smtp_host'] ?? '') ?>"
                           placeholder="mail.example.com"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="smtp_port">Port</label>
                    <input type="number" 
                           id="smtp_port" 
                           name="smtp_port" 
                           class="form-control" 
                           value="<?= e($settings['smtp_port'] ?? '587') ?>"
                           required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="smtp_user">Utilisateur SMTP</label>
                    <input type="text" 
                           id="smtp_user" 
                           name="smtp_user" 
                           class="form-control" 
                           value="<?= e($settings['smtp_user'] ?? '') ?>"
                           placeholder="listes@example.com"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="smtp_password">Mot de passe SMTP</label>
                    <input type="password" 
                           id="smtp_password" 
                           name="smtp_password" 
                           class="form-control" 
                           value="<?= e($settings['smtp_password'] ?? '') ?>"
                           placeholder="••••••••">
                </div>
            </div>
            
            <div class="text-right">
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">save</span>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cron Settings -->
<div class="card">
    <div class="card-header">
        <h2>Configuration Cron</h2>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="section" value="cron">
            
            <div class="form-group">
                <label class="form-label" for="cron_key">Clé de sécurité cron</label>
                <div class="flex gap-2">
                    <input type="text" 
                           id="cron_key" 
                           name="cron_key" 
                           class="form-control" 
                           value="<?= e($settings['cron_key'] ?? '') ?>"
                           readonly>
                    <button type="button" 
                            onclick="this.form.cron_key.value = [...Array(32)].map(() => Math.random().toString(36)[2]).join('')"
                            class="btn btn-secondary">
                        <span class="material-icons">refresh</span>
                    </button>
                </div>
            </div>
            
            <div class="alert alert-info">
                <strong>URL du cron:</strong><br>
                <code style="word-break: break-all;">
                    <?= e((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . url('cron.php?key=' . ($settings['cron_key'] ?? ''))) ?>
                </code>
            </div>
            
            <div class="text-right">
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">save</span>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
