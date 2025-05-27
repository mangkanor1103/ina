<?php
// filepath: c:\xampp\htdocs\ina\create_quiz.php
include 'includes/header.php';

// Check if user is logged in and has permission to create quizzes
if (!is_logged_in()) {
    set_flash_message("error", "Please login to access this page.");
    redirect("login.php");
}

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get classroom ID from URL
if (!isset($_GET['classroom_id']) || !is_numeric($_GET['classroom_id'])) {
    set_flash_message("error", "Invalid classroom ID.");
    redirect("index.php");
}

$classroom_id = (int)$_GET['classroom_id'];

// Verify classroom exists and user has permission
$stmt = $conn->prepare("SELECT * FROM classrooms WHERE id = ?");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flash_message("error", "Classroom not found.");
    redirect("index.php");
}

$classroom = $result->fetch_assoc();

// Check permissions
$has_permission = false;
if ($user_role === 'admin') {
    $has_permission = true;
} elseif ($user_role === 'teacher' && $classroom['teacher_id'] == $user_id) {
    $has_permission = true;
}

if (!$has_permission) {
    set_flash_message("error", "You don't have permission to create quizzes in this classroom.");
    redirect("view_classroom.php?id=" . $classroom_id);
}

// Check if quizzes table exists, if not create it
$quizzes_table_exists = $conn->query("SHOW TABLES LIKE 'quizzes'")->num_rows > 0;
if (!$quizzes_table_exists) {
    $create_quizzes_sql = "
        CREATE TABLE quizzes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            classroom_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            time_limit INT DEFAULT NULL COMMENT 'Time limit in minutes',
            attempts_allowed INT DEFAULT 1,
            shuffle_questions BOOLEAN DEFAULT FALSE,
            show_results BOOLEAN DEFAULT TRUE,
            pass_percentage DECIMAL(5,2) DEFAULT 60.00,
            status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
        )
    ";
    $conn->query($create_quizzes_sql);
}

// Check if quiz_questions table exists, if not create it
$quiz_questions_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_questions'")->num_rows > 0;
if (!$quiz_questions_table_exists) {
    $create_quiz_questions_sql = "
        CREATE TABLE quiz_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT NOT NULL,
            question_text TEXT NOT NULL,
            question_type ENUM('multiple_choice', 'true_false', 'short_answer', 'essay') DEFAULT 'multiple_choice',
            points DECIMAL(5,2) DEFAULT 1.00,
            order_num INT DEFAULT 0,
            explanation TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        )
    ";
    $conn->query($create_quiz_questions_sql);
}

// Check if quiz_options table exists, if not create it
$quiz_options_table_exists = $conn->query("SHOW TABLES LIKE 'quiz_options'")->num_rows > 0;
if (!$quiz_options_table_exists) {
    $create_quiz_options_sql = "
        CREATE TABLE quiz_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question_id INT NOT NULL,
            option_text TEXT NOT NULL,
            is_correct BOOLEAN DEFAULT FALSE,
            order_num INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
        )
    ";
    $conn->query($create_quiz_options_sql);
}

