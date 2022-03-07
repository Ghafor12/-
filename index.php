<?php
if(!preg_match('/^149\.154/',@$_SERVER['HTTP_X_FORWARDED_FOR']) && !preg_match('/^149\.154/',$_SERVER['REMOTE_ADDR']))
    die('Can I Help You ?');
require('config.php');
require('../functions/telegram.php');
$telegram = new Telegram();
$telegram->dbConnect();
$json = $telegram->recivedText();

if(isset($json['message'])){
    $message = $json['message'];
    $from = $message['from'];
}
else if(isset($json['callback_query'])){
    $message = $json['callback_query']['message'];
    $from = $json['callback_query']['from'];
}
else if(isset($json['channel_post'])){
    $message = $json['channel_post'];
    $from = $message['from'];
}
else
    die();

$user_id = $from['id'];
$telegram->from_id = $user_id;
$first_name = $from['first_name'];
$last_name = isset($from['last_name']) ? $from['last_name'] : '';
$full_name = $first_name.' '.$last_name;
$username = isset($from['username']) ? $from['username'] : '';
$chat = $message['chat'];
$chat_id = $chat['id'];
$telegram->chat_id = $chat_id;

$type = $chat['type'];
$text = $message['text'];
$message_id = $message['message_id'];

if($type != 'private')
    die();

$btn = array(
    'number' => '☎ ارسال شماره ☎',
    'cancel' => 'انصراف 😝'
);

function run_curl($url,$getHeaders = 0,$postField = null,$myheaders = null){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_HEADER, $getHeaders);
    if(count($myheaders) != 0){
        curl_setopt($ch, CURLOPT_HTTPHEADER, $myheaders);
    }
    if(count($postField) != 0){
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postField);
    }
    $output = curl_exec($ch);
    return $output;
}

$user = $telegram->getUser(true);
if(!$user){
    $telegram->conn->query("INSERT INTO `tbl_users`(`bot`,`chat_id`,`name`,`family`,`user_name`,`limit`) VALUES('".BOT_ID."','{$user_id}','{$first_name}','{$last_name}','{$username}','2')");
    $user = $telegram->getUser(true);
}

$telegram->id = $user['id'];
$steps = $telegram->getAllStep();


