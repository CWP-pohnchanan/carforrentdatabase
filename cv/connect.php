<?php
$hostname = 'localhost';
$username = 'root';
$password = '';
$database = 'customer_account';
$port = NULL ;
$socket = NULL ;
$connect = mysqli_connect($hostname,$username,$password,$database);

if(!$connect){
    die('การเชื่อมฐานข้อมูลล้มเหลว ;' .mysqli_connect_error($connect));
}else{
    mysqli_set_charset($connect,'utf8');
}

?>