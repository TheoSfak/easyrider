<?php
/**
 * EasyRide - Legacy report redirect
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

setFlash('info', 'Η παλιά αναφορά έχει αφαιρεθεί από το EasyRide.');
redirect('dashboard.php');
