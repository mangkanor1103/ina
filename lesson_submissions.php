<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view submissions.");
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

// Check if user has permission to view submissions
if (!$is_teacher && !$is_admin) {
    set_flash_message("error", "You don't have permission to view these submissions.");
    redirect("index.php");
}

// Get all submissions for this lesson
$stmt = $conn->prepare("
    SELECT s.*, u.username, u.email 
    FROM submissions s
    JOIN users u ON s.student_id = u.id
    WHERE s.lesson_id = ?
    ORDER BY s.submitted_at DESC
");
$stmt->bind_param("i", $lesson_id);
$stmt->execute();
$result = $stmt->get_result();
$submissions = $result->fetch_all(MYSQLI_ASSOC);

// Handle grading submission if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submission_id'], $_POST['grade'], $_POST['feedback'])) {
    $submission_id = clean_input($_POST['submission_id']);
    $grade = clean_input($_POST['grade']);
    $feedback = clean_input($_POST['feedback']);
    
    // Validate grade
    if (!is_numeric($grade) || $grade < 0 || $grade > 100) {
        set_flash_message("error", "Grade must be a number between 0 and 100.");
        redirect("lesson_submissions.php?id=" . $lesson_id);
    }
    
    // Update submission with grade and feedback
    $update_stmt = $conn->prepare("UPDATE submissions SET grade = ?, feedback = ?, graded_at = NOW(), graded_by = ? WHERE id = ?");
    $update_stmt->bind_param("dsis", $grade, $feedback, $user_id, $submission_id);
    
    if ($update_stmt->execute()) {
        set_flash_message("success", "Submission graded successfully.");
        redirect("lesson_submissions.php?id=" . $lesson_id);
    } else {
        set_flash_message("error", "Failed to grade submission. Please try again.");
    }
}
?>

<div class="mb-6">
    <a href="view_lesson.php?id=<?php echo $lesson_id; ?>" class="text-indigo-600 hover:underline flex items-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        Back to Lesson
    </a>
</div>

<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <!-- Lesson Header -->
    <div class="bg-indigo-700 text-white p-6">
        <h1 class="text-2xl font-bold mb-2">
            Submissions for: <?php echo htmlspecialchars($lesson['title']); ?>
        </h1>
        <p class="text-indigo-100">
            <span class="inline-block mr-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Classroom: <?php echo htmlspecialchars($lesson['classroom_name']); ?>
            </span>
            <span class="inline-block mr-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Total Submissions: <?php echo count($submissions); ?>
            </span>
            <?php if ($lesson['deadline']): ?>
                <span class="inline-block">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Deadline: <?php echo date('M d, Y H:i', strtotime($lesson['deadline'])); ?>
                    <?php
                    $now = new DateTime();
                    $deadline = new DateTime($lesson['deadline']);
                    $is_overdue = $now > $deadline;
                    ?>
                    <?php if ($is_overdue): ?>
                        <span class="bg-red-500 text-white px-2 py-1 rounded-full text-xs ml-2">OVERDUE</span>
                    <?php else: ?>
                        <span class="bg-green-500 text-white px-2 py-1 rounded-full text-xs ml-2">ACTIVE</span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </p>
    </div>
    
    <!-- Submissions List -->
    <div class="p-6">
        <?php if (empty($submissions)): ?>
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h2 class="text-xl font-medium text-gray-900 mb-2">No submissions yet</h2>
                <p class="text-gray-600">No students have submitted work for this lesson yet.</p>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php 
                // Count stats
                $graded_count = 0;
                $ungraded_count = 0;
                foreach ($submissions as $sub) {
                    if ($sub['grade'] !== null) {
                        $graded_count++;
                    } else {
                        $ungraded_count++;
                    }
                }
                ?>
                
                <!-- Stats Summary -->
                <div class="flex flex-wrap gap-4 mb-6">
                    <div class="bg-blue-50 p-4 rounded-lg flex-1">
                        <div class="text-blue-800 text-sm font-semibold">Total Submissions</div>
                        <div class="text-2xl font-bold"><?php echo count($submissions); ?></div>
                    </div>
                    
                    <div class="bg-green-50 p-4 rounded-lg flex-1">
                        <div class="text-green-800 text-sm font-semibold">Graded</div>
                        <div class="text-2xl font-bold"><?php echo $graded_count; ?></div>
                    </div>
                    
                    <div class="bg-yellow-50 p-4 rounded-lg flex-1">
                        <div class="text-yellow-800 text-sm font-semibold">Awaiting Feedback</div>
                        <div class="text-2xl font-bold"><?php echo $ungraded_count; ?></div>
                    </div>
                </div>
                
                <!-- Submission Cards -->
                <?php foreach ($submissions as $submission): ?>
                    <div class="border rounded-lg overflow-hidden mb-6">
                        <!-- Submission Header -->
                        <div class="bg-gray-50 p-4 flex justify-between items-center border-b">
                            <div>
                                <div class="font-medium"><?php echo htmlspecialchars($submission['username']); ?></div>
                                <div class="text-sm text-gray-500">
                                    Submitted: <?php echo format_date($submission['submitted_at']); ?>
                                </div>
                            </div>
                            
                            <div>
                                <?php if ($submission['grade'] !== null): ?>
                                    <span class="bg-green-100 text-green-800 text-sm font-semibold px-3 py-1 rounded-full">
                                        Grade: <?php echo $submission['grade']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="bg-yellow-100 text-yellow-800 text-sm font-semibold px-3 py-1 rounded-full">
                                        Not Graded
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Submission Content -->
                        <div class="p-4">
                            <div class="mb-4">
                                <h3 class="font-medium text-gray-700 mb-1">Student Response:</h3>
                                <div class="bg-gray-50 p-3 rounded border border-gray-200">
                                    <?php echo nl2br(htmlspecialchars($submission['content'])); ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($submission['file_path'])): ?>
                            <div class="mb-4">
                                <h3 class="font-medium text-gray-700 mb-1">Attachment:</h3>
                                <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" class="text-blue-600 hover:underline flex items-center" target="_blank">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                    </svg>
                                    View Attachment
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Feedback Section -->
                            <form method="POST" action="lesson_submissions.php?id=<?php echo $lesson_id; ?>" class="mt-4 border-t pt-4">
                                <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                                
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                                    <div class="md:col-span-1">
                                        <label for="grade-<?php echo $submission['id']; ?>" class="block text-sm font-medium text-gray-700 mb-1">Grade (0-100):</label>
                                        <input type="number" id="grade-<?php echo $submission['id']; ?>" name="grade" 
                                               value="<?php echo $submission['grade'] !== null ? $submission['grade'] : ''; ?>" 
                                               class="border rounded px-3 py-2 w-full" min="0" max="100">
                                    </div>
                                    
                                    <div class="md:col-span-3">
                                        <label for="feedback-<?php echo $submission['id']; ?>" class="block text-sm font-medium text-gray-700 mb-1">Feedback:</label>
                                        <textarea id="feedback-<?php echo $submission['id']; ?>" name="feedback" rows="3" 
                                                  class="border rounded px-3 py-2 w-full"><?php echo htmlspecialchars($submission['feedback'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                
                                <div class="flex justify-end">
                                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded">
                                        <?php echo $submission['grade'] !== null ? 'Update Feedback' : 'Submit Feedback'; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>