const { createApp, ref, reactive, onMounted, watch, computed } = Vue;
const { createRouter, createWebHashHistory } = VueRouter;
const API_BASE = 'api/index.php';
async function apiFetch(action, method = 'GET', body = null) {
  const url = `${API_BASE}?action=${action}`;
  const options = {
    method,
    headers: { 'Content-Type': 'application/json' }
  };
  if (body) {
    options.body = JSON.stringify(body);
  }
  const response = await fetch(url, options);
  return await response.json();
}
const LandingView = {
  template: `
    <div class="container py-5 text-center">
      <div class="card shadow-lg border-0 p-5 rounded-4 my-4 bg-light">
        <h1 class="display-4 fw-bold text-primary mb-3"><i class="fa fa-university me-2"></i> GNCP Academic System</h1>
        <p class="lead text-secondary mb-4">
          Empowering Academic Excellence &amp; Seamless Digital Admissions
        </p>
        <div class="row g-4 justify-content-center mt-3">
          <div class="col-md-3">
            <router-link to="/register" class="btn btn-primary btn-lg w-100 py-3 rounded-3 shadow-sm">
              <i class="fa fa-user-plus me-2"></i> Online Pre-Reg
            </router-link>
          </div>
          <div class="col-md-3">
            <router-link to="/track" class="btn btn-outline-secondary btn-lg w-100 py-3 rounded-3 shadow-sm">
              <i class="fa fa-search me-2"></i> Track Application
            </router-link>
          </div>
          <div class="col-md-3">
            <router-link to="/login" class="btn btn-dark btn-lg w-100 py-3 rounded-3 shadow-sm">
              <i class="fa fa-lock me-2"></i> Employee Login
            </router-link>
          </div>
          <div class="col-md-3">
            <router-link to="/student-portal" class="btn btn-success btn-lg w-100 py-3 rounded-3 shadow-sm">
              <i class="fa fa-graduation-cap me-2"></i> Student Portal
            </router-link>
          </div>
        </div>
      </div>
    </div>
  `
};
const RegisterView = {
  template: `
    <div class="container py-4">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-lg border-0 rounded-4">
            <div class="card-header bg-primary text-white p-4 rounded-top-4">
              <h3 class="mb-0"><i class="fa fa-user-plus me-2"></i> Online Pre-Registration</h3>
              <p class="mb-0 small opacity-75">Fill in your information to start your enrollment process</p>
            </div>
            <div class="card-body p-4">
              <div v-if="successData" class="alert alert-success p-4 text-center rounded-3">
                <h4><i class="fa fa-check-circle text-success me-2"></i> Registration Successful!</h4>
                <p class="lead">Your Reference Number: <strong>{{ successData.referenceNumber }}</strong></p>
                <p>Your Temporary PIN: <strong>{{ successData.tempPin }}</strong></p>
                <router-link to="/track" class="btn btn-success mt-2">Track Application Status</router-link>
              </div>
              <form v-else @submit.prevent="handleRegister">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label fw-bold">First Name *</label>
                    <input v-model="form.firstName" type="text" class="form-control" required />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input v-model="form.middleName" type="text" class="form-control" />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fw-bold">Last Name *</label>
                    <input v-model="form.lastName" type="text" class="form-control" required />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold">Email Address *</label>
                    <input v-model="form.email" type="email" class="form-control" required />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold">Phone Number *</label>
                    <input v-model="form.phone" type="text" class="form-control" required />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold">Birth Date *</label>
                    <input v-model="form.birthDate" type="date" class="form-control" required />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold">Gender *</label>
                    <select v-model="form.gender" class="form-select" required>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                    </select>
                  </div>
                  <div class="col-12">
                    <label class="form-label fw-bold">Address *</label>
                    <textarea v-model="form.address" class="form-control" rows="2" required></textarea>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold">Desired Course / Program *</label>
                    <select v-model="form.courseCode" class="form-select" required>
                      <option value="BSCS">BS in Computer Science</option>
                      <option value="BSIT">BS in Information Technology</option>
                      <option value="BSIS">BS in Information Systems</option>
                      <option value="BSEMC">BS in Entertainment & Multimedia Computing</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold">Student Type *</label>
                    <select v-model="form.studentType" class="form-select" required>
                      <option value="FRESHMAN">Freshman</option>
                      <option value="TRANSFEREE">Transferee</option>
                      <option value="RETURNING">Returning Student</option>
                    </select>
                  </div>
                </div>
                <div v-if="error" class="alert alert-danger mt-3">{{ error }}</div>
                <div class="mt-4 d-flex justify-content-between">
                  <router-link to="/" class="btn btn-light">Back</router-link>
                  <button type="submit" class="btn btn-primary px-4" :disabled="loading">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span> Submit Registration
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  setup() {
    const form = reactive({
      firstName: '', middleName: '', lastName: '', email: '', phone: '',
      birthDate: '', gender: 'Male', address: '', courseCode: 'BSCS', studentType: 'FRESHMAN'
    });
    const loading = ref(false);
    const error = ref(null);
    const successData = ref(null);
    const handleRegister = async () => {
      loading.value = true;
      error.value = null;
      try {
        const res = await apiFetch('student/register', 'POST', form);
        if (res.success) {
          successData.value = res.data;
        } else {
          error.value = res.message;
        }
      } catch (err) {
        error.value = 'Failed to submit registration.';
      } finally {
        loading.value = false;
      }
    };
    return { form, loading, error, successData, handleRegister };
  }
};
const TrackerView = {
  template: `
    <div class="container py-4">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow border-0 rounded-4">
            <div class="card-header bg-secondary text-white p-4 rounded-top-4">
              <h3 class="mb-0"><i class="fa fa-search me-2"></i> Application Status Tracker</h3>
            </div>
            <div class="card-body p-4">
              <form @submit.prevent="track" class="mb-4">
                <div class="input-group input-group-lg">
                  <input v-model="refNo" type="text" class="form-control" placeholder="Enter Reference Number (e.g. REF-2026-1001)" required />
                  <button type="submit" class="btn btn-primary" :disabled="loading">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span> Lookup
                  </button>
                </div>
              </form>
              <div v-if="error" class="alert alert-danger">{{ error }}</div>
              <div v-if="student" class="mt-4">
                <div class="card bg-light border-0 p-3 mb-4">
                  <h5>Student: <strong>{{ student.firstName }} {{ student.lastName }}</strong></h5>
                  <p class="mb-1">Course: <strong>{{ student.courseCode || student.program }}</strong> | Ref: <strong>{{ student.referenceNumber }}</strong></p>
                  <span class="badge bg-primary w-auto fs-6">Status: {{ student.status }}</span>
                </div>
                <h6 class="fw-bold mb-3">Enrollment Roadmap Progress</h6>
                <div class="list-group">
                  <div v-for="step in student.roadmap" :key="step.id" class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div><strong class="me-2">Step {{ step.id }}: {{ step.name }}</strong></div>
                    <span class="badge" :class="getBadgeClass(step.status)">{{ step.status }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  setup() {
    const refNo = ref('');
    const loading = ref(false);
    const error = ref(null);
    const student = ref(null);
    const track = async () => {
      loading.value = true;
      error.value = null;
      student.value = null;
      try {
        const res = await apiFetch(`student/track&ref=${refNo.value}`);
        if (res.success) {
          student.value = res.data;
        } else {
          error.value = res.message;
        }
      } catch (err) {
        error.value = 'Record not found.';
      } finally {
        loading.value = false;
      }
    };
    const getBadgeClass = (status) => {
      switch (status) {
        case 'COMPLETED': return 'bg-success';
        case 'IN_PROGRESS': return 'bg-warning text-dark';
        case 'PENDING': return 'bg-info text-dark';
        case 'LOCKED': return 'bg-secondary';
        default: return 'bg-dark';
      }
    };
    return { refNo, loading, error, student, track, getBadgeClass };
  }
};
const LoginComponent = {
  template: `
    <div class="container py-5">
      <div class="row justify-content-center">
        <div class="col-md-5">
          <div class="card shadow-lg border-0 rounded-4">
            <div class="card-header bg-dark text-white p-4 rounded-top-4 text-center">
              <h4 class="mb-0"><i class="fa fa-lock me-2"></i> Employee Workstation Login</h4>
            </div>
            <div class="card-body p-4">
              <form @submit.prevent="handleLogin">
                <div class="mb-3">
                  <label class="form-label fw-bold">Username</label>
                  <input v-model="username" type="text" class="form-control form-control-lg" required />
                </div>
                <div class="mb-4">
                  <label class="form-label fw-bold">Password</label>
                  <input v-model="password" type="password" class="form-control form-control-lg" required />
                </div>
                <div v-if="error" class="alert alert-danger mb-3">{{ error }}</div>
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-3" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span> Login
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  setup() {
    const username = ref('');
    const password = ref('');
    const loading = ref(false);
    const error = ref(null);
    const router = VueRouter.useRouter();
    const handleLogin = async () => {
      loading.value = true;
      error.value = null;
      try {
        const res = await apiFetch('auth/login', 'POST', { username: username.value, password: password.value });
        if (res.success) {
          sessionStorage.setItem('gncp_user', JSON.stringify(res.data));
          const role = res.data.role;
          if (role === 'SUPER_ADMIN' || role === 'ADMIN') router.push('/admin');
          else if (role === 'REGISTRAR') router.push('/registrar');
          else if (role === 'HELPDESK') router.push('/stations/helpdesk');
          else if (role === 'MEDICAL') router.push('/stations/medical');
          else if (role === 'CASHIER') router.push('/stations/payment-processing');
          else if (role === 'IT_CENTER') router.push('/stations/it-center');
          else router.push('/');
        } else {
          error.value = res.message;
        }
      } catch (err) {
        error.value = 'Login failed.';
      } finally {
        loading.value = false;
      }
    };
    return { username, password, loading, error, handleLogin };
  }
};
const AdminDashboardComponent = {
  template: `
    <div class="container-fluid py-4">
      <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <h2><i class="fa fa-dashboard me-2"></i> System Administrator Control Panel</h2>
        <button @click="logout" class="btn btn-outline-danger btn-sm"><i class="fa fa-sign-out me-1"></i> Logout</button>
      </div>
      <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><button class="nav-link" :class="{ active: activeTab === 'programs' }" @click="activeTab = 'programs'">Programs</button></li>
        <li class="nav-item"><button class="nav-link" :class="{ active: activeTab === 'subjects' }" @click="activeTab = 'subjects'">Subjects</button></li>
        <li class="nav-item"><button class="nav-link" :class="{ active: activeTab === 'sections' }" @click="activeTab = 'sections'">Sections</button></li>
        <li class="nav-item"><button class="nav-link" :class="{ active: activeTab === 'terms' }" @click="activeTab = 'terms'">Terms</button></li>
      </ul>
      <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
      <div v-else>
        <div v-if="activeTab === 'programs'" class="card shadow-sm border-0 p-3">
          <h5>Academic Programs</h5>
          <table class="table table-hover">
            <thead><tr><th>Code</th><th>Program Name</th><th>Department</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="p in catalog.programs" :key="p.id">
                <td><strong>{{ p.code }}</strong></td><td>{{ p.name }}</td><td>{{ p.department }}</td>
                <td><span class="badge bg-success">{{ p.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="activeTab === 'subjects'" class="card shadow-sm border-0 p-3">
          <h5>Course Subjects</h5>
          <table class="table table-hover">
            <thead><tr><th>Subject Code</th><th>Title</th><th>Lec Units</th><th>Lab Units</th></tr></thead>
            <tbody>
              <tr v-for="s in catalog.subjects" :key="s.id">
                <td><strong>{{ s.code }}</strong></td><td>{{ s.title }}</td><td>{{ s.lecture_units }}</td><td>{{ s.lab_units }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="activeTab === 'sections'" class="card shadow-sm border-0 p-3">
          <h5>Class Sections</h5>
          <table class="table table-hover">
            <thead><tr><th>Code</th><th>Program</th><th>Year Level</th><th>Capacity</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="sec in sections" :key="sec.id">
                <td><strong>{{ sec.code }}</strong></td><td>{{ sec.program }}</td><td>{{ sec.year_level }}</td>
                <td>{{ sec.enrolled_count }} / {{ sec.capacity }}</td><td><span class="badge bg-primary">{{ sec.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="activeTab === 'terms'" class="card shadow-sm border-0 p-3">
          <h5>Academic Terms</h5>
          <table class="table table-hover">
            <thead><tr><th>School Year</th><th>Semester</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="t in terms" :key="t.id">
                <td><strong>{{ t.school_year }}</strong></td><td>{{ t.semester }}</td>
                <td><span class="badge" :class="t.is_active ? 'bg-success' : 'bg-secondary'">{{ t.is_active ? 'ACTIVE' : 'INACTIVE' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `,
  setup() {
    const activeTab = ref('programs');
    const catalog = reactive({ programs: [], subjects: [] });
    const sections = ref([]);
    const terms = ref([]);
    const loading = ref(true);
    const router = VueRouter.useRouter();
    onMounted(async () => {
      try {
        const [cRes, sRes, tRes] = await Promise.all([
          apiFetch('admin/catalog'),
          apiFetch('admin/sections'),
          apiFetch('admin/terms')
        ]);
        if (cRes.success) {
          catalog.programs = cRes.data.programs || [];
          catalog.subjects = cRes.data.subjects || [];
        }
        if (sRes.success) sections.value = sRes.data || [];
        if (tRes.success) terms.value = tRes.data || [];
      } catch (err) {
        console.error(err);
      } finally {
        loading.value = false;
      }
    });
    const logout = async () => {
      await apiFetch('auth/logout', 'POST');
      sessionStorage.removeItem('gncp_user');
      router.push('/login');
    };
    return { activeTab, catalog, sections, terms, loading, logout };
  }
};
const RegistrarComponent = {
  template: `
    <div class="container-fluid py-4">
      <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <h2><i class="fa fa-folder-open me-2"></i> Registrar Workstation</h2>
        <button @click="logout" class="btn btn-outline-danger btn-sm"><i class="fa fa-sign-out me-1"></i> Logout</button>
      </div>
      <div class="row">
        <div class="col-md-5">
          <div class="card shadow-sm border-0 p-3">
            <h5>Pending Applications Queue</h5>
            <div v-if="loading" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
            <div v-else-if="queue.length === 0" class="alert alert-info mt-2">No pending applications in queue.</div>
            <div v-else class="list-group mt-2">
              <button v-for="s in queue" :key="s.referenceNumber" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3" :class="{ active: selected?.referenceNumber === s.referenceNumber }" @click="selected = s">
                <div><strong>{{ s.firstName }} {{ s.lastName }}</strong><div class="small opacity-75">{{ s.courseCode }} | {{ s.referenceNumber }}</div></div>
                <span class="badge bg-warning text-dark">{{ s.status }}</span>
              </button>
            </div>
          </div>
        </div>
        <div class="col-md-7">
          <div v-if="selected" class="card shadow-sm border-0 p-4">
            <h4>Review Application: <strong>{{ selected.firstName }} {{ selected.lastName }}</strong></h4>
            <p class="text-muted">Ref: {{ selected.referenceNumber }} | Type: {{ selected.studentType }}</p>
            <h6 class="fw-bold mt-3">Document Verification Checklist</h6>
            <div class="form-check mb-2" v-for="(req, idx) in selected.requirements" :key="idx">
              <input class="form-check-input" type="checkbox" checked />
              <label class="form-check-label">{{ req.title || req }}</label>
            </div>
            <div class="mt-4">
              <button @click="approve" class="btn btn-success me-2" :disabled="submitting">
                <i class="fa fa-check-circle me-1"></i> Admit & Forward to Advising
              </button>
            </div>
          </div>
          <div v-else class="card shadow-sm border-0 p-5 text-center text-muted">Select an applicant from the queue to review documents.</div>
        </div>
      </div>
    </div>
  `,
  setup() {
    const queue = ref([]);
    const selected = ref(null);
    const loading = ref(true);
    const submitting = ref(false);
    const router = VueRouter.useRouter();
    const fetchQueue = async () => {
      loading.value = true;
      const res = await apiFetch('stations/queue');
      if (res.success) queue.value = res.data;
      loading.value = false;
    };
    onMounted(fetchQueue);
    const approve = async () => {
      if (!selected.value) return;
      submitting.value = true;
      const updatedRoadmap = selected.value.roadmap.map(s => {
        if (s.id === 2) return { ...s, status: 'COMPLETED' };
        if (s.id === 3) return { ...s, status: 'IN_PROGRESS' };
        return s;
      });
      await apiFetch('stations/update', 'POST', {
        referenceNumber: selected.value.referenceNumber,
        updateData: { roadmap: updatedRoadmap, status: 'IN_PROGRESS' }
      });
      selected.value = null;
      await fetchQueue();
      submitting.value = false;
    };
    const logout = async () => {
      await apiFetch('auth/logout', 'POST');
      sessionStorage.removeItem('gncp_user');
      router.push('/login');
    };
    return { queue, selected, loading, submitting, approve, logout };
  }
};
const StationComponent = {
  props: ['stationType'],
  template: `
    <div class="container-fluid py-4">
      <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <h2><i class="fa fa-desktop me-2"></i> {{ stationTitle }}</h2>
        <button @click="logout" class="btn btn-outline-danger btn-sm"><i class="fa fa-sign-out me-1"></i> Logout</button>
      </div>
      <div class="row">
        <div class="col-md-5">
          <div class="card shadow-sm border-0 p-3">
            <h5>Workstation Queue</h5>
            <div v-if="loading" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
            <div v-else-if="queue.length === 0" class="alert alert-info mt-2">No pending students in queue.</div>
            <div v-else class="list-group mt-2">
              <button v-for="s in queue" :key="s.referenceNumber" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3" :class="{ active: selected?.referenceNumber === s.referenceNumber }" @click="selected = s">
                <div><strong>{{ s.firstName }} {{ s.lastName }}</strong><div class="small opacity-75">{{ s.courseCode }} | Ref: {{ s.referenceNumber }}</div></div>
                <span class="badge bg-warning text-dark">{{ s.status }}</span>
              </button>
            </div>
          </div>
        </div>
        <div class="col-md-7">
          <div v-if="selected" class="card shadow-sm border-0 p-4">
            <h4>Processing: <strong>{{ selected.firstName }} {{ selected.lastName }}</strong></h4>
            <p class="text-muted">Ref: {{ selected.referenceNumber }} | Program: {{ selected.courseCode }}</p>
            <div v-if="stationType === 'helpdesk'" class="mb-3">
              <label class="form-label fw-bold">Section Assignment</label>
              <select v-model="sectionCode" class="form-select">
                <option value="BSCS-1A">BSCS-1A (MWF 8:00 AM - 12:00 PM)</option>
                <option value="BSIT-1A">BSIT-1A (MWF 1:00 PM - 5:00 PM)</option>
              </select>
            </div>
            <div v-if="stationType === 'medical'" class="mb-3">
              <label class="form-label fw-bold">Evaluation Result</label>
              <select v-model="medicalResult" class="form-select"><option value="FIT">Fit for Enrollment</option></select>
            </div>
            <div v-if="stationType === 'cashier'" class="mb-3">
              <label class="form-label fw-bold">OR Number</label>
              <input v-model="orNumber" class="form-control" />
            </div>
            <div v-if="stationType === 'it-center'" class="mb-3">
              <label class="form-label fw-bold">Permanent Student ID</label>
              <input v-model="studentId" class="form-control" />
            </div>
            <button @click="process" class="btn btn-success btn-lg mt-3" :disabled="submitting">
              <i class="fa fa-check-circle me-1"></i> Complete Station Processing
            </button>
          </div>
          <div v-else class="card shadow-sm border-0 p-5 text-center text-muted">Select a student from the workstation queue.</div>
        </div>
      </div>
    </div>
  `,
  setup(props) {
    const queue = ref([]);
    const selected = ref(null);
    const loading = ref(true);
    const submitting = ref(false);
    const sectionCode = ref('BSCS-1A');
    const medicalResult = ref('FIT');
    const orNumber = ref('OR-2026-1001');
    const studentId = ref('2026-1001');
    const router = VueRouter.useRouter();
    const stationTitle = computed(() => {
      switch (props.stationType) {
        case 'helpdesk': return 'TLC Helpdesk — Academic Advising Workstation';
        case 'medical': return 'Medical Checkup Workstation';
        case 'cashier': return 'Cashier Payment Processing Workstation';
        case 'it-center': return 'IT Center — Account Activation Workstation';
        default: return 'Station Workstation';
      }
    });
    const fetchQueue = async () => {
      loading.value = true;
      const res = await apiFetch('stations/queue');
      if (res.success) queue.value = res.data;
      loading.value = false;
    };
    onMounted(fetchQueue);
    const process = async () => {
      if (!selected.value) return;
      submitting.value = true;
      let targetStepId = 3;
      if (props.stationType === 'medical') targetStepId = 4;
      else if (props.stationType === 'cashier') targetStepId = 6;
      else if (props.stationType === 'it-center') targetStepId = 7;
      const updatedRoadmap = selected.value.roadmap.map(s => {
        if (s.id === targetStepId) return { ...s, status: 'COMPLETED' };
        if (s.id === targetStepId + 1) return { ...s, status: 'IN_PROGRESS' };
        return s;
      });
      const payload = {
        roadmap: updatedRoadmap,
        status: props.stationType === 'it-center' ? 'ENROLLED' : 'IN_PROGRESS'
      };
      if (props.stationType === 'it-center') {
        payload.enrollment = { permanentId: studentId.value };
      }
      await apiFetch('stations/update', 'POST', {
        referenceNumber: selected.value.referenceNumber,
        updateData: payload
      });
      selected.value = null;
      await fetchQueue();
      submitting.value = false;
    };
    const logout = async () => {
      await apiFetch('auth/logout', 'POST');
      sessionStorage.removeItem('gncp_user');
      router.push('/login');
    };
    return { queue, selected, loading, submitting, sectionCode, medicalResult, orNumber, studentId, stationTitle, process, logout };
  }
};
const StudentPortalComponent = {
  template: `
    <div class="container py-4">
      <div class="card shadow-lg border-0 rounded-4 p-4">
        <h3>Student Academic Portal</h3>
        <p class="text-muted">Enrolled Subjects & Class Schedule</p>
        <table class="table table-bordered table-striped mt-3">
          <thead class="table-dark"><tr><th>Code</th><th>Subject Title</th><th>Units</th><th>Schedule</th></tr></thead>
          <tbody>
            <tr><td><strong>CS101</strong></td><td>Introduction to Computing</td><td>3.0</td><td>MWF 8:00 AM - 9:00 AM</td></tr>
            <tr><td><strong>CS102</strong></td><td>Computer Programming 1</td><td>3.0</td><td>MWF 9:00 AM - 11:00 AM</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  `
};
const routes = [
  { path: '/', component: LandingView },
  { path: '/register', component: RegisterView },
  { path: '/track', component: TrackerView },
  { path: '/login', component: LoginComponent },
  { path: '/admin', component: AdminDashboardComponent, meta: { requiresAuth: true, roles: ['SUPER_ADMIN', 'ADMIN'] } },
  { path: '/registrar', component: RegistrarComponent, meta: { requiresAuth: true, roles: ['REGISTRAR', 'ADMIN'] } },
  { path: '/stations/helpdesk', component: StationComponent, props: { stationType: 'helpdesk' }, meta: { requiresAuth: true, roles: ['HELPDESK', 'ADMIN'] } },
  { path: '/stations/medical', component: StationComponent, props: { stationType: 'medical' }, meta: { requiresAuth: true, roles: ['MEDICAL', 'ADMIN'] } },
  { path: '/stations/payment-processing', component: StationComponent, props: { stationType: 'cashier' }, meta: { requiresAuth: true, roles: ['CASHIER', 'ADMIN'] } },
  { path: '/stations/it-center', component: StationComponent, props: { stationType: 'it-center' }, meta: { requiresAuth: true, roles: ['IT_CENTER', 'ADMIN'] } },
  { path: '/student-portal', component: StudentPortalComponent }
];
const router = createRouter({
  history: createWebHashHistory(),
  routes
});
router.beforeEach((to, from, next) => {
  const user = JSON.parse(sessionStorage.getItem('gncp_user') || 'null');
  if (to.meta.requiresAuth) {
    if (!user) return next('/login');
    if (to.meta.roles && !to.meta.roles.includes(user.role)) return next('/');
  }
  next();
});
const RootApp = {
  template: `
    <div class="d-flex flex-column min-vh-100">
      <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container-fluid">
          <router-link to="/" class="navbar-brand fw-bold">
            <i class="fa fa-university text-warning me-2"></i> GNCP Academic System
          </router-link>
          <div class="navbar-nav me-auto">
            <router-link to="/" class="nav-link">Home</router-link>
            <router-link to="/register" class="nav-link">Pre-Registration</router-link>
            <router-link to="/track" class="nav-link">Status Tracker</router-link>
          </div>
          <div class="d-flex align-items-center">
            <span v-if="currentUser" class="text-white me-3 small">
              Logged in: <strong class="text-warning">{{ currentUser.name }}</strong> ({{ currentUser.role }})
            </span>
            <router-link v-else to="/login" class="btn btn-outline-light btn-sm">Login</router-link>
          </div>
        </div>
      </nav>
      <main class="flex-grow-1">
        <router-view></router-view>
      </main>
      <footer class="bg-dark text-white text-center py-3 mt-auto">
        <small>&copy; 2026 GNCP Academic System — Pure Vue 3 + Vue Router (No Vite/Build Required)</small>
      </footer>
    </div>
  `,
  setup() {
    const currentUser = computed(() => JSON.parse(sessionStorage.getItem('gncp_user') || 'null'));
    return { currentUser };
  }
};
const app = createApp(RootApp);
app.use(router);
app.mount('#app');
