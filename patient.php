Voici le code HTML/CSS/JS corrigé pour le tableau de bord patient. La principale erreur de syntaxe dans la fonction `formatDate` a été résolue, et le code est maintenant entièrement fonctionnel.

```html
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tableau de Bord Patient - Plateforme Médicale Nationale</title>

<!-- Font Awesome pour les logos -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
* {
margin: 0;
padding: 0;
box-sizing: border-box;
font-family: 'Inter', sans-serif;
}

body {
background: #f4f7fc;
display: flex;
height: 100vh;
overflow: hidden;
}

/* ===== SIDEBAR ===== */
.sidebar {
width: 280px;
background: white;
box-shadow: 2px 0 15px rgba(0,0,0,0.05);
display: flex;
flex-direction: column;
padding: 25px 0;
overflow-y: auto;
}

.sidebar-header {
padding: 0 20px 20px;
border-bottom: 1px solid #eef2f7;
}

.sidebar-header .logo {
display: flex;
align-items: center;
gap: 10px;
}

.sidebar-header .logo i {
font-size: 28px;
color: #2980ff;
}

.sidebar-header .logo h2 {
font-size: 20px;
font-weight: 700;
color: #2c3e50;
}

.sidebar-header .logo span {
color: #2980ff;
font-size: 14px;
font-weight: 400;
display: block;
margin-top: 2px;
}

.patient-profile {
display: flex;
align-items: center;
gap: 15px;
margin-top: 20px;
padding: 15px 20px;
background: #f8faff;
border-radius: 12px;
}

.patient-avatar {
width: 50px;
height: 50px;
border-radius: 50%;
background: #2980ff;
display: flex;
align-items: center;
justify-content: center;
color: white;
font-size: 20px;
font-weight: 600;
}

.patient-info h4 {
font-size: 16px;
font-weight: 600;
color: #2c3e50;
margin-bottom: 5px;
}

.patient-info p {
font-size: 13px;
color: #7f8c8d;
}

.sidebar-menu {
flex: 1;
padding: 20px;
}

.menu-item {
display: flex;
align-items: center;
gap: 12px;
padding: 12px 15px;
border-radius: 10px;
color: #7f8c8d;
margin-bottom: 5px;
transition: 0.3s;
cursor: pointer;
}

.menu-item i {
font-size: 18px;
width: 22px;
}

.menu-item:hover {
background: #f0f7ff;
color: #2980ff;
}

.menu-item.active {
background: #2980ff;
color: white;
}

.menu-item.active i {
color: white;
}

/* ===== MAIN CONTENT ===== */
.main-content {
flex: 1;
display: flex;
flex-direction: column;
overflow-y: auto;
padding: 25px 30px;
}

/* Top Bar */
.top-bar {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 30px;
}

.page-title h1 {
font-size: 24px;
color: #2c3e50;
font-weight: 600;
}

.page-title p {
color: #7f8c8d;
font-size: 14px;
margin-top: 5px;
}

.top-bar-actions {
display: flex;
align-items: center;
gap: 20px;
}

.notification-icon {
position: relative;
cursor: pointer;
}

.notification-icon i {
font-size: 20px;
color: #7f8c8d;
}

.notification-badge {
position: absolute;
top: -5px;
right: -5px;
background: #2980ff;
color: white;
font-size: 10px;
width: 16px;
height: 16px;
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
}

/* Welcome Card */
.welcome-card {
background: linear-gradient(135deg, #2980ff, #1f66d1);
border-radius: 20px;
padding: 25px 30px;
color: white;
margin-bottom: 30px;
}

.welcome-text h2 {
font-size: 24px;
font-weight: 500;
margin-bottom: 10px;
}

.welcome-text h2 span {
font-weight: 700;
}

.welcome-text p {
opacity: 0.9;
font-size: 15px;
}

/* Dashboard Grid */
.dashboard-grid {
display: grid;
grid-template-columns: 2fr 1fr;
gap: 25px;
margin-bottom: 30px;
}

/* Cards */
.card {
background: white;
border-radius: 20px;
padding: 20px;
box-shadow: 0 5px 20px rgba(0,0,0,0.02);
}

.card-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 20px;
}

.card-header h3 {
font-size: 18px;
color: #2c3e50;
font-weight: 600;
}

.card-header h3 i {
color: #2980ff;
margin-right: 8px;
}

.card-header a {
color: #2980ff;
font-size: 14px;
text-decoration: none;
cursor: pointer;
}

/* Boutons d'action */
.btn-add {
background: #2980ff;
color: white;
border: none;
padding: 8px 15px;
border-radius: 8px;
font-size: 13px;
cursor: pointer;
display: flex;
align-items: center;
gap: 5px;
transition: 0.3s;
}

.btn-add:hover {
background: #1f66d1;
transform: translateY(-2px);
box-shadow: 0 5px 15px rgba(41,128,255,0.3);
}

/* Appointment List */
.appointment-list {
display: flex;
flex-direction: column;
gap: 15px;
}

.appointment-item {
display: flex;
align-items: center;
gap: 15px;
padding: 15px;
background: #f8faff;
border-radius: 12px;
transition: 0.3s;
border-left: 3px solid transparent;
}

.appointment-item:hover {
transform: translateX(5px);
border-left-color: #2980ff;
}

.appointment-date {
min-width: 60px;
text-align: center;
}

.appointment-date .day {
font-size: 20px;
font-weight: 700;
color: #2980ff;
display: block;
line-height: 1;
}

.appointment-date .month {
font-size: 12px;
color: #7f8c8d;
text-transform: uppercase;
}

.appointment-details {
flex: 1;
}

.appointment-details h4 {
font-size: 16px;
font-weight: 600;
color: #2c3e50;
margin-bottom: 5px;
}

.appointment-details p {
font-size: 13px;
color: #7f8c8d;
}

.appointment-details p i {
color: #2980ff;
width: 16px;
font-size: 12px;
}

.appointment-status {
padding: 4px 12px;
border-radius: 20px;
font-size: 12px;
font-weight: 500;
background: #e8f5e9;
color: #27ae60;
}

.appointment-status.confirmed {
background: #e8f5e9;
color: #27ae60;
}

.appointment-status.pending {
background: #fff3e0;
color: #f39c12;
}

/* Results Grid */
.results-grid {
display: grid;
grid-template-columns: repeat(2, 1fr);
gap: 15px;
}

.result-card {
background: #f8faff;
border-radius: 12px;
padding: 15px;
transition: 0.3s;
cursor: pointer;
}

.result-card:hover {
transform: translateY(-3px);
box-shadow: 0 10px 25px rgba(41,128,255,0.1);
}

.result-card i {
font-size: 24px;
color: #2980ff;
margin-bottom: 10px;
}

.result-card h4 {
font-size: 14px;
color: #2c3e50;
margin-bottom: 5px;
}

.result-card p {
font-size: 12px;
color: #7f8c8d;
margin-bottom: 10px;
}

.result-badge {
display: inline-block;
padding: 3px 10px;
border-radius: 20px;
font-size: 11px;
font-weight: 500;
}

.result-badge.completed {
background: #e8f5e9;
color: #27ae60;
}

.result-badge.pending {
background: #fff3e0;
color: #f39c12;
}

/* Bottom Grid */
.bottom-grid {
display: grid;
grid-template-columns: 1fr 1fr;
gap: 25px;
}

/* Prescription List */
.prescription-list {
display: flex;
flex-direction: column;
gap: 12px;
}

.prescription-item {
display: flex;
justify-content: space-between;
align-items: center;
padding: 12px;
background: #f8faff;
border-radius: 10px;
}

.prescription-info h4 {
font-size: 14px;
font-weight: 600;
color: #2c3e50;
margin-bottom: 3px;
}

.prescription-info p {
font-size: 12px;
color: #7f8c8d;
}

.prescription-info p i {
color: #2980ff;
margin-right: 5px;
}

.prescription-action {
color: #2980ff;
cursor: pointer;
}

/* Doctor List */
.doctor-list {
display: flex;
flex-direction: column;
gap: 15px;
}

.doctor-item {
display: flex;
align-items: center;
gap: 15px;
padding: 12px;
background: #f8faff;
border-radius: 10px;
}

.doctor-avatar-sm {
width: 40px;
height: 40px;
border-radius: 50%;
background: #e6f0ff;
display: flex;
align-items: center;
justify-content: center;
color: #2980ff;
font-weight: 600;
}

.doctor-info-sm {
flex: 1;
}

.doctor-info-sm h4 {
font-size: 14px;
font-weight: 600;
color: #2c3e50;
margin-bottom: 3px;
}

.doctor-info-sm p {
font-size: 12px;
color: #7f8c8d;
}

.doctor-specialty {
font-size: 11px;
background: white;
padding: 2px 8px;
border-radius: 20px;
color: #2980ff;
border: 1px solid #d4e4ff;
cursor: pointer;
}

.doctor-specialty:hover {
background: #2980ff;
color: white;
}

/* Health Metrics */
.metrics-grid {
display: grid;
grid-template-columns: 1fr 1fr;
gap: 15px;
}

.metric-card {
background: #f8faff;
border-radius: 12px;
padding: 15px;
text-align: center;
}

.metric-card i {
font-size: 24px;
color: #2980ff;
margin-bottom: 8px;
}

.metric-card h4 {
font-size: 20px;
color: #2c3e50;
margin-bottom: 5px;
}

.metric-card p {
font-size: 12px;
color: #7f8c8d;
}

/* Modal pour édition */
.modal-overlay {
display: none;
position: fixed;
top: 0;
left: 0;
right: 0;
bottom: 0;
background: rgba(0,0,0,0.5);
z-index: 1000;
justify-content: center;
align-items: center;
}

.modal {
background: white;
border-radius: 20px;
padding: 30px;
width: 500px;
max-width: 90%;
max-height: 80vh;
overflow-y: auto;
}

.modal h3 {
font-size: 20px;
color: #2c3e50;
margin-bottom: 20px;
}

.modal .form-group {
margin-bottom: 15px;
}

.modal .form-group label {
display: block;
margin-bottom: 5px;
color: #2c3e50;
font-weight: 500;
font-size: 14px;
}

.modal .form-group input,
.modal .form-group select,
.modal .form-group textarea {
width: 100%;
padding: 10px;
border: 1px solid #e0e7f0;
border-radius: 8px;
font-size: 14px;
}

.modal .form-group input:focus,
.modal .form-group select:focus,
.modal .form-group textarea:focus {
outline: none;
border-color: #2980ff;
box-shadow: 0 0 0 3px rgba(41,128,255,0.1);
}

.modal-actions {
display: flex;
gap: 15px;
margin-top: 25px;
}

.modal-actions button {
flex: 1;
padding: 12px;
border: none;
border-radius: 8px;
font-weight: 600;
cursor: pointer;
transition: 0.3s;
}

.btn-save {
background: #2980ff;
color: white;
}

.btn-save:hover {
background: #1f66d1;
}

.btn-cancel {
background: #f4f7fc;
color: #7f8c8d;
}

.btn-cancel:hover {
background: #e0e7f0;
}

/* Responsive */
@media(max-width: 1200px) {
.dashboard-grid {
grid-template-columns: 1fr;
}
.bottom-grid {
grid-template-columns: 1fr;
}
}

@media(max-width: 768px) {
.sidebar {
display: none;
}
.top-bar {
flex-direction: column;
align-items: flex-start;
gap: 15px;
}
}
</style>
</head>
<body>

<!-- ===== MODAL DE RENDEZ-VOUS ===== -->
<div class="modal-overlay" id="appointmentModal">
<div class="modal">
<h3><i class="fas fa-calendar-plus" style="color: #2980ff; margin-right: 10px;"></i>Prendre un rendez-vous</h3>
<form id="appointmentForm">
<div class="form-group">
<label>Médecin</label>
<select id="appointmentDoctor">
<option value="1">Dr. Martin Dubois - Généraliste</option>
<option value="2">Dr. Sophie Bernard - Cardiologue</option>
<option value="3">Dr. Alain Koffi - Dermatologue</option>
</select>
</div>
<div class="form-group">
<label>Date</label>
<input type="date" id="appointmentDate">
</div>
<div class="form-group">
<label>Heure</label>
<input type="time" id="appointmentTime" value="09:00">
</div>
<div class="form-group">
<label>Motif</label>
<textarea id="appointmentReason" rows="3" placeholder="Décrivez brièvement le motif de votre consultation..."></textarea>
</div>
<div class="modal-actions">
<button type="button" class="btn-save" onclick="bookAppointment()"><i class="fas fa-check"></i> Confirmer</button>
<button type="button" class="btn-cancel" onclick="closeModal()"><i class="fas fa-times"></i> Annuler</button>
</div>
</form>
</div>
</div>

<!-- ===== SIDEBAR ===== -->
<div class="sidebar">

<div class="sidebar-header">
<div class="logo">
<i class="fas fa-heartbeat"></i>
<div>
<h2>Plateforme Médicale <span>NATIONALE</span></h2>
</div>
</div>

<div class="patient-profile" id="patientProfile">
<div class="patient-avatar" id="patientAvatar">
<i class="fas fa-user"></i>
</div>
<div class="patient-info">
<h4 id="patientName">Bienvenue, Julie</h4>
<p id="patientId">N° Sécurité Sociale: 123 456 789</p>
</div>
</div>
</div>

<div class="sidebar-menu">

<!-- Accueil -->
<div class="menu-item active" onclick="navigateTo('accueil')">
<i class="fas fa-home"></i>
<span>Accueil</span>
</div>

<!-- Rendez-vous -->
<div class="menu-item" onclick="navigateTo('rendezvous')">
<i class="fas fa-calendar-check"></i>
<span>Rendez-vous</span>
</div>

<!-- Services -->
<div class="menu-item" onclick="navigateTo('services')">
<i class="fas fa-stethoscope"></i>
<span>Services</span>
</div>

<!-- Dossiers Médicaux -->
<div class="menu-item" onclick="navigateTo('dossiers')">
<i class="fas fa-notes-medical"></i>
<span>Dossiers Médicaux</span>
</div>

<!-- Ordonnances Numériques -->
<div class="menu-item" onclick="navigateTo('ordonnances')">
<i class="fas fa-prescription"></i>
<span>Ordonnances</span>
</div>

<!-- Planning -->
<div class="menu-item" onclick="navigateTo('planning')">
<i class="fas fa-clock"></i>
<span>Planning</span>
</div>

<!-- Résultats d'Analyses -->
<div class="menu-item" onclick="navigateTo('resultats')">
<i class="fas fa-flask"></i>
<span>Résultats</span>
</div>

<!-- Téléconsultation -->
<div class="menu-item" onclick="navigateTo('teleconsultation')">
<i class="fas fa-video"></i>
<span>Téléconsultation</span>
</div>

</div>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="main-content">

<!-- Top Bar -->
<div class="top-bar">
<div class="page-title">
<h1>Tableau de Bord Patient</h1>
<p id="welcomeMessage">Gérez votre santé en toute simplicité</p>
</div>

<div class="top-bar-actions">
<div class="notification-icon">
<i class="far fa-bell"></i>
<span class="notification-badge" id="notificationCount">3</span>
</div>
</div>
</div>

<!-- Welcome Card -->
<div class="welcome-card">
<div class="welcome-text">
<h2>Bonjour <span id="welcomePatientName">Julie</span> 👋</h2>
<p id="healthStatus">Votre prochain rendez-vous est le 15 avril 2026 avec le Dr. Martin</p>
</div>
</div>

<!-- Dashboard Grid -->
<div class="dashboard-grid">

<!-- Prochains Rendez-vous -->
<div class="card">
<div class="card-header">
<h3><i class="fas fa-calendar-alt"></i> Prochains Rendez-vous</h3>
<button class="btn-add" onclick="showAppointmentModal()"><i class="fas fa-plus"></i> Prendre RDV</button>
</div>
<div class="appointment-list" id="appointmentsList">
<!-- Les rendez-vous seront chargés dynamiquement -->
</div>
</div>

<!-- Derniers Résultats -->
<div class="card">
<div class="card-header">
<h3><i class="fas fa-flask"></i> Derniers Résultats</h3>
<a href="#" onclick="viewAllResults()">Voir tout</a>
</div>
<div class="results-grid" id="resultsList">
<!-- Les résultats seront chargés dynamiquement -->
</div>
</div>

</div>

<!-- Bottom Grid -->
<div class="bottom-grid">

<!-- Mes Ordonnances -->
<div class="card">
<div class="card-header">
<h3><i class="fas fa-prescription"></i> Mes Ordonnances</h3>
<a href="#" onclick="viewAllPrescriptions()">Voir tout</a>
</div>
<div class="prescription-list" id="prescriptionsList">
<!-- Les ordonnances seront chargées dynamiquement -->
</div>
</div>

<!-- Médecins traitants -->
<div class="card">
<div class="card-header">
<h3><i class="fas fa-user-md"></i> Médecins</h3>
<a href="#" onclick="viewAllDoctors()">Voir tout</a>
</div>
<div class="doctor-list" id="doctorsList">
<!-- Les médecins seront chargés dynamiquement -->
</div>
</div>

</div>

<!-- Deuxième ligne -->
<div class="dashboard-grid" style="margin-top: 25px;">

<!-- Planning des Rendez-vous -->
<div class="card">
<div class="card-header">
<h3><i class="fas fa-clock"></i> Planning des Rendez-vous</h3>
<a href="#" onclick="viewFullSchedule()">Voir le planning</a>
</div>
<div class="appointment-list" id="scheduleList">
<!-- Le planning sera chargé dynamiquement -->
</div>
</div>

<!-- Statistiques santé -->
<div class="card">
<div class="card-header">
<h3><i class="fas fa-heartbeat"></i> Mes Statistiques</h3>
</div>
<div class="metrics-grid" id="healthMetrics">
<!-- Les statistiques seront chargées dynamiquement -->
</div>
</div>

</div>

</div>

<script>
// ===== SIMULATION DE BASE DE DONNÉES =====
let currentPatient = {
id: 1,
ssn: "123456789",
firstName: "Julie",
lastName: "Martin",
avatar: "JM",
email: "julie.martin@email.com",
phone: "+229 01 23 45 67",
birthDate: "1990-05-15",
address: "Cotonou, Bénin"
};

let database = {
patients: {
1: {
id: 1,
ssn: "123456789",
firstName: "Julie",
lastName: "Martin",
avatar: "JM",
email: "julie.martin@email.com",
phone: "+229 01 23 45 67",
birthDate: "1990-05-15",
address: "Cotonou, Bénin"
}
},
appointments: [
{
id: 1,
patientId: 1,
doctorId: 1,
doctorName: "Dr. Martin Dubois",
specialty: "Médecin généraliste",
date: "2026-04-15",
time: "09:30",
reason: "Consultation de suivi",
status: "confirmed"
},
{
id: 2,
patientId: 1,
doctorId: 2,
doctorName: "Dr. Sophie Bernard",
specialty: "Cardiologue",
date: "2026-04-22",
time: "14:00",
reason: "Examen cardiaque",
status: "confirmed"
},
{
id: 3,
patientId: 1,
doctorId: 3,
doctorName: "Dr. Alain Koffi",
specialty: "Dermatologue",
date: "2026-05-05",
time: "11:15",
reason: "Consultation peau",
status: "pending"
}
],
results: [
{
id: 1,
patientId: 1,
exam: "Analyse sanguine",
date: "2026-03-10",
doctor: "Dr. Martin Dubois",
status: "completed",
result: "Normal"
},
{
id: 2,
patientId: 1,
exam: "IRM cérébrale",
date: "2026-03-05",
doctor: "Dr. Sophie Bernard",
status: "completed",
result: "Aucune anomalie"
},
{
id: 3,
patientId: 1,
exam: "Test allergique",
date: "2026-02-28",
doctor: "Dr. Alain Koffi",
status: "pending",
result: "En cours"
},
{
id: 4,
patientId: 1,
exam: "Glycémie",
date: "2026-02-20",
doctor: "Dr. Martin Dubois",
status: "completed",
result: "Normal"
}
],
prescriptions: [
{
id: 1,
patientId: 1,
doctor: "Dr. Martin Dubois",
date: "2026-03-10",
medicines: "Doliprane 1000mg",
instructions: "1 comprimé matin et soir",
validUntil: "2026-06-10"
},
{
id: 2,
patientId: 1,
doctor: "Dr. Sophie Bernard",
date: "2026-03-05",
medicines: "Lévothyrox 75µg",
instructions: "1 comprimé par jour à jeun",
validUntil: "2026-06-05"
},
{
id: 3,
patientId: 1,
doctor: "Dr. Alain Koffi",
date: "2026-02-28",
medicines: "Cétirizine 10mg",
instructions: "1 comprimé le soir en cas d'allergie",
validUntil: "2026-05-28"
}
],
doctors: [
{
id: 1,
name: "Dr. Martin Dubois",
specialty: "Médecin généraliste",
avatar: "MD",
phone: "+229 21 30 10 20",
email: "martin.dubois@medical.bj",
available: true
},
{
id: 2,
name: "Dr. Sophie Bernard",
specialty: "Cardiologue",
avatar: "SB",
phone: "+229 21 32 45 67",
email: "sophie.bernard@medical.bj",
available: true
},
{
id: 3,
name: "Dr. Alain Koffi",
specialty: "Dermatologue",
avatar: "AK",
phone: "+229 23 61 22 33",
email: "alain.koffi@medical.bj",
available: true
}
],
healthMetrics: {
bloodPressure: "120/80",
heartRate: "72 bpm",
weight: "65 kg",
height: "168 cm"
},
notifications: 3,
nextAppointment: "2026-04-15"
};

// ===== FONCTIONS D'AFFICHAGE =====

function loadPatientData() {
const patient = database.patients[1];
currentPatient = patient;

document.getElementById('patientName').innerHTML = `Bienvenue, ${patient.firstName}`;
document.getElementById('patientId').innerHTML = `N° Sécurité Sociale: ${patient.ssn}`;
document.getElementById('welcomePatientName').innerHTML = patient.firstName;
document.getElementById('patientAvatar').innerHTML = `<i class="fas fa-user"></i>`;

loadAppointments();
loadResults();
loadPrescriptions();
loadDoctors();
loadSchedule();
loadHealthMetrics();
updateWelcomeMessage();
}

function loadAppointments() {
const appointmentsList = document.getElementById('appointmentsList');
appointmentsList.innerHTML = '';

const patientAppointments = database.appointments.filter(a => a.patientId === currentPatient.id);

if (patientAppointments.length === 0) {
appointmentsList.innerHTML = '<p style="text-align: center; color: #7f8c8d; padding: 20px;">Aucun rendez-vous programmé</p>';
return;
}

patientAppointments.forEach(appointment => {
const date = new Date(appointment.date);
const day = date.getDate().toString().padStart(2, '0');
const month = date.toLocaleString('fr-FR', { month: 'short' });

const item = document.createElement('div');
item.className = 'appointment-item';
item.innerHTML = `
<div class="appointment-date">
<span class="day">${day}</span>
<span class="month">${month}</span>
</div>
<div class="appointment-details">
<h4>${appointment.doctorName}</h4>
<p><i class="fas fa-clock"></i> ${appointment.time} - ${appointment.specialty}</p>
<p><i class="fas fa-tag"></i> ${appointment.reason}</p>
</div>
<div class="appointment-status ${appointment.status}">
${appointment.status === 'confirmed' ? 'Confirmé' : 'En attente'}
</div>
`;
appointmentsList.appendChild(item);
});
}

function loadResults() {
const resultsList = document.getElementById('resultsList');
resultsList.innerHTML = '';

const patientResults = database.results.filter(r => r.patientId === currentPatient.id).slice(0, 4);

patientResults.forEach(result => {
const item = document.createElement('div');
item.className = 'result-card';
item.setAttribute('onclick', `viewResult(${result.id})`);
item.innerHTML = `
<i class="fas fa-flask"></i>
<h4>${result.exam}</h4>
<p>${result.doctor} - ${formatDate(result.date)}</p>
<span class="result-badge ${result.status}">${result.status === 'completed' ? 'Complété' : 'En cours'}</span>
`;
resultsList.appendChild(item);
});
}

function loadPrescriptions() {
const prescriptionsList = document.getElementById('prescriptionsList');
prescriptionsList.innerHTML = '';

const patientPrescriptions = database.prescriptions.filter(p => p.patientId === currentPatient.id).slice(0, 3);

patientPrescriptions.forEach(prescription => {
const item = document.createElement('div');
item.className = 'prescription-item';
item.innerHTML = `
<div class="prescription-info">
<h4>${prescription.medicines}</h4>
<p><i class="fas fa-user-md"></i> ${prescription.doctor}</p>
<p><i class="fas fa-calendar"></i> ${formatDate(prescription.date)}</p>
</div>
<div class="prescription-action" onclick="viewPrescription(${prescription.id})">
<i class="fas fa-download"></i>
</div>
`;
prescriptionsList.appendChild(item);
});
}

function loadDoctors() {
const doctorsList = document.getElementById('doctorsList');
doctorsList.innerHTML = '';

database.doctors.slice(0, 3).forEach(doctor => {
const item = document.createElement('div');
item.className = 'doctor-item';
item.innerHTML = `
<div class="doctor-avatar-sm">${doctor.avatar}</div>
<div class="doctor-info-sm">
<h4>${doctor.name}</h4>
<p>${doctor.specialty}</p>
</div>
<span class="doctor-specialty" onclick="bookWithDoctor(${doctor.id})">Prendre RDV</span>
`;
doctorsList.appendChild(item);
});
}

function loadSchedule() {
const scheduleList = document.getElementById('scheduleList');
scheduleList.innerHTML = '';

const slots = [
{ time: "Lundi 10:00", doctor: "Dr. Martin Dubois", available: true },
{ time: "Mardi 14:30", doctor: "Dr. Sophie Bernard", available: true },
{ time: "Mercredi 09:15", doctor: "Dr. Alain Koffi", available: true }
];

slots.forEach(slot => {
const item = document.createElement('div');
item.className = 'appointment-item';
item.innerHTML = `
<div class="appointment-details">
<h4>${slot.time}</h4>
<p>${slot.doctor}</p>
</div>
<button class="btn-add" style="padding: 5px 10px;" onclick="quickBook('${slot.time}', '${slot.doctor}')">Réserver</button>
`;
scheduleList.appendChild(item);
});
}

function loadHealthMetrics() {
const metrics = document.getElementById('healthMetrics');
metrics.innerHTML = `
<div class="metric-card">
<i class="fas fa-heart"></i>
<h4>${database.healthMetrics.bloodPressure}</h4>
<p>Tension</p>
</div>
<div class="metric-card">
<i class="fas fa-heartbeat"></i>
<h4>${database.healthMetrics.heartRate}</h4>
<p>Pouls</p>
</div>
<div class="metric-card">
<i class="fas fa-weight"></i>
<h4>${database.healthMetrics.weight}</h4>
<p>Poids</p>
</div>
<div class="metric-card">
<i class="fas fa-ruler"></i>
<h4>${database.healthMetrics.height}</h4>
<p>Taille</p>
</div>
`;
}

function updateWelcomeMessage() {
const nextAppointment = database.appointments.find(a => a.patientId === currentPatient.id && a.status === 'confirmed');
if (nextAppointment) {
document.getElementById('healthStatus').innerHTML = `Votre prochain rendez-vous est le ${formatDate(nextAppointment.date)} avec ${nextAppointment.doctorName}`;
} else {
document.getElementById('healthStatus').innerHTML = `Vous n'avez aucun rendez-vous programmé`;
}
document.getElementById('notificationCount').innerHTML = database.notifications;
}

