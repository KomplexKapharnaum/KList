<?php
/**
 * KList - the page a moderation mail links to (#t-073, decided 2026-11-12 with the relay)
 *
 * Opening the link only SHOWS the held message; the buttons on the page POST, and only the POST
 * accepts or refuses it. Mail scanners (Outlook Safe Links, corporate gateways) open the links in
 * a mail: with the old links, opening one was enough to approve a message (audit finding 5).
 *
 * The link names the message (ListIntake::moderationId) and carries an HMAC of that id made with
 * the database's link secret: no login, and never the cron key — the token is the right to decide
 * this one message, nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

if (!is_installed()) {
    http_response_code(503);
    exit('Service unavailable');
}

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$id = (string) ($post ? ($_POST['id'] ?? '') : ($_GET['id'] ?? ''));
$token = (string) ($post ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));

$state = 'show';        // show | invalid | gone | approved | discarded | error
$message = null;
$error = '';

ob_start();             // MailProcessor::log() prints its log as HTML: not on this page
try {
    $processor = new MailProcessor();
    if (!preg_match('/^[0-9a-f]{40}$/', $id) || !$processor->checkModerationToken($id, $token)) {
        $state = 'invalid';
    } elseif (!$processor->connect()) {
        $state = 'error';
        $error = 'connexion à la boîte aux lettres impossible';
    } elseif ($post) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'approve') {
            $state = $processor->approve($id) ? 'approved' : 'gone';
        } elseif ($action === 'discard') {
            $state = $processor->discard($id) ? 'discarded' : 'gone';
        } else {
            $state = 'error';
            $error = 'action inconnue';
        }
    } else {
        $message = $processor->getPendingMessage($id);
        $state = $message === null ? 'gone' : 'show';
    }
} catch (\Throwable $e) {
    $state = 'error';
    $error = $e->getMessage();
}
ob_end_clean();

http_response_code(match ($state) {
    'invalid' => 403,
    'error' => 500,
    default => 200,
});

$titles = [
    'show' => 'Message en attente',
    'invalid' => 'Lien non valide',
    'gone' => 'Message déjà traité',
    'approved' => 'Message accepté',
    'discarded' => 'Message refusé',
    'error' => 'Erreur',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= e($titles[$state]) ?> - <?= e(setting('site_title', 'Listes')) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>
<main style="max-width: 46rem; margin: 2rem auto; padding: 0 1rem;">
    <h1 class="mb-3"><?= e($titles[$state]) ?></h1>

<?php if ($state === 'invalid'): ?>
    <div class="alert alert-danger">Ce lien n'est pas valide. Ouvrez le message depuis la page Modération de l'interface.</div>
<?php elseif ($state === 'gone'): ?>
    <div class="alert alert-info">Ce message n'est plus en attente : il a déjà été accepté ou refusé.</div>
<?php elseif ($state === 'approved'): ?>
    <div class="alert alert-success">Message accepté : il est envoyé à la liste.</div>
<?php elseif ($state === 'discarded'): ?>
    <div class="alert alert-success">Message refusé : il ne sera pas envoyé.</div>
<?php elseif ($state === 'error'): ?>
    <div class="alert alert-danger">Erreur : <?= e($error) ?></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <p><strong><?= e((string) ($message['subject'] ?: '(Sans sujet)')) ?></strong></p>
            <p class="text-muted">
                De : <?= e($message['from_name'] ? $message['from_name'] . ' <' . $message['from'] . '>' : $message['from']) ?>
                <?php if (!$message['verified']): ?>
                <br><strong style="color: var(--warning, #ff9800);">Adresse non vérifiée (DMARC) : n'importe qui peut écrire cet expéditeur.</strong>
                <?php endif; ?>
                <br>Liste(s) : <?= e(implode(', ', $message['lists'])) ?>
                <?php if ($message['date']): ?><br>Reçu le <?= date('d/m/Y H:i', (int) $message['date']) ?><?php endif; ?>
            </p>
            <?php if ($message['body_preview'] !== ''): ?>
            <div style="padding: 1rem; border-radius: 4px; white-space: pre-wrap;"><?= e($message['body_preview']) ?></div>
            <?php endif; ?>
            <form method="POST" action="<?= e(url('moderate.php')) ?>" style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <button type="submit" name="action" value="approve" class="btn btn-success">Accepter la diffusion</button>
                <button type="submit" name="action" value="discard" class="btn btn-danger">Refuser la diffusion</button>
            </form>
        </div>
    </div>
<?php endif; ?>
</main>
</body>
</html>
