<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to edit lessons.");
    redirect("login.php");
}

// Check if user is a teacher or admin
if (!is_teacher() && !is_admin()) {
    set_flash_message("error", "Only teachers can edit lessons.");
    redirect("index.php");
}

// Check if lesson ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    set_flash_message("error", "Invalid lesson ID.");
    redirect("classrooms.php");
}

$lesson_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Get lesson details with classroom info
$stmt = $conn->prepare("
    SELECT l.*, c.name as classroom_name, c.id as classroom_id, c.teacher_id 
    FROM lessons l
    JOIN classrooms c ON l.classroom_id = c.id
    WHERE l.id = ?
");
$stmt->bind_param("i", $lesson_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if lesson exists
if ($result->num_rows === 0) {
    set_flash_message("error", "Lesson not found.");
    redirect("classrooms.php");
}

$lesson = $result->fetch_assoc();
$classroom_id = $lesson['classroom_id'];

// Check if user has permission to edit this lesson
if (!is_admin() && $lesson['teacher_id'] != $user_id) {
    set_flash_message("error", "You don't have permission to edit this lesson.");
    redirect("view_classroom.php?id=" . $classroom_id);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = clean_input($_POST['title']);
    $description = clean_input($_POST['description']);
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    $deadline = !empty($_POST['deadline']) ? clean_input($_POST['deadline']) : null;
    
    $errors = [];
    
    // Validate input
    if (empty($title)) {
        $errors[] = "Lesson title is required";
    }
    
    // If no errors, update the lesson
    if (empty($errors)) {
        // Check if the lesson table has the necessary columns
        $check_content = $conn->query("SHOW COLUMNS FROM lessons LIKE 'content'");
        $content_exists = ($check_content->num_rows > 0);
        
        $check_file_path = $conn->query("SHOW COLUMNS FROM lessons LIKE 'file_path'");
        $file_path_exists = ($check_file_path->num_rows > 0);
        
        $check_deadline = $conn->query("SHOW COLUMNS FROM lessons LIKE 'deadline'");
        $deadline_exists = ($check_deadline->num_rows > 0);
        
        // Add missing columns if needed
        if (!$content_exists) {
            $conn->query("ALTER TABLE lessons ADD COLUMN content TEXT AFTER description");
            $content_exists = true;
        }
        
        if (!$file_path_exists) {
            $conn->query("ALTER TABLE lessons ADD COLUMN file_path VARCHAR(255) AFTER content");
            $file_path_exists = true;
        }
        
        if (!$deadline_exists) {
            $conn->query("ALTER TABLE lessons ADD COLUMN deadline DATETIME NULL AFTER content");
            $deadline_exists = true;
        }
        
        // Check if updated_at column exists and add it if missing
        $check_updated_at = $conn->query("SHOW COLUMNS FROM lessons LIKE 'updated_at'");
        $updated_at_exists = ($check_updated_at->num_rows > 0);
        
        if (!$updated_at_exists) {
            $conn->query("ALTER TABLE lessons ADD COLUMN updated_at DATETIME NULL");
            $updated_at_exists = true;
        }
        
        // Handle file upload if present
        $file_path = $lesson['file_path'] ?? null;
        $new_file_uploaded = false;
        
        if (isset($_FILES['lesson_file']) && $_FILES['lesson_file']['error'] === 0) {
            $upload_dir = 'uploads/lessons/';
            
            // Ensure the upload directory exists
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['lesson_file']['name'], PATHINFO_EXTENSION);
            $file_name = generate_random_string(10) . '_' . time() . '.' . $file_extension;
            $new_file_path = $upload_dir . $file_name;
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['lesson_file']['tmp_name'], $new_file_path)) {
                // Delete old file if exists
                if (!empty($file_path) && file_exists($file_path)) {
                    unlink($file_path);
                }
                $file_path = $new_file_path;
                $new_file_uploaded = true;
            } else {
                $errors[] = "Failed to upload file. Please try again.";
            }
        }
        
        // Handle file deletion if requested
        if (isset($_POST['delete_file']) && $_POST['delete_file'] === '1' && !$new_file_uploaded) {
            if (!empty($file_path) && file_exists($file_path)) {
                unlink($file_path);
            }
            $file_path = null;
        }
        
        if (empty($errors)) {
            // Update the lesson with deadline - modify the queries to handle updated_at column
            if ($content_exists && $file_path_exists && $deadline_exists && $updated_at_exists) {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ?, content = ?, deadline = ?, file_path = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssssi", $title, $description, $content, $deadline, $file_path, $lesson_id);
            } elseif ($content_exists && $deadline_exists && $updated_at_exists) {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ?, content = ?, deadline = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssi", $title, $description, $content, $deadline, $lesson_id);
            } elseif ($content_exists && $file_path_exists && $deadline_exists) {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ?, content = ?, deadline = ?, file_path = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $title, $description, $content, $deadline, $file_path, $lesson_id);
            } elseif ($content_exists && $deadline_exists) {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ?, content = ?, deadline = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $title, $description, $content, $deadline, $lesson_id);
            } elseif ($content_exists && $updated_at_exists) {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ?, content = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssi", $title, $description, $content, $lesson_id);
            } elseif ($content_exists) {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ?, content = ? WHERE id = ?");
                $stmt->bind_param("sssi", $title, $description, $content, $lesson_id);
            } elseif ($updated_at_exists) {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssi", $title, $description, $lesson_id);
            } else {
                $stmt = $conn->prepare("UPDATE lessons SET title = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssi", $title, $description, $lesson_id);
            }
            
            if ($stmt->execute()) {
                set_flash_message("success", "Lesson updated successfully.");
                redirect("view_lesson.php?id=" . $lesson_id);
            } else {
                $errors[] = "Failed to update lesson. Please try again.";
            }
        }
    }
}
?>

