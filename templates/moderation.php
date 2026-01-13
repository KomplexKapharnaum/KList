<?php
/**
 * Moderation Queue Template
 */

ob_start();
?>

<h1 class="mb-3">
    <span class="material-icons" style="vertical-align: middle;">pending_actions</span>
    Modération
</h1>

<?php if ($error): ?>
<div class="alert alert-danger">
    <?= e($error) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Messages en attente</h2>
        <span class="badge badge-warning"><?= count($messages) ?> message(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($messages)): ?>
        <div class="text-center text-muted" style="padding: 3rem;">
            <span class="material-icons" style="font-size: 4rem; opacity: 0.3; display: block; margin-bottom: 1rem;">check_circle</span>
            <p>Aucun message en attente de modération.</p>
        </div>
        <?php else: ?>
        
        <?php foreach ($messages as $msg): ?>
        <div class="card mb-3" style="background: var(--bg-tertiary); border-left: 3px solid var(--warning);">
            <div class="card-body">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                    <div>
                        <strong style="font-size: 1.1rem;"><?= e($msg['subject'] ?: '(Sans sujet)') ?></strong>
                        <div class="text-muted" style="font-size: 0.9rem;">
                            <span class="material-icons" style="font-size: 1rem; vertical-align: middle;">person</span>
                            <?= e($msg['from_name'] ? $msg['from_name'] . ' <' . $msg['from'] . '>' : $msg['from']) ?>
                        </div>
                        <div class="text-muted" style="font-size: 0.85rem;">
                            <span class="material-icons" style="font-size: 1rem; vertical-align: middle;">schedule</span>
                            <?= date('d/m/Y H:i', $msg['date']) ?>
                            
                            <?php if (!empty($msg['lists'])): ?>
                            <span style="margin-left: 1rem;">
                                <span class="material-icons" style="font-size: 1rem; vertical-align: middle;">mail</span>
                                <?= e(implode(', ', $msg['lists'])) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <form method="POST" action="<?= url('?page=moderation&action=approve') ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="uid" value="<?= e($msg['uid']) ?>">
                            <button type="submit" class="btn btn-success" 
                                    onclick="return confirm('Approuver ce message ?');">
                                <span class="material-icons">check</span>
                                Approuver
                            </button>
                        </form>
                        <form method="POST" action="<?= url('?page=moderation&action=discard') ?>" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="uid" value="<?= e($msg['uid']) ?>">
                            <button type="submit" class="btn btn-danger"
                                    onclick="return confirm('Refuser ce message ?');">
                                <span class="material-icons">close</span>
                                Refuser
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php if (!empty($msg['body_preview'])): ?>
                <div style="background: var(--bg-card); padding: 1rem; border-radius: 4px; font-size: 0.9rem; color: var(--text-secondary);">
                    <?= e($msg['body_preview']) ?>...
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
