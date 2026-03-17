<?php
/**
 * AcadVerify — Student Dashboard
 */
require_once __DIR__ . '/../includes/auth.php';
requireLogin('student');
require_once __DIR__ . '/../includes/db.php';

$studentId   = $_SESSION['user_id'];
$studentName = $_SESSION['name'];

// ── Unread notification count ──
$stmtNotif = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$stmtNotif->execute([$studentId]);
$unreadCount = (int) $stmtNotif->fetchColumn();

// ── Stats ──
// Rough signed count
$stmtRS = $pdo->prepare("SELECT COUNT(*) FROM experiment_status WHERE student_id = ? AND rough_status = 'signed'");
$stmtRS->execute([$studentId]);
$roughSignedCount = (int) $stmtRS->fetchColumn();

// Fair submitted count
$stmtFS = $pdo->prepare("SELECT COUNT(*) FROM experiment_status WHERE student_id = ? AND fair_status = 'submitted'");
$stmtFS->execute([$studentId]);
$fairSubmittedCount = (int) $stmtFS->fetchColumn();

// Assignments marked count
$stmtAM = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE student_id = ? AND final_mark IS NOT NULL");
$stmtAM->execute([$studentId]);
$assignmentsMarked = (int) $stmtAM->fetchColumn();

// Exam pass rate
$stmtEP = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN pass_fail = 'pass' THEN 1 ELSE 0 END) AS passed FROM exam_results WHERE student_id = ?");
$stmtEP->execute([$studentId]);
$examRow  = $stmtEP->fetch();
$passRate = ($examRow['total'] > 0) ? round(($examRow['passed'] / $examRow['total']) * 100) : 0;