$errors = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = clean_input($_POST['title']);
    $description = clean_input($_POST['description']);
    $time_limit = !empty($_POST['time_limit']) ? (int)$_POST['time_limit'] : null;
    $attempts_allowed = (int)($_POST['attempts_allowed'] ?? 1);
    $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
    $show_results = isset($_POST['show_results']) ? 1 : 0;
    $pass_percentage = (float)($_POST['pass_percentage'] ?? 60.00);
    $status = $_POST['status'] ?? 'draft';
    $questions = $_POST['questions'] ?? [];

    // Validate input
    if (empty($title)) {
        $errors[] = "Quiz title is required.";
    }

    if (empty($questions)) {
        $errors[] = "At least one question is required.";
    }

    if ($attempts_allowed < 1 || $attempts_allowed > 10) {
        $errors[] = "Attempts allowed must be between 1 and 10.";
    }

    if ($pass_percentage < 0 || $pass_percentage > 100) {
        $errors[] = "Pass percentage must be between 0 and 100.";
    }

    // Validate questions
    if (!empty($questions)) {
        foreach ($questions as $index => $question) {
            $question_num = $index + 1;
            
            if (empty($question['text'])) {
                $errors[] = "Question {$question_num}: Question text is required.";
            }

            if (empty($question['type'])) {
                $errors[] = "Question {$question_num}: Question type is required.";
            }

            $points = (float)($question['points'] ?? 1);
            if ($points <= 0) {
                $errors[] = "Question {$question_num}: Points must be greater than 0.";
            }

            // Validate based on question type
            if ($question['type'] === 'multiple_choice') {
                $options = $question['options'] ?? [];
                if (count($options) < 2) {
                    $errors[] = "Question {$question_num}: Multiple choice questions must have at least 2 options.";
                }

                $has_correct = false;
                foreach ($options as $option) {
                    if (!empty($option['is_correct'])) {
                        $has_correct = true;
                        break;
                    }
                }
                if (!$has_correct) {
                    $errors[] = "Question {$question_num}: At least one option must be marked as correct.";
                }
            } elseif ($question['type'] === 'true_false') {
                if (empty($question['correct_answer'])) {
                    $errors[] = "Question {$question_num}: Correct answer must be selected for true/false questions.";
                }
            }
        }
    }

    // If no errors, save the quiz
    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // Insert quiz
            $stmt = $conn->prepare("
                INSERT INTO quizzes (classroom_id, title, description, time_limit, attempts_allowed, 
                                   shuffle_questions, show_results, pass_percentage, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ississiis", $classroom_id, $title, $description, $time_limit, 
                            $attempts_allowed, $shuffle_questions, $show_results, $pass_percentage, $status);
            $stmt->execute();
            
            $quiz_id = $conn->insert_id;

            // Insert questions
            foreach ($questions as $index => $question) {
                $question_text = clean_input($question['text']);
                $question_type = $question['type'];
                $points = (float)($question['points'] ?? 1);
                $explanation = clean_input($question['explanation'] ?? '');

                $stmt = $conn->prepare("
                    INSERT INTO quiz_questions (quiz_id, question_text, question_type, points, order_num, explanation)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("issdis", $quiz_id, $question_text, $question_type, $points, $index, $explanation);
                $stmt->execute();
                
                $question_id = $conn->insert_id;

                // Insert options for multiple choice questions
                if ($question_type === 'multiple_choice') {
                    $options = $question['options'] ?? [];
                    foreach ($options as $opt_index => $option) {
                        if (!empty($option['text'])) {
                            $option_text = clean_input($option['text']);
                            $is_correct = !empty($option['is_correct']) ? 1 : 0;

                            $stmt = $conn->prepare("
                                INSERT INTO quiz_options (question_id, option_text, is_correct, order_num)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->bind_param("isii", $question_id, $option_text, $is_correct, $opt_index);
                            $stmt->execute();
                        }
                    }
                } elseif ($question_type === 'true_false') {
                    // Create true/false options
                    $correct_answer = $question['correct_answer'];
                    
                    $stmt = $conn->prepare("
                        INSERT INTO quiz_options (question_id, option_text, is_correct, order_num)
                        VALUES (?, 'True', ?, 0), (?, 'False', ?, 1)
                    ");
                    $true_correct = ($correct_answer === 'true') ? 1 : 0;
                    $false_correct = ($correct_answer === 'false') ? 1 : 0;
                    $stmt->bind_param("iiii", $question_id, $true_correct, $question_id, $false_correct);
                    $stmt->execute();
                }
            }

            // Log activity if table exists
            $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
            if ($activity_table_exists) {
                $activity_description = "Created quiz: " . $title;
                $stmt = $conn->prepare("
                    INSERT INTO activity_log (user_id, classroom_id, activity_type, description, created_at)
                    VALUES (?, ?, 'quiz_created', ?, NOW())
                ");
                $stmt->bind_param("iis", $user_id, $classroom_id, $activity_description);
                $stmt->execute();
            }

            $conn->commit();
            
            $action = ($status === 'published') ? 'created and published' : 'created as draft';
            set_flash_message("success", "Quiz successfully {$action}!");
            redirect("view_classroom.php?id=" . $classroom_id);

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error creating quiz: " . $e->getMessage();
        }
    }
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
                Create Quiz
            </h1>
        </div>
        <p class="text-gray-600 ml-10">Create an interactive quiz for "<?php echo htmlspecialchars($classroom['name']); ?>"</p>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-8 animate__animated animate__headShake">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium">Please fix the following errors:</h3>
                    <ul class="list-disc list-inside mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quiz Creation Form -->
    <form method="POST" id="quizForm" class="space-y-8">
        <!-- Basic Quiz Information -->
        <div class="bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.1s;">
            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Quiz Information
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2">
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Quiz Title *</label>
                    <input type="text" id="title" name="title" required 
                        value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
                        placeholder="Enter quiz title...">
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="description" name="description" rows="3"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
                        placeholder="Describe the quiz purpose and instructions..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                </div>

                <div>
                    <label for="time_limit" class="block text-sm font-medium text-gray-700 mb-2">Time Limit (minutes)</label>
                    <input type="number" id="time_limit" name="time_limit" min="1" max="300"
                        value="<?php echo isset($_POST['time_limit']) ? $_POST['time_limit'] : ''; ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
                        placeholder="No time limit">
                    <p class="text-xs text-gray-500 mt-1">Leave empty for unlimited time</p>
                </div>

                <div>
                    <label for="attempts_allowed" class="block text-sm font-medium text-gray-700 mb-2">Attempts Allowed</label>
                    <select id="attempts_allowed" name="attempts_allowed"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300">
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo (isset($_POST['attempts_allowed']) && $_POST['attempts_allowed'] == $i) ? 'selected' : ($i == 1 ? 'selected' : ''); ?>>
                                <?php echo $i; ?> attempt<?php echo $i > 1 ? 's' : ''; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div>
                    <label for="pass_percentage" class="block text-sm font-medium text-gray-700 mb-2">Pass Percentage</label>
                    <input type="number" id="pass_percentage" name="pass_percentage" min="0" max="100" step="0.01"
                        value="<?php echo isset($_POST['pass_percentage']) ? $_POST['pass_percentage'] : '60.00'; ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300">
                    <p class="text-xs text-gray-500 mt-1">Minimum percentage to pass</p>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select id="status" name="status"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300">
                        <option value="draft" <?php echo (isset($_POST['status']) && $_POST['status'] === 'draft') ? 'selected' : 'selected'; ?>>Draft</option>
                        <option value="published" <?php echo (isset($_POST['status']) && $_POST['status'] === 'published') ? 'selected' : ''; ?>>Published</option>
                    </select>
                </div>
            </div>

            <div class="mt-6 space-y-4">
                <div class="flex items-center">
                    <input type="checkbox" id="shuffle_questions" name="shuffle_questions" 
                        <?php echo isset($_POST['shuffle_questions']) ? 'checked' : ''; ?>
                        class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
                    <label for="shuffle_questions" class="ml-2 block text-sm text-gray-900">
                        Shuffle questions for each student
                    </label>
                </div>

                <div class="flex items-center">
                    <input type="checkbox" id="show_results" name="show_results" 
                        <?php echo !isset($_POST['show_results']) || $_POST['show_results'] ? 'checked' : ''; ?>
                        class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
                    <label for="show_results" class="ml-2 block text-sm text-gray-900">
                        Show results to students after completion
                    </label>
                </div>
            </div>
        </div>

        <!-- Questions Section -->
        <div class="bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-900 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Questions
                </h2>
                <button type="button" onclick="addQuestion()" 
                    class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md flex items-center transition-all duration-300 transform hover:scale-105">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Question
                </button>
            </div>

            <div id="questionsContainer">
                <!-- Questions will be added here dynamically -->
            </div>

            <!-- Add First Question Prompt -->
            <div id="noQuestionsPrompt" class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-24 w-24 mx-auto text-purple-200 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No questions added yet</h3>
                <p class="text-gray-500 mb-4">Click "Add Question" to create your first quiz question.</p>
                <button type="button" onclick="addQuestion()" 
                    class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-md flex items-center mx-auto transition-all duration-300 transform hover:scale-105">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Your First Question
                </button>
            </div>
        </div>

        <!-- Submit Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-end animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
            <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" 
                class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-3 rounded-md transition-all duration-300 text-center">
                Cancel
            </a>
            <button type="submit" id="submitBtn"
                class="bg-purple-600 hover:bg-purple-700 text-white px-8 py-3 rounded-md transition-all duration-300 flex items-center justify-center transform hover:scale-105">
                <span id="btnText">Create Quiz</span>
            </button>
        </div>
    </form>
</div>

<!-- Question Templates (Hidden) -->
<template id="questionTemplate">
    <div class="question-item bg-gray-50 rounded-lg p-6 mb-6 border border-gray-200" data-question-index="">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-lg font-medium text-gray-900">Question <span class="question-number"></span></h3>
            <button type="button" onclick="removeQuestion(this)" 
                class="text-red-600 hover:text-red-800 transition-colors duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Question Text *</label>
                <textarea name="questions[][text]" rows="3" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
                    placeholder="Enter your question..."></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Question Type</label>
                <select name="questions[][type]" onchange="updateQuestionType(this)" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300">
                    <option value="multiple_choice">Multiple Choice</option>
                    <option value="true_false">True/False</option>
                    <option value="short_answer">Short Answer</option>
                    <option value="essay">Essay</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Points</label>
                <input type="number" name="questions[][points]" min="0.1" step="0.1" value="1" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Explanation (Optional)</label>
                <input type="text" name="questions[][explanation]"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
                    placeholder="Explain the correct answer...">
            </div>
        </div>

        <!-- Options Container -->
        <div class="options-container">
            <!-- Options will be added here based on question type -->
        </div>
    </div>
</template>

<script>
let questionIndex = 0;

function addQuestion() {
    const container = document.getElementById('questionsContainer');
    const template = document.getElementById('questionTemplate');
    const questionDiv = template.content.cloneNode(true);
    
    // Update question number and index
    questionDiv.querySelector('.question-number').textContent = questionIndex + 1;
    questionDiv.querySelector('.question-item').setAttribute('data-question-index', questionIndex);
    
    // Update input names with index
    const inputs = questionDiv.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        if (input.name && input.name.includes('[]')) {
            input.name = input.name.replace('[]', `[${questionIndex}]`);
        }
    });
    
    container.appendChild(questionDiv);
    
    // Initialize with multiple choice options
    const newQuestion = container.lastElementChild;
    updateQuestionType(newQuestion.querySelector('select[name*="[type]"]'));
    
    questionIndex++;
    
    // Hide the no questions prompt
    document.getElementById('noQuestionsPrompt').style.display = 'none';
    
    // Scroll to the new question
    newQuestion.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function removeQuestion(button) {
    const questionItem = button.closest('.question-item');
    questionItem.remove();
    
    // Update question numbers
    updateQuestionNumbers();
    
    // Show no questions prompt if no questions left
    const container = document.getElementById('questionsContainer');
    if (container.children.length === 0) {
        document.getElementById('noQuestionsPrompt').style.display = 'block';
    }
}

function updateQuestionNumbers() {
    const questions = document.querySelectorAll('.question-item');
    questions.forEach((question, index) => {
        question.querySelector('.question-number').textContent = index + 1;
        question.setAttribute('data-question-index', index);
        
        // Update input names
        const inputs = question.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            if (input.name && input.name.match(/\[\d+\]/)) {
                input.name = input.name.replace(/\[\d+\]/, `[${index}]`);
            }
        });
    });
}