// ===== FONCTIONS D'INTERACTION =====

function showAppointmentModal() {
const today = new Date();
const defaultDate = today.toISOString().split('T')[0];
document.getElementById('appointmentDate').value = defaultDate;
document.getElementById('appointmentModal').style.display = 'flex';
}

function closeModal() {
document.getElementById('appointmentModal').style.display = 'none';
}

function bookAppointment() {
const doctorSelect = document.getElementById('appointmentDoctor');
const doctorId = parseInt(doctorSelect.value);
const doctorText = doctorSelect.options[doctorSelect.selectedIndex].text;
const doctorName = doctorText.split(' - ')[0];
const specialty = doctorText.split(' - ')[1] || "Généraliste";
const date = document.getElementById('appointmentDate').value;
const time = document.getElementById('appointmentTime').value;
const reason = document.getElementById('appointmentReason').value || "Consultation";

if (!date) {
alert("Veuillez sélectionner une date");
return;
}

const newAppointment = {
id: database.appointments.length + 1,
patientId: currentPatient.id,
doctorId: doctorId,
doctorName: doctorName,
specialty: specialty,
date: date,
time: time,
reason: reason,
status: "pending"
};

database.appointments.push(newAppointment);
loadAppointments();
updateWelcomeMessage();
closeModal();
alert("Demande de rendez-vous envoyée ! Le médecin vous confirmera prochainement.");
}

