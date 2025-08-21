<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize message variable
$noTasksMessage = "No tasks found. Click 'Add Task' to create your first task!";

// Fetch todos with better error handling
try {
    $stmt = $conn->prepare("
        SELECT 
            id,
            title,
            description,
            due_date,
            priority,
            status,
            created_at,
            completed_at
        FROM todos 
        WHERE user_id = ? 
        ORDER BY 
            CASE status
                WHEN 'pending' THEN 1
                ELSE 2
            END,
            CASE priority
                WHEN 'high' THEN 1
                WHEN 'medium' THEN 2
                ELSE 3
            END,
            created_at DESC
    ");
    $stmt->execute([$user_id]);
    $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug output
    error_log("Found " . count($todos) . " tasks for user $user_id");
    
    // Clear the message if we have tasks
    if (!empty($todos)) {
        $noTasksMessage = '';
    }
} catch(PDOException $e) {
    error_log("Error fetching todos: " . $e->getMessage());
    $todos = [];
}

// Check if we have any todos
if (empty($todos)) {
    $noTasksMessage = "No tasks found. Click 'Add Task' to create your first task!";
}

// Bifurcate tasks
$pendingTasks = [];
$completedTasks = [];
foreach ($todos as $todo) {
    if ($todo['status'] === 'completed') {
        $completedTasks[] = $todo;
    } else {
        $pendingTasks[] = $todo;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task - BerrySweet</title>
    
    <!-- CSS Framework & Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/master.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/modern.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <style>
        .task-card {
            transition: box-shadow 0.3s, transform 0.3s;
            border-radius: 1.25rem;
            background: rgba(255,255,255,0.95);
            box-shadow: 0 4px 24px -8px rgba(251,56,84,0.08);
            border: 1px solid rgba(251,56,84,0.08);
            min-height: 160px; /* increased height */
            max-width: 350px;  /* smaller width */
            width: 100%;
            display: flex;
            flex-direction: column;
            position: relative;
            padding: 16px 14px;
            margin: 0 auto;
        }
        .task-card:hover {
            box-shadow: 0 12px 32px -8px rgba(251,56,84,0.16);
            transform: translateY(-4px);
        }
        .task-card.completed {
            opacity: 0.7;
            background: linear-gradient(135deg, #f0fdf4 0%, #fff0f1 100%);
        }
        .task-badge {
            font-size: 0.85rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 500;
            margin-right: 0.5rem;
        }
        .priority-high { background: #fee2e2; color: #b91c1c; }
        .priority-medium { background: #fef9c3; color: #b45309; }
        .priority-low { background: #d1fae5; color: #047857; }
        .task-date {
            font-size: 0.85rem;
            color: #64748b;
        }
        .task-actions {
            display: flex;
            gap: 0.5rem;
        }
        .bifurcate-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .bifurcate-tab {
            padding: 0.5rem 1.5rem;
            border-radius: 1rem;
            font-weight: 500;
            cursor: pointer;
            background: #f3f4f6;
            color: #fb3854;
            transition: background 0.2s, color 0.2s;
        }
        .bifurcate-tab.active {
            background: #fb3854;
            color: #fff;
        }
        .task-list-section {
            display: none;
        }
        .task-list-section.active {
            display: grid;
        }
        .task-list-section {
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.2rem;
        }
    </style>
</head>
<body class="theme-<?php echo htmlspecialchars($_SESSION['theme'] ?? 'strawberry'); ?>">
    <?php include 'components/nav.php'; ?>
    
    <main class="min-h-screen pl-80 p-8 relative z-0 theme-aware-dashboard">
        <!-- Header Section -->
        <div class="card p-8 mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-4xl font-bold text-primary mb-2">Tasks</h1>
                    <p class="text-gray-600">Manage your tasks and stay productive</p>
                </div>
                <button id="addTaskBtn" 
                        class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-xl transition-all transform hover:scale-105 hover:shadow-lg flex items-center gap-2">
                                 <i class="fas fa-plus"></i>
                    <span>Add Task</span>
                </button>
            </div>
        </div>

        <!-- Bifurcate Tabs -->
        <div class="bifurcate-tabs mb-6">
            <button class="bifurcate-tab active" data-tab="pending">Pending Tasks</button>
            <button class="bifurcate-tab" data-tab="completed">Completed Tasks</button>
        </div>

        <!-- Tasks Section -->
        <div id="pendingTasks" class="task-list-section active">
            <?php if (empty($pendingTasks)): ?>
                <div class="col-span-full card p-8 text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full mx-auto flex items-center justify-center mb-4">
                        <i class="fas fa-tasks text-primary text-xl"></i>
                    </div>
                    <p class="text-gray-500"><?php echo $noTasksMessage; ?></p>
                </div>
            <?php else: foreach ($pendingTasks as $todo): ?>
                <div class="task-card group flex flex-col justify-between h-full theme-aware-card hover:shadow-lg">
                    <div class="flex-1 flex flex-col justify-center">
                        <!-- Task Header -->
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="task-badge <?php
                                    echo match($todo['priority'] ?? 'medium') {
                                        'high' => 'bg-red-50 text-red-600',
                                        'medium' => 'bg-yellow-50 text-yellow-600',
                                        'low' => 'bg-green-50 text-green-600',
                                        default => 'bg-gray-50 text-gray-600'
                                    };
                                ?>">
                                    <?php echo ucfirst($todo['priority'] ?? 'medium'); ?>
                                </span>
                                <span class="task-date text-gray-500">
                                    <?php echo $todo['due_date'] ? date('M j, Y', strtotime($todo['due_date'])) : 'No due date'; ?>
                                </span>
                            </div>
                            <!-- Task Actions -->
                            <div class="task-actions opacity-0 group-hover:opacity-100 transition-opacity">
                                <button class="edit-task p-2 text-gray-400 hover:text-primary rounded-lg hover:bg-primary/5 transition-all"
                                        data-id="<?php echo $todo['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-task p-2 text-gray-400 hover:text-red-500 rounded-lg hover:bg-red-50 transition-all"
                                        data-id="<?php echo $todo['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-1 break-words text-center"><?php echo htmlspecialchars($todo['title']); ?></h3>
                        <?php if (!empty($todo['description'])): ?>
                            <p class="text-sm text-gray-500 mb-2 break-words text-center"><?php echo htmlspecialchars($todo['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <div class="flex items-center gap-2">
                            <input type="checkbox"
                                class="w-6 h-6 rounded-lg text-primary border-gray-300 focus:ring-primary/20 transition-all task-checkbox"
                                data-id="<?php echo $todo['id']; ?>"
                                <?php echo $todo['status'] === 'completed' ? 'checked' : ''; ?>>
                            <span class="text-sm text-gray-500">Mark as done</span>
                        </div>
                        <button class="complete-task px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-xl transition-all text-sm"
                                data-id="<?php echo $todo['id']; ?>"
                                data-status="pending">
                            Complete
                        </button>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div id="completedTasks" class="task-list-section">
            <?php if (empty($completedTasks)): ?>
                <div class="col-span-full card p-8 text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-full mx-auto flex items-center justify-center mb-4">
                        <i class="fas fa-check-circle text-green-500 text-xl"></i>
                    </div>
                    <p class="text-gray-500">No completed tasks yet.</p>
                </div>
            <?php else: foreach ($completedTasks as $todo): ?>
                <div class="task-card completed group flex flex-col justify-between h-full">
                    <div class="flex-1 flex flex-col justify-center">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="task-badge <?php
                                    echo match($todo['priority'] ?? 'medium') {
                                        'high' => 'priority-high',
                                        'medium' => 'priority-medium',
                                        'low' => 'priority-low',
                                        default => 'priority-medium'
                                    };
                                ?>">
                                    <?php echo ucfirst($todo['priority'] ?? 'medium'); ?>
                                </span>
                                <span class="task-date">
                                    <?php echo $todo['due_date'] ? 'Due: ' . date('M j, Y', strtotime($todo['due_date'])) : 'No due date'; ?>
                                </span>
                            </div>
                            <div class="task-actions">
                                <button class="edit-task p-2 text-gray-400 hover:text-primary rounded-lg hover:bg-gray-100 transition-all" title="Edit"
                                    data-id="<?php echo $todo['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-task p-2 text-gray-400 hover:text-red-500 rounded-lg hover:bg-red-50 transition-all" title="Delete"
                                    data-id="<?php echo $todo['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-400 line-through mb-1 break-words text-center"><?php echo htmlspecialchars($todo['title']); ?></h3>
                        <?php if (!empty($todo['description'])): ?>
                            <p class="text-sm text-gray-400 line-through mb-2 break-words text-center"><?php echo htmlspecialchars($todo['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <span class="text-xs text-green-700">
                            Completed on: <?php echo $todo['completed_at'] ? date('M j, Y H:i', strtotime($todo['completed_at'])) : '—'; ?>
                        </span>
                        <span class="text-xs text-gray-400">Task ID: <?php echo $todo['id']; ?></span>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </main>

    <!-- Modal with updated styling -->
    <div id="taskModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50">
        <div class="theme-aware-card w-full max-w-md rounded-2xl p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold text-primary" id="modalTitle">Add New Task</h3>
                <button id="closeTaskModal" class="p-2 hover:bg-primary/5 rounded-xl transition-colors text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="taskForm" class="space-y-6">
                <input type="hidden" id="taskId" name="id">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Task Title</label>
                    <input type="text" id="taskTitle" name="title"
                           class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-primary focus:ring focus:ring-primary/20 transition-all"
                           placeholder="Enter task title" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="taskDescription" name="description" rows="3" 
                            class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-primary focus:ring focus:ring-primary/20 transition-all"
                            placeholder="Add task details"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Due Date (Optional)</label>
                        <input type="date" id="taskDueDate" name="due_date"
                               class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-primary focus:ring focus:ring-primary/20 transition-all">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Priority</label>
                        <select id="taskPriority" name="priority"
                                class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-primary focus:ring focus:ring-primary/20 transition-all">
                            <option value="low">Low Priority</option>
                            <option value="medium">Medium Priority</option>
                            <option value="high">High Priority</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 pt-6">
                    <button type="button" id="cancelTask"
                            class="px-6 py-3 border border-gray-200 text-gray-700 rounded-xl hover:bg-gray-50 transition-all">
                        Cancel
                    </button>
                    <button type="submit"
                          class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-xl transition-all transform hover:scale-105 hover:shadow-lg flex items-center gap-2">
              
                        Save Task
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include 'components/chatbox.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="assets/js/todos.js"></script>
    <script>
    // Bifurcate tabs logic
    document.querySelectorAll('.bifurcate-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.bifurcate-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('pendingTasks').classList.toggle('active', this.dataset.tab === 'pending');
            document.getElementById('completedTasks').classList.toggle('active', this.dataset.tab === 'completed');
        });
    });

    // Completed button and checkbox logic
    function completeTask(taskId, currentStatus) {
        const newStatus = currentStatus === 'completed' ? 'pending' : 'completed';
        
        fetch('api/tasks.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: taskId,
                status: newStatus
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: newStatus === 'completed' ? 'Task Completed!' : 'Task Reopened!',
                    text: newStatus === 'completed' ? 'The task has been marked as completed.' : 'The task has been reopened.',
                    showConfirmButton: false,
                    timer: 1500
                }).then(() => window.location.reload());
            } else {
                throw new Error(data.message || 'Failed to update task');
            }
        })
        .catch(error => {
            Swal.fire('Error!', error.message, 'error');
        });
    }

    // Update event listeners for complete buttons and checkboxes
    document.querySelectorAll('.complete-task, .task-checkbox').forEach(elem => {
        elem.addEventListener('click', function() {
            const taskId = this.dataset.id;
            const currentStatus = this.dataset.status || 'pending';
            completeTask(taskId, currentStatus);
        });
    });

    document.querySelectorAll('.edit-task').forEach(btn => {
        btn.addEventListener('click', function() {
            const taskId = this.getAttribute('data-id');
            fetch(`api/tasks.php?id=${taskId}`)
                .then(response => response.json())
                .then(task => {
                    document.getElementById('taskId').value = task.id;
                    document.getElementById('taskTitle').value = task.title;
                    document.getElementById('taskDescription').value = task.description;
                    document.getElementById('taskDueDate').value = task.due_date;
                    document.getElementById('taskPriority').value = task.priority;
                    document.getElementById('modalTitle').textContent = 'Edit Task';
                    document.getElementById('taskModal').classList.remove('hidden');
                    document.getElementById('taskModal').classList.add('flex');
                });
        });
    });
    document.querySelectorAll('.delete-task').forEach(btn => {
        btn.addEventListener('click', function() {
            const taskId = this.getAttribute('data-id');
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('api/tasks.php', {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: taskId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Deleted!', 'Task has been deleted.', 'success')
                                .then(() => window.location.reload());
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(error => {
                        Swal.fire('Error!', error.message, 'error');
                    });
                }
            });
        });
    });
    </script>
</body>
</html>
