<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch notes with error handling
try {
    $stmt = $conn->prepare("
        SELECT 
            id, 
            title, 
            content, 
            color,
            COALESCE(is_pinned, 0) as is_pinned,
            created_at 
        FROM notes 
        WHERE user_id = ? 
        ORDER BY is_pinned DESC, created_at DESC
    ");
    $stmt->execute([$user_id]);
    $notes = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log("Error fetching notes: " . $e->getMessage());
    $notes = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"="width=device-width, initial-scale=1.0">
    <title>Notes - BerrySweet</title>
    
    <!-- CSS Framework & Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/master.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <style>
        .calendar-view { display: none; }
        .calendar-view.active { display: block; }
        .notes-grid.hidden { display: none; }
        
        #calendarView {
            display: none;
        }
        #calendarView.active {
            display: block;
        }
        
        .calendar {
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 8px;
            width: 100%;
            overflow-x: auto;
        }
        
        .calendar-header {
            display: grid;
            grid-template-columns: repeat(7, minmax(9.7rem, 1fr));
            text-align: center;
            font-weight: bold;
            padding: 8px 0;
            border-bottom: 2px solid #eee;
            margin-bottom: 16px;
            position: relative;
            background: #fff;
        }
        
        .calendar-header div {
            padding: 12px 8px;
            font-size: 1rem;
            color: #444;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(9.7rem, 1fr));
            gap: 8px;
            padding-top: 8px;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            border: 1px solid #eee;
            padding: 12px;
            min-height: 120px;
            position: relative;
            background: #fff;
            display: flex;
            flex-direction: column;
            border-radius: 15px;
        }
        
        .calendar-day .date {
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f8f8f8;
            width: fit-content;
            position: relative;
            z-index: 1;
        }
        
        .note-marker {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin: 3px;
            display: inline-block;
            transition: transform 0.2s;
        }
        
        .note-marker:hover {
            transform: scale(1.2);
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
        }
        
        .note-markers {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            padding: 4px;
            margin-top: 4px;
        }
        
        .empty-day {
            background: #f9f9f9;
            opacity: 0.7;
        }
    </style>
