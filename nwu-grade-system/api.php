<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'grade_opening_system';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed.']));
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

function getUser() {
    return $_SESSION['user'] ?? null;
}

// " Remarks: ..." suffix for a notification ('' when there are none); trimmed so the message always fits
function remarksNote($remarks) {
    $remarks = trim($remarks);
    if ($remarks === '') return '';
    return ' Remarks: ' . (preg_replace('/^(.{80}).+$/us', '$1...', $remarks) ?? $remarks);
}

// Add one notification row
function notify($pdo, $to, $rid, $msg, $now) {
    $pdo->prepare("INSERT INTO notifications (recipient_user_id, request_id, message, sent_at) VALUES (?, ?, ?, ?)")
        ->execute([$to, $rid, $msg, $now]);
}

if ($action === 'login') {
    $username = trim($input['username'] ?? $input['u'] ?? '');
    $password = $input['p'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['error' => 'Invalid username or password.']);
    }
}

elseif ($action === 'register') {
    $username = trim($input['username'] ?? $input['u'] ?? '');

    $stmt = $pdo->prepare("SELECT username FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) die(json_encode(['error' => 'Username already taken.']));

    $hash = password_hash($input['p'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, college, dept) VALUES (?, ?, ?, 'instructor', ?, ?)");
    $stmt->execute([$username, $hash, $input['name'], $input['college'], $input['dept']]);

    echo json_encode(['success' => true]);
}

elseif ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
}

elseif ($action === 'get_data') {
    $user = getUser();
    if (!$user) die(json_encode(['user' => null]));

    // instructor_name feeds the "Instructor" column and the "Name of Requesting Instructor" field
    $q = "SELECT r.*, iu.full_name AS instructor_name
          FROM grade_opening_requests r
          LEFT JOIN users iu ON iu.username = r.instructor_id";
    $params = [];

    if ($user['role'] === 'instructor') {
        // own requests, every status
        $q .= " WHERE r.instructor_id = ?";
        $params[] = $user['username'];
    } elseif ($user['role'] === 'dean') {
        // own college, every status
        $q .= " WHERE r.college = ?";
        $params[] = $user['college'];
    } elseif ($user['role'] === 'vpaa') {
        // everything the Dean has acted on, including Dean rejections
        $q .= " WHERE r.status <> 'Pending Dean Approval'";
    } elseif ($user['role'] === 'cict') {
        // approved by VPAA, opened, or rejected by either approver
        $q .= " WHERE r.status IN ('Approved - Awaiting CICT', 'Grades Opened', 'Rejected by Dean', 'Rejected by VPAA')";
    }

    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_requests = [];
    foreach ($requests as $r) {
        $stmt_p = $pdo->prepare("SELECT period FROM request_periods WHERE request_id = ?");
        $stmt_p->execute([$r['request_id']]);
        $per = $stmt_p->fetchAll(PDO::FETCH_COLUMN);

        $stmt_app = $pdo->prepare("SELECT a.*, u.full_name, u.role FROM approvals a JOIN users u ON a.approver_user_id = u.username WHERE a.request_id = ?");
        $stmt_app->execute([$r['request_id']]);
        $approvals = $stmt_app->fetchAll(PDO::FETCH_ASSOC);

        $jsReq = [
            'id' => $r['request_id'],
            'by' => $r['instructor_id'],
            'name' => $r['instructor_name'] ?? $r['instructor_id'],
            'college' => $r['college'],
            'ay' => $r['academic_year'],
            'date' => $r['date_submitted'],
            'created' => $r['created_at'],
            'code' => $r['subject_code'],
            'title' => $r['subject_title'],
            'section' => $r['section'],
            'calendar' => $r['calendar'],
            'per' => $per,
            'reason' => $r['reason'],
            'attachment' => $r['attachment'],
            'status' => $r['status']
        ];

        foreach ($approvals as $app) {
            $roleKey = $app['role'];
            if ($roleKey === 'dean' || $roleKey === 'vpaa') {
                $jsReq[$roleKey] = [
                    'by' => $app['full_name'],
                    'at' => $app['decision_date'],
                    'ok' => (bool)$app['decision'],
                    'remarks' => $app['remarks']
                ];
            } elseif ($roleKey === 'cict') {
                $openedAt = $app['decision_date'];
                $expTime = $openedAt ? date('Y-m-d H:i:s', strtotime($openedAt . ' +48 hours')) : null;
                $jsReq['cict'] = [
                    'by' => $app['full_name'],
                    'openedAt' => $openedAt,
                    'exp' => $expTime,
                    'remarks' => $app['remarks']
                ];
            }
        }

        $formatted_requests[] = $jsReq;
    }

    $n_stmt = $pdo->prepare("SELECT * FROM notifications WHERE recipient_user_id = ? OR (recipient_user_id = ? AND college_to = ?) ORDER BY notification_id DESC LIMIT 50");
    $n_stmt->execute([$user['username'], $user['role'], $user['college']]);
    $notifs = [];
    while ($n = $n_stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifs[] = [
            'id' => $n['notification_id'],
            'to' => $n['recipient_user_id'],
            'rid' => $n['request_id'],
            'msg' => $n['message'],
            'at' => $n['sent_at'],
            'read' => (bool)$n['is_read']
        ];
    }

    echo json_encode(['user' => $user, 'requests' => $formatted_requests, 'notifs' => $notifs]);
}

elseif ($action === 'submit') {
    $u = getUser();
    if (!$u || $u['role'] !== 'instructor') die(json_encode(['error' => 'Unauthorized']));

    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM grade_opening_requests WHERE request_id LIKE ?");
    $stmt->execute(["GOR-$year-%"]);
    $num = $stmt->fetchColumn() + 1;
    $id = sprintf("GOR-%s-%04d", $year, $num);
    $now = date('Y-m-d H:i:s');

    // Handle File Upload (only the file types the form allows)
    $attachment = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], true)) {
            die(json_encode(['error' => 'Unsupported file type. Allowed: PDF, JPG, PNG, DOC, DOCX.']));
        }
        $filename = $id . '_' . time() . '.' . $ext;
        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        move_uploaded_file($_FILES['attachment']['tmp_name'], 'uploads/' . $filename);
        $attachment = 'uploads/' . $filename;
    }

    $stmt = $pdo->prepare("INSERT INTO grade_opening_requests (request_id, instructor_id, college, academic_year, date_submitted, created_at, subject_code, subject_title, section, calendar, reason, status, attachment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Dean Approval', ?)");
    $stmt->execute([$id, $u['username'], $_POST['college'], $_POST['ay'], $_POST['date'], $now, $_POST['code'], $_POST['title'], $_POST['section'], $_POST['calendar'], $_POST['reason'], $attachment]);

    $stmt_p = $pdo->prepare("INSERT INTO request_periods (request_id, period) VALUES (?, ?)");
    if (isset($_POST['per']) && is_array($_POST['per'])) {
        foreach ($_POST['per'] as $p) {
            $stmt_p->execute([$id, $p]);
        }
    }

    $stmt_n = $pdo->prepare("INSERT INTO notifications (recipient_user_id, college_to, request_id, message, sent_at) VALUES ('dean', ?, ?, ?, ?)");
    $stmt_n->execute([$_POST['college'], $id, "New request $id from {$u['full_name']} ({$_POST['code']}) awaits your recommendation.", $now]);

    echo json_encode(['success' => true, 'id' => $id]);
}

