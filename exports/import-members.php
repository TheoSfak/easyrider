<?php
// Compatibility endpoint for bookmarks created before the import controller
// was consolidated at the application root.
header('Location: ../import-members.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
