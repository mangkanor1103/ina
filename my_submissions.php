<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view your submissions.");
    redirect("login.php");
}

// Check if user is a student
if (!is_student()) {
    set_flash_message("error", "This page is only for students.");
    redirect("index.php");
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Get all submissions by this student
$stmt = $conn->prepare("
    SELECT s.*, l.title as lesson_title, c.name as classroom_name, c.id as classroom_id
    FROM submissions s
    JOIN lessons l ON s.lesson_id = l.id
    JOIN classrooms c ON l.classroom_id = c.id
    WHERE s.student_id = ?
    ORDER BY s.submitted_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$submissions = $result->fetch_all(MYSQLI_ASSOC);

// Group submissions by classroom
$submissions_by_classroom = [];
foreach ($submissions as $submission) {
    $classroom_id = $submission['classroom_id'];
    $classroom_name = $submission['classroom_name'];
    
    if (!isset($submissions_by_classroom[$classroom_id])) {
        $submissions_by_classroom[$classroom_id] = [
            'name' => $classroom_name,
            'submissions' => []
        ];
    }
    
    $submissions_by_classroom[$classroom_id]['submissions'][] = $submission;
}

// Get pending assignments (lessons without submissions)
$stmt = $conn->prepare("
    SELECT l.*, c.name as classroom_name, c.id as classroom_id
    FROM lessons l
    JOIN classrooms c ON l.classroom_id = c.id
    JOIN classroom_students cs ON c.id = cs.classroom_id
    LEFT JOIN submissions s ON l.id = s.lesson_id AND s.student_id = ?
    WHERE cs.student_id = ? AND s.id IS NULL
    ORDER BY l.created_at DESC
");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$pending_assignments = $result->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$total_submissions = count($submissions);
$graded_submissions = 0;
$total_grades = 0;

foreach ($submissions as $submission) {
    if ($submission['grade'] !== null) {
        $graded_submissions++;
        $total_grades += $submission['grade'];
    }
}

$average_grade = $graded_submissions > 0 ? round($total_grades / $graded_submissions, 1) : 0;
$completion_rate = $total_submissions > 0 ? round(($graded_submissions / $total_submissions) * 100) : 0;
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <div class="mb-8 animate__animated animate__fadeInLeft">
        <h1 class="text-3xl font-bold text-green-800 mb-2 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-3 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            My Submissions
        </h1>
        <p class="text-gray-600 ml-11">Track all your assignments, submissions, and grades in one place.</p>
    </div>

    <!-- Dashboard Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10 animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
        <div class="bg-green-50 rounded-lg p-5 shadow-sm border border-green-100 transition-all duration-300 hover:shadow-md hover:translate-y-[-2px]">
            <div class="flex items-start">
                <div class="bg-green-100 rounded-full p-3 mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <div class="text-green-700 text-sm font-medium uppercase tracking-wider mb-1">Completed</div>
                    <div class="text-3xl font-bold text-green-800"><?php echo $total_submissions; ?></div>
                    <div class="text-xs text-green-600 mt-1">Total submissions you've made</div>
                </div>
            </div>
        </div>
        
        <div class="bg-amber-50 rounded-lg p-5 shadow-sm border border-amber-100 transition-all duration-300 hover:shadow-md hover:translate-y-[-2px]">
            <div class="flex items-start">
                <div class="bg-amber-100 rounded-full p-3 mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <div class="text-amber-700 text-sm font-medium uppercase tracking-wider mb-1">Pending</div>
                    <div class="text-3xl font-bold text-amber-800"><?php echo count($pending_assignments); ?></div>
                    <div class="text-xs text-amber-600 mt-1">Assignments waiting for submission</div>
                </div>
            </div>
        </div>
        
        <div class="bg-blue-50 rounded-lg p-5 shadow-sm border border-blue-100 transition-all duration-300 hover:shadow-md hover:translate-y-[-2px]">
            <div class="flex items-start">
                <div class="bg-blue-100 rounded-full p-3 mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                    </svg>
                </div>
                <div>
                    <div class="text-blue-700 text-sm font-medium uppercase tracking-wider mb-1">Average Grade</div>
                    <div class="text-3xl font-bold text-blue-800"><?php echo $average_grade; ?></div>
                    <div class="text-xs text-blue-600 mt-1"><?php echo $graded_submissions; ?> of <?php echo $total_submissions; ?> graded</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Assignments Section -->
    <?php if (!empty($pending_assignments)): ?>
        <div class="mb-10 animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-amber-700 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Pending Assignments
                </h2>
                <span class="bg-amber-100 text-amber-800 text-sm font-semibold px-3 py-1 rounded-full flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <?php echo count($pending_assignments); ?> Due
                </span>
            </div>
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-100 transition-all duration-300 hover:shadow-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Lesson
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Classroom
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Posted Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($pending_assignments as $index => $assignment): ?>
                                <tr class="hover:bg-gray-50 transition-colors duration-200 animate__animated animate__fadeIn" style="animation-delay: <?php echo 0.4 + ($index * 0.05); ?>s;">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($assignment['classroom_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-500">
                                            <?php echo date('M d, Y', strtotime($assignment['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="submit_assignment.php?lesson_id=<?php echo $assignment['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded-md text-sm inline-flex items-center transition-colors duration-200">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                            </svg>
                                            Submit
                                        </a>
                                        <a href="view_lesson.php?id=<?php echo $assignment['id']; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded-md text-sm inline-flex items-center ml-2 transition-colors duration-200">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                            View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Completed Submissions Section -->
    <div class="animate__animated animate__fadeIn" style="animation-delay: 0.5s;">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold text-green-800 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                Completed Submissions
            </h2>
            
            <?php if (!empty($submissions)): ?>
                <span class="bg-green-100 text-green-800 text-sm font-semibold px-3 py-1 rounded-full flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <?php echo $total_submissions; ?> Total
                </span>
            <?php endif; ?>
        </div>

        <?php if (empty($submissions)): ?>
            <div class="bg-green-50 border-l-4 border-green-400 p-6 rounded-lg shadow-sm animate__animated animate__fadeIn" style="animation-delay: 0.6s;">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-green-700">
                            You haven't submitted any assignments yet. Your completed submissions will appear here.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Empty state illustration -->
            <div class="mt-12 text-center animate__animated animate__fadeIn" style="animation-delay: 0.7s;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-32 w-32 mx-auto text-green-200 mb-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                <h3 class="text-xl font-semibold text-gray-700 mb-2">Start Your Learning Journey</h3>
                <p class="text-gray-500 max-w-md mx-auto">Complete your first assignment to see your submissions and track your progress.</p>
                
                <?php if (!empty($pending_assignments)): ?>
                    <a href="submit_assignment.php?lesson_id=<?php echo $pending_assignments[0]['id']; ?>" class="mt-6 inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md font-medium transition-colors duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                        Submit Your First Assignment
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($submissions_by_classroom as $classroom_id => $classroom_data): ?>
                <div class="mb-8 animate__animated animate__fadeIn" style="animation-delay: 0.6s;">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" class="hover:text-green-700 transition-colors duration-200">
                                <?php echo htmlspecialchars($classroom_data['name']); ?>
                            </a>
                        </h3>
                        <span class="bg-green-50 text-green-700 text-sm font-medium px-3 py-1 rounded-full flex items-center border border-green-100">
                            <?php echo count($classroom_data['submissions']); ?> Submissions
                        </span>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-100 transition-all duration-300 hover:shadow-lg">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Lesson
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Submitted On
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Grade
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($classroom_data['submissions'] as $index => $submission): ?>
                                        <tr class="hover:bg-gray-50 transition-colors duration-200 animate__animated animate__fadeIn" style="animation-delay: <?php echo 0.7 + ($index * 0.05); ?>s;">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($submission['lesson_title']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-500 flex items-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                    </svg>
                                                    <?php echo date('M d, Y', strtotime($submission['submitted_at'])); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($submission['grade'] !== null): ?>
                                                    <div class="text-sm font-medium">
                                                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full inline-flex items-center">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                            <?php echo $submission['grade']; ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-sm text-gray-500 italic">
                                                        Pending
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($submission['grade'] !== null): ?>
                                                    <span class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                                        Graded
                                                    </span>
                                                <?php else: ?>
                                                    <span class="bg-yellow-100 text-yellow-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">
                                                        Awaiting Feedback
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <a href="view_submission.php?id=<?php echo $submission['id']; ?>" class="text-green-600 hover:text-green-900 transition-colors duration-200 inline-flex items-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                    </svg>
                                                    View Details
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_submissions > 0): ?>
        <!-- Submission Statistics -->
        <div class="mt-12 bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.8s;">
            <h2 class="text-xl font-bold mb-6 text-green-800 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                Submission Statistics
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-green-50 rounded-lg p-4 shadow-sm border border-green-100 transition-all duration-300 hover:shadow-md hover:translate-y-[-2px]">
                    <div class="text-green-700 text-sm font-medium uppercase tracking-wider mb-1">Total Submissions</div>
                    <div class="text-3xl font-bold text-green-800"><?php echo $total_submissions; ?></div>
                    <div class="flex items-center mt-2">
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full" style="width: 100%"></div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-blue-50 rounded-lg p-4 shadow-sm border border-blue-100 transition-all duration-300 hover:shadow-md hover:translate-y-[-2px]">
                    <div class="text-blue-700 text-sm font-medium uppercase tracking-wider mb-1">Average Grade</div>
                    <div class="text-3xl font-bold text-blue-800"><?php echo $average_grade; ?></div>
                    <div class="flex items-center justify-between mt-2 text-xs text-blue-600">
                        <span>0</span>
                        <span>50</span>
                        <span>100</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo min(100, $average_grade); ?>%"></div>
                    </div>
                </div>
                
                <div class="bg-amber-50 rounded-lg p-4 shadow-sm border border-amber-100 transition-all duration-300 hover:shadow-md hover:translate-y-[-2px]">
                    <div class="text-amber-700 text-sm font-medium uppercase tracking-wider mb-1">Feedback Rate</div>
                    <div class="text-3xl font-bold text-amber-800"><?php echo $completion_rate; ?>%</div>
                    <div class="text-xs text-amber-600 mt-1"><?php echo $graded_submissions; ?> of <?php echo $total_submissions; ?> submissions graded</div>
                    <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                        <div class="bg-amber-600 h-2 rounded-full" style="width: <?php echo $completion_rate; ?>%"></div>
                    </div>
                </div>
            </div>
            
            <!-- Grading Progress Bar -->
            <div class="mt-6">
                <div class="flex justify-between items-center mb-2">
                    <div class="text-sm font-medium text-gray-700">Feedback Progress</div>
                    <div class="text-sm text-gray-500"><?php echo $completion_rate; ?>% Complete</div>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-4">
                    <div class="bg-green-600 h-4 rounded-full flex items-center justify-center text-xs font-medium text-white" style="width: <?php echo $completion_rate; ?>%">
                        <?php if ($completion_rate > 15): ?>
                            <?php echo $graded_submissions; ?>/<?php echo $total_submissions; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Tips Section -->
            <div class="mt-8 p-4 bg-green-50 rounded-lg border border-green-100">
                <h3 class="font-medium text-green-800 mb-2 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Tips to Improve Your Grades
                </h3>
                <ul class="text-green-700 text-sm space-y-1">
                    <li class="flex items-start">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Submit assignments on time to avoid late penalties
                    </li>
                    <li class="flex items-start">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Review teacher feedback on your graded submissions
                    </li>
                    <li class="flex items-start">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Reach out to your teacher if you need help understanding concepts
                    </li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add smooth transitions for cards
    const cards = document.querySelectorAll('.hover\\:shadow-md');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.classList.add('shadow-md');
            this.style.transform = 'translateY(-2px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.classList.remove('shadow-md');
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Add smooth transitions to buttons
    const buttons = document.querySelectorAll('a.bg-green-600, a.bg-gray-100');
    buttons.forEach(button => {
        button.classList.add('transition-transform', 'duration-300');
        button.addEventListener('mouseenter', function() {
            this.classList.add('transform', 'scale-105');
        });
        
        button.addEventListener('mouseleave', function() {
            this.classList.remove('transform', 'scale-105');
        });
    });
    
    // Progress bar animation
    const progressBars = document.querySelectorAll('.bg-green-600, .bg-blue-600, .bg-amber-600');
    setTimeout(() => {
        progressBars.forEach(bar => {
            bar.style.transition = 'width 1s ease-in-out';
            bar.style.width = bar.style.width;
        });
    }, 500);
});
</script>

<?php include 'includes/footer.php'; ?>