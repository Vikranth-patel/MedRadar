<?php
// logout.php — POST + CSRF only, so another site cannot log a chemist out.
require_once __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) { logout_user(); }
redirect('index.php');
