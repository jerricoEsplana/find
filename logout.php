<?php
require 'config.php';
logout_user();
header('Location: index.php');
exit;
