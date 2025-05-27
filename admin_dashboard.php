<?php
// filepath: c:\xampp\htdocs\ina\admin_dashboard.php
include 'includes/header.php';

// Check if user is logged in and is an admin
if (!is_logged_in() || !has_role('admin')) {
    set_flash_message("error", "You don't have permission to access the admin panel.");
    redirect("admin_login.php");
}

// Get counts and statistics
// Total students count
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
$stmt->execute();
$result = $stmt->get_result();
$student_count = $result->fetch_assoc()['count'];

// Total teachers count
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'");
$stmt->execute();
$result = $stmt->get_result();
$teacher_count = $result->fetch_assoc()['count'];

// Total classrooms count
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM classrooms");
$stmt->execute();
$result = $stmt->get_result();
$classroom_count = $result->fetch_assoc()['count'];

// Total submissions count
$submissions_table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;
$submission_count = 0;
$graded_count = 0;

if ($submissions_table_exists) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM submissions");
    $stmt->execute();
    $result = $stmt->get_result();
    $submission_count = $result->fetch_assoc()['count'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM submissions WHERE grade IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();
    $graded_count = $result->fetch_assoc()['count'];
}

// Recent users registered
$stmt = $conn->prepare("SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$result = $stmt->get_result();
$recent_users = $result->fetch_all(MYSQLI_ASSOC);

// Recent activity
$activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
$recent_activity = [];

if ($activity_table_exists) {
    $stmt = $conn->prepare("
        SELECT al.*, u.username 
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_activity = $result->fetch_all(MYSQLI_ASSOC);
}

// Most active classrooms
$classroom_activity = [];
if ($submissions_table_exists) {
    $stmt = $conn->prepare("
        SELECT c.id, c.name, COUNT(s.id) as submission_count
        FROM classrooms c
        LEFT JOIN lessons l ON c.id = l.classroom_id
        LEFT JOIN submissions s ON l.id = s.lesson_id
        GROUP BY c.id
        ORDER BY submission_count DESC
        LIMIT 5
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $classroom_activity = $result->fetch_all(MYSQLI_ASSOC);
}

// Registration trend - last 7 days
$registration_trend = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $next_date = date('Y-m-d', strtotime("-" . ($i - 1) . " days"));
    
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE DATE(created_at) = ? AND role = 'student') as students,
            (SELECT COUNT(*) FROM users WHERE DATE(created_at) = ? AND role = 'teacher') as teachers
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $counts = $result->fetch_assoc();
    
    $registration_trend[] = [
        'date' => date('M d', strtotime($date)),
        'students' => (int)$counts['students'],
        'teachers' => (int)$counts['teachers']
    ];
}

// Prepare registration trend data for chart
$chart_dates = json_encode(array_column($registration_trend, 'date'));
$chart_students = json_encode(array_column($registration_trend, 'students'));
$chart_teachers = json_encode(array_column($registration_trend, 'teachers'));
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-8">
        <div class="animate__animated animate__fadeInLeft">
            <h1 class="text-3xl font-bold text-green-800 mb-2 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-3 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                </svg>
                Admin Dashboard
            </h1>
            <p class="text-gray-600 ml-11">System overview and key metrics</p>
        </div>
        
        <div class="mt-4 md:mt-0 flex flex-wrap gap-2 animate__animated animate__fadeInRight">
            <a href="admin_users.php" class="bg-green-100 hover:bg-green-200 text-green-800 px-4 py-2 rounded-lg transition-all duration-300 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                Manage Users
            </a>
            
            <a href="admin_classrooms.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition-all duration-300 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                Manage Classrooms
            </a>
            
            <a href="admin_reports.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition-all duration-300 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                View Reports
            </a>
        </div>
    </div>

    <!-- Dashboard Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-green-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Total Students</h3>
                <span class="bg-green-100 text-green-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path d="M12 14l9-5-9-5-9 5 9 5z" />
                        <path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-bold text-gray-900"><?php echo number_format($student_count); ?></div>
            <p class="text-sm text-gray-500 mt-2">Registered students in the system</p>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-blue-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Total Teachers</h3>
                <span class="bg-blue-100 text-blue-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-bold text-gray-900"><?php echo number_format($teacher_count); ?></div>
            <p class="text-sm text-gray-500 mt-2">Faculty members and instructors</p>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-purple-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Total Classrooms</h3>
                <span class="bg-purple-100 text-purple-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-bold text-gray-900"><?php echo number_format($classroom_count); ?></div>
            <p class="text-sm text-gray-500 mt-2">Active learning environments</p>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-amber-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-700">Submissions</h3>
                <span class="bg-amber-100 text-amber-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </span>
            </div>
            <div class="text-3xl font-bold text-gray-900"><?php echo number_format($submission_count); ?></div>
            <div class="flex justify-between items-center mt-2">
                <p class="text-sm text-gray-500">Completed assignments</p>
                <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">
                    <?php echo number_format($graded_count); ?> Graded
                </span>
            </div>
        </div>
    </div>
    
    <!-- Registration Trend Chart -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="lg:col-span-2 bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
            <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                </svg>
                Registration Trend (Last 7 Days)
            </h3>
            <div>
                <canvas id="registrationChart" width="100%" height="50"></canvas>
            </div>
        </div>
        
        <!-- User Distribution -->
        <div class="bg-white rounded-lg shadow-md p-6 animate__animated animate__fadeIn" style="animation-delay: 0.4s;">
            <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                </svg>
                User Distribution
            </h3>
            <div class="flex justify-center">
                <canvas id="userDistributionChart" width="100%" height="200"></canvas>
            </div>
            <div class="mt-6 grid grid-cols-2 gap-4">
                <div class="text-center p-3 bg-green-50 rounded-lg">
                    <div class="text-sm text-gray-500">Students</div>
                    <div class="text-xl font-bold text-green-600"><?php echo number_format($student_count); ?></div>
                    <div class="text-xs text-gray-400">
                        <?php echo $student_count + $teacher_count > 0 ? round(($student_count / ($student_count + $teacher_count)) * 100) : 0; ?>% of users
                    </div>
                </div>
                <div class="text-center p-3 bg-blue-50 rounded-lg">
                    <div class="text-sm text-gray-500">Teachers</div>
                    <div class="text-xl font-bold text-blue-600"><?php echo number_format($teacher_count); ?></div>
                    <div class="text-xs text-gray-400">
                        <?php echo $student_count + $teacher_count > 0 ? round(($teacher_count / ($student_count + $teacher_count)) * 100) : 0; ?>% of users
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Activities and Users -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Recent Users -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden animate__animated animate__fadeIn" style="animation-delay: 0.5s;">
            <div class="bg-green-600 text-white px-6 py-4">
                <h3 class="font-semibold flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    Recently Registered Users
                </h3>
            </div>
            <div class="divide-y divide-gray-200">
                <?php if (empty($recent_users)): ?>
                    <div class="p-6 text-center text-gray-500">
                        No recent user registrations
                    </div>
                <?php else: ?>
                    <?php foreach($recent_users as $user): ?>
                        <div class="p-4 hover:bg-gray-50 transition-colors duration-200">
                            <div class="flex items-center space-x-3">
                                <div class="flex-shrink-0">
                                    <?php if ($user['role'] === 'student'): ?>
                                        <div class="bg-green-100 p-2 rounded-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path d="M12 14l9-5-9-5-9 5 9 5z" />
                                                <path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" />
                                            </svg>
                                        </div>
                                    <?php elseif ($user['role'] === 'teacher'): ?>
                                        <div class="bg-blue-100 p-2 rounded-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                                            </svg>
                                        </div>
                                    <?php else: ?>
                                        <div class="bg-purple-100 p-2 rounded-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1">
                                    <div class="flex justify-between">
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></p>
                                        <span class="text-xs text-gray-500"><?php echo date('M d', strtotime($user['created_at'])); ?></span>
                                    </div>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user['email']); ?></p>
                                    <span class="inline-flex items-center text-xs px-2 py-0.5 rounded-full <?php echo $user['role'] === 'student' ? 'bg-green-100 text-green-800' : ($user['role'] === 'teacher' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'); ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="bg-gray-50 px-4 py-3 text-right">
                <a href="admin_users.php" class="text-sm font-medium text-green-600 hover:text-green-800 flex items-center justify-end">
                    View all users
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
            </div>
        </div>
        
        <!-- Active Classrooms -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden animate__animated animate__fadeIn" style="animation-delay: 0.6s;">
            <div class="bg-green-600 text-white px-6 py-4">
                <h3 class="font-semibold flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    Most Active Classrooms
                </h3>
            </div>
            <div class="divide-y divide-gray-200">
                <?php if (empty($classroom_activity)): ?>
                    <div class="p-6 text-center text-gray-500">
                        No classroom activity recorded yet
                    </div>
                <?php else: ?>
                    <?php foreach($classroom_activity as $classroom): ?>
                        <div class="p-4 hover:bg-gray-50 transition-colors duration-200">
                            <div class="flex items-center">
                                <div class="flex-1">
                                    <div class="flex justify-between">
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($classroom['name']); ?></p>
                                        <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">
                                            <?php echo $classroom['submission_count']; ?> submissions
                                        </span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                        <?php 
                                        $max_submissions = max(array_column($classroom_activity, 'submission_count'));
                                        $percentage = $max_submissions > 0 ? ($classroom['submission_count'] / $max_submissions) * 100 : 0;
                                        ?>
                                        <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="bg-gray-50 px-4 py-3 text-right">
                <a href="admin_classrooms.php" class="text-sm font-medium text-green-600 hover:text-green-800 flex items-center justify-end">
                    View all classrooms
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden animate__animated animate__fadeIn" style="animation-delay: 0.7s;">
            <div class="bg-green-600 text-white px-6 py-4">
                <h3 class="font-semibold flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Recent System Activity
                </h3>
            </div>
            <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
                <?php if (empty($recent_activity) || !$activity_table_exists): ?>
                    <div class="p-6 text-center text-gray-500">
                        No activity recorded yet
                    </div>
                <?php else: ?>
                    <?php foreach($recent_activity as $activity): ?>
                        <div class="p-4 hover:bg-gray-50 transition-colors duration-200">
                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0 mt-1">
                                    <?php if (strpos($activity['activity_type'], 'login') !== false): ?>
                                        <div class="bg-blue-100 p-1 rounded-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                                            </svg>
                                        </div>
                                    <?php elseif (strpos($activity['activity_type'], 'submission') !== false): ?>
                                        <div class="bg-green-100 p-1 rounded-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    <?php else: ?>
                                        <div class="bg-gray-100 p-1 rounded-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1">
                                    <div class="flex justify-between">
                                        <p class="text-xs text-gray-500">
                                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($activity['username'] ?? 'System'); ?></span>
                                            <span class="mx-1">•</span>
                                            <span><?php echo ucwords(str_replace('_', ' ', $activity['activity_type'])); ?></span>
                                        </p>
                                        <span class="text-xs text-gray-500"><?php echo date('M d H:i', strtotime($activity['created_at'])); ?></span>
                                    </div>
                                    <p class="text-sm text-gray-700 mt-0.5"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="bg-gray-50 px-4 py-3 text-right">
                <a href="admin_activity_log.php" class="text-sm font-medium text-green-600 hover:text-green-800 flex items-center justify-end">
                    View full activity log
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-8 animate__animated animate__fadeIn" style="animation-delay: 0.8s;">
        <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            Quick Actions
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <a href="admin_create_user.php" class="bg-green-50 hover:bg-green-100 p-4 rounded-lg flex items-center transition-colors duration-200 border border-green-100">
                <div class="bg-green-100 p-2 rounded-full mr-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                </div>
                <div>
                    <div class="font-medium text-gray-800">Add New User</div>
                    <div class="text-xs text-gray-500">Create student or teacher account</div>
                </div>
            </a>
            
            <a href="admin_create_classroom.php" class="bg-blue-50 hover:bg-blue-100 p-4 rounded-lg flex items-center transition-colors duration-200 border border-blue-100">
                <div class="bg-blue-100 p-2 rounded-full mr-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                    <div class="font-medium text-gray-800">Create Classroom</div>
                    <div class="text-xs text-gray-500">Set up new learning environment</div>
                </div>
            </a>
            
            <a href="admin_export.php" class="bg-amber-50 hover:bg-amber-100 p-4 rounded-lg flex items-center transition-colors duration-200 border border-amber-100">
                <div class="bg-amber-100 p-2 rounded-full mr-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div>
                    <div class="font-medium text-gray-800">Export Reports</div>
                    <div class="text-xs text-gray-500">Generate system reports</div>
                </div>
            </a>
            
            <a href="admin_settings.php" class="bg-purple-50 hover:bg-purple-100 p-4 rounded-lg flex items-center transition-colors duration-200 border border-purple-100">
                <div class="bg-purple-100 p-2 rounded-full mr-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div>
                    <div class="font-medium text-gray-800">System Settings</div>
                    <div class="text-xs text-gray-500">Configure platform options</div>
                </div>
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.6.0/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Registration trend chart
    const ctx = document.getElementById('registrationChart').getContext('2d');
    const registrationChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo $chart_dates; ?>,
            datasets: [
                {
                    label: 'Students',
                    data: <?php echo $chart_students; ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.5)',
                    borderColor: 'rgb(16, 185, 129)',
                    borderWidth: 1
                },
                {
                    label: 'Teachers',
                    data: <?php echo $chart_teachers; ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.5)',
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
    
    // User distribution chart
    const ctxPie = document.getElementById('userDistributionChart').getContext('2d');
    const userDistributionChart = new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: ['Students', 'Teachers'],
            datasets: [{
                data: [<?php echo $student_count; ?>, <?php echo $teacher_count; ?>],
                backgroundColor: [
                    'rgba(16, 185, 129, 0.7)',
                    'rgba(59, 130, 246, 0.7)'
                ],
                borderColor: [
                    'rgb(16, 185, 129)',
                    'rgb(59, 130, 246)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                }
            },
            cutout: '65%'
        }
    });
    
    // Add smooth transitions to cards
    const cards = document.querySelectorAll('.hover\\:shadow-lg');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.classList.add('shadow-lg');
        });
        
        card.addEventListener('mouseleave', function() {
            this.classList.remove('shadow-lg');
        });
    });
    
    // Add smooth transitions to buttons
    const buttons = document.querySelectorAll('a.bg-green-100, a.bg-gray-100');
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
</script>

<?php include 'includes/footer.php'; ?>