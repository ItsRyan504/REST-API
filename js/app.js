// ============================================================
//  School Grading System – Frontend
//  Communicates with api.php via Fetch API
// ============================================================

// ── Config ──────────────────────────────────────────────────
const API_BASE = (() => {
  // Works regardless of subfolder name in htdocs
  const path = window.location.pathname.replace(/\/[^/]*$/, '');
  return `${window.location.origin}${path}/api.php`;
})();

// Philippine Grade Scale options
const GRADE_OPTIONS = [
  { value: 1.0,  label: '1.00 – Excellent'    },
  { value: 1.25, label: '1.25 – Superior'      },
  { value: 1.5,  label: '1.50 – Superior'      },
  { value: 1.75, label: '1.75 – Very Good'     },
  { value: 2.0,  label: '2.00 – Very Good'     },
  { value: 2.25, label: '2.25 – Good'          },
  { value: 2.5,  label: '2.50 – Satisfactory'  },
  { value: 2.75, label: '2.75 – Satisfactory'  },
  { value: 3.0,  label: '3.00 – Passing'       },
  { value: 5.0,  label: '5.00 – Failed'        },
];

// ── State ────────────────────────────────────────────────────
let students        = [];
let activeStudentId = null;

// ── DOM refs ─────────────────────────────────────────────────
const studentsList = document.getElementById('students-list');
const detailPanel  = document.getElementById('detail-content');
const searchInput  = document.getElementById('search-input');
const apiStatus    = document.getElementById('api-status');
const overlay      = document.getElementById('modal-overlay');
const modal        = document.getElementById('modal');
const modalTitle   = document.getElementById('modal-title');
const modalBody    = document.getElementById('modal-body');
const modalClose   = document.getElementById('modal-close');
const toast        = document.getElementById('toast');

// ── Auth Session Storage ─────────────────────────────────────
function getToken()  { return localStorage.getItem('gs_token'); }
function getUser()   { return JSON.parse(localStorage.getItem('gs_user') || 'null'); }
function setSession(token, user) {
  localStorage.setItem('gs_token', token);
  localStorage.setItem('gs_user', JSON.stringify(user));
}
function clearSession() {
  localStorage.removeItem('gs_token');
  localStorage.removeItem('gs_user');
}

// ── API helpers ──────────────────────────────────────────────
async function api(method, path, body = null) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
  };
  const token = getToken();
  if (token) opts.headers['Authorization'] = `Bearer ${token}`;
  if (body) opts.body = JSON.stringify(body);
  const res  = await fetch(`${API_BASE}${path}`, opts);
  const json = await res.json();
  if (res.status === 401) {
    clearSession();
    showAuthScreen();
    const e = new Error(json.message || 'Session expired. Please log in again.');
    e.isAuthError = true;
    throw e;
  }
  if (!json.success) throw new Error(json.message || 'Request failed');
  return json;
}

// ── Toast ────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
  clearTimeout(toastTimer);
  toast.textContent = msg;
  toast.className   = `toast ${type}`;
  toastTimer = setTimeout(() => { toast.className = 'toast hidden'; }, 3200);
}

// ── Modal ────────────────────────────────────────────────────
function openModal(title, bodyHTML) {
  modalTitle.textContent = title;
  modalBody.innerHTML    = bodyHTML;
  overlay.classList.remove('hidden');
}
function closeModal() {
  overlay.classList.add('hidden');
  modalBody.innerHTML = '';
}
modalClose.addEventListener('click', closeModal);
overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });

