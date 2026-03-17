<?php
/**
 * AcadVerify — Assistant Dashboard
 */
require_once __DIR__ . '/../includes/auth.php';
requireLogin('assistant');
require_once __DIR__ . '/../includes/db.php';

$assistantName = $_SESSION['name'];
$assistantId   = $_SESSION['user_id'];
$success       = '';
$error         = '';

// ═══════════════════════════════════════════
//  POST ACTIONS
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Sign Off Rough ──
    if ($action === 'sign_rough') {
        $esId      = (int) ($_POST['es_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $expTitle  = $_POST['exp_title'] ?? 'Experiment';

        if ($esId > 0) {
            $stmt = $pdo->prepare("
                UPDATE experiment_status
                SET rough_status = 'signed', rough_signed_at = NOW()
                WHERE id = ? AND rough_status = 'pending'
            ");
            $stmt->execute([$esId]);

            if ($stmt->rowCount() > 0) {
                // Notify student
                $msg = "Your rough work for \"{$expTitle}\" has been signed off. You can now upload the fair copy.";
                $notif = $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
                $notif->execute([$studentId, $msg]);
                $success = "Rough signed off for \"{$expTitle}\".";
            }
        }
    }

    // ── Approve Assignment ──
    if ($action === 'approve') {
        $subId     = (int) ($_POST['sub_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $asgnTitle = $_POST['asgn_title'] ?? 'Assignment';

        if ($subId > 0) {
            // Find the main (professor) for this subject to notify
            $stmtMain = $pdo->prepare("
                SELECT s.main_id
                FROM submissions sub
                JOIN assignments a ON a.id = sub.assignment_id
                JOIN subjects s ON s.id = a.subject_id
                WHERE sub.id = ?
            ");
            $stmtMain->execute([$subId]);
            $mainId = $stmtMain->fetchColumn();

            $stmt = $pdo->prepare("
                UPDATE submissions
                SET status = 'pending_main'
                WHERE id = ? AND status = 'pending_assistant'
            ");
            $stmt->execute([$subId]);

            if ($stmt->rowCount() > 0) {
                // Notify professor
                if ($mainId) {
                    $msg = "Assignment \"{$asgnTitle}\" has been verified by assistant and is ready for marking.";
                    $notif = $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
                    $notif->execute([$mainId, $msg]);
                }
                $success = "Approved \"{$asgnTitle}\" — forwarded to professor.";
            }
        }
    }

    // ── Reject Assignment ──
    if ($action === 'reject') {
        $subId     = (int) ($_POST['sub_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $asgnTitle = $_POST['asgn_title'] ?? 'Assignment';
        $notes     = trim($_POST['notes'] ?? '');

        if ($notes === '') {
            $error = 'Rejection notes are required. Please provide a reason.';
        } elseif ($subId > 0) {
            // Check current attempt count
            $stmtCheck = $pdo->prepare('SELECT attempt_count FROM submissions WHERE id = ?');
            $stmtCheck->execute([$subId]);
            $currentAttempts = (int) $stmtCheck->fetchColumn();

            $newAttempts = $currentAttempts + 1;
            $maxAttempts = 3;

            $stmt = $pdo->prepare("
                UPDATE submissions
                SET status = 'rejected', assistant_notes = ?, attempt_count = ?
                WHERE id = ? AND status = 'pending_assistant'
            ");
            $stmt->execute([$notes, $newAttempts, $subId]);

            if ($stmt->rowCount() > 0) {
                $attemptsLeft = $maxAttempts - $newAttempts;
                if ($attemptsLeft > 0) {
                    $msg = "Your assignment \"{$asgnTitle}\" was rejected. Reason: {$notes}. You have {$attemptsLeft} attempt(s) left.";
                } else {
                    $msg = "Your assignment \"{$asgnTitle}\" was rejected. Reason: {$notes}. No resubmissions remaining.";
                }
                $notif = $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
                $notif->execute([$studentId, $msg]);
                $success = "Rejected \"{$asgnTitle}\" — student notified.";
            }
        }
    }
}

// ═══════════════════════════════════════════
//  FETCH DATA
// ═══════════════════════════════════════════

// Unread notification count
$stmtNotif = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$stmtNotif->execute([$assistantId]);
$unreadCount = (int) $stmtNotif->fetchColumn();

// Pending rough submissions
$stmtRough = $pdo->prepare("
    SELECT
        es.id AS es_id,
        es.student_id,
        u.name AS student_name,
        s.name AS subject_name,
        a.title AS exp_title,
        a.experiment_order,
        a.due_date
    FROM experiment_status es
    JOIN users u ON u.id = es.student_id
    JOIN assignments a ON a.id = es.assignment_id
    JOIN subjects s ON s.id = a.subject_id
    WHERE es.rough_status = 'pending'
    ORDER BY a.due_date ASC
");
$stmtRough->execute();
$pendingRough = $stmtRough->fetchAll();

// Pending assignment submissions
$stmtPending = $pdo->prepare("
    SELECT
        sub.id AS sub_id,
        sub.student_id,
        sub.file_path,
        sub.mime_type,
        sub.attempt_count,
        u.name AS student_name,
        s.name AS subject_name,
        a.title AS asgn_title,
        a.due_date
    FROM submissions sub
    JOIN users u ON u.id = sub.student_id
    JOIN assignments a ON a.id = sub.assignment_id
    JOIN subjects s ON s.id = a.subject_id
    WHERE sub.status = 'pending_assistant'
    ORDER BY sub.created_at ASC
");
$stmtPending->execute();
$pendingAssignments = $stmtPending->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistant Dashboard — AcadVerify</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
        }

        /* ── Navbar ── */
        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 32px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            position: sticky;
            top: 0; z-index: 100;
        }
        .navbar .brand {
            font-size: 1.25rem; font-weight: 700;
            background: linear-gradient(135deg, #f59e0b, #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .navbar .nav-right { display: flex; align-items: center; gap: 20px; }
        .bell-wrap { position: relative; cursor: pointer; }
        .bell-wrap svg { width: 22px; height: 22px; fill: #94a3b8; }
        .bell-badge {
            position: absolute; top: -6px; right: -8px;
            background: #ef4444; color: #fff;
            font-size: 0.65rem; font-weight: 700;
            width: 18px; height: 18px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .role-tag {
            font-size: 0.72rem; font-weight: 600;
            background: #713f12; color: #fde047;
            padding: 3px 10px; border-radius: 999px;
        }
        .logout-link {
            color: #94a3b8; text-decoration: none; font-size: 0.85rem;
            transition: color 0.2s;
        }
        .logout-link:hover { color: #f87171; }

        /* ── Layout ── */
        .container { max-width: 1120px; margin: 0 auto; padding: 32px 24px; }
        .greeting { font-size: 1.6rem; font-weight: 700; margin-bottom: 8px; }
        .greeting span { color: #f59e0b; }

        /* ── Alerts ── */
        .alert {
            padding: 12px 18px; border-radius: 10px;
            font-size: 0.88rem; margin-bottom: 24px;
        }
        .alert-success { background: #065f46; color: #6ee7b7; }
        .alert-error   { background: #7f1d1d; color: #fca5a5; }

        /* ── Section ── */
        .section { margin-bottom: 44px; }
        .section-title {
            font-size: 1.15rem; font-weight: 700;
            margin-bottom: 16px; padding-bottom: 8px;
            border-bottom: 1px solid #334155;
        }
        .section-count {
            font-size: 0.85rem; font-weight: 400; color: #64748b;
        }

        /* ── Cards grid ── */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 18px;
        }
        .review-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 22px 20px;
            transition: border-color 0.2s;
        }
        .review-card:hover { border-color: #475569; }
        .card-header {
            display: flex; justify-content: space-between;
            align-items: flex-start; margin-bottom: 12px;
        }
        .card-student { font-weight: 700; font-size: 0.95rem; }
        .card-meta { font-size: 0.8rem; color: #94a3b8; margin-bottom: 10px; }
        .card-meta span { margin-right: 14px; }

        /* ── Photo preview ── */
        .photo-preview {
            width: 100%; height: 180px;
            object-fit: cover;
            border-radius: 10px;
            background: #0f172a;
            margin-bottom: 14px;
            cursor: pointer;
        }

        /* ── Pills ── */
        .pill {
            display: inline-block; padding: 3px 10px;
            border-radius: 999px; font-size: 0.72rem;
            font-weight: 600; text-transform: uppercase;
        }
        .pill-green  { background: #065f46; color: #6ee7b7; }
        .pill-yellow { background: #713f12; color: #fde047; }
        .pill-red    { background: #7f1d1d; color: #fca5a5; }
        .pill-gray   { background: #334155; color: #94a3b8; }

        /* ── Buttons ── */
        .btn {
            display: inline-block; padding: 8px 16px;
            border: none; border-radius: 8px;
            font-size: 0.82rem; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.85; }
        .btn-sign    { background: #059669; color: #fff; }
        .btn-approve { background: #059669; color: #fff; }
        .btn-reject  { background: #dc2626; color: #fff; }
        .btn-disabled {
            background: #334155; color: #64748b;
            cursor: not-allowed; pointer-events: none;
        }

        .card-actions {
            display: flex; gap: 10px; align-items: flex-end;
            flex-wrap: wrap; margin-top: 10px;
        }
        .notes-input {
            width: 100%; padding: 8px 12px;
            border: 1px solid #334155; border-radius: 8px;
            background: #0f172a; color: #e2e8f0;
            font-size: 0.82rem; margin-top: 8px;
            outline: none; resize: vertical; min-height: 40px;
        }
        .notes-input:focus { border-color: #f59e0b; }
        .notes-input::placeholder { color: #64748b; }
        .attempt-info {
            font-size: 0.72rem; color: #64748b;
            margin-top: 4px;
        }

        .empty-state {
            text-align: center; color: #64748b;
            padding: 40px 20px; font-size: 0.9rem;
        }

        /* ── Lightbox ── */
        .lightbox {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.85); z-index: 999;
            align-items: center; justify-content: center;
            cursor: zoom-out;
        }
        .lightbox.active { display: flex; }
        .lightbox img {
            max-width: 90vw; max-height: 90vh;
            border-radius: 10px;
        }

        @media (max-width: 768px) {
            .navbar { padding: 14px 16px; }
            .container { padding: 20px 14px; }
            .card-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
    <div class="brand">AcadVerify</div>
    <div class="nav-right">
        <span class="role-tag">Assistant</span>
        <div class="bell-wrap" title="Notifications">
            <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5S10.5 3.17 10.5 4v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
            <?php if ($unreadCount > 0): ?>
                <span class="bell-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
            <?php endif; ?>
        </div>
        <a href="/logout.php" class="logout-link">Logout</a>
    </div>
</nav>

<div class="container">

    <h1 class="greeting">Hello, <span><?= htmlspecialchars($assistantName) ?></span> 👋</h1>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ═══ Pending Rough Sign-offs ═══ -->
    <div class="section">
        <h2 class="section-title">
            🧪 Pending Rough Sign-offs
            <span class="section-count">(<?= count($pendingRough) ?>)</span>
        </h2>

        <?php if (empty($pendingRough)): ?>
            <div class="empty-state">No pending rough submissions. All caught up! ✅</div>
        <?php else: ?>
            <div class="card-grid">
            <?php foreach ($pendingRough as $r): ?>
                <div class="review-card">
                    <div class="card-header">
                        <div class="card-student"><?= htmlspecialchars($r['student_name']) ?></div>
                        <span class="due-pill pill" data-due="<?= htmlspecialchars($r['due_date'] ?? '') ?>">
                            <?= $r['due_date'] ? htmlspecialchars($r['due_date']) : '—' ?>
                        </span>
                    </div>
                    <div class="card-meta">
                        <span>📘 <?= htmlspecialchars($r['subject_name']) ?></span>
                        <span>Exp #<?= (int) $r['experiment_order'] ?> — <?= htmlspecialchars($r['exp_title']) ?></span>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="sign_rough">
                        <input type="hidden" name="es_id" value="<?= $r['es_id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $r['student_id'] ?>">
                        <input type="hidden" name="exp_title" value="<?= htmlspecialchars($r['exp_title']) ?>">
                        <div class="card-actions">
                            <button type="submit" class="btn btn-sign">✓ Sign Off Rough</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══ Pending Assignment Reviews ═══ -->
    <div class="section">
        <h2 class="section-title">
            📝 Pending Assignment Reviews
            <span class="section-count">(<?= count($pendingAssignments) ?>)</span>
        </h2>

        <?php if (empty($pendingAssignments)): ?>
            <div class="empty-state">No pending assignment submissions. All caught up! ✅</div>
        <?php else: ?>
            <div class="card-grid">
            <?php foreach ($pendingAssignments as $pa):
                $maxAttempts   = 3;
                $attemptsUsed  = (int) $pa['attempt_count'];
                $attemptsLeft  = $maxAttempts - $attemptsUsed;
                $canResubmit   = $attemptsLeft > 0;
            ?>
                <div class="review-card">
                    <div class="card-header">
                        <div class="card-student"><?= htmlspecialchars($pa['student_name']) ?></div>
                        <span class="due-pill pill" data-due="<?= htmlspecialchars($pa['due_date'] ?? '') ?>">
                            <?= $pa['due_date'] ? htmlspecialchars($pa['due_date']) : '—' ?>
                        </span>
                    </div>
                    <div class="card-meta">
                        <span>📘 <?= htmlspecialchars($pa['subject_name']) ?></span>
                        <span>📄 <?= htmlspecialchars($pa['asgn_title']) ?></span>
                    </div>

                    <!-- Photo preview -->
                    <?php if ($pa['file_path']): ?>
                        <img src="/<?= htmlspecialchars($pa['file_path']) ?>"
                             alt="Submission"
                             class="photo-preview"
                             onclick="openLightbox(this.src)">
                    <?php endif; ?>

                    <div class="attempt-info">
                        Attempt <?= $attemptsUsed ?> of <?= $maxAttempts ?>
                        <?php if (!$canResubmit): ?>
                            — <span style="color:#fca5a5;">Max attempts used</span>
                        <?php endif; ?>
                    </div>

                    <!-- Approve Form -->
                    <form method="POST" action="" style="display:inline;">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="sub_id" value="<?= $pa['sub_id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $pa['student_id'] ?>">
                        <input type="hidden" name="asgn_title" value="<?= htmlspecialchars($pa['asgn_title']) ?>">
                        <div class="card-actions">
                            <button type="submit" class="btn btn-approve">✓ Approve</button>
                        </div>
                    </form>

                    <!-- Reject Form -->
                    <form method="POST" action="" onsubmit="return validateReject(this);">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="sub_id" value="<?= $pa['sub_id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $pa['student_id'] ?>">
                        <input type="hidden" name="asgn_title" value="<?= htmlspecialchars($pa['asgn_title']) ?>">
                        <textarea name="notes" class="notes-input" placeholder="Rejection reason (required)…"></textarea>
                        <div class="card-actions">
                            <button type="submit" class="btn btn-reject">✕ Reject</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ── Lightbox ── -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <img src="" alt="Full preview" id="lightboxImg">
</div>

<script>
// Due-date colour coding
document.addEventListener('DOMContentLoaded', function () {
    const pills = document.querySelectorAll('.due-pill');
    const now = new Date();
    now.setHours(0, 0, 0, 0);

    pills.forEach(function (el) {
        const raw = el.getAttribute('data-due');
        if (!raw) return;
        const due  = new Date(raw + 'T00:00:00');
        const diff = Math.ceil((due - now) / (1000 * 60 * 60 * 24));

        if (diff < 0)       el.classList.add('pill-red');
        else if (diff <= 2) el.classList.add('pill-yellow');
        else                el.classList.add('pill-green');
    });
});

// Reject validation (client-side, PHP also checks)
function validateReject(form) {
    var notes = form.querySelector('textarea[name="notes"]').value.trim();
    if (notes === '') {
        alert('Please provide a rejection reason.');
        return false;
    }
    return confirm('Reject this submission? The student will be notified.');
}

// Lightbox
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('active');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('active');
}
</script>

</body>
</html>
