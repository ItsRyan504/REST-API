<?php
// ============================================================
//  School Grading System – REST API
//  Philippine Grade Scale (1.0 – 5.0)
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --------------- Storage ---------------
define('DATA_DIR',      __DIR__ . '/data');
define('DATA_FILE',     DATA_DIR . '/students.json');
define('USERS_FILE',    DATA_DIR . '/users.json');
define('SESSIONS_FILE', DATA_DIR . '/sessions.json');
define('TOKEN_EXPIRY',  86400); // 24 hours

function initStorage(): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    if (!file_exists(DATA_FILE))
        file_put_contents(DATA_FILE,     json_encode(['students' => [], 'next_id' => 1], JSON_PRETTY_PRINT));
    if (!file_exists(USERS_FILE))
        file_put_contents(USERS_FILE,    json_encode(['users' => [],    'next_id' => 1], JSON_PRETTY_PRINT));
    if (!file_exists(SESSIONS_FILE))
        file_put_contents(SESSIONS_FILE, json_encode(['sessions' => []]              , JSON_PRETTY_PRINT));
}

function loadData(): array    { return json_decode(file_get_contents(DATA_FILE),     true); }
function saveData(array $d)   { file_put_contents(DATA_FILE,     json_encode($d, JSON_PRETTY_PRINT)); }
function loadUsers(): array   { return json_decode(file_get_contents(USERS_FILE),    true); }
function saveUsers(array $d)  { file_put_contents(USERS_FILE,    json_encode($d, JSON_PRETTY_PRINT)); }
function loadSessions(): array  { return json_decode(file_get_contents(SESSIONS_FILE), true); }
function saveSessions(array $d) { file_put_contents(SESSIONS_FILE, json_encode($d, JSON_PRETTY_PRINT)); }

// --------------- Auth Helpers ---------------
function getAuthToken(): ?string {
    $auth = '';
    if (function_exists('getallheaders')) {
        $h    = getallheaders();
        $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if (!$auth) $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : null;
}

function requireAuth(): array {
    $token = getAuthToken();
    if (!$token) respond(401, ['success' => false, 'message' => 'Unauthorized. Please log in.']);

    $sessions = loadSessions();
    $session  = null;
    foreach ($sessions['sessions'] as $s) {
        if ($s['token'] === $token) { $session = $s; break; }
    }
    if (!$session) respond(401, ['success' => false, 'message' => 'Invalid token. Please log in again.']);
    if (time() - $session['created_at'] > TOKEN_EXPIRY)
        respond(401, ['success' => false, 'message' => 'Session expired. Please log in again.']);

    $users = loadUsers();
    foreach ($users['users'] as $u) {
        if ($u['id'] === $session['user_id']) {
            unset($u['password']);
            return $u;
        }
    }
    respond(401, ['success' => false, 'message' => 'User account not found.']);
}

// --------------- Helpers ---------------
function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function calculateGWA(array $subjects): ?float {
    if (empty($subjects)) return null;
    $total = array_sum(array_column($subjects, 'grade'));
    return round($total / count($subjects), 4);
}

function getRemarks(?float $gwa): string {
    if ($gwa === null)  return 'No grades recorded';
    if ($gwa <= 1.20)  return 'Summa Cum Laude';
    if ($gwa <= 1.45)  return 'Magna Cum Laude';
    if ($gwa <= 1.75)  return 'Cum Laude';
    if ($gwa <= 3.00)  return 'Passed';
    return 'Failed';
}

function getGradeLabel(float $grade): string {
    $map = [
        1.0  => 'Excellent',
        1.25 => 'Superior',
        1.5  => 'Superior',
        1.75 => 'Very Good',
        2.0  => 'Very Good',
        2.25 => 'Good',
        2.5  => 'Satisfactory',
        2.75 => 'Satisfactory',
        3.0  => 'Passing',
        5.0  => 'Failed',
    ];
    return $map[$grade] ?? 'Unknown';
}

const VALID_GRADES = [1.0, 1.25, 1.5, 1.75, 2.0, 2.25, 2.5, 2.75, 3.0, 5.0];

function isValidGrade($grade): bool {
    return in_array((float) $grade, VALID_GRADES, true);
}

function withGWA(array $student): array {
    $gwa = calculateGWA($student['subjects']);
    $student['gwa']     = $gwa;
    $student['remarks'] = getRemarks($gwa);
    return $student;
}

function findStudent(array $data, int $id): ?int {
    foreach ($data['students'] as $i => $s) {
        if ($s['id'] === $id) return $i;
    }
    return null;
}

// --------------- Path Parsing ---------------
// Supports both PATH_INFO and REQUEST_URI fallback for XAMPP compatibility
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (empty($pathInfo)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $pathInfo = substr($requestUri, strlen($scriptName));
    if (($q = strpos($pathInfo, '?')) !== false) {
        $pathInfo = substr($pathInfo, 0, $q);
    }
}
$parts    = array_values(array_filter(explode('/', trim($pathInfo, '/'))));
$method   = $_SERVER['REQUEST_METHOD'];
$body     = json_decode(file_get_contents('php://input'), true) ?? [];

$resource = $parts[0] ?? null;           // "students"
$id       = isset($parts[1]) ? (int) $parts[1] : null;
$sub      = $parts[2] ?? null;           // "subjects" | "gwa"
$subParam = isset($parts[3]) ? urldecode($parts[3]) : null; // subject name

// --------------- Init & Gate ---------------
initStorage();

// ================================================================
//  AUTH ROUTES  (public – no token required)
// ================================================================

// POST /register
if ($method === 'POST' && $resource === 'register') {
    $username  = trim($body['username']  ?? '');
    $password  = $body['password']       ?? '';
    $full_name = trim($body['full_name'] ?? '');

    if (!$username || !$password || !$full_name)
        respond(400, ['success' => false, 'message' => 'username, password, and full_name are required.']);
    if (strlen($username) < 3)
        respond(400, ['success' => false, 'message' => 'Username must be at least 3 characters.']);
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username))
        respond(400, ['success' => false, 'message' => 'Username may only contain letters, numbers, and underscores.']);
    if (strlen($password) < 6)
        respond(400, ['success' => false, 'message' => 'Password must be at least 6 characters.']);

    $users = loadUsers();
    foreach ($users['users'] as $u)
        if (strtolower($u['username']) === strtolower($username))
            respond(409, ['success' => false, 'message' => "Username \"{$username}\" is already taken."]);

    $user = [
        'id'         => $users['next_id'],
        'username'   => $username,
        'full_name'  => $full_name,
        'password'   => password_hash($password, PASSWORD_BCRYPT),
        'role'       => 'teacher',
        'created_at' => time(),
    ];
    $users['users'][]  = $user;
    $users['next_id']++;
    saveUsers($users);
    unset($user['password']);
    respond(201, ['success' => true, 'message' => 'Account created successfully! You can now log in.', 'data' => $user]);
}

