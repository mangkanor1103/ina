<?php

include 'includes/header.php';

// Check if user is logged in and is a teacher
if (!is_logged_in() || (!has_role('teacher') && !has_role('admin'))) {
    set_flash_message("error", "You don't have permission to access this page.");
    redirect("index.php");
}

// Get classroom ID from URL
if (!isset($_GET['classroom_id']) || !is_numeric($_GET['classroom_id'])) {
    set_flash_message("error", "Invalid classroom ID.");
    redirect("classrooms.php");
}

$classroom_id = $_GET['classroom_id'];
$teacher_id = $_SESSION['user_id'];

// Check if the classroom belongs to this teacher or if user is admin
$stmt = $conn->prepare("SELECT * FROM classrooms WHERE id = ? AND (teacher_id = ? OR ? = (SELECT id FROM users WHERE role = 'admin' AND id = ?))");
$stmt->bind_param("iiii", $classroom_id, $teacher_id, $teacher_id, $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flash_message("error", "You don't have permission to manage students for this classroom.");
    redirect("classrooms.php");
}

$classroom = $result->fetch_assoc();

// Process adding a student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_student') {
    $email_or_username = clean_input($_POST['email_or_username']);
    
    // Find user by email or username
    $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE (email = ? OR username = ?) AND role = 'student'");
    $stmt->bind_param("ss", $email_or_username, $email_or_username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $errors[] = "No student found with this email or username.";
    } else {
        $student = $result->fetch_assoc();
        
        // Check if student is already enrolled
        $stmt = $conn->prepare("SELECT * FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
        $stmt->bind_param("ii", $classroom_id, $student['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "This student is already enrolled in this classroom.";
        } else {
            // Add student to classroom
            $stmt = $conn->prepare("INSERT INTO classroom_students (classroom_id, student_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $classroom_id, $student['id']);
            
            if ($stmt->execute()) {
                // Log activity if table exists
                $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
                
                if ($activity_table_exists) {
                    $activity = "Added student " . htmlspecialchars($student['username']) . " to the classroom";
                    $log_stmt = $conn->prepare("
                        INSERT INTO activity_log (classroom_id, user_id, activity_type, description, created_at) 
                        VALUES (?, ?, 'student_added', ?, NOW())
                    ");
                    $log_stmt->bind_param("iis", $classroom_id, $teacher_id, $activity);
                    $log_stmt->execute();
                }
                
                // Show success message with SweetAlert
                echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            title: 'Success!',
                            text: 'Student " . htmlspecialchars($student['username']) . " has been added to the classroom.',
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
                        });
                    });
                </script>";
            } else {
                $errors[] = "Failed to add student. Please try again.";
            }
        }
    }
}

// Process removing a student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_student') {
    $student_id = clean_input($_POST['student_id']);
    
    // Get student info for the message
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    $student = $student_result->fetch_assoc();
    $student_name = $student ? $student['username'] : 'Student';
    
    // Remove student from classroom
    $stmt = $conn->prepare("DELETE FROM classroom_students WHERE classroom_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $classroom_id, $student_id);
    
    if ($stmt->execute()) {
        // Log activity if table exists
        $activity_table_exists = $conn->query("SHOW TABLES LIKE 'activity_log'")->num_rows > 0;
        
        if ($activity_table_exists) {
            $activity = "Removed student " . htmlspecialchars($student_name) . " from the classroom";
            $log_stmt = $conn->prepare("
                INSERT INTO activity_log (classroom_id, user_id, activity_type, description, created_at) 
                VALUES (?, ?, 'student_removed', ?, NOW())
            ");
            $log_stmt->bind_param("iis", $classroom_id, $teacher_id, $activity);
            $log_stmt->execute();
        }
        
        // Show success message with SweetAlert
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: 'Student Removed',
                    text: '" . htmlspecialchars($student_name) . " has been removed from the classroom.',
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
                });
            });
        </script>";
    } else {
        $errors[] = "Failed to remove student. Please try again.";
    }
}

// Get enrolled students
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.email
    FROM classroom_students cs
    JOIN users u ON cs.student_id = u.id
    WHERE cs.classroom_id = ?
    ORDER BY u.username ASC
