<?php
// ============================================================
//  GradeTrack API
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php';

define('TOKEN_EXPIRY', 86400); // 24 hours
const VALID_GRADES = [1.0, 1.25, 1.5, 1.75, 2.0, 2.25, 2.5, 2.75, 3.0, 5.0];

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error. Please check your database setup.',
    ]);
    exit;
});

function db(): PDO
{
    return getPDO();
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function calculateGWA(array $subjects): ?float
{
    if (empty($subjects)) {
        return null;
    }

    $total = array_sum(array_column($subjects, 'grade'));
    return round($total / count($subjects), 4);
}

function getRemarks(?float $gwa): string
{
    if ($gwa === null) {
        return 'No grades recorded';
    }
    if ($gwa <= 1.20) {
        return 'Summa Cum Laude';
    }
    if ($gwa <= 1.45) {
        return 'Magna Cum Laude';
    }
    if ($gwa <= 1.75) {
        return 'Cum Laude';
    }
    if ($gwa <= 3.00) {
        return 'Passed';
    }
    return 'Failed';
}

function getGradeLabel(float $grade): string
{
    $map = [
        1.0 => 'Excellent',
        1.25 => 'Superior',
        1.5 => 'Superior',
        1.75 => 'Very Good',
        2.0 => 'Very Good',
        2.25 => 'Good',
        2.5 => 'Satisfactory',
        2.75 => 'Satisfactory',
        3.0 => 'Passing',
        5.0 => 'Failed',
    ];

    return $map[$grade] ?? 'Unknown';
}

function isValidGrade($grade): bool
{
    return in_array((float) $grade, VALID_GRADES, true);
}

function getAuthToken(): ?string
{
    $auth = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!$auth) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }

    return preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches) ? $matches[1] : null;
}

function fetchUserById(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, username, full_name, role, created_at
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'created_at' => (int) $user['created_at'],
    ];
}

function deleteSessionToken(string $token): void
{
    $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
    $stmt->execute([$token]);
}

function requireAuth(): array
{
    $token = getAuthToken();
    if (!$token) {
        respond(401, ['success' => false, 'message' => 'Unauthorized. Please log in.']);
    }

    $stmt = db()->prepare(
        'SELECT token, user_id, created_at
         FROM sessions
         WHERE token = ?
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $session = $stmt->fetch();

    if (!$session) {
        respond(401, ['success' => false, 'message' => 'Invalid token. Please log in again.']);
    }

    if (time() - (int) $session['created_at'] > TOKEN_EXPIRY) {
        deleteSessionToken($token);
        respond(401, ['success' => false, 'message' => 'Session expired. Please log in again.']);
    }

    $user = fetchUserById((int) $session['user_id']);
    if (!$user) {
        deleteSessionToken($token);
        respond(401, ['success' => false, 'message' => 'User account not found.']);
    }

    return $user;
}

function fetchSubjectsByStudentId(int $studentId): array
{
    $stmt = db()->prepare(
        'SELECT name, grade, label
         FROM subjects
         WHERE student_id = ?
         ORDER BY id ASC'
    );
    $stmt->execute([$studentId]);

    $subjects = [];
    foreach ($stmt->fetchAll() as $row) {
        $subjects[] = [
            'name' => $row['name'],
            'grade' => (float) $row['grade'],
            'label' => $row['label'],
        ];
    }

    return $subjects;
}

function withGWA(array $student): array
{
    $gwa = calculateGWA($student['subjects']);
    $student['gwa'] = $gwa;
    $student['remarks'] = getRemarks($gwa);
    return $student;
}

function fetchStudentById(int $studentId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, name, year_level
         FROM students
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return withGWA([
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'year_level' => $row['year_level'] ?? '',
        'subjects' => fetchSubjectsByStudentId((int) $row['id']),
    ]);
}

function fetchAllStudents(): array
{
    $stmt = db()->query(
        'SELECT id, name, year_level
         FROM students
         ORDER BY id ASC'
    );

    $students = [];
    foreach ($stmt->fetchAll() as $row) {
        $students[] = withGWA([
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'year_level' => $row['year_level'] ?? '',
            'subjects' => fetchSubjectsByStudentId((int) $row['id']),
        ]);
    }

    return $students;
}

function findSubjectRecord(int $studentId, string $subjectName): ?array
{
    $stmt = db()->prepare(
        'SELECT id, name, grade, label
         FROM subjects
         WHERE student_id = ?
           AND LOWER(name) = LOWER(?)
         LIMIT 1'
    );
    $stmt->execute([$studentId, $subjectName]);
    $subject = $stmt->fetch();

    return $subject ?: null;
}

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo === '') {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $pathInfo = substr($requestUri, strlen($scriptName));
    if (($questionPos = strpos($pathInfo, '?')) !== false) {
        $pathInfo = substr($pathInfo, 0, $questionPos);
    }
}

