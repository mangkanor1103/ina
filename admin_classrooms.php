<?php
// filepath: c:\xampp\htdocs\ina\admin_classrooms.php
include 'includes/header.php';

// Check if user is logged in and is an admin
if (!is_logged_in() || !has_role('admin')) {
    set_flash_message("error", "You don't have permission to access the admin panel.");
    redirect("admin_login.php");
}

// Check if status column exists in classrooms table, if not add it
$status_column_exists = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'status'")->num_rows > 0;
if (!$status_column_exists) {
    $conn->query("ALTER TABLE classrooms ADD COLUMN status ENUM('active', 'archived') DEFAULT 'active' AFTER description");
}

// Handle classroom deletion
if (isset($_POST['delete_classroom'])) {
    $classroom_id = (int)$_POST['classroom_id'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Check if submissions table exists and if classroom has lessons with submissions
        $submissions_table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;
        $submission_count = 0;
        
        if ($submissions_table_exists) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM lessons l 
                LEFT JOIN submissions s ON l.id = s.lesson_id 
                WHERE l.classroom_id = ? AND s.id IS NOT NULL
            ");
            $stmt->bind_param("i", $classroom_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $submission_count = $result->fetch_assoc()['count'];
        }
        
        if ($submission_count > 0) {
            set_flash_message("error", "Cannot delete classroom with existing submissions. Archive it instead.");
        } else {
            // Delete lessons first
            $stmt = $conn->prepare("DELETE FROM lessons WHERE classroom_id = ?");
            $stmt->bind_param("i", $classroom_id);
            $stmt->execute();
            
            // Delete classroom students (check if table exists)
            $classroom_students_table_exists = $conn->query("SHOW TABLES LIKE 'classroom_students'")->num_rows > 0;
            if ($classroom_students_table_exists) {
                $stmt = $conn->prepare("DELETE FROM classroom_students WHERE classroom_id = ?");
                $stmt->bind_param("i", $classroom_id);
                $stmt->execute();
            }
            
            // Delete classroom
            $stmt = $conn->prepare("DELETE FROM classrooms WHERE id = ?");
            $stmt->bind_param("i", $classroom_id);
            $stmt->execute();
            
            $conn->commit();
            set_flash_message("success", "Classroom deleted successfully.");
        }
    } catch (Exception $e) {
        $conn->rollback();
        set_flash_message("error", "Error deleting classroom: " . $e->getMessage());
    }
    
    redirect("admin_classrooms.php");
}

