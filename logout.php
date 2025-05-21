<?php
require_once 'includes/functions.php';

// Start the session if it's not already started
session_start_safe();

// Destroy the session
session_destroy();

// Redirect to the login page
redirect('index.php');