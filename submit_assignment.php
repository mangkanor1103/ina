<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to submit assignments.");
    redirect("login.php");
}

// Check if user is a student
if (!is_student()) {
    set_flash_message("error", "Only students can submit assignments.");
    redirect("index.php");
}

// Check if lesson ID is provided
if (!isset($_GET['lesson_id']) || !is_numeric($_GET['lesson_id'])) {
    set_flash_message("error", "Invalid lesson ID.");
    redirect("index.php");
}

$lesson_id = clean_input($_GET['lesson_id']);

// Get lesson details including deadline
$stmt = $conn->prepare("SELECT *, deadline FROM lessons WHERE id = ?");
$stmt->bind_param("i", $lesson_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if lesson exists
if ($result->num_rows === 0) {
    set_flash_message("error", "Lesson not found.");
    redirect("index.php");
}

$lesson = $result->fetch_assoc();
$classroom_id = $lesson['classroom_id'];

// Check if student is enrolled in this classroom
$stmt = $conn->prepare("SELECT * FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
$stmt->bind_param("ii", $classroom_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flash_message("error", "You are not enrolled in this classroom.");
    redirect("my_classes.php");
}

// Check if student has already submitted
$stmt = $conn->prepare("SELECT * FROM submissions WHERE lesson_id = ? AND student_id = ?");
$stmt->bind_param("ii", $lesson_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $submission = $result->fetch_assoc();
    set_flash_message("info", "You have already submitted work for this lesson. You can edit your submission instead.");
    redirect("view_submission.php?id=" . $submission['id']);
}

// Check if submissions table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;

if (!$table_exists) {
    // Create submissions table if it doesn't exist with NULL allowed
    $create_table_sql = "CREATE TABLE submissions (
        id INT(11) NOT NULL AUTO_INCREMENT,
        lesson_id INT(11) NOT NULL,
        student_id INT(11) NOT NULL,
        content TEXT NULL,
        file_path VARCHAR(255) NULL,
        submitted_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        grade DECIMAL(5,2) DEFAULT NULL,
        feedback TEXT NULL,
        graded_at DATETIME DEFAULT NULL,
        graded_by INT(11) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY lesson_id (lesson_id),
        KEY student_id (student_id)
    )";
    
    $conn->query($create_table_sql);
} else {
    // Check if required columns exist and add them if they don't
    
    // Check for content column and make sure it allows NULL
    $content_column_exists = $conn->query("SHOW COLUMNS FROM submissions LIKE 'content'")->num_rows > 0;
    if (!$content_column_exists) {
        $conn->query("ALTER TABLE submissions ADD COLUMN content TEXT NULL AFTER student_id");
    }
    
    // Check for file_path column and make sure it allows NULL
    $file_path_column_exists = $conn->query("SHOW COLUMNS FROM submissions LIKE 'file_path'")->num_rows > 0;
    if (!$file_path_column_exists) {
        $conn->query("ALTER TABLE submissions ADD COLUMN file_path VARCHAR(255) NULL AFTER content");
    } else {
        // Modify file_path column to allow NULL values if it exists but doesn't allow NULL
        $conn->query("ALTER TABLE submissions MODIFY COLUMN file_path VARCHAR(255) NULL");
    }
    
    // Check for feedback column
    $feedback_column_exists = $conn->query("SHOW COLUMNS FROM submissions LIKE 'feedback'")->num_rows > 0;
    if (!$feedback_column_exists) {
        $conn->query("ALTER TABLE submissions ADD COLUMN feedback TEXT NULL AFTER grade");
    }
    
    // Check for graded_at column
    $graded_at_column_exists = $conn->query("SHOW COLUMNS FROM submissions LIKE 'graded_at'")->num_rows > 0;
    if (!$graded_at_column_exists) {
        $conn->query("ALTER TABLE submissions ADD COLUMN graded_at DATETIME DEFAULT NULL AFTER feedback");
    }
    
    // Check for graded_by column
    $graded_by_column_exists = $conn->query("SHOW COLUMNS FROM submissions LIKE 'graded_by'")->num_rows > 0;
    if (!$graded_by_column_exists) {
        $conn->query("ALTER TABLE submissions ADD COLUMN graded_by INT(11) DEFAULT NULL AFTER graded_at");
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = clean_input($_POST['content'] ?? '');
    
    $errors = [];
    
    // Validate input - either content or file should be provided
    if (empty($content) && (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] === 4)) {
        $errors[] = "Please provide either a written response or upload a file.";
    }
    
    // If no errors, process the submission
    if (empty($errors)) {
        // Initialize file path variable
        $file_path = null;
        
        // Handle file upload if present
        if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === 0) {
            $upload_dir = 'uploads/submissions/';
            
            // Ensure the upload directory exists
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['submission_file']['name'], PATHINFO_EXTENSION);
            $file_name = "submission_" . $user_id . "_" . $lesson_id . "_" . time() . "." . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $file_path)) {
                $errors[] = "Failed to upload file. Please try again.";
                $file_path = null;
            }
        }
        
        if (empty($errors)) {
            // FIX: Use a single query for all scenarios and properly handle null values
            $stmt = $conn->prepare("
                INSERT INTO submissions (lesson_id, student_id, content, file_path, submitted_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            // FIX: Use proper NULL handling for file_path parameter
            if ($file_path === null) {
                $stmt->bind_param("iiss", $lesson_id, $user_id, $content, $file_path);
                $stmt->execute();
            } else {
                $stmt->bind_param("iiss", $lesson_id, $user_id, $content, $file_path);
                $stmt->execute();
            }
            
            if ($stmt->affected_rows > 0) {
                $submission_id = $conn->insert_id;
                
                // Create activity log entry if the table exists
                $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
                
                if ($activity_table_exists) {
                    // Get student name
                    $student_name = "";
                    $name_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                    $name_stmt->bind_param("i", $user_id);
                    $name_stmt->execute();
                    $name_result = $name_stmt->get_result();
                    if ($name_result->num_rows > 0) {
                        $student_name = $name_result->fetch_assoc()['username'];
                    }
                    
                    $activity = "Student " . $student_name . " submitted work for lesson: " . $lesson['title'];
                    $log_stmt = $conn->prepare("
                        INSERT INTO activity_log (classroom_id, user_id, activity_type, description, created_at) 
                        VALUES (?, ?, 'submission', ?, NOW())
                    ");
                    $log_stmt->bind_param("iis", $classroom_id, $user_id, $activity);
                    $log_stmt->execute();
                }
                
                set_flash_message("success", "Your work has been submitted successfully.");
                redirect("view_lesson.php?id=" . $lesson_id);
            } else {
                $errors[] = "Failed to submit assignment. Please try again. " . $conn->error;
                
                // Delete uploaded file if submission failed
                if ($file_path && file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }
    }
}
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <div class="mb-6 animate__animated animate__fadeInLeft">
        <a href="view_lesson.php?id=<?php echo $lesson_id; ?>" class="text-green-600 hover:text-green-800 hover:underline flex items-center transition-colors duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Lesson
        </a>
    </div>

    <div class="bg-white rounded-lg shadow-md overflow-hidden hover-scale animate__animated animate__fadeIn">
        <!-- Lesson Header -->
        <div class="bg-green-600 text-white p-6">
            <h1 class="text-2xl font-bold mb-2 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                Submit Assignment
            </h1>
            <p class="text-green-100 flex flex-col md:flex-row md:items-center">
                <span class="inline-block mr-4 mb-2 md:mb-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Lesson: <?php echo htmlspecialchars($lesson['title']); ?>
                </span>
                <span class="inline-block">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Classroom: <?php echo htmlspecialchars($lesson['classroom_name']); ?>
                </span>
            </p>
        </div>
        
        <!-- Submission Form -->
        <div class="p-6">
            <?php if (isset($errors) && !empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 animate__animated animate__headShake">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="submit_assignment.php?lesson_id=<?php echo $lesson_id; ?>" enctype="multipart/form-data" id="submissionForm">
                <div class="mb-6">
                    <label for="content" class="block text-gray-700 font-medium mb-2">Your Response</label>
                    <textarea id="content" name="content" rows="8" 
                              class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300"
                              placeholder="Type your answer here..."><?php echo isset($content) ? htmlspecialchars($content) : ''; ?></textarea>
                    <div class="flex justify-between mt-1">
                        <p class="text-sm text-gray-500">Explain your understanding, answer questions, or share your work.</p>
                        <p class="text-sm text-gray-500" id="charCount">0 characters</p>
                    </div>
                </div>
                
                <div class="mb-6">
                    <label for="submission_file" class="block text-gray-700 font-medium mb-2">Attachment (Optional)</label>
                    <div class="relative">
                        <input type="file" id="submission_file" name="submission_file" 
                               class="hidden" onchange="updateFileLabel(this)">
                        <div class="border border-gray-300 rounded-lg px-4 py-2 bg-white flex items-center justify-between">
                            <span id="file-name" class="text-gray-500">No file selected</span>
                            <label for="submission_file" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded cursor-pointer transition-colors duration-300">
                                Browse Files
                            </label>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">Upload documents, images, or other files related to your work. Max file size: 10MB.</p>
                </div>
                
                <div class="bg-green-50 p-4 rounded-lg mb-6 border border-green-100">
                    <h3 class="font-medium text-green-800 mb-2 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Submission Guidelines:
                    </h3>
                    <ul class="list-disc list-inside text-sm text-green-700 space-y-1">
                        <li>Your work will be submitted to your teacher for review and grading.</li>
                        <li>Make sure your submission is complete before submitting.</li>
                        <li>You can edit your submission later if needed.</li>
                        <li>Supported file types: PDF, DOC, DOCX, JPG, PNG, ZIP (check with your teacher for specific requirements).</li>
                    </ul>
                </div>
                
                <div class="flex flex-col sm:flex-row justify-between gap-4">
                    <a href="view_lesson.php?id=<?php echo $lesson_id; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg text-center transition-colors duration-300">
                        Cancel
                    </a>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg transform hover:scale-[1.02] transition-all duration-300 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                        Submit Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Add character counter for content
    document.addEventListener('DOMContentLoaded', function() {
        const contentArea = document.getElementById('content');
        const charCount = document.getElementById('charCount');
        
        // Initial count
        updateCharCount();
        
        contentArea.addEventListener('input', updateCharCount);
        
        function updateCharCount() {
            const count = contentArea.value.length;
            charCount.textContent = count + (count === 1 ? ' character' : ' characters');
        }
        
        // Add tab support (indent with tab key)
        contentArea.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                
                // Insert tab at cursor position
                const start = this.selectionStart;
                const end = this.selectionEnd;
                
                this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
                
                // Move cursor after the inserted tab
                this.selectionStart = this.selectionEnd = start + 4;
                
                // Update character count
                updateCharCount();
            }
        });
        
        // File type validation
        const fileInput = document.getElementById('submission_file');
        fileInput.addEventListener('change', function() {
            const allowedTypes = [
                'application/pdf', 
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'image/jpeg',
                'image/png',
                'application/zip'
            ];
            
            if (this.files.length > 0) {
                const fileType = this.files[0].type;
                if (!allowedTypes.includes(fileType)) {
                    Swal.fire({
                        title: 'Warning!',
                        text: 'You are uploading a file type that may not be accepted. Please check with your teacher for approved file types.',
                        icon: 'warning',
                        confirmButtonColor: '#16a34a'
                    });
                }
                
                // Check file size (10MB limit)
                const fileSize = this.files[0].size / 1024 / 1024; // convert to MB
                if (fileSize > 10) {
                    Swal.fire({
                        title: 'Error!',
                        text: 'File size exceeds 10MB limit. Please upload a smaller file.',
                        icon: 'error',
                        confirmButtonColor: '#16a34a'
                    });
                    this.value = '';
                    updateFileLabel(this);
                }
            }
        });
        
        // Submit form with validation
        const form = document.getElementById('submissionForm');
        form.addEventListener('submit', function(e) {
            const content = contentArea.value.trim();
            const fileInput = document.getElementById('submission_file');
            
            if (content === '' && fileInput.files.length === 0) {
                e.preventDefault();
                
                Swal.fire({
                    title: 'Missing Content',
                    text: 'Please provide either a written response or upload a file.',
                    icon: 'error',
                    confirmButtonColor: '#16a34a'
                });
            } else {
                // Show loading state
                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Submitting...';
            }
        });
    });
    
    // Update file input label with selected filename
    function updateFileLabel(input) {
        const fileName = document.getElementById('file-name');
        
        if (input.files.length > 0) {
            fileName.textContent = input.files[0].name;
            fileName.classList.remove('text-gray-500');
            fileName.classList.add('text-green-700', 'font-medium');
        } else {
            fileName.textContent = 'No file selected';
            fileName.classList.remove('text-green-700', 'font-medium');
            fileName.classList.add('text-gray-500');
        }
    }
</script>

<?php include 'includes/footer.php'; ?>