function updateQuestionType(selectElement) {
    const questionItem = selectElement.closest('.question-item');
    const optionsContainer = questionItem.querySelector('.options-container');
    const questionIndex = questionItem.getAttribute('data-question-index');
    const questionType = selectElement.value;
    
    optionsContainer.innerHTML = '';
    
    if (questionType === 'multiple_choice') {
        optionsContainer.innerHTML = `
            <div class="multiple-choice-options">
                <div class="flex justify-between items-center mb-3">
                    <label class="block text-sm font-medium text-gray-700">Answer Options</label>
                    <button type="button" onclick="addOption(this)" 
                        class="text-purple-600 hover:text-purple-800 text-sm font-medium transition-colors duration-300">
                        + Add Option
                    </button>
                </div>
                <div class="options-list space-y-3">
                    <div class="option-item flex items-center space-x-3">
                        <input type="checkbox" name="questions[${questionIndex}][options][0][is_correct]" value="1"
                            class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
                        <input type="text" name="questions[${questionIndex}][options][0][text]" required
                            class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
                            placeholder="Option 1">
                        <button type="button" onclick="removeOption(this)" 
                            class="text-red-600 hover:text-red-800 transition-colors duration-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="option-item flex items-center space-x-3">
                        <input type="checkbox" name="questions[${questionIndex}][options][1][is_correct]" value="1"
                            class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
                        <input type="text" name="questions[${questionIndex}][options][1][text]" required
                            class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
                            placeholder="Option 2">
                        <button type="button" onclick="removeOption(this)" 
                            class="text-red-600 hover:text-red-800 transition-colors duration-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Check the box next to correct answers</p>
            </div>
        `;
    } else if (questionType === 'true_false') {
        optionsContainer.innerHTML = `
            <div class="true-false-options">
                <label class="block text-sm font-medium text-gray-700 mb-3">Correct Answer</label>
                <div class="space-y-2">
                    <label class="flex items-center">
                        <input type="radio" name="questions[${questionIndex}][correct_answer]" value="true" required
                            class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300">
                        <span class="ml-2 text-sm text-gray-900">True</span>
                    </label>
                    <label class="flex items-center">
                        <input type="radio" name="questions[${questionIndex}][correct_answer]" value="false" required
                            class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300">
                        <span class="ml-2 text-sm text-gray-900">False</span>
                    </label>
                </div>
            </div>
        `;
    } else if (questionType === 'short_answer') {
        optionsContainer.innerHTML = `
            <div class="short-answer-info bg-blue-50 p-4 rounded-lg">
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm text-blue-800">Short answer questions will require manual grading by the teacher.</p>
                </div>
            </div>
        `;
    } else if (questionType === 'essay') {
        optionsContainer.innerHTML = `
            <div class="essay-info bg-blue-50 p-4 rounded-lg">
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm text-blue-800">Essay questions will require manual grading by the teacher.</p>
                </div>
            </div>
        `;
    }
}

