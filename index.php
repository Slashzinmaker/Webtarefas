<?php
session_start();

require_once 'js/EduSPAuth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'login') {
            handleLogin();
        } elseif ($_POST['action'] === 'logout') {
            session_destroy();
            header('Location: index.php');
            exit;
        } elseif (in_array($_POST['action'], ['get_tasks', 'submit_task', 'submit_all_tasks', 'get_stats'])) {
            handleTaskActions();
        }
    }
}

function handleLogin() {
    $ra = $_POST['ra'] ?? '';
    $senha = $_POST['senha'] ?? '';
    
    if (empty($ra) || empty($senha)) {
        $_SESSION['error'] = 'RA e senha são obrigatórios';
        header('Location: index.php');
        exit;
    }
    
    try {
        $subscriptionKey = "2b03c1db3884488795f79c37c069381a";
        $eduspAuth = new EduSPAuth($subscriptionKey);
        
        $tokenData = $eduspAuth->login($ra, $senha);
        $registrationResult = $eduspAuth->registerToken($tokenData);
        
        $_SESSION['user'] = [
            'token' => $tokenData['token'],
            'auth_token' => $registrationResult['auth_token'],
            'ra' => $ra,
            'nickname' => $tokenData['nickname'] ?? $ra,
            'last_login' => date('Y-m-d H:i:s')
        ];
        
        header('Location: index.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: index.php');
        exit;
    }
}

