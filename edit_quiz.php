<?php
include 'includes/header.php';

// Check if user is logged in and is a teacher
if (!is_logged_in()) {
    set_flash_message("error", "Please login to edit quiz.");
    redirect("login.php");
}

if (!is_teacher() && !is_admin()) {
    set_flash_message("error", "Only teachers can edit quizzes.");
    redirect("index.php");
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

// Get quiz information
$stmt = $conn->prepare("
    SELECT q.*, c.name as classroom_name, c.teacher_id
    FROM quizzes q
    JOIN classrooms c ON q.classroom_id = c.id
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

// Check permissions
if ($user_role === 'teacher' && $quiz['teacher_id'] != $user_id) {
    set_flash_message("error", "You don't have permission to edit this quiz.");
    redirect("view_classroom.php?id=" . $quiz['classroom_id']);
}

// Get existing questions and options
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
$existing_questions = $result->fetch_all(MYSQLI_ASSOC);

// Process existing questions for JavaScript
$questions_data = [];
foreach ($existing_questions as $question) {
    $options = [];
    if ($question['options']) {
        foreach (explode('||', $question['options']) as $option_data) {
            $parts = explode(':', $option_data, 4);
            if (count($parts) === 4) {
                $options[] = [
                    'id' => $parts[0],
                    'text' => $parts[1],
                    'is_correct' => $parts[2] == 1,
                    'order_num' => $parts[3]
                ];
            }
        }
    }
    
    $questions_data[] = [
        'id' => $question['id'],
        'question_text' => $question['question_text'],
        'question_type' => $question['question_type'],
        'points' => $question['points'],
        'order_num' => $question['order_num'],
        'options' => $options
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $time_limit = (int)($_POST['time_limit'] ?? 0);
    $attempts_allowed = (int)($_POST['attempts_allowed'] ?? 1);
    $pass_percentage = (float)($_POST['pass_percentage'] ?? 70);
    $status = $_POST['status'] ?? 'draft';
    $questions = json_decode($_POST['questions_data'] ?? '[]', true);
    
    // Validation
    $errors = [];
    
    if (empty($title)) {
        $errors[] = "Title is required.";
    }
    
    if ($time_limit < 0) {
        $errors[] = "Time limit must be 0 or greater.";
    }
    
    if ($attempts_allowed < 1) {
        $errors[] = "Attempts allowed must be at least 1.";
    }
    
    if ($pass_percentage < 0 || $pass_percentage > 100) {
        $errors[] = "Pass percentage must be between 0 and 100.";
    }
    
    if (empty($questions)) {
        $errors[] = "At least one question is required.";
    }
    
    // Validate questions
    foreach ($questions as $index => $question) {
        $q_num = $index + 1;
        
        if (empty(trim($question['question_text']))) {
            $errors[] = "Question $q_num text is required.";
        }
        
        if (!in_array($question['question_type'], ['multiple_choice', 'true_false', 'short_answer', 'essay'])) {
            $errors[] = "Question $q_num has invalid type.";
        }
        
        if ($question['points'] <= 0) {
            $errors[] = "Question $q_num points must be greater than 0.";
        }
        
        // Validate options for multiple choice and true/false
        if (in_array($question['question_type'], ['multiple_choice', 'true_false'])) {
            if (empty($question['options'])) {
                $errors[] = "Question $q_num must have options.";
            } else {
                $has_correct = false;
                foreach ($question['options'] as $option) {
                    if (empty(trim($option['text']))) {
                        $errors[] = "Question $q_num has empty option text.";
                    }
                    if ($option['is_correct']) {
                        $has_correct = true;
                    }
                }
                if (!$has_correct) {
                    $errors[] = "Question $q_num must have at least one correct answer.";
                }
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->begin_transaction();
            
            // Update quiz
            $stmt = $conn->prepare("
                UPDATE quizzes 
                SET title = ?, description = ?, time_limit = ?, attempts_allowed = ?, 
                    pass_percentage = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->bind_param("ssiiisi", $title, $description, $time_limit, $attempts_allowed, $pass_percentage, $status, $quiz_id);
            $stmt->execute();
            
            // Delete existing questions and options
            $stmt = $conn->prepare("DELETE FROM quiz_options WHERE question_id IN (SELECT id FROM quiz_questions WHERE quiz_id = ?)");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();
            
            $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();
            
            // Insert new questions
            foreach ($questions as $index => $question) {
                $stmt = $conn->prepare("
                    INSERT INTO quiz_questions (quiz_id, question_text, question_type, points, order_num) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $order_num = $index + 1;
                $stmt->bind_param("issii", $quiz_id, $question['question_text'], $question['question_type'], $question['points'], $order_num);
                $stmt->execute();
                $question_id = $conn->insert_id;
                
                // Insert options for multiple choice and true/false questions
                if (in_array($question['question_type'], ['multiple_choice', 'true_false']) && !empty($question['options'])) {
                    foreach ($question['options'] as $opt_index => $option) {
                        $stmt = $conn->prepare("
                            INSERT INTO quiz_options (question_id, option_text, is_correct, order_num) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $is_correct = $option['is_correct'] ? 1 : 0;
                        $opt_order = $opt_index + 1;
                        $stmt->bind_param("isii", $question_id, $option['text'], $is_correct, $opt_order);
                        $stmt->execute();
                    }
                }
            }
            
            $conn->commit();
            set_flash_message("success", "Quiz updated successfully!");
            redirect("view_classroom.php?id=" . $quiz['classroom_id']);
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error updating quiz: " . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            set_flash_message("error", $error);
        }
    }
}
?>

<div class="container mx-auto px-4 py-6 fade-in">
    <!-- Back Button -->
    <div class="mb-6 animate__animated animate__fadeInLeft">
        <a href="view_classroom.php?id=<?php echo $quiz['classroom_id']; ?>" class="text-purple-600 hover:text-purple-800 hover:underline flex items-center transition-colors duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Classroom
        </a>
    </div>

    <!-- Page Header -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden animate__animated animate__fadeIn mb-8">
        <div class="bg-purple-600 p-6 text-white">
            <h1 class="text-3xl font-bold mb-2">Edit Quiz</h1>
            <p class="text-purple-100 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-2m-2 0H7m14 0a2 2 0 002 2H3a2 2 0 002-2m0 0V9a2 2 0 012-2h10a2 2 0 012 2v12" />
                </svg>
                <span class="font-medium">Classroom:</span> <?php echo htmlspecialchars($quiz['classroom_name']); ?>
            </p>
        </div>
        
        <div class="p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($quiz['title']); ?></h2>
                    <p class="text-gray-600">Modify your quiz questions and settings</p>
                </div>
                <div class="text-right">
                    <div class="text-sm text-gray-500">Current Status</div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium 
                        <?php echo $quiz['status'] === 'published' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                        <?php echo ucfirst($quiz['status']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Quiz Form -->
    <form method="POST" class="space-y-8" id="quiz-form">
        <!-- Basic Information -->
        <div class="bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.1s;">
            <h2 class="text-2xl font-bold text-purple-800 mb-6 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Basic Information
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2">
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Quiz Title *</label>
                    <input type="text" id="title" name="title" required 
                           value="<?php echo htmlspecialchars($quiz['title']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                </div>
                
                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="description" name="description" rows="3" 
                              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500"
                              placeholder="Optional description for the quiz"><?php echo htmlspecialchars($quiz['description']); ?></textarea>
                </div>
                
                <div>
                    <label for="time_limit" class="block text-sm font-medium text-gray-700 mb-2">Time Limit (minutes)</label>
                    <input type="number" id="time_limit" name="time_limit" min="0" 
                           value="<?php echo $quiz['time_limit']; ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500"
                           placeholder="0 for unlimited">
                    <p class="text-sm text-gray-500 mt-1">Set to 0 for unlimited time</p>
                </div>
                
                <div>
                    <label for="attempts_allowed" class="block text-sm font-medium text-gray-700 mb-2">Attempts Allowed</label>
                    <input type="number" id="attempts_allowed" name="attempts_allowed" min="1" 
                           value="<?php echo $quiz['attempts_allowed']; ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                </div>
                
                <div>
                    <label for="pass_percentage" class="block text-sm font-medium text-gray-700 mb-2">Pass Percentage (%)</label>
                    <input type="number" id="pass_percentage" name="pass_percentage" min="0" max="100" step="0.1" 
                           value="<?php echo $quiz['pass_percentage']; ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                </div>
                
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select id="status" name="status" 
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                        <option value="draft" <?php echo $quiz['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo $quiz['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                    </select>
                    <p class="text-sm text-gray-500 mt-1">Students can only access published quizzes</p>
                </div>
            </div>
        </div>

        <!-- Questions Section -->
        <div class="bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-purple-800 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Questions
                </h2>
                <button type="button" onclick="addQuestion()" 
                        class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md transition-colors duration-300 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Question
                </button>
            </div>
            
            <div id="questions-container" class="space-y-6">
                <!-- Questions will be dynamically added here -->
            </div>
            
            <div id="no-questions-message" class="text-center py-8 text-gray-500">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p>No questions added yet. Click "Add Question" to get started.</p>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center space-y-4 sm:space-y-0">
                <div class="text-sm text-gray-600">
                    <p>• Questions are automatically saved when you submit the form</p>
                    <p>• Students can only see published quizzes</p>
                    <p>• Changes will apply immediately after saving</p>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" onclick="previewQuiz()" 
                            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-md transition-colors duration-300 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        Preview
                    </button>
                    
                    <button type="submit" 
                            class="bg-purple-600 hover:bg-purple-700 text-white px-8 py-2 rounded-md transition-colors duration-300 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Update Quiz
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Hidden input to store questions data -->
        <input type="hidden" name="questions_data" id="questions_data">
    </form>
</div>

<!-- Question Templates -->
<template id="question-template">
    <div class="question-item border border-gray-200 rounded-lg p-6 bg-gray-50" data-question-index="">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Question <span class="question-number"></span></h3>
            <div class="flex space-x-2">
                <button type="button" onclick="moveQuestion(this, 'up')" class="text-gray-500 hover:text-gray-700 p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                    </svg>
                </button>
                <button type="button" onclick="moveQuestion(this, 'down')" class="text-gray-500 hover:text-gray-700 p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <button type="button" onclick="deleteQuestion(this)" class="text-red-500 hover:text-red-700 p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Question Text *</label>
                <textarea class="question-text w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500" 
                         rows="3" placeholder="Enter your question here..." required></textarea>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Question Type</label>
                    <select class="question-type w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500"
                           onchange="updateQuestionType(this)">
                        <option value="multiple_choice">Multiple Choice</option>
                        <option value="true_false">True/False</option>
                        <option value="short_answer">Short Answer</option>
                        <option value="essay">Essay</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Points</label>
                    <input type="number" class="question-points w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500" 
                          min="1" value="1" required>
                </div>
            </div>
        </div>
        
        <div class="options-container">
            <!-- Options will be added here based on question type -->
        </div>
    </div>
</template>

<script>
let questions = <?php echo json_encode($questions_data); ?>;
let questionCounter = questions.length;

// Initialize the form with existing questions
document.addEventListener('DOMContentLoaded', function() {
    if (questions.length === 0) {
        document.getElementById('no-questions-message').style.display = 'block';
    } else {
        document.getElementById('no-questions-message').style.display = 'none';
        questions.forEach((question, index) => {
            addQuestion(question);
        });
    }
});

function addQuestion(existingQuestion = null) {
    const container = document.getElementById('questions-container');
    const template = document.getElementById('question-template');
    const questionDiv = template.content.cloneNode(true);
    
    const questionIndex = questionCounter++;
    const questionItem = questionDiv.querySelector('.question-item');
    questionItem.setAttribute('data-question-index', questionIndex);
    
    // Update question number
    questionDiv.querySelector('.question-number').textContent = questionIndex + 1;
    
    // If existing question data, populate it
    if (existingQuestion) {
        questionDiv.querySelector('.question-text').value = existingQuestion.question_text;
        questionDiv.querySelector('.question-type').value = existingQuestion.question_type;
        questionDiv.querySelector('.question-points').value = existingQuestion.points;
    }
    
    container.appendChild(questionDiv);
    
    // Update question type to show appropriate options
    const questionTypeSelect = container.lastElementChild.querySelector('.question-type');
    updateQuestionType(questionTypeSelect);
    
    // If existing question data, populate options
    if (existingQuestion && existingQuestion.options) {
        const optionsContainer = container.lastElementChild.querySelector('.options-container');
        if (existingQuestion.question_type === 'multiple_choice') {
            existingQuestion.options.forEach((option, index) => {
                if (index > 0) { // First option is already added
                    addOption(container.lastElementChild.querySelector('[onclick="addOption(this)"]'));
                }
                const optionInputs = optionsContainer.querySelectorAll('.option-text');
                const correctCheckboxes = optionsContainer.querySelectorAll('.option-correct');
                
                if (optionInputs[index]) {
                    optionInputs[index].value = option.text;
                }
                if (correctCheckboxes[index]) {
                    correctCheckboxes[index].checked = option.is_correct;
                }
            });
        } else if (existingQuestion.question_type === 'true_false') {
            const correctRadios = optionsContainer.querySelectorAll('input[type="radio"]');
            existingQuestion.options.forEach((option, index) => {
                if (option.is_correct && correctRadios[index]) {
                    correctRadios[index].checked = true;
                }
            });
        }
    }
    
    document.getElementById('no-questions-message').style.display = 'none';
    updateQuestionNumbers();
}

function deleteQuestion(button) {
    if (confirm('Are you sure you want to delete this question?')) {
        const questionItem = button.closest('.question-item');
        questionItem.remove();
        updateQuestionNumbers();
        
        if (document.querySelectorAll('.question-item').length === 0) {
            document.getElementById('no-questions-message').style.display = 'block';
        }
    }
}

function moveQuestion(button, direction) {
    const questionItem = button.closest('.question-item');
    const container = document.getElementById('questions-container');
    
    if (direction === 'up' && questionItem.previousElementSibling) {
        container.insertBefore(questionItem, questionItem.previousElementSibling);
    } else if (direction === 'down' && questionItem.nextElementSibling) {
        container.insertBefore(questionItem.nextElementSibling, questionItem);
    }
    
    updateQuestionNumbers();
}

function updateQuestionNumbers() {
    const questionItems = document.querySelectorAll('.question-item');
    questionItems.forEach((item, index) => {
        item.querySelector('.question-number').textContent = index + 1;
        item.setAttribute('data-question-index', index);
    });
}

function updateQuestionType(select) {
    const questionItem = select.closest('.question-item');
    const optionsContainer = questionItem.querySelector('.options-container');
    const questionType = select.value;
    
    optionsContainer.innerHTML = '';
    
    if (questionType === 'multiple_choice') {
        optionsContainer.innerHTML = `
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Answer Options</label>
                <div class="options-list space-y-2">
                    <div class="flex items-center space-x-2">
                        <input type="text" class="option-text flex-1 px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500" 
                               placeholder="Option 1" required>
                        <label class="flex items-center">
                            <input type="checkbox" class="option-correct mr-2">
                            <span class="text-sm text-gray-600">Correct</span>
                        </label>
                        <button type="button" onclick="removeOption(this)" class="text-red-500 hover:text-red-700 p-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <button type="button" onclick="addOption(this)" class="mt-2 text-purple-600 hover:text-purple-800 text-sm flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Option
                </button>
            </div>
        `;
    } else if (questionType === 'true_false') {
        optionsContainer.innerHTML = `
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Correct Answer</label>
                <div class="space-y-2">
                    <label class="flex items-center">
                        <input type="radio" name="tf_correct_${questionItem.getAttribute('data-question-index')}" value="true" class="mr-2">
                        <span>True</span>
                    </label>
                    <label class="flex items-center">
                        <input type="radio" name="tf_correct_${questionItem.getAttribute('data-question-index')}" value="false" class="mr-2">
                        <span>False</span>
                    </label>
                </div>
            </div>
        `;
    } else {
        optionsContainer.innerHTML = `
            <div class="mt-4">
                <p class="text-sm text-gray-600">
                    ${questionType === 'short_answer' ? 'Students will enter a short text answer.' : 'Students will enter a longer essay-style answer.'}
                </p>
            </div>
        `;
    }
}

function addOption(button) {
    const optionsList = button.previousElementSibling;
    const optionCount = optionsList.children.length + 1;
    
    const optionDiv = document.createElement('div');
    optionDiv.className = 'flex items-center space-x-2';
    optionDiv.innerHTML = `
        <input type="text" class="option-text flex-1 px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500" 
               placeholder="Option ${optionCount}" required>
        <label class="flex items-center">
            <input type="checkbox" class="option-correct mr-2">
            <span class="text-sm text-gray-600">Correct</span>
        </label>
        <button type="button" onclick="removeOption(this)" class="text-red-500 hover:text-red-700 p-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    `;
    
    optionsList.appendChild(optionDiv);
}

function removeOption(button) {
    const optionDiv = button.closest('.flex');
    const optionsList = optionDiv.parentElement;
    
    if (optionsList.children.length > 1) {
        optionDiv.remove();
    } else {
        alert('At least one option is required.');
    }
}

function previewQuiz() {
    // Collect form data and show preview modal or new window
    const formData = collectQuizData();
    if (formData) {
        // Here you could open a preview modal or new window
        alert('Preview functionality would show how the quiz looks to students.');
    }
}

function collectQuizData() {
    const questionItems = document.querySelectorAll('.question-item');
    const questionsData = [];
    
    for (let i = 0; i < questionItems.length; i++) {
        const item = questionItems[i];
        const questionText = item.querySelector('.question-text').value.trim();
        const questionType = item.querySelector('.question-type').value;
        const points = parseInt(item.querySelector('.question-points').value);
        
        if (!questionText) {
            alert(`Question ${i + 1} text is required.`);
            return null;
        }
        
        if (points <= 0) {
            alert(`Question ${i + 1} points must be greater than 0.`);
            return null;
        }
        
        const questionData = {
            question_text: questionText,
            question_type: questionType,
            points: points,
            options: []
        };
        
        if (questionType === 'multiple_choice') {
            const optionTexts = item.querySelectorAll('.option-text');
            const optionCorrects = item.querySelectorAll('.option-correct');
            let hasCorrect = false;
            
            for (let j = 0; j < optionTexts.length; j++) {
                const optionText = optionTexts[j].value.trim();
                if (!optionText) {
                    alert(`Question ${i + 1}, Option ${j + 1} text is required.`);
                    return null;
                }
                
                const isCorrect = optionCorrects[j].checked;
                if (isCorrect) hasCorrect = true;
                
                questionData.options.push({
                    text: optionText,
                    is_correct: isCorrect
                });
            }
            
            if (!hasCorrect) {
                alert(`Question ${i + 1} must have at least one correct answer.`);
                return null;
            }
        } else if (questionType === 'true_false') {
            const correctRadio = item.querySelector(`input[name="tf_correct_${i}"]:checked`);
            if (!correctRadio) {
                alert(`Question ${i + 1} must have a correct answer selected.`);
                return null;
            }
            
            questionData.options = [
                { text: 'True', is_correct: correctRadio.value === 'true' },
                { text: 'False', is_correct: correctRadio.value === 'false' }
            ];
        }
        
        questionsData.push(questionData);
    }
    
    return questionsData;
}

// Form submission handler
document.getElementById('quiz-form').addEventListener('submit', function(e) {
    const questionsData = collectQuizData();
    if (!questionsData) {
        e.preventDefault();
        return;
    }
    
    if (questionsData.length === 0) {
        alert('At least one question is required.');
        e.preventDefault();
        return;
    }
    
    document.getElementById('questions_data').value = JSON.stringify(questionsData);
});

// Auto-save functionality (optional)
setInterval(function() {
    const questionsData = collectQuizData();
    if (questionsData) {
        // Could implement auto-save to localStorage here
        console.log('Auto-save triggered');
    }
}, 30000); // Auto-save every 30 seconds
</script>

<style>
.question-item {
    transition: all 0.3s ease;
}

.question-item:hover {
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.options-list > div {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Print styles */
@media print {
    .no-print {
        display: none !important;
    }
}
</style>

<?php include 'includes/footer.php'; ?>