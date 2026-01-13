<?php
/**
 * Subscribers Search Template
 */

ob_start();
?>

<h1 class="mb-3">
    <span class="material-icons" style="vertical-align: middle;">search</span>
    Rechercher un abonné
</h1>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= url('') ?>" id="searchForm">
            <input type="hidden" name="page" value="subscribers">
            <div style="display: flex; gap: 1rem; align-items: center;">
                <input type="text" name="q" id="searchInput" class="form-control" 
                       placeholder="Rechercher un email (partiel ou complet)..."
                       value="<?= e($query) ?>"
                       autocomplete="off"
                       style="flex: 1;">
                <button type="submit" class="btn btn-primary">
                    <span class="material-icons">search</span>
                    Rechercher
                </button>
            </div>
        </form>
    </div>
</div>

<div id="resultsContainer">
<?php if (!empty($query)): ?>
<div class="card">
    <div class="card-header">
        <h2>Résultats pour "<?= e($query) ?>"</h2>
        <span class="badge badge-info"><?= count($results) ?> résultat(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($results)): ?>
        <p class="text-muted text-center">Aucun abonné trouvé.</p>
        <?php else: ?>
        <?php 
        // Group results by email
        $grouped = [];
        foreach ($results as $r) {
            if (!isset($grouped[$r['email']])) {
                $grouped[$r['email']] = [];
            }
            $grouped[$r['email']][] = $r;
        }
        ?>
        
        <?php foreach ($grouped as $email => $subscriptions): ?>
        <div class="card mb-2" style="background: var(--bg-tertiary);">
            <div class="card-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="font-size: 1.1rem; margin: 0;">
                        <span class="material-icons" style="vertical-align: middle; color: var(--accent-light);">person</span>
                        <?= e($email) ?>
                        <span class="badge badge-info" style="font-size: 0.75rem; margin-left: 0.5rem;">
                            <?= count($subscriptions) ?> liste(s)
                        </span>
                    </h3>
                    <?php if (count($subscriptions) > 1): ?>
                    <form method="POST" style="display: inline;" 
                          onsubmit="return confirm('Désinscrire <?= e($email) ?> de TOUTES les listes (<?= count($subscriptions) ?>) ?');">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="email" value="<?= e($email) ?>">
                        <button type="submit" name="unsubscribe_all" value="1" class="btn btn-sm btn-danger">
                            <span class="material-icons">delete_forever</span>
                            Désinscrire de tout
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <table class="table" style="margin-bottom: 0;">
                    <thead>
                        <tr>
                            <th>Liste</th>
                            <th>Inscrit le</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscriptions as $sub): ?>
                        <tr>
                            <td>
                                <a href="<?= url('?page=list&action=edit&name=' . urlencode($sub['liste_nom'])) ?>">
                                    <?= e($sub['liste_nom']) ?>
                                </a>
                            </td>
                            <td class="text-muted">
                                <?= date('d/m/Y', strtotime($sub['created_at'])) ?>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Désinscrire <?= e($email) ?> de la liste <?= e($sub['liste_nom']) ?> ?');">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="email" value="<?= e($email) ?>">
                                    <input type="hidden" name="liste_id" value="<?= $sub['liste_id'] ?>">
                                    <button type="submit" name="unsubscribe" value="1" class="btn btn-sm btn-secondary">
                                        <span class="material-icons">person_remove</span>
                                        Désinscrire
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card" id="emptyState">
    <div class="card-body text-center text-muted">
        <span class="material-icons" style="font-size: 4rem; opacity: 0.3; display: block; margin-bottom: 1rem;">search</span>
        <p>Entrez une adresse email (partielle ou complète) pour rechercher un abonné.</p>
        <p style="font-size: 0.9rem;">Vous pourrez ensuite voir toutes les listes auxquelles il est inscrit et le désinscrire.</p>
    </div>
</div>
<?php endif; ?>
</div>

<script>
// Live search as user types
(function() {
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');
    let debounceTimer = null;
    
    // Auto-focus the input field and move cursor to end
    searchInput.focus();
    searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
    
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        // Clear previous timer
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        
        // Only search if 2+ characters
        if (query.length >= 2) {
            // Debounce: wait 300ms after user stops typing
            debounceTimer = setTimeout(function() {
                searchForm.submit();
            }, 400);
        }
    });
    
    // Prevent double-submit on enter
    searchForm.addEventListener('submit', function() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
