<?php
/**
 * Backup folder index guard - prevents directory listing
 */
header('Location: ' . str_repeat('../', 2) . 'admin/backup-restore.php');
exit;
