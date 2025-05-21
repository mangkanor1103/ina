<?php
include 'includes/header.php';

// Check if user is logged in and is a teacher
if (!is_logged_in() || !has_role('teacher')) {
    set_flash_message("error", "You don't have permission to access this page.");
    redirect("index.php");
}

// Get teacher's classrooms
$teacher_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM classrooms WHERE teacher_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$classrooms = $result->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <div class="mb-8 flex flex-col md:flex-row md:justify-between md:items-center">
        <h1 class="text-3xl font-bold text-green-800 mb-4 md:mb-0 animate__animated animate__fadeInLeft">My Classrooms</h1>
        <a href="create_classroom.php" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg flex items-center justify-center transition-all duration-300 hover:shadow-lg transform hover:scale-[1.02] animate__animated animate__fadeInRight">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Create New Classroom
        </a>
    </div>

    <?php if (empty($classrooms)): ?>
        <div class="bg-green-50 border-l-4 border-green-400 p-6 rounded-lg shadow-md animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-green-700">
                        You haven't created any classrooms yet. Click "Create New Classroom" to get started.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Empty state illustration -->
        <div class="mt-12 text-center animate__animated animate__fadeIn" style="animation-delay: 0.4s;">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-32 w-32 mx-auto text-green-200 mb-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">Your Classroom Journey Starts Here</h3>
            <p class="text-gray-500 max-w-md mx-auto">Create your first virtual classroom to organize lessons, manage students, and share learning materials.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php 
            $delay = 0;
            foreach ($classrooms as $classroom): 
                $delay += 0.1;
            ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden hover-scale feature-card animate__animated animate__fadeInUp" style="animation-delay: <?php echo $delay; ?>s;">
                    <div class="bg-green-600 h-2 w-full"></div>
                    <div class="p-6">
                        <h2 class="text-xl font-bold mb-2 text-green-800"><?php echo htmlspecialchars($classroom['name']); ?></h2>
                        <p class="text-gray-600 mb-4">
                            <?php 
                            $description = htmlspecialchars($classroom['description']);
                            echo strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description;
                            ?>
                        </p>
                        
                        <?php
                        // Get student count for this classroom
                        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM classroom_students WHERE classroom_id = ?");
                        $stmt->bind_param("i", $classroom['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $student_count = $result->fetch_assoc()['count'];
                        
                        // Get lesson count for this classroom
                        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM lessons WHERE classroom_id = ?");
                        $stmt->bind_param("i", $classroom['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $lesson_count = $result->fetch_assoc()['count'];
                        ?>
                        
                        <div class="flex space-x-4 text-sm text-gray-500 mb-4">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <?php echo $student_count; ?> Students
                            </div>
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                                <?php echo $lesson_count; ?> Lessons
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row sm:justify-between space-y-2 sm:space-y-0 sm:space-x-2">
                            <a href="view_classroom.php?id=<?php echo $classroom['id']; ?>" 
                               class="bg-green-100 hover:bg-green-200 text-green-700 px-4 py-2 rounded-md text-sm flex items-center justify-center transition-colors duration-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                View Classroom
                            </a>
                            <a href="edit_classroom.php?id=<?php echo $classroom['id']; ?>" 
                               class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm flex items-center justify-center transition-colors duration-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                Edit
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Quick Stats Summary -->
        <div class="mt-12 bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.6s;">
            <h2 class="text-xl font-bold mb-4 text-green-800">Classroom Overview</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-green-50 p-4 rounded-lg">
                    <div class="text-4xl font-bold text-green-600 mb-2"><?php echo count($classrooms); ?></div>
                    <div class="text-gray-600">Active Classrooms</div>
                </div>
                
                <?php
                // Get total student count across all classrooms
                $stmt = $conn->prepare("SELECT COUNT(DISTINCT student_id) as count FROM classroom_students WHERE classroom_id IN (SELECT id FROM classrooms WHERE teacher_id = ?)");
                $stmt->bind_param("i", $teacher_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $total_students = $result->fetch_assoc()['count'];
                
                // Get total lesson count across all classrooms
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM lessons WHERE classroom_id IN (SELECT id FROM classrooms WHERE teacher_id = ?)");
                $stmt->bind_param("i", $teacher_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $total_lessons = $result->fetch_assoc()['count'];
                ?>
                
                <div class="bg-blue-50 p-4 rounded-lg">
                    <div class="text-4xl font-bold text-blue-600 mb-2"><?php echo $total_students; ?></div>
                    <div class="text-gray-600">Total Students</div>
                </div>
                
                <div class="bg-purple-50 p-4 rounded-lg">
                    <div class="text-4xl font-bold text-purple-600 mb-2"><?php echo $total_lessons; ?></div>
                    <div class="text-gray-600">Total Lessons</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Tips Section -->
    <div class="mt-8 bg-green-50 p-6 rounded-lg shadow-sm border border-green-100 animate__animated animate__fadeIn" style="animation-delay: 0.8s;">
        <h3 class="text-lg font-semibold text-green-800 mb-3 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Teacher Tips
        </h3>
        <ul class="text-green-700 space-y-2">
            <li class="flex items-start">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 flex-shrink-0 mt-0.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Create separate classrooms for different subjects or classes you teach.
            </li>
            <li class="flex items-start">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 flex-shrink-0 mt-0.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Regularly update lessons and learning materials to keep content fresh.
            </li>
            <li class="flex items-start">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 flex-shrink-0 mt-0.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Monitor student progress by checking submission rates and grades.
            </li>
        </ul>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add smooth hover transitions to classroom cards
    const classroomCards = document.querySelectorAll('.feature-card');
    classroomCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06)';
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>