// ── Utilities ────────────────────────────────────────────────
function initials(name) {
  return name.trim().split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function gwaCardClass(gwa, remarks) {
  if (gwa === null) return 'no-grade';
  if (gwa > 3.0)    return 'failed';
  if (gwa <= 1.75)  return 'honors';
  return 'passed';
}

function gwaBadgeClass(gwa) {
  if (gwa === null) return 'badge-none';
  if (gwa > 3.0)    return 'badge-failed';
  if (gwa <= 1.75)  return 'badge-honors';
  return 'badge-passed';
}

function gradeTagClass(grade) {
  return grade >= 5.0 ? 'grade-tag failed-tag' : 'grade-tag';
}

function buildGradeSelect(selectedVal = null) {
  return GRADE_OPTIONS.map(o =>
    `<option value="${o.value}"${selectedVal == o.value ? ' selected' : ''}>${o.label}</option>`
  ).join('');
}

// ── Render: Students List ────────────────────────────────────
function renderStudentsList(filter = '') {
  const q = filter.toLowerCase();
  const filtered = students.filter(s => s.name.toLowerCase().includes(q));

  if (filtered.length === 0) {
    studentsList.innerHTML = `<p class="placeholder-text">${
      filter ? 'No students match your search.' : 'No students yet. Click <strong>+ Add Student</strong> to begin.'
    }</p>`;
    return;
  }

  studentsList.innerHTML = filtered.map(s => {
    const gwaText = s.gwa !== null ? s.gwa.toFixed(2) : '—';
    const badgeClass = gwaBadgeClass(s.gwa);
    const isActive = s.id === activeStudentId ? 'active' : '';
    return `
      <div class="student-card ${isActive}" data-id="${s.id}" role="button" tabindex="0">
        <div class="card-avatar">${initials(s.name)}</div>
        <div class="card-info">
          <div class="card-name">${escHtml(s.name)}</div>
          <div class="card-meta">${escHtml(s.year_level || 'Year level not set')} · ${s.subjects.length} subject${s.subjects.length !== 1 ? 's' : ''}</div>
        </div>
        <span class="badge ${badgeClass} card-gwa-badge">${gwaText}</span>
      </div>`;
  }).join('');

  // Attach click handlers
  studentsList.querySelectorAll('.student-card').forEach(card => {
    card.addEventListener('click', () => loadStudentDetail(parseInt(card.dataset.id)));
    card.addEventListener('keydown', (e) => { if (e.key === 'Enter') loadStudentDetail(parseInt(card.dataset.id)); });
  });
}

// ── Render: Student Detail ───────────────────────────────────
function renderStudentDetail(student) {
  const cardClass = gwaCardClass(student.gwa, student.remarks);
  const gwaDisplay = student.gwa !== null ? student.gwa.toFixed(4) : '—';

  const subjectsHTML = student.subjects.length === 0
    ? `<tr><td colspan="3" class="no-subjects">No subjects added yet. Click <strong>+ Add Subject</strong>.</td></tr>`
    : student.subjects.map(sub => `
        <tr>
          <td>${escHtml(sub.name)}</td>
          <td>
            <div class="grade-pill">
              <span class="grade-num">${sub.grade.toFixed(2)}</span>
              <span class="${gradeTagClass(sub.grade)}">${escHtml(sub.label || '')}</span>
            </div>
          </td>
          <td>
            <div class="action-btns">
              <button class="btn btn-outline btn-xs" onclick="editSubject(${student.id}, '${escAttr(sub.name)}', ${sub.grade})">&#9998; Edit</button>
              <button class="btn btn-danger btn-xs" onclick="deleteSubject(${student.id}, '${escAttr(sub.name)}')">&#128465;</button>
            </div>
          </td>
        </tr>`
      ).join('');

  detailPanel.innerHTML = `
    <div class="detail-inner">
      <!-- Student header -->
      <div class="student-info-bar">
        <div class="big-avatar">${initials(student.name)}</div>
        <div class="info-text">
          <h3>${escHtml(student.name)}</h3>
          <div class="year">${escHtml(student.year_level || 'Year level not set')}</div>
        </div>
        <div class="info-actions">
          <button class="btn btn-outline btn-sm" onclick="editStudent(${student.id})">&#9998; Edit</button>
          <button class="btn btn-danger btn-sm" onclick="deleteStudent(${student.id})">&#128465; Delete</button>
        </div>
      </div>

      <!-- GWA card -->
      <div class="gwa-card ${cardClass}">
        <div>
          <div class="gwa-label">General Weighted Average</div>
          <div class="gwa-value">${gwaDisplay}</div>
          <div class="gwa-remarks">${escHtml(student.remarks)}</div>
        </div>
        <div style="text-align:center; opacity:.6; font-size:.8rem; line-height:1.6;">
          ${student.subjects.length} Subject${student.subjects.length !== 1 ? 's' : ''}<br/>
          <small>Academic performance summary</small>
        </div>
      </div>

      <!-- Subjects table -->
      <div class="subjects-header">
        <h4>Subjects &amp; Grades</h4>
        <button class="btn btn-primary btn-sm" onclick="addSubject(${student.id})">&#43; Add Subject</button>
      </div>
      <table class="subjects-table">
        <thead>
          <tr>
            <th>Subject</th>
            <th>Grade</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>${subjectsHTML}</tbody>
      </table>
    </div>`;
}

// ── XSS helpers ──────────────────────────────────────────────
function escHtml(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(str) {
  return String(str ?? '').replace(/'/g, "\\'");
}

// ── Load Data ────────────────────────────────────────────────
async function loadStudents(keepActive = false) {
  try {
    const res = await api('GET', '/students');
    students = res.data;
    renderStudentsList(searchInput.value);
    if (keepActive && activeStudentId !== null) {
      const s = students.find(x => x.id === activeStudentId);
      if (s) renderStudentDetail(s);
    }
    updateApiStatus(true);
  } catch (err) {
    if (!err.isAuthError) {
      showToast('Could not reach the API. Is XAMPP running?', 'error');
      updateApiStatus(false);
    }
  }
}

async function loadStudentDetail(id) {
  try {
    const res = await api('GET', `/students/${id}`);
    activeStudentId = id;
    renderStudentDetail(res.data);
    renderStudentsList(searchInput.value); // refresh active highlight
  } catch (err) {
    if (!err.isAuthError) showToast(err.message, 'error');
  }
}

function updateApiStatus(online) {
  apiStatus.className = `badge ${online ? 'badge-online' : 'badge-offline'}`;
  apiStatus.textContent = online ? 'API Online' : 'API Offline';
}

// ── Add Student ──────────────────────────────────────────────
function showAddStudentModal() {
  openModal('Add New Student', `
    <div class="form-group">
      <label for="f-name">Full Name *</label>
      <input id="f-name" class="input" type="text" placeholder="e.g. Juan dela Cruz" maxlength="100" />
    </div>
    <div class="form-group">
      <label for="f-year">Year Level</label>
      <select id="f-year" class="select">
        <option value="">— Select Year Level —</option>
        <option>1st Year</option>
        <option>2nd Year</option>
        <option>3rd Year</option>
        <option>4th Year</option>
        <option>5th Year</option>
        <option>Graduate</option>
      </select>
    </div>
    <div class="form-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="btn-submit-student">Add Student</button>
    </div>`
  );
  document.getElementById('f-name').focus();
  document.getElementById('btn-submit-student').addEventListener('click', submitAddStudent);
  document.getElementById('f-name').addEventListener('keydown', e => { if (e.key === 'Enter') submitAddStudent(); });
}

async function submitAddStudent() {
  const name  = document.getElementById('f-name').value.trim();
  const year  = document.getElementById('f-year').value;
  if (!name) { document.getElementById('f-name').focus(); return; }
  try {
    const res = await api('POST', '/students', { name, year_level: year });
    await loadStudents();
    closeModal();
    showToast(`Student "${res.data.name}" added!`);
    loadStudentDetail(res.data.id);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// ── Edit Student ─────────────────────────────────────────────
function editStudent(id) {
  const s = students.find(x => x.id === id);
  if (!s) return;
  openModal('Edit Student', `
    <div class="form-group">
      <label for="ef-name">Full Name *</label>
      <input id="ef-name" class="input" type="text" value="${escHtml(s.name)}" maxlength="100" />
    </div>
    <div class="form-group">
      <label for="ef-year">Year Level</label>
      <select id="ef-year" class="select">
        <option value="">— Select Year Level —</option>
        ${['1st Year','2nd Year','3rd Year','4th Year','5th Year','Graduate'].map(y =>
          `<option${s.year_level === y ? ' selected' : ''}>${y}</option>`
        ).join('')}
      </select>
    </div>
    <div class="form-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="btn-submit-edit">Save Changes</button>
    </div>`
  );
  document.getElementById('ef-name').focus();
  document.getElementById('btn-submit-edit').addEventListener('click', () => submitEditStudent(id));
}

async function submitEditStudent(id) {
  const name = document.getElementById('ef-name').value.trim();
  const year = document.getElementById('ef-year').value;
  if (!name) { document.getElementById('ef-name').focus(); return; }
  try {
    await api('PUT', `/students/${id}`, { name, year_level: year });
    await loadStudents(true);
    closeModal();
    showToast('Student updated!');
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// ── Delete Student ───────────────────────────────────────────
function deleteStudent(id) {
  const s = students.find(x => x.id === id);
  if (!s) return;
  openModal('Delete Student', `
    <p class="confirm-msg">Are you sure you want to delete <strong>${escHtml(s.name)}</strong>?
    This will remove all their subjects and grades.</p>
    <div class="form-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" id="btn-confirm-delete">Yes, Delete</button>
    </div>`
  );
  document.getElementById('btn-confirm-delete').addEventListener('click', () => confirmDeleteStudent(id));
}

async function confirmDeleteStudent(id) {
  try {
    const res = await api('DELETE', `/students/${id}`);
    activeStudentId = null;
    detailPanel.innerHTML = `
      <div class="detail-empty">
        <span class="empty-icon">&#128101;</span>
        <p>Select a student from the list<br/>to view grades and GWA.</p>
      </div>`;
    await loadStudents();
    closeModal();
    showToast(res.message);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// ── Add Subject ──────────────────────────────────────────────
function addSubject(studentId) {
  openModal('Add Subject', `
    <div class="form-group">
      <label for="sf-name">Subject Name *</label>
      <input id="sf-name" class="input" type="text" placeholder="e.g. Mathematics, English, Science" maxlength="80" />
    </div>
    <div class="form-group">
      <label for="sf-grade">Grade *</label>
      <select id="sf-grade" class="select">
        <option value="">— Select Grade —</option>
        ${buildGradeSelect()}
      </select>
    </div>
    <div class="form-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="btn-submit-subject">Add Subject</button>
    </div>`
  );
  document.getElementById('sf-name').focus();
  document.getElementById('btn-submit-subject').addEventListener('click', () => submitAddSubject(studentId));
}

async function submitAddSubject(studentId) {
  const subject_name = document.getElementById('sf-name').value.trim();
  const grade        = document.getElementById('sf-grade').value;
  if (!subject_name)    { document.getElementById('sf-name').focus();  return; }
  if (!grade)           { document.getElementById('sf-grade').focus(); return; }
  try {
    await api('POST', `/students/${studentId}/subjects`, { subject_name, grade: parseFloat(grade) });
    await loadStudents(true);
    closeModal();
    showToast(`Subject "${subject_name}" added!`);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// ── Edit Subject ─────────────────────────────────────────────
function editSubject(studentId, subjectName, currentGrade) {
  openModal(`Edit Grade – ${subjectName}`, `
    <div class="form-group">
      <label>Subject</label>
      <input class="input" type="text" value="${escHtml(subjectName)}" disabled />
    </div>
    <div class="form-group">
      <label for="eg-grade">New Grade *</label>
      <select id="eg-grade" class="select">
        <option value="">— Select Grade —</option>
        ${buildGradeSelect(currentGrade)}
      </select>
    </div>
    <div class="form-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="btn-submit-edit-grade">Update Grade</button>
    </div>`
  );
  document.getElementById('eg-grade').focus();
  document.getElementById('btn-submit-edit-grade').addEventListener('click', () => submitEditSubject(studentId, subjectName));
}

async function submitEditSubject(studentId, subjectName) {
  const grade = document.getElementById('eg-grade').value;
  if (!grade) { document.getElementById('eg-grade').focus(); return; }
  try {
    await api('PUT', `/students/${studentId}/subjects/${encodeURIComponent(subjectName)}`, { grade: parseFloat(grade) });
    await loadStudents(true);
    closeModal();
    showToast('Grade updated!');
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// ── Delete Subject ───────────────────────────────────────────
function deleteSubject(studentId, subjectName) {
  openModal('Remove Subject', `
    <p class="confirm-msg">Remove <strong>${escHtml(subjectName)}</strong> from this student's record?</p>
    <div class="form-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" id="btn-confirm-del-sub">Yes, Remove</button>
    </div>`
  );
  document.getElementById('btn-confirm-del-sub').addEventListener('click', () => confirmDeleteSubject(studentId, subjectName));
}

async function confirmDeleteSubject(studentId, subjectName) {
  try {
    const res = await api('DELETE', `/students/${studentId}/subjects/${encodeURIComponent(subjectName)}`);
    await loadStudents(true);
    closeModal();
    showToast(res.message);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// ── Search ───────────────────────────────────────────────────
searchInput.addEventListener('input', () => renderStudentsList(searchInput.value));

// ── Add Student button ───────────────────────────────────────
document.getElementById('btn-add-student').addEventListener('click', showAddStudentModal);

// ── Auth Screen ─────────────────────────────────────────────
const authScreen   = document.getElementById('auth-screen');
const userInfo     = document.getElementById('user-info');
const userDisplay  = document.getElementById('user-display');
const tabLogin     = document.getElementById('tab-login');
const tabRegister  = document.getElementById('tab-register');
const formLogin    = document.getElementById('form-login');
const formRegister = document.getElementById('form-register');
const authError    = document.getElementById('auth-error');
const loginUsernameInput = document.getElementById('login-username');
const loginPasswordInput = document.getElementById('login-password');
const regFullNameInput   = document.getElementById('reg-fullname');
const regUsernameInput   = document.getElementById('reg-username');
const regPasswordInput   = document.getElementById('reg-password');
const regConfirmInput    = document.getElementById('reg-confirm');

function resetLoginForm(username = '') {
  loginUsernameInput.value = username;
  loginPasswordInput.value = '';
}

function resetRegisterForm() {
  regFullNameInput.value = '';
  regUsernameInput.value = '';
  regPasswordInput.value = '';
  regConfirmInput.value = '';
}

function resetAuthForms(username = '') {
  resetLoginForm(username);
  resetRegisterForm();
}

async function performLogin(username, password, { showWelcomeToast = true } = {}) {
  const res = await fetch(`${API_BASE}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  });
  const json = await res.json();
  if (!json.success) {
    showAuthError(json.message);
    return false;
  }
  setSession(json.token, json.user);
  resetAuthForms();
  hideAuthScreen();
  loadStudents();
  if (showWelcomeToast) {
    showToast(`Welcome back, ${json.user.full_name}!`);
  }
  return true;
}

function showAuthScreen() {
  authScreen.style.display = 'flex';
  userInfo.classList.add('hidden');
  apiStatus.className = 'badge badge-checking';
  apiStatus.textContent = 'Connecting…';
  resetAuthForms();
  switchAuthTab('login');
}

function hideAuthScreen() {
  authScreen.style.display = 'none';
  const user = getUser();
  if (user) {
    userDisplay.textContent = `👤 ${user.full_name}`;
    userInfo.classList.remove('hidden');
  }
}

function switchAuthTab(tab) {
  const isLogin = tab === 'login';
  tabLogin.classList.toggle('active', isLogin);
  tabRegister.classList.toggle('active', !isLogin);
  formLogin.style.display    = isLogin ? '' : 'none';
  formRegister.style.display = isLogin ? 'none' : '';
  clearAuthError();
}

function showAuthError(msg) {
  authError.textContent    = msg;
  authError.style.display  = 'block';
}
function clearAuthError() { authError.style.display = 'none'; }

// ── Login ────────────────────────────────────────────────
async function handleLogin() {
  const username = loginUsernameInput.value.trim();
  const password = loginPasswordInput.value;
  clearAuthError();
  if (!username || !password) { showAuthError('Please enter your username and password.'); return; }

  const btn = document.getElementById('btn-login');
  btn.disabled = true; btn.textContent = 'Logging in…';
  try {
    await performLogin(username, password);
  } catch (_) {
    showAuthError('Could not reach the API. Is XAMPP running?');
  } finally {
    btn.disabled = false; btn.textContent = 'Login';
  }
}

// ── Register ─────────────────────────────────────────────
async function handleRegister() {
  const full_name = regFullNameInput.value.trim();
  const username  = regUsernameInput.value.trim();
  const password  = regPasswordInput.value;
  const confirm   = regConfirmInput.value;
  clearAuthError();
  if (!full_name || !username || !password || !confirm) { showAuthError('All fields are required.'); return; }
  if (password !== confirm) { showAuthError('Passwords do not match.'); return; }
  if (password.length < 6) { showAuthError('Password must be at least 6 characters.'); return; }

  const btn = document.getElementById('btn-register');
  btn.disabled = true; btn.textContent = 'Creating account…';
  try {
    const res = await fetch(`${API_BASE}/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ full_name, username, password }),
    });
    const json = await res.json();
    if (!json.success) { showAuthError(json.message); return; }
    resetRegisterForm();
    showToast('Account created! Logging you in…', 'info');
    await performLogin(username, password, { showWelcomeToast: false });
  } catch (_) {
    showAuthError('Could not reach the API. Is XAMPP running?');
  } finally {
    btn.disabled = false; btn.textContent = 'Create Account';
  }
}

// ── Logout ──────────────────────────────────────────────
async function handleLogout() {
  try {
    await fetch(`${API_BASE}/logout`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${getToken()}` },
    });
  } catch (_) { /* ignore */ }
  clearSession();
  activeStudentId = null;
  students = [];
  studentsList.innerHTML = '<p class="placeholder-text">Loading students…</p>';
  detailPanel.innerHTML  = `<div class="detail-empty">
    <span class="empty-icon">&#128101;</span>
    <p>Select a student from the list<br/>to view grades and GWA.</p>
  </div>`;
  showAuthScreen();
  showToast('Logged out successfully.', 'info');
}

// ── Auth event listeners ────────────────────────────────
function attachAuthListeners() {
  tabLogin.addEventListener('click',    () => switchAuthTab('login'));
  tabRegister.addEventListener('click', () => switchAuthTab('register'));
  document.getElementById('btn-login').addEventListener('click', handleLogin);
  document.getElementById('btn-register').addEventListener('click', handleRegister);
  document.getElementById('btn-logout').addEventListener('click', handleLogout);
  loginPasswordInput
    .addEventListener('keydown', e => { if (e.key === 'Enter') handleLogin(); });
  regConfirmInput
    .addEventListener('keydown', e => { if (e.key === 'Enter') handleRegister(); });
}

// ── Init ───────────────────────────────────────────────────
attachAuthListeners();
if (getToken() && getUser()) {
  hideAuthScreen();
  loadStudents();
} else {
  showAuthScreen();
}