$parts = array_values(array_filter(explode('/', trim($pathInfo, '/'))));
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$resource = $parts[0] ?? null;
$id = isset($parts[1]) ? (int) $parts[1] : null;
$sub = $parts[2] ?? null;
$subParam = isset($parts[3]) ? urldecode($parts[3]) : null;

if ($method === 'POST' && $resource === 'register') {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $fullName = trim($body['full_name'] ?? '');

    if (!$username || !$password || !$fullName) {
        respond(400, ['success' => false, 'message' => 'username, password, and full_name are required.']);
    }
    if (strlen($username) < 3) {
        respond(400, ['success' => false, 'message' => 'Username must be at least 3 characters.']);
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        respond(400, ['success' => false, 'message' => 'Username may only contain letters, numbers, and underscores.']);
    }
    if (strlen($password) < 6) {
        respond(400, ['success' => false, 'message' => 'Password must be at least 6 characters.']);
    }

    $checkStmt = db()->prepare(
        'SELECT id
         FROM users
         WHERE LOWER(username) = LOWER(?)
         LIMIT 1'
    );
    $checkStmt->execute([$username]);
    if ($checkStmt->fetch()) {
        respond(409, ['success' => false, 'message' => "Username \"{$username}\" is already taken."]);
    }

    $insertStmt = db()->prepare(
        'INSERT INTO users (full_name, username, password, role, created_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $fullName,
        $username,
        password_hash($password, PASSWORD_BCRYPT),
        'teacher',
        time(),
    ]);

    $user = fetchUserById((int) db()->lastInsertId());
    respond(201, [
        'success' => true,
        'message' => 'Account created successfully! You can now log in.',
        'data' => $user,
    ]);
}

if ($method === 'POST' && $resource === 'login') {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        respond(400, ['success' => false, 'message' => 'Username and password are required.']);
    }

    $stmt = db()->prepare(
        'SELECT id, username, full_name, password, role, created_at
         FROM users
         WHERE LOWER(username) = LOWER(?)
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $found = $stmt->fetch();

    if (!$found || !password_verify($password, $found['password'])) {
        respond(401, ['success' => false, 'message' => 'Invalid username or password.']);
    }

    $token = bin2hex(random_bytes(32));

    db()->beginTransaction();
    try {
        $deleteStmt = db()->prepare('DELETE FROM sessions WHERE user_id = ?');
        $deleteStmt->execute([(int) $found['id']]);

        $insertStmt = db()->prepare(
            'INSERT INTO sessions (token, user_id, created_at)
             VALUES (?, ?, ?)'
        );
        $insertStmt->execute([$token, (int) $found['id'], time()]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    respond(200, [
        'success' => true,
        'message' => 'Login successful!',
        'token' => $token,
        'user' => [
            'id' => (int) $found['id'],
            'username' => $found['username'],
            'full_name' => $found['full_name'],
            'role' => $found['role'],
            'created_at' => (int) $found['created_at'],
        ],
    ]);
}

if ($method === 'POST' && $resource === 'logout') {
    $token = getAuthToken();
    if ($token) {
        deleteSessionToken($token);
    }

    respond(200, ['success' => true, 'message' => 'Logged out successfully.']);
}

if ($method === 'GET' && $resource === 'me') {
    $user = requireAuth();
    respond(200, ['success' => true, 'data' => $user]);
}

if ($resource !== 'students') {
    respond(404, [
        'success' => false,
        'message' => 'Endpoint not found. Available resources: /register, /login, /logout, /me, /students',
    ]);
}

$currentUser = requireAuth();

if ($method === 'GET' && $id === null) {
    $students = fetchAllStudents();
    respond(200, [
        'success' => true,
        'count' => count($students),
        'data' => $students,
    ]);
}

if ($method === 'POST' && $id === null) {
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        respond(400, ['success' => false, 'message' => 'Field "name" is required.']);
    }

    $stmt = db()->prepare(
        'INSERT INTO students (name, year_level, created_by, created_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        trim($body['year_level'] ?? ''),
        (int) $currentUser['id'],
        time(),
    ]);

    $student = fetchStudentById((int) db()->lastInsertId());
    respond(201, [
        'success' => true,
        'message' => "Student \"{$name}\" created successfully.",
        'data' => $student,
    ]);
}

