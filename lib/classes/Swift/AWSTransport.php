<?php
	/*
	* This file requires SwiftMailer.
	* (c) 2011 John Hobbs
	*
	* For the full copyright and license information, please view the LICENSE
	* file that was distributed with this source code.
	*/

	/**
	* Sends Messages over AWS.
	* @package Swift
	* @subpackage Transport
	* @author John Hobbs
	*/
	class Swift_AWSTransport extends Swift_Transport_AWSTransport {

		/** the service access key */
		private $AWSAccessKeyId;
		/** the service secret key */
		private $AWSSecretKey;
		/** the service endpoint */
		private $endpoint;
		/** is debug mode activated */
		private $debug;

		/**
		* Create a new AWSTransport.
		* @param string $AWSAccessKeyId Your access key.
		* @param string $AWSSecretKey Your secret key.
		* @param boolean $debug Set to true to enable debug messages.
		* @param string $endpoint The AWS endpoint to use.
		*/
		public function __construct($AWSAccessKeyId = null , $AWSSecretKey = null, $debug = false, $endpoint = 'https://email.us-east-1.amazonaws.com/') {
			call_user_func_array(
				array($this, 'Swift_Transport_AWSTransport::__construct'),
				Swift_DependencyContainer::getInstance()
					->createDependenciesFor('transport.aws')
				);

			$this->AWSAccessKeyId = $AWSAccessKeyId;
			$this->AWSSecretKey = $AWSSecretKey;
			$this->endpoint = $endpoint;
			$this->debug = $debug;
		}

		/**
		* Create a new AWSTransport.
		* @param string $AWSAccessKeyId Your access key.
		* @param string $AWSSecretKey Your secret key.
		*/
		public static function newInstance( $AWSAccessKeyId , $AWSSecretKey ) {
			return new Swift_AWSTransport( $AWSAccessKeyId , $AWSSecretKey );
		}

		public function setAccessKeyId($val) {
			$this->AWSAccessKeyId = $val;
		}

		public function setSecretKey($val) {
			$this->AWSSecretKey = $val;
		}

		public function setDebug($val) {
			$this->debug = $val;
		}

		public function setEndpoint($val) {
			$this->endpoint = $val;
		}

		/**
		* Send the given Message.
		*
		* Recipient/sender data will be retreived from the Message API.
		* The return value is the number of recipients who were accepted for delivery.
		*
		* @param Swift_Mime_Message $message
		* @param string[] &$failedRecipients to collect failures by-reference
		* @return int
		* @throws AWSConnectionError
		*/
		public function send( Swift_Mime_Message $message, &$failedRecipients = null ) {

			if ($evt = $this->_eventDispatcher->createSendEvent($this, $message))
			{
				$this->_eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
				if ($evt->bubbleCancelled())
				{
					return 0;
				}
			}

			$result = $this->_doSend($message, $failedRecipients);

			if( defined('SWIFT_AWS_DEBUG') || $this->debug ) {
				echo "=== Start AWS Response ===\r\n";
				echo $result->body;
				echo "===	End AWS Response	===\r\n";
			}

			$success = (200 == $result->code);

            // Handle errors in AWS response
            if (isset($result->xml->Error)) {
                $error = $result->xml->Error;
                if ($error->Code == 'MessageRejected') {
                    throw new AWSMessageRejectedException(
                        "Message for {$message->getTo()} was rejected by AWS: {$error->Message}"
                    );
                } else {
                    $errorMessage = sprintf(
                        "AWS responded with error: [Type: '%s'; Code: '%s'; Message: '%s']",
                        $error->Type, $error->Code, $error->Message
                    );
                    throw new AWSErrorResponseException($errorMessage);
                }
            }

			if ($evt)
			{
				$evt->setResult($success ? Swift_Events_SendEvent::RESULT_SUCCESS : Swift_Events_SendEvent::RESULT_FAILED);
				$this->_eventDispatcher->dispatchEvent($evt, 'sendPerformed');
			}

			if( $success ) {
				return count((array) $message->getTo());
			}
			else {
				return 0;
			}
		}

		/**
		 * do send through the API
		 *
		 * @param Swift_Mime_Message $message
		 * @param string[] &$failedRecipients to collect failures by-reference
		 * @return AWSResponse
		 */
		protected function _doSend( Swift_Mime_Message $message, &$failedRecipients = null )
		{
			$date = date( 'D, j F Y H:i:s O' );
			if( function_exists( 'hash_hmac' ) and in_array( 'sha1', hash_algos() ) ) {
				$hmac = base64_encode( hash_hmac( 'sha1', $date, $this->AWSSecretKey, true ) );
			}
			else {
				$hmac = $this->calculate_RFC2104HMAC( $date, $this->AWSSecretKey );
			}
			$auth = "AWS3-HTTPS AWSAccessKeyId=" . $this->AWSAccessKeyId . ", Algorithm=HmacSHA1, Signature=" . $hmac;

			$host = parse_url( $this->endpoint, PHP_URL_HOST );
			$path = parse_url( $this->endpoint, PHP_URL_PATH );

			$fp = fsockopen( 'ssl://' . $host , 443, $errno, $errstr, 30 );

			if( ! $fp ) {
				throw new AWSConnectionError( "$errstr ($errno)" );
			}

			$socket = new ChunkedTransferSocket( $fp, $host, $path );

			$socket->header("Date", $date);
			$socket->header("X-Amzn-Authorization", $auth);

			$socket->write("Action=SendRawEmail&RawMessage.Data=");

			$ais = new Swift_AWSInputByteStream($socket);
			$message->toByteStream($ais);
			$ais->flushBuffers();

			$result = $socket->read();

			return $result;
		}

		/**
		* Cribbed from php-aws - Thanks!
		* https://github.com/tylerhall/php-aws/blob/master/class.awis.php
		* (c) Tyler Hall
		* MIT License
		*/
		protected function calculate_RFC2104HMAC($data, $key) {
			return base64_encode (
				pack("H*", sha1((str_pad($key, 64, chr(0x00))
				^(str_repeat(chr(0x5c), 64))) .
				pack("H*", sha1((str_pad($key, 64, chr(0x00))
				^(str_repeat(chr(0x36), 64))) . $data))))
			);
		}

		public function isStarted() {}
		public function start() {}
		public function stop() {}

		/**
		 * Register a plugin.
		 *
		 * @param Swift_Events_EventListener $plugin
		 */
		public function registerPlugin(Swift_Events_EventListener $plugin)
		{
			$this->_eventDispatcher->bindEventListener($plugin);
		}

	} // AWSTransport


	/**
	 * Convenience methods to use a socket for chunked transfer in HTTP
	 */
	class ChunkedTransferSocket {

		/**
		 * @param $socket
		 * @param $host
		 * @param $path
		 * @param $method
		 */
		public function __construct( $socket, $host, $path, $method="POST" ) {

			$this->socket = $socket;
			$this->write_started = false;
			$this->write_finished = false;
			$this->read_started = false;

			$this->fwrite( $this->socket, "$method $path HTTP/1.1\r\n" );

			$this->header( "Host", $host );
			if( "POST" == $method ) {
				$this->header( "Content-Type", "application/x-www-form-urlencoded" );
			}
			$this->header( "Connection", "close" );
			$this->header( "Transfer-Encoding", "chunked" );
		}

		/**
		 * Add an HTTP header
		 *
		 * @param $header
		 * @param $value
		 */
		public function header ( $header, $value ) {
			if( $this->write_started ) { throw new InvalidOperationException( "Can not write header, body writing has started." ); }
            $this->fwrite( $this->socket, "$header: $value\r\n" );
            $this->fflush( $this->socket );
		}

		/**
		 * Write a chunk of data
		 * @param $chunk
		 */
		public function write ( $chunk ) {
			if( $this->write_finished ) { throw new InvalidOperationException( "Can not write, reading has started." ); }

			if( ! $this->write_started ) {
                $this->fwrite( $this->socket, "\r\n" ); // Start message body
				$this->write_started = true;
			}

            $this->fwrite( $this->socket, sprintf( "%x\r\n", strlen( $chunk ) ) );
            $this->fwrite( $this->socket, $chunk . "\r\n" );
			$this->fflush( $this->socket );
		}

		/**
		 * Finish writing chunks and get ready to read.
		 */
		public function finishWrite () {
			$this->write("");
			$this->write_finished = true;
		}

		/**
		 * Read the socket for a response
		 */
		public function read () {
			if( ! $this->write_finished ) { $this->finishWrite(); }
			$this->read_started = true;

			$response = new AWSResponse();
			while( ! feof( $this->socket ) ) {
				$response->line( fgets( $this->socket ) );
			}
			$response->complete();
			fclose( $this->socket );

			return $response;
		}

        /**
         * Wrapper around fflush call
         *
         * @param $sock
         * @param $data
         *
         * @return void
         */
        protected function fflush($sock)
        {
            $oldh = set_error_handler(
                function ($errno, $errstr) {
                    throw new InvalidOperationException($errstr);
                }
            );

            try {
                @fflush($sock);
            } catch (InvalidOperationException $e) {
                set_error_handler($oldh);
                throw $e;
            }

            set_error_handler($oldh);
        }

        /**
         * Wrapper around fwrite call
         *
         * @param $sock
         * @param $data
         *
         * @return integer
         */
        protected function fwrite($sock, $data)
        {
            $oldh = set_error_handler(
                function ($errno, $errstr) {
                    throw new InvalidOperationException($errstr);
                }
            );

            $num = 0;

            try {
                $num = @fwrite($sock, $data);
            } catch (InvalidOperationException $e) {
                set_error_handler($oldh);
                throw $e;
            }

            set_error_handler($oldh);

            return $num;
        }
	}

	/**
	 * A wrapper to parse an AWS HTTP response
	 */
	class AWSResponse {

		public $headers = array();
		public $code = 0;
		public $message = '';
		public $body = '';
		public $xml = null;

		const STATE_EMPTY = 0;
		const STATE_HEADERS = 1;
		const STATE_BODY = 2;

		protected $state = self::STATE_EMPTY;

		public function line ( $line ) {

			switch( $this->state ) {
				case self::STATE_EMPTY:
					if( ! $line ) {
						throw new AWSEmptyResponseException('Empty response from AWS');
					}
					$split = explode( ' ', $line );
					$this->code = $split[1];
					$this->message = implode( array_slice( $split, 2 ), ' ' );
					$this->state = self::STATE_HEADERS;
					break;
				case self::STATE_HEADERS:
					if( "\r\n" == $line ) {
						$this->state = self::STATE_BODY;
						break;
					}

					$pos = strpos( $line, ':' );
					if( false === $pos ) { throw new InvalidHeaderException( $line ); }
					$key = substr( $line, 0, $pos );
					$this->headers[$key] = substr( $line, $pos );
					break;
				case self::STATE_BODY:
					$this->body .= $line;
					break;
			}

		}

		public function complete () {
			$this->xml = simplexml_load_string( $this->body );
		}

	}

	class AWSConnectionError extends Swift_TransportException {}
	class InvalidOperationException extends Swift_TransportException {}
	class InvalidHeaderException extends Swift_TransportException {}
	class AWSEmptyResponseException extends Swift_TransportException {}
    class AWSErrorResponseException extends Swift_TransportException {}
    class AWSMessageRejectedException extends Swift_TransportException {}