// ── Experiments ──
$stmtExp = $pdo->prepare("
    SELECT
        a.id AS assignment_id,
        a.title,
        a.experiment_order,
        a.due_date,
        s.name AS subject_name,
        es.rough_status,
        es.fair_status
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    JOIN subject_students ss ON ss.subject_id = s.id AND ss.student_id = ?
    LEFT JOIN experiment_status es ON es.assignment_id = a.id AND es.student_id = ?
    WHERE a.type = 'experiment'
    ORDER BY s.name, a.experiment_order ASC
");
$stmtExp->execute([$studentId, $studentId]);
$experiments = $stmtExp->fetchAll();

// Build a lookup: subject_name -> list of experiments (for sequential locking)
$expBySubject = [];
foreach ($experiments as $exp) {
    $expBySubject[$exp['subject_name']][] = $exp;
}

// ── Assignments ──
$stmtAsgn = $pdo->prepare("
    SELECT
        a.id AS assignment_id,
        a.title,
        a.due_date,
        a.total_marks,
        s.name AS subject_name,
        sub.status,
        sub.final_mark,
        sub.assistant_notes,
        sub.attempt_count
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    JOIN subject_students ss ON ss.subject_id = s.id AND ss.student_id = ?
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE a.type = 'assignment'
    ORDER BY a.due_date ASC
");
$stmtAsgn->execute([$studentId, $studentId]);
$assignments = $stmtAsgn->fetchAll();

// ── Exams ──
$stmtExam = $pdo->prepare("
    SELECT
        a.title,
        a.due_date,
        a.total_marks,
        er.attendance,
        er.mark,
        er.pass_fail
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    JOIN subject_students ss ON ss.subject_id = s.id AND ss.student_id = ?
    LEFT JOIN exam_results er ON er.assignment_id = a.id AND er.student_id = ?
    WHERE a.type IN ('class_exam', 'lab_exam')
    ORDER BY a.due_date ASC
");
$stmtExam->execute([$studentId, $studentId]);
$exams = $stmtExam->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — AcadVerify</title>
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
            top: 0;
            z-index: 100;
        }
        .navbar .brand {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .navbar .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .bell-wrap {
            position: relative;
            cursor: pointer;
        }
        .bell-wrap svg { width: 22px; height: 22px; fill: #94a3b8; }
        .bell-badge {
            position: absolute;
            top: -6px; right: -8px;
            background: #ef4444;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            width: 18px; height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logout-link {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.2s;
        }
        .logout-link:hover { color: #f87171; }

        /* ── Layout ── */
        .container { max-width: 1120px; margin: 0 auto; padding: 32px 24px; }

        .greeting {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .greeting span { color: #818cf8; }

        /* ── Stats Row ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 22px 20px;
            text-align: center;
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #6366f1, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .stat-card .stat-label {
            font-size: 0.8rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 4px;
        }

        /* ── Section ── */
        .section-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid #334155;
        }
        .section { margin-bottom: 44px; }

        /* ── Table ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th {
            text-align: left;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            padding: 10px 14px;
            border-bottom: 1px solid #334155;
        }
        .data-table td {
            padding: 14px;
            border-bottom: 1px solid #1e293b;
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .data-table tbody tr {
            transition: background 0.15s;
        }
        .data-table tbody tr:hover {
            background: rgba(99, 102, 241, 0.06);
        }

        /* ── Pills ── */
        .pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .pill-green  { background: #065f46; color: #6ee7b7; }
        .pill-yellow { background: #713f12; color: #fde047; }
        .pill-red    { background: #7f1d1d; color: #fca5a5; }
        .pill-gray   { background: #334155; color: #94a3b8; }
        .pill-blue   { background: #1e3a5f; color: #7dd3fc; }

        /* ── Status badges ── */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .status-not-started { background: #334155; color: #94a3b8; }
        .status-rough-pending { background: #713f12; color: #fde047; }
        .status-rough-signed  { background: #065f46; color: #6ee7b7; }
        .status-fair-pending  { background: #1e3a5f; color: #7dd3fc; }
        .status-fair-submitted { background: #312e81; color: #a5b4fc; }
        .status-approved { background: #065f46; color: #6ee7b7; }
        .status-rejected { background: #7f1d1d; color: #fca5a5; }
        .status-pending  { background: #713f12; color: #fde047; }

        /* ── Buttons ── */
        .btn {
            display: inline-block;
            padding: 6px 14px;
            border: none;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.85; }
        .btn-primary { background: #6366f1; color: #fff; }
        .btn-success { background: #059669; color: #fff; }
        .btn-disabled {
            background: #334155;
            color: #64748b;
            cursor: not-allowed;
            pointer-events: none;
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #6366f1;
            color: #818cf8;
        }

        .mark-display {
            font-weight: 700;
            color: #a78bfa;
        }
        .feedback-text {
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 2px;
        }

        .pass { color: #6ee7b7; font-weight: 600; }
        .fail { color: #fca5a5; font-weight: 600; }

        .locked-hint {
            font-size: 0.72rem;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .navbar { padding: 14px 16px; }
            .container { padding: 20px 14px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .data-table { font-size: 0.82rem; }
        }
    </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
    <div class="brand">AcadVerify</div>
    <div class="nav-right">
        <div class="bell-wrap" title="Notifications">
            <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5S10.5 3.17 10.5 4v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
            <?php if ($unreadCount > 0): ?>
                <span class="bell-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
            <?php endif; ?>
        </div>
        <a href="/acadverify/logout.php" class="logout-link">Logout</a>
    </div>
</nav>

<div class="container">

    <!-- ── Greeting ── -->
    <h1 class="greeting">Hello, <span><?= htmlspecialchars($studentName) ?></span> 👋</h1>

    <!-- ── Stats Row ── -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-value"><?= $roughSignedCount ?></div>
            <div class="stat-label">Rough Signed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $fairSubmittedCount ?></div>
            <div class="stat-label">Fair Submitted</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $assignmentsMarked ?></div>
            <div class="stat-label">Assignments Marked</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $passRate ?>%</div>
            <div class="stat-label">Exam Pass Rate</div>
        </div>
    </div>

    <!-- ── Experiments ── -->
    <div class="section">
        <h2 class="section-title">🧪 Experiments</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Title</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Due Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($expBySubject as $subjectName => $subjectExps): ?>
                <?php foreach ($subjectExps as $i => $exp):
                    // Determine status
                    $roughStatus = $exp['rough_status'] ?? null;
                    $fairStatus  = $exp['fair_status'] ?? null;

                    if ($roughStatus === null) {
                        $statusLabel = 'Not Started';
                        $statusClass = 'status-not-started';
                    } elseif ($roughStatus === 'pending') {
                        $statusLabel = 'Rough Pending';
                        $statusClass = 'status-rough-pending';
                    } elseif ($roughStatus === 'signed' && $fairStatus === 'pending') {
                        $statusLabel = 'Fair Pending';
                        $statusClass = 'status-fair-pending';
                    } elseif ($roughStatus === 'signed' && $fairStatus === 'submitted') {
                        $statusLabel = 'Fair Submitted';
                        $statusClass = 'status-fair-submitted';
                    } else {
                        $statusLabel = 'Rough Signed';
                        $statusClass = 'status-rough-signed';
                    }

                    // Sequential locking: check if previous experiment's rough is signed
                    $order = (int) $exp['experiment_order'];
                    $isLocked = false;
                    if ($order > 1) {
                        // Find previous experiment in same subject
                        $prevExp = $subjectExps[$i - 1] ?? null;
                        if ($prevExp) {
                            $prevRough = $prevExp['rough_status'] ?? null;
                            if ($prevRough !== 'signed') {
                                $isLocked = true;
                            }
                        }
                    }

                    // Determine action button
                    if ($isLocked) {
                        $actionHtml = '<span class="locked-hint">🔒 Complete Exp ' . ($order - 1) . ' first</span>';
                    } elseif ($roughStatus === 'signed' && $fairStatus !== 'submitted') {
                        $actionHtml = '<a href="/acadverify/student/upload.php?id=' . $exp['assignment_id'] . '&type=fair" class="btn btn-success">Upload Fair</a>';
                    } elseif ($roughStatus === null || $roughStatus === 'pending') {
                        $actionHtml = '<a href="/acadverify/student/upload.php?id=' . $exp['assignment_id'] . '&type=rough" class="btn btn-primary">Upload Rough</a>';
                    } else {
                        $actionHtml = '<span class="pill pill-gray">Done</span>';
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars($exp['experiment_order']) ?></td>
                    <td><?= htmlspecialchars($exp['title']) ?></td>
                    <td><?= htmlspecialchars($exp['subject_name']) ?></td>
                    <td><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                    <td><span class="due-pill" data-due="<?= htmlspecialchars($exp['due_date'] ?? '') ?>"><?= $exp['due_date'] ? htmlspecialchars($exp['due_date']) : '—' ?></span></td>
                    <td><?= $actionHtml ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (empty($experiments)): ?>
                <tr><td colspan="6" style="text-align:center;color:#64748b;padding:30px;">No experiments found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Assignments ── -->
    <div class="section">
        <h2 class="section-title">📝 Assignments</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Due Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $asgn):
                $status  = $asgn['status'] ?? null;
                $mark    = $asgn['final_mark'];
                $notes   = $asgn['assistant_notes'];

                if ($status === null) {
                    $statusLabel = 'Not Submitted';
                    $statusClass = 'status-not-started';
                } elseif ($status === 'pending') {
                    $statusLabel = 'Pending Review';
                    $statusClass = 'status-pending';
                } elseif ($status === 'approved') {
                    $statusLabel = 'Approved';
                    $statusClass = 'status-approved';
                } elseif ($status === 'rejected') {
                    $statusLabel = 'Rejected';
                    $statusClass = 'status-rejected';
                } else {
                    $statusLabel = ucfirst($status);
                    $statusClass = 'status-not-started';
                }
            ?>
            <tr>
                <td><?= htmlspecialchars($asgn['title']) ?></td>
                <td><?= htmlspecialchars($asgn['subject_name']) ?></td>
                <td><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                <td><span class="due-pill" data-due="<?= htmlspecialchars($asgn['due_date'] ?? '') ?>"><?= $asgn['due_date'] ? htmlspecialchars($asgn['due_date']) : '—' ?></span></td>
                <td>
                    <?php if ($mark !== null): ?>
                        <span class="mark-display"><?= $mark ?>/<?= $asgn['total_marks'] ?></span>
                        <?php if ($notes): ?>
                            <div class="feedback-text"><?= htmlspecialchars($notes) ?></div>
                        <?php endif; ?>
                    <?php elseif ($status === 'rejected'): ?>
                        <a href="/acadverify/student/submit.php?id=<?= $asgn['assignment_id'] ?>" class="btn btn-primary">Resubmit</a>
                    <?php elseif ($status === null): ?>
                        <a href="/acadverify/student/submit.php?id=<?= $asgn['assignment_id'] ?>" class="btn btn-primary">Submit</a>
                    <?php else: ?>
                        <span class="pill pill-gray">Under Review</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($assignments)): ?>
                <tr><td colspan="5" style="text-align:center;color:#64748b;padding:30px;">No assignments yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Exams ── -->
    <div class="section">
        <h2 class="section-title">📊 Exams</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Exam</th>
                    <th>Date</th>
                    <th>Attendance</th>
                    <th>Mark</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($exams as $exam): ?>
            <tr>
                <td><?= htmlspecialchars($exam['title']) ?></td>
                <td><span class="due-pill" data-due="<?= htmlspecialchars($exam['due_date'] ?? '') ?>"><?= $exam['due_date'] ? htmlspecialchars($exam['due_date']) : '—' ?></span></td>
                <td>
                    <?php if ($exam['attendance']): ?>
                        <span class="pill <?= $exam['attendance'] === 'present' ? 'pill-green' : 'pill-red' ?>">
                            <?= ucfirst($exam['attendance']) ?>
                        </span>
                    <?php else: ?>
                        <span class="pill pill-gray">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($exam['mark'] !== null): ?>
                        <span class="mark-display"><?= $exam['mark'] ?>/<?= $exam['total_marks'] ?></span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($exam['pass_fail']): ?>
                        <span class="<?= $exam['pass_fail'] === 'pass' ? 'pass' : 'fail' ?>">
                            <?= strtoupper($exam['pass_fail']) ?>
                        </span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($exams)): ?>
                <tr><td colspan="5" style="text-align:center;color:#64748b;padding:30px;">No exams recorded.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- ── Due-date colour coding (JavaScript) ── -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pills = document.querySelectorAll('.due-pill');
    const now   = new Date();
    now.setHours(0, 0, 0, 0);

    pills.forEach(function (el) {
        const raw = el.getAttribute('data-due');
        if (!raw) return;

        const due  = new Date(raw + 'T00:00:00');
        const diff = Math.ceil((due - now) / (1000 * 60 * 60 * 24));

        el.classList.add('pill');
        if (diff < 0) {
            el.classList.add('pill-red');      // overdue
        } else if (diff <= 2) {
            el.classList.add('pill-yellow');   // within 2 days
        } else {
            el.classList.add('pill-green');    // 2+ days away
        }
    });
});
</script>

</body>
</html>
