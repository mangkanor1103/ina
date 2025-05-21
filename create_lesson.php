<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to create a lesson.");
    redirect("login.php");
}

// Check if user is a teacher
if (!is_teacher() && !is_admin()) {
    set_flash_message("error", "Only teachers can create lessons.");
    redirect("index.php");
}

// Check if classroom ID is provided
if (!isset($_GET['classroom_id']) || !is_numeric($_GET['classroom_id'])) {
    set_flash_message("error", "Invalid classroom ID.");
    redirect("classrooms.php");
}

$classroom_id = $_GET['classroom_id'];
$user_id = $_SESSION['user_id'];

// Check if classroom exists and belongs to this teacher
$stmt = $conn->prepare("SELECT * FROM classrooms WHERE id = ?");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flash_message("error", "Classroom not found.");
    redirect("classrooms.php");
}

$classroom = $result->fetch_assoc();

// Check if the user has permission to add lessons to this classroom
if (!is_admin() && $classroom['teacher_id'] != $user_id) {
    set_flash_message("error", "You don't have permission to add lessons to this classroom.");
    redirect("classrooms.php");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = clean_input($_POST['title']);
    $description = clean_input($_POST['description']);
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    
    $errors = [];
    
    // Validate input
    if (empty($title)) {
        $errors[] = "Lesson title is required";
    }
    
    // If no errors, create the lesson
    if (empty($errors)) {
        // Initialize file path variable
        $file_path = null;
        
        // Handle file upload if present
        if (isset($_FILES['lesson_file']) && $_FILES['lesson_file']['error'] === 0) {
            $upload_dir = 'uploads/lessons/';
            
            // Ensure the upload directory exists
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['lesson_file']['name'], PATHINFO_EXTENSION);
            $file_name = generate_random_string(10) . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['lesson_file']['tmp_name'], $file_path)) {
                $errors[] = "Failed to upload file. Please try again.";
                $file_path = null;
            }
        }
        
        if (empty($errors)) {
            // Check if the lessons table exists and create it if it doesn't
            $table_exists = $conn->query("SHOW TABLES LIKE 'lessons'")->num_rows > 0;
            
            if (!$table_exists) {
                // Create the lessons table with necessary columns
                $create_table_sql = "CREATE TABLE lessons (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    classroom_id INT(11) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    content TEXT,
                    file_path VARCHAR(255),
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME,
                    PRIMARY KEY (id),
                    KEY classroom_id (classroom_id)
                )";
                
                $conn->query($create_table_sql);
            }
            
            // Now check which columns exist in the lessons table
            $check_content = $conn->query("SHOW COLUMNS FROM lessons LIKE 'content'");
            $content_exists = ($check_content->num_rows > 0);
            
            $check_file_path = $conn->query("SHOW COLUMNS FROM lessons LIKE 'file_path'");
            $file_path_exists = ($check_file_path->num_rows > 0);
            
            // If content column doesn't exist, add it
            if (!$content_exists) {
                $conn->query("ALTER TABLE lessons ADD COLUMN content TEXT AFTER description");
                $content_exists = true;
            }
            
            // If file_path column doesn't exist, add it
            if (!$file_path_exists) {
                $conn->query("ALTER TABLE lessons ADD COLUMN file_path VARCHAR(255) AFTER content");
                $file_path_exists = true;
            }
            
            // Now build the query using the columns we've ensured exist
            $stmt = $conn->prepare("INSERT INTO lessons (classroom_id, title, description, content, file_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("issss", $classroom_id, $title, $description, $content, $file_path);
            
            if ($stmt->execute()) {
                $lesson_id = $conn->insert_id;
                set_flash_message("success", "Lesson created successfully.");
                redirect("view_lesson.php?id=" . $lesson_id);
            } else {
                $errors[] = "Failed to create lesson. Please try again.";
            }
        }
    }
}
?>

<div class="mb-6">
    <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" class="text-indigo-600 hover:underline flex items-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        Back to Classroom
    </a>
</div>

<div class="bg-white rounded-lg shadow-md p-6">
    <h1 class="text-2xl font-bold mb-6">Create New Lesson</h1>
    
    <?php if (isset($errors) && !empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="create_lesson.php?classroom_id=<?php echo $classroom_id; ?>" enctype="multipart/form-data">
        <div class="mb-4">
            <label for="title" class="block text-gray-700 font-medium mb-2">Lesson Title <span class="text-red-500">*</span></label>
            <input type="text" id="title" name="title" value="<?php echo isset($title) ? htmlspecialchars($title) : ''; ?>" 
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
        </div>
        
        <div class="mb-4">
            <label for="description" class="block text-gray-700 font-medium mb-2">Brief Description</label>
            <textarea id="description" name="description" rows="2" 
                      class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
            <p class="text-sm text-gray-500 mt-1">A short summary of what this lesson covers.</p>
        </div>
        
        <div class="mb-4">
            <label for="content" class="block text-gray-700 font-medium mb-2">Lesson Content</label>
            <textarea id="content" name="content" rows="10" 
                      class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?php echo isset($content) ? htmlspecialchars($content) : ''; ?></textarea>
            <p class="text-sm text-gray-500 mt-1">The main content of your lesson. You can include detailed instructions, explanations, and assignment details.</p>
        </div>
        
        <div class="mb-6">
            <label for="lesson_file" class="block text-gray-700 font-medium mb-2">Attachment (Optional)</label>
            <input type="file" id="lesson_file" name="lesson_file" 
                   class="w-full px-4 py-2 border rounded-lg">
            <p class="text-sm text-gray-500 mt-1">Upload lesson materials, worksheets, or resources. Max file size: 10MB.</p>
        </div>
        
        <div class="flex justify-between">
            <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg">
                Create Lesson
            </button>
        </div>
    </form>
</div>

<script>
    // Add a simple content editor enhancement if needed
    document.addEventListener('DOMContentLoaded', function() {
        const contentArea = document.getElementById('content');
        
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
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>