function addOption(button) {
    const optionsList = button.closest('.multiple-choice-options').querySelector('.options-list');
    const questionItem = button.closest('.question-item');
    const questionIndex = questionItem.getAttribute('data-question-index');
    const optionIndex = optionsList.children.length;
    
    const optionDiv = document.createElement('div');
    optionDiv.className = 'option-item flex items-center space-x-3';
    optionDiv.innerHTML = `
        <input type="checkbox" name="questions[${questionIndex}][options][${optionIndex}][is_correct]" value="1"
            class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
        <input type="text" name="questions[${questionIndex}][options][${optionIndex}][text]" required
            class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500 transition-colors duration-300"
            placeholder="Option ${optionIndex + 1}">
        <button type="button" onclick="removeOption(this)" 
            class="text-red-600 hover:text-red-800 transition-colors duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    `;
    
    optionsList.appendChild(optionDiv);
}

function removeOption(button) {
    const optionItem = button.closest('.option-item');
    const optionsList = optionItem.closest('.options-list');
    
    // Don't remove if it's one of the last two options
    if (optionsList.children.length <= 2) {
        alert('Multiple choice questions must have at least 2 options.');
        return;
    }
    
    optionItem.remove();
    
    // Update option indices
    const questionItem = optionItem.closest('.question-item');
    const questionIndex = questionItem.getAttribute('data-question-index');
    const options = optionsList.querySelectorAll('.option-item');
    
    options.forEach((option, index) => {
        const inputs = option.querySelectorAll('input');
        inputs[0].name = `questions[${questionIndex}][options][${index}][is_correct]`;
        inputs[1].name = `questions[${questionIndex}][options][${index}][text]`;
        inputs[1].placeholder = `Option ${index + 1}`;
    });
}

// Form submission handling
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('quizForm');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    
    form.addEventListener('submit', function(e) {
        // Check if at least one question exists
        const questionsContainer = document.getElementById('questionsContainer');
        if (questionsContainer.children.length === 0) {
            e.preventDefault();
            alert('Please add at least one question to the quiz.');
            return;
        }
        
        // Show loading state
        submitBtn.disabled = true;
        btnText.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Creating Quiz...
        `;
    });
    
    // Add first question automatically if none exist
    if (document.getElementById('questionsContainer').children.length === 0) {
        // Don't auto-add, let user click the button
    }
});
</script>

<?php include 'includes/footer.php'; ?>