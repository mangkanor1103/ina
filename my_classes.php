<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view your classes.");
    redirect("login.php");
}

// Check if user is a student
if (!is_student()) {
    set_flash_message("error", "This page is only for students.");
    redirect("index.php");
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Get all classrooms the student is enrolled in
$stmt = $conn->prepare("
    SELECT c.*, u.username as teacher_name 
    FROM classrooms c
    JOIN classroom_students cs ON c.id = cs.classroom_id
    JOIN users u ON c.teacher_id = u.id
    WHERE cs.student_id = ?
    ORDER BY c.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$classrooms = $result->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <div class="mb-8 flex flex-col md:flex-row md:justify-between md:items-center">
        <div class="animate__animated animate__fadeInLeft">
            <h1 class="text-3xl font-bold text-green-800 mb-2">My Classes</h1>
            <p class="text-gray-600">Welcome, <?php echo htmlspecialchars($username); ?>! Here are the classes you're enrolled in.</p>
        </div>
        
        <div class="mt-4 md:mt-0 flex flex-wrap gap-2 animate__animated animate__fadeInRight">
            <a href="#available-classrooms" class="bg-green-100 hover:bg-green-200 text-green-800 px-4 py-2 rounded-lg transition-all duration-300 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>
                Browse Available Classes
            </a>
            
            <a href="#" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition-all duration-300 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                View All Assignments
            </a>
        </div>
    </div>

    <?php if (empty($classrooms)): ?>
        <div class="bg-green-50 border-l-4 border-green-400 p-6 rounded-lg shadow-md animate__animated animate__fadeIn">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-green-700">
                        You are not enrolled in any classes yet. Scroll down to see available classrooms to join, or contact your teacher for an enrollment code.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Empty state illustration -->
        <div class="mt-12 text-center animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-32 w-32 mx-auto text-green-200 mb-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 6v6m0 0v6m0-6h6m-6 0H6m6 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">Start Your Learning Journey</h3>
            <p class="text-gray-500 max-w-md mx-auto">Join your first class to begin accessing lessons, assignments, and educational resources.</p>
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
                        <p class="text-gray-600 mb-4 text-sm flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <span class="font-medium">Teacher:</span> <?php echo htmlspecialchars($classroom['teacher_name']); ?>
                        </p>
                        
                        <div class="h-24 overflow-hidden text-gray-600 text-sm mb-4">
                            <?php if (!empty($classroom['description'])): ?>
                                <p><?php echo nl2br(htmlspecialchars(substr($classroom['description'], 0, 150))); ?><?php echo (strlen($classroom['description']) > 150) ? '...' : ''; ?></p>
                            <?php else: ?>
                                <p class="text-gray-500 italic">No description provided.</p>
                            <?php endif; ?>
                        </div>
                        
                        <?php
                        // Get lessons count for the classroom
                        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM lessons WHERE classroom_id = ?");
                        $stmt->bind_param("i", $classroom['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $lesson_count = $result->fetch_assoc()['count'];
                        
                        // Get pending submissions count
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as count 
                            FROM lessons l
                            LEFT JOIN submissions s ON l.id = s.lesson_id AND s.student_id = ?
                            WHERE l.classroom_id = ? AND s.id IS NULL
                        ");
                        $stmt->bind_param("ii", $user_id, $classroom['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $pending_submissions = $result->fetch_assoc()['count'];
                        ?>
                        
                        <div class="flex space-x-4 text-sm text-gray-500 mb-4">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                                <?php echo $lesson_count; ?> Lessons
                            </div>
                            
                            <?php if ($pending_submissions > 0): ?>
                                <div class="flex items-center text-amber-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <?php echo $pending_submissions; ?> Pending
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between space-y-2 sm:space-y-0">
                            <a href="view_classroom.php?id=<?php echo $classroom['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md flex items-center justify-center transition-all duration-300 transform hover:scale-[1.02]">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                View Classroom
                            </a>
                            
                            <?php if ($pending_submissions > 0): ?>
                                <span class="bg-amber-100 text-amber-800 text-xs font-medium px-2.5 py-1.5 rounded-full flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    Assignments Due
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Quick Stats -->
        <div class="mt-12 bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.6s;">
            <h2 class="text-xl font-bold mb-4 text-green-800">Your Progress at a Glance</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php
                // Get total lessons across all enrolled classrooms
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM lessons 
                    WHERE classroom_id IN (
                        SELECT classroom_id FROM classroom_students WHERE student_id = ?
                    )
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $total_lessons = $result->fetch_assoc()['count'];
                
                // Get completed assignments count
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM submissions 
                    WHERE student_id = ?
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $completed_assignments = $result->fetch_assoc()['count'];
                
                // Get total assignments count
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM lessons 
                    WHERE classroom_id IN (
                        SELECT classroom_id FROM classroom_students WHERE student_id = ?
                    )
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $total_assignments = $result->fetch_assoc()['count'];
                
                // Calculate completion percentage
                $completion_percentage = ($total_assignments > 0) ? round(($completed_assignments / $total_assignments) * 100) : 0;
                ?>
                
                <div class="bg-green-50 p-4 rounded-lg">
                    <div class="text-4xl font-bold text-green-600 mb-2"><?php echo count($classrooms); ?></div>
                    <div class="text-gray-600">Enrolled Classes</div>
                </div>
                
                <div class="bg-blue-50 p-4 rounded-lg">
                    <div class="text-4xl font-bold text-blue-600 mb-2"><?php echo $total_lessons; ?></div>
                    <div class="text-gray-600">Total Lessons</div>
                </div>
                
                <div class="bg-purple-50 p-4 rounded-lg">
                    <div class="text-4xl font-bold text-purple-600 mb-2"><?php echo $completion_percentage; ?>%</div>
                    <div class="text-gray-600">Completion Rate</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // Check if there are available classrooms to join (not yet enrolled)
    $stmt = $conn->prepare("
        SELECT c.*, u.username as teacher_name 
        FROM classrooms c
        JOIN users u ON c.teacher_id = u.id
        WHERE c.id NOT IN (
            SELECT classroom_id FROM classroom_students WHERE student_id = ?
        )
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $available_classrooms = $result->fetch_all(MYSQLI_ASSOC);

    if (!empty($available_classrooms)):
    ?>
        <div id="available-classrooms" class="mt-12 pt-8 border-t border-gray-200 animate__animated animate__fadeIn" style="animation-delay: 0.8s;">
            <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-green-800 mb-2">Available Classrooms</h2>
                    <p class="text-gray-600">These classrooms are available for you to join. Contact your teacher for the enrollment code.</p>
                </div>
                
                <?php if (count($available_classrooms) > 5): ?>
                    <a href="browse_classrooms.php" class="mt-4 md:mt-0 inline-flex items-center text-green-600 hover:text-green-800">
                        View All Available Classes
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php 
                $delay = 0.8;
                foreach ($available_classrooms as $classroom): 
                    $delay += 0.1;
                ?>
                    <div class="bg-green-50 rounded-lg shadow-sm overflow-hidden hover-scale feature-card animate__animated animate__fadeInUp border border-green-100" style="animation-delay: <?php echo $delay; ?>s;">
                        <div class="p-6">
                            <h2 class="text-xl font-bold mb-2 text-green-800"><?php echo htmlspecialchars($classroom['name']); ?></h2>
                            <p class="text-gray-600 mb-4 text-sm flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <span class="font-medium">Teacher:</span> <?php echo htmlspecialchars($classroom['teacher_name']); ?>
                            </p>
                            
                            <div class="h-20 overflow-hidden text-gray-600 text-sm mb-4">
                                <?php if (!empty($classroom['description'])): ?>
                                    <p><?php echo nl2br(htmlspecialchars(substr($classroom['description'], 0, 100))); ?><?php echo (strlen($classroom['description']) > 100) ? '...' : ''; ?></p>
                                <?php else: ?>
                                    <p class="text-gray-500 italic">No description provided.</p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-4">
                                <button type="button" 
                                        onclick="showEnrollModal('<?php echo htmlspecialchars(addslashes($classroom['name'])); ?>', <?php echo $classroom['id']; ?>)" 
                                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md w-full flex items-center justify-center transition-all duration-300 transform hover:scale-[1.02]">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                                    </svg>
                                    Join Class
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Enrollment Modal -->
        <div id="enrollment-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6 animate__animated animate__fadeInDown">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-green-800" id="modal-title">Join Class</h3>
                    <button type="button" onclick="hideEnrollModal()" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                
                <form id="enrollment-form" method="POST" action="join_classroom.php">
                    <input type="hidden" id="classroom_id" name="classroom_id">
                    
                    <div class="mb-6">
                        <label for="enrollment_code" class="block text-gray-700 font-medium mb-2">Enrollment Code</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                            <input type="text" id="enrollment_code" name="enrollment_code" 
                                class="w-full pl-10 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300"
                                placeholder="Enter the enrollment code provided by your teacher" required>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">Ask your teacher for the class enrollment code.</p>
                    </div>
                    
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="hideEnrollModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md transition-colors duration-300">
                            Cancel
                        </button>
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md transition-all duration-300 transform hover:scale-[1.02] flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                            Join Class
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Learning Tips Section -->
    <div class="mt-12 bg-green-50 p-6 rounded-lg shadow-sm border border-green-100 animate__animated animate__fadeIn" style="animation-delay: 1s;">
        <h3 class="text-lg font-semibold text-green-800 mb-3 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Learning Tips
        </h3>
        <ul class="text-green-700 space-y-2">
            <li class="flex items-start">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 flex-shrink-0 mt-0.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Regularly check your classes for new assignments and learning materials.
            </li>
            <li class="flex items-start">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 flex-shrink-0 mt-0.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Complete your assignments on time to maintain a good learning pace.
            </li>
            <li class="flex items-start">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 flex-shrink-0 mt-0.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Reach out to your teachers if you have questions or need additional help.
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
    
    // Add smooth transitions to buttons
    const buttons = document.querySelectorAll('button, a.bg-green-600, a.bg-green-100, a.bg-gray-100');
    buttons.forEach(button => {
        button.classList.add('transition-transform', 'duration-300');
        button.addEventListener('mouseenter', function() {
            this.classList.add('transform', 'scale-105');
        });
        
        button.addEventListener('mouseleave', function() {
            this.classList.remove('transform', 'scale-105');
        });
    });
});

function showEnrollModal(className, classroomId) {
    document.getElementById('modal-title').textContent = 'Join Class: ' + className;
    document.getElementById('classroom_id').value = classroomId;
    
    const modal = document.getElementById('enrollment-modal');
    modal.classList.remove('hidden');
    
    // Animate the modal
    const modalContent = modal.querySelector('.animate__animated');
    modalContent.classList.remove('animate__fadeOutUp');
    modalContent.classList.add('animate__fadeInDown');
}

function hideEnrollModal() {
    const modal = document.getElementById('enrollment-modal');
    const modalContent = modal.querySelector('.animate__animated');
    
    // Animate the modal out
    modalContent.classList.remove('animate__fadeInDown');
    modalContent.classList.add('animate__fadeOutUp');
    
    // Hide after animation completes
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Close modal when clicking outside
document.getElementById('enrollment-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideEnrollModal();
    }
});
</script>

<?php include 'includes/footer.php'; ?>