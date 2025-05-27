<?php
// filepath: c:\xampp\htdocs\ina\take_quiz.php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to take the quiz.");
    redirect("login.php");
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get quiz ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    set_flash_message("error", "Invalid quiz ID.");
    redirect("index.php");
}

$quiz_id = (int)$_GET['id'];

// Check if required tables exist
$quizzes_table_exists = $conn->query("SHOW TABLES LIKE 'quizzes'")->num_rows > 0;
$quiz_questions_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_questions'")->num_rows > 0;
$quiz_options_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_options'")->num_rows > 0;

if (!$quizzes_table_exists || !$quiz_questions_table_exists || !$quiz_options_table_exists) {
    set_flash_message("error", "Quiz system is not properly configured.");
    redirect("index.php");
}

// Create quiz_attempts table if it doesn't exist
$quiz_attempts_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_attempts'")->num_rows > 0;
if (!$quiz_attempts_table_exists) {
    $create_quiz_attempts_sql = "
        CREATE TABLE quiz_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT NOT NULL,
            user_id INT NOT NULL,
            attempt_number INT DEFAULT 1,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            submitted_at TIMESTAMP NULL,
            time_spent INT DEFAULT 0 COMMENT 'Time spent in seconds',
            score DECIMAL(5,2) DEFAULT 0.00,
            percentage DECIMAL(5,2) DEFAULT 0.00,
            status ENUM('in_progress', 'submitted', 'auto_submitted', 'expired') DEFAULT 'in_progress',
            answers JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_attempt (quiz_id, user_id, attempt_number)
        )
    ";
    $conn->query($create_quiz_attempts_sql);
}

// Get quiz information with classroom and teacher details
$stmt = $conn->prepare("
    SELECT q.*, 
           COALESCE(c.name, 'Unknown Classroom') as classroom_name, 
           COALESCE(c.id, 0) as classroom_id, 
           COALESCE(c.teacher_id, 0) as teacher_id
    FROM quizzes q
    LEFT JOIN classrooms c ON q.classroom_id = c.id
    WHERE q.id = ?
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flash_message("error", "Quiz not found.");
    redirect("index.php");
}

$quiz = $result->fetch_assoc();

// Add debug information to see the quiz status
if (isset($_GET['debug'])) {
    echo "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4'>";
    echo "<strong>Debug Information:</strong><br>";
    echo "Quiz ID: " . $quiz_id . "<br>";
    echo "Quiz Status: " . $quiz['status'] . "<br>";
    echo "Quiz Title: " . $quiz['title'] . "<br>";
    echo "Expected Status: 'published'<br>";
    echo "</div>";
}

// Check if quiz is available - be more flexible with status check
$valid_statuses = ['published', 'active', 'open'];
if (!in_array($quiz['status'], $valid_statuses)) {
    // Show more detailed error message
    $status_message = "This quiz is not currently available. Current status: " . $quiz['status'];
    if ($user_role === 'admin' || $user_role === 'teacher') {
        $status_message .= " (Only published quizzes can be taken by students)";
    }
    set_flash_message("error", $status_message);
    redirect("index.php");
}

$classroom_id = $quiz['classroom_id'];

