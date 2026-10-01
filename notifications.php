<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (($_POST['action'] ?? '') === 'mark_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = :id AND user_id = :uid")
            ->execute([':id' => (int) $_POST['notification_id'], ':uid' => $_SESSION['user_id']]);
    }
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid")
            ->execute([':uid' => $_SESSION['user_id']]);
    }
    redirect('/notifications');
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50");
$stmt->execute([':uid' => $_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/header.php';
?>

<div class="section-head">
    <div></div>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="mark_all_read">
        <button class="btn btn-outline btn-sm" type="submit">Mark all as read</button>
    </form>
</div>

<div class="card">
    <?php if ($notifications): ?>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Title</th>
                    <th>Message</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notifications as $n): ?>
                    <tr style="<?= $n['is_read'] ? '' : 'font-weight:600;' ?>">
                        <td><?= $n['is_read'] ? '' : '<span class="badge badge-info">New</span>' ?></td>
                        <td><?= e($n['title']) ?></td>
                        <td><?= e($n['message']) ?></td>
                        <td><?= date('M j, Y g:ia', strtotime($n['created_at'])) ?></td>
                        <td>
                            <?php if (!$n['is_read']): ?>
                                <form method="POST"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
                                    <button class="btn btn-outline btn-sm" type="submit">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">No notifications yet.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>