</head>
<body class="theme-<?php echo htmlspecialchars($_SESSION['theme'] ?? 'strawberry'); ?>">
    <?php include 'components/nav.php'; ?>
    
    <main class="min-h-screen pl-72 p-8 animate-fade-in">
        <!-- Header Section -->
        <div class="card p-8 mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-4xl font-bold text-primary mb-2">My Notes</h1>
                    <p class="text-gray-600">Capture your thoughts and ideas</p>
                </div>
                <div class="flex gap-4">
                    <button id="viewToggle" 
                            class="px-6 py-3 bg-primary/10 text-primary rounded-xl hover:bg-primary/20 transition-all flex items-center gap-2">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Toggle View</span>
                    </button>
                    <button id="addNoteBtn" 
                            class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-xl transition-all transform hover:scale-105 hover:shadow-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i>
                        <span>Add Note</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Grid View -->
        <div id="notesGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php if (empty($notes)): ?>
                <div class="col-span-full card p-8 text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full mx-auto flex items-center justify-center mb-4">
                        <i class="fas fa-sticky-note text-primary text-xl"></i>
                    </div>
                    <p class="text-gray-500">No notes yet. Click 'Add Note' to create your first note!</p>
                </div>
            <?php else: foreach ($notes as $note): ?>
                <?php
                    // Always use the selected color as the background, even if it's white.
                    // For white, add a subtle border for visibility.
                    $noteBg = $note['color'] ? $note['color'] : '#fff';
                    $isWhite = in_array(strtolower($noteBg), ['#fff', '#ffffff', 'white']);
                    // Make the color lighter by blending with white (using 15% opacity)
                    if (!$isWhite) {
                        // If color is hex, convert to rgba with alpha
                        if (preg_match('/^#([a-f0-9]{6})$/i', $noteBg, $m)) {
                            $rgb = [
                                hexdec(substr($m[1], 0, 2)),
                                hexdec(substr($m[1], 2, 2)),
                                hexdec(substr($m[1], 4, 2))
                            ];
                            $noteBgCss = "background: rgba({$rgb[0]},{$rgb[1]},{$rgb[2]},0.15);";
                        } elseif (preg_match('/^#([a-f0-9]{3})$/i', $noteBg, $m)) {
                            $rgb = [
                                hexdec(str_repeat(substr($m[1], 0, 1), 2)),
                                hexdec(str_repeat(substr($m[1], 1, 1), 2)),
                                hexdec(str_repeat(substr($m[1], 2, 1), 2))
                            ];
                            $noteBgCss = "background: rgba({$rgb[0]},{$rgb[1]},{$rgb[2]},0.15);";
                        } else {
                            $noteBgCss = "background: {$noteBg};";
                        }
                    } else {
                        $noteBgCss = "background: #fff; border: 1.5px solid #e5e7eb;";
                    }
                ?>
                <div class="card note-card p-6 hover:scale-[1.02] transition-all duration-300 group cursor-pointer"
                     data-id="<?php echo $note['id']; ?>"
                     style="<?php echo $noteBgCss; ?>">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h3 class="font-medium text-lg text-gray-900"><?php echo htmlspecialchars($note['title']); ?></h3>
                                <?php if (!empty($note['is_pinned'])): ?>
                                    <i class="fas fa-thumbtack text-primary text-sm"></i>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($note['created_at'])); ?></div>
                        </div>
                        <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button class="edit-note p-2 text-gray-400 hover:text-primary rounded-lg hover:bg-white/50 transition-all">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="delete-note p-2 text-gray-400 hover:text-red-500 rounded-lg hover:bg-white/50 transition-all">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="prose prose-sm max-w-none text-gray-600 line-clamp-3" style="min-height: 3rem;">
                        <?php echo $note['content']; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Calendar View -->
        <div id="calendarView" class="calendar-view card p-6 hidden transform transition-all duration-300">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-medium text-gray-900">Calendar View</h3>
                <div class="flex gap-2">
                    <button id="prevMonth" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="currentMonth" class="py-2 px-4 font-medium"></span>
                    <button id="nextMonth" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
            <div class="calendar"></div>
        </div>

        <!-- Note Modal -->
        <div id="noteModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm hidden items-center justify-center z-50">
            <div class="bg-white w-full max-w-4xl rounded-2xl shadow-2xl transform transition-all duration-300" id="modalContent">
                <!-- Modal Header -->
                <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="text-2xl font-bold text-primary" id="modalTitle">Add New Note</h3>
                    <button id="closeNoteModal" class="p-2 hover:bg-gray-100 rounded-xl transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form id="noteForm" class="flex flex-col h-[calc(100vh-20rem)]">
                    <div class="flex-1 p-6 space-y-6 overflow-y-auto">
                        <!-- Title Input -->
                        <div class="grid grid-cols-4 gap-4 items-center">
                            <div class="col-span-3">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                                <input type="text" id="noteTitle" name="title"
                                       class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-primary focus:ring focus:ring-primary/20"
                                       placeholder="Enter note title..." required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Color</label>
                                <div class="color-preview flex gap-2 flex-wrap">
                                    <?php foreach(['#FF7EB9', '#7afcff', '#FEFF9C', '#fff740', '#7AFFAD', '#ff65a3', '#FFF'] as $color): ?>
                                        <button type="button" 
                                                class="w-8 h-8 rounded-lg border hover:scale-110 transition-transform color-option <?php echo $color === '#FFF' ? 'ring-2 ring-primary' : ''; ?>"
                                                style="background-color: <?php echo $color; ?>; position: relative;"
                                                data-color="<?php echo $color; ?>">
                                            <?php if ($color === '#FFF'): ?>
                                                <i class="fas fa-check text-primary" style="color: #fb3854; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);"></i>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Rich Text Editor -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Content</label>
                            <div id="editor" class="h-64 border rounded-xl overflow-hidden"></div>
                        </div>

                        <!-- Additional Options -->
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="isPinned" name="is_pinned" 
                                       class="w-4 h-4 rounded text-primary border-gray-300 focus:ring-primary/20">
                                <label for="isPinned" class="text-sm text-gray-700">Pin to top</label>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="p-6 border-t border-gray-100 bg-gray-50/80 rounded-b-2xl">
                        <div class="flex justify-between items-center">
                            <div class="text-sm text-gray-500" id="wordCount">0 words</div>
                            <div class="flex gap-3">
                                <button type="button" id="cancelNote"
                                        class="px-6 py-3 border border-gray-200 text-gray-700 rounded-xl hover:bg-gray-50 transition-all">
                                    Cancel
                                </button>
                                <button type="submit"
                                        id="saveNoteBtn"
                                        class="px-6 py-3 bg-primary hover:bg-primary-dark text-white rounded-xl transition-all flex items-center gap-2">
                                    <i class="fas fa-save"></i>
                                    <span>Save Note</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </main>

    <?php include 'components/chatbox.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="assets/js/notes.js"></script>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script>
        document.getElementById('viewToggle').addEventListener('click', function() {
            const calendarView = document.getElementById('calendarView');
            const notesGrid = document.getElementById('notesGrid');
            
            if (calendarView.classList.contains('active')) {
                calendarView.classList.remove('active');
                notesGrid.classList.remove('hidden');
            } else {
                calendarView.classList.add('active');
                notesGrid.classList.add('hidden');
                loadCalendarView();
            }
        });

        async function loadCalendarView() {
            try {
                const response = await fetch('api/notes.php');
                const notes = await response.json();
                
                const calendar = document.querySelector('.calendar');
                const currentMonthEl = document.getElementById('currentMonth');
                const date = new Date();
                
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                                  'July', 'August', 'September', 'October', 'November', 'December'];
                
                currentMonthEl.textContent = `${monthNames[date.getMonth()]} ${date.getFullYear()}`;
                
                const daysInMonth = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
                const firstDay = new Date(date.getFullYear(), date.getMonth(), 1).getDay();
                
                const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                let calendarHTML = `
                    <div class="calendar-header">
                        ${days.map(day => `<div>${day}</div>`).join('')}
                    </div>
                    <div class="calendar-grid">
                `;
                
                // Add empty cells for days before the first day of month
                for (let i = 0; i < firstDay; i++) {
                    calendarHTML += '<div class="calendar-day empty-day"></div>';
                }
                
                for (let i = 1; i <= daysInMonth; i++) {
                    const dayNotes = notes.filter(note => {
                        const noteDate = new Date(note.created_at);
                        return noteDate.getDate() === i && 
                               noteDate.getMonth() === date.getMonth() &&
                               noteDate.getFullYear() === date.getFullYear();
                    });
                    
                    calendarHTML += `
                        <div class="calendar-day">
                            <div class="date">${i}</div>
                            <div class="note-markers">
                                ${dayNotes.map(note => `
                                    <div class="note-marker" 
                                         title="${note.title}"
                                         style="background-color: ${note.color || '#ffffff'};
                                                box-shadow: 0 0 3px rgba(0,0,0,0.2);">
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                }
                
                // Add remaining empty cells to complete the grid
                const totalDays = firstDay + daysInMonth;
                const remainingDays = Math.ceil(totalDays / 7) * 7 - totalDays;
                for (let i = 0; i < remainingDays; i++) {
                    calendarHTML += '<div class="calendar-day empty-day"></div>';
                }
                
                // Close the calendar-grid div
                calendarHTML += '</div>';
                
                calendar.innerHTML = calendarHTML;
            } catch (error) {
                console.error('Error loading calendar:', error);
            }
        }

        // Quill editor initialization
        let quill;
        document.addEventListener('DOMContentLoaded', function() {
            // Only one Quill editor initialization on the single #editor div
            quill = new Quill('#editor', {
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'font': [] }, { 'size': [] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'color': [] }, { 'background': [] }],
                        [{ 'script': 'sub'}, { 'script': 'super' }],
                        [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
                        ['blockquote', 'code-block'],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });

            // Prevent double submission and double event binding
            let submitting = false;
            const noteForm = document.getElementById('noteForm');

            // Remove all previous submit/cancel event listeners by replacing the node
            // (Do NOT replace the editor div, only the form events)
            noteForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                if (submitting) return;
                submitting = true;

                const title = document.getElementById('noteTitle').value.trim();
                const content = quill.root.innerHTML.trim();
                const colorBtn = document.querySelector('.color-option.ring-2.ring-primary') || document.querySelector('.color-option.ring-2') || document.querySelector('.color-option');
                const color = colorBtn ? colorBtn.getAttribute('data-color') : '#FFF';
                const isPinned = document.getElementById('isPinned').checked ? 1 : 0;

                if (!title || !content) {
                    Swal.fire('Error', 'Title and content are required.', 'error');
                    submitting = false;
                    return;
                }

                try {
                    const response = await fetch('api/notes.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            title,
                            content,
                            color,
                            is_pinned: isPinned
                        })
                    });
                    const result = await response.json();
                    if (result.success) {
                        Swal.fire('Saved!', 'Note has been saved.', 'success')
                            .then(() => window.location.reload());
                    } else {
                        throw new Error(result.message || 'Failed to save note.');
                    }
                } catch (err) {
                    Swal.fire('Error', err.message, 'error');
                } finally {
                    submitting = false;
                }
            });

            // Cancel button logic
            document.getElementById('cancelNote').addEventListener('click', function() {
                document.getElementById('noteModal').classList.add('hidden');
                noteForm.reset();
                quill.setContents([]);
                document.querySelectorAll('.color-option').forEach(b => b.classList.remove('ring-2', 'ring-primary'));
                const defaultBtn = document.querySelector('.color-option[data-color="#FFF"]');
                if (defaultBtn) defaultBtn.classList.add('ring-2', 'ring-primary');
            });

            // Color selection logic
            document.querySelectorAll('.color-option').forEach(btn => {
                btn.addEventListener('click', function() {
                    // Remove selection from all
                    document.querySelectorAll('.color-option').forEach(b => b.classList.remove('ring-2', 'ring-primary'));
                    // Add selection to clicked
                    this.classList.add('ring-2', 'ring-primary');
                    // Move the check icon to the selected button
                    document.querySelectorAll('.color-option i.fas.fa-check').forEach(icon => icon.remove());
                    // Only show the check icon on the selected color
                    if (this.getAttribute('data-color').toLowerCase() === '#fff' || this.getAttribute('data-color').toLowerCase() === '#ffffff') {
                        this.innerHTML = '<i class="fas fa-check text-primary" style="color: #fb3854; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);"></i>';
                    }
                });
            });

            // Edit and Delete Note Button Logic
            function attachNoteCardActions() {
                // Edit Note
                document.querySelectorAll('.edit-note').forEach(btn => {
                    btn.onclick = async function(e) {
                        e.stopPropagation();
                        const card = this.closest('.note-card');
                        const noteId = card.getAttribute('data-id');
                        try {
                            const res = await fetch('api/notes.php?id=' + noteId);
                            const note = await res.json();
                            document.getElementById('modalTitle').textContent = 'Edit Note';
                            document.getElementById('noteTitle').value = note.title;
                            if (window.quill) quill.root.innerHTML = note.content;
                            document.getElementById('isPinned').checked = note.is_pinned == 1 || note.is_pinned === true;
                            document.querySelectorAll('.color-option').forEach(b => {
                                b.classList.remove('ring-2', 'ring-primary');
                                if (b.getAttribute('data-color').toLowerCase() === (note.color || '#fff').toLowerCase()) {
                                    b.classList.add('ring-2', 'ring-primary');
                                }
                            });
                            document.getElementById('noteModal').classList.remove('hidden');
                            document.getElementById('noteForm').setAttribute('data-edit-id', noteId);
                        } catch (err) {
                            Swal.fire('Error', 'Failed to load note for editing.', 'error');
                        }
                    };
                });

                // Delete Note
                document.querySelectorAll('.delete-note').forEach(btn => {
                    btn.onclick = function(e) {
                        e.stopPropagation();
                        const card = this.closest('.note-card');
                        const noteId = card.getAttribute('data-id');
                        Swal.fire({
                            title: 'Delete this note?',
                            text: "This action cannot be undone.",
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#fb3854',
                            cancelButtonColor: '#6b7280',
                            confirmButtonText: 'Yes, delete it!'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                fetch('api/notes.php', {
                                    method: 'DELETE',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ id: noteId })
                                })
                                .then(res => res.json())
                                .then(data => {
                                    if (data.success) {
                                        Swal.fire('Deleted!', 'Note has been deleted.', 'success')
                                            .then(() => window.location.reload());
                                    } else {
                                        Swal.fire('Error', data.message || 'Failed to delete note.', 'error');
                                    }
                                });
                            }
                        });
                    };
                });
            }

            // Attach actions after DOM is loaded and after any dynamic content update
            document.addEventListener('DOMContentLoaded', function() {
                attachNoteCardActions();
            });
        });
    </script>
</body>
</html>