// Check if user has access to this classroom
$has_access = false;
if ($user_role === 'admin') {
    $has_access = true;
} elseif ($user_role === 'teacher' && $quiz['teacher_id'] == $user_id) {
    $has_access = true;
} elseif ($user_role === 'student') {
    // Check if student is enrolled in classroom
    $classroom_students_table_exists = $conn->query("SHOW TABLES LIKE 'classroom_students'")->num_rows > 0;
    if ($classroom_students_table_exists) {
        $stmt = $conn->prepare("SELECT * FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
        $stmt->bind_param("ii", $classroom_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $has_access = $result->num_rows > 0;
    } else {
        // If no enrollment table exists, allow all students to access
        $has_access = true;
    }
}

if (!$has_access) {
    set_flash_message("error", "You don't have permission to take this quiz.");
    redirect("view_classroom.php?id=" . $classroom_id);
}

// Get user's previous attempts
$stmt = $conn->prepare("
    SELECT * FROM quiz_attempts 
    WHERE quiz_id = ? AND user_id = ? 
    ORDER BY attempt_number DESC
");
$stmt->bind_param("ii", $quiz_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$attempts = $result->fetch_all(MYSQLI_ASSOC);

$current_attempt = null;
$completed_attempts = 0;
$best_score = 0;

foreach ($attempts as $attempt) {
    if ($attempt['status'] === 'in_progress') {
        $current_attempt = $attempt;
    } else {
        $completed_attempts++;
        if ($attempt['percentage'] > $best_score) {
            $best_score = $attempt['percentage'];
        }
    }
}

// Check if user can start/continue quiz
$can_take_quiz = true;
$message = "";

if ($completed_attempts >= $quiz['attempts_allowed'] && !$current_attempt) {
    $can_take_quiz = false;
    $message = "You have used all allowed attempts for this quiz.";
}

// Handle quiz submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $answers = $_POST['answers'] ?? [];
    $attempt_id = (int)$_POST['attempt_id'];
    
    try {
        $conn->begin_transaction();
        
        // Calculate score
        $total_points = 0;
        $earned_points = 0;
        
        // Get all questions with correct answers
        $stmt = $conn->prepare("
            SELECT qq.*, 
                   GROUP_CONCAT(
                       CONCAT(qo.id, ':', qo.option_text, ':', qo.is_correct) 
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
        
        foreach ($questions as $question) {
            $total_points += $question['points'];
            $question_id = $question['id'];
            $user_answer = $answers[$question_id] ?? null;
            
            if ($question['question_type'] === 'multiple_choice') {
                $options = [];
                if ($question['options']) {
                    foreach (explode('||', $question['options']) as $option_data) {
                        $parts = explode(':', $option_data);
                        if (count($parts) >= 3) {
                            $options[] = [
                                'id' => $parts[0],
                                'text' => $parts[1],
                                'is_correct' => $parts[2]
                            ];
                        }
                    }
                }
                
                $correct_options = array_filter($options, function($opt) {
                    return $opt['is_correct'] == 1;
                });
                
                if (is_array($user_answer)) {
                    $correct_count = 0;
                    $total_correct = count($correct_options);
                    
                    foreach ($user_answer as $selected_option_id) {
                        foreach ($correct_options as $correct_option) {
                            if ($correct_option['id'] == $selected_option_id) {
                                $correct_count++;
                                break;
                            }
                        }
                    }
                    
                    // Award points based on percentage of correct answers
                    if ($total_correct > 0 && count($user_answer) == $total_correct && $correct_count == $total_correct) {
                        $earned_points += $question['points'];
                    }
                }
            } elseif ($question['question_type'] === 'true_false') {
                $options = [];
                if ($question['options']) {
                    foreach (explode('||', $question['options']) as $option_data) {
                        $parts = explode(':', $option_data);
                        if (count($parts) >= 3) {
                            $options[] = [
                                'id' => $parts[0],
                                'text' => $parts[1],
                                'is_correct' => $parts[2]
                            ];
                        }
                    }
                }
                
                foreach ($options as $option) {
                    if ($option['is_correct'] == 1 && $user_answer == $option['id']) {
                        $earned_points += $question['points'];
                        break;
                    }
                }
            }
            // Short answer and essay questions need manual grading
        }
        
        $percentage = $total_points > 0 ? ($earned_points / $total_points) * 100 : 0;
        
        // Calculate time spent
        $stmt = $conn->prepare("SELECT started_at FROM quiz_attempts WHERE id = ?");
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attempt_data = $result->fetch_assoc();
        
        $time_spent = time() - strtotime($attempt_data['started_at']);
        
        // Update attempt record
        $stmt = $conn->prepare("
            UPDATE quiz_attempts 
            SET submitted_at = NOW(), 
                time_spent = ?, 
                score = ?, 
                percentage = ?, 
                status = 'submitted',
                answers = ?
            WHERE id = ?
        ");
        $answers_json = json_encode($answers);
        $stmt->bind_param("iddsi", $time_spent, $earned_points, $percentage, $answers_json, $attempt_id);
        $stmt->execute();
        
        $conn->commit();
        
        // Log activity if table exists
        $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
        if ($activity_table_exists) {
            $activity_description = "Completed quiz: " . $quiz['title'] . " (Score: " . number_format($percentage, 1) . "%)";
            $stmt = $conn->prepare("
                INSERT INTO activity_log (user_id, classroom_id, activity_type, description, created_at)
                VALUES (?, ?, 'quiz_completed', ?, NOW())
            ");
            $stmt->bind_param("iis", $user_id, $classroom_id, $activity_description);
            $stmt->execute();
        }
        
        $status = $percentage >= $quiz['pass_percentage'] ? 'passed' : 'failed';
        set_flash_message("success", "Quiz submitted successfully! You scored " . number_format($percentage, 1) . "% and " . $status . ".");
        redirect("quiz_results.php?attempt_id=" . $attempt_id);
        
    } catch (Exception $e) {
        $conn->rollback();
        set_flash_message("error", "Error submitting quiz: " . $e->getMessage());
    }
}

// Start new attempt or get current attempt
if ($can_take_quiz && !$current_attempt && ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_quiz']))) {
    try {
        $attempt_number = $completed_attempts + 1;
        $stmt = $conn->prepare("
            INSERT INTO quiz_attempts (quiz_id, user_id, attempt_number, started_at, status)
            VALUES (?, ?, ?, NOW(), 'in_progress')
        ");
        $stmt->bind_param("iii", $quiz_id, $user_id, $attempt_number);
        $stmt->execute();
        
        $current_attempt = [
            'id' => $conn->insert_id,
            'quiz_id' => $quiz_id,
            'user_id' => $user_id,
            'attempt_number' => $attempt_number,
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'in_progress'
        ];
        
    } catch (Exception $e) {
        set_flash_message("error", "Error starting quiz: " . $e->getMessage());
        redirect("view_classroom.php?id=" . $classroom_id);
    }
}

// Get quiz questions if taking quiz
$questions = [];
if ($current_attempt || $can_take_quiz) {
    $order_clause = $quiz['shuffle_questions'] ? 'RAND()' : 'qq.order_num';
    
    $stmt = $conn->prepare("
        SELECT qq.*, 
               GROUP_CONCAT(
                   CONCAT(qo.id, ':', qo.option_text, ':', qo.is_correct) 
                   ORDER BY qo.order_num SEPARATOR '||'
               ) as options
        FROM quiz_questions qq
        LEFT JOIN quiz_options qo ON qq.id = qo.question_id
        WHERE qq.quiz_id = ?
        GROUP BY qq.id
        ORDER BY {$order_clause}
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $questions = $result->fetch_all(MYSQLI_ASSOC);
    
    // Process options
    foreach ($questions as &$question) {
        $question['parsed_options'] = [];
        if ($question['options']) {
            foreach (explode('||', $question['options']) as $option_data) {
                $parts = explode(':', $option_data);
                if (count($parts) >= 3) {
                    $question['parsed_options'][] = [
                        'id' => $parts[0],
                        'text' => $parts[1],
                        'is_correct' => $parts[2]
                    ];
                }
            }
        }
    }
}

// Calculate time remaining
$time_remaining = null;
$time_expired = false;
if ($current_attempt && $quiz['time_limit']) {
    $started_time = strtotime($current_attempt['started_at']);
    $time_limit_seconds = $quiz['time_limit'] * 60;
    $elapsed_time = time() - $started_time;
    $time_remaining = $time_limit_seconds - $elapsed_time;
    
    if ($time_remaining <= 0) {
        $time_expired = true;
        // Auto-submit the quiz
        // This would be handled by JavaScript
    }
}

// Debug information for troubleshooting
if (isset($_GET['debug']) && $user_role === 'admin') {
    echo "<pre>";
    echo "User ID: " . $user_id . "\n";
    echo "User Role: " . $user_role . "\n";
    echo "Quiz ID: " . $quiz_id . "\n";
    echo "Classroom ID: " . $classroom_id . "\n";
    echo "Has Access: " . ($has_access ? 'Yes' : 'No') . "\n";
    echo "Can Take Quiz: " . ($can_take_quiz ? 'Yes' : 'No') . "\n";
    echo "Classroom Students Table Exists: " . ($classroom_students_table_exists ? 'Yes' : 'No') . "\n";
    echo "Questions Count: " . count($questions) . "\n";
    var_dump($quiz);
    echo "</pre>";
}
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <!-- Header -->
    <div class="mb-8 animate__animated animate__fadeInLeft">
        <div class="flex items-center mb-4">
            <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" class="text-purple-600 hover:text-purple-800 mr-4 transition-colors duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <h1 class="text-3xl font-bold text-purple-800 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-3 text-purple-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                <?php echo htmlspecialchars($quiz['title']); ?>
            </h1>
        </div>
        <p class="text-gray-600 ml-10">
            <span class="font-medium"><?php echo htmlspecialchars($quiz['classroom_name']); ?></span>
            <?php if ($quiz['description']): ?>
                • <?php echo htmlspecialchars($quiz['description']); ?>
            <?php endif; ?>
        </p>
    </div>

    <?php if (!$current_attempt): ?>
        <!-- Quiz Information & Start -->
        <div class="bg-white rounded-lg shadow-md p-8 animate__animated animate__fadeIn">
            <div class="text-center mb-8">
                <div class="w-24 h-24 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($quiz['title']); ?></h2>
                <?php if ($quiz['description']): ?>
                    <p class="text-gray-600 mb-6"><?php echo htmlspecialchars($quiz['description']); ?></p>
                <?php endif; ?>
            </div>

            <!-- Quiz Details -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-purple-600 mb-1"><?php echo count($questions); ?></div>
                    <div class="text-sm text-gray-600">Questions</div>
                </div>
                
                <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600 mb-1">
                        <?php echo $quiz['time_limit'] ? $quiz['time_limit'] . 'min' : '∞'; ?>
                    </div>
                    <div class="text-sm text-gray-600">Time Limit</div>
                </div>
                
                <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-green-600 mb-1"><?php echo $quiz['attempts_allowed']; ?></div>
                    <div class="text-sm text-gray-600">Attempts Allowed</div>
                </div>
                
                <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-orange-600 mb-1"><?php echo number_format($quiz['pass_percentage'], 0); ?>%</div>
                    <div class="text-sm text-gray-600">Pass Percentage</div>
                </div>
            </div>

            <!-- Attempt History -->
            <?php if (!empty($attempts) && $completed_attempts > 0): ?>
                <div class="mb-8">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Your Previous Attempts</h3>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="text-center">
                                <div class="text-xl font-bold text-gray-900"><?php echo $completed_attempts; ?></div>
                                <div class="text-sm text-gray-600">Completed Attempts</div>
                            </div>
                            <div class="text-center">
                                <div class="text-xl font-bold text-green-600"><?php echo number_format($best_score, 1); ?>%</div>
                                <div class="text-sm text-gray-600">Best Score</div>
                            </div>
                            <div class="text-center">
                                <div class="text-xl font-bold text-blue-600"><?php echo $quiz['attempts_allowed'] - $completed_attempts; ?></div>
                                <div class="text-sm text-gray-600">Remaining Attempts</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Instructions -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-8">
                <h3 class="text-lg font-medium text-blue-900 mb-4 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Instructions
                </h3>
                <ul class="list-disc list-inside text-blue-800 space-y-2">
                    <li>Read each question carefully before answering</li>
                    <li>You can change your answers before submitting</li>
                    <?php if ($quiz['time_limit']): ?>
                        <li class="font-medium">This quiz has a time limit of <?php echo $quiz['time_limit']; ?> minutes</li>
                    <?php endif; ?>
                    <li>Make sure to submit your quiz before the time runs out</li>
                    <li>You have <?php echo $quiz['attempts_allowed']; ?> attempt(s) for this quiz</li>
                    <?php if (!$quiz['show_results']): ?>
                        <li>Results will not be shown immediately after submission</li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Start Quiz Button -->
            <?php if ($can_take_quiz): ?>
                <form method="POST" class="text-center">
                    <button type="submit" name="start_quiz" 
                        class="bg-purple-600 hover:bg-purple-700 text-white px-8 py-4 rounded-lg text-lg font-medium transition-all duration-300 transform hover:scale-105">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Start Quiz
                    </button>
                </form>
            <?php else: ?>
                <div class="text-center p-6 bg-red-50 border border-red-200 rounded-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-red-500 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <p class="text-red-800 font-medium"><?php echo $message; ?></p>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- Taking Quiz -->
        <form method="POST" id="quizForm">
            <input type="hidden" name="attempt_id" value="<?php echo $current_attempt['id']; ?>">
            
            <!-- Timer and Progress -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6 sticky top-4 z-10">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-6">
                        <div class="text-sm text-gray-600">
                            Attempt <?php echo $current_attempt['attempt_number']; ?> of <?php echo $quiz['attempts_allowed']; ?>
                        </div>
                        <?php if ($quiz['time_limit']): ?>
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span id="timer" class="font-medium text-orange-600">
                                    <?php echo $quiz['time_limit']; ?>:00
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <div class="text-sm text-gray-600">
                            Progress: <span id="progress">0</span>/<?php echo count($questions); ?>
                        </div>
                        <button type="submit" name="submit_quiz" 
                            class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md transition-colors duration-300">
                            Submit Quiz
                        </button>
                    </div>
                </div>
            </div>

            <!-- Questions -->
            <div class="space-y-8">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 question-card" data-question="<?php echo $index + 1; ?>">
                        <div class="mb-4">
                            <div class="flex justify-between items-start mb-3">
                                <h3 class="text-lg font-medium text-gray-900">
                                    Question <?php echo $index + 1; ?>
                                    <span class="text-sm font-normal text-gray-500 ml-2">
                                        (<?php echo $question['points']; ?> point<?php echo $question['points'] != 1 ? 's' : ''; ?>)
                                    </span>
                                </h3>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                    <?php echo $question['question_type'] === 'multiple_choice' ? 'bg-blue-100 text-blue-800' : 
                                               ($question['question_type'] === 'true_false' ? 'bg-green-100 text-green-800' : 
                                               ($question['question_type'] === 'short_answer' ? 'bg-yellow-100 text-yellow-800' : 'bg-purple-100 text-purple-800')); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?>
                                </span>
                            </div>
                            <p class="text-gray-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($question['question_text'])); ?></p>
                        </div>

                        <!-- Answer Options -->
                        <div class="space-y-3">
                            <?php if ($question['question_type'] === 'multiple_choice'): ?>
                                <?php foreach ($question['parsed_options'] as $option): ?>
                                    <label class="flex items-start space-x-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer transition-colors duration-200">
                                        <input type="checkbox" name="answers[<?php echo $question['id']; ?>][]" 
                                               value="<?php echo $option['id']; ?>"
                                               class="mt-1 h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
                                        <span class="text-gray-700 flex-1"><?php echo htmlspecialchars($option['text']); ?></span>
                                    </label>
                                <?php endforeach; ?>

                            <?php elseif ($question['question_type'] === 'true_false'): ?>
                                <?php foreach ($question['parsed_options'] as $option): ?>
                                    <label class="flex items-center space-x-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer transition-colors duration-200">
                                        <input type="radio" name="answers[<?php echo $question['id']; ?>]" 
                                               value="<?php echo $option['id']; ?>"
                                               class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300">
                                        <span class="text-gray-700 flex-1"><?php echo htmlspecialchars($option['text']); ?></span>
                                    </label>
                                <?php endforeach; ?>

                            <?php elseif ($question['question_type'] === 'short_answer'): ?>
                                <input type="text" name="answers[<?php echo $question['id']; ?>]"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500"
                                       placeholder="Enter your answer...">

                            <?php elseif ($question['question_type'] === 'essay'): ?>
                                <textarea name="answers[<?php echo $question['id']; ?>]" rows="6"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500"
                                          placeholder="Write your essay answer here..."></textarea>
                            <?php endif; ?>
                        </div>

                        <?php if ($question['explanation'] && $user_role === 'admin'): ?>
                            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-sm text-blue-800">
                                    <strong>Explanation:</strong> <?php echo htmlspecialchars($question['explanation']); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Submit Button -->
            <div class="mt-8 text-center">
                <button type="submit" name="submit_quiz" 
                    class="bg-green-600 hover:bg-green-700 text-white px-8 py-4 rounded-lg text-lg font-medium transition-all duration-300 transform hover:scale-105">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 inline-block mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Submit Quiz
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($current_attempt): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Timer functionality
    <?php if ($quiz['time_limit'] && $time_remaining): ?>
    let timeRemaining = <?php echo max(0, $time_remaining); ?>;
    const timerElement = document.getElementById('timer');
    
    function updateTimer() {
        if (timeRemaining <= 0) {
            // Auto-submit the quiz
            document.getElementById('quizForm').submit();
            return;
        }
        
        const minutes = Math.floor(timeRemaining / 60);
        const seconds = timeRemaining % 60;
        timerElement.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        
        // Warning colors
        if (timeRemaining <= 300) { // 5 minutes
            timerElement.className = 'font-medium text-red-600';
        } else if (timeRemaining <= 600) { // 10 minutes
            timerElement.className = 'font-medium text-orange-600';
        }
        
        timeRemaining--;
    }
    
    updateTimer();
    const timerInterval = setInterval(updateTimer, 1000);
    <?php endif; ?>
    
    // Progress tracking
    function updateProgress() {
        const questions = document.querySelectorAll('.question-card');
        let answered = 0;
        
        questions.forEach(question => {
            const inputs = question.querySelectorAll('input, textarea');
            let hasAnswer = false;
            
            inputs.forEach(input => {
                if (input.type === 'checkbox' || input.type === 'radio') {
                    if (input.checked) hasAnswer = true;
                } else {
                    if (input.value.trim()) hasAnswer = true;
                }
            });
            
            if (hasAnswer) answered++;
        });
        
        document.getElementById('progress').textContent = answered;
        
        // Visual feedback
        const progressPercentage = (answered / questions.length) * 100;
        document.getElementById('progress').style.color = progressPercentage === 100 ? '#059669' : '#D97706';
    }
    
    // Add change listeners to all inputs
    document.querySelectorAll('input, textarea').forEach(input => {
        input.addEventListener('change', updateProgress);
        input.addEventListener('input', updateProgress);
    });
    
    // Initial progress check
    updateProgress();
    
    // Auto-save functionality (optional)
    let saveTimeout;
    function autoSave() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(() => {
            // Implement auto-save logic here if needed
            console.log('Auto-saving answers...');
        }, 30000); // Save every 30 seconds
    }
    
    document.querySelectorAll('input, textarea').forEach(input => {
        input.addEventListener('change', autoSave);
    });
    
    // Form submission confirmation
    document.getElementById('quizForm').addEventListener('submit', function(e) {
        if (e.submitter && e.submitter.name === 'submit_quiz') {
            if (!confirm('Are you sure you want to submit your quiz? This action cannot be undone.')) {
                e.preventDefault();
            }
        }
    });
    
    // Prevent accidental page refresh
    window.addEventListener('beforeunload', function(e) {
        e.preventDefault();
        e.returnValue = 'You have an active quiz. Are you sure you want to leave?';
    });
    
    // Remove beforeunload listener when form is submitted
    document.getElementById('quizForm').addEventListener('submit', function() {
        window.removeEventListener('beforeunload', function() {});
    });
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>