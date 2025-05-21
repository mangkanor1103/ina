<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to delete lessons.");
    redirect("login.php");
}

// Check if user is a teacher or admin
if (!is_teacher() && !is_admin()) {
    set_flash_message("error", "Only teachers can delete lessons.");
    redirect("index.php");
}

// Check if lesson ID is provided
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['lesson_id']) || !is_numeric($_POST['lesson_id'])) {
    set_flash_message("error", "Invalid request.");
    redirect("classrooms.php");
}

$lesson_id = $_POST['lesson_id'];
$user_id = $_SESSION['user_id'];

// Get lesson details with classroom info
$stmt = $conn->prepare("
    SELECT l.*, c.teacher_id, c.id as classroom_id 
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

// Check if user has permission to delete this lesson
if (!is_admin() && $lesson['teacher_id'] != $user_id) {
    set_flash_message("error", "You don't have permission to delete this lesson.");
    redirect("view_classroom.php?id=" . $classroom_id);
}

// Begin transaction for safe deletion
$conn->begin_transaction();

try {
    // Delete all related submissions first
    $submissions_stmt = $conn->prepare("SELECT * FROM submissions WHERE lesson_id = ?");
    
    // Check if submissions table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;
    
    if ($table_exists) {
        $submissions_stmt->bind_param("i", $lesson_id);
        $submissions_stmt->execute();
        $submissions_result = $submissions_stmt->get_result();
        
        // Delete submission files and records
        while ($submission = $submissions_result->fetch_assoc()) {
            // Delete submission file if exists
            if (isset($submission['file_path']) && !empty($submission['file_path']) && file_exists($submission['file_path'])) {
                unlink($submission['file_path']);
            }
        }
        
        // Delete all submissions for this lesson
        $delete_submissions = $conn->prepare("DELETE FROM submissions WHERE lesson_id = ?");
        $delete_submissions->bind_param("i", $lesson_id);
        $delete_submissions->execute();
    }
    
    // Delete lesson file if exists
    if (isset($lesson['file_path']) && !empty($lesson['file_path']) && file_exists($lesson['file_path'])) {
        unlink($lesson['file_path']);
    }
    
    // Delete lesson record
    $delete_lesson = $conn->prepare("DELETE FROM lessons WHERE id = ?");
    $delete_lesson->bind_param("i", $lesson_id);
    $delete_lesson->execute();
    
    // Log the activity
    $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
    
    if ($activity_table_exists) {
        $activity = "Lesson '" . htmlspecialchars($lesson['title']) . "' was deleted";
        $log_activity = $conn->prepare("
            INSERT INTO activity_log (classroom_id, user_id, activity_type, description, created_at) 
            VALUES (?, ?, 'lesson_deleted', ?, NOW())
        ");
        $log_activity->bind_param("iis", $classroom_id, $user_id, $activity);
        $log_activity->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Show success message with SweetAlert
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: 'Deleted!',
                text: 'The lesson has been successfully deleted.',
                icon: 'success',
                confirmButtonColor: '#16a34a',
                timer: 2000,
                timerProgressBar: true,
                showClass: {
                    popup: 'animate__animated animate__fadeIn animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOut animate__faster'
                }
            }).then(() => {
                window.location.href = 'view_classroom.php?id=" . $classroom_id . "';
            });
        });
    </script>";
    
} catch (Exception $e) {
    // Roll back transaction if something went wrong
    $conn->rollback();
    
    // Show error message with SweetAlert
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: 'Error!',
                text: 'Failed to delete lesson: " . $e->getMessage() . "',
                icon: 'error',
                confirmButtonColor: '#16a34a',
                showClass: {
                    popup: 'animate__animated animate__fadeIn animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOut animate__faster'
                }
            }).then(() => {
                window.location.href = 'edit_lesson.php?id=" . $lesson_id . "';
            });
        });
    </script>";
}
?>

<div class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-md mt-8">
    <h2 class="text-2xl font-bold mb-6 text-center text-green-800">Processing Request</h2>
    <p class="text-center mb-4">Please wait while we process your deletion request...</p>
    <div class="flex justify-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-green-600"></div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>