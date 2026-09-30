<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'connect.php';

if(isset($_POST['full_name']) && isset($_POST['email']) && isset($_POST['password']) && isset($_POST['birthdate']) && isset($_POST['phone'])){
     $full_name = htmlspecialchars(mysqli_real_escape_string($connect, $_POST['full_name']));
     $email = htmlspecialchars(mysqli_real_escape_string($connect, $_POST['email']));
     $password = htmlspecialchars(mysqli_real_escape_string($connect, $_POST['password']));
     $birthdate = htmlspecialchars(mysqli_real_escape_string($connect, $_POST['birthdate']));
     $phone = htmlspecialchars(mysqli_real_escape_string($connect, $_POST['phone']));
    
     if(empty($full_name)){
        die(header('Location: register.php')); //คุณไม่ได้กรอกชื่อผู้ใช้
     }elseif(empty($email)){
        die(header('Location:register.php')); //คุณไม่ได้กรอกอีเมล
     }elseif(empty($password)){
        die(header('Location: register.php')); //คุณไม่ได้กรอกรหัสผ่าน
     }elseif(empty($birthdate)){
        die(header('Location: register.php')); //คุณไม่ได้กรอกการยืนยันรหัสผ่าน
     }elseif(empty($phone)){
        die(header('Location: register.php')); //กรุณายืนยันรหัสผ่านให้ถูกต้อง
     }else{
         $query_check_email_account = "SELECT email FROM customeraccount WHERE email = '$email'";
         $call_back_query_check_email_account = mysqli_query($connect, $query_check_email_account);
         if(mysqli_num_rows($call_back_query_check_email_account) > 0){
            die(header('Location: register.php')); //มีผู้ใช้อีเมลนี้แล้ว
         }else{
             $length = random_int(97, 128);
             $salt = bin2hex(random_bytes($length)); //สร้างค่าเกลือ
             $password = $password . $salt; //เอารหัสผ่านต่อกับค่าเกลือ
             $algo = PASSWORD_ARGON2ID;
             $options = [
                'cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
                'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
                'threads' => PASSWORD_ARGON2_DEFAULT_THREADS
             ];
             $password_account =password_hash($password, $algo, $options); //นำรหัสผ่านที่ต่อกับค่าเกลือแล้ว เข้ารหัสด้วยวิธี ARGON2ID
             $query_create_account = "INSERT INTO customeraccount(full_name, email,password_account, phone, birthdate, salt)
             VALUES('$full_name', '$email', '$password_account', '$phone', '$birthdate', '$salt')";    
             $call_back_create_account = mysqli_query($connect, $query_create_account);
             if($call_back_create_account){
                 die(header('Location: login.php')); //สร้างบัญชีสำเร็จ
             }else{
                die(header('Location: register.php')); //สร้างบัญชีล้มเหลว
             }
         }
     }

}else{
    die(header('Location: register.html')); //ไม่มีข้อมูล
}

?>