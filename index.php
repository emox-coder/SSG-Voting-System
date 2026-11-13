<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
require_once __DIR__ . '/config.php';

$db = null;
$databaseError = false;
try {
    $db = ssg_db();
} catch (PDOException | RuntimeException $exception) {
    error_log('SSG Voting database setup failed: ' . $exception->getMessage());
    $databaseError = true;
}

if ($db instanceof PDO && !session_set_save_handler(new SsgPostgresSessionHandler($db), true)) {
    throw new RuntimeException('Unable to configure PostgreSQL session storage.');
}
session_start();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function form_value(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function form_secret(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function app_redirect(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
    header('Location: index.php');
    exit;
}

function require_admin(): void
{
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Please sign in with an administrator account to do that.');
    }
}

function require_valid_password(string $password): void
{
    if (strlen($password) < 10 || strlen($password) > 72) {
        throw new RuntimeException('Passwords must be between 10 and 72 characters.');
    }
}

function has_ballots(PDO $db): bool
{
    return (int) ssg_one($db, 'SELECT COUNT(*) AS total FROM ballots')['total'] > 0;
}

if ($db instanceof PDO && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), form_value('csrf_token'))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $action = form_value('action');
        if ($action === 'setup_admin') {
            if ((int) ssg_one($db, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")['total'] > 0) {
                throw new RuntimeException('An administrator account already exists. Please sign in.');
            }

            $db->beginTransaction();
            try {
                $lock = ssg_one(
                    $db,
                    'SELECT CASE WHEN pg_try_advisory_xact_lock(1981510295) THEN 1 ELSE 0 END AS acquired'
                );
                if ((int) ($lock['acquired'] ?? 0) !== 1) {
                    throw new RuntimeException('Initial setup is busy. Please try again.');
                }

                if ((int) ssg_one($db, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")['total'] > 0) {
                    throw new RuntimeException('An administrator account already exists. Please sign in.');
                }

                $username = strtolower(form_value('username'));
                $displayName = form_value('display_name');
                $password = form_secret('password');
                require_valid_password($password);
                if (!preg_match('/\A[a-z0-9._-]{3,32}\z/', $username) || $displayName === '' || strlen($displayName) > 100) {
                    throw new RuntimeException('Enter a valid name and username (3–32 letters, numbers, dots, dashes, or underscores).');
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $statement = ssg_execute(
                    $db,
                    "INSERT INTO users (username, display_name, password_hash, role)
                    VALUES (?, ?, ?, 'admin') RETURNING id",
                    [$username, $displayName, $hash]
                );
                $adminId = (int) $statement->fetchColumn();
                $db->commit();
                $_SESSION['user'] = [
                    'id' => $adminId,
                    'username' => $username,
                    'display_name' => $displayName,
                    'role' => 'admin',
                ];
                session_regenerate_id(true);
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            app_redirect('Administrator account created. Add positions, candidates, and student accounts to begin.');
        }

        if ($action === 'login') {
            $username = strtolower(form_value('username'));
            $password = form_secret('password');
            $user = ssg_one(
                $db,
                'SELECT id, username, display_name, password_hash, role FROM users WHERE username = ?',
                [$username]
            );
            if (!$user || !password_verify($password, $user['password_hash'])) {
                throw new RuntimeException('The username or password is incorrect.');
            }

            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'display_name' => $user['display_name'],
                'role' => $user['role'],
            ];
            app_redirect('Welcome, ' . $user['display_name'] . '.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
            }
            session_destroy();
            header('Location: index.php');
            exit;
        }

        if ($action === 'cast_vote') {
            if (($_SESSION['user']['role'] ?? '') !== 'student') {
                throw new RuntimeException('Only signed-in student accounts can cast a ballot.');
            }

            $db->beginTransaction();
            try {
                $settings = ssg_one(
                    $db,
                    'SELECT is_open, results_published FROM election_settings WHERE id = 1 FOR SHARE'
                );
                if (!$settings || (int) $settings['is_open'] !== 1 || (int) $settings['results_published'] === 1) {
                    throw new RuntimeException('Voting is not currently open.');
                }

                $voterId = (int) $_SESSION['user']['id'];
                try {
                    ssg_execute($db, 'INSERT INTO voter_participation (voter_id) VALUES (?)', [$voterId]);
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23505') {
                        throw new RuntimeException('This account has already submitted its ballot.');
                    }
                    throw $exception;
                }

                $positions = ssg_all($db, 'SELECT id, name FROM positions ORDER BY id');
                if (!$positions) {
                    throw new RuntimeException('The election has no positions to vote for.');
                }
                $choices = $_POST['votes'] ?? [];
                if (!is_array($choices)) {
                    throw new RuntimeException('Please select one candidate for every position.');
                }

                foreach ($positions as $position) {
                    $positionId = (int) $position['id'];
                    $choice = $choices[(string) $positionId] ?? null;
                    if (!is_string($choice) || !ctype_digit($choice)) {
                        throw new RuntimeException('Please select one candidate for every position.');
                    }
                    $candidateId = (int) $choice;
                    if (!ssg_one(
                        $db,
                        'SELECT id FROM candidates WHERE id = ? AND position_id = ?',
                        [$candidateId, $positionId]
                    )) {
                        throw new RuntimeException('One of your selections is invalid. Please review your ballot.');
                    }
                    ssg_execute(
                        $db,
                        'INSERT INTO ballots (position_id, candidate_id) VALUES (?, ?)',
                        [$positionId, $candidateId]
                    );
                }
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollBack();
                throw $exception;
            }
            app_redirect('Your ballot was recorded. You can only vote once in this election.');
        }

        if (str_starts_with($action, 'admin_')) {
            require_admin();
        }

        if ($action === 'admin_add_student') {
            $username = strtolower(form_value('username'));
            $displayName = form_value('display_name');
            $password = form_secret('password');
            require_valid_password($password);
            if (!preg_match('/\A[a-z0-9._-]{3,32}\z/', $username) || $displayName === '' || strlen($displayName) > 100) {
                throw new RuntimeException('Enter a valid student name and username.');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            ssg_execute(
                $db,
                "INSERT INTO users (username, display_name, password_hash, role)
                VALUES (?, ?, ?, 'student')",
                [$username, $displayName, $hash]
            );
            app_redirect('Student account created. Share the username and password securely with the student.');
        }

        $candidateActions = [
            'admin_add_position',
            'admin_add_candidate',
            'admin_edit_position',
            'admin_delete_position',
            'admin_edit_candidate',
            'admin_delete_candidate',
        ];
        if (in_array($action, $candidateActions, true)) {
            $db->beginTransaction();
            try {
                $settings = ssg_one($db, 'SELECT is_open FROM election_settings WHERE id = 1 FOR UPDATE');
                if ((int) $settings['is_open'] === 1) {
                    throw new RuntimeException('Close voting before editing the election.');
                }
                if (has_ballots($db)) {
                    throw new RuntimeException('Election positions and candidates are locked after the first ballot is submitted.');
                }

                if ($action === 'admin_add_position') {
                    $name = form_value('position_name');
                    if ($name === '' || strlen($name) > 80) {
                        throw new RuntimeException('Position names must be between 1 and 80 characters.');
                    }
                    ssg_execute($db, 'INSERT INTO positions (name) VALUES (?)', [$name]);
                    $db->commit();
                    app_redirect('Position added.');
                }

                if ($action === 'admin_edit_position') {
                    $positionIdText = form_value('position_id');
                    $name = form_value('position_name');
                    if (!ctype_digit($positionIdText) || $name === '' || strlen($name) > 80) {
                        throw new RuntimeException('Enter a valid position name.');
                    }
                    $positionId = (int) $positionIdText;
                    $statement = ssg_execute($db, 'UPDATE positions SET name = ? WHERE id = ?', [$name, $positionId]);
                    if ($statement->rowCount() === 0) {
                        if (!ssg_one($db, 'SELECT id FROM positions WHERE id = ?', [$positionId])) {
                            throw new RuntimeException('That position no longer exists.');
                        }
                    }
                    $db->commit();
                    app_redirect('Position updated.');
                }

                if ($action === 'admin_delete_position') {
                    $positionIdText = form_value('position_id');
                    if (!ctype_digit($positionIdText)) {
                        throw new RuntimeException('Choose a valid position to remove.');
                    }
                    $positionId = (int) $positionIdText;
                    if (!ssg_one($db, 'SELECT id FROM positions WHERE id = ?', [$positionId])) {
                        throw new RuntimeException('That position no longer exists.');
                    }
                    ssg_execute($db, 'DELETE FROM candidates WHERE position_id = ?', [$positionId]);
                    ssg_execute($db, 'DELETE FROM positions WHERE id = ?', [$positionId]);
                    $db->commit();
                    app_redirect('Position and its candidates removed.');
                }

                if ($action === 'admin_edit_candidate') {
                    $candidateIdText = form_value('candidate_id');
                    $positionIdText = form_value('position_id');
                    $name = form_value('candidate_name');
                    $statementText = form_value('candidate_statement');
                    if (!ctype_digit($candidateIdText) || !ctype_digit($positionIdText)
                        || $name === '' || strlen($name) > 100
                        || $statementText === '' || strlen($statementText) > 3000) {
                        throw new RuntimeException('Enter a valid candidate name, statement, and position.');
                    }
                    $candidateId = (int) $candidateIdText;
                    $positionId = (int) $positionIdText;
                    if (!ssg_one($db, 'SELECT id FROM candidates WHERE id = ?', [$candidateId])) {
                        throw new RuntimeException('That candidate no longer exists.');
                    }
                    ssg_execute(
                        $db,
                        'UPDATE candidates SET position_id = ?, name = ?, statement = ? WHERE id = ?',
                        [$positionId, $name, $statementText, $candidateId]
                    );
                    $db->commit();
                    app_redirect('Candidate updated.');
                }

                if ($action === 'admin_delete_candidate') {
                    $candidateIdText = form_value('candidate_id');
                    if (!ctype_digit($candidateIdText)) {
                        throw new RuntimeException('Choose a valid candidate to remove.');
                    }
                    $candidateId = (int) $candidateIdText;
                    $statement = ssg_execute($db, 'DELETE FROM candidates WHERE id = ?', [$candidateId]);
                    if ($statement->rowCount() === 0) {
                        throw new RuntimeException('That candidate no longer exists.');
                    }
                    $db->commit();
                    app_redirect('Candidate removed.');
                }

                $positionIdText = form_value('position_id');
                $name = form_value('candidate_name');
                $statementText = form_value('candidate_statement');
                if (!ctype_digit($positionIdText) || $name === '' || strlen($name) > 100 || $statementText === '' || strlen($statementText) > 3000) {
                    throw new RuntimeException('Enter a candidate name and statement (up to 3,000 characters), and choose a position.');
                }
                $positionId = (int) $positionIdText;
                ssg_execute(
                    $db,
                    'INSERT INTO candidates (position_id, name, statement) VALUES (?, ?, ?)',
                    [$positionId, $name, $statementText]
                );
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollBack();
                throw $exception;
            }
            app_redirect('Candidate added.');
        }

        if ($action === 'admin_election') {
            $mode = form_value('mode');
            $db->beginTransaction();
            try {
                $settings = ssg_one($db, 'SELECT is_open, results_published FROM election_settings WHERE id = 1 FOR UPDATE');
                if ($mode === 'open') {
                    if ((int) $settings['results_published'] === 1) {
                        throw new RuntimeException('Results have already been published; this election cannot be reopened.');
                    }
                    if (has_ballots($db)) {
                        throw new RuntimeException('This election has already accepted ballots and cannot be reopened.');
                    }
                    $uncontested = (int) ssg_one(
                        $db,
                        'SELECT COUNT(*) AS total FROM (
                            SELECT p.id FROM positions p
                            LEFT JOIN candidates c ON c.position_id = p.id
                            GROUP BY p.id HAVING COUNT(c.id) < 2
                        ) AS incomplete'
                    )['total'];
                    $positionCount = (int) ssg_one($db, 'SELECT COUNT(*) AS total FROM positions')['total'];
                    if ($positionCount === 0 || $uncontested > 0) {
                        throw new RuntimeException('Add at least two candidates to every position before opening voting.');
                    }
                    $db->exec('UPDATE election_settings SET is_open = 1 WHERE id = 1');
                } elseif ($mode === 'close') {
                    $db->exec('UPDATE election_settings SET is_open = 0 WHERE id = 1');
                } else {
                    throw new RuntimeException('Choose a valid election action.');
                }
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollBack();
                throw $exception;
            }
            app_redirect($mode === 'open' ? 'Voting is now open.' : 'Voting is now closed.');
        }

        if ($action === 'admin_publish_results') {
            $db->beginTransaction();
            try {
                $settings = ssg_one($db, 'SELECT is_open, results_published FROM election_settings WHERE id = 1 FOR UPDATE');
                if ((int) $settings['is_open'] === 1) {
                    throw new RuntimeException('Close voting before publishing results.');
                }
                if (has_ballots($db) === false) {
                    throw new RuntimeException('There are no ballots to publish yet.');
                }
                $db->exec('UPDATE election_settings SET results_published = 1 WHERE id = 1');
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollBack();
                throw $exception;
            }
            app_redirect('Results are now available to students.');
        }

        throw new RuntimeException('That action is not available.');
    } catch (PDOException $exception) {
        error_log('SSG Voting database operation failed: ' . $exception->getMessage());
        $message = $exception->getCode() === '23505'
            ? 'That username, position, or ballot already exists.'
            : 'The request could not be saved. Check the form and try again.';
        app_redirect($message, 'error');
    } catch (RuntimeException $exception) {
        app_redirect($exception->getMessage(), 'error');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$currentUser = $_SESSION['user'] ?? null;
$adminExists = false;
$settings = ['is_open' => 0, 'results_published' => 0];
$positions = [];
$candidateRows = [];
$results = [];
$studentAccounts = [];
$alreadyVoted = false;
$ballotCount = 0;
$studentCount = 0;
$turnoutPercent = 0.0;
$candidateCounts = [];

if ($db instanceof PDO) {
    $adminExists = (int) ssg_one($db, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")['total'] > 0;
    $settings = ssg_one($db, 'SELECT is_open, results_published FROM election_settings WHERE id = 1');
    $positions = ssg_all($db, 'SELECT id, name FROM positions ORDER BY id');
    $candidateRows = ssg_all(
        $db,
        'SELECT c.id, c.position_id, c.name, c.statement, p.name AS position_name
         FROM candidates c JOIN positions p ON p.id = c.position_id
         ORDER BY p.id, c.name'
    );
    $ballotCount = (int) ssg_one($db, 'SELECT COUNT(*) AS total FROM voter_participation')['total'];

    if ((int) $settings['results_published'] === 1) {
        $results = ssg_all(
            $db,
            'SELECT p.id AS position_id, p.name AS position_name, c.id AS candidate_id, c.name AS candidate_name,
                    c.statement, COUNT(b.candidate_id) AS votes
             FROM positions p
             JOIN candidates c ON c.position_id = p.id
             LEFT JOIN ballots b ON b.candidate_id = c.id
             GROUP BY p.id, p.name, c.id, c.name, c.statement
             ORDER BY p.id, votes DESC, c.name'
        );
    }

    if ($currentUser && $currentUser['role'] === 'student') {
        $voterId = (int) $currentUser['id'];
        $alreadyVoted = (int) ssg_one(
            $db,
            'SELECT COUNT(*) AS total FROM voter_participation WHERE voter_id = ?',
            [$voterId]
        )['total'] > 0;
    }

    if ($currentUser && $currentUser['role'] === 'admin') {
        $studentAccounts = ssg_all(
            $db,
            "SELECT username, display_name, created_at FROM users WHERE role = 'student' ORDER BY display_name"
        );
        $studentCount = count($studentAccounts);
        $turnoutPercent = $studentCount > 0 ? ($ballotCount / $studentCount) * 100 : 0;
        foreach ($positions as $position) {
            $candidateCounts[(int) $position['id']] = 0;
        }
        foreach ($candidateRows as $candidate) {
            $positionId = (int) $candidate['position_id'];
            $candidateCounts[$positionId] = ($candidateCounts[$positionId] ?? 0) + 1;
        }
    }
}

$steps = [
    [
        'number' => '01',
        'title' => 'Student login',
        'description' => 'Students create an account or sign in to their registered school account.',
        'icon' => '<svg viewBox="0 0 96 76" role="img" aria-label="Student at a laptop"><circle cx="29" cy="21" r="13" fill="#f3b28c"/><path d="M16 20c0-11 7-17 16-17s15 7 15 16c-4-1-8-4-11-8-4 5-11 8-20 9Z" fill="#12396f"/><path d="M7 68c1-18 10-29 23-29s22 11 23 29" fill="#1476c8"/><rect x="17" y="52" width="70" height="21" rx="4" fill="#12396f"/><rect x="21" y="48" width="61" height="20" rx="3" fill="#2f91e1"/><circle cx="51" cy="58" r="2" fill="#fff"/><path d="M67 11 85 5l18 7-18 7-18-8Z" fill="#1476c8"/><path d="M73 19v9c7 5 17 5 24 0v-9l-12 5-12-5Z" fill="#1476c8"/><path d="M102 13v13" stroke="#12396f" stroke-width="2"/><circle cx="102" cy="29" r="2" fill="#f1ae28"/></svg>',
    ],
    [
        'number' => '02',
        'title' => 'View candidates',
        'description' => 'Students review the candidates and their positions.',
        'icon' => '<svg viewBox="0 0 96 76" role="img" aria-label="Candidates on a computer screen"><rect x="10" y="5" width="76" height="52" rx="5" fill="#12396f"/><rect x="15" y="10" width="66" height="41" rx="2" fill="#eaf5ff"/><circle cx="32" cy="24" r="7" fill="#a8bdce"/><circle cx="49" cy="22" r="8" fill="#2f91e1"/><circle cx="66" cy="24" r="7" fill="#a8bdce"/><path d="M23 45c1-9 4-13 9-13s8 4 9 13M39 45c1-11 4-16 10-16s9 5 10 16M57 45c1-9 4-13 9-13s8 4 9 13" fill="#1476c8"/><path d="M4 58h88l-5 8H10l-6-8Z" fill="#12396f"/></svg>',
    ],
    [
        'number' => '03',
        'title' => 'Cast a vote',
        'description' => 'Each student selects their preferred candidates and submits a ballot.',
        'icon' => '<svg viewBox="0 0 96 76" role="img" aria-label="Ballot box with a checked ballot"><path d="m31 8 44 2-6 34H25L31 8Z" fill="#fff" stroke="#12396f" stroke-width="3" stroke-linejoin="round"/><path d="m41 28 8 8 16-18" fill="none" stroke="#1476c8" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><path d="m13 42 13-9h51l13 9-5 28H18l-5-28Z" fill="#1476c8"/><path d="M13 42h77" stroke="#12396f" stroke-width="3"/><path d="M37 50h23" stroke="#12396f" stroke-width="3" stroke-linecap="round"/></svg>',
    ],
    [
        'number' => '04',
        'title' => 'Verify & save',
        'description' => 'The system checks voter eligibility, prevents duplicate votes, and securely records each ballot.',
        'icon' => '<svg viewBox="0 0 96 76" role="img" aria-label="Shield and secure vote storage"><path d="M34 5 63 15v20c0 17-11 27-29 35C16 62 5 52 5 35V15L34 5Z" fill="#2f91e1" stroke="#1476c8" stroke-width="3"/><path d="m20 37 9 9 19-21" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/><ellipse cx="70" cy="35" rx="20" ry="8" fill="#12396f"/><path d="M50 35v25c0 5 9 9 20 9s20-4 20-9V35" fill="#12396f"/><path d="M50 47c0 5 9 9 20 9s20-4 20-9M50 59c0 5 9 9 20 9s20-4 20-9" fill="none" stroke="#8ac4f4" stroke-width="2"/><ellipse cx="70" cy="35" rx="20" ry="8" fill="none" stroke="#8ac4f4" stroke-width="2"/></svg>',
    ],
    [
        'number' => '05',
        'title' => 'Count votes',
        'description' => 'The system totals all valid votes automatically.',
        'icon' => '<svg viewBox="0 0 96 76" role="img" aria-label="Vote count chart"><path d="M12 63V45h14v18M35 63V30h14v33M58 63V13h14v50" fill="#2f91e1"/><path d="M7 64h70" stroke="#12396f" stroke-width="4" stroke-linecap="round"/><circle cx="76" cy="52" r="13" fill="#12396f"/><circle cx="76" cy="52" r="5" fill="#eaf5ff"/><path d="m76 34 3 6 7-1 1 7 6 3-3 6 3 6-6 3-1 7-7-1-3 6-6-4-6 4-3-6-7 1-1-7-6-3 3-6-3-6 6-3 1-7 7 1 3-6 6 4 6-4Z" fill="none" stroke="#12396f" stroke-width="3" stroke-linejoin="round"/></svg>',
    ],
    [
        'number' => '06',
        'title' => 'Show results',
        'description' => 'Authorized results display the vote totals and winning candidates.',
        'icon' => '<svg viewBox="0 0 96 76" role="img" aria-label="Trophy for election results"><path d="M28 10h40v19c0 15-8 24-20 24S28 44 28 29V10Z" fill="#f3b52c"/><path d="M28 17H15v8c0 10 7 16 17 17M68 17h13v8c0 10-7 16-17 17" fill="none" stroke="#e89b12" stroke-width="5" stroke-linecap="round"/><path d="m48 16 4 8 9 1-7 6 2 9-8-5-8 5 2-9-7-6 9-1 4-8Z" fill="#fff"/><path d="M40 53h16v10H40zM33 63h30v7H33z" fill="#12396f"/><path d="M13 56c-6-7-8-15-6-23M16 54c5-7 6-13 4-19M83 56c6-7 8-15 6-23M80 54c-5-7-6-13-4-19" fill="none" stroke="#1476c8" stroke-width="3" stroke-linecap="round"/><circle cx="7" cy="30" r="3" fill="#1476c8"/><circle cx="19" cy="32" r="3" fill="#1476c8"/><circle cx="89" cy="30" r="3" fill="#1476c8"/><circle cx="77" cy="32" r="3" fill="#1476c8"/></svg>',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1269b4">
    <meta name="description" content="Sign in, vote for student leaders, and follow election results with SSGVote.">
    <title>SSGVote | Online Student Elections</title>
    <link rel="stylesheet" href="style.css">
    <script src="script.js" defer></script>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="#top" aria-label="SSG Vote home">
            <span class="brand-mark" aria-hidden="true">S</span>
            <span>SSG<span class="brand-light">Vote</span></span>
        </a>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
            <span class="menu-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Toggle navigation</span>
        </button>
        <nav class="site-nav" id="site-nav" aria-label="Main navigation">
            <a href="#portal"><?= $currentUser ? 'Dashboard' : 'Sign in' ?></a>
            <a href="#how-it-works">How it works</a>
            <?php if ($currentUser): ?>
                <form method="post" class="nav-logout">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="nav-cta" type="submit">Sign out</button>
                </form>
            <?php else: ?>
                <a class="nav-cta" href="#portal">Go to voting <span aria-hidden="true">→</span></a>
            <?php endif; ?>
        </nav>
    </header>

    <main id="top">
        <section class="hero" aria-labelledby="page-title">
            <div class="hero-copy">
                <p class="eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span> A better way to elect</p>
                <h1 id="page-title">Your voice.<br><span>Your school.</span></h1>
                <p class="hero-description">A clear, secure, and simple way for every student to take part in choosing their next student leaders.</p>
                <a class="hero-link" href="#how-it-works">See how it works <span aria-hidden="true">↓</span></a>
            </div>
            <div class="hero-art" aria-hidden="true">
                <div class="orbit orbit-one"></div>
                <div class="orbit orbit-two"></div>
                <div class="hero-card">
                    <span class="hero-card-label">YOUR BALLOT</span>
                    <span class="hero-card-line"></span>
                    <span class="hero-card-line short"></span>
                    <span class="hero-check">✓</span>
                    <span class="hero-card-caption">Your vote is counted.</span>
                </div>
                <span class="hero-spark spark-one">✳</span>
                <span class="hero-spark spark-two">✳</span>
                <span class="hero-float">ONE STUDENT<br>ONE VALID VOTE</span>
            </div>
            <div class="hero-note"><span class="note-icon" aria-hidden="true">✓</span> One student. One valid ballot.</div>
        </section>

        <section class="portal-area section-wrap" id="portal">
            <?php if ($flash): ?>
                <div class="notice notice-<?= h((string) $flash['kind']) ?>" role="status"><?= h((string) $flash['message']) ?></div>
            <?php endif; ?>

            <?php if ($databaseError): ?>
                <div class="portal-card setup-card">
                    <p class="eyebrow section-eyebrow">Database setup</p>
                    <h2>Connect the voting database</h2>
                    <p>SSGVote could not connect to PostgreSQL. Check that the database is running and that the connection settings are correct.</p>
                    <p class="help-text">Set <code>DATABASE_URL</code> to a PostgreSQL connection URL, or configure <code>PGHOST</code>, <code>PGPORT</code>, <code>PGDATABASE</code>, <code>PGUSER</code>, and <code>PGPASSWORD</code>. The database user needs permission to create tables on first run.</p>
                </div>
            <?php elseif (!$adminExists): ?>
                <div class="portal-card setup-card">
                    <p class="eyebrow section-eyebrow">First-time setup</p>
                    <h2>Create your administrator account</h2>
                    <p>This account manages the election. Choose a strong password and keep it private.</p>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="setup_admin">
                        <label>Administrator name<input name="display_name" maxlength="100" autocomplete="name" required></label>
                        <label>Username<input name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]+" autocomplete="username" required></label>
                        <label class="form-wide">Password <span class="label-hint">(10–72 characters)</span><input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
                        <button class="button button-primary form-wide" type="submit">Create admin account</button>
                    </form>
                </div>
            <?php elseif (!$currentUser): ?>
                <div class="portal-card login-card">
                    <div class="portal-intro">
                        <p class="eyebrow section-eyebrow">Ready when you are</p>
                        <h2>Sign in to SSGVote</h2>
                        <p>Sign in with the account provided by your election administrator.</p>
                    </div>
                    <form method="post" class="form-grid login-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="login">
                        <label>Username<input name="username" autocomplete="username" required></label>
                        <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                        <button class="button button-primary form-wide" type="submit">Sign in</button>
                    </form>
                </div>
            <?php elseif ($currentUser['role'] === 'admin'): ?>
                <div class="portal-heading">
                    <div>
                        <p class="eyebrow section-eyebrow">Administrator dashboard</p>
                        <h2>Welcome, <?= h((string) $currentUser['display_name']) ?></h2>
                    </div>
                    <span class="status-pill <?= (int) $settings['is_open'] === 1 ? 'status-open' : 'status-closed' ?>">
                        <?= (int) $settings['is_open'] === 1 ? 'Voting is open' : 'Voting is closed' ?>
                    </span>
                </div>
                <div class="admin-metrics" aria-label="Election overview">
                    <article class="metric-card">
                        <span class="metric-label">Student accounts</span>
                        <strong><?= number_format($studentCount) ?></strong>
                        <span class="metric-detail">eligible voters</span>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Ballots submitted</span>
                        <strong><?= number_format($ballotCount) ?></strong>
                        <span class="metric-detail">students participated</span>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Turnout</span>
                        <strong><?= number_format($turnoutPercent, 1) ?>%</strong>
                        <span class="metric-detail">of student accounts</span>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Election setup</span>
                        <strong><?= count($positions) ?></strong>
                        <span class="metric-detail"><?= count($candidateRows) ?> candidates across positions</span>
                    </article>
                </div>
                <div class="admin-grid">
                    <section class="portal-card election-card">
                        <p class="eyebrow section-eyebrow">Election controls</p>
                        <h3>Voting status</h3>
                        <p><?= number_format($ballotCount) ?> student<?= $ballotCount === 1 ? ' has' : 's have' ?> submitted a ballot.</p>
                        <?php if ((int) $settings['results_published'] === 1): ?>
                            <p class="status-note">Results are published and visible to students.</p>
                        <?php elseif ((int) $settings['is_open'] === 1): ?>
                            <form method="post" class="stack-form">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="admin_election">
                                <input type="hidden" name="mode" value="close">
                                <button class="button button-secondary" type="submit">Close voting</button>
                            </form>
                        <?php elseif ($ballotCount === 0): ?>
                            <form method="post" class="stack-form">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="admin_election">
                                <input type="hidden" name="mode" value="open">
                                <button class="button button-primary" type="submit">Open voting</button>
                            </form>
                        <?php else: ?>
                            <p class="status-note">Voting is closed. Publish the final results below.</p>
                        <?php endif; ?>
                        <?php if ((int) $settings['is_open'] === 0 && (int) $settings['results_published'] === 0 && $ballotCount > 0): ?>
                            <form method="post" class="stack-form publish-form" data-confirm-publish>
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="admin_publish_results">
                                <button class="button button-secondary" type="submit">Publish results</button>
                            </form>
                        <?php endif; ?>
                        <p class="help-text">Voting can open after every position has at least two candidates. Once the first ballot is submitted, candidates are locked. Close voting before publishing results.</p>
                    </section>

                    <section class="portal-card">
                        <p class="eyebrow section-eyebrow">Student access</p>
                        <h3>Create a student account</h3>
                        <form method="post" class="form-grid compact-form">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="admin_add_student">
                            <label>Student name<input name="display_name" maxlength="100" required></label>
                            <label>Username<input name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]+" required></label>
                            <label class="form-wide">Student password <span class="label-hint">(10–72 characters)</span><input type="password" name="password" minlength="10" maxlength="72" required></label>
                            <button class="button button-primary form-wide" type="submit">Add student</button>
                        </form>
                        <h4>Student accounts <span class="count-badge"><?= count($studentAccounts) ?></span></h4>
                        <?php if ($studentAccounts): ?>
                            <ul class="account-list">
                                <?php foreach ($studentAccounts as $account): ?>
                                    <li><span><strong><?= h($account['display_name']) ?></strong><small><?= h($account['username']) ?></small></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="help-text">Student accounts will appear here after you add them.</p>
                        <?php endif; ?>
                    </section>

                    <section class="portal-card election-setup-card">
                        <p class="eyebrow section-eyebrow">Election setup</p>
                        <h3>Positions & candidates</h3>
                        <?php if ((int) $settings['is_open'] === 1 || $ballotCount > 0): ?>
                            <p class="help-text">Election positions and candidates are locked once voting opens or a ballot is submitted.</p>
                        <?php else: ?>
                            <div class="management-forms">
                                <form method="post" class="stack-form">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="admin_add_position">
                                    <label>New position<input name="position_name" maxlength="80" placeholder="e.g. President" required></label>
                                    <button class="button button-secondary" type="submit">Add position</button>
                                </form>
                                <form method="post" class="stack-form">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="admin_add_candidate">
                                    <label>Position<select name="position_id" required>
                                        <option value="">Choose a position</option>
                                        <?php foreach ($positions as $position): ?>
                                            <option value="<?= (int) $position['id'] ?>"><?= h($position['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select></label>
                                    <label>Candidate name<input name="candidate_name" maxlength="100" required></label>
                                    <label>Candidate statement<textarea name="candidate_statement" maxlength="3000" rows="3" required></textarea></label>
                                    <button class="button button-secondary" type="submit">Add candidate</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php if ($positions): ?>
                            <h4>Manage positions</h4>
                            <ul class="candidate-list management-list">
                                <?php foreach ($positions as $position): ?>
                                    <li>
                                        <span class="management-item-heading">
                                            <strong><?= h($position['name']) ?></strong>
                                            <small><?= (int) ($candidateCounts[(int) $position['id']] ?? 0) ?> candidate<?= (int) ($candidateCounts[(int) $position['id']] ?? 0) === 1 ? '' : 's' ?></small>
                                        </span>
                                        <?php if ((int) $settings['is_open'] === 0 && $ballotCount === 0): ?>
                                            <div class="management-actions">
                                                <details class="management-details">
                                                    <summary>Edit</summary>
                                                    <form method="post" class="stack-form">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="admin_edit_position">
                                                        <input type="hidden" name="position_id" value="<?= (int) $position['id'] ?>">
                                                        <label>Position name<input name="position_name" maxlength="80" value="<?= h($position['name']) ?>" required></label>
                                                        <button class="button button-secondary" type="submit">Save position</button>
                                                    </form>
                                                </details>
                                                <form method="post" data-confirm-delete="Remove this position and all its candidates?">
                                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="admin_delete_position">
                                                    <input type="hidden" name="position_id" value="<?= (int) $position['id'] ?>">
                                                    <button class="button button-danger" type="submit">Remove</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <h4>Candidate roster</h4>
                        <?php if ($candidateRows): ?>
                            <ul class="candidate-list">
                                <?php foreach ($candidateRows as $candidate): ?>
                                    <li>
                                        <span class="management-item-heading"><strong><?= h($candidate['name']) ?></strong><small><?= h($candidate['position_name']) ?></small></span>
                                        <p><?= h($candidate['statement']) ?></p>
                                        <?php if ((int) $settings['is_open'] === 0 && $ballotCount === 0): ?>
                                            <div class="management-actions">
                                                <details class="management-details">
                                                    <summary>Edit</summary>
                                                    <form method="post" class="stack-form">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="admin_edit_candidate">
                                                        <input type="hidden" name="candidate_id" value="<?= (int) $candidate['id'] ?>">
                                                        <label>Position<select name="position_id" required>
                                                            <?php foreach ($positions as $position): ?>
                                                                <option value="<?= (int) $position['id'] ?>" <?= (int) $position['id'] === (int) $candidate['position_id'] ? 'selected' : '' ?>><?= h($position['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select></label>
                                                        <label>Candidate name<input name="candidate_name" maxlength="100" value="<?= h($candidate['name']) ?>" required></label>
                                                        <label>Candidate statement<textarea name="candidate_statement" maxlength="3000" rows="3" required><?= h($candidate['statement']) ?></textarea></label>
                                                        <button class="button button-secondary" type="submit">Save candidate</button>
                                                    </form>
                                                </details>
                                                <form method="post" data-confirm-delete="Remove this candidate from the election?">
                                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="admin_delete_candidate">
                                                    <input type="hidden" name="candidate_id" value="<?= (int) $candidate['id'] ?>">
                                                    <button class="button button-danger" type="submit">Remove</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="help-text">Add positions, then add at least two candidates to each position.</p>
                        <?php endif; ?>
                    </section>
                </div>

                <?php if ((int) $settings['results_published'] === 1): ?>
                    <section class="portal-card results-card">
                        <p class="eyebrow section-eyebrow">Final tally</p>
                        <h3>Published election results</h3>
                        <?php foreach ($positions as $position): ?>
                            <div class="result-position">
                                <h4><?= h($position['name']) ?></h4>
                                <div class="result-list">
                                    <?php foreach ($results as $result): ?>
                                        <?php if ((int) $result['position_id'] === (int) $position['id']): ?>
                                            <div class="result-row">
                                                <span><strong><?= h($result['candidate_name']) ?></strong><small><?= h($result['statement']) ?></small></span>
                                                <span class="vote-total"><?= number_format((int) $result['votes']) ?> <small><?= (int) $result['votes'] === 1 ? 'vote' : 'votes' ?></small></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            <?php else: ?>
                <div class="portal-heading">
                    <div>
                        <p class="eyebrow section-eyebrow">Student dashboard</p>
                        <h2>Hello, <?= h((string) $currentUser['display_name']) ?></h2>
                    </div>
                    <span class="status-pill <?= (int) $settings['is_open'] === 1 ? 'status-open' : 'status-closed' ?>">
                        <?= (int) $settings['is_open'] === 1 ? 'Voting is open' : 'Voting is closed' ?>
                    </span>
                </div>

                <?php if ($alreadyVoted): ?>
                    <div class="portal-card ballot-message">
                        <span class="message-icon" aria-hidden="true">✓</span>
                        <div><h3>Your ballot has been recorded.</h3><p>Thank you for taking part. Each student can submit one ballot in this election.</p></div>
                    </div>
                <?php elseif ((int) $settings['is_open'] === 1): ?>
                    <section class="portal-card ballot-card">
                        <p class="eyebrow section-eyebrow">Make your choice</p>
                        <h3>Review the candidates and cast your ballot</h3>
                        <p class="help-text">Choose one candidate for every position. Your selections are final once submitted.</p>
                        <form method="post" data-confirm-vote>
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="cast_vote">
                            <div class="ballot-positions">
                                <?php foreach ($positions as $position): ?>
                                    <fieldset class="ballot-position">
                                        <legend><?= h($position['name']) ?></legend>
                                        <div class="candidate-options">
                                            <?php foreach ($candidateRows as $candidate): ?>
                                                <?php if ((int) $candidate['position_id'] === (int) $position['id']): ?>
                                                    <label class="candidate-option">
                                                        <input type="radio" name="votes[<?= (int) $position['id'] ?>]" value="<?= (int) $candidate['id'] ?>" required>
                                                        <span><strong><?= h($candidate['name']) ?></strong><small><?= h($candidate['statement']) ?></small></span>
                                                    </label>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>
                                <?php endforeach; ?>
                            </div>
                            <button class="button button-primary submit-ballot" type="submit">Submit my ballot</button>
                        </form>
                    </section>
                <?php else: ?>
                    <div class="portal-card ballot-message">
                        <span class="message-icon message-pending" aria-hidden="true">i</span>
                        <div><h3>Voting is not open yet.</h3><p>Your administrator will open the election when everything is ready.</p></div>
                    </div>
                <?php endif; ?>

                <?php if ((int) $settings['results_published'] === 1): ?>
                    <section class="portal-card results-card">
                        <p class="eyebrow section-eyebrow">Election results</p>
                        <h3>Here are the final vote totals</h3>
                        <?php foreach ($positions as $position): ?>
                            <div class="result-position">
                                <h4><?= h($position['name']) ?></h4>
                                <div class="result-list">
                                    <?php foreach ($results as $result): ?>
                                        <?php if ((int) $result['position_id'] === (int) $position['id']): ?>
                                            <div class="result-row">
                                                <span><strong><?= h($result['candidate_name']) ?></strong><small><?= h($result['statement']) ?></small></span>
                                                <span class="vote-total"><?= number_format((int) $result['votes']) ?> <small><?= (int) $result['votes'] === 1 ? 'vote' : 'votes' ?></small></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="process section-wrap" id="how-it-works" aria-labelledby="process-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow section-eyebrow">From sign-in to results</p>
                    <h2 id="process-title">How the voting process works</h2>
                </div>
                <p>Six straightforward steps make an election easy to follow from start to finish.</p>
            </div>
            <ol class="steps">
                <?php foreach ($steps as $step): ?>
                    <li class="step-card">
                        <div class="step-top">
                            <span class="step-number"><?= htmlspecialchars($step['number'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="step-icon"><?= $step['icon'] ?></span>
                        </div>
                        <h3><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= htmlspecialchars($step['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="why section-wrap" id="why-it-works" aria-label="Built for a smooth election">
            <div class="why-block">
                <span class="why-symbol people-symbol" aria-hidden="true">
                    <svg viewBox="0 0 100 68"><circle cx="50" cy="18" r="11"/><circle cx="20" cy="26" r="9"/><circle cx="80" cy="26" r="9"/><path d="M29 64c1-18 8-28 21-28s20 10 21 28H29ZM1 64c1-15 7-23 19-23 6 0 10 2 13 7-4 7-5 11-6 16H1ZM73 64c-1-5-2-9-6-16 3-5 7-7 13-7 12 0 18 8 19 23H73Z"/></svg>
                </span>
                <div>
                    <h3>Many students can vote at once.</h3>
                    <p>Students can cast their ballots at the same time—no long lines, no paper pile-up.</p>
                </div>
            </div>
            <div class="why-divider" aria-hidden="true"></div>
            <div class="why-block network-block">
                <span class="why-symbol network-symbol" aria-hidden="true">
                    <svg viewBox="0 0 100 68"><path d="M50 4 78 14v20c0 15-10 24-28 30-18-6-28-15-28-30V14L50 4Z"/><path d="m38 34 8 8 17-19" class="network-check"/></svg>
                </span>
                <div>
                    <h3>Connected computers share the work.</h3>
                    <p>The system handles many voters at once for a smooth, fast election.</p>
                </div>
            </div>
        </section>

        <section class="promise" aria-label="Our voting promise">
            <div class="promise-inner">
                <p class="eyebrow promise-eyebrow">Simple. Secure. Fair.</p>
                <h2>One student <span aria-hidden="true">·</span> One valid ballot <span aria-hidden="true">·</span> Automatic counting</h2>
                <p>So every student voice has a chance to be heard.</p>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <a class="brand footer-brand" href="#top"><span class="brand-mark" aria-hidden="true">S</span><span>SSG<span class="brand-light">Vote</span></span></a>
        <p>Student voices shape a stronger school.</p>
        <a class="back-top" href="#top">Back to top ↑</a>
    </footer>
</body>
</html>
