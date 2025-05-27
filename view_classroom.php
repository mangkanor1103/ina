<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to access this page.");
    redirect("login.php");
}

// Get classroom ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    set_flash_message("error", "Invalid classroom ID.");
    redirect("index.php");
}

$classroom_id = $_GET['id'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get classroom details
$stmt = $conn->prepare("SELECT * FROM classrooms WHERE id = ?");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if classroom exists
if ($result->num_rows === 0) {
    set_flash_message("error", "Classroom not found.");
    redirect("index.php");
}

$classroom = $result->fetch_assoc();

// Check permissions
$has_access = false;

if ($user_role === 'teacher' && $classroom['teacher_id'] === $user_id) {
    $has_access = true;
    $is_teacher = true;
} elseif ($user_role === 'student') {
    // Check if student is enrolled in this classroom
    $classroom_students_table_exists = $conn->query("SHOW TABLES LIKE 'classroom_students'")->num_rows > 0;
    if ($classroom_students_table_exists) {
        $stmt = $conn->prepare("SELECT * FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
        $stmt->bind_param("ii", $classroom_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $has_access = true;
            $is_teacher = false;
        }
    }
} elseif ($user_role === 'admin') {
    $has_access = true;
    $is_teacher = true;
}

if (!$has_access) {
    set_flash_message("error", "You don't have permission to access this classroom.");
    redirect("index.php");
}

// Get teacher information
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $classroom['teacher_id']);
$stmt->execute();
$result = $stmt->get_result();
$teacher = $result->fetch_assoc();

// Get enrolled students count (check if table exists)
$student_count = 0;
$classroom_students_table_exists = $conn->query("SHOW TABLES LIKE 'classroom_students'")->num_rows > 0;
if ($classroom_students_table_exists) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM classroom_students WHERE classroom_id = ?");
    $stmt->bind_param("i", $classroom_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student_count = $result->fetch_assoc()['count'];
}

// Get lessons
$stmt = $conn->prepare("SELECT * FROM lessons WHERE classroom_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();
$lessons = $result->fetch_all(MYSQLI_ASSOC);

// Get quizzes (check if table exists)
$quizzes = [];
$quizzes_table_exists = $conn->query("SHOW TABLES LIKE 'quizzes'")->num_rows > 0;
if ($quizzes_table_exists) {
    $stmt = $conn->prepare("SELECT * FROM quizzes WHERE classroom_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $classroom_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $quizzes = $result->fetch_all(MYSQLI_ASSOC);
}

// Get recent activity if the table exists
$activity = [];
$activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;

if ($activity_table_exists) {
    $stmt = $conn->prepare("
        SELECT al.*, u.username 
        FROM activity_log al
        JOIN users u ON al.user_id = u.id
        WHERE al.classroom_id = ?
        ORDER BY al.created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $classroom_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $activity = $result->fetch_all(MYSQLI_ASSOC);
}
?>

<div class="container mx-auto px-4 py-6 fade-in">
    <div class="mb-6 animate__animated animate__fadeInLeft">
        <?php if ($is_teacher): ?>
            <a href="classrooms.php" class="text-green-600 hover:text-green-800 hover:underline flex items-center transition-colors duration-300">
        <?php else: ?>
            <a href="my_classes.php" class="text-green-600 hover:text-green-800 hover:underline flex items-center transition-colors duration-300">
        <?php endif; ?>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Classrooms
        </a>
    </div>

    <!-- Classroom Header -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden hover-scale animate__animated animate__fadeIn mb-8">
        <div class="bg-green-600 p-6 text-white">
            <h1 class="text-3xl font-bold mb-2"><?php echo htmlspecialchars($classroom['name']); ?></h1>
            <p class="text-green-100 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <span class="font-medium">Teacher:</span> <?php echo htmlspecialchars($teacher['username']); ?>
            </p>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-2">
                    <div class="bg-gray-50 rounded-lg p-4 mb-4">
                        <h3 class="font-semibold text-gray-700 mb-2">Description</h3>
                        <?php if (!empty($classroom['description'])): ?>
                            <p class="text-gray-600"><?php echo nl2br(htmlspecialchars($classroom['description'])); ?></p>
                        <?php else: ?>
                            <p class="text-gray-500 italic">No description provided.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex flex-wrap items-center space-x-6 text-sm">
                        <div class="flex items-center mb-2 text-gray-600">
                            <div class="bg-green-100 text-green-700 p-2 rounded-full mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            </div>
                            <div>
                                <div class="font-semibold"><?php echo $student_count; ?></div>
                                <div class="text-xs text-gray-500">Students</div>
                            </div>
                        </div>
                        
                        <div class="flex items-center mb-2 text-gray-600">
                            <div class="bg-blue-100 text-blue-700 p-2 rounded-full mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                            </div>
                            <div>
                                <div class="font-semibold"><?php echo count($lessons); ?></div>
                                <div class="text-xs text-gray-500">Lessons</div>
                            </div>
                        </div>

                        <div class="flex items-center mb-2 text-gray-600">
                            <div class="bg-purple-100 text-purple-700 p-2 rounded-full mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                </svg>
                            </div>
                            <div>
                                <div class="font-semibold"><?php echo count($quizzes); ?></div>
                                <div class="text-xs text-gray-500">Quizzes</div>
                            </div>
                        </div>
                        
                        <div class="flex items-center mb-2 text-gray-600">
                            <div class="bg-amber-100 text-amber-700 p-2 rounded-full mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <div class="font-semibold"><?php echo date('M d, Y', strtotime($classroom['created_at'])); ?></div>
                                <div class="text-xs text-gray-500">Created</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div>
                    <?php if ($is_teacher): ?>
                        <div class="bg-green-50 p-4 rounded-lg border border-green-100 shadow-sm">
                            <h3 class="font-semibold text-green-800 mb-3">Teacher Actions</h3>
                            <div class="space-y-2">
                                <a href="edit_classroom.php?id=<?php echo $classroom_id; ?>" class="bg-white hover:bg-gray-50 text-gray-700 w-full px-4 py-2 rounded-md flex items-center border border-gray-200 transition-colors duration-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit Classroom
                                </a>
                                
                                <?php if ($classroom_students_table_exists): ?>
                                <a href="manage_students.php?classroom_id=<?php echo $classroom_id; ?>" class="bg-white hover:bg-gray-50 text-gray-700 w-full px-4 py-2 rounded-md flex items-center border border-gray-200 transition-colors duration-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    Manage Students
                                </a>
                                <?php endif; ?>
                                
                                <a href="create_lesson.php?classroom_id=<?php echo $classroom_id; ?>" class="bg-green-600 hover:bg-green-700 text-white w-full px-4 py-2 rounded-md flex items-center transition-colors duration-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Add New Lesson
                                </a>
                                
                                <a href="create_quiz.php?classroom_id=<?php echo $classroom_id; ?>" class="bg-purple-600 hover:bg-purple-700 text-white w-full px-4 py-2 rounded-md flex items-center transition-colors duration-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                    </svg>
                                    Create Quiz
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($activity)): ?>
                        <div class="mt-4 bg-gray-50 p-4 rounded-lg shadow-sm">
                            <h3 class="font-semibold text-gray-700 mb-3">Recent Activity</h3>
                            <div class="space-y-3">
                                <?php foreach($activity as $item): ?>
                                    <div class="flex items-start text-sm">
                                        <div class="bg-gray-200 text-gray-600 rounded-full h-7 w-7 flex items-center justify-center mr-3 flex-shrink-0">
                                            <?php 
                                            $icon = match($item['activity_type']) {
                                                'lesson_created' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>',
                                                'quiz_created' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>',
                                                'submission' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>',
                                                'student_added' => '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>',
                                                default => '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
                                            };
                                            echo $icon;
                                            ?>
                                        </div>
                                        <div>
                                            <p class="text-gray-600">
                                                <?php echo htmlspecialchars($item['description']); ?>
                                            </p>
                                            <p class="text-xs text-gray-400 mt-1">
                                                <?php echo date('M d, g:i a', strtotime($item['created_at'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="mb-6 animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button onclick="showTab('lessons')" id="lessons-tab" class="tab-button active border-green-500 text-green-600 py-2 px-1 border-b-2 font-medium text-sm transition-colors duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    Lessons (<?php echo count($lessons); ?>)
                </button>
                <button onclick="showTab('quizzes')" id="quizzes-tab" class="tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 py-2 px-1 border-b-2 font-medium text-sm transition-colors duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    Quizzes (<?php echo count($quizzes); ?>)
                </button>
            </nav>
        </div>
    </div>

    <!-- Lessons Tab Content -->
    <div id="lessons-content" class="tab-content">
        <div class="mb-6 flex flex-col md:flex-row md:justify-between md:items-center">
            <h2 class="text-2xl font-bold text-green-800 mb-4 md:mb-0">Lessons</h2>
            
            <?php if ($is_teacher): ?>
                <a href="create_lesson.php?classroom_id=<?php echo $classroom_id; ?>" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg flex items-center justify-center transition-all duration-300 hover:shadow-lg transform hover:scale-[1.02]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add New Lesson
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($lessons)): ?>
            <div class="bg-green-50 border-l-4 border-green-400 p-6 rounded-lg shadow-sm">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-green-700">
                            <?php if ($is_teacher): ?>
                                No lessons have been added to this classroom yet. Click "Add New Lesson" to get started.
                            <?php else: ?>
                                No lessons have been added to this classroom yet.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Empty state illustration for teachers -->
            <?php if ($is_teacher): ?>
                <div class="mt-12 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-32 w-32 mx-auto text-green-200 mb-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Time to Add Your First Lesson</h3>
                    <p class="text-gray-500 max-w-md mx-auto">Create lessons to share learning materials, assignments, and resources with your students.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($lessons as $lesson): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden hover-scale feature-card transition-all duration-300">
                        <div class="p-6">
                            <div class="flex flex-wrap justify-between items-start">
                                <h3 class="text-xl font-bold mb-2 text-green-800"><?php echo htmlspecialchars($lesson['title']); ?></h3>
                                <div class="flex items-center text-sm text-gray-500">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    <?php echo date('F j, Y', strtotime($lesson['created_at'])); ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($lesson['description'])): ?>
                                <p class="text-gray-600 mb-4 mt-2"><?php echo nl2br(htmlspecialchars($lesson['description'])); ?></p>
                            <?php endif; ?>
                            
                            <?php
                            // Count submissions for this lesson
                            $submission_count = 0;
                            $submissions_table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;
                            if ($is_teacher && $submissions_table_exists) {
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM submissions WHERE lesson_id = ?");
                                $stmt->bind_param("i", $lesson['id']);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $submission_count = $result->fetch_assoc()['count'];
                            }
                            ?>
                            
                            <?php if ($is_teacher && $submission_count > 0): ?>
                                <div class="flex items-center mb-4">
                                    <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <?php echo $submission_count; ?> Submissions
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="flex flex-wrap items-center justify-between mt-4">
                                <div class="flex flex-wrap space-x-2 mb-2 md:mb-0">
                                    <?php if (!empty($lesson['file_path'])): ?>
                                        <a href="<?php echo $lesson['file_path']; ?>" class="bg-blue-100 hover:bg-blue-200 text-blue-700 px-4 py-2 rounded-md flex items-center transition-colors duration-300 mb-2 md:mb-0" download>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4-4m0 0l-4-4m4 4V4" />
                                            </svg>
                                            Download Attachment
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="view_lesson.php?id=<?php echo $lesson['id']; ?>" class="bg-green-100 hover:bg-green-200 text-green-700 px-4 py-2 rounded-md flex items-center transition-colors duration-300">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        View Details
                                    </a>
                                </div>
                                
                                <?php if ($is_teacher): ?>
                                    <div class="flex flex-wrap space-x-2">
                                        <a href="edit_lesson.php?id=<?php echo $lesson['id']; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md flex items-center transition-colors duration-300 mb-2 md:mb-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            Edit
                                        </a>
                                        <?php if ($submissions_table_exists): ?>
                                        <a href="lesson_submissions.php?id=<?php echo $lesson['id']; ?>" class="bg-green-100 hover:bg-green-200 text-green-700 px-4 py-2 rounded-md flex items-center transition-colors duration-300">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                            View Submissions
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php
                                    // Check if student has already submitted
                                    $has_submitted = false;
                                    $submission = null;
                                    if ($submissions_table_exists) {
                                        $stmt = $conn->prepare("SELECT id, grade FROM submissions WHERE lesson_id = ? AND student_id = ?");
                                        $stmt->bind_param("ii", $lesson['id'], $user_id);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        $submission = $result->fetch_assoc();
                                        $has_submitted = $result->num_rows > 0;
                                    }
                                    ?>
                                    
                                    <?php if ($has_submitted): ?>
                                        <div class="flex items-center">
                                            <?php if ($submission['grade'] !== null): ?>
                                                <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium flex items-center mr-2">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Grade: <?php echo $submission['grade']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-medium flex items-center mr-2">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Awaiting Grade
                                                </span>
                                            <?php endif; ?>
                                            <a href="view_submission.php?id=<?php echo $submission['id']; ?>" class="text-green-600 hover:text-green-800 text-sm font-medium transition-colors duration-300">
                                                View Submission
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <a href="submit_assignment.php?lesson_id=<?php echo $lesson['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md flex items-center transition-colors duration-300 transform hover:scale-[1.02]">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                            </svg>
                                            Submit Assignment
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quizzes Tab Content -->
    <div id="quizzes-content" class="tab-content hidden">
        <div class="mb-6 flex flex-col md:flex-row md:justify-between md:items-center">
            <h2 class="text-2xl font-bold text-purple-800 mb-4 md:mb-0">Quizzes</h2>
            
            <?php if ($is_teacher): ?>
                <a href="create_quiz.php?classroom_id=<?php echo $classroom_id; ?>" class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2 rounded-lg flex items-center justify-center transition-all duration-300 hover:shadow-lg transform hover:scale-[1.02]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Create New Quiz
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($quizzes)): ?>
            <div class="bg-purple-50 border-l-4 border-purple-400 p-6 rounded-lg shadow-sm">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-purple-700">
                            <?php if ($is_teacher): ?>
                                No quizzes have been created for this classroom yet. Click "Create New Quiz" to get started.
                            <?php else: ?>
                                No quizzes have been created for this classroom yet.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Empty state illustration for teachers -->
            <?php if ($is_teacher): ?>
                <div class="mt-12 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-32 w-32 mx-auto text-purple-200 mb-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Time to Create Your First Quiz</h3>
                    <p class="text-gray-500 max-w-md mx-auto">Create interactive quizzes to test your students' understanding and track their progress.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($quizzes as $quiz): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden hover-scale feature-card transition-all duration-300 border-l-4 border-purple-500">
                        <div class="p-6">
                            <div class="flex flex-wrap justify-between items-start">
                                <h3 class="text-xl font-bold mb-2 text-purple-800"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                                <div class="flex items-center text-sm text-gray-500">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2h2a2 2 0 002-2z" />
                                    </svg>
                                    <?php echo date('F j, Y', strtotime($quiz['created_at'])); ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($quiz['description'])): ?>
                                <p class="text-gray-600 mb-4 mt-2"><?php echo nl2br(htmlspecialchars($quiz['description'])); ?></p>
                            <?php endif; ?>
                            
                            <?php
                            // Get quiz statistics
                            $quiz_attempts = 0;
                            $quiz_questions = 0;
                            $quiz_attempts_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_attempts'")->num_rows > 0;
                            $quiz_questions_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_questions'")->num_rows > 0;
                            
                            if ($quiz_attempts_table_exists) {
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM quiz_attempts WHERE quiz_id = ?");
                                $stmt->bind_param("i", $quiz['id']);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $quiz_attempts = $result->fetch_assoc()['count'];
                            }
                            
                            if ($quiz_questions_table_exists) {
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM quiz_questions WHERE quiz_id = ?");
                                $stmt->bind_param("i", $quiz['id']);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $quiz_questions = $result->fetch_assoc()['count'];
                            }
                            ?>
                            
                            <div class="flex flex-wrap items-center space-x-4 mb-4">
                                <div class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full text-sm font-medium flex items-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <?php echo $quiz_questions; ?> Questions
                                </div>
                                
                                <?php if ($is_teacher && $quiz_attempts > 0): ?>
                                    <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <?php echo $quiz_attempts; ?> Attempts
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (isset($quiz['time_limit']) && $quiz['time_limit'] > 0): ?>
                                    <div class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-medium flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <?php echo $quiz['time_limit']; ?> min
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex flex-wrap items-center justify-between mt-4">
                                <div class="flex flex-wrap space-x-2 mb-2 md:mb-0">
                                    <a href="view_quiz.php?id=<?php echo $quiz['id']; ?>" class="bg-purple-100 hover:bg-purple-200 text-purple-700 px-4 py-2 rounded-md flex items-center transition-colors duration-300">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        View Details
                                    </a>
                                </div>
                                
                                <?php if ($is_teacher): ?>
                                    <div class="flex flex-wrap space-x-2">
                                        <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md flex items-center transition-colors duration-300 mb-2 md:mb-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            Edit
                                        </a>
                                        <?php if ($quiz_attempts_table_exists): ?>
                                        <a href="quiz_results.php?id=<?php echo $quiz['id']; ?>" class="bg-purple-100 hover:bg-purple-200 text-purple-700 px-4 py-2 rounded-md flex items-center transition-colors duration-300">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2v-14a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                            </svg>
                                            View Results
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php
                                    // Check if student has already taken the quiz
                                    $has_attempted = false;
                                    $attempt = null;
                                    if ($quiz_attempts_table_exists) {
                                        // Fix: Change student_id to user_id to match the quiz_attempts table structure
                                        $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 1");
                                        $stmt->bind_param("ii", $quiz['id'], $user_id);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        $attempt = $result->fetch_assoc();
                                        $has_attempted = $result->num_rows > 0;
                                    }
                                    ?>
                                    
                                    <?php if ($has_attempted && $attempt['status'] === 'submitted'): ?>
                                        <div class="flex items-center">
                                            <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium flex items-center mr-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                Score: <?php echo number_format($attempt['percentage'] ?? 0, 1); ?>%
                                            </span>
                                            <a href="quiz_results.php?attempt_id=<?php echo $attempt['id']; ?>" class="text-purple-600 hover:text-purple-800 text-sm font-medium transition-colors duration-300">
                                                View Results
                                            </a>
                                        </div>
                                    <?php elseif ($has_attempted && $attempt['status'] === 'in_progress'): ?>
                                        <a href="take_quiz.php?id=<?php echo $quiz['id']; ?>" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-md flex items-center transition-colors duration-300 transform hover:scale-[1.02]">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                            Continue Quiz
                                        </a>
                                    <?php else: ?>
                                        <a href="take_quiz.php?id=<?php echo $quiz['id']; ?>" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md flex items-center transition-colors duration-300 transform hover:scale-[1.02]">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                            </svg>
                                            Take Quiz
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-green-500', 'text-green-600', 'border-purple-500', 'text-purple-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab content
    document.getElementById(tabName + '-content').classList.remove('hidden');
    
    // Add active class to selected tab
    const activeTab = document.getElementById(tabName + '-tab');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
    activeTab.classList.add('active');
    
    if (tabName === 'lessons') {
        activeTab.classList.add('border-green-500', 'text-green-600');
    } else if (tabName === 'quizzes') {
        activeTab.classList.add('border-purple-500', 'text-purple-600');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Add smooth hover transitions to cards
    const cards = document.querySelectorAll('.feature-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06)';
        });
    });
    
    // Add smooth transitions to action buttons
    const actionButtons = document.querySelectorAll('a.bg-green-600, a.bg-green-100, a.bg-blue-100, a.bg-gray-100, a.bg-purple-600, a.bg-purple-100');
    actionButtons.forEach(button => {
        button.classList.add('transition-transform', 'duration-300');
        button.addEventListener('mouseenter', function() {
            this.classList.add('transform', 'scale-105');
        });
        
        button.addEventListener('mouseleave', function() {
            this.classList.remove('transform', 'scale-105');
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