// POST /login
if ($method === 'POST' && $resource === 'login') {
    $username = trim($body['username'] ?? '');
    $password = $body['password']       ?? '';

    if (!$username || !$password)
        respond(400, ['success' => false, 'message' => 'Username and password are required.']);

    $users = loadUsers();
    $found = null;
    foreach ($users['users'] as $u)
        if (strtolower($u['username']) === strtolower($username)) { $found = $u; break; }

    if (!$found || !password_verify($password, $found['password']))
        respond(401, ['success' => false, 'message' => 'Invalid username or password.']);

    $token    = bin2hex(random_bytes(32));
    $sessions = loadSessions();
    // Invalidate previous sessions for this user
    $sessions['sessions'] = array_values(array_filter(
        $sessions['sessions'], fn($s) => $s['user_id'] !== $found['id']
    ));
    $sessions['sessions'][] = [
        'token'      => $token,
        'user_id'    => $found['id'],
        'created_at' => time(),
    ];
    saveSessions($sessions);
    unset($found['password']);
    respond(200, ['success' => true, 'message' => 'Login successful!', 'token' => $token, 'user' => $found]);
}

// POST /logout
if ($method === 'POST' && $resource === 'logout') {
    $token = getAuthToken();
    if ($token) {
        $sessions = loadSessions();
        $sessions['sessions'] = array_values(array_filter(
            $sessions['sessions'], fn($s) => $s['token'] !== $token
        ));
        saveSessions($sessions);
    }
    respond(200, ['success' => true, 'message' => 'Logged out successfully.']);
}

// GET /me
if ($method === 'GET' && $resource === 'me') {
    $user = requireAuth();
    respond(200, ['success' => true, 'data' => $user]);
}

if ($resource !== 'students') {
    respond(404, [
        'success' => false,
        'message' => 'Endpoint not found. Available resources: /register, /login, /logout, /me, /students'
    ]);
}

// ── All /students routes require a valid login token ────────
$currentUser = requireAuth();

$data = loadData();

// ================================================================
//  STUDENTS – collection routes  ( /students )
// ================================================================

// GET /students
if ($method === 'GET' && $id === null) {
    $students = array_map('withGWA', $data['students']);
    respond(200, [
        'success' => true,
        'count'   => count($students),
        'data'    => array_values($students),
    ]);
}

// POST /students  – create a new student
if ($method === 'POST' && $id === null) {
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        respond(400, ['success' => false, 'message' => 'Field "name" is required.']);
    }
    $student = [
        'id'         => $data['next_id'],
        'name'       => $name,
        'year_level' => trim($body['year_level'] ?? ''),
        'subjects'   => [],
    ];
    $data['students'][] = $student;
    $data['next_id']++;
    saveData($data);
    respond(201, [
        'success' => true,
        'message' => "Student \"{$name}\" created successfully.",
        'data'    => withGWA($student),
    ]);
}

// ================================================================
//  STUDENTS – item routes  ( /students/{id} )
// ================================================================

