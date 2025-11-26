<?php
//connection to mysql database

$host = "sql101.infinityfree.com";  //database host
$username = "if0_40276106";  //database user
$password = "mxnQ05zLFMb";    //database password
$database = "if0_40276106_Emma";  //database name

$con = mysqli_connect("$host","$username","$password","$database");

if(!$con)
{
    echo 'error in connection';
}




