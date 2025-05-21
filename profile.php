<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view your profile.");
    redirect("login.php");
}

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = clean_input($_POST['full_name']);
    $email = clean_input($_POST['email']);
    $bio = clean_input($_POST['bio']);
    
    $errors = [];
    
    // Validate email
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Check if email is already taken by another user
    if (!empty($email) && $email !== $user['email']) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "Email address is already in use by another account";
        }
    }
    
    // If no errors, update profile
    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, bio = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("sssi", $full_name, $email, $bio, $user_id);
        
        if ($stmt->execute()) {
            // Handle profile image upload if provided
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
                $upload_dir = 'uploads/profile_images/';
                $upload_result = upload_file($_FILES['profile_image'], $upload_dir);
                
                if ($upload_result['success']) {
                    $profile_image = $upload_result['file_path'];
                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->bind_param("si", $profile_image, $user_id);
                    $stmt->execute();
                } else {
                    $errors[] = $upload_result['message'];
                }
            }
            
            set_flash_message("success", "Your profile has been updated successfully.");
            redirect("profile.php");
        } else {
            $errors[] = "Failed to update profile. Please try again.";
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $errors = [];
    
    // Validate current password
    if (empty($current_password)) {
        $errors[] = "Current password is required";
    } elseif (!password_verify($current_password, $user['password'])) {
        $errors[] = "Current password is incorrect";
    }
    
    // Validate new password
    if (empty($new_password)) {
        $errors[] = "New password is required";
    } elseif (strlen($new_password) < 6) {
        $errors[] = "New password must be at least 6 characters long";
    }
    
    // Validate confirm password
    if ($new_password !== $confirm_password) {
        $errors[] = "New passwords do not match";
    }
    
    // If no errors, update password
    if (empty($errors)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            set_flash_message("success", "Your password has been changed successfully.");
            redirect("profile.php");
        } else {
            $errors[] = "Failed to change password. Please try again.";
        }
    }
}

// Get user statistics
$role = $user['role'];