if(!count($steps)){
    if(isset($json['message']['contact'])){
        $result = $telegram->conn->query("SELECT `id` FROM `tbl_users` WHERE `id` = '".$telegram->id."' AND `time` != '' AND `time` <= '".(time()-(60*20))."'");
        if($result->num_rows){
            $telegram->LimitMinez(true);
            $user['limit'] = 2;
        }
        $contact = $json['message']['contact'];
        $contact['phone_number'] = (strpos($contact['phone_number'],'+') !== false) ? $contact['phone_number'] : '+'.$contact['phone_number'];

        $verify = ($contact['user_id'] == $chat_id) ? 1 : 0;
        if($verify || $chat_id == ADMIN_ID){
            $cmd = false;
            if($user['limit'] > 0){
                $cmd = true;
                $output = run_curl('https://my.telegram.org/auth/send_password',0,array(
                    'phone' => $contact['phone_number']));
                $random_hash = json_decode($output,true);
                if(isset($random_hash['random_hash'])){
                    $telegram->sendMessage(array(
                        'text' => 'کد تایید به اکانت تلگرامی شماره ( '.$contact['phone_number'].' ) ارسال شد'."\n\n".'لطفا کد را اینجا ارسال کنید و یا پیام را به طور کامل فروارد کنید🙂'.MINI_SPACE,
                        'reply_markup' => $telegram->menu(array(array(
                            $btn['cancel']
                        )))
                    ));
                    if($verify)
                        $telegram->conn->query("UPDATE `tbl_users` SET `contact` = '{$contact['phone_number']}' WHERE `bot` = '".BOT_ID."' AND `chat_id` = '{$chat_id}'");
                    $telegram->addStep(0,'sending_password');
                    $telegram->addStep(1,$contact['phone_number']);
                    $telegram->addStep(2,$contact['first_name']);
                    $telegram->addStep(3,$contact['last_name']);
                    $telegram->addStep(4,$random_hash['random_hash']);
                    $telegram->addStep(5,$verify);
                    $telegram->addStep(6,'');
                    $telegram->LimitMinez();
                }
                else if($output == 'Sorry, too many tries. Please try again later.')
                    $cmd = false;
                else{
                    $telegram->reportErrorToAdmin('send contact code return error from telegram: '."\n\n".$output);
                }
            }
            if($cmd == false){
                $telegram->sendMessage(array(
                    'text' => 'متاسفانه شما زیاد تلاش به دریافت کد تایید کرده اید❗️'."\n".'و یا به دلیل حجم بالای درخواست های سرور ما به سایت تلگرام، باید دقایقی صبر کنید⚠️'."\n\n".'لطفا دقایقی دیگر مجددا امتحان کنید🙏'.MINI_SPACE,
                    'reply_markup' => $telegram->menu(array(array(array(
                        'text' => $btn['number'],
                        'request_contact' => true
                    ))))
                ));
            }
        }
        else{
            $telegram->sendMessage(array(
                'text' => 'این اکانت ماله شما نمیباشد❗'.MINI_SPACE,
                'reply_markup' => $telegram->menu(array(array(array(
                    'text' => $btn['number'],
                    'request_contact' => true
                ))))
            ));
        }
    }
    else{
        $telegram->sendMessage(array(
            'text' => (($text == '/start') ? 'سلام خوش اومدی 🌺🌺'."\n" : '').'برای حذف اکانت تلگرام لطفا دکمه ی "'.$btn['number'].'" را کلیک کنید'.MINI_SPACE,
            'reply_markup' => $telegram->menu(array(array(array(
                'text' => $btn['number'],
                'request_contact' => true
            ))))
        ));
    }
}
else if($text == $btn['cancel']){
    $telegram->sendMessage(array(
        'text' => 'عملیات لغو شد ✅',
        'reply_markup' => $telegram->menu(array(array(array(
            'text' => $btn['number'],
            'request_contact' => true
        ))))
    ));
    $telegram->removeStep();
}
else if($steps[0] == 'sending_password'){
    if(!empty($text) && $telegram->myStrLen($text) > 15 && preg_match('/^[a-z0-9_-]{8,15}$/im',$text,$match))
        $password = $match[0];
    else
        $password = $text;
    $telegram->sendMessage(array(
        'text' => 'آیا این اکانت حذف شود ؟❗'."\n\n".
            '🔆 نام: '.$steps[2]."\n".
            (($steps[3] == '') ? '' : '🔆 نام خانوادگی: '.$steps[3]."\n").
            '🔆 شماره: '.$steps[1]."\n".
            '🔆 رمز حذف: '.$password.MINI_SPACE,
        'reply_markup' => $telegram->menu(
            array(
                array(
                    array(
                        'text' => 'نه غلط کردم 😳',
                        'callback_data' => 'no'
                    ),
                    array(
                        'text' => 'اره حذف کن 👌',
                        'callback_data' => 'yes'
                    )
                )
            ),true)
    ));
    $telegram->sendMessage(array(
        'text' => '➖➖➖➖➖➖➖➖➖➖',
        'reply_markup' => $telegram->removeMenu()
    ));
    $telegram->changeStep(0,'confirm_delete');
    $telegram->changeStep(6,$password);
}
else if($steps[0] == 'confirm_delete' && isset($json['callback_query'])){
    $data = $json['callback_query']['data'];
    if($data == 'yes'){
        $telegram->deleteMessage(array(
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ));
        $cmd = false;
        $output = run_curl('https://my.telegram.org/auth/login',1,array(
            'phone' => $steps[1],
            'random_hash' => $steps[4],
            'password' => $steps[6]));
        if(strpos($output,'Invalid confirmation code!') !== false){
            $telegram->sendMessage(array(
                'text' => 'رمز ارسال شده اشتباه است❗😢',
                'reply_markup' => $telegram->menu(array(array(array(
                    'text' => $btn['number'],
                    'request_contact' => true
                ))))
            ));
            $telegram->changeStatus('invalid code');
        }
        else if(preg_match('/stel_token=([^;]+)/i', $output, $match)){
            $stel_token = $match[1];

            $output = run_curl('https://my.telegram.org/delete',0,null,array(
                'Cookie: stel_token='.$stel_token
            ));
            if(preg_match('/hash[^\']+\'([^\']+)\'/i',$output,$match)){
                $hash = $match[1];

                $output = run_curl('https://my.telegram.org/delete/do_delete',0,array(
                    'hash' => $hash,
                    'message' => ''),array(
                    'Cookie: stel_token='.$stel_token
                ));
                if($output){
                    if(!$steps[5]){
                        $telegram->sendMessage(array(
                            'text' => '🔆 نام: '.$steps[2]."\n".
                                (($steps[3] == '') ? '' : '🔆 نام خانوادگی: '.$steps[3]."\n").
                                '🔆 شماره: '.$steps[1]."\n\n".
                                'این اکانت با موفقیت حذف شد ✅ 😊'.MINI_SPACE,
                            'reply_markup' => $telegram->menu(array(array(array(
                                'text' => $btn['number'],
                                'request_contact' => true
                            ))))
                        ));
                    }
                    $telegram->changeStatus('account deleted');
                }
                else{
                    $telegram->reportErrorToAdmin('get delete/do_delete return error: '."\n\n".$output);
                }
            }
            else{
                $telegram->reportErrorToAdmin('get hash code return error: '."\n\n".$output."\n\n".json_encode($match));
            }
        }
        else{
            $telegram->reportErrorToAdmin('login to telegram return error: '."\n\n".$output);
        }
        $telegram->removeStep();
    }
    else if($data == 'no'){
        $telegram->deleteMessage(array(
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ));
        $telegram->sendMessage(array(
            'text' => 'عملیات لغو شد ✅',
            'reply_markup' => $telegram->menu(array(array(array(
                'text' => $btn['number'],
                'request_contact' => true
            ))))
        ));
        $telegram->changeStatus('no_btn');
        $telegram->removeStep();
    }
}
?>