<?php
include 'includes/header.php';

// Check if user is logged in
if (!is_logged_in()) {
    set_flash_message("error", "Please login to view submissions.");
    redirect("login.php");
}

// Check if lesson ID is provided
if (!isset($_GET['lesson_id']) || !is_numeric($_GET['lesson_id'])) {
    set_flash_message("error", "Invalid lesson ID.");
    redirect("index.php");
}

$lesson_id = $_GET['lesson_id'];

// Redirect to the proper submissions page
redirect("lesson_submissions.php?id=" . $lesson_id);
?>

<div class="max-w-md mx-auto bg-white p-8 rounded-lg shadow-md mt-8">
    <h2 class="text-2xl font-bold mb-6 text-center">Redirecting...</h2>
    <p class="text-center mb-4">Taking you to the submissions page...</p>
    <div class="flex justify-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>