if ($role === 'teacher') {
    // Get classroom count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM classrooms WHERE teacher_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $classroom_count = $result->fetch_assoc()['count'];
    
    // Get lesson count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM lessons l
        JOIN classrooms c ON l.classroom_id = c.id
        WHERE c.teacher_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $lesson_count = $result->fetch_assoc()['count'];
    
    // Get student count
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT cs.student_id) as count
        FROM classroom_students cs
        JOIN classrooms c ON cs.classroom_id = c.id
        WHERE c.teacher_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student_count = $result->fetch_assoc()['count'];
    
    // Get pending submissions count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM submissions s
        JOIN lessons l ON s.lesson_id = l.id
        JOIN classrooms c ON l.classroom_id = c.id
        WHERE c.teacher_id = ? AND s.grade IS NULL
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_submissions = $result->fetch_assoc()['count'];
    
} else if ($role === 'student') {
    // Get enrolled classes count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM classroom_students WHERE student_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $enrolled_count = $result->fetch_assoc()['count'];
    
    // Get submission count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM submissions WHERE student_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $submission_count = $result->fetch_assoc()['count'];
    
    // Get pending assignments
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM lessons l
        JOIN classrooms c ON l.classroom_id = c.id
        JOIN classroom_students cs ON c.id = cs.classroom_id
        LEFT JOIN submissions s ON l.id = s.lesson_id AND s.student_id = cs.student_id
        WHERE cs.student_id = ? AND s.id IS NULL
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_assignments = $result->fetch_assoc()['count'];
    
    // Get average grade
    $stmt = $conn->prepare("
        SELECT AVG(grade) as avg_grade
        FROM submissions
        WHERE student_id = ? AND grade IS NOT NULL
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $avg_grade = $result->fetch_assoc()['avg_grade'];
    $avg_grade = $avg_grade ? round($avg_grade, 1) : 'N/A';
}
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between">
        <h1 class="text-3xl font-bold">My Profile</h1>
        <div class="mt-2 md:mt-0">
            <a href="<?php echo $role === 'teacher' ? 'classrooms.php' : 'my_classes.php'; ?>" class="text-indigo-600 hover:underline flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Dashboard
            </a>
        </div>
    </div>
    
    <?php if (isset($errors) && !empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Left column: User stats -->
        <div>
            <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                <div class="p-6 text-center">
                    <div class="w-32 h-32 rounded-full mx-auto mb-4 overflow-hidden bg-gray-100">
                        <?php if (!empty($user['profile_image'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile Image" class="w-full h-full object-cover">
                        <?php else: ?>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-full w-full text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        <?php endif; ?>
                    </div>
                    
                    <h2 class="text-xl font-bold"><?php echo !empty($user['full_name']) ? htmlspecialchars($user['full_name']) : htmlspecialchars($user['username']); ?></h2>
                    <p class="text-gray-500 mb-2"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></p>
                    
                    <?php if (!empty($user['email'])): ?>
                        <p class="text-sm text-gray-600 mb-4 flex justify-center items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <?php echo htmlspecialchars($user['email']); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <div class="bg-gray-50 px-6 py-4">
                    <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide mb-3">Account Info</h3>
                    
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-gray-600">Username:</span>
                        <span class="font-medium"><?php echo htmlspecialchars($user['username']); ?></span>
                    </div>
                    
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-gray-600">Member since:</span>
                        <span class="font-medium"><?php echo isset($user['created_at']) ? format_date($user['created_at']) : 'N/A'; ?></span>
                    </div>
                    
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Last updated:</span>
                        <span class="font-medium"><?php echo isset($user['updated_at']) ? format_date($user['updated_at']) : 'N/A'; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Section -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold">Statistics</h3>
                </div>
                
                <div class="p-6">
                    <?php if ($role === 'teacher'): ?>
                        <div class="flex justify-between text-sm mb-3">
                            <span class="text-gray-600">Classrooms:</span>
                            <span class="font-medium"><?php echo $classroom_count; ?></span>
                        </div>
                        
                        <div class="flex justify-between text-sm mb-3">
                            <span class="text-gray-600">Total Lessons:</span>
                            <span class="font-medium"><?php echo $lesson_count; ?></span>
                        </div>
                        
                        <div class="flex justify-between text-sm mb-3">
                            <span class="text-gray-600">Total Students:</span>
                            <span class="font-medium"><?php echo $student_count; ?></span>
                        </div>
                        
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Pending Submissions:</span>
                            <span class="font-medium <?php echo $pending_submissions > 0 ? 'text-amber-600' : ''; ?>">
                                <?php echo $pending_submissions; ?>
                            </span>
                        </div>
                        
                        <?php if ($pending_submissions > 0): ?>
                            <div class="mt-4">
                                <a href="pending_submissions.php" class="block w-full text-center bg-indigo-100 hover:bg-indigo-200 text-indigo-700 py-2 px-4 rounded-md text-sm">
                                    View Pending Submissions
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($role === 'student'): ?>
                        <div class="flex justify-between text-sm mb-3">
                            <span class="text-gray-600">Enrolled Classes:</span>
                            <span class="font-medium"><?php echo $enrolled_count; ?></span>
                        </div>
                        
                        <div class="flex justify-between text-sm mb-3">
                            <span class="text-gray-600">Total Submissions:</span>
                            <span class="font-medium"><?php echo $submission_count; ?></span>
                        </div>
                        
                        <div class="flex justify-between text-sm mb-3">
                            <span class="text-gray-600">Pending Assignments:</span>
                            <span class="font-medium <?php echo $pending_assignments > 0 ? 'text-amber-600' : ''; ?>">
                                <?php echo $pending_assignments; ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Average Grade:</span>
                            <span class="font-medium"><?php echo $avg_grade; ?></span>
                        </div>
                        
                        <?php if ($pending_assignments > 0): ?>
                            <div class="mt-4">
                                <a href="my_submissions.php" class="block w-full text-center bg-indigo-100 hover:bg-indigo-200 text-indigo-700 py-2 px-4 rounded-md text-sm">
                                    View Pending Assignments
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right column: Profile settings -->
        <div class="md:col-span-2">
            <!-- Update Profile Form -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold">Profile Information</h3>
                </div>
                
                <form method="POST" action="profile.php" enctype="multipart/form-data" class="p-6">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="mb-4">
                        <label for="username" class="block text-gray-700 font-medium mb-2">Username</label>
                        <input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" 
                               class="w-full px-4 py-2 border rounded-lg bg-gray-100" disabled>
                        <p class="text-sm text-gray-500 mt-1">Username cannot be changed</p>
                    </div>
                    
                    <div class="mb-4">
                        <label for="full_name" class="block text-gray-700 font-medium mb-2">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <div class="mb-4">
                        <label for="email" class="block text-gray-700 font-medium mb-2">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <div class="mb-4">
                        <label for="bio" class="block text-gray-700 font-medium mb-2">Bio</label>
                        <textarea id="bio" name="bio" rows="4" 
                                  class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label for="profile_image" class="block text-gray-700 font-medium mb-2">Profile Image</label>
                        <input type="file" id="profile_image" name="profile_image" accept="image/*" 
                               class="w-full px-4 py-2 border rounded-lg">
                        <p class="text-sm text-gray-500 mt-1">Maximum file size: 2MB. Allowed formats: JPG, PNG, GIF</p>
                    </div>
                    
                    <button type="submit" class="w-full bg-indigo-600 text-white font-medium py-2 px-4 rounded-lg hover:bg-indigo-700 transition-colors duration-300">
                        Update Profile
                    </button>
                </form>
            </div>
            
            <!-- Change Password Form -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold">Change Password</h3>
                </div>
                
                <form method="POST" action="profile.php" class="p-6">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="mb-4">
                        <label for="current_password" class="block text-gray-700 font-medium mb-2">Current Password</label>
                        <input type="password" id="current_password" name="current_password" 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                    </div>
                    
                    <div class="mb-4">
                        <label for="new_password" class="block text-gray-700 font-medium mb-2">New Password</label>
                        <input type="password" id="new_password" name="new_password" 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                    </div>
                    
                    <div class="mb-6">
                        <label for="confirm_password" class="block text-gray-700 font-medium mb-2">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                    </div>
                    
                    <button type="submit" class="w-full bg-indigo-600 text-white font-medium py-2 px-4 rounded-lg hover:bg-indigo-700 transition-colors duration-300">
                        Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>