function handleTaskActions() {
    header('Content-Type: application/json');
    
    try {
        if (!isset($_SESSION['user'])) {
            throw new Exception('Você precisa fazer login primeiro');
        }
        
        $user = $_SESSION['user'];
        $action = $_POST['action'];
        
        switch ($action) {
            case 'get_tasks':
                $expiredOnly = $_POST['expired_only'] ?? false;
                $tasks = getStudentTasks($user, $expiredOnly);
                echo json_encode(['success' => true, 'tasks' => $tasks]);
                break;
                
            case 'submit_task':
                $taskId = $_POST['task_id'] ?? '';
                $minTime = intval($_POST['min_time'] ?? 10) * 60;
                $maxTime = intval($_POST['max_time'] ?? 20) * 60;
                
                if (empty($taskId)) throw new Exception('ID da tarefa não especificado');
                
                $result = submitTask($user, $taskId, $minTime, $maxTime);
                echo json_encode(['success' => true, 'message' => 'Tarefa concluída com sucesso!', 'task_id' => $taskId]);
                break;
                
            case 'submit_all_tasks':
                $minTime = intval($_POST['min_time'] ?? 10) * 60;
                $maxTime = intval($_POST['max_time'] ?? 20) * 60;
                $expiredOnly = $_POST['expired_only'] ?? false;
                
                $tasks = getStudentTasks($user, $expiredOnly);
                $results = [];
                
                foreach ($tasks as $task) {
                    try {
                        $result = submitTask($user, $task['id'], $minTime, $maxTime);
                        $results[] = ['task_id' => $task['id'], 'success' => true, 'title' => $task['title']];
                    } catch (Exception $e) {
                        $results[] = ['task_id' => $task['id'], 'success' => false, 'message' => $e->getMessage(), 'title' => $task['title']];
                    }
                }
                
                // Atualizar estatísticas
                if (!isset($_SESSION['stats'])) {
                    $_SESSION['stats'] = ['total_tasks' => 0, 'completed_tasks' => 0];
                }
                $_SESSION['stats']['total_tasks'] += count($tasks);
                $_SESSION['stats']['completed_tasks'] += count(array_filter($results, fn($r) => $r['success']));
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Tarefas concluídas!',
                    'results' => $results,
                    'stats' => $_SESSION['stats']
                ]);
                break;
                
            case 'get_stats':
                $stats = $_SESSION['stats'] ?? ['total_tasks' => 0, 'completed_tasks' => 0];
                echo json_encode(['success' => true, 'stats' => $stats]);
                break;
                
            default:
                throw new Exception('Ação inválida');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

function getStudentTasks($user, $expiredOnly = false) {
    $eduspAuth = new EduSPAuth("2b03c1db3884488795f79c37c069381a");
    return $eduspAuth->getStudentTasks($user, $expiredOnly);
}

function submitTask($user, $taskId, $minTime = 600, $maxTime = 1200) {
    $eduspAuth = new EduSPAuth("2b03c1db3884488795f79c37c069381a");
    return $eduspAuth->submitTask($user, $taskId, $minTime, $maxTime);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EduSP Script Dashboard</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #6366f1;
      --primary-dark: #4f46e5;
      --secondary: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
      --dark: #1e293b;
      --darker: #0f172a;
      --light: #f8fafc;
      --gray: #94a3b8;
      --success-bg: rgba(16, 185, 129, 0.1);
      --danger-bg: rgba(239, 68, 68, 0.1);
      --warning-bg: rgba(245, 158, 11, 0.1);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    body {
      background-color: var(--darker);
      color: var(--light);
      min-height: 100vh;
      line-height: 1.5;
    }

    .container {
      display: grid;
      grid-template-columns: 280px 1fr;
      min-height: 100vh;
    }

    /* Sidebar */
    .sidebar {
      background: var(--dark);
      border-right: 1px solid rgba(255, 255, 255, 0.1);
      padding: 1.5rem;
      position: sticky;
      top: 0;
      height: 100vh;
      overflow-y: auto;
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .logo-icon {
      width: 32px;
      height: 32px;
      background: var(--primary);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: bold;
    }

    .logo-text {
      font-weight: 600;
      font-size: 1.25rem;
    }

    .nav-menu {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .nav-item {
      padding: 0.75rem 1rem;
      border-radius: 8px;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      color: var(--gray);
      text-decoration: none;
      transition: all 0.2s;
    }

    .nav-item:hover, .nav-item.active {
      background: rgba(255, 255, 255, 0.05);
      color: var(--light);
    }

    .nav-item i {
      width: 20px;
      text-align: center;
    }

    /* Main Content */
    .main-content {
      padding: 2rem;
      overflow-x: hidden;
    }

    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
    }

    .page-title {
      font-size: 1.5rem;
      font-weight: 600;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      color: white;
    }

    .username {
      font-weight: 500;
    }

    .logout-btn {
      background: none;
      border: none;
      color: var(--gray);
      cursor: pointer;
      transition: color 0.2s;
    }

    .logout-btn:hover {
      color: var(--danger);
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: var(--dark);
      border-radius: 12px;
      padding: 1.5rem;
      border-left: 4px solid var(--primary);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      transition: transform 0.2s;
    }

    .stat-card:hover {
      transform: translateY(-4px);
    }

    .stat-card.success {
      border-left-color: var(--secondary);
    }

    .stat-card.warning {
      border-left-color: var(--warning);
    }

    .stat-card.danger {
      border-left-color: var(--danger);
    }

    .stat-title {
      font-size: 0.875rem;
      color: var(--gray);
      margin-bottom: 0.5rem;
    }

    .stat-value {
      font-size: 1.75rem;
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .stat-change {
      font-size: 0.75rem;
      display: flex;
      align-items: center;
      gap: 0.25rem;
    }

    .stat-change.positive {
      color: var(--secondary);
    }

    .stat-change.negative {
      color: var(--danger);
    }

    /* Tasks Section */
    .section {
      background: var(--dark);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .section-title {
      font-size: 1.25rem;
      font-weight: 600;
    }

    .btn {
      padding: 0.625rem 1.25rem;
      border-radius: 8px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      border: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background: var(--primary-dark);
    }

    .btn-success {
      background: var(--secondary);
      color: white;
    }

    .btn-success:hover {
      background: #0d9f6e;
    }

    .btn-danger {
      background: var(--danger);
      color: white;
    }

    .btn-danger:hover {
      background: #dc2626;
    }

    .btn-sm {
      padding: 0.5rem 1rem;
      font-size: 0.875rem;
    }

    /* Tasks Table */
    .tasks-table {
      width: 100%;
      border-collapse: collapse;
    }

    .tasks-table th {
      text-align: left;
      padding: 0.75rem 1rem;
      font-weight: 500;
      color: var(--gray);
      background: rgba(255, 255, 255, 0.03);
      font-size: 0.875rem;
    }

    .tasks-table td {
      padding: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .task-title {
      font-weight: 500;
      margin-bottom: 0.25rem;
    }

    .task-subject {
      font-size: 0.875rem;
      color: var(--gray);
    }

    .task-deadline {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.875rem;
    }

    .badge {
      padding: 0.25rem 0.5rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 500;
    }

    .badge-success {
      background: var(--success-bg);
      color: var(--secondary);
    }

    .badge-warning {
      background: var(--warning-bg);
      color: var(--warning);
    }

    .badge-danger {
      background: var(--danger-bg);
      color: var(--danger);
    }

    .actions-cell {
      display: flex;
      gap: 0.5rem;
    }

    /* Login Page */
    .login-container {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 2rem;
    }

    .login-card {
      background: var(--dark);
      border-radius: 16px;
      padding: 2.5rem;
      width: 100%;
      max-width: 480px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .login-title {
      text-align: center;
      margin-bottom: 2rem;
      font-size: 1.5rem;
      font-weight: 600;
    }

    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 500;
      font-size: 0.875rem;
    }

    .form-control {
      width: 100%;
      padding: 0.875rem 1rem;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      color: var(--light);
      font-size: 0.9375rem;
      transition: all 0.2s;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      font-size: 0.875rem;
    }

    .alert-danger {
      background: var(--danger-bg);
      color: var(--danger);
      border-left: 4px solid var(--danger);
    }

    .alert-success {
      background: var(--success-bg);
      color: var(--secondary);
      border-left: 4px solid var(--secondary);
    }

    /* Modal */
    .modal {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: all 0.3s;
    }

    .modal.active {
      opacity: 1;
      visibility: visible;
    }

    .modal-content {
      background: var(--dark);
      border-radius: 16px;
      width: 90%;
      max-width: 600px;
      max-height: 90vh;
      overflow-y: auto;
      padding: 2rem;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
      transform: translateY(20px);
      transition: transform 0.3s;
    }

    .modal.active .modal-content {
      transform: translateY(0);
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .modal-title {
      font-size: 1.25rem;
      font-weight: 600;
    }

    .close-modal {
      background: none;
      border: none;
      color: var(--gray);
      font-size: 1.5rem;
      cursor: pointer;
      transition: color 0.2s;
    }

    .close-modal:hover {
      color: var(--danger);
    }

    .time-controls {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .container {
        grid-template-columns: 1fr;
      }
      
      .sidebar {
        height: auto;
        position: static;
        border-right: none;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .time-controls {
        grid-template-columns: 1fr;
      }
    }

    /* Animations */
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .fade-in {
      animation: fadeIn 0.3s ease-out;
    }

    /* Loading */
    .loader {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 3px solid rgba(255, 255, 255, 0.3);
      border-radius: 50%;
      border-top-color: white;
      animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>
  <?php if (!isset($_SESSION['user'])): ?>
    <div class="login-container">
      <div class="login-card fade-in">
        <h1 class="login-title">EduSP Script Dashboard</h1>
        
        <?php if (isset($_SESSION['error'])): ?>
          <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="index.php">
          <input type="hidden" name="action" value="login">
          
          <div class="form-group">
            <label for="ra" class="form-label">RA (Registro do Aluno)</label>
            <input type="text" id="ra" name="ra" class="form-control" placeholder="Ex: 12345678sp" required>
          </div>
          
          <div class="form-group">
            <label for="senha" class="form-label">Senha</label>
            <input type="password" id="senha" name="senha" class="form-control" placeholder="Digite sua senha" required>
          </div>
          
          <button type="submit" class="btn btn-primary btn-block">
            <i class="fas fa-sign-in-alt"></i> Entrar
          </button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="container">
      <!-- Sidebar -->
      <aside class="sidebar">
        <div class="logo">
          <div class="logo-icon">ES</div>
          <span class="logo-text">EduSP Script</span>
        </div>
        
        <nav class="nav-menu">
          <a href="#" class="nav-item active">
            <i class="fas fa-tachometer-alt"></i>
            Dashboard
          </a>
          <a href="#" class="nav-item">
            <i class="fas fa-tasks"></i>
            Tarefas
          </a>
          <a href="#" class="nav-item">
            <i class="fas fa-chart-line"></i>
            Estatísticas
          </a>
          <a href="#" class="nav-item">
            <i class="fas fa-cog"></i>
            Configurações
          </a>
        </nav>
        
        <div style="margin-top: auto; padding-top: 1.5rem;">
          <form method="POST" action="index.php">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="nav-item" style="width: 100%; background: none; border: none; cursor: pointer;">
              <i class="fas fa-sign-out-alt"></i>
              Sair
            </button>
          </form>
        </div>
      </aside>
      
      <!-- Main Content -->
      <main class="main-content">
        <div class="header">
          <h1 class="page-title">Dashboard</h1>
          <div class="user-profile">
            <div class="avatar"><?php echo strtoupper(substr($_SESSION['user']['nickname'], 0, 1)); ?></div>
            <span class="username"><?php echo htmlspecialchars($_SESSION['user']['nickname']); ?></span>
          </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-title">Tarefas Totais</div>
            <div class="stat-value" id="totalTasks">0</div>
            <div class="stat-change">
              <i class="fas fa-database"></i> Todas as tarefas
            </div>
          </div>
          
          <div class="stat-card success">
            <div class="stat-title">Tarefas Concluídas</div>
            <div class="stat-value" id="completedTasks">0</div>
            <div class="stat-change positive">
              <i class="fas fa-check-circle"></i> Sucesso
            </div>
          </div>
          
          <div class="stat-card warning">
            <div class="stat-title">Tarefas Pendentes</div>
            <div class="stat-value" id="pendingTasks">0</div>
            <div class="stat-change">
              <i class="fas fa-clock"></i> A fazer
            </div>
          </div>
          
          <div class="stat-card danger">
            <div class="stat-title">Tarefas Expiradas</div>
            <div class="stat-value" id="expiredTasks">0</div>
            <div class="stat-change negative">
              <i class="fas fa-exclamation-circle"></i> Atrasadas
            </div>
          </div>
        </div>
        
        <!-- Tasks Section -->
        <div class="section">
          <div class="section-header">
            <h2 class="section-title">Tarefas Recentes</h2>
            <div>
              <button id="findTasksBtn" class="btn btn-primary">
                <i class="fas fa-search"></i> Buscar Tarefas
              </button>
              <button id="runAllTasksBtn" class="btn btn-success">
                <i class="fas fa-bolt"></i> Executar Todas
              </button>
            </div>
          </div>
          
          <div class="table-responsive">
            <table class="tasks-table">
              <thead>
                <tr>
                  <th>Tarefa</th>
                  <th>Disciplina</th>
                  <th>Prazo</th>
                  <th>Status</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody id="tasksTableBody">
                <tr>
                  <td colspan="5" style="text-align: center; padding: 2rem;">
                    <i class="fas fa-tasks" style="font-size: 2rem; color: var(--gray); margin-bottom: 1rem;"></i>
                    <p>Nenhuma tarefa encontrada</p>
                    <button id="loadTasksBtn" class="btn btn-sm btn-primary">
                      <i class="fas fa-sync-alt"></i> Carregar Tarefas
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
    
    <!-- Task Modal -->
    <div class="modal" id="taskModal">
      <div class="modal-content">
        <div class="modal-header">
          <h3 class="modal-title">Configurações de Execução</h3>
          <button class="close-modal" id="closeModal">&times;</button>
        </div>
        
        <div class="time-controls">
          <div class="form-group">
            <label for="minTime" class="form-label">Tempo Mínimo (minutos)</label>
            <input type="number" id="minTime" class="form-control" value="10" min="1" max="60">
          </div>
          <div class="form-group">
            <label for="maxTime" class="form-label">Tempo Máximo (minutos)</label>
            <input type="number" id="maxTime" class="form-control" value="20" min="1" max="60">
          </div>
        </div>
        
        <div class="form-group">
          <label>
            <input type="checkbox" id="expiredOnly">
            Mostrar apenas tarefas expiradas
          </label>
        </div>
        
        <div class="form-group">
          <button id="submitAllTasksBtn" class="btn btn-success btn-block">
            <i class="fas fa-play"></i> Executar Todas as Tarefas
          </button>
        </div>
      </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        // Elementos da interface
        const findTasksBtn = document.getElementById('findTasksBtn');
        const runAllTasksBtn = document.getElementById('runAllTasksBtn');
        const loadTasksBtn = document.getElementById('loadTasksBtn');
        const taskModal = document.getElementById('taskModal');
        const closeModal = document.getElementById('closeModal');
        const submitAllTasksBtn = document.getElementById('submitAllTasksBtn');
        const expiredOnlyCheckbox = document.getElementById('expiredOnly');
        const minTimeInput = document.getElementById('minTime');
        const maxTimeInput = document.getElementById('maxTime');
        const tasksTableBody = document.getElementById('tasksTableBody');
        
        // Carregar estatísticas iniciais
        loadStats();
        
        // Event listeners
        if (findTasksBtn) {
          findTasksBtn.addEventListener('click', function() {
            taskModal.classList.add('active');
          });
        }
        
        if (runAllTasksBtn) {
          runAllTasksBtn.addEventListener('click', function() {
            if (confirm('Tem certeza que deseja executar todas as tarefas?')) {
              submitAllTasks();
            }
          });
        }
        
        if (loadTasksBtn) {
          loadTasksBtn.addEventListener('click', loadTasks);
        }
        
        closeModal.addEventListener('click', function() {
          taskModal.classList.remove('active');
        });
        
        submitAllTasksBtn.addEventListener('click', function() {
          submitAllTasks();
        });
        
        expiredOnlyCheckbox.addEventListener('change', loadTasks);
        
        // Funções
        function loadStats() {
          fetch('index.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_stats'
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              document.getElementById('totalTasks').textContent = data.stats.total_tasks || 0;
              document.getElementById('completedTasks').textContent = data.stats.completed_tasks || 0;
              document.getElementById('pendingTasks').textContent = (data.stats.total_tasks || 0) - (data.stats.completed_tasks || 0);
            }
          });
        }
        
        function loadTasks() {
          const expiredOnly = expiredOnlyCheckbox.checked;
          
          // Mostrar loading
          tasksTableBody.innerHTML = `
            <tr>
              <td colspan="5" style="text-align: center; padding: 2rem;">
                <div class="loader" style="margin: 0 auto;"></div>
                <p>Carregando tarefas...</p>
              </td>
            </tr>
          `;
          
          fetch('index.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=get_tasks&expired_only=${expiredOnly}`
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              displayTasks(data.tasks);
              updateExpiredTasksCount(data.tasks);
            } else {
              showError('Erro ao carregar tarefas: ' + data.message);
            }
          })
          .catch(error => {
            console.error('Error:', error);
            showError('Erro ao carregar tarefas');
          });
        }
        
        function updateExpiredTasksCount(tasks) {
          const now = new Date();
          const expiredCount = tasks.filter(task => {
            if (!task.deadline) return false;
            return new Date(task.deadline) < now;
          }).length;
          
          document.getElementById('expiredTasks').textContent = expiredCount;
        }
        
        function displayTasks(tasks) {
          if (tasks.length === 0) {
            tasksTableBody.innerHTML = `
              <tr>
                <td colspan="5" style="text-align: center; padding: 2rem;">
                  <i class="fas fa-tasks" style="font-size: 2rem; color: var(--gray); margin-bottom: 1rem;"></i>
                  <p>Nenhuma tarefa encontrada</p>
                </td>
              </tr>
            `;
            return;
          }
          
          let html = '';
          const now = new Date();
          
          tasks.forEach(task => {
            const deadline = task.deadline ? new Date(task.deadline) : null;
            let statusBadge = '';
            
            if (deadline) {
              if (deadline < now) {
                statusBadge = '<span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> Expirada</span>';
              } else if ((deadline - now) < 86400000 * 3) { // Menos de 3 dias
                statusBadge = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pendente</span>';
              } else {
                statusBadge = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Ativa</span>';
              }
            }
            
            html += `
              <tr>
                <td>
                  <div class="task-title">${task.title || 'Sem título'}</div>
                  ${task.description ? `<div class="task-subject">${task.description}</div>` : ''}
                </td>
                <td>
                  <div class="task-subject">${task.subject || 'Não especificado'}</div>
                </td>
                <td>
                  ${deadline ? `
                    <div class="task-deadline">
                      <i class="far fa-calendar-alt"></i>
                      ${deadline.toLocaleString()}
                    </div>
                  ` : 'Sem prazo'}
                </td>
                <td>
                  ${statusBadge}
                </td>
                <td class="actions-cell">
                  <button class="btn btn-primary btn-sm" onclick="submitSingleTask('${task.id}')">
                    <i class="fas fa-play"></i> Executar
                  </button>
                </td>
              </tr>
            `;
          });
          
          tasksTableBody.innerHTML = html;
        }
        
        function submitAllTasks() {
          const minTime = parseInt(minTimeInput.value) * 60;
          const maxTime = parseInt(maxTimeInput.value) * 60;
          const expiredOnly = expiredOnlyCheckbox.checked;
          
          Swal.fire({
            title: 'Executando tarefas',
            html: 'Por favor, aguarde enquanto todas as tarefas são processadas...',
            allowOutsideClick: false,
            didOpen: () => {
              Swal.showLoading();
            }
          });
          
          fetch('index.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=submit_all_tasks&min_time=${minTime/60}&max_time=${maxTime/60}&expired_only=${expiredOnly}`
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              const successCount = data.results.filter(r => r.success).length;
              const errorCount = data.results.length - successCount;
              
              Swal.fire({
                title: 'Tarefas concluídas!',
                html: `
                  <div style="text-align: left;">
                    <p><strong>Total:</strong> ${data.results.length} tarefas</p>
                    <p style="color: var(--success);"><strong>Sucesso:</strong> ${successCount}</p>
                    <p style="color: ${errorCount > 0 ? 'var(--danger)' : 'var(--gray)'}"><strong>Falhas:</strong> ${errorCount}</p>
                  </div>
                `,
                icon: 'success'
              });
              
              loadTasks();
              loadStats();
              taskModal.classList.remove('active');
            } else {
              Swal.fire('Erro', data.message, 'error');
            }
          })
          .catch(error => {
            console.error('Error:', error);
            Swal.fire('Erro', 'Ocorreu um erro ao processar as tarefas', 'error');
          });
        }
        
        function showError(message) {
          tasksTableBody.innerHTML = `
            <tr>
              <td colspan="5" style="text-align: center; padding: 2rem; color: var(--danger);">
                <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                <p>${message}</p>
                <button onclick="location.reload()" class="btn btn-sm btn-primary">
                  <i class="fas fa-sync-alt"></i> Tentar novamente
                </button>
              </td>
            </tr>
          `;
        }
        
        window.submitSingleTask = function(taskId) {
          const minTime = parseInt(minTimeInput.value) * 60;
          const maxTime = parseInt(maxTimeInput.value) * 60;
          
          Swal.fire({
            title: 'Executando tarefa',
            html: 'Por favor, aguarde...',
            allowOutsideClick: false,
            didOpen: () => {
              Swal.showLoading();
            }
          });
          
          fetch('index.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=submit_task&task_id=${taskId}&min_time=${minTime/60}&max_time=${maxTime/60}`
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              Swal.fire({
                title: 'Sucesso!',
                text: 'Tarefa concluída com sucesso',
                icon: 'success'
              });
              loadTasks();
              loadStats();
            } else {
              Swal.fire('Erro', data.message, 'error');
            }
          })
          .catch(error => {
            console.error('Error:', error);
            Swal.fire('Erro', 'Ocorreu um erro ao executar a tarefa', 'error');
          });
        };
      });
    </script>
  <?php endif; ?>
</body>
</html>