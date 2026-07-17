<?php
// Minimal auth check only - no HTML, no DB, no CSS
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['auth'])) { header('Location: login.php'); exit; }
