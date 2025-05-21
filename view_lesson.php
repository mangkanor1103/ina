<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view this lesson.");
    redirect("login.php");
}

// Check if lesson ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    set_flash_message("error", "Invalid lesson ID.");
    redirect("index.php");
}

$lesson_id = $_GET['id'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get lesson details with classroom and teacher info
$stmt = $conn->prepare("
    SELECT l.*, c.name as classroom_name, c.id as classroom_id, c.teacher_id, 
           u.username as teacher_username
    FROM lessons l
    JOIN classrooms c ON l.classroom_id = c.id
    JOIN users u ON c.teacher_id = u.id
    WHERE l.id = ?
");
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
$is_teacher = ($user_role === 'teacher' && $lesson['teacher_id'] == $user_id);
$is_admin = ($user_role === 'admin');

// Check if user has access to this lesson
$has_access = false;

if ($is_teacher || $is_admin) {
    $has_access = true;
} else if ($user_role === 'student') {
    // Check if student is enrolled in the classroom
    $stmt = $conn->prepare("SELECT * FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $classroom_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_access = ($result->num_rows > 0);
}

// Redirect if user doesn't have access
if (!$has_access) {
    set_flash_message("error", "You don't have permission to view this lesson.");
    redirect("index.php");
}

// Get student submission for this lesson (if student)
$submission = null;
if ($user_role === 'student') {
    $stmt = $conn->prepare("SELECT * FROM submissions WHERE lesson_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $lesson_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $submission = $result->fetch_assoc();
    }
}

// Get total number of submissions (if teacher)
$submission_count = 0;
$graded_count = 0;
if ($is_teacher || $is_admin) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM submissions WHERE lesson_id = ?");
    $stmt->bind_param("i", $lesson_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $submission_count = $result->fetch_assoc()['total'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as graded FROM submissions WHERE lesson_id = ? AND grade IS NOT NULL");
    $stmt->bind_param("i", $lesson_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $graded_count = $result->fetch_assoc()['graded'];
}
?>

<div class="mb-6">
    <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" class="text-indigo-600 hover:underline flex items-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        Back to <?php echo htmlspecialchars($lesson['classroom_name']); ?>
    </a>
</div>

<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <!-- Lesson Header -->
    <div class="bg-indigo-700 text-white p-6">
        <div class="flex justify-between items-start">
            <div>
                <h1 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($lesson['title']); ?></h1>
                <p class="text-indigo-100">
                    <span class="inline-block mr-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Posted: <?php echo isset($lesson['created_at']) ? format_date($lesson['created_at']) : 'N/A'; ?>
                    </span>
                    <span class="inline-block">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        By: <?php echo htmlspecialchars($lesson['teacher_username']); ?>
                    </span>
                </p>
            </div>
            
            <?php if ($is_teacher || $is_admin): ?>
            <div>
                <a href="edit_lesson.php?id=<?php echo $lesson_id; ?>" class="bg-white text-indigo-700 px-4 py-2 rounded-md inline-block hover:bg-indigo-50">
                    Edit Lesson
                </a>
                
                <?php if ($submission_count > 0): ?>
                <a href="lesson_submissions.php?id=<?php echo $lesson_id; ?>" class="ml-2 bg-white text-indigo-700 px-4 py-2 rounded-md inline-block hover:bg-indigo-50">
                    View Submissions (<?php echo $graded_count; ?>/<?php echo $submission_count; ?>)
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Lesson Content -->
    <div class="p-6">
        <?php if (!empty($lesson['description'])): ?>
        <div class="mb-6">
            <h2 class="text-lg font-semibold mb-2">Description</h2>
            <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($lesson['description'])); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if (isset($lesson['content']) && !empty($lesson['content'])): ?>
        <div class="mb-6">
            <h2 class="text-lg font-semibold mb-2">Lesson Content</h2>
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 prose max-w-none">
                <?php echo nl2br(htmlspecialchars($lesson['content'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (isset($lesson['file_path']) && !empty($lesson['file_path'])): ?>
        <div class="mb-6">
            <h2 class="text-lg font-semibold mb-2">Attachments</h2>
            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                <a href="<?php echo htmlspecialchars($lesson['file_path']); ?>" class="flex items-center text-blue-700 hover:text-blue-900" target="_blank">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <span>
                        Download Attachment
                        <span class="text-sm text-blue-500 ml-1">(Click to open or download)</span>
                    </span>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Student Submission Section -->
    <?php if ($user_role === 'student'): ?>
    <div class="border-t border-gray-200 p-6 bg-gray-50">
        <h2 class="text-xl font-bold mb-4">Your Submission</h2>
        
        <?php if ($submission): ?>
            <div class="bg-white p-4 rounded-lg border border-gray-200 mb-4">
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <p class="text-sm text-gray-500">
                            Submitted on: <?php echo format_date($submission['submitted_at']); ?>
                        </p>
                    </div>
                    
                    <div>
                        <?php if ($submission['grade'] !== null): ?>
                            <span class="bg-green-100 text-green-800 text-sm font-semibold px-3 py-1 rounded-full">
                                Grade: <?php echo $submission['grade']; ?>
                            </span>
                        <?php else: ?>
                            <span class="bg-yellow-100 text-yellow-800 text-sm font-semibold px-3 py-1 rounded-full">
                                Awaiting Feedback
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mb-3">
                    <h3 class="text-sm font-medium text-gray-700 mb-1">Your Answer:</h3>
                    <div class="bg-gray-50 p-3 rounded border border-gray-200">
                        <?php echo nl2br(htmlspecialchars($submission['content'])); ?>
                    </div>
                </div>
                
                <?php if (!empty($submission['file_path'])): ?>
                <div class="mb-3">
                    <h3 class="text-sm font-medium text-gray-700 mb-1">Your Attachment:</h3>
                    <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" class="text-blue-600 hover:underline flex items-center" target="_blank">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                        </svg>
                        View Attachment
                    </a>
                </div>
                <?php endif; ?>
                
                <?php if ($submission['grade'] !== null && !empty($submission['feedback'])): ?>
                <div>
                    <h3 class="text-sm font-medium text-gray-700 mb-1">Teacher Feedback:</h3>
                    <div class="bg-blue-50 p-3 rounded border border-blue-100 text-gray-700">
                        <?php echo nl2br(htmlspecialchars($submission['feedback'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="mt-4 flex justify-end">
                    <a href="edit_submission.php?id=<?php echo $submission['id']; ?>" class="bg-indigo-100 text-indigo-700 hover:bg-indigo-200 px-4 py-2 rounded-md text-sm">
                        Edit Submission
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white p-6 rounded-lg border border-gray-200 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Submission Yet</h3>
                <p class="text-gray-600 mb-6">You haven't submitted your work for this lesson yet.</p>
                <a href="submit_assignment.php?lesson_id=<?php echo $lesson_id; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg inline-block">
                    Submit Assignment
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>