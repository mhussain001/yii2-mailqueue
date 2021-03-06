<?php

/**
 * MailQueue.php
 * @author Saranga Abeykoon http://nterms.com
 */

namespace mhussain001\mailqueue;

use Yii;
use yii\swiftmailer\Mailer;
use mhussain001\mailqueue\Message;
use mhussain001\mailqueue\models\Queue;

/**
 * MailQueue is a sub class of [yii\switmailer\Mailer](https://github.com/yiisoft/yii2-swiftmailer/blob/master/Mailer.php)
 * which intends to replace it.
 *
 * Configuration is the same as in `yii\switmailer\Mailer` with some additional properties to control the mail queue
 *
 * ~~~
 * 	'components' => [
 * 		...
 * 		'mailqueue' => [
 * 			'class' => 'mhussain001\mailqueue\MailQueue',
 *			'table' => '{{%mail_queue}}',
 *			'mailsPerRound' => 10,
 *			'maxAttempts' => 3,
 * 			'transport' => [
 * 				'class' => 'Swift_SmtpTransport',
 * 				'host' => 'localhost',
 * 				'username' => 'username',
 * 				'password' => 'password',
 * 				'port' => '587',
 * 				'encryption' => 'tls',
 * 			],
 * 		],
 * 		...
 * 	],
 * ~~~
 *
 * @see http://www.yiiframework.com/doc-2.0/yii-swiftmailer-mailer.html
 * @see http://www.yiiframework.com/doc-2.0/ext-swiftmailer-index.html
 *
 * This extension replaces `yii\switmailer\Message` with `mhussain001\mailqueue\Message'
 * to enable queuing right from the message.
 *
 */
class MailQueue extends Mailer
{
	const NAME = 'mailqueue';

	/**
	 * @var string message default class name.
	 */
	public $messageClass = 'mhussain001\mailqueue\Message';

	/**
	 * @var string the name of the database table to store the mail queue.
	 */
	public $table = '{{%mail_queue}}';

	/**
	 * @var integer the default value for the number of mails to be sent out per processing round.
	 */
	public $mailsPerRound = 10;

	/**
	 * @var integer maximum number of attempts to try sending an email out.
	 */
	public $maxAttempts = 3;

	/**
	 * Initializes the MailQueue component.
	 */
	public function init()
	{
		parent::init();
	}

	/**
	 * Sends out the messages in email queue and update the database.
	 *
	 * @return boolean true if all messages are successfully sent out
	 */
	public function process()
	{
		if (Yii::$app->db->getTableSchema($this->table) == null) {
			throw new \yii\base\InvalidConfigException('"' . $this->table . '" not found in database. Make sure the db migration is properly done and the table is created.');
		}

		$success = true;

		$items = Queue::find()->where(['and', ['sent_time' => NULL], ['!=', 'to', 'a:0:{}'], ['<', 'attempts', $this->maxAttempts], ['<=', 'time_to_send', date('Y-m-d H:i:s')]])->orderBy(['created_at' => SORT_ASC])->limit($this->mailsPerRound);
		foreach ($items->each() as $item) {
		    if ($message = $item->toMessage()) {
		    	$message->setDirectMessage(false);
			$attributes = ['attempts', 'last_attempt_time'];
			if ($this->send($message)) {
			    $item->sent_time = new \yii\db\Expression('NOW()');
			    $attributes[] = 'sent_time';
			} else {
			    $success = false;
			}

			$item->attempts++;
			$item->last_attempt_time = new \yii\db\Expression('NOW()');

			$item->updateAttributes($attributes);
		    }
		}


		return $success;
	}

	protected function sendMessage($message)
	{
		if($message->getDirectMessage()) {
	        $item = new Queue();

	        $item->from = serialize($message->from);
	        $item->to = serialize($message->getTo());
	        $item->cc = serialize($message->getCc());
	        $item->bcc = serialize($message->getBcc());
	        $item->reply_to = serialize($message->getReplyTo());
	        $item->charset = $message->getCharset();
	        $item->subject = $message->getSubject();
	        $item->attempts = 1;
	        $item->swift_message = base64_encode(serialize($message));
	        $item->time_to_send = date('Y-m-d H:i:s', time());

	        $parts = $message->getSwiftMessage()->getChildren();
	        // if message has no parts, use message
	        if ( !is_array($parts) || !sizeof($parts) ) {
	            $parts = [ $message->getSwiftMessage() ];
	        }

	        foreach( $parts as $part ) {
	            if( !( $part instanceof \Swift_Mime_Attachment ) ) {
	                /* @var $part \Swift_Mime_MimeEntity */
	                switch( $part->getContentType() ) {
	                    case 'text/html':
	                        $item->html_body = $part->getBody();
	                    break;
	                    case 'text/plain':
	                        $item->text_body = $part->getBody();
	                    break;
	                }

	                if( !$item->charset ) {
	                    $item->charset = $part->getCharset();
	                }
	            }
	        }
	    }
        $mailSent = false;
		if(parent::sendMessage($message)) {
		    $mailSent = true;
		}

		if($message->getDirectMessage()) {
		    $item->sent_time = new \yii\db\Expression('NOW()');
			$item->last_attempt_time = new \yii\db\Expression('NOW()');
			if ($item->validate()) {
				$item->save();
			    // all inputs are valid
			} else {
			    // validation failed: $errors is an array containing error messages
			    $errors = $item->errors;
			}
        }
        return $mailSent;
	}
}
