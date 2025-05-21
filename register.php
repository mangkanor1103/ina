<?php
include 'includes/header.php';

// Process registration form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = clean_input($_POST['username']);
    $email = clean_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = clean_input($_POST['role']);
    
    // Validate input
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "Username is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    if (!in_array($role, ['student', 'teacher'])) {
        $errors[] = "Invalid role selected";
    }
    
    // Check if username or email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $errors[] = "Username or email already exists";
    }
    
    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);
        
        if ($stmt->execute()) {
            set_flash_message("success", "Registration successful! You can now login.");
            redirect("login.php");
        } else {
            $errors[] = "Registration failed. Please try again.";
        }
    }
}
?>

<div class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-md">
    <h2 class="text-2xl font-bold mb-6 text-center">Register</h2>
    
    <?php if (!empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="register.php">
        <div class="mb-4">
            <label for="username" class="block text-gray-700 font-medium mb-2">Username</label>
            <input type="text" id="username" name="username" value="<?php echo isset($username) ? $username : ''; ?>" 
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        
        <div class="mb-4">
            <label for="email" class="block text-gray-700 font-medium mb-2">Email</label>
            <input type="email" id="email" name="email" value="<?php echo isset($email) ? $email : ''; ?>" 
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        
        <div class="mb-4">
            <label for="password" class="block text-gray-700 font-medium mb-2">Password</label>
            <input type="password" id="password" name="password" 
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        
        <div class="mb-4">
            <label for="confirm_password" class="block text-gray-700 font-medium mb-2">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" 
                   class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        
        <div class="mb-6">
            <label for="role" class="block text-gray-700 font-medium mb-2">I am a:</label>
            <select id="role" name="role" 
                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="student" <?php echo (isset($role) && $role == 'student') ? 'selected' : ''; ?>>Student</option>
                <option value="teacher" <?php echo (isset($role) && $role == 'teacher') ? 'selected' : ''; ?>>Teacher</option>
            </select>
        </div>
        
        <button type="submit" class="w-full bg-indigo-600 text-white font-medium py-2 px-4 rounded-lg hover:bg-indigo-700 transition-colors duration-300">
            Register
        </button>
    </form>
    
    <p class="mt-4 text-center text-gray-600">
        Already have an account? <a href="login.php" class="text-indigo-600 hover:underline">Login here</a>
    </p>
</div>

<?php include 'includes/footer.php'; ?>