if ($id === null) {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$student = fetchStudentById($id);
if (!$student) {
    respond(404, ['success' => false, 'message' => "Student with id {$id} not found."]);
}

if ($method === 'GET' && $sub === null) {
    respond(200, ['success' => true, 'data' => $student]);
}

if ($method === 'GET' && $sub === 'gwa') {
    respond(200, [
        'success' => true,
        'data' => [
            'student_id' => $student['id'],
            'name' => $student['name'],
            'year_level' => $student['year_level'],
            'gwa' => $student['gwa'],
            'remarks' => $student['remarks'],
            'total_subjects' => count($student['subjects']),
            'subjects' => $student['subjects'],
        ],
    ]);
}

if ($method === 'PUT' && $sub === null) {
    $nextName = $student['name'];
    $nextYearLevel = $student['year_level'];

    $incomingName = trim($body['name'] ?? '');
    if ($incomingName !== '') {
        $nextName = $incomingName;
    }
    if (array_key_exists('year_level', $body)) {
        $nextYearLevel = trim((string) $body['year_level']);
    }

    $stmt = db()->prepare(
        'UPDATE students
         SET name = ?, year_level = ?
         WHERE id = ?'
    );
    $stmt->execute([$nextName, $nextYearLevel, $id]);

    respond(200, [
        'success' => true,
        'message' => 'Student updated successfully.',
        'data' => fetchStudentById($id),
    ]);
}

if ($method === 'DELETE' && $sub === null) {
    $stmt = db()->prepare('DELETE FROM students WHERE id = ?');
    $stmt->execute([$id]);

    respond(200, [
        'success' => true,
        'message' => "Student \"{$student['name']}\" deleted successfully.",
    ]);
}

if ($sub !== 'subjects') {
    respond(404, ['success' => false, 'message' => "Unknown sub-resource \"{$sub}\"."]);
}

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
    if (findSubjectRecord($id, $subjectName)) {
        respond(409, [
            'success' => false,
            'message' => "Subject \"{$subjectName}\" already exists for this student.",
        ]);
    }

    $grade = (float) $body['grade'];
    $stmt = db()->prepare(
        'INSERT INTO subjects (student_id, name, grade, label)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$id, $subjectName, $grade, getGradeLabel($grade)]);

    respond(201, [
        'success' => true,
        'message' => "Subject \"{$subjectName}\" added.",
        'data' => fetchStudentById($id),
    ]);
}

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

    $subject = findSubjectRecord($id, $subParam);
    if (!$subject) {
        respond(404, ['success' => false, 'message' => "Subject \"{$subParam}\" not found."]);
    }

    $grade = (float) $body['grade'];
    $stmt = db()->prepare(
        'UPDATE subjects
         SET grade = ?, label = ?
         WHERE id = ?'
    );
    $stmt->execute([$grade, getGradeLabel($grade), (int) $subject['id']]);

    respond(200, [
        'success' => true,
        'message' => "Grade for \"{$subParam}\" updated.",
        'data' => fetchStudentById($id),
    ]);
}

if ($method === 'DELETE') {
    if ($subParam === null) {
        respond(400, ['success' => false, 'message' => 'Subject name is required in the URL.']);
    }

    $subject = findSubjectRecord($id, $subParam);
    if (!$subject) {
        respond(404, ['success' => false, 'message' => "Subject \"{$subParam}\" not found."]);
    }

    $stmt = db()->prepare('DELETE FROM subjects WHERE id = ?');
    $stmt->execute([(int) $subject['id']]);

    respond(200, [
        'success' => true,
        'message' => "Subject \"{$subParam}\" removed.",
        'data' => fetchStudentById($id),
    ]);
}

respond(405, ['success' => false, 'message' => 'Method not allowed.']);
