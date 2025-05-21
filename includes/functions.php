<?php
// Start session if not already started
function session_start_safe() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Check if user is logged in
function is_logged_in() {
    session_start_safe();
    return isset($_SESSION['user_id']);
}

// Get current user role
function get_user_role() {
    session_start_safe();
    return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

// Check if user has specific role
function has_role($role) {
    session_start_safe();
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Check if user is a teacher
function is_teacher() {
    session_start_safe();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'teacher';
}

// Check if user is a student
function is_student() {
    session_start_safe();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'student';
}

// Check if user is an admin
function is_admin() {
    session_start_safe();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Redirect function with JavaScript fallback
function redirect($location) {
    // Check if headers have been sent
    if (!headers_sent()) {
        // If no output has been sent, use normal PHP redirect
        header("Location: $location");
        exit;
    } else {
        // If headers already sent, use JavaScript redirect
        echo "<script>window.location.href='$location';</script>";
        // Provide a fallback link for users without JavaScript
        echo "<noscript><meta http-equiv='refresh' content='0;url=$location'></noscript>";
        echo "<noscript><p>Please click <a href='$location'>here</a> to continue.</p></noscript>";
        exit;
    }
}

// Flash message system
function set_flash_message($type, $message) {
    session_start_safe();
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_flash_message() {
    session_start_safe();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Generate random string for file names
function generate_random_string($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

// Format date
function format_date($date) {
    return date('F j, Y', strtotime($date));
}

// Handle file upload
function upload_file($file, $directory) {
    // Create directory if it doesn't exist
    if (!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }

    $target_file = $directory . basename($file["name"]);
    $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    
    // Generate unique filename
    $new_filename = generate_random_string() . "_" . time() . "." . $file_extension;
    $target_path = $directory . $new_filename;

    // Check if file already exists
    if (file_exists($target_path)) {
        return [
            'success' => false,
            'message' => "Sorry, file already exists."
        ];
    }
    
    // Check file size (limit to 15MB)
    if ($file["size"] > 15000000) {
        return [
            'success' => false,
            'message' => "Sorry, your file is too large."
        ];
    }
    
    // Allow only certain file formats
    $allowed_extensions = ["jpg", "jpeg", "png", "gif", "pdf", "doc", "docx", "ppt", "pptx", "txt", "zip", "rar"];
    if (!in_array($file_extension, $allowed_extensions)) {
        return [
            'success' => false,
            'message' => "Sorry, only JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, PPT, PPTX, TXT, ZIP, and RAR files are allowed."
        ];
    }
    
    // Try to upload file
    if (move_uploaded_file($file["tmp_name"], $target_path)) {
        return [
            'success' => true,
            'file_path' => $target_path,
            'file_name' => $new_filename
        ];
    } else {
        return [
            'success' => false,
            'message' => "Sorry, there was an error uploading your file."
        ];
    }
}
?>