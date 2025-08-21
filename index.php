<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>To-Do List</title>
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/calendar.css">
</head>
<body>
    <div class="container">
        <div class="top-bar">
            <button class="toggle-view-btn">Toggle View</button>
        </div>
        
        <div class="notes-container">
            <div class="grid-view" id="notesGrid">
                <!-- Your existing notes grid content -->
            </div>
            <div class="calendar-view">
                <!-- Calendar will be populated by JavaScript -->
            </div>
        </div>
    </div>
    <script src="js/scripts.js"></script>
    <script src="js/calendar-view.js"></script>
</body>
</html>