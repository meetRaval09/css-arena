<?php
/* Shared page head. Set $PAGE_TITLE and, from a subfolder, $BASE = '..'. */
if (!isset($PAGE_TITLE)) $PAGE_TITLE = 'CSS Arena';
if (!isset($BASE))       $BASE = '.';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($PAGE_TITLE) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css', $BASE) ?>">
</head>
<body>
<?php include __DIR__ . '/brand.php'; ?>