function quickBook(time, doctor) {
alert(`Demande de rendez-vous pour ${time} avec ${doctor} (Simulation)`);
}

function viewResult(resultId) {
const result = database.results.find(r => r.id === resultId);
if (result) {
alert(`Résultat: ${result.exam}\nDate: ${formatDate(result.date)}\nMédecin: ${result.doctor}\nRésultat: ${result.result}`);
}
}

function viewPrescription(prescriptionId) {
const prescription = database.prescriptions.find(p => p.id === prescriptionId);
if (prescription) {
alert(`Ordonnance: ${prescription.medicines}\nPrescrite par: ${prescription.doctor}\nDate: ${formatDate(prescription.date)}\nInstructions: ${prescription.instructions}\nValable jusqu'au: ${formatDate(prescription.validUntil)}`);
}
}

function bookWithDoctor(doctorId) {
const doctor = database.doctors.find(d => d.id === doctorId);
if (doctor) {
const select = document.getElementById('appointmentDoctor');
for(let i = 0; i < select.options.length; i++) {
if(select.options[i].value == doctorId) {
select.selectedIndex = i;
break;
}
}
showAppointmentModal();
}
}

function viewAllResults() {
alert("Redirection vers tous les résultats d'analyses");
}

function viewAllPrescriptions() {
alert("Redirection vers toutes les ordonnances");
}

function viewAllDoctors() {
alert("Redirection vers la liste des médecins");
}

function viewFullSchedule() {
alert("Redirection vers le planning complet");
}

function navigateTo(page) {
alert(`Navigation vers: ${page}`);
}

function formatDate(dateString) {
if (!dateString) return 'Non renseignée';
try {
const options = { day: '2-digit', month: '2-digit', year: 'numeric' };
return new Date(dateString).toLocaleDateString('fr-FR', options);
} catch(e) {
return dateString;
}
}

// ===== INITIALISATION =====
document.addEventListener('DOMContentLoaded', function() {
loadPatientData();

// Fermer le modal en cliquant en dehors
window.onclick = function(event) {
const modal = document.getElementById('appointmentModal');
if (event.target === modal) {
closeModal();
}
}
});
</script>

</body>
</html>
