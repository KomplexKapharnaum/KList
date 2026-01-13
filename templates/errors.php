<?php
/**
 * Errors and Discarded Messages Template
 */

ob_start();
?>

<h1 class="mb-3">
    <span class="material-icons" style="vertical-align: middle;">error_outline</span>
    Erreurs & Messages refusés
</h1>

<?php if ($errorMsg): ?>
<div class="alert alert-danger">
    <?= e($errorMsg) ?>
</div>
<?php endif; ?>

<div class="grid-2">
    <!-- Error Messages -->
    <div class="card">
        <div class="card-header">
            <h2>
                <span class="material-icons" style="color: var(--danger); vertical-align: middle;">error</span>
                Erreurs
            </h2>
            <span class="badge badge-danger"><?= count($errors) ?></span>
        </div>
        <div class="card-body" style="max-height: 600px; overflow-y: auto;">
            <?php if (empty($errors)): ?>
            <div class="text-center text-muted" style="padding: 2rem;">
                <span class="material-icons" style="font-size: 3rem; opacity: 0.3;">check_circle</span>
                <p>Aucun message en erreur.</p>
            </div>
            <?php else: ?>
            
            <?php foreach ($errors as $msg): ?>
            <div class="card mb-2" style="background: var(--bg-tertiary); border-left: 3px solid var(--danger);">
                <div class="card-body" style="padding: 1rem;">
                    <div style="font-weight: bold; margin-bottom: 0.25rem;">
                        <?= e($msg['subject'] ?: '(Sans sujet)') ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.85rem;">
                        <span class="material-icons" style="font-size: 0.9rem; vertical-align: middle;">person</span>
                        <?= e($msg['from']) ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.8rem;">
                        <span class="material-icons" style="font-size: 0.9rem; vertical-align: middle;">schedule</span>
                        <?= date('d/m/Y H:i', $msg['date']) ?>
                    </div>
                    <?php if (!empty($msg['body_preview'])): ?>
                    <div style="margin-top: 0.5rem; font-size: 0.85rem; color: var(--text-muted); 
                                background: var(--bg-card); padding: 0.5rem; border-radius: 4px;">
                        <?= e(substr($msg['body_preview'], 0, 100)) ?>...
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Discarded Messages -->
    <div class="card">
        <div class="card-header">
            <h2>
                <span class="material-icons" style="color: var(--warning); vertical-align: middle;">block</span>
                Refusés
            </h2>
            <span class="badge badge-warning"><?= count($discarded) ?></span>
        </div>
        <div class="card-body" style="max-height: 600px; overflow-y: auto;">
            <?php if (empty($discarded)): ?>
            <div class="text-center text-muted" style="padding: 2rem;">
                <span class="material-icons" style="font-size: 3rem; opacity: 0.3;">check_circle</span>
                <p>Aucun message refusé.</p>
            </div>
            <?php else: ?>
            
            <?php foreach ($discarded as $msg): ?>
            <div class="card mb-2" style="background: var(--bg-tertiary); border-left: 3px solid var(--warning);">
                <div class="card-body" style="padding: 1rem;">
                    <div style="font-weight: bold; margin-bottom: 0.25rem;">
                        <?= e($msg['subject'] ?: '(Sans sujet)') ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.85rem;">
                        <span class="material-icons" style="font-size: 0.9rem; vertical-align: middle;">person</span>
                        <?= e($msg['from']) ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.8rem;">
                        <span class="material-icons" style="font-size: 0.9rem; vertical-align: middle;">schedule</span>
                        <?= date('d/m/Y H:i', $msg['date']) ?>
                    </div>
                    <?php if (!empty($msg['body_preview'])): ?>
                    <div style="margin-top: 0.5rem; font-size: 0.85rem; color: var(--text-muted); 
                                background: var(--bg-card); padding: 0.5rem; border-radius: 4px;">
                        <?= e(substr($msg['body_preview'], 0, 100)) ?>...
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body text-muted" style="font-size: 0.9rem;">
        <strong>Légende:</strong>
        <ul style="margin: 0.5rem 0 0 1.5rem;">
            <li><strong>Erreurs:</strong> Messages qui n'ont pas pu être traités (bounces, erreurs SMTP, etc.)</li>
            <li><strong>Refusés:</strong> Messages refusés par modération ou envoyés par des expéditeurs non autorisés</li>
        </ul>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
