<?php
/**
 * Template for injecting IBM Plex Sans Arabic font + new style
 * into the 4 risk sub-reports
 */
$REPLACE_HEAD = <<<'PHP'
<!DOCTYPE html>
<html lang="ar" dir="<?= lang_dir() ?>">
<head>
<meta charset="UTF-8">
<title>PHP;
$REPLACE_HEAD_END = <<<'PHP'
</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --font-ar: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif; }
PHP;
PHP;
