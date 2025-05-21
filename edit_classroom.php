<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to access this page.");
    redirect("login.php");
}

// Check if user is a teacher or admin
if (!is_teacher() && !is_admin()) {
    set_flash_message("error", "You don't have permission to edit classrooms.");
    redirect("index.php");
}

// Check if classroom ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    set_flash_message("error", "Invalid classroom ID.");
    redirect("classrooms.php");
}

$classroom_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Get classroom details
$stmt = $conn->prepare("SELECT * FROM classrooms WHERE id = ?");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if classroom exists
if ($result->num_rows === 0) {
    set_flash_message("error", "Classroom not found.");
    redirect("classrooms.php");
}

$classroom = $result->fetch_assoc();

// Check if user has permission to edit this classroom
if (!is_admin() && $classroom['teacher_id'] != $user_id) {
    set_flash_message("error", "You don't have permission to edit this classroom.");
    redirect("classrooms.php");
}

// Check if enrollment_code column exists
$enrollment_code_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'enrollment_code'");
if ($check_column->num_rows > 0) {
    $enrollment_code_exists = true;
    
    // Generate enrollment code if it doesn't exist
    if (empty($classroom['enrollment_code'])) {
        $enrollment_code = strtoupper(generate_random_string(8));
        $stmt = $conn->prepare("UPDATE classrooms SET enrollment_code = ? WHERE id = ?");
        $stmt->bind_param("si", $enrollment_code, $classroom_id);
        $stmt->execute();
        $classroom['enrollment_code'] = $enrollment_code;
    }
} else {
    // Add enrollment_code column to classrooms table
    $add_column = $conn->query("ALTER TABLE classrooms ADD COLUMN enrollment_code VARCHAR(10) AFTER description");
    if ($add_column) {
        $enrollment_code_exists = true;
        
        // Generate enrollment code
        $enrollment_code = strtoupper(generate_random_string(8));
        $stmt = $conn->prepare("UPDATE classrooms SET enrollment_code = ? WHERE id = ?");
        $stmt->bind_param("si", $enrollment_code, $classroom_id);
        $stmt->execute();
        $classroom['enrollment_code'] = $enrollment_code;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean_input($_POST['name']);
    $description = clean_input($_POST['description']);
    
    $errors = [];
    
    // Validate input
    if (empty($name)) {
        $errors[] = "Classroom name is required";
    }
    
    // If no errors, update classroom
    if (empty($errors)) {
        if ($enrollment_code_exists && isset($_POST['regenerate_code'])) {
            $enrollment_code = strtoupper(generate_random_string(8));
            $stmt = $conn->prepare("UPDATE classrooms SET name = ?, description = ?, enrollment_code = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $name, $description, $enrollment_code, $classroom_id);
        } else if ($enrollment_code_exists) {
            $stmt = $conn->prepare("UPDATE classrooms SET name = ?, description = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssi", $name, $description, $classroom_id);
        } else {
            $stmt = $conn->prepare("UPDATE classrooms SET name = ?, description = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssi", $name, $description, $classroom_id);
        }
        
        if ($stmt->execute()) {
            set_flash_message("success", "Classroom updated successfully.");
            redirect("view_classroom.php?id=" . $classroom_id);
        } else {
            $errors[] = "Failed to update classroom. Please try again.";
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
    <h1 class="text-2xl font-bold mb-6">Edit Classroom</h1>
    
    <?php if (isset($errors) && !empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="edit_classroom.php?id=<?php echo $classroom_id; ?>">
        <div class="mb-4">
            <label for="name" class="block text-gray-700 font-medium mb-2">Classroom Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($classroom['name']); ?>" 
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
        </div>
        
        <div class="mb-4">
            <label for="description" class="block text-gray-700 font-medium mb-2">Description</label>
            <textarea id="description" name="description" rows="4" 
                      class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?php echo htmlspecialchars($classroom['description']); ?></textarea>
            <p class="text-sm text-gray-500 mt-1">Provide details about the classroom, subject matter, or any other relevant information.</p>
        </div>
        
        <?php if ($enrollment_code_exists): ?>
        <div class="mb-6">
            <label class="block text-gray-700 font-medium mb-2">Enrollment Code</label>
            <div class="flex items-center">
                <input type="text" value="<?php echo htmlspecialchars($classroom['enrollment_code']); ?>" 
                       class="px-4 py-2 border rounded-lg bg-gray-100 text-gray-800 font-medium" readonly>
                <button type="submit" name="regenerate_code" value="1" 
                        class="ml-2 bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm">
                    Regenerate Code
                </button>
            </div>
            <p class="text-sm text-gray-500 mt-1">Students use this code to join your classroom. Regenerating will invalidate the current code.</p>
        </div>
        <?php endif; ?>
        
        <div class="flex justify-between">
            <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg">
                Cancel
            </a>
            <div>
                <a href="#" onclick="confirmDelete(<?php echo $classroom_id; ?>); return false;" class="bg-red-100 hover:bg-red-200 text-red-700 px-5 py-2 rounded-lg mr-2">
                    Delete
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg">
                    Save Changes
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-xl font-bold mb-4">Delete Classroom</h3>
        <p class="mb-6">Are you sure you want to delete this classroom? This action cannot be undone and will remove all lessons and student data associated with this classroom.</p>
        
        <div class="flex justify-end space-x-3">
            <button onclick="hideDeleteModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md">
                Cancel
            </button>
            <form id="delete-form" method="POST" action="delete_classroom.php">
                <input type="hidden" id="delete_classroom_id" name="classroom_id">
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    function confirmDelete(classroomId) {
        document.getElementById('delete_classroom_id').value = classroomId;
        document.getElementById('delete-modal').classList.remove('hidden');
    }
    
    function hideDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }
    
    // Close modal when clicking outside
    document.getElementById('delete-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideDeleteModal();
        }
    });
</script>

<?php include 'includes/footer.php'; ?>