<?php
namespace Email;

/**
 * Created by PhpStorm.
 * User: Chris Rocco
 * Date: 4/28/2017
 * Time: 2:33 PM
 */
class Email {

    // This will display as the sender and reply-to in all emails
    public static $app_email = 'uabbigdata@gmail.com';
    public static $app_name = 'Big Data';
    public $mail;

    /*------------------------------*/
    /* Functions to build the email */
    /*------------------------------*/
    function subject($subject){
        $this->mail->Subject = $subject;
    }

    function body($body){
        $this->mail->Body = $body;
    }

    function send(){
        return $this->mail->send();
    }

    /*-----------------*/
    /* Pre-made Emails */
    /*-----------------*/
    public static function validationEmail($to, $full_name, $user_id, $hash_code){
        $email = new Email($to, $full_name);
        $email->subject("Welcome to Big Data!");

        $html_email_template = file_get_contents (__DIR__ . '/templates/validate_email.html');

        $href = "https://researchcoder.com/api/users/validate?_key=$user_id&hash_code=$hash_code";
        $html_email_template = str_replace('{href}', $href, $html_email_template);
        $email->body($html_email_template);

        return $email;
    }
    public static function resetPasswordEmail($to, $full_name, $callback, $hash_code){
        $href = $callback . "?hash=" . $hash_code;

        $email = new Email($to, $full_name);
        $email->subject("Reset Password | Big Data");

        $html_email_template = file_get_contents (__DIR__ . '/templates/reset_password_email.html');

        $html_email_template = str_replace('{{href}}', $href, $html_email_template);

        $email->body($html_email_template);

        return $email;
    }
    public static function errorReportEmail ($error_body) {
        $settings = require __DIR__ . '/../../app/settings.php';
        $smtp_settings = $settings['settings']['smtp'];

        $mail = new \PHPMailer();

        $mail->isSMTP();
        $mail->Host         =   $smtp_settings['host'];
        $mail->SMTPAuth     =   $smtp_settings['smtp_auth'];
        $mail->Username     =   $smtp_settings['username'];
        $mail->Password     =   $smtp_settings['password'];
        $mail->SMTPSecure   =   $smtp_settings['smtp_secure'];
        $mail->Port         =   $smtp_settings['port'];

        $mail->From         =   Email::$app_email;
        $mail->FromName     =   Email::$app_name;

        $mail->addAddress("chris.rocco7@gmail.com", "Chris Rocco");
        $mail->addAddress("caleb.falcione@gmail.com", "Caleb Falcione");
        $mail->addReplyTo(Email::$app_email, Email::$app_name);
        $mail->isHTML(true);

        $mail->Subject = "Researchcoder.com bug report";
        $mail->Body = $error_body;

        return $mail;
    }

    function __construct($to_email, $to_name){
        $settings = require __DIR__ . '/../../app/settings.php';
        $smtp_settings = $settings['settings']['smtp'];

        $mail = new \PHPMailer();

        $mail->isSMTP();
        $mail->Host         =   $smtp_settings['host'];
        $mail->SMTPAuth     =   $smtp_settings['smtp_auth'];
        $mail->Username     =   $smtp_settings['username'];
        $mail->Password     =   $smtp_settings['password'];
        $mail->SMTPSecure   =   $smtp_settings['smtp_secure'];
        $mail->Port         =   $smtp_settings['port'];

        $mail->From         =   Email::$app_email;
        $mail->FromName     =   Email::$app_name;
        $mail->addAddress($to_email, $to_name);     // Add a recipient
        $mail->addReplyTo(Email::$app_email, Email::$app_name);
        $mail->isHTML(true);

        $this->mail = $mail;
    }
}
