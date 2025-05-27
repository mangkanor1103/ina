<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view quiz results.");
    redirect("login.php");
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Check if we have either quiz_id or attempt_id
$quiz_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : null;

if (!$quiz_id && !$attempt_id) {
    set_flash_message("error", "Invalid quiz or attempt ID.");
    redirect("index.php");
}

// Check if required tables exist
$quiz_attempts_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_attempts'")->num_rows > 0;
$quiz_questions_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_questions'")->num_rows > 0;
$quiz_options_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_options'")->num_rows > 0;

if (!$quiz_attempts_table_exists || !$quiz_questions_table_exists || !$quiz_options_table_exists) {
    set_flash_message("error", "Quiz system is not properly configured.");
    redirect("index.php");
}

// Get quiz information
if ($attempt_id) {
    // Get quiz info from attempt
    $stmt = $conn->prepare("
        SELECT q.*, qa.*, c.name as classroom_name, c.teacher_id, u.username as teacher_name
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        JOIN classrooms c ON q.classroom_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE qa.id = ?
    ");
    $stmt->bind_param("i", $attempt_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        set_flash_message("error", "Quiz attempt not found.");
        redirect("index.php");
    }
    
    $quiz_data = $result->fetch_assoc();
    $quiz_id = $quiz_data['quiz_id'];
    $is_student_view = true;
    
    // Check if student owns this attempt
    if ($user_role === 'student' && $quiz_data['user_id'] != $user_id) {
        set_flash_message("error", "You don't have permission to view this attempt.");
        redirect("index.php");
    }
} else {
    // Get quiz info for teacher view
    $stmt = $conn->prepare("
        SELECT q.*, c.name as classroom_name, c.teacher_id, u.username as teacher_name
        FROM quizzes q
        JOIN classrooms c ON q.classroom_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE q.id = ?
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        set_flash_message("error", "Quiz not found.");
        redirect("index.php");
    }
    
    $quiz_data = $result->fetch_assoc();
    $is_student_view = false;
}

// Check permissions
$has_access = false;
if ($user_role === 'admin') {
    $has_access = true;
} elseif ($user_role === 'teacher' && $quiz_data['teacher_id'] == $user_id) {
    $has_access = true;
} elseif ($user_role === 'student' && $is_student_view) {
    $has_access = true;
}

if (!$has_access) {
    // Add debug information for troubleshooting
    if (isset($_GET['debug'])) {
        echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4'>";
        echo "<strong>Debug Information:</strong><br>";
        echo "User ID: " . $user_id . "<br>";
        echo "User Role: " . $user_role . "<br>";
        echo "Quiz Teacher ID: " . ($quiz_data['teacher_id'] ?? 'Not found') . "<br>";
        echo "Is Student View: " . ($is_student_view ? 'Yes' : 'No') . "<br>";
        echo "</div>";
    }
    
    set_flash_message("error", "You don't have permission to view these results.");
    redirect("index.php");
}

// Get quiz questions with options
$stmt = $conn->prepare("
    SELECT qq.*, 
           GROUP_CONCAT(
               CONCAT(qo.id, ':', qo.option_text, ':', qo.is_correct, ':', qo.order_num) 
               ORDER BY qo.order_num SEPARATOR '||'
           ) as options
    FROM quiz_questions qq
    LEFT JOIN quiz_options qo ON qq.id = qo.question_id
    WHERE qq.quiz_id = ?
    GROUP BY qq.id
    ORDER BY qq.order_num
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$result = $stmt->get_result();
$questions = $result->fetch_all(MYSQLI_ASSOC);

if ($is_student_view) {
    // Student view - show single attempt
    $attempts = [$quiz_data];
    $user_answers = json_decode($quiz_data['answers'] ?? '{}', true) ?: [];
} else {
    // Teacher view - show all attempts AND students who haven't attempted
    $stmt = $conn->prepare("
        SELECT qa.*, u.username, u.username as full_name
        FROM quiz_attempts qa
        JOIN users u ON qa.user_id = u.id
        WHERE qa.quiz_id = ? AND qa.status = 'submitted'
        ORDER BY qa.submitted_at DESC
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempts = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get all students enrolled in this classroom
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email, u.username as full_name
        FROM users u
        JOIN classroom_students cs ON u.id = cs.student_id
        WHERE cs.classroom_id = ? AND u.role = 'student'
        ORDER BY u.username
    ");
    $stmt->bind_param("i", $quiz_data['classroom_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_students = $result->fetch_all(MYSQLI_ASSOC);
    
    // Create a list of students who haven't attempted the quiz
    $attempted_user_ids = array_column($attempts, 'user_id');
    $students_not_attempted = array_filter($all_students, function($student) use ($attempted_user_ids) {
        return !in_array($student['id'], $attempted_user_ids);
    });
    
    $user_answers = [];
}

// Calculate statistics for teacher view
$stats = [
    'total_attempts' => count($attempts),
    'total_students' => count($all_students ?? []),
    'students_not_attempted' => count($students_not_attempted ?? []),
    'average_score' => 0,
    'highest_score' => 0,
    'lowest_score' => 100,
    'pass_rate' => 0
];

if (!empty($attempts)) {
    $total_score = 0;
    $passed = 0;
    
    foreach ($attempts as $attempt) {
        $score = $attempt['percentage'] ?? 0;
        $total_score += $score;
        
        if ($score > $stats['highest_score']) {
            $stats['highest_score'] = $score;
        }
        if ($score < $stats['lowest_score']) {
            $stats['lowest_score'] = $score;
        }
        if ($score >= ($quiz_data['pass_percentage'] ?? 70)) {
            $passed++;
        }
    }
    
    $stats['average_score'] = $total_score / count($attempts);
    $stats['pass_rate'] = (count($attempts) > 0) ? ($passed / count($attempts)) * 100 : 0;
}
?>

<div class="container mx-auto px-4 py-6 fade-in">
    <!-- Back Button -->
    <div class="mb-6 animate__animated animate__fadeInLeft">
        <a href="view_classroom.php?id=<?php echo $quiz_data['classroom_id']; ?>" class="text-purple-600 hover:text-purple-800 hover:underline flex items-center transition-colors duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Classroom
        </a>
    </div>

    <!-- Quiz Header -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden animate__animated animate__fadeIn mb-8">
        <div class="bg-purple-600 p-6 text-white">
            <h1 class="text-3xl font-bold mb-2"><?php echo htmlspecialchars($quiz_data['title']); ?></h1>
            <p class="text-purple-100 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-2m-2 0H7m14 0a2 2 0 002 2H3a2 2 0 002-2m0 0V9a2 2 0 012-2h10a2 2 0 012 2v12" />
                </svg>
                <span class="font-medium">Classroom:</span> <?php echo htmlspecialchars($quiz_data['classroom_name']); ?>
            </p>
        </div>
        
        <div class="p-6">
            <?php if (!empty($quiz_data['description'])): ?>
                <div class="mb-4">
                    <h3 class="font-semibold text-gray-700 mb-2">Description</h3>
                    <p class="text-gray-600"><?php echo nl2br(htmlspecialchars($quiz_data['description'])); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div class="bg-purple-50 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-purple-600"><?php echo count($questions); ?></div>
                    <div class="text-sm text-gray-600">Questions</div>
                </div>
                
                <?php if ($is_student_view): ?>
                    <div class="bg-green-50 p-4 rounded-lg">
                        <div class="text-2xl font-bold text-green-600"><?php echo number_format($quiz_data['percentage'] ?? 0, 1); ?>%</div>
                        <div class="text-sm text-gray-600">Your Score</div>
                    </div>
                    
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600"><?php echo number_format($quiz_data['score'] ?? 0, 1); ?></div>
                        <div class="text-sm text-gray-600">Points Earned</div>
                    </div>
                    
                    <div class="bg-amber-50 p-4 rounded-lg">
                        <div class="text-2xl font-bold text-amber-600">
                            <?php 
                            $time_spent = $quiz_data['time_spent'] ?? 0;
                            echo gmdate("H:i:s", $time_spent);
                            ?>
                        </div>
                        <div class="text-sm text-gray-600">Time Spent</div>
                    </div>
                <?php else: ?>
                    <div class="bg-green-50 p-4 rounded-lg">
                        <div class="text-2xl font-bold text-green-600"><?php echo $stats['total_attempts']; ?></div>
                        <div class="text-sm text-gray-600">Total Attempts</div>
                    </div>
                    
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['average_score'], 1); ?>%</div>
                        <div class="text-sm text-gray-600">Average Score</div>
                    </div>
                    
                    <div class="bg-amber-50 p-4 rounded-lg">
                        <div class="text-2xl font-bold text-amber-600"><?php echo number_format($stats['pass_rate'], 1); ?>%</div>
                        <div class="text-sm text-gray-600">Pass Rate</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($is_student_view): ?>
        <!-- Student Results View -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8 animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
            <h2 class="text-2xl font-bold text-purple-800 mb-6 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Your Answers Review
            </h2>
            
            <div class="space-y-6">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="border border-gray-200 rounded-lg p-6">
                        <div class="flex items-start justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">
                                Question <?php echo $index + 1; ?>
                                <span class="text-sm font-normal text-gray-600">(<?php echo $question['points']; ?> points)</span>
                            </h3>
                        </div>
                        
                        <p class="text-gray-700 mb-4"><?php echo nl2br(htmlspecialchars($question['question_text'])); ?></p>
                        
                        <?php
                        $options = [];
                        if ($question['options']) {
                            foreach (explode('||', $question['options']) as $option_data) {
                                $parts = explode(':', $option_data, 4);
                                if (count($parts) >= 3) {
                                    $options[] = [
                                        'id' => $parts[0],
                                        'text' => $parts[1],
                                        'is_correct' => $parts[2] == 1
                                    ];
                                }
                            }
                        }
                        
                        $user_answer = $user_answers[$question['id']] ?? null;
                        $is_correct = false;
                        ?>
                        
                        <?php if ($question['question_type'] === 'multiple_choice'): ?>
                            <div class="space-y-2">
                                <?php foreach ($options as $option): ?>
                                    <?php
                                    $is_selected = is_array($user_answer) ? in_array($option['id'], $user_answer) : $user_answer == $option['id'];
                                    $option_class = '';
                                    $icon = '';
                                    
                                    if ($option['is_correct']) {
                                        $option_class = 'bg-green-50 border-green-300 text-green-800';
                                        $icon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
                                        if ($is_selected) $is_correct = true;
                                    } elseif ($is_selected) {
                                        $option_class = 'bg-red-50 border-red-300 text-red-800';
                                        $icon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>';
                                    } else {
                                        $option_class = 'bg-gray-50 border-gray-200 text-gray-700';
                                    }
                                    ?>
                                    <div class="p-3 border rounded-md <?php echo $option_class; ?> flex items-center">
                                        <?php echo $icon; ?>
                                        <span><?php echo htmlspecialchars($option['text']); ?></span>
                                        <?php if ($is_selected): ?>
                                            <span class="ml-auto text-sm font-medium">Your Answer</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                        <?php elseif ($question['question_type'] === 'true_false'): ?>
                            <div class="space-y-2">
                                <?php foreach ($options as $option): ?>
                                    <?php
                                    $is_selected = $user_answer == $option['id'];
                                    $option_class = '';
                                    $icon = '';
                                    
                                    if ($option['is_correct']) {
                                        $option_class = 'bg-green-50 border-green-300 text-green-800';
                                        $icon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
                                        if ($is_selected) $is_correct = true;
                                    } elseif ($is_selected) {
                                        $option_class = 'bg-red-50 border-red-300 text-red-800';
                                        $icon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>';
                                    } else {
                                        $option_class = 'bg-gray-50 border-gray-200 text-gray-700';
                                    }
                                    ?>
                                    <div class="p-3 border rounded-md <?php echo $option_class; ?> flex items-center">
                                        <?php echo $icon; ?>
                                        <span><?php echo htmlspecialchars($option['text']); ?></span>
                                        <?php if ($is_selected): ?>
                                            <span class="ml-auto text-sm font-medium">Your Answer</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                        <?php elseif ($question['question_type'] === 'short_answer'): ?>
                            <div class="space-y-3">
                                <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                                    <div class="text-sm font-medium text-blue-800 mb-1">Your Answer:</div>
                                    <div class="text-blue-700"><?php echo $user_answer ? htmlspecialchars($user_answer) : '<em>No answer provided</em>'; ?></div>
                                </div>
                                <div class="text-sm text-amber-600 flex items-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    This question requires manual grading by the teacher.
                                </div>
                            </div>
                            
                        <?php elseif ($question['question_type'] === 'essay'): ?>
                            <div class="space-y-3">
                                <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                                    <div class="text-sm font-medium text-blue-800 mb-1">Your Answer:</div>
                                    <div class="text-blue-700 whitespace-pre-wrap"><?php echo $user_answer ? htmlspecialchars($user_answer) : '<em>No answer provided</em>'; ?></div>
                                </div>
                                <div class="text-sm text-amber-600 flex items-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    This question requires manual grading by the teacher.
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Result indicator -->
                        <div class="mt-4 flex items-center">
                            <?php if (in_array($question['question_type'], ['multiple_choice', 'true_false'])): ?>
                                <?php if ($is_correct): ?>
                                    <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Correct (+<?php echo $question['points']; ?> points)
                                    </span>
                                <?php else: ?>
                                    <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-medium flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        Incorrect (0 points)
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full text-sm font-medium flex items-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Pending Review
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Final Score Summary -->
            <div class="mt-8 bg-purple-50 border border-purple-200 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-purple-800 mb-4">Final Results</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-purple-600"><?php echo number_format($quiz_data['score'] ?? 0, 1); ?></div>
                        <div class="text-sm text-gray-600">Points Earned</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-purple-600"><?php echo number_format($quiz_data['percentage'] ?? 0, 1); ?>%</div>
                        <div class="text-sm text-gray-600">Percentage</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold <?php echo ($quiz_data['percentage'] ?? 0) >= ($quiz_data['pass_percentage'] ?? 70) ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo ($quiz_data['percentage'] ?? 0) >= ($quiz_data['pass_percentage'] ?? 70) ? 'PASS' : 'FAIL'; ?>
                        </div>
                        <div class="text-sm text-gray-600">Result</div>
                    </div>
                </div>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Teacher Results View -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8 animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
            <h2 class="text-2xl font-bold text-purple-800 mb-6 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2v-14a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                Quiz Results Overview
            </h2>
            
            <!-- Enhanced Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                    <div class="text-2xl font-bold text-blue-600"><?php echo $stats['total_students']; ?></div>
                    <div class="text-sm text-gray-600">Total Students</div>
                </div>
                <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                    <div class="text-2xl font-bold text-green-600"><?php echo $stats['total_attempts']; ?></div>
                    <div class="text-sm text-gray-600">Completed</div>
                </div>
                <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                    <div class="text-2xl font-bold text-red-600"><?php echo $stats['students_not_attempted']; ?></div>
                    <div class="text-sm text-gray-600">Not Attempted</div>
                </div>
                <div class="bg-amber-50 p-4 rounded-lg border border-amber-200">
                    <div class="text-2xl font-bold text-amber-600"><?php echo number_format($stats['average_score'], 1); ?>%</div>
                    <div class="text-sm text-gray-600">Average Score</div>
                </div>
                <div class="bg-purple-50 p-4 rounded-lg border border-purple-200">
                    <div class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['pass_rate'], 1); ?>%</div>
                    <div class="text-sm text-gray-600">Pass Rate</div>
                </div>
            </div>
            
            <!-- Tab Navigation -->
            <div class="mb-6">
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                        <button onclick="showResultsTab('completed')" id="completed-tab" class="results-tab-button active border-green-500 text-green-600 py-2 px-1 border-b-2 font-medium text-sm">
                            Completed (<?php echo count($attempts); ?>)
                        </button>
                        <button onclick="showResultsTab('not-attempted')" id="not-attempted-tab" class="results-tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 py-2 px-1 border-b-2 font-medium text-sm">
                            Not Attempted (<?php echo count($students_not_attempted); ?>)
                        </button>
                    </nav>
                </div>
            </div>
            
            <!-- Completed Attempts Tab -->
            <div id="completed-content" class="results-tab-content">
                <?php if (empty($attempts)): ?>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-8 text-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">No Completed Attempts</h3>
                        <p class="text-gray-500">No students have completed this quiz yet.</p>
                    </div>
                <?php else: ?>
                    <!-- Results Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time Spent</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($attempts as $attempt): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($attempt['username']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">@<?php echo htmlspecialchars($attempt['username']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo number_format($attempt['score'] ?? 0, 1); ?> pts
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo ($attempt['percentage'] ?? 0) >= ($quiz_data['pass_percentage'] ?? 70) ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo number_format($attempt['percentage'] ?? 0, 1); ?>%
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo gmdate("H:i:s", $attempt['time_spent'] ?? 0); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M d, Y g:i A', strtotime($attempt['submitted_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo ($attempt['percentage'] ?? 0) >= ($quiz_data['pass_percentage'] ?? 70) ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ($attempt['percentage'] ?? 0) >= ($quiz_data['pass_percentage'] ?? 70) ? 'Passed' : 'Failed'; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="quiz_results.php?attempt_id=<?php echo $attempt['id']; ?>" class="text-purple-600 hover:text-purple-900 transition-colors duration-200">
                                                View Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Not Attempted Tab -->
            <div id="not-attempted-content" class="results-tab-content hidden">
                <?php if (empty($students_not_attempted)): ?>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-8 text-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-green-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">Great Job!</h3>
                        <p class="text-gray-500">All enrolled students have attempted this quiz.</p>
                    </div>
                <?php else: ?>
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">
                                    <?php echo count($students_not_attempted); ?> student<?php echo count($students_not_attempted) > 1 ? 's' : ''; ?> haven't attempted this quiz yet
                                </h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <p>Consider sending reminders to these students or checking if they need additional support.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($students_not_attempted as $student): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($student['username']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">@<?php echo htmlspecialchars($student['username']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($student['email'] ?? 'No email'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                Not Attempted
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button onclick="sendReminder(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['username']); ?>')" class="text-blue-600 hover:text-blue-900 transition-colors duration-200 mr-3">
                                                Send Reminder
                                            </button>
                                            <?php if (!empty($student['email'])): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>?subject=Quiz Reminder: <?php echo urlencode($quiz_data['title']); ?>" class="text-green-600 hover:text-green-900 transition-colors duration-200">
                                                    Email
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Options -->
            <div class="mt-6 flex flex-wrap gap-2">
                <button onclick="exportResults('csv')" class="bg-green-100 hover:bg-green-200 text-green-800 px-4 py-2 rounded-md transition-colors duration-300 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Export Results
                </button>
                <button onclick="window.print()" class="bg-blue-100 hover:bg-blue-200 text-blue-800 px-4 py-2 rounded-md transition-colors duration-300 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    Print Results
                </button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function showResultsTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.results-tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.results-tab-button').forEach(button => {
        button.classList.remove('active', 'border-green-500', 'text-green-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab content
    document.getElementById(tabName + '-content').classList.remove('hidden');
    
    // Add active class to selected tab
    const activeTab = document.getElementById(tabName + '-tab');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
    activeTab.classList.add('active', 'border-green-500', 'text-green-600');
}

function sendReminder(studentId, studentUsername) {
    if (confirm(`Send quiz reminder to ${studentUsername}?`)) {
        // Here you would implement the reminder functionality
        // For now, just show a success message
        alert(`Reminder sent to ${studentUsername}!`);
        
        // You could implement an AJAX call here to actually send the reminder
        // fetch('send_reminder.php', {
        //     method: 'POST',
        //     headers: { 'Content-Type': 'application/json' },
        //     body: JSON.stringify({ studentId: studentId, quizId: <?php echo $quiz_id; ?> })
        // });
    }
}

function exportResults(format) {
    if (format === 'csv') {
        // Create CSV content
        let csv = 'Student,Username,Email,Score,Percentage,Time Spent,Submitted,Status\n';
        
        <?php if (!$is_student_view): ?>
        // Add completed attempts
        <?php foreach ($attempts as $attempt): ?>
        csv += '<?php echo addslashes($attempt['username']); ?>,';
        csv += '<?php echo addslashes($attempt['username']); ?>,';
        csv += 'N/A,';
        csv += '<?php echo $attempt['score'] ?? 0; ?>,';
        csv += '<?php echo $attempt['percentage'] ?? 0; ?>,';
        csv += '<?php echo gmdate("H:i:s", $attempt['time_spent'] ?? 0); ?>,';
        csv += '<?php echo date('Y-m-d H:i:s', strtotime($attempt['submitted_at'])); ?>,';
        csv += '<?php echo ($attempt['percentage'] ?? 0) >= ($quiz_data['pass_percentage'] ?? 70) ? 'Passed' : 'Failed'; ?>\n';
        <?php endforeach; ?>
        
        // Add not attempted students
        <?php foreach ($students_not_attempted as $student): ?>
        csv += '<?php echo addslashes($student['username']); ?>,';
        csv += '<?php echo addslashes($student['username']); ?>,';
        csv += '<?php echo addslashes($student['email'] ?? ''); ?>,';
        csv += 'N/A,N/A,N/A,N/A,Not Attempted\n';
        <?php endforeach; ?>
        <?php endif; ?>
        
        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.setAttribute('hidden', '');
        a.setAttribute('href', url);
        a.setAttribute('download', 'quiz-results-<?php echo $quiz_id; ?>.csv');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
}

// Initialize with completed tab
document.addEventListener('DOMContentLoaded', function() {
    showResultsTab('completed');
});
</script>

<style>
@media print {
    .no-print {
        display: none !important;
    }
    
    .container {
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    .shadow-md {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>