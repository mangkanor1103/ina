<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view lessons.");
    redirect("login.php");
}

// Only teachers and admins can see all lessons
if (!is_teacher() && !is_admin()) {
    set_flash_message("error", "You don't have permission to access this page.");
    redirect("index.php");
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Pagination settings
$items_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Search functionality
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$classroom_filter = isset($_GET['classroom']) && is_numeric($_GET['classroom']) ? (int)$_GET['classroom'] : 0;

// Base query parts
$select_query = "
    SELECT l.*, c.name as classroom_name, c.id as classroom_id, c.teacher_id, 
           u.username as teacher_username
    FROM lessons l
    JOIN classrooms c ON l.classroom_id = c.id
    JOIN users u ON c.teacher_id = u.id
";

$count_query = "
    SELECT COUNT(*) as total
    FROM lessons l
    JOIN classrooms c ON l.classroom_id = c.id
    JOIN users u ON c.teacher_id = u.id
";

// Prepare where conditions and parameters
$where_conditions = [];
$count_params = [];
$count_param_types = '';
$select_params = [];
$select_param_types = '';

// Add search condition if provided
if (!empty($search)) {
    $where_conditions[] = "(l.title LIKE ? OR l.description LIKE ? OR c.name LIKE ?)";
    $search_param = "%$search%";
    
    // For count query
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_param_types .= 'sss';
    
    // For select query
    $select_params[] = $search_param;
    $select_params[] = $search_param;
    $select_params[] = $search_param;
    $select_param_types .= 'sss';
}

// Add classroom filter if provided
if ($classroom_filter > 0) {
    $where_conditions[] = "c.id = ?";
    
    // For count query
    $count_params[] = $classroom_filter;
    $count_param_types .= 'i';
    
    // For select query
    $select_params[] = $classroom_filter;
    $select_param_types .= 'i';
}

// Restrict non-admin teachers to only see their own lessons
if (!is_admin() && $user_role === 'teacher') {
    $where_conditions[] = "c.teacher_id = ?";
    
    // For count query
    $count_params[] = $user_id;
    $count_param_types .= 'i';
    
    // For select query
    $select_params[] = $user_id;
    $select_param_types .= 'i';
}

// Combine where conditions
if (!empty($where_conditions)) {
    $where_clause = " WHERE " . implode(' AND ', $where_conditions);
    $select_query .= $where_clause;
    $count_query .= $where_clause;
}

// Add pagination to select query
$select_query .= " ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$select_params[] = $items_per_page;
$select_params[] = $offset;
$select_param_types .= 'ii';

// Execute count query
if (!empty($count_params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($count_param_types, ...$count_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_count = $count_result->fetch_assoc()['total'];
} else {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_count = $count_result->fetch_assoc()['total'];
}

$total_pages = ceil($total_count / $items_per_page);

// Execute main query with pagination
if (!empty($select_params)) {
    $select_stmt = $conn->prepare($select_query);
    $select_stmt->bind_param($select_param_types, ...$select_params);
    $select_stmt->execute();
    $lessons = $select_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $select_stmt = $conn->prepare($select_query);
    $select_stmt->execute();
    $lessons = $select_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get classrooms for filter dropdown
if (is_admin()) {
    $classroom_stmt = $conn->prepare("SELECT id, name FROM classrooms ORDER BY name");
} else {
    $classroom_stmt = $conn->prepare("SELECT id, name FROM classrooms WHERE teacher_id = ? ORDER BY name");
    $classroom_stmt->bind_param("i", $user_id);
}
$classroom_stmt->execute();
$classrooms = $classroom_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="mb-8 flex flex-col md:flex-row md:justify-between md:items-center space-y-4 md:space-y-0">
    <h1 class="text-3xl font-bold">Lessons Management</h1>
    
    <!-- Filters and Search -->
    <div class="flex flex-col sm:flex-row items-center space-y-3 sm:space-y-0 sm:space-x-3">
        <form action="lessons.php" method="GET" class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-3 w-full">
            <div class="relative">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Search lessons..." 
                       class="border rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full sm:w-64">
                <?php if (!empty($search)): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['search' => ''])); ?>" 
                       class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
            
            <select name="classroom" class="border rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="0">All Classrooms</option>
                <?php foreach ($classrooms as $classroom): ?>
                    <option value="<?php echo $classroom['id']; ?>" <?php echo ($classroom_filter == $classroom['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($classroom['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                Filter
            </button>
        </form>
    </div>
</div>

<!-- Results counter -->
<div class="mb-4 text-sm text-gray-600">
    Showing <?php echo min($total_count, $offset + 1); ?>-<?php echo min($total_count, $offset + count($lessons)); ?> of <?php echo $total_count; ?> lessons
</div>

<?php if (empty($lessons)): ?>
    <div class="bg-white rounded-lg shadow-md p-6 text-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <h2 class="text-xl font-medium text-gray-900 mb-2">No lessons found</h2>
        <?php if (!empty($search) || $classroom_filter > 0): ?>
            <p class="text-gray-600 mb-4">Try changing your search criteria or selecting a different classroom.</p>
            <a href="lessons.php" class="inline-block bg-indigo-100 text-indigo-700 hover:bg-indigo-200 px-4 py-2 rounded-md">
                Clear all filters
            </a>
        <?php else: ?>
            <p class="text-gray-600 mb-4">You haven't created any lessons yet.</p>
            <a href="classrooms.php" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md">
                Go to Classrooms
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lesson</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classroom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submissions</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($lessons as $lesson): ?>
                    <?php
                    // Get submission count for this lesson
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM submissions WHERE lesson_id = ?");
                    $submission_count = 0;
                    if ($stmt) {
                        $stmt->bind_param("i", $lesson['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $submission_count = $result->fetch_assoc()['count'];
                    }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($lesson['title']); ?>
                                    </div>
                                    <?php if (!empty($lesson['description'])): ?>
                                        <div class="text-sm text-gray-500 max-w-md truncate">
                                            <?php echo htmlspecialchars($lesson['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">
                                <?php echo htmlspecialchars($lesson['classroom_name']); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                By: <?php echo htmlspecialchars($lesson['teacher_username']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo isset($lesson['created_at']) ? format_date($lesson['created_at']) : 'N/A'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                <?php echo $submission_count; ?> submissions
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="view_lesson.php?id=<?php echo $lesson['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">
                                View
                            </a>
                            <?php if ($submission_count > 0): ?>
                                <a href="lesson_submissions.php?id=<?php echo $lesson['id']; ?>" class="text-green-600 hover:text-green-900 mr-3">
                                    Submissions
                                </a>
                            <?php endif; ?>
                            <?php if (is_admin() || $lesson['teacher_id'] == $user_id): ?>
                                <a href="edit_lesson.php?id=<?php echo $lesson['id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                    Edit
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="mt-6 flex justify-center">
            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                <?php if ($current_page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" 
                       class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                        <span class="sr-only">Previous</span>
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                       class="<?php echo $i === $current_page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?> relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($current_page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" 
                       class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                        <span class="sr-only">Next</span>
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4-4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                        </svg>
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>