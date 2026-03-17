<?php
/**
 * AcadVerify — Main Professor Dashboard
 *
 * Every query is scoped to the professor's own subject via main_id.
 */
require_once __DIR__ . '/../includes/auth.php';
requireLogin('main');
require_once __DIR__ . '/../includes/db.php';

$mainId   = $_SESSION['user_id'];
$mainName = $_SESSION['name'];
$success  = '';
$error    = '';

// ── Resolve this professor's subject ──
$stmtSubj = $pdo->prepare('SELECT id, name, code FROM subjects WHERE main_id = ? LIMIT 1');
$stmtSubj->execute([$mainId]);
$subject = $stmtSubj->fetch();

if (!$subject) {
    die('<p style="color:#ef4444;text-align:center;margin-top:60px;">No subject assigned to your account.</p>');
}
$subjectId   = (int) $subject['id'];
$subjectName = $subject['name'];

// ═══════════════════════════════════════════
//  POST ACTIONS
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create Experiment ──
    if ($action === 'create_experiment') {
        $title = trim($_POST['exp_title'] ?? '');
        $desc  = trim($_POST['exp_desc'] ?? '');
        $due   = $_POST['exp_due'] ?? null;
        $order = (int) ($_POST['exp_order'] ?? 0);
        $items = array_filter(array_map('trim', $_POST['exp_checklist'] ?? []));

        if ($title === '' || $order < 1) {
            $error = 'Experiment title and order are required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO assignments (subject_id, type, title, description, due_date, experiment_order) VALUES (?, "experiment", ?, ?, ?, ?)');
            $stmt->execute([$subjectId, $title, $desc ?: null, $due ?: null, $order]);
            $newId = (int) $pdo->lastInsertId();

            foreach ($items as $itemText) {
                $pdo->prepare('INSERT INTO checklist_items (assignment_id, item_text) VALUES (?, ?)')->execute([$newId, $itemText]);
            }
            $success = "Experiment \"{$title}\" created.";
        }
    }

    // ── Create Assignment ──
    if ($action === 'create_assignment') {
        $title  = trim($_POST['asgn_title'] ?? '');
        $desc   = trim($_POST['asgn_desc'] ?? '');
        $due    = $_POST['asgn_due'] ?? null;
        $marks  = (float) ($_POST['asgn_marks'] ?? 0);
        $items  = array_filter(array_map('trim', $_POST['asgn_checklist'] ?? []));

        if ($title === '' || $marks <= 0) {
            $error = 'Assignment title and total marks are required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO assignments (subject_id, type, title, description, due_date, total_marks) VALUES (?, "assignment", ?, ?, ?, ?)');
            $stmt->execute([$subjectId, $title, $desc ?: null, $due ?: null, $marks]);
            $newId = (int) $pdo->lastInsertId();

            foreach ($items as $itemText) {
                $pdo->prepare('INSERT INTO checklist_items (assignment_id, item_text) VALUES (?, ?)')->execute([$newId, $itemText]);
            }
            $success = "Assignment \"{$title}\" created.";
        }
    }

    // ── Create Exam ──
    if ($action === 'create_exam') {
        $title    = trim($_POST['exam_title'] ?? '');
        $type     = $_POST['exam_type'] ?? 'class_exam';
        $due      = $_POST['exam_date'] ?? null;
        $marks    = (float) ($_POST['exam_marks'] ?? 0);
        $passMark = (float) ($_POST['exam_pass'] ?? 0);

        if ($title === '' || $marks <= 0) {
            $error = 'Exam name and total marks are required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO assignments (subject_id, type, title, due_date, total_marks, pass_mark) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$subjectId, $type, $title, $due ?: null, $marks, $passMark]);
            $success = "Exam \"{$title}\" created.";
        }
    }

    // ── Mark Fair as Received ──
    if ($action === 'mark_fair_received') {
        $esId      = (int) ($_POST['es_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $expTitle  = $_POST['exp_title'] ?? '';

        if ($esId > 0) {
            $stmt = $pdo->prepare("UPDATE experiment_status SET fair_status = 'submitted' WHERE id = ? AND fair_status = 'pending'");
            $stmt->execute([$esId]);
            if ($stmt->rowCount() > 0) {
                $msg = "Your fair copy for \"{$expTitle}\" has been received.";
                $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)')->execute([$studentId, $msg]);
                $success = "Fair copy received for \"{$expTitle}\".";
            }
        }
    }

    // ── Submit Assignment Mark ──
    if ($action === 'submit_mark') {
        $subId     = (int) ($_POST['sub_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $mark      = $_POST['mark'] ?? '';
        $feedback  = trim($_POST['feedback'] ?? '');
        $asgnTitle = $_POST['asgn_title'] ?? '';

        if ($mark === '') {
            $error = 'Mark is required.';
        } elseif ($subId > 0) {
            $stmt = $pdo->prepare("UPDATE submissions SET status = 'marked', final_mark = ?, main_feedback = ? WHERE id = ? AND status = 'pending_main'");
            $stmt->execute([(float) $mark, $feedback ?: null, $subId]);
            if ($stmt->rowCount() > 0) {
                $msg = "Your assignment \"{$asgnTitle}\" has been marked: {$mark} marks.";
                if ($feedback) $msg .= " Feedback: {$feedback}";
                $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)')->execute([$studentId, $msg]);
                $success = "Mark saved for \"{$asgnTitle}\".";
            }
        }
    }

    // ── Save Exam Results ──
    if ($action === 'save_exam_results') {
        $examId   = (int) ($_POST['exam_id'] ?? 0);
        $passMark = (float) ($_POST['pass_mark'] ?? 0);
        $students = $_POST['students'] ?? [];
        $saved    = 0;

        foreach ($students as $sid => $data) {
            $sid        = (int) $sid;
            $attendance = ($data['attendance'] ?? '') === 'present' ? 'present' : 'absent';
            $mark       = isset($data['mark']) && $data['mark'] !== '' ? (float) $data['mark'] : null;
            $passFail   = null;

            if ($attendance === 'present' && $mark !== null) {
                $passFail = $mark >= $passMark ? 'pass' : 'fail';
            }

            $stmtCheck = $pdo->prepare('SELECT id FROM exam_results WHERE student_id = ? AND assignment_id = ?');
            $stmtCheck->execute([$sid, $examId]);
            $existing = $stmtCheck->fetchColumn();

            if ($existing) {
                $pdo->prepare('UPDATE exam_results SET attendance = ?, mark = ?, pass_fail = ? WHERE id = ?')
                    ->execute([$attendance, $mark, $passFail, $existing]);
            } else {
                $pdo->prepare('INSERT INTO exam_results (student_id, assignment_id, attendance, mark, pass_fail) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$sid, $examId, $attendance, $mark, $passFail]);
            }
            $saved++;
        }
        $success = "Exam results saved for {$saved} student(s).";
    }
}

// ═══════════════════════════════════════════
//  FETCH DATA
// ═══════════════════════════════════════════

// Notification count
$stmtNotif = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$stmtNotif->execute([$mainId]);
$unreadCount = (int) $stmtNotif->fetchColumn();

// Pending fair copies
$pendingFair = $pdo->prepare("
    SELECT es.id AS es_id, es.student_id, u.name AS student_name,
           a.title AS exp_title, a.experiment_order
    FROM experiment_status es
    JOIN assignments a ON a.id = es.assignment_id
    JOIN users u ON u.id = es.student_id
    WHERE a.subject_id = ? AND es.rough_status = 'signed' AND es.fair_status = 'pending'
    ORDER BY a.experiment_order
");
$pendingFair->execute([$subjectId]);
$fairRows = $pendingFair->fetchAll();

// Assignments pending marking
$pendingMark = $pdo->prepare("
    SELECT sub.id AS sub_id, sub.student_id, sub.file_path, sub.final_mark,
           u.name AS student_name, a.title AS asgn_title, a.total_marks, a.due_date
    FROM submissions sub
    JOIN assignments a ON a.id = sub.assignment_id
    JOIN users u ON u.id = sub.student_id
    WHERE a.subject_id = ? AND sub.status = 'pending_main'
    ORDER BY sub.created_at ASC
");
$pendingMark->execute([$subjectId]);
$markRows = $pendingMark->fetchAll();

// Exams list
$examsStmt = $pdo->prepare("SELECT id, title, type, due_date, total_marks, pass_mark FROM assignments WHERE subject_id = ? AND type IN ('class_exam','lab_exam') ORDER BY due_date");
$examsStmt->execute([$subjectId]);
$exams = $examsStmt->fetchAll();

// Enrolled students
$enrolledStmt = $pdo->prepare("SELECT u.id, u.name FROM subject_students ss JOIN users u ON u.id = ss.student_id WHERE ss.subject_id = ? ORDER BY u.name");
$enrolledStmt->execute([$subjectId]);
$students = $enrolledStmt->fetchAll();

// ── Class overview data ──
$allExperiments = $pdo->prepare("SELECT id, title, experiment_order FROM assignments WHERE subject_id = ? AND type = 'experiment' ORDER BY experiment_order");
$allExperiments->execute([$subjectId]);
$expList = $allExperiments->fetchAll();

$allAssignments = $pdo->prepare("SELECT id, title FROM assignments WHERE subject_id = ? AND type = 'assignment' ORDER BY due_date");
$allAssignments->execute([$subjectId]);
$asgnList = $allAssignments->fetchAll();

// Build lookup maps
$expStatusMap = []; // [student_id][assignment_id] => row
$stmtES = $pdo->prepare("SELECT es.student_id, es.assignment_id, es.rough_status, es.fair_status FROM experiment_status es JOIN assignments a ON a.id = es.assignment_id WHERE a.subject_id = ?");
$stmtES->execute([$subjectId]);
foreach ($stmtES->fetchAll() as $r) {
    $expStatusMap[$r['student_id']][$r['assignment_id']] = $r;
}

$subStatusMap = [];
$stmtSS = $pdo->prepare("SELECT sub.student_id, sub.assignment_id, sub.status, sub.final_mark FROM submissions sub JOIN assignments a ON a.id = sub.assignment_id WHERE a.subject_id = ?");
$stmtSS->execute([$subjectId]);
foreach ($stmtSS->fetchAll() as $r) {
    $subStatusMap[$r['student_id']][$r['assignment_id']] = $r;
}

// Existing exam results lookup  [exam_id][student_id] => row
$examResultsMap = [];
foreach ($exams as $ex) {
    $stmtER = $pdo->prepare('SELECT student_id, attendance, mark, pass_fail FROM exam_results WHERE assignment_id = ?');
    $stmtER->execute([$ex['id']]);
    foreach ($stmtER->fetchAll() as $r) {
        $examResultsMap[$ex['id']][$r['student_id']] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professor Dashboard — AcadVerify</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}

        /* Navbar */
        .navbar{display:flex;align-items:center;justify-content:space-between;padding:16px 32px;background:#1e293b;border-bottom:1px solid #334155;position:sticky;top:0;z-index:100}
        .brand{font-size:1.25rem;font-weight:700;background:linear-gradient(135deg,#10b981,#059669);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .nav-right{display:flex;align-items:center;gap:20px}
        .bell-wrap{position:relative;cursor:pointer}
        .bell-wrap svg{width:22px;height:22px;fill:#94a3b8}
        .bell-badge{position:absolute;top:-6px;right:-8px;background:#ef4444;color:#fff;font-size:.65rem;font-weight:700;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center}
        .role-tag{font-size:.72rem;font-weight:600;background:#065f46;color:#6ee7b7;padding:3px 10px;border-radius:999px}
        .subject-tag{font-size:.78rem;color:#94a3b8}
        .logout-link{color:#94a3b8;text-decoration:none;font-size:.85rem;transition:color .2s}
        .logout-link:hover{color:#f87171}

        /* Layout */
        .container{max-width:1200px;margin:0 auto;padding:32px 24px}
        .greeting{font-size:1.5rem;font-weight:700;margin-bottom:6px}
        .greeting span{color:#10b981}

        /* Alerts */
        .alert{padding:12px 18px;border-radius:10px;font-size:.88rem;margin-bottom:20px}
        .alert-success{background:#065f46;color:#6ee7b7}
        .alert-error{background:#7f1d1d;color:#fca5a5}

        /* Tabs */
        .tabs{display:flex;gap:4px;margin-bottom:28px;border-bottom:1px solid #334155;overflow-x:auto;padding-bottom:0}
        .tab-btn{padding:10px 18px;border:none;background:transparent;color:#94a3b8;font-size:.85rem;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;transition:all .2s;white-space:nowrap}
        .tab-btn:hover{color:#e2e8f0}
        .tab-btn.active{color:#10b981;border-bottom-color:#10b981}
        .tab-panel{display:none}
        .tab-panel.active{display:block}

        /* Section */
        .section{margin-bottom:36px}
        .section-title{font-size:1.1rem;font-weight:700;margin-bottom:14px;padding-bottom:7px;border-bottom:1px solid #334155}
        .section-count{font-size:.82rem;font-weight:400;color:#64748b}

        /* Forms */
        .form-card{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:24px;margin-bottom:20px}
        .form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:14px}
        .form-group{display:flex;flex-direction:column;gap:5px}
        .form-group label{font-size:.78rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
        .form-input,.form-select,.form-textarea{padding:9px 14px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:.88rem;outline:none;transition:border-color .2s}
        .form-input:focus,.form-select:focus,.form-textarea:focus{border-color:#10b981}
        .form-textarea{resize:vertical;min-height:60px}
        .form-select{appearance:none;cursor:pointer}

        /* Checklist builder */
        .checklist-list{list-style:none;margin-bottom:8px}
        .checklist-list li{display:flex;align-items:center;gap:8px;margin-bottom:6px}
        .checklist-list li input{flex:1}
        .remove-item{background:none;border:none;color:#f87171;cursor:pointer;font-size:1.1rem;padding:2px 6px}
        .add-item-btn{font-size:.78rem;color:#10b981;cursor:pointer;border:none;background:none;font-weight:600}

        /* Cards grid */
        .card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}
        .review-card{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:20px;transition:border-color .2s}
        .review-card:hover{border-color:#475569}
        .card-student{font-weight:700;font-size:.95rem;margin-bottom:4px}
        .card-meta{font-size:.8rem;color:#94a3b8;margin-bottom:10px}
        .card-meta span{margin-right:14px}

        .photo-preview{width:100%;height:160px;object-fit:cover;border-radius:10px;background:#0f172a;margin-bottom:12px;cursor:pointer}

        /* Buttons */
        .btn{display:inline-block;padding:8px 16px;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;transition:opacity .2s}
        .btn:hover{opacity:.85}
        .btn-primary{background:#10b981;color:#fff}
        .btn-blue{background:#3b82f6;color:#fff}
        .btn-danger{background:#dc2626;color:#fff}
        .btn-outline{background:transparent;border:1px solid #334155;color:#94a3b8}
        .card-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}

        /* Pills */
        .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.72rem;font-weight:600;text-transform:uppercase}
        .pill-green{background:#065f46;color:#6ee7b7}
        .pill-yellow{background:#713f12;color:#fde047}
        .pill-red{background:#7f1d1d;color:#fca5a5}
        .pill-gray{background:#334155;color:#94a3b8}
        .pill-blue{background:#1e3a5f;color:#7dd3fc}

        /* Compact mark input */
        .mark-input{width:70px;padding:6px 10px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:.88rem;outline:none}
        .mark-input:focus{border-color:#10b981}
        .feedback-input{width:100%;padding:7px 12px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:.82rem;outline:none;margin-top:6px}
        .feedback-input:focus{border-color:#10b981}

        /* Overview table */
        .overview-wrap{overflow-x:auto}
        .overview-table{width:100%;border-collapse:collapse;font-size:.78rem;min-width:800px}
        .overview-table th{background:#1e293b;padding:8px 10px;text-align:center;border:1px solid #334155;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;position:sticky;top:0}
        .overview-table td{padding:8px 10px;border:1px solid #1e293b;text-align:center;vertical-align:middle}
        .overview-table tbody tr:hover{background:rgba(16,185,129,.06)}
        .ov-signed{color:#6ee7b7}
        .ov-pending{color:#fde047}
        .ov-na{color:#475569}
        .ov-mark{color:#a78bfa;font-weight:700}

        /* Exam table */
        .exam-section{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:22px;margin-bottom:20px}
        .exam-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
        .exam-title{font-weight:700;font-size:1rem}
        .exam-meta{font-size:.78rem;color:#94a3b8}
        .exam-table{width:100%;border-collapse:collapse}
        .exam-table th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;padding:8px 10px;border-bottom:1px solid #334155}
        .exam-table td{padding:10px;border-bottom:1px solid #1e293b;vertical-align:middle}
        .attendance-select{padding:5px 10px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;font-size:.82rem}
        .pass{color:#6ee7b7;font-weight:700}.fail{color:#fca5a5;font-weight:700}

        .empty-state{text-align:center;color:#64748b;padding:36px 20px;font-size:.9rem}

        /* Lightbox */
        .lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:999;align-items:center;justify-content:center;cursor:zoom-out}
        .lightbox.active{display:flex}
        .lightbox img{max-width:90vw;max-height:90vh;border-radius:10px}

        @media(max-width:768px){.navbar{padding:14px 16px}.container{padding:20px 14px}.card-grid{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}}
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="brand">AcadVerify</div>
    <div class="nav-right">
        <span class="role-tag">Professor</span>
        <span class="subject-tag"><?= htmlspecialchars($subjectName) ?></span>
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

    <h1 class="greeting">Hello, <span><?= htmlspecialchars($mainName) ?></span></h1>
    <p class="subject-tag" style="margin-bottom:20px">Managing: <?= htmlspecialchars($subject['code']) ?> — <?= htmlspecialchars($subjectName) ?></p>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ═══ Tabs ═══ -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="create">Create</button>
        <button class="tab-btn" data-tab="fair">Fair Records <span class="section-count">(<?= count($fairRows) ?>)</span></button>
        <button class="tab-btn" data-tab="marks">Mark Assignments <span class="section-count">(<?= count($markRows) ?>)</span></button>
        <button class="tab-btn" data-tab="exams">Exams</button>
        <button class="tab-btn" data-tab="overview">Class Overview</button>
    </div>

    <!-- ────────────────────────────────────────
         TAB 1 : CREATE
    ──────────────────────────────────────── -->
    <div class="tab-panel active" id="tab-create">

        <!-- Create Experiment -->
        <div class="section">
            <h2 class="section-title">🧪 Create Experiment</h2>
            <form method="POST" class="form-card" autocomplete="off">
                <input type="hidden" name="action" value="create_experiment">
                <div class="form-row">
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="exp_title" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label>Order # *</label>
                        <input type="number" name="exp_order" class="form-input" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="exp_due" class="form-input">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:14px">
                    <label>Description</label>
                    <textarea name="exp_desc" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Checklist Items</label>
                    <ul class="checklist-list" id="exp-checklist"></ul>
                    <button type="button" class="add-item-btn" onclick="addChecklistItem('exp-checklist','exp_checklist')">+ Add Item</button>
                </div>
                <div style="margin-top:14px"><button type="submit" class="btn btn-primary">Create Experiment</button></div>
            </form>
        </div>

        <!-- Create Assignment -->
        <div class="section">
            <h2 class="section-title">📝 Create Assignment</h2>
            <form method="POST" class="form-card" autocomplete="off">
                <input type="hidden" name="action" value="create_assignment">
                <div class="form-row">
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="asgn_title" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label>Total Marks *</label>
                        <input type="number" name="asgn_marks" class="form-input" min="1" step="0.5" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="asgn_due" class="form-input">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:14px">
                    <label>Description</label>
                    <textarea name="asgn_desc" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Checklist Items</label>
                    <ul class="checklist-list" id="asgn-checklist"></ul>
                    <button type="button" class="add-item-btn" onclick="addChecklistItem('asgn-checklist','asgn_checklist')">+ Add Item</button>
                </div>
                <div style="margin-top:14px"><button type="submit" class="btn btn-primary">Create Assignment</button></div>
            </form>
        </div>

        <!-- Create Exam -->
        <div class="section">
            <h2 class="section-title">📊 Create Exam</h2>
            <form method="POST" class="form-card" autocomplete="off">
                <input type="hidden" name="action" value="create_exam">
                <div class="form-row">
                    <div class="form-group">
                        <label>Exam Name *</label>
                        <input type="text" name="exam_title" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label>Type *</label>
                        <select name="exam_type" class="form-select">
                            <option value="class_exam">Class Exam</option>
                            <option value="lab_exam">Lab Exam</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="exam_date" class="form-input">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Total Marks *</label>
                        <input type="number" name="exam_marks" class="form-input" min="1" step="0.5" required>
                    </div>
                    <div class="form-group">
                        <label>Pass Mark</label>
                        <input type="number" name="exam_pass" class="form-input" min="0" step="0.5">
                    </div>
                </div>
                <div style="margin-top:14px"><button type="submit" class="btn btn-primary">Create Exam</button></div>
            </form>
        </div>
    </div>

    <!-- ────────────────────────────────────────
         TAB 2 : FAIR RECORDS PENDING
    ──────────────────────────────────────── -->
    <div class="tab-panel" id="tab-fair">
        <h2 class="section-title">📋 Fair Copies Pending <span class="section-count">(<?= count($fairRows) ?>)</span></h2>
        <?php if (empty($fairRows)): ?>
            <div class="empty-state">No fair copies pending. ✅</div>
        <?php else: ?>
            <div class="card-grid">
            <?php foreach ($fairRows as $fr): ?>
                <div class="review-card">
                    <div class="card-student"><?= htmlspecialchars($fr['student_name']) ?></div>
                    <div class="card-meta">
                        <span>Exp #<?= (int) $fr['experiment_order'] ?> — <?= htmlspecialchars($fr['exp_title']) ?></span>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="mark_fair_received">
                        <input type="hidden" name="es_id" value="<?= $fr['es_id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $fr['student_id'] ?>">
                        <input type="hidden" name="exp_title" value="<?= htmlspecialchars($fr['exp_title']) ?>">
                        <div class="card-actions">
                            <button type="submit" class="btn btn-primary">✓ Mark as Received</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ────────────────────────────────────────
         TAB 3 : MARK ASSIGNMENTS
    ──────────────────────────────────────── -->
    <div class="tab-panel" id="tab-marks">
        <h2 class="section-title">📝 Assignments Pending Marks <span class="section-count">(<?= count($markRows) ?>)</span></h2>
        <?php if (empty($markRows)): ?>
            <div class="empty-state">No assignments to mark. ✅</div>
        <?php else: ?>
            <div class="card-grid">
            <?php foreach ($markRows as $mr): ?>
                <div class="review-card">
                    <div class="card-student"><?= htmlspecialchars($mr['student_name']) ?></div>
                    <div class="card-meta">
                        <span>📄 <?= htmlspecialchars($mr['asgn_title']) ?></span>
                        <span class="due-pill pill" data-due="<?= htmlspecialchars($mr['due_date'] ?? '') ?>"><?= $mr['due_date'] ?: '—' ?></span>
                    </div>
                    <?php if ($mr['file_path']): ?>
                        <img src="/<?= htmlspecialchars($mr['file_path']) ?>" alt="Submission" class="photo-preview" onclick="openLightbox(this.src)">
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_mark">
                        <input type="hidden" name="sub_id" value="<?= $mr['sub_id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $mr['student_id'] ?>">
                        <input type="hidden" name="asgn_title" value="<?= htmlspecialchars($mr['asgn_title']) ?>">
                        <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
                            <label style="font-size:.78rem;color:#94a3b8">Mark:</label>
                            <input type="number" name="mark" class="mark-input" min="0" max="<?= $mr['total_marks'] ?>" step="0.5" required>
                            <span style="font-size:.78rem;color:#64748b">/ <?= $mr['total_marks'] ?></span>
                        </div>
                        <input type="text" name="feedback" class="feedback-input" placeholder="Feedback (optional)…">
                        <div class="card-actions">
                            <button type="submit" class="btn btn-blue">Submit Mark</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ────────────────────────────────────────
         TAB 4 : EXAMS
    ──────────────────────────────────────── -->
    <div class="tab-panel" id="tab-exams">
        <h2 class="section-title">📊 Exam Management</h2>
        <?php if (empty($exams)): ?>
            <div class="empty-state">No exams created yet. Use the Create tab to add one.</div>
        <?php endif; ?>

        <?php foreach ($exams as $exam): ?>
        <div class="exam-section">
            <div class="exam-header">
                <div>
                    <div class="exam-title"><?= htmlspecialchars($exam['title']) ?></div>
                    <div class="exam-meta"><?= ucfirst(str_replace('_', ' ', $exam['type'])) ?> · Total: <?= $exam['total_marks'] ?> · Pass: <?= $exam['pass_mark'] ?></div>
                </div>
                <span class="pill <?= $exam['due_date'] ? '' : 'pill-gray' ?> due-pill" data-due="<?= htmlspecialchars($exam['due_date'] ?? '') ?>"><?= $exam['due_date'] ?: 'No date' ?></span>
            </div>

            <?php if (empty($students)): ?>
                <div class="empty-state">No students enrolled.</div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_exam_results">
                <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
                <input type="hidden" name="pass_mark" value="<?= $exam['pass_mark'] ?>">
                <table class="exam-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Attendance</th>
                            <th>Mark</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $stu):
                        $er = $examResultsMap[$exam['id']][$stu['id']] ?? null;
                        $att = $er['attendance'] ?? 'present';
                        $mk  = $er['mark'] ?? '';
                        $pf  = $er['pass_fail'] ?? null;
                    ?>
                    <tr>
                        <td style="text-align:left"><?= htmlspecialchars($stu['name']) ?></td>
                        <td>
                            <select name="students[<?= $stu['id'] ?>][attendance]" class="attendance-select" onchange="toggleMarkInput(this)">
                                <option value="present" <?= $att === 'present' ? 'selected' : '' ?>>Present</option>
                                <option value="absent" <?= $att === 'absent' ? 'selected' : '' ?>>Absent</option>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="students[<?= $stu['id'] ?>][mark]"
                                   class="mark-input exam-mark-input"
                                   min="0" max="<?= $exam['total_marks'] ?>" step="0.5"
                                   value="<?= htmlspecialchars($mk) ?>"
                                   <?= $att === 'absent' ? 'disabled' : '' ?>>
                        </td>
                        <td>
                            <?php if ($pf): ?>
                                <span class="<?= $pf ?>"><?= strtoupper($pf) ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:14px;text-align:right">
                    <button type="submit" class="btn btn-primary">Save Exam Results</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ────────────────────────────────────────
         TAB 5 : CLASS OVERVIEW
    ──────────────────────────────────────── -->
    <div class="tab-panel" id="tab-overview">
        <h2 class="section-title">👥 Class Overview</h2>
        <?php if (empty($students)): ?>
            <div class="empty-state">No students enrolled in this subject.</div>
        <?php else: ?>
        <div class="overview-wrap">
            <table class="overview-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="text-align:left">Student</th>
                        <?php if (!empty($expList)): ?>
                            <th colspan="<?= count($expList) ?>">Experiments</th>
                        <?php endif; ?>
                        <?php if (!empty($asgnList)): ?>
                            <th colspan="<?= count($asgnList) ?>">Assignments</th>
                        <?php endif; ?>
                    </tr>
                    <tr>
                        <?php foreach ($expList as $e): ?>
                            <th title="<?= htmlspecialchars($e['title']) ?>">E<?= $e['experiment_order'] ?></th>
                        <?php endforeach; ?>
                        <?php foreach ($asgnList as $a): ?>
                            <th title="<?= htmlspecialchars($a['title']) ?>"><?= mb_substr($a['title'], 0, 8) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $stu): ?>
                <tr>
                    <td style="text-align:left;white-space:nowrap"><?= htmlspecialchars($stu['name']) ?></td>
                    <?php foreach ($expList as $e):
                        $es = $expStatusMap[$stu['id']][$e['id']] ?? null;
                        if (!$es) {
                            $cell = '<span class="ov-na">—</span>';
                        } elseif ($es['fair_status'] === 'submitted') {
                            $cell = '<span class="ov-signed" title="Fair submitted">✓✓</span>';
                        } elseif ($es['rough_status'] === 'signed') {
                            $cell = '<span class="ov-signed" title="Rough signed">✓</span>';
                        } else {
                            $cell = '<span class="ov-pending" title="Rough pending">⏳</span>';
                        }
                    ?>
                        <td><?= $cell ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($asgnList as $a):
                        $ss = $subStatusMap[$stu['id']][$a['id']] ?? null;
                        if (!$ss) {
                            $cell = '<span class="ov-na">—</span>';
                        } elseif ($ss['status'] === 'marked' && $ss['final_mark'] !== null) {
                            $cell = '<span class="ov-mark">' . $ss['final_mark'] . '</span>';
                        } elseif ($ss['status'] === 'rejected') {
                            $cell = '<span style="color:#fca5a5">✕</span>';
                        } else {
                            $cell = '<span class="ov-pending" title="' . htmlspecialchars($ss['status']) . '">⏳</span>';
                        }
                    ?>
                        <td><?= $cell ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /container -->

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <img src="" alt="Preview" id="lightboxImg">
</div>

<script>
/* ── Tabs ── */
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

/* ── Due-date colouring ── */
document.addEventListener('DOMContentLoaded', function() {
    var now = new Date(); now.setHours(0,0,0,0);
    document.querySelectorAll('.due-pill').forEach(function(el) {
        var raw = el.getAttribute('data-due');
        if (!raw) return;
        var due = new Date(raw + 'T00:00:00');
        var diff = Math.ceil((due - now) / 864e5);
        if (diff < 0) el.classList.add('pill-red');
        else if (diff <= 2) el.classList.add('pill-yellow');
        else el.classList.add('pill-green');
    });
});

/* ── Checklist builder ── */
function addChecklistItem(listId, inputName) {
    var ul = document.getElementById(listId);
    var li = document.createElement('li');
    li.innerHTML = '<input type="text" name="' + inputName + '[]" class="form-input" placeholder="Checklist item…" required>'
                 + '<button type="button" class="remove-item" onclick="this.parentElement.remove()">×</button>';
    ul.appendChild(li);
    li.querySelector('input').focus();
}

/* ── Exam attendance toggle ── */
function toggleMarkInput(sel) {
    var row = sel.closest('tr');
    var inp = row.querySelector('.exam-mark-input');
    if (sel.value === 'absent') {
        inp.disabled = true; inp.value = '';
    } else {
        inp.disabled = false;
    }
}

/* ── Lightbox ── */
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
