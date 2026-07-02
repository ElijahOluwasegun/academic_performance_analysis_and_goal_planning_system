<?php
session_start();
unset($_SESSION["lecturer_ID"]);
unset($_SESSION["lecturer_name"]);
session_destroy();
header("Location: LecturerLoginInterface.php");
exit();
