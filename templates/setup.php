<?php
/**
 * Setup Template - First-time installation wizard
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - KList</title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .setup-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: var(--bg-primary);
        }
        .setup-container {
            width: 100%;
            max-width: 700px;
        }
        .setup-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .setup-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }
        .setup-header p {
            color: var(--text-secondary);
        }
        .setup-section {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }
        .setup-section h2 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .setup-section h2 .material-icons {
            font-size: 1.25rem;
            color: var(--primary);
        }
        .setup-note {
            background: rgba(33, 150, 243, 0.1);
            border-left: 3px solid var(--primary);
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .setup-note strong {
            color: var(--text-primary);
        }
        .field-hint {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="setup-wrapper">
        <div class="setup-container">
            <div class="setup-header">
                <h1>📮 KList - Installation</h1>
                <p>Configuration initiale du gestionnaire de listes de diffusion</p>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul style="margin: 0; padding-left: 1.25rem;">
                    <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST">
                <!-- General Settings -->
                <div class="setup-section">
                    <h2>
                        <span class="material-icons">settings</span>
                        Paramètres généraux
                    </h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="site_title">Titre du site</label>
                            <input type="text" 
                                   id="site_title" 
                                   name="site_title" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['site_title']) ?>"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="domains">Domaine email</label>
                            <input type="text" 
                                   id="domains" 
                                   name="domains" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['domains']) ?>"
                                   placeholder="exemple.com"
                                   required>
                            <div class="field-hint">Domaine pour les adresses de listes (liste@domaine.com)</div>
                        </div>
                    </div>
                </div>

                <!-- Admin Credentials -->
                <div class="setup-section">
                    <h2>
                        <span class="material-icons">admin_panel_settings</span>
                        Compte administrateur
                    </h2>
                    
                    <div class="setup-note">
                        <strong>Note :</strong> Cet email sera également utilisé pour recevoir les notifications de modération.
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="admin_email">Email administrateur</label>
                            <input type="email" 
                                   id="admin_email" 
                                   name="admin_email" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['admin_email']) ?>"
                                   placeholder="admin@exemple.com"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="admin_password">Mot de passe</label>
                            <input type="password" 
                                   id="admin_password" 
                                   name="admin_password" 
                                   class="form-control" 
                                   placeholder="Minimum 8 caractères"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="admin_password_confirm">Confirmer le mot de passe</label>
                            <input type="password" 
                                   id="admin_password_confirm" 
                                   name="admin_password_confirm" 
                                   class="form-control" 
                                   placeholder="Répétez le mot de passe"
                                   required>
                        </div>
                    </div>
                </div>

                <!-- IMAP Settings -->
                <div class="setup-section">
                    <h2>
                        <span class="material-icons">inbox</span>
                        Configuration IMAP (réception)
                    </h2>
                    
                    <div class="setup-note">
                        <strong>Boîte mail dédiée :</strong> Utilisez une adresse email dédiée aux listes (ex: listes@domaine.com). 
                        Toutes les listes seront des alias pointant vers cette adresse.
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="imap_host">Serveur IMAP</label>
                            <input type="text" 
                                   id="imap_host" 
                                   name="imap_host" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['imap_host']) ?>"
                                   placeholder="mail.exemple.com"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="imap_port">Port</label>
                            <input type="number" 
                                   id="imap_port" 
                                   name="imap_port" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['imap_port']) ?>"
                                   required>
                            <div class="field-hint">993 pour SSL (recommandé)</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="imap_user">Utilisateur IMAP</label>
                            <input type="text" 
                                   id="imap_user" 
                                   name="imap_user" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['imap_user']) ?>"
                                   placeholder="listes@exemple.com"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="imap_password">Mot de passe IMAP</label>
                            <input type="password" 
                                   id="imap_password" 
                                   name="imap_password" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['imap_password']) ?>"
                                   required>
                        </div>
                    </div>
                </div>

                <!-- SMTP Settings -->
                <div class="setup-section">
                    <h2>
                        <span class="material-icons">send</span>
                        Configuration SMTP (envoi)
                    </h2>
                    
                    <div class="setup-note">
                        <strong>Test de connexion :</strong> Un email de test sera envoyé à l'adresse administrateur pour valider la configuration SMTP.
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="smtp_host">Serveur SMTP</label>
                            <input type="text" 
                                   id="smtp_host" 
                                   name="smtp_host" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['smtp_host']) ?>"
                                   placeholder="mail.exemple.com"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="smtp_port">Port</label>
                            <input type="number" 
                                   id="smtp_port" 
                                   name="smtp_port" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['smtp_port']) ?>"
                                   required>
                            <div class="field-hint">587 pour TLS, 465 pour SSL</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="smtp_user">Utilisateur SMTP</label>
                            <input type="text" 
                                   id="smtp_user" 
                                   name="smtp_user" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['smtp_user']) ?>"
                                   placeholder="listes@exemple.com"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="smtp_password">Mot de passe SMTP</label>
                            <input type="password" 
                                   id="smtp_password" 
                                   name="smtp_password" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($formData['smtp_password']) ?>"
                                   required>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="text-center">
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1rem;">
                        <span class="material-icons">check_circle</span>
                        Valider et installer
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