// Handle classroom archiving/unarchiving
if (isset($_POST['toggle_archive']) && $status_column_exists) {
    $classroom_id = (int)$_POST['classroom_id'];
    $current_status = $_POST['current_status'];
    $new_status = ($current_status === 'active') ? 'archived' : 'active';
    
    $stmt = $conn->prepare("UPDATE classrooms SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $classroom_id);
    
    if ($stmt->execute()) {
        $action = ($new_status === 'archived') ? 'archived' : 'restored';
        set_flash_message("success", "Classroom {$action} successfully.");
    } else {
        set_flash_message("error", "Error updating classroom status.");
    }
    
    redirect("admin_classrooms.php");
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = '';

if ($status_filter !== 'all' && $status_column_exists) {
    $where_conditions[] = "c.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if (!empty($search)) {
    $where_conditions[] = "(c.name LIKE ? OR c.description LIKE ? OR u.username LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Valid sort columns
$valid_sorts = ['name', 'created_at', 'student_count', 'lesson_count', 'teacher_name'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'created_at';
}

$valid_orders = ['ASC', 'DESC'];
if (!in_array($order, $valid_orders)) {
    $order = 'DESC';
}

// Check if required tables exist
$classroom_students_table_exists = $conn->query("SHOW TABLES LIKE 'classroom_students'")->num_rows > 0;
$submissions_table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;

// Build SQL query based on available tables
$status_select = $status_column_exists ? "c.status," : "'active' as status,";

$student_count_query = $classroom_students_table_exists 
    ? "(SELECT COUNT(*) FROM classroom_students cs WHERE cs.classroom_id = c.id)" 
    : "0";

$submission_count_query = $submissions_table_exists 
    ? "(SELECT COUNT(*) FROM lessons l LEFT JOIN submissions s ON l.id = s.lesson_id WHERE l.classroom_id = c.id AND s.id IS NOT NULL)" 
    : "0";

// Get classrooms with statistics
$sql = "
    SELECT 
        c.*,
        {$status_select}
        u.username as teacher_name,
        u.email as teacher_email,
        {$student_count_query} as student_count,
        (SELECT COUNT(*) FROM lessons l WHERE l.classroom_id = c.id) as lesson_count,
        {$submission_count_query} as submission_count
    FROM classrooms c
    LEFT JOIN users u ON c.teacher_id = u.id
    {$where_clause}
    ORDER BY {$sort} {$order}
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$classrooms = $result->fetch_all(MYSQLI_ASSOC);

// Get summary statistics
$status_stats = $status_column_exists ? 
    "SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_classrooms,
     SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_classrooms," :
    "COUNT(*) as active_classrooms,
     0 as archived_classrooms,";

$student_avg_query = $classroom_students_table_exists ?
    "LEFT JOIN (
        SELECT classroom_id, COUNT(*) as student_count 
        FROM classroom_students 
        GROUP BY classroom_id
    ) student_counts ON c.id = student_counts.classroom_id" :
    "LEFT JOIN (SELECT NULL as classroom_id, 0 as student_count) student_counts ON 1=0";

$stats_sql = "
    SELECT 
        COUNT(*) as total_classrooms,
        {$status_stats}
        AVG(student_counts.student_count) as avg_students_per_classroom
    FROM classrooms c
    {$student_avg_query}
";

$stmt = $conn->prepare($stats_sql);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-8">
        <div class="animate__animated animate__fadeInLeft">
            <div class="flex items-center mb-4">
                <a href="admin_dashboard.php" class="text-green-600 hover:text-green-800 mr-4 transition-colors duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </a>
                <h1 class="text-3xl font-bold text-green-800 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-3 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    Classroom Management
                </h1>
            </div>
            <p class="text-gray-600 ml-10">Manage all classrooms and their activities</p>
        </div>
        
        <div class="mt-4 md:mt-0 flex flex-wrap gap-2 animate__animated animate__fadeInRight">
            <a href="create_classroom.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-all duration-300 flex items-center transform hover:scale-105">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Create Classroom
            </a>
            
            <a href="admin_export_classrooms.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition-all duration-300 flex items-center transform hover:scale-105">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Export Data
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-green-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-medium text-gray-500">Total Classrooms</h3>
                <span class="bg-green-100 text-green-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_classrooms']); ?></div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-blue-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-medium text-gray-500">Active Classrooms</h3>
                <span class="bg-blue-100 text-blue-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['active_classrooms']); ?></div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-amber-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-medium text-gray-500"><?php echo $status_column_exists ? 'Archived Classrooms' : 'All Classrooms'; ?></h3>
                <span class="bg-amber-100 text-amber-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8l6 6 6-6" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['archived_classrooms']); ?></div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md p-6 transition-all duration-300 hover:shadow-lg border-b-4 border-purple-500 transform hover:-translate-y-1">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-medium text-gray-500">Avg Students/Class</h3>
                <span class="bg-purple-100 text-purple-800 p-2 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </span>
            </div>
            <div class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['avg_students_per_classroom'] ?? 0, 1); ?></div>
        </div>
    </div>

    <!-- System Status Notice -->
    <?php if (!$status_column_exists || !$classroom_students_table_exists || !$submissions_table_exists): ?>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-8 animate__animated animate__fadeIn">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">System Setup Notice</h3>
                    <div class="mt-2 text-sm text-yellow-700">
                        <p>Some database tables or columns are missing. The following features may be limited:</p>
                        <ul class="list-disc list-inside mt-1">
                            <?php if (!$status_column_exists): ?>
                                <li>Classroom status (active/archived) - Added automatically</li>
                            <?php endif; ?>
                            <?php if (!$classroom_students_table_exists): ?>
                                <li>Student enrollment tracking</li>
                            <?php endif; ?>
                            <?php if (!$submissions_table_exists): ?>
                                <li>Assignment submission tracking</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters and Search -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-8 animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
        <form method="GET" action="admin_classrooms.php" class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search Classrooms</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                        class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 transition-colors duration-300"
                        placeholder="Search by classroom name, description, or teacher...">
                </div>
            </div>
            
            <?php if ($status_column_exists): ?>
            <div class="md:w-48">
                <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status Filter</label>
                <select id="status" name="status" class="block w-full py-2 px-3 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 transition-colors duration-300">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="md:w-48">
                <label for="sort" class="block text-sm font-medium text-gray-700 mb-2">Sort By</label>
                <select id="sort" name="sort" class="block w-full py-2 px-3 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 transition-colors duration-300">
                    <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Date Created</option>
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name</option>
                    <option value="student_count" <?php echo $sort === 'student_count' ? 'selected' : ''; ?>>Student Count</option>
                    <option value="lesson_count" <?php echo $sort === 'lesson_count' ? 'selected' : ''; ?>>Lesson Count</option>
                    <option value="teacher_name" <?php echo $sort === 'teacher_name' ? 'selected' : ''; ?>>Teacher</option>
                </select>
            </div>
            
            <div class="md:w-32">
                <label for="order" class="block text-sm font-medium text-gray-700 mb-2">Order</label>
                <select id="order" name="order" class="block w-full py-2 px-3 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 transition-colors duration-300">
                    <option value="DESC" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md transition-all duration-300 flex items-center transform hover:scale-105">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.414A1 1 0 013 6.707V4z" />
                    </svg>
                    Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Classrooms Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden animate__animated animate__fadeIn" style="animation-delay: 0.4s;">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">
                Classrooms 
                <span class="text-sm text-gray-500">(<?php echo count($classrooms); ?> found)</span>
            </h3>
        </div>
        
        <?php if (empty($classrooms)): ?>
            <div class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No classrooms found</h3>
                <p class="mt-1 text-sm text-gray-500">Get started by creating a new classroom.</p>
                <div class="mt-6">
                    <a href="create_classroom.php" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 transition-colors duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Create Classroom
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classroom</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Teacher</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Students</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lessons</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submissions</th>
                            <?php if ($status_column_exists): ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($classrooms as $classroom): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-200">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="h-10 w-10 rounded-lg bg-green-100 flex items-center justify-center">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <a href="view_classroom.php?id=<?php echo $classroom['id']; ?>" class="hover:text-green-600 transition-colors duration-200">
                                                    <?php echo htmlspecialchars($classroom['name']); ?>
                                                </a>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars(substr($classroom['description'] ?? '', 0, 50)); ?>
                                                <?php echo strlen($classroom['description'] ?? '') > 50 ? '...' : ''; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($classroom['teacher_name'] ?? 'No teacher assigned'); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($classroom['teacher_email'] ?? ''); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <?php echo $classroom['student_count']; ?> students
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                        <?php echo $classroom['lesson_count']; ?> lessons
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <?php echo $classroom['submission_count']; ?> submissions
                                    </span>
                                </td>
                                <?php if ($status_column_exists): ?>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($classroom['status'] === 'active'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <svg class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 8 8">
                                                <circle cx="4" cy="4" r="3"/>
                                            </svg>
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                            <svg class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 8 8">
                                                <circle cx="4" cy="4" r="3"/>
                                            </svg>
                                            Archived
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($classroom['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex items-center justify-end space-x-2">
                                        <a href="view_classroom.php?id=<?php echo $classroom['id']; ?>" 
                                           class="text-green-600 hover:text-green-900 transition-colors duration-200 transform hover:scale-110"
                                           title="View Classroom">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </a>
                                        
                                        <a href="edit_classroom.php?id=<?php echo $classroom['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-900 transition-colors duration-200 transform hover:scale-110"
                                           title="Edit Classroom">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </a>
                                        
                                        <?php if ($status_column_exists): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to <?php echo $classroom['status'] === 'active' ? 'archive' : 'restore'; ?> this classroom?');">
                                            <input type="hidden" name="classroom_id" value="<?php echo $classroom['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $classroom['status']; ?>">
                                            <button type="submit" name="toggle_archive" 
                                                    class="<?php echo $classroom['status'] === 'active' ? 'text-yellow-600 hover:text-yellow-900' : 'text-green-600 hover:text-green-900'; ?> transition-colors duration-200 transform hover:scale-110"
                                                    title="<?php echo $classroom['status'] === 'active' ? 'Archive' : 'Restore'; ?> Classroom">
                                                <?php if ($classroom['status'] === 'active'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8l6 6 6-6" />
                                                    </svg>
                                                <?php else: ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                                    </svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($classroom['submission_count'] == 0): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this classroom? This action cannot be undone.');">
                                                <input type="hidden" name="classroom_id" value="<?php echo $classroom['id']; ?>">
                                                <button type="submit" name="delete_classroom" 
                                                        class="text-red-600 hover:text-red-900 transition-colors duration-200 transform hover:scale-110"
                                                        title="Delete Classroom">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-400 transform hover:scale-110 transition-transform duration-200" title="Cannot delete classroom with submissions">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Quick Stats -->
    <div class="mt-8 bg-green-50 rounded-lg p-6 animate__animated animate__fadeIn" style="animation-delay: 0.5s;">
        <h3 class="text-lg font-medium text-green-800 mb-4 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            Quick Insights
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-green-700">
            <div class="flex items-center p-3 bg-white rounded-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
                <span>Most lessons: <strong><?php echo !empty($classrooms) ? max(array_column($classrooms, 'lesson_count')) : 0; ?></strong> lessons</span>
            </div>
            <div class="flex items-center p-3 bg-white rounded-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <span>Most students: <strong><?php echo !empty($classrooms) ? max(array_column($classrooms, 'student_count')) : 0; ?></strong> students</span>
            </div>
            <div class="flex items-center p-3 bg-white rounded-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span>Most submissions: <strong><?php echo !empty($classrooms) ? max(array_column($classrooms, 'submission_count')) : 0; ?></strong> submissions</span>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit form when filters change
    const filters = document.querySelectorAll('#status, #sort, #order');
    filters.forEach(filter => {
        filter.addEventListener('change', function() {
            this.form.submit();
        });
    });
    
    // Add smooth transitions to table rows
    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.classList.add('transform', 'scale-[1.005]');
        });
        
        row.addEventListener('mouseleave', function() {
            this.classList.remove('transform', 'scale-[1.005]');
        });
    });
    
    // Add loading state to filter form
    const filterForm = document.querySelector('form');
    const filterButton = filterForm.querySelector('button[type="submit"]');
    
    filterForm.addEventListener('submit', function() {
        filterButton.disabled = true;
        filterButton.innerHTML = `
            <svg class="animate-spin h-4 w-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Filtering...
        `;
    });
});
</script>

<?php include 'includes/footer.php'; ?>