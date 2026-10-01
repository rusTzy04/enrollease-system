<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_ADMIN]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_room') {
        $name = trim($_POST['room_name'] ?? '');
        $capacity = (int) ($_POST['capacity'] ?? 0);

        if ($name === '')
            $errors[] = 'Room name is required.';
        if ($capacity <= 0 || $capacity > 500)
            $errors[] = 'Capacity must be between 1 and 500.';

        if (empty($errors)) {
            $dupe = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_name = :n");
            $dupe->execute([':n' => $name]);
            if ((int) $dupe->fetchColumn() > 0) {
                $errors[] = "A room named \"{$name}\" already exists.";
            } else {
                $pdo->prepare("INSERT INTO rooms (room_name, capacity) VALUES (:n, :c)")
                    ->execute([':n' => $name, ':c' => $capacity]);
                logActivity($pdo, $_SESSION['user_id'], 'room_created', $name);
                setFlash('success', 'Room added.');
            }
        }
    }

    if ($action === 'delete_room') {
        $roomId = (int) $_POST['room_id'];
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM class_schedules WHERE room_id = :id");
        $inUse->execute([':id' => $roomId]);

        if ((int) $inUse->fetchColumn() > 0) {
            $errors[] = 'This room is assigned to one or more class schedules and can\'t be deleted. Remove those schedule assignments first.';
        } else {
            $pdo->prepare("DELETE FROM rooms WHERE room_id = :id")->execute([':id' => $roomId]);
            logActivity($pdo, $_SESSION['user_id'], 'room_deleted', "Room #{$roomId}");
            setFlash('success', 'Room deleted.');
        }
    }

    if (empty($errors))
        redirect('/admin/rooms');
}

$rooms = $pdo->query(
    "SELECT r.*, (SELECT COUNT(*) FROM class_schedules cs WHERE cs.room_id = r.room_id) AS schedule_count
     FROM rooms r ORDER BY r.room_name"
)->fetchAll();

$pageTitle = 'Rooms';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card">
    <h4>Add Room</h4>
    <form method="POST" class="grid grid-3" style="align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_room">
        <div class="field"><label>Room Name</label><input type="text" name="room_name"
                placeholder="e.g. Room 201, Computer Lab 2" required></div>
        <div class="field"><label>Capacity</label><input type="number" name="capacity" min="1" max="500" value="40"
                required></div>
        <div class="field"><label style="visibility:hidden;">&nbsp;</label>
            <button class="btn btn-primary" type="submit">Add Room</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:20px;">
    <h4>All Rooms</h4>
    <table>
        <thead>
            <tr>
                <th>Room</th>
                <th>Capacity</th>
                <th>Used By</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rooms as $r): ?>
                <tr>
                    <td><?= e($r['room_name']) ?></td>
                    <td><?= (int) $r['capacity'] ?></td>
                    <td><?= (int) $r['schedule_count'] ?> schedule(s)</td>
                    <td>
                        <form method="POST"><?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_room">
                            <input type="hidden" name="room_id" value="<?= (int) $r['room_id'] ?>">
                            <button class="btn btn-danger btn-sm"
                                data-confirm="Delete room &quot;<?= e($r['room_name']) ?>&quot;?">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rooms): ?>
                <tr>
                    <td colspan="4" class="helper-text">No rooms yet.</td>
                </tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>