<?php
// limonade
require_once 'lib/limonade.php';
require_once 'functions.php';
session_start();


dispatch('/', 'login');
function login(){
  if (!isset($_SESSION['control_num']))
    return render('login.html.php');
  else
    redirect_to('/manage');
}

dispatch_post('/login', 'login_post');
function login_post(){
  $pass = $_POST['password'];
  $control = $_POST['control_num'];
  $q = "select control from users where password = '$pass'";
  $user = select_q($q);
  //var_dump($user[0]);
  if ($user[0]['control'] == 'admin'){
    $_SESSION['admin'] = true;
    $_SESSION['control_num'] = $control;
  } elseif ($user[0]['control'] == 'general'){
    $_SESSION['control_num'] = $control;    
  }
  //$control = 3;
  redirect_to('/manage');
}

dispatch('/manage', 'get_control');
function get_control(){
  global $conn;
  $control_num = (isset($_SESSION['control_num']) ? $_SESSION['control_num'] : false );
  // admin can go to any page
  if ( isset($_SESSION['admin']) && $_SESSION['admin'] && isset($_GET['control_num'])){
    $control_num = $_GET['control_num'];
  }

  if ($control_num){ // if control number is not defined then redirect to login page.
    $after_cn = []; $acco = [];
    $q = "select check_ins.*, receipts.name, receipts.college from check_ins inner join receipts on check_ins.receipt_id=receipts.id ";
    
    if ($control_num == 2){
      $q2 = $q."where kit_issued = false || id_issued = false";
      $after_cn = select_q($q2);      
    } elseif ($control_num == 3) {
      $q3 = $q."where kit_issued = true && id_issued = true && (bhawan = '' || room_no = '' || caution = false)"; // these column should be null by default but its not working :(. Any ideas?
      $after_cn = select_q($q3);
      $q_acco = "select * from accomodation where available > 0 order by available";
      $acco = select_q($q_acco);
    }
    return render('control'.$control_num.'.html.php', 'default.php', array('cn'=>$after_cn, 'acco'=>$acco));
  
  } else{
    redirect_to('/');
  }
}

// used from ajax in control 1
dispatch('/check-cogni-id', 'check_cogni_id');
function check_cogni_id(){
  global $conn;
  $cogni_id = $_GET['cogni_id'];
  // all tickets in which this cogni-id exits
  $q = "select * from receipts where cogni_id = '$cogni_id' or email like '$cogni_id%'";
  $result = $conn->query($q);
  if ($result->num_rows > 0){
    while ($row = $result->fetch_assoc()){
      // now all the entries on same ticket
      $q = "select * from receipts where ticket_id = '".$row['ticket_id']."'";
      $result2 = $conn->query($q);
      if ($result2->num_rows > 0){
        while ($row2 = $result2->fetch_assoc()){
          $data[] = $row2;
        }
      }
    }
  }
  return json_encode($data);
}

// on submitting the control-1
dispatch_post('/c1-submit', 'save_control1');
function save_control1(){
  global $conn; $c1_values = ''; $c1_ws_values = '';
  $c1_data = json_decode($_POST['c1_data'], true);
  
  foreach ($c1_data as $key => $value) {
    if (!$c1_data[$key]['is_workshop']){
      $c1_values .= "('".$c1_data[$key]['receipt_id']."', '".$c1_data[$key]['cogni_id']."', '".$c1_data[$key]['ticket_id']."', '".$c1_data[$key]['noc']."', '".$c1_data[$key]['college_id']."', '".$c1_data[$key]['is_acco']."', '".date('Y-m-d h:i:sa')."'),";
    } else{
      $c1_ws_values .= "('".$c1_data[$key]['receipt_id']."', '".$c1_data[$key]['cogni_id']."', '".$c1_data[$key]['ticket_id']."', '".$c1_data[$key]['ws_name']."', '".date('Y-m-d h:i:sa')."'),";
    }    
  }

  $c1_values = rtrim($c1_values, ',');
  $c1_ws_values = rtrim($c1_ws_values, ',');
  $check_ins_cols = "receipt_id, cogni_id, ticket_id, noc, college_id, is_acco, control1_at";

  $q1 = "insert into check_ins ($check_ins_cols) values $c1_values";
  $q2 = "insert into ws_participants (receipt_id, cogni_id, ticket_id, ws_name, control1_at) values $c1_ws_values";
  //echo $q;
  if($c1_values != ''){
    $status1 = insert_q($q1);
    if ($c1_ws_values != '')
      return insert_q($q2);

  } else
    return '[Failed] Please verify identity card of at least one participant.';
  
  return $status1;
}
// on submitting the control-2
dispatch_post('/c2-submit', 'save_control2');
function save_control2(){
  $c2_values = "kit_issued = ".$_POST['kit_issued'].", id_issued = ".$_POST['id_issued'].", control2_at = '".date('Y-m-d h:i:sa')."'";
  $q = "update check_ins set $c2_values where receipt_id = '".$_POST['receipt_id']."'";
  //echo $q;
  return insert_q($q);
}

// on submitting the control-3
dispatch_post('/c3-submit', 'save_control3');
function save_control3(){
  $bhawan = $_POST['bhawan'];
  $room_no = $_POST['room_no'];
  $caution = $_POST['caution'];
  $receipt_id = $_POST['receipt_id'];
  $c3_values = "caution = ".$caution.", bhawan = '".$bhawan."', room_no = '".$room_no."'";
  
  $q1 = "update check_ins set $c3_values where receipt_id = '".$receipt_id."'";  
  $q2 = "update accomodation set available = available - 1 where bhawan = '$bhawan' && room_no = '$room_no'";
  $status = insert_q($q2);
  //echo $q;
  if ($status == 'success')
    return insert_q($q1);
  else
    return $status;
}

dispatch('/logout', 'logout');
function logout(){
  unset($_SESSION['control_num']);
  if (isset($_SESSION['admin']))
    unset($_SESSION['admin']);
  redirect_to('/');
}

//Route for all public assets
dispatch('/public/**', 'public_pages');
function public_pages(){
  $filename = params(0);
  return render_file(option('public_dir').'/'.$filename);
};

function before(){
  layout('default.php');
}

function configure(){
  global $conn;
  $conn = new mysqli('127.0.0.1', 'root', 'root', 'cogni-controls');
  if ($conn->connect_error)
    die('Connection failed: '.$conn->connect_error);
}

run();

?>