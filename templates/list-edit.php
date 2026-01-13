<?php
/**
 * List Edit Template
 */

$pageTitle = $isNew ? 'Nouvelle liste' : $list['nom'];

ob_start();
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul style="margin: 0; padding-left: 1.25rem;">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- List Settings Card -->
<div class="card">
    <div class="card-header">
        <h2><?= $isNew ? 'Nouvelle liste' : e($list['nom']) ?></h2>
        <?php if (!$isNew): ?>
        <div class="actions">
            <form method="POST" action="<?= url('?page=list&action=delete&id=' . $list['id']) ?>" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" 
                        class="btn btn-sm btn-icon danger" 
                        data-confirm="Supprimer la liste <?= e($list['nom']) ?> et tous ses abonnés ?">
                    <span class="material-icons">delete</span>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="save" value="1">
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="nom">Nom de la liste</label>
                    <input type="text" 
                           id="nom" 
                           name="nom" 
                           class="form-control" 
                           value="<?= e($formData['nom']) ?>"
                           placeholder="ma-liste"
                           pattern="[a-zA-Z0-9_\-\.]+"
                           required>
                    <small class="text-muted">Sera accessible via <?= e($formData['nom'] ?: 'nom') ?>@<?= e(setting('domains', 'example.com')) ?></small>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <input type="text" 
                           id="description" 
                           name="description" 
                           class="form-control" 
                           value="<?= e($formData['description']) ?>"
                           placeholder="Description de la liste">
                </div>
            </div>
            
            <div class="checkbox-group">
                <label class="checkbox-label">
                    <input type="checkbox" 
                           name="moderation" 
                           value="1" 
                           <?= $formData['moderation'] ? 'checked' : '' ?>>
                    <span>Modérer les messages</span>
                </label>
                
                <label class="checkbox-label">
                    <input type="checkbox" 
                           name="reponse" 
                           value="1" 
                           <?= $formData['reponse'] ? 'checked' : '' ?>>
                    <span>Liste de discussion (destinataires visibles)</span>
                </label>
                
                <label class="checkbox-label">
                    <input type="checkbox" 
                           name="active" 
                           value="1" 
                           <?= $formData['active'] ? 'checked' : '' ?>>
                    <span>Liste activée</span>
                </label>
            </div>
            
            <div class="flex flex-between flex-center mt-2">
                <a href="<?= url('?page=dashboard') ?>" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">save</span>
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!$isNew): ?>
<!-- Subscribers Card -->
<div class="card">
    <div class="card-header">
        <h2>
            Abonnés 
            <span class="badge badge-success" style="font-size: 0.9rem; vertical-align: middle;">
                <?= count($subscribers) ?>
            </span>
        </h2>
        <div class="actions">
            <a href="<?= url('?page=export&action=subscribers&name=' . urlencode($list['nom'])) ?>" 
               class="btn btn-sm btn-secondary"
               target="_blank">
                <span class="material-icons">download</span>
                Exporter CSV
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Add Subscribers Form -->
        <form method="POST" action="<?= url('?page=list&action=add-subscribers&id=' . $list['id']) ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            
            <div class="form-group">
                <label class="form-label" for="emails">Ajouter des abonnés</label>
                <textarea id="emails" 
                          name="emails" 
                          class="form-control" 
                          rows="3"
                          placeholder="email1@example.com&#10;email2@example.com&#10;Ou séparés par des virgules ou points-virgules"></textarea>
            </div>
            
            <div class="text-right mb-3">
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">person_add</span>
                    Ajouter
                </button>
            </div>
        </form>
        
        <hr class="section-divider">
        
        <!-- Subscribers List -->
        <?php if (empty($subscribers)): ?>
        <p class="text-muted text-center">Aucun abonné dans cette liste.</p>
        <?php else: ?>
        <div class="subscriber-list">
            <?php foreach ($subscribers as $subscriber): ?>
            <div class="subscriber-item">
                <span class="subscriber-email"><?= e($subscriber['email']) ?></span>
                <form method="POST" 
                      action="<?= url('?page=list&action=remove-subscriber&id=' . $list['id']) ?>"
                      style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="email" value="<?= e($subscriber['email']) ?>">
                    <button type="submit" 
                            class="btn btn-icon danger" 
                            data-confirm="Désinscrire <?= e($subscriber['email']) ?> ?">
                        <span class="material-icons">close</span>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
