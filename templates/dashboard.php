<?php
/**
 * Dashboard Template
 */

ob_start();

// Calculate cron status
$cronStatus = 'unknown';
$cronElapsed = null;
$cronWarning = false;

if (!empty($lastCronRun)) {
    $lastRun = strtotime($lastCronRun);
    $cronElapsed = time() - $lastRun;
    
    if ($cronElapsed < 3600) { // Less than 1 hour
        $cronStatus = 'ok';
    } elseif ($cronElapsed < 7200) { // Less than 2 hours
        $cronStatus = 'warning';
        $cronWarning = true;
    } else {
        $cronStatus = 'error';
        $cronWarning = true;
    }
}

function formatElapsed($seconds) {
    if ($seconds < 60) {
        return $seconds . ' sec';
    } elseif ($seconds < 3600) {
        return round($seconds / 60) . ' min';
    } elseif ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        $mins = round(($seconds % 3600) / 60);
        return $hours . 'h ' . $mins . 'min';
    } else {
        $days = floor($seconds / 86400);
        return $days . ' jour(s)';
    }
}
?>

<h1 class="mb-3">Tableau de bord</h1>

<div class="grid-4">
    <div class="card">
        <div class="card-body text-center">
            <div class="text-muted mb-1">Listes actives</div>
            <div style="font-size: 2.5rem; color: var(--accent-light);">
                <?= count($lists) ?>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body text-center">
            <div class="text-muted mb-1">Abonnés uniques</div>
            <div style="font-size: 2.5rem; color: var(--success);">
                <?= $uniqueSubscribers ?>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body text-center">
            <div class="text-muted mb-1">Domaine</div>
            <div style="font-size: 1.2rem; color: var(--text-primary); margin-top: 0.75rem;">
                <?= e(setting('domains', 'Non configuré')) ?>
            </div>
        </div>
    </div>
    
    <!-- Cron Status Card -->
    <div class="card" style="<?= $cronWarning ? 'border-color: var(--warning);' : '' ?>">
        <div class="card-body text-center">
            <div class="text-muted mb-1">
                Dernier traitement
                <?php if ($cronStatus === 'ok'): ?>
                <span class="material-icons" style="color: var(--success); font-size: 1rem; vertical-align: middle;">check_circle</span>
                <?php elseif ($cronStatus === 'warning'): ?>
                <span class="material-icons" style="color: var(--warning); font-size: 1rem; vertical-align: middle;">warning</span>
                <?php elseif ($cronStatus === 'error'): ?>
                <span class="material-icons" style="color: var(--danger); font-size: 1rem; vertical-align: middle;">error</span>
                <?php endif; ?>
            </div>
            <?php if ($cronElapsed !== null): ?>
            <div style="font-size: 1.5rem; color: <?= $cronStatus === 'ok' ? 'var(--success)' : ($cronStatus === 'warning' ? 'var(--warning)' : 'var(--danger)') ?>;">
                <?= formatElapsed($cronElapsed) ?>
            </div>
            <div class="text-muted" style="font-size: 0.75rem;">
                <?= date('d/m H:i', strtotime($lastCronRun)) ?>
            </div>
            <?php else: ?>
            <div style="font-size: 1.2rem; color: var(--text-muted);">
                Jamais
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card mb-3">
    <div class="card-header">
        <h2>Actions rapides</h2>
    </div>
    <div class="card-body">
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <a href="<?= url('?page=subscribers') ?>" class="btn btn-secondary">
                <span class="material-icons">search</span>
                Rechercher un abonné
            </a>
            <a href="<?= url('?page=moderation') ?>" class="btn btn-secondary">
                <span class="material-icons">pending_actions</span>
                Modération
            </a>
            <a href="<?= url('?page=errors') ?>" class="btn btn-secondary">
                <span class="material-icons">error_outline</span>
                Erreurs / Refusés
            </a>
            <?php if (!empty($cronKey)): ?>
            <a href="<?= url('cron.php?key=' . urlencode($cronKey)) ?>" class="btn btn-secondary" target="_blank">
                <span class="material-icons">sync</span>
                Lancer le traitement
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Vos listes de diffusion</h2>
        <a href="<?= url('?page=list&action=new') ?>" class="btn btn-sm btn-secondary">
            <span class="material-icons">add</span>
            Nouvelle liste
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($lists)): ?>
        <p class="text-muted text-center">
            Aucune liste créée. 
            <a href="<?= url('?page=list&action=new') ?>">Créez votre première liste</a>.
        </p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Abonnés</th>
                    <th>Dernière utilisation</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lists as $list): ?>
                <tr>
                    <td>
                        <strong><?= e($list['nom']) ?></strong>
                        <div class="text-muted" style="font-size: 0.8rem;">
                            <?= e($list['nom']) ?>@<?= e(setting('domains', 'example.com')) ?>
                        </div>
                    </td>
                    <td><?= $list['subscriber_count'] ?></td>
                    <td>
                        <?php if (!empty($list['last_used'])): ?>
                        <span class="text-muted" style="font-size: 0.9rem;">
                            <?= date('d/m/Y', strtotime($list['last_used'])) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size: 0.85rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($list['active']): ?>
                        <span class="badge badge-success">Active</span>
                        <?php else: ?>
                        <span class="badge badge-danger">Inactive</span>
                        <?php endif; ?>
                        <?php if ($list['moderation']): ?>
                        <span class="badge badge-warning">Modérée</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= url('?page=list&action=edit&name=' . urlencode($list['nom'])) ?>" 
                           class="btn btn-sm btn-secondary">
                            <span class="material-icons">edit</span>
                            Modifier
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
