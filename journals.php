<?php
session_start();
require_once 'config/database.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Get journals ordered by creation date
    $stmt = $conn->prepare("
        SELECT * FROM journals 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
    $journals = $stmt->fetchAll();

    // Simplified stats
    $stats = [
        'total_entries' => $conn->query("SELECT COUNT(*) FROM journals WHERE user_id = $user_id")->fetchColumn(),
        'this_month' => $conn->query("SELECT COUNT(*) FROM journals WHERE user_id = $user_id AND MONTH(created_at) = MONTH(CURRENT_DATE)")->fetchColumn(),
        'streak' => $conn->query("SELECT COUNT(*) FROM (SELECT DATE(created_at) as entry_date FROM journals WHERE user_id = $user_id GROUP BY DATE(created_at)) as dates")->fetchColumn()
    ];

} catch(PDOException $e) {
    error_log("Error in journals.php: " . $e->getMessage());
    $journals = [];
    $stats = ['total_entries' => 0, 'this_month' => 0, 'streak' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal - BerrySweet</title>
    
    <!-- CSS Framework & Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <!-- Emoji Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.css">
    <script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js"></script>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/master.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <style>
        .journal-entry {
            transform: translateY(20px);
            opacity: 0;
            animation: fadeInUp 0.5s ease forwards;
        }
        
        @keyframes fadeInUp {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .mood-indicator {
            font-size: 1.5rem;
            margin-right: 0.5rem;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }

        .journal-content {
            position: relative;
            overflow: hidden;
            max-height: 200px;
            transition: max-height 0.3s ease;
        }

        .journal-content.expanded {
            max-height: none;
        }

        .fade-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 50px;
            background: linear-gradient(transparent, white);
            pointer-events: none;
        }

        .paper-texture {
            background-image: linear-gradient(rgba(255,255,255,.9) 1px, transparent 2px);
            background-size: 30px 30px;
            background-color: #fbfbfb;
            border: 1px solid #e5e7eb;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }

        .journal-modal {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }

        .color-picker {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.5rem;
        }

        .color-option {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: transform 0.2s;
            border: 2px solid transparent;
        }

        .color-option:hover {
            transform: scale(1.1);
        }

        .color-option.selected {
            border-color: var(--primary-500);
        }

        #journalContent {
            min-height: 400px;
            line-height: 2rem;
            padding: 2rem;
            background-color: white;
            background-image: 
            linear-gradient(#e5e7eb 1px, transparent 1px);
            background-size: 100% 2rem, 2rem 100%;
            background-position: 0 -1px;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            resize: vertical;
            font-size: 1rem;
            letter-spacing: 0.01em;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }

        .journal-preview {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .journal-preview:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .journal-full-content {
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .journal-full-content.shown {
            display: block;
            opacity: 1;
        }

        .emoji-trigger {
            position: absolute;
            left: 4.6rem;
            top: -0.5rem;
            padding: 0.5rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .emoji-trigger:hover {
            background: rgba(0,0,0,0.05);
        }
        
        .emoji-picker-wrapper {
            position: absolute;
            right: 1rem;
            top: 3rem;
            z-index: 10;
            display: none;
        }
        
        .emoji-picker-wrapper.active {
            display: block;
        }
    </style>
</head>
<body class="theme-<?php echo htmlspecialchars($_SESSION['theme'] ?? 'strawberry'); ?>">
    <?php include 'components/nav.php'; ?>
    
    <main class="min-h-screen pl-72 p-8">
        <!-- Add Statistics Bar before Header Section -->
        <div class="grid grid-cols-4 gap-6 mb-8">
            <?php
            // Get journal statistics
            $stats = [
                'total_entries' => $conn->query("SELECT COUNT(*) FROM journals WHERE user_id = $user_id")->fetchColumn(),
                'this_month' => $conn->query("SELECT COUNT(*) FROM journals WHERE user_id = $user_id AND MONTH(created_at) = MONTH(CURRENT_DATE)")->fetchColumn(),
                'streak' => $conn->query("SELECT COUNT(*) FROM (SELECT DATE(created_at) as entry_date FROM journals WHERE user_id = $user_id GROUP BY DATE(created_at)) as dates")->fetchColumn()
            ];
            ?>
            <div class="bg-white rounded-xl p-6 shadow-sm">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                        <i class="fas fa-book text-primary"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Total Entries</p>
                        <p class="text-2xl font-bold text-primary"><?php echo $stats['total_entries']; ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-6 shadow-sm">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                        <i class="fas fa-calendar text-blue-500"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">This Month</p>
                        <p class="text-2xl font-bold text-blue-500"><?php echo $stats['this_month']; ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-6 shadow-sm">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-50 rounded-xl flex items-center justify-center">
                        <i class="fas fa-fire text-green-500"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Writing Streak</p>
                        <p class="text-2xl font-bold text-green-500"><?php echo $stats['streak']; ?> days</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Simplified Search Bar -->
        <div class="bg-white rounded-xl p-4 mb-8">
            <div class="flex-1">
                <input type="text" id="searchJournal" placeholder="Search your journal entries..." 
                       class="w-full px-4 py-3 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
            </div>
        </div>

        <!-- Header Section -->
        <div class=" p-8 mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-4xl font-bold text-primary mb-2">My Journal</h1>
                    <p class="text-gray-600">Document your thoughts and reflections</p>
                </div>
                <button id="newJournalBtn" 
                        class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-xl transition-all transform hover:scale-105 hover:shadow-lg flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    <span>New Entry</span>
                </button>
            </div>
        </div>

        <!-- Journal Entries -->
        <div class="space-y-6">
            <?php if (empty($journals)): ?>
                <div class="card p-8 text-center animate__animated animate__fadeIn">
                    <div class="w-20 h-20 bg-primary/10 rounded-full mx-auto flex items-center justify-center mb-4">
                        <i class="fas fa-book text-primary text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-medium text-gray-900 mb-2">No Journal Entries Yet</h3>
                    <p class="text-gray-500">Start documenting your journey today!</p>
                </div>
            <?php else: foreach ($journals as $index => $journal): ?>
                <div class="journal-entry card" style="animation-delay: <?php echo $index * 0.1; ?>s">
                    <div class="journal-preview p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <span class="mood-indicator">
                                    <?php
                                    // Show emoji if mood is an emoji, else fallback to mood mapping
                                    if (!empty($journal['mood']) && preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $journal['mood'])) {
                                        echo htmlspecialchars($journal['mood']);
                                    } else {
                                        echo match($journal['mood']) {
                                            'happy' => '😊',
                                            'neutral' => '😐',
                                            'sad' => '😔',
                                            'excited' => '🤩',
                                            'tired' => '😴',
                                            'peaceful' => '😌',
                                            'anxious' => '😰',
                                            'angry' => '😠',
                                            default => '📝'
                                        };
                                    }
                                    ?>
                                </span>
                                <div>
                                    <h3 class="text-xl font-medium text-gray-900"><?php echo htmlspecialchars($journal['title']); ?></h3>
                                    <p class="text-sm text-gray-500">
                                        <?php
                                            $displayDate = !empty($journal['date']) ? $journal['date'] : $journal['created_at'];
                                            $displayTime = !empty($journal['created_at']) ? $journal['created_at'] : $journal['date'];
                                            echo date('l, F j, Y', strtotime($displayDate));
                                            if (!empty($journal['created_at'])) {
                                                echo ' at ' . date('g:i A', strtotime($journal['created_at']));
                                            }
                                        ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button class="edit-journal p-2 text-gray-400 hover:text-primary rounded-lg hover:bg-gray-100"
                                        data-id="<?php echo $journal['id']; ?>"
                                        onclick="event.stopPropagation()">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-journal p-2 text-gray-400 hover:text-red-500 rounded-lg hover:bg-red-50"
                                        data-id="<?php echo $journal['id']; ?>"
                                        onclick="event.stopPropagation()">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <i class="fas fa-chevron-down text-gray-400 transition-transform" 
                                   onclick="toggleJournalContent(<?php echo $journal['id']; ?>)"
                                   id="chevron-<?php echo $journal['id']; ?>"></i>
                            </div>
                        </div>
                    </div>
                    <div id="content-<?php echo $journal['id']; ?>" class="journal-full-content border-t border-gray-100">
                        <div class="p-6 prose max-w-none" style="background-color: <?php echo $journal['color']; ?>60">
                            <?php echo nl2br(htmlspecialchars($journal['content'])); ?>
                        </div>
                        <div class="p-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-2">
                            <!-- Moved edit/delete buttons to the preview section -->
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </main>

    <!-- Journal Modal -->
    <div id="journalModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm hidden items-center justify-center z-50">
        <div class="journal-modal w-full max-w-4xl rounded-2xl p-8 animate__animated animate__fadeInUp">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h3 class="text-2xl font-bold text-primary">New Journal Entry</h3>
                    <p class="text-sm text-gray-500 mt-1">Record your thoughts and feelings</p>
                </div>
                <button class="close-modal p-2 hover:bg-gray-100 rounded-xl transition-all">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="journalForm" class="space-y-6">
                <div class="grid grid-cols-1 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                        <input type="text" id="journalTitle" name="title"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                               placeholder="Give your entry a title..." required>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                        <input type="date" id="entryDate" name="date"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mood</label>
                        <select id="mood" name="mood" 
                                class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                            <option value="">Select Mood</option>
                            <option value="happy">😊 Khushily Khush</option>
                            <option value="excited">🤩 Mazedaar</option>
                            <option value="peaceful">😌 Shaanti</option>
                            <option value="neutral">😐 Theek Theek </option>
                            <option value="anxious">😰 Nhi Pata</option>
                            <option value="sad">😔 Dukhi</option>
                            <option value="angry">😠 Mann Karra hai maar du</option>
                            <option value="tired">😴 Mhuje neend aa rahi hai</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Theme Color</label>
                        <div class="color-picker">
                            <button type="button" class="color-option selected" style="background-color: #ffffff" data-color="#ffffff"></button>
                            <button type="button" class="color-option" style="background-color: #ffdede" data-color="#ffdede"></button>
                            <button type="button" class="color-option" style="background-color: #fff2cc" data-color="#fff2cc"></button>
                            <button type="button" class="color-option" style="background-color: #e8f3d6" data-color="#e8f3d6"></button>
                            <button type="button" class="color-option" style="background-color: #dcf2f1" data-color="#dcf2f1"></button>
                            <button type="button" class="color-option" style="background-color: #f7e6f7" data-color="#f7e6f7"></button>
                        </div>
                    </div>
                </div>
                
                <div class="relative">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Your Story</label>
                    <button type="button" class="emoji-trigger" id="emojiTrigger">
                        <i class="far fa-smile text-gray-400 text-xl"></i>
                    </button>
                    <div class="emoji-picker-wrapper" id="emojiPicker"></div>
                    <textarea id="journalContent" name="content"
                              class="paper-texture w-full rounded-xl border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                              placeholder="Dear Diary..." required></textarea>
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" class="close-modal px-6 py-3 border border-gray-200 text-gray-700 rounded-xl hover:bg-gray-50 transition-all">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-3 bg-primary hover:bg-primary-dark text-white rounded-xl transition-all flex items-center gap-2">
                        <i class="fas fa-save"></i>
                        <span>Save Entry</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Journal Modal - New Separate Modal for Editing -->
    <div id="editJournalModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm hidden items-center justify-center z-50">
        <div class="journal-modal w-full max-w-4xl rounded-2xl p-8 animate__animated animate__fadeInUp">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h3 class="text-2xl font-bold text-primary">Edit Journal Entry</h3>
                    <p class="text-sm text-gray-500 mt-1">Update your thoughts and feelings</p>
                </div>
                <button class="close-edit-modal p-2 hover:bg-gray-100 rounded-xl transition-all">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="editJournalForm" class="space-y-6">
                <input type="hidden" id="editJournalId" name="id">
                <div class="grid grid-cols-1 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                        <input type="text" id="editJournalTitle" name="title"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                               placeholder="Give your entry a title..." required>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                        <input type="date" id="editEntryDate" name="date"
                               class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mood</label>
                        <select id="editMood" name="mood" 
                                class="w-full px-4 py-2 rounded-lg border-gray-200 focus:border-primary focus:ring focus:ring-primary/20">
                            <option value="">Select Mood</option>
                            <option value="happy">😊 Khushily Khush</option>
                            <option value="excited">🤩 Mazedaar</option>
                            <option value="peaceful">😌 Shaanti</option>
                            <option value="neutral">😐 Theek Theek </option>
                            <option value="anxious">😰 Nhi Pata</option>
                            <option value="sad">😔 Dukhi</option>
                            <option value="angry">😠 Mann Karra hai maar du</option>
                            <option value="tired">😴 Mhuje neend aa rahi hai</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Theme Color</label>
                        <div class="color-picker">
                            <button type="button" class="color-option selected" style="background-color: #ffffff" data-color="#ffffff"></button>
                            <button type="button" class="color-option" style="background-color: #ffdede" data-color="#ffdede"></button>
                            <button type="button" class="color-option" style="background-color: #fff2cc" data-color="#fff2cc"></button>
                            <button type="button" class="color-option" style="background-color: #e8f3d6" data-color="#e8f3d6"></button>
                            <button type="button" class="color-option" style="background-color: #dcf2f1" data-color="#dcf2f1"></button>
                            <button type="button" class="color-option" style="background-color: #f7e6f7" data-color="#f7e6f7"></button>
                        </div>
                    </div>
                </div>
                
                <div class="relative">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Your Story</label>
                    <button type="button" class="emoji-trigger" id="emojiTrigger">
                        <i class="far fa-smile text-gray-400 text-xl"></i>
                    </button>
                    <div class="emoji-picker-wrapper" id="emojiPicker"></div>
                    <textarea id="editJournalContent" name="content"
                              class="paper-texture w-full rounded-xl border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                              placeholder="Dear Diary..." required></textarea>
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" class="close-edit-modal px-6 py-3 border border-gray-200 text-gray-700 rounded-xl hover:bg-gray-50 transition-all">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-3 bg-primary hover:bg-primary-dark text-white rounded-xl transition-all flex items-center gap-2">
                        <i class="fas fa-save"></i>
                        <span>Update Entry</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include 'components/chatbox.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="assets/js/emoji-picker.js"></script>
    <script src="assets/js/journals.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function () {
    // Attach edit and delete handlers after DOM is loaded

    // Edit Journal
    document.querySelectorAll('.edit-journal').forEach(btn => {
        btn.onclick = async function(e) {
            e.preventDefault();
            e.stopPropagation();
            const journalId = this.getAttribute('data-id');
            
            try {
                const res = await fetch('api/journals.php?id=' + journalId);
                const journal = await res.json();
                
                document.getElementById('editJournalId').value = journal.id;
                document.getElementById('editJournalTitle').value = journal.title;
                document.getElementById('editEntryDate').value = journal.date;
                document.getElementById('editMood').value = journal.mood;
                document.getElementById('editJournalContent').value = journal.content;
                
                document.querySelectorAll('#editJournalModal .color-option').forEach(b => {
                    b.classList.remove('selected');
                    if (b.getAttribute('data-color').toLowerCase() === (journal.color || '#ffffff').toLowerCase()) {
                        b.classList.add('selected');
                    }
                });
                
                document.getElementById('editJournalModal').classList.remove('hidden');
                document.getElementById('editJournalModal').classList.add('flex');
            } catch (err) {
                Swal.fire('Error', 'Failed to load journal entry', 'error');
            }
        };
    });

    // Delete Journal
    document.querySelectorAll('.delete-journal').forEach(btn => {
        btn.onclick = function(e) {
            e.stopPropagation();
            const journalId = this.getAttribute('data-id');
            Swal.fire({
                title: 'Delete this entry?',
                text: "This action cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#fb3854',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('api/journals.php', {
                        method: 'POST', // Use POST for delete override
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: journalId, _method: 'DELETE' })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            // Remove the journal entry from the DOM instantly
                            const entry = btn.closest('.journal-entry');
                            if (entry) entry.remove();
                            Swal.fire('Deleted!', 'Entry has been deleted.', 'success');
                        } else {
                            Swal.fire('Error', data.message || 'Failed to delete entry.', 'error');
                        }
                    })
                    .catch(() => {
                        Swal.fire('Error', 'Failed to delete entry.', 'error');
                    });
                }
            });
        };
    });

    // Handle journal form submit for create and edit
    document.getElementById('journalForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = this;
        const editId = form.getAttribute('data-edit-id');
        const title = document.getElementById('journalTitle').value.trim();
        const date = document.getElementById('entryDate').value;
        const mood = document.getElementById('mood').value;
        const content = document.getElementById('journalContent').value;
        let color = "#ffffff";
        document.querySelectorAll('.color-option').forEach(b => {
            if (b.classList.contains('selected')) color = b.getAttribute('data-color');
        });

        const payload = {
            title,
            date,
            mood,
            color,
            content
        };
        if (editId) payload.id = editId;

        try {
            const response = await fetch('api/journals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                Swal.fire('Saved!', 'Journal entry has been saved.', 'success')
                    .then(() => window.location.reload());
            } else {
                Swal.fire('Error', result.message || 'Failed to save entry.', 'error');
            }
        } catch (err) {
            Swal.fire('Error', 'Failed to save entry.', 'error');
        } finally {
            form.removeAttribute('data-edit-id');
        }
    });

    // Handle Edit Form Submit
    document.getElementById('editJournalForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = {
            id: document.getElementById('editJournalId').value,
            title: document.getElementById('editJournalTitle').value,
            date: document.getElementById('editEntryDate').value,
            mood: document.getElementById('editMood').value,
            content: document.getElementById('editJournalContent').value,
            color: document.querySelector('#editJournalModal .color-option.selected').getAttribute('data-color')
        };

        try {
            const response = await fetch('api/journals.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            
            const result = await response.json();
            if (result.success) {
                Swal.fire('Updated!', 'Journal entry has been updated.', 'success')
                    .then(() => window.location.reload());
            } else {
                throw new Error(result.message || 'Failed to update entry');
            }
        } catch (err) {
            Swal.fire('Error', err.message, 'error');
        }
    });

    // Close Edit Modal
    document.querySelectorAll('.close-edit-modal').forEach(btn => {
        btn.onclick = function() {
            document.getElementById('editJournalModal').classList.add('hidden');
            document.getElementById('editJournalModal').classList.remove('flex');
        };
    });
});
</script>
</body>
</html>
