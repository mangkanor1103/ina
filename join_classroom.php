<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to join a classroom.");
    redirect("login.php");
}

// Check if user is a student
if (!is_student()) {
    set_flash_message("error", "Only students can join classrooms.");
    redirect("index.php");
}

$user_id = $_SESSION['user_id'];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['classroom_id']) || !isset($_POST['enrollment_code'])) {
    set_flash_message("error", "Invalid request. Please try again.");
    redirect("my_classes.php");
}

$classroom_id = clean_input($_POST['classroom_id']);
$enrollment_code = clean_input($_POST['enrollment_code']);

// Validate classroom ID
if (!is_numeric($classroom_id)) {
    set_flash_message("error", "Invalid classroom ID.");
    redirect("my_classes.php");
}

// Check if the classroom exists
$stmt = $conn->prepare("SELECT * FROM classrooms WHERE id = ?");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flash_message("error", "Classroom not found.");
    redirect("my_classes.php");
}

$classroom = $result->fetch_assoc();

// Check if the enrollment code column exists in the database
$code_exists_query = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'enrollment_code'");
$enrollment_code_exists = ($code_exists_query->num_rows > 0);

// Verify the enrollment code if the column exists
if ($enrollment_code_exists) {
    if (empty($classroom['enrollment_code']) || $classroom['enrollment_code'] !== $enrollment_code) {
        set_flash_message("error", "Invalid enrollment code. Please check the code and try again.");
        redirect("my_classes.php");
    }
} else {
    // If enrollment_code column doesn't exist, allow enrollment without code verification
    // This is a fallback for compatibility
}

// Check if the student is already enrolled
$stmt = $conn->prepare("SELECT * FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
$stmt->bind_param("ii", $classroom_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    set_flash_message("info", "You are already enrolled in this classroom.");
    redirect("view_classroom.php?id=" . $classroom_id);
}

// Get teacher and classroom info for notification
$stmt = $conn->prepare("
    SELECT c.name as classroom_name, u.username as teacher_name, u.email as teacher_email 
    FROM classrooms c
    JOIN users u ON c.teacher_id = u.id
    WHERE c.id = ?
");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();
$info = $result->fetch_assoc();

// Get student info - check if full_name column exists
$check_full_name = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
$full_name_exists = ($check_full_name->num_rows > 0);

if ($full_name_exists) {
    $stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
} else {
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

// Use username as the student name if full_name doesn't exist or is empty
$student_name = ($full_name_exists && !empty($student['full_name'])) ? $student['full_name'] : $student['username'];

// Check if enrolled_at column exists in classroom_students table
$check_enrolled_at = $conn->query("SHOW COLUMNS FROM classroom_students LIKE 'enrolled_at'");
$enrolled_at_exists = ($check_enrolled_at->num_rows > 0);

// Enroll the student with or without the enrolled_at field
if ($enrolled_at_exists) {
    $stmt = $conn->prepare("INSERT INTO classroom_students (classroom_id, student_id, enrolled_at) VALUES (?, ?, NOW())");
    $stmt->bind_param("ii", $classroom_id, $user_id);
} else {
    $stmt = $conn->prepare("INSERT INTO classroom_students (classroom_id, student_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $classroom_id, $user_id);
}

if ($stmt->execute()) {
    // Successfully enrolled
    set_flash_message("success", "You have successfully joined " . htmlspecialchars($classroom['name']) . "!");
    
    // Create activity log entry
    $activity = "Student " . $student_name . " joined the classroom";
    
    // Check if the activity_log table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
    
    if ($table_exists) {
        $stmt = $conn->prepare("INSERT INTO activity_log (classroom_id, user_id, activity_type, description, created_at) VALUES (?, ?, 'enrollment', ?, NOW())");
        $stmt->bind_param("iis", $classroom_id, $user_id, $activity);
        $stmt->execute();
    }
    
    redirect("view_classroom.php?id=" . $classroom_id);
} else {
    // Enrollment failed
    set_flash_message("error", "Failed to join the classroom. Please try again.");
    redirect("my_classes.php");
}
?>

<div class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-md mt-8">
    <h2 class="text-2xl font-bold mb-6 text-center">Joining Classroom</h2>
    <p class="text-center mb-4">Processing your enrollment...</p>
    <div class="flex justify-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
    </div>
    <p class="text-center text-sm text-gray-500 mt-4">You'll be redirected automatically...</p>
</div>

<?php include 'includes/footer.php'; ?>