");
$stmt->bind_param("i", $classroom_id);
$stmt->execute();
$result = $stmt->get_result();
$enrolled_students = $result->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mx-auto px-4 py-8 fade-in">
    <div class="mb-6 animate__animated animate__fadeInLeft">
        <a href="view_classroom.php?id=<?php echo $classroom_id; ?>" class="text-green-600 hover:text-green-800 hover:underline flex items-center transition-colors duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Classroom
        </a>
    </div>

    <div class="bg-white rounded-lg shadow-md overflow-hidden hover-scale animate__animated animate__fadeIn mb-8">
        <div class="bg-green-600 p-6 text-white">
            <h1 class="text-2xl font-bold mb-2 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                Manage Students: <?php echo htmlspecialchars($classroom['name']); ?>
            </h1>
            <p class="text-green-100">Add or remove students from this classroom</p>
        </div>
        
        <div class="p-6">
            <?php if (isset($errors) && !empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 animate__animated animate__headShake">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Add Student Form -->
                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 animate__animated animate__fadeInLeft" style="animation-delay: 0.2s;">
                    <h2 class="text-xl font-bold text-green-800 mb-4 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                        Add Student
                    </h2>
                    
                    <form method="POST" action="manage_students.php?classroom_id=<?php echo $classroom_id; ?>" id="addStudentForm">
                        <input type="hidden" name="action" value="add_student">
                        
                        <div class="mb-4">
                            <label for="email_or_username" class="block text-gray-700 font-medium mb-2">
                                Email or Username <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </div>
                                <input type="text" id="email_or_username" name="email_or_username" required
                                       class="w-full pl-10 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-300" 
                                       placeholder="Enter student's email or username">
                            </div>
                            <p class="text-sm text-gray-500 mt-1">Enter the email address or username of the student you want to add.</p>
                        </div>
                        
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-all duration-300 transform hover:scale-[1.02] flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Add Student
                        </button>
                    </form>
                    
                    <div class="mt-6 bg-green-50 p-4 rounded-lg border border-green-100">
                        <h3 class="font-medium text-green-800 mb-2 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Quick Tip
                        </h3>
                        <p class="text-green-700 text-sm">
                            Students need to have a registered account with the "student" role before they can be added to a classroom.
                        </p>
                    </div>
                </div>
                
                <!-- Student Stats -->
                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 animate__animated animate__fadeInRight" style="animation-delay: 0.2s;">
                    <h2 class="text-xl font-bold text-green-800 mb-4 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        Classroom Statistics
                    </h2>
                    
                    <div class="grid grid-cols-1 gap-4">
                        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
                            <div class="flex items-center mb-2">
                                <div class="bg-green-100 text-green-700 p-2 rounded-full mr-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-xl font-bold text-gray-800"><?php echo count($enrolled_students); ?></div>
                                    <div class="text-sm text-gray-600">Students Enrolled</div>
                                </div>
                            </div>
                        </div>
                        
                        <?php
                        // Get active students stats (submitted at least one assignment)
                        $active_students_count = 0;
                        $table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;
                        
                        if ($table_exists && count($enrolled_students) > 0) {
                            $student_ids = array_column($enrolled_students, 'id');
                            $ids_string = implode(',', $student_ids);
                            
                            $query = "SELECT COUNT(DISTINCT student_id) as count FROM submissions 
                                     WHERE student_id IN ($ids_string) 
                                     AND lesson_id IN (SELECT id FROM lessons WHERE classroom_id = ?)";
                            
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("i", $classroom_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $active_students_count = $result->fetch_assoc()['count'];
                        }
                        ?>
                        
                        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
                            <div class="flex items-center mb-2">
                                <div class="bg-blue-100 text-blue-700 p-2 rounded-full mr-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-xl font-bold text-gray-800"><?php echo $active_students_count; ?></div>
                                    <div class="text-sm text-gray-600">Active Students</div>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Students who have submitted at least one assignment</p>
                        </div>
                    </div>
                    
                    <?php if (count($enrolled_students) > 0): ?>
                        <div class="mt-4">
                            <a href="export_students.php?classroom_id=<?php echo $classroom_id; ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg transition-all duration-300 flex items-center justify-center w-full">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                Export Student List
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Enrolled Students List -->
            <div class="mt-8 animate__animated animate__fadeIn" style="animation-delay: 0.4s;">
                <h2 class="text-xl font-bold text-green-800 mb-4 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    Enrolled Students (<?php echo count($enrolled_students); ?>)
                </h2>
                
                <?php if (empty($enrolled_students)): ?>
                    <div class="bg-green-50 border-l-4 border-green-400 p-6 rounded-lg">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-green-700">
                                    No students are currently enrolled in this classroom. Use the form above to add students.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white overflow-hidden border border-gray-200 rounded-lg shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Student
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Email
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($enrolled_students as $student): ?>
                                        <tr class="hover:bg-gray-50 transition-colors duration-200">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10 bg-green-100 rounded-full flex items-center justify-center">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                        </svg>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($student['username']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($student['email']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <?php
                                                // Check if student has submissions
                                                $has_submissions = false;
                                                $submission_table_exists = $conn->query("SHOW TABLES LIKE 'submissions'")->num_rows > 0;
                                                
                                                if ($submission_table_exists) {
                                                    $stmt = $conn->prepare("
                                                        SELECT COUNT(*) as count 
                                                        FROM submissions 
                                                        WHERE student_id = ? 
                                                        AND lesson_id IN (SELECT id FROM lessons WHERE classroom_id = ?)
                                                    ");
                                                    $stmt->bind_param("ii", $student['id'], $classroom_id);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();
                                                    $submission_count = $result->fetch_assoc()['count'];
                                                    $has_submissions = $submission_count > 0;
                                                }
                                                ?>
                                                
                                                <div class="flex items-center justify-end space-x-2">
                                                    <?php if ($has_submissions): ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                            <?php echo $submission_count; ?> submissions
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" action="manage_students.php?classroom_id=<?php echo $classroom_id; ?>" class="inline-block" onsubmit="return confirmRemove('<?php echo htmlspecialchars($student['username']); ?>')">
                                                        <input type="hidden" name="action" value="remove_student">
                                                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                        <button type="submit" class="text-red-600 hover:text-red-900 focus:outline-none flex items-center">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                            </svg>
                                                            Remove
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sweet Alert for adding student
    const addStudentForm = document.getElementById('addStudentForm');
    if (addStudentForm) {
        addStudentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get the email/username value
            const emailOrUsername = document.getElementById('email_or_username').value;
            
            if (!emailOrUsername.trim()) {
                Swal.fire({
                    title: 'Error!',
                    text: 'Please enter an email or username.',
                    icon: 'error',
                    confirmButtonColor: '#16a34a',
                    showClass: {
                        popup: 'animate__animated animate__fadeIn animate__faster'
                    },
                    hideClass: {
                        popup: 'animate__animated animate__fadeOut animate__faster'
                    }
                });
                return;
            }
            
            // Show loading state
            Swal.fire({
                title: 'Adding Student...',
                text: 'Please wait while we add the student to the classroom.',
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                },
                showClass: {
                    popup: 'animate__animated animate__fadeIn animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOut animate__faster'
                }
            });
            
            // Submit the form
            this.submit();
        });
    }
    
    // Add subtle animation to the form on focus
    const inputs = document.querySelectorAll('input, textarea');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.closest('.hover-scale')?.classList.add('shadow-lg');
        });
        
        input.addEventListener('blur', function() {
            if (!document.querySelector('input:focus, textarea:focus')) {
                this.closest('.hover-scale')?.classList.remove('shadow-lg');
            }
        });
    });
});

// Confirmation dialog for removing students
function confirmRemove(studentName) {
    event.preventDefault();
    
    Swal.fire({
        title: 'Remove Student?',
        text: `Are you sure you want to remove ${studentName} from this classroom?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, remove',
        cancelButtonText: 'Cancel',
        showClass: {
            popup: 'animate__animated animate__fadeIn animate__faster'
        },
        hideClass: {
            popup: 'animate__animated animate__fadeOut animate__faster'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            event.target.closest('form').submit();
        }
    });
    
    return false;
}
</script>

<?php include 'includes/footer.php'; ?>