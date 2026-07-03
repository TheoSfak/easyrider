<?php
/**
 * EasyRide - Legacy reports redirect
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

setFlash('info', 'Οι παλιές αναφορές έχουν αφαιρεθεί από το EasyRide.');
redirect('dashboard.php');