elseif ($action === 'decide') {
    $u = getUser();
    if (!$u || $u['role'] === 'instructor') die(json_encode(['error' => 'Unauthorized']));

    $id  = $input['id'] ?? '';
    $ok  = !empty($input['ok']) ? 1 : 0;
    $rm  = trim($input['remarks'] ?? '');
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("SELECT * FROM grade_opening_requests WHERE request_id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    // Each role can only act on a request that is waiting for that role
    $need = [
        'dean' => 'Pending Dean Approval',
        'vpaa' => 'Pending VPAA Approval',
        'cict' => 'Approved - Awaiting CICT'
    ];
    if (!$req
        || $req['status'] !== ($need[$u['role']] ?? null)
        || ($u['role'] === 'dean' && $req['college'] !== $u['college'])) {
        die(json_encode(['error' => 'This request is not waiting for your action.']));
    }
    if ($u['role'] === 'cict' && !$ok) {
        die(json_encode(['error' => 'CICT can only open grades.']));
    }

    try {
        $pdo->beginTransaction();

        $stmt_app = $pdo->prepare("INSERT INTO approvals (request_id, approver_user_id, decision, remarks, decision_date) VALUES (?, ?, ?, ?, ?)");
        $stmt_app->execute([$id, $u['username'], $ok, $rm, $now]);

        $code = $req['subject_code'];
        $to   = $req['instructor_id'];   // the instructor is told about every step and every rejection
        $note = remarksNote($rm);

        if ($u['role'] === 'dean') {
            $st = $ok ? 'Pending VPAA Approval' : 'Rejected by Dean';
            $pdo->prepare("UPDATE grade_opening_requests SET status=? WHERE request_id=?")->execute([$st, $id]);
            if ($ok) {
                notify($pdo, 'vpaa', $id, "Request $id ($code) was recommended by the Dean.", $now);
                notify($pdo, $to, $id, "Request $id ($code) was recommended by the College Dean. Next: VPAA approval." . $note, $now);
            } else {
                notify($pdo, $to, $id, "Request $id ($code) was rejected by the College Dean." . $note, $now);
            }
        }
        elseif ($u['role'] === 'vpaa') {
            $st = $ok ? 'Approved - Awaiting CICT' : 'Rejected by VPAA';
            $pdo->prepare("UPDATE grade_opening_requests SET status=? WHERE request_id=?")->execute([$st, $id]);
            if ($ok) {
                notify($pdo, 'cict', $id, "Request $id ($code) is approved. Please open grades.", $now);
                notify($pdo, $to, $id, "Request $id ($code) was approved by the VPAA. Next: CICT will open the grades." . $note, $now);
            } else {
                notify($pdo, $to, $id, "Request $id ($code) was rejected by the VPAA." . $note, $now);
            }
        }
        elseif ($u['role'] === 'cict') {
            $pdo->prepare("UPDATE grade_opening_requests SET status='Grades Opened' WHERE request_id=?")->execute([$id]);
            notify($pdo, $to, $id, "CICT opened the grades for $code. Access started." . $note, $now);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // shows the real reason in the red toast while you develop; drop the getMessage() part before going live
        die(json_encode(['error' => 'Could not save your decision: ' . $e->getMessage()]));
    }
    echo json_encode(['success' => true]);
}

elseif ($action === 'read_notif') {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE notification_id=?")->execute([$input['id']]);
    echo json_encode(['success' => true]);
}

elseif ($action === 'read_all') {
    $u = getUser();
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE recipient_user_id=? OR (recipient_user_id=? AND college_to=?)")->execute([$u['username'], $u['role'], $u['college']]);
    echo json_encode(['success' => true]);
}

elseif ($action === 'change_password') {
    $u = getUser();
    if (!$u) die(json_encode(['error' => 'Unauthorized']));

    $currentPassword = $input['current'] ?? '';
    $newPassword = $input['new'] ?? '';

    if (empty($newPassword) || strlen($newPassword) < 6) {
        die(json_encode(['error' => 'New password must be at least 6 characters long.']));
    }

    // Fetch the user's current password hash from the database
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE username = ?");
    $stmt->execute([$u['username']]);
    $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRecord || !password_verify($currentPassword, $userRecord['password_hash'])) {
        die(json_encode(['error' => 'Incorrect current password.']));
    }

    // Generate secure Bcrypt hash for the new password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update database
    $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
    $updateStmt->execute([$newHash, $u['username']]);

    echo json_encode(['success' => true]);
}

elseif ($action === 'batch_approve') {
    $u = getUser();
    if (!$u || !in_array($u['role'], ['dean', 'vpaa'])) die(json_encode(['error' => 'Unauthorized']));

    $ids = $input['ids'] ?? [];
    if (empty($ids)) die(json_encode(['error' => 'No requests selected']));
    $now = date('Y-m-d H:i:s');

    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT * FROM grade_opening_requests WHERE request_id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) continue;

        // Only batch approve requests waiting on this role (and, for a Dean, in their own college)
        if (($u['role'] === 'dean' && ($req['status'] !== 'Pending Dean Approval' || $req['college'] !== $u['college'])) ||
            ($u['role'] === 'vpaa' && $req['status'] !== 'Pending VPAA Approval')) continue;

        $pdo->prepare("INSERT INTO approvals (request_id, approver_user_id, decision, remarks, decision_date) VALUES (?, ?, 1, 'Batch Approved', ?)")->execute([$id, $u['username'], $now]);

        $code = $req['subject_code'];
        if ($u['role'] === 'dean') {
            $pdo->prepare("UPDATE grade_opening_requests SET status='Pending VPAA Approval' WHERE request_id=?")->execute([$id]);
            notify($pdo, 'vpaa', $id, "Request $id was recommended by the Dean (Batch).", $now);
            notify($pdo, $req['instructor_id'], $id, "Request $id ($code) was recommended by the College Dean. Next: VPAA approval.", $now);
        } elseif ($u['role'] === 'vpaa') {
            $pdo->prepare("UPDATE grade_opening_requests SET status='Approved - Awaiting CICT' WHERE request_id=?")->execute([$id]);
            notify($pdo, 'cict', $id, "Request $id is approved (Batch). Please open grades.", $now);
            notify($pdo, $req['instructor_id'], $id, "Request $id ($code) was approved by the VPAA. Next: CICT will open the grades.", $now);
        }
    }
    echo json_encode(['success' => true]);
}