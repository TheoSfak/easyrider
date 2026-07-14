<?php
// Legacy member export URL. Keep bookmarks working without maintaining
// a second export implementation.
header('Location: export-members.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