$idx = findStudent($data, $id);
if ($idx === null) {
    respond(404, ['success' => false, 'message' => "Student with id {$id} not found."]);
}

// GET /students/{id}
if ($method === 'GET' && $sub === null) {
    respond(200, ['success' => true, 'data' => withGWA($data['students'][$idx])]);
}

// GET /students/{id}/gwa
if ($method === 'GET' && $sub === 'gwa') {
    $s   = $data['students'][$idx];
    $gwa = calculateGWA($s['subjects']);
    respond(200, [
        'success' => true,
        'data' => [
            'student_id'     => $id,
            'name'           => $s['name'],
            'year_level'     => $s['year_level'],
            'gwa'            => $gwa,
            'remarks'        => getRemarks($gwa),
            'total_subjects' => count($s['subjects']),
            'subjects'       => $s['subjects'],
        ],
    ]);
}

// PUT /students/{id}  – update student info
if ($method === 'PUT' && $sub === null) {
    $name = trim($body['name'] ?? '');
    if ($name !== '') {
        $data['students'][$idx]['name'] = $name;
    }
    if (array_key_exists('year_level', $body)) {
        $data['students'][$idx]['year_level'] = trim($body['year_level']);
    }
    saveData($data);
    respond(200, [
        'success' => true,
        'message' => 'Student updated successfully.',
        'data'    => withGWA($data['students'][$idx]),
    ]);
}

// DELETE /students/{id}
if ($method === 'DELETE' && $sub === null) {
    $name = $data['students'][$idx]['name'];
    array_splice($data['students'], $idx, 1);
    saveData($data);
    respond(200, [
        'success' => true,
        'message' => "Student \"{$name}\" deleted successfully.",
    ]);
}

// ================================================================
//  SUBJECTS  ( /students/{id}/subjects  &  /…/subjects/{name} )
// ================================================================

if ($sub !== 'subjects') {
    respond(404, ['success' => false, 'message' => "Unknown sub-resource \"{$sub}\"."]);
}

// POST /students/{id}/subjects  – add a subject
if ($method === 'POST') {
    $subjectName = trim($body['subject_name'] ?? '');
    if ($subjectName === '') {
        respond(400, ['success' => false, 'message' => 'Field "subject_name" is required.']);
    }
    if (!isset($body['grade']) || !isValidGrade($body['grade'])) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid grade. Accepted values: ' . implode(', ', VALID_GRADES),
        ]);
    }
    // Duplicate check
    foreach ($data['students'][$idx]['subjects'] as $s) {
        if (strtolower($s['name']) === strtolower($subjectName)) {
            respond(409, [
                'success' => false,
                'message' => "Subject \"{$subjectName}\" already exists for this student.",
            ]);
        }
    }
    $grade = (float) $body['grade'];
    $data['students'][$idx]['subjects'][] = [
        'name'  => $subjectName,
        'grade' => $grade,
        'label' => getGradeLabel($grade),
    ];
    saveData($data);
    respond(201, [
        'success' => true,
        'message' => "Subject \"{$subjectName}\" added.",
        'data'    => withGWA($data['students'][$idx]),
    ]);
}

// PUT /students/{id}/subjects/{name}  – update a grade
if ($method === 'PUT') {
    if ($subParam === null) {
        respond(400, ['success' => false, 'message' => 'Subject name is required in the URL.']);
    }
    if (!isset($body['grade']) || !isValidGrade($body['grade'])) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid grade. Accepted values: ' . implode(', ', VALID_GRADES),
        ]);
    }
    $found = false;
    foreach ($data['students'][$idx]['subjects'] as &$s) {
        if (strtolower($s['name']) === strtolower($subParam)) {
            $grade      = (float) $body['grade'];
            $s['grade'] = $grade;
            $s['label'] = getGradeLabel($grade);
            $found = true;
            break;
        }
    }
    unset($s);
    if (!$found) {
        respond(404, ['success' => false, 'message' => "Subject \"{$subParam}\" not found."]);
    }
    saveData($data);
    respond(200, [
        'success' => true,
        'message' => "Grade for \"{$subParam}\" updated.",
        'data'    => withGWA($data['students'][$idx]),
    ]);
}

// DELETE /students/{id}/subjects/{name}  – remove a subject
if ($method === 'DELETE') {
    if ($subParam === null) {
        respond(400, ['success' => false, 'message' => 'Subject name is required in the URL.']);
    }
    $found = false;
    foreach ($data['students'][$idx]['subjects'] as $i => $s) {
        if (strtolower($s['name']) === strtolower($subParam)) {
            array_splice($data['students'][$idx]['subjects'], $i, 1);
            $found = true;
            break;
        }
    }
    if (!$found) {
        respond(404, ['success' => false, 'message' => "Subject \"{$subParam}\" not found."]);
    }
    saveData($data);
    respond(200, [
        'success' => true,
        'message' => "Subject \"{$subParam}\" removed.",
        'data'    => withGWA($data['students'][$idx]),
    ]);
}

respond(405, ['success' => false, 'message' => 'Method not allowed.']);