<div class="mb-6">
    <a href="view_lesson.php?id=<?php echo $lesson_id; ?>" class="text-green-600 hover:underline flex items-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        Back to Lesson
    </a>
</div>

<div class="bg-white rounded-lg shadow-md p-6 hover-scale" style="transition-delay: 0.1s;">
    <h1 class="text-2xl font-bold mb-6 text-green-800">Edit Lesson</h1>
    
    <?php if (isset($errors) && !empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 animate__animated animate__fadeIn">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="edit_lesson.php?id=<?php echo $lesson_id; ?>" enctype="multipart/form-data" id="editLessonForm">
        <div class="mb-4">
            <label for="title" class="block text-gray-700 font-medium mb-2">Lesson Title <span class="text-red-500">*</span></label>
            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($lesson['title']); ?>" 
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300" required>
        </div>
        
        <div class="mb-4">
            <label for="description" class="block text-gray-700 font-medium mb-2">Brief Description</label>
            <textarea id="description" name="description" rows="2" 
                      class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300"><?php echo htmlspecialchars($lesson['description'] ?? ''); ?></textarea>
            <p class="text-sm text-gray-500 mt-1">A short summary of what this lesson covers.</p>
        </div>
        
        <div class="mb-4">
            <label for="content" class="block text-gray-700 font-medium mb-2">Lesson Content</label>
            <textarea id="content" name="content" rows="10" 
                      class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300"><?php echo htmlspecialchars($lesson['content'] ?? ''); ?></textarea>
            <p class="text-sm text-gray-500 mt-1">The main content of your lesson. You can include detailed instructions, explanations, and assignment details.</p>
        </div>
        
        <!-- Add deadline field -->
        <div class="mb-4">
            <label for="deadline" class="block text-gray-700 font-medium mb-2">
                Submission Deadline
                <span class="text-gray-500 font-normal">(Optional)</span>
            </label>
            <input type="datetime-local" id="deadline" name="deadline" 
                   value="<?php echo isset($lesson['deadline']) && $lesson['deadline'] ? date('Y-m-d\TH:i', strtotime($lesson['deadline'])) : ''; ?>"
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300">
            <p class="text-sm text-gray-500 mt-1">Set a deadline for student submissions. Leave empty for no deadline.</p>
            
            <?php if (isset($lesson['deadline']) && $lesson['deadline']): ?>
                <?php
                $now = new DateTime();
                $deadline = new DateTime($lesson['deadline']);
                $is_overdue = $now > $deadline;
                $time_diff = $deadline->diff($now);
                ?>
                <div class="mt-2 p-2 rounded-md <?php echo $is_overdue ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700'; ?>">
                    <div class="flex items-center text-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="font-medium">Current deadline: </span>
                        <span class="ml-1"><?php echo $deadline->format('M d, Y H:i'); ?></span>
                        <?php if ($is_overdue): ?>
                            <span class="ml-2 bg-red-200 text-red-800 px-2 py-1 rounded-full text-xs font-semibold">OVERDUE</span>
                        <?php elseif ($time_diff->days <= 1): ?>
                            <span class="ml-2 bg-yellow-200 text-yellow-800 px-2 py-1 rounded-full text-xs font-semibold">DUE SOON</span>
                        <?php else: ?>
                            <span class="ml-2 bg-green-200 text-green-800 px-2 py-1 rounded-full text-xs font-semibold">ACTIVE</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$is_overdue): ?>
                        <div class="text-xs mt-1">
                            Time remaining: 
                            <?php 
                            if ($time_diff->days > 0) {
                                echo $time_diff->days . ' days';
                            } elseif ($time_diff->h > 0) {
                                echo $time_diff->h . ' hours';
                            } else {
                                echo $time_diff->i . ' minutes';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="mb-6">
            <label class="block text-gray-700 font-medium mb-2">Attachment</label>
            
            <?php if (isset($lesson['file_path']) && !empty($lesson['file_path'])): ?>
                <div class="mb-3 flex items-center bg-green-50 p-3 rounded-lg animate__animated animate__fadeIn">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <div class="flex-grow">
                        <div class="font-medium">Current attachment:</div>
                        <a href="<?php echo htmlspecialchars($lesson['file_path']); ?>" target="_blank" class="text-green-600 hover:underline text-sm">
                            <?php echo basename($lesson['file_path']); ?>
                        </a>
                    </div>
                    <div>
                        <label for="delete_file" class="flex items-center text-red-600 cursor-pointer">
                            <input type="checkbox" id="delete_file" name="delete_file" value="1" class="mr-2">
                            <span>Delete</span>
                        </label>
                    </div>
                </div>
            <?php endif; ?>
            
            <input type="file" id="lesson_file" name="lesson_file" 
                   class="w-full px-4 py-2 border rounded-lg transition-all duration-300">
            <p class="text-sm text-gray-500 mt-1">
                <?php if (isset($lesson['file_path']) && !empty($lesson['file_path'])): ?>
                    Upload a new file to replace the current one, or check "Delete" to remove it.
                <?php else: ?>
                    Upload lesson materials, worksheets, or resources. Max file size: 10MB.
                <?php endif; ?>
            </p>
        </div>
        
        <div class="flex justify-between">
            <a href="view_lesson.php?id=<?php echo $lesson_id; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg transition-all duration-300">
                Cancel
            </a>
            <div>
                <button type="button" onclick="confirmLessonDelete(<?php echo $lesson_id; ?>)" class="bg-red-100 hover:bg-red-200 text-red-700 px-5 py-2 rounded-lg mr-2 transition-all duration-300">
                    Delete
                </button>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg transition-all duration-300">
                    Save Changes
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Hidden delete form -->
<form id="delete-form" method="POST" action="delete_lesson.php" style="display: none;">
    <input type="hidden" id="delete_lesson_id" name="lesson_id">
    <input type="hidden" name="classroom_id" value="<?php echo $classroom_id; ?>">
</form>

<script>
    // Add a simple content editor enhancement
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
        
        // Form submission confirmation
        document.getElementById('editLessonForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            Swal.fire({
                title: 'Save Changes?',
                text: 'Are you sure you want to save these changes?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, save changes',
                showClass: {
                    popup: 'animate__animated animate__fadeIn animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOut animate__faster'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });
    });
    
    function confirmLessonDelete(lessonId) {
        Swal.fire({
            title: 'Delete Lesson?',
            text: 'This will permanently delete the lesson and all student submissions. This action cannot be undone!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, delete it!',
            showClass: {
                popup: 'animate__animated animate__fadeIn animate__faster'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOut animate__faster'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_lesson_id').value = lessonId;
                document.getElementById('delete-form').submit();
            }
        });
    }
    
    // Show warning when deleting attachment
    document.getElementById('delete_file')?.addEventListener('change', function() {
        if (this.checked) {
            Swal.fire({
                title: 'Delete Attachment?',
                text: 'Are you sure you want to delete the current attachment?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it',
                showClass: {
                    popup: 'animate__animated animate__fadeIn animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOut animate__faster'
                }
            }).then((result) => {
                if (!result.isConfirmed) {
                    this.checked = false;
                }
            });
        }
    });
</script>

<?php include 'includes/footer.php'; ?>