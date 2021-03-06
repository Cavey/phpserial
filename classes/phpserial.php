<?php defined('SYSPATH') OR die('No direct script access.');

class PHPSerial 
{
	const SERIAL_DEVICE_NOTSET = 0;
	const SERIAL_DEVICE_SET = 1;
	const SERIAL_DEVICE_OPENED = 2;
	
	protected $_device = null;
    protected $_winDevice = null;
    protected $_rate = null;
	protected $_dHandle = null;
	protected $_dState = self::SERIAL_DEVICE_NOTSET;
	protected $_buffer = '';
	
	/**
	 * This var says if buffer should be flushed by send (true) or
	 * manually (false)
	 *
	 * @var bool
	 */
	protected $_auto_flush = true;
	
	protected $_os = '';

	public function __construct($device = NULL, $rate=9600)
	{
		$sysName = substr(php_uname(), 0, 4);
		if ($sysName == 'Linu') 
		{
			$this->_os = 'linux';
			if ($this->_exec('stty') === 0) 
			{
				register_shutdown_function(array($this, 'close'));
			} 
			else 
			{
				throw new Kohana_Exception('No stty availible, unable to run.',E_USER_ERROR);
			}
		} 
		elseif ($sysName === 'Darw') 
		{
			$this->_os = 'osx';
			register_shutdown_function(array($this, 'close'));
		} 
		elseif ($sysName === 'Wind') 
		{
			$this->_os = 'windows';
			register_shutdown_function(array($this, 'close'));
		} 
		else 
		{
			throw new Kohana_Exception('Host OS is neither osx, linux nor windows, unable to run.', E_USER_ERROR);
		}
		if($device != NULL)
		{
			$this->set_device($device, $rate);
			if( $this->_dState == self::SERIAL_DEVICE_SET )
			{
				$this->open();
			}
		}
	}
	//
	// OPEN/CLOSE DEVICE SECTION -- {START}
	//

	/**
	 * Valid formats are
	 *		/dev/ttyYYYxxx (recommended, default for Linux)
	 *		COMxx (default for Windows)
	 * 
	 * Internal code should make it cross-platform.
	 *
	 * @param mixed $device the identifier of the device to be used
	 * @return bool
	 */
	public function set_device($device, $rate=NULL)
	{
		if ($this->_dState !== self::SERIAL_DEVICE_OPENED) 
		{
			$sd = 'S';
			$id = NULL;
			if (is_numeric($device))
			{
				$id = $device;
			}
			else if (preg_match('@^COM(\\d+):?$@i', $device, $matches)) 
			{
				// Format is COMxx
				$id = $matches[1];
			}
			else if (preg_match('@^/dev/tty([A-Z]+)(\\d+)/?$@i', $device, $matches)) 
			{
				// Format is /dev/ttyYYYxx
				// YYY might be S or ACM or similar
				$sd = $matches[1];
				$id = $matches[2];
			}
			
			switch($this->_os)
			{
				case 'osx':
					$device = '/dev/tty.serial';
					break;
				case 'linux':
					$device = '/dev/tty'.$sd.$id;
					break;
				case 'windows':
					$device = 'COM'.$id;
					break;
			}
			switch($this->_os)
			{
				case 'windows':
					if($rate == NULL) $rate=9600;
					if ($this->_exec('mode ' . $device . ' xon=on BAUD='.$rate)
							 === 0 ) 
					{
						$this->_winDevice = $device;
						$this->_device = '\\.com' . $id;
						$this->_dState = self::SERIAL_DEVICE_SET;
					}
					else 
					{
						throw new Kohana_Exception('Specified serial port is not valid.', array());		
					}
				default:
					if ($this->_exec('stty -F ' . $device) === 0) 
					{
						$this->_device = $device;
						$this->_dState = self::SERIAL_DEVICE_SET;

					}
					else 
					{
						throw new Kohana_Exception('Specified serial port is not valid. Check stty is available', array());		
					}
					break;
			}
			if( $rate !== NULL)
			{
				$this->set_baud_rate($rate);
			}
		} 
		else 
		{
			throw new Kohana_Exception('You must close the device or create another one', array());
			return false;
		}
	}

	/**
	 * Opens the device for reading and/or writing.
	 *
	 * @param  string $mode Opening mode : same parameter as fopen()
	 * @return object|bool
	 */
	public function open($mode = 'r+b')
	{
		if ($this->_dState === self::SERIAL_DEVICE_OPENED) 
		{
			// We don't throw an exception here because that would be stupid
			return $this;
		}

		if ($this->_dState === self::SERIAL_DEVICE_NOTSET) 
		{
			throw new Kohana_Exception( 'The device must be set before to be open',	array());
			return false;
		}

		if (!preg_match('@^[raw]\\+?b?$@', $mode)) 
		{
			throw new Kohana_Exception('Invalid opening mode : '.$mode.'. Use fopen() modes.',array()	);
			return false;
		}

		$this->_dHandle = @fopen($this->_device, $mode);

		if ($this->_dHandle !== false) 
		{
			stream_set_blocking($this->_dHandle, 0);
			$this->_dState = self::SERIAL_DEVICE_OPENED;
			return $this;
		}

		$this->_dHandle = null;
		throw new Kohana_Exception('Unable to open the device', array());

		return false;
	}

	/**
	 * Closes the device
	 *
	 * @return object|bool
	 */
	public function close()
	{
		if ($this->_dState !== self::SERIAL_DEVICE_OPENED) 
		{
			return $this;
		}

		if (fclose($this->_dHandle)) 
		{
			$this->_dHandle = null;
			$this->_dState = self::SERIAL_DEVICE_SET;
			return $this;
		}

		throw new Kohana_Exception('Unable to close the device', E_USER_ERROR);

		return false;
	}

	//
	// OPEN/CLOSE DEVICE SECTION -- {STOP}
	//

	//
	// CONFIGURE SECTION -- {START}
	//

	/**
	 * Configure the Baud Rate
	 * Possible rates : 110, 150, 300, 600, 1200, 2400, 4800, 9600, 38400,
	 * 57600 and 115200.
	 *
	 * @param  int  $rate the rate to set the port in
	 * @return bool
	 */
	public function set_baud_rate($rate)
	{
		if ($this->_dState !== self::SERIAL_DEVICE_SET) 
		{
			throw new Kohana_Exception('Unable to set the baud rate : the device is ' .
						  'either not set or opened', array());
			return false;
		}

		$valid_bauds = array (
			110    => 11,
			150    => 15,
			300    => 30,
			600    => 60,
			1200   => 12,
			2400   => 24,
			4800   => 48,
			9600   => 96,
			19200  => 19,
			38400  => 38400,
			57600  => 57600,
			115200 => 115200
		);

		if (isset($valid_bauds[$rate])) 
		{
			switch($this->_os)
			{
				case 'windows':
					$ret = $this->_exec(
						'mode ' . $this->_winDevice . ' BAUD=' . $valid_bauds[$rate],
						$out
					);
					break;
				case 'osx':
				case 'linux':
					$ret = $this->_exec(
						'stty -F ' . $this->_device . ' ' . (int) $rate,
						$out
					);
					break;
			}
			if ($ret !== 0) 
			{
				throw new Kohana_Exception(
					'Unable to set baud rate: ' . $out[1],
					array()
				);
				return false;
			}
			return $this;
		} 
		else 
		{
			throw new Kohana_Exception(
				'Invalid baud rate :rate specified',
				array('rate'=>$rate)
			);
			return false;
		}
	}

	/**
	 * Configure parity.
	 * Modes : odd, even, none
	 *
	 * @param  string $parity one of the modes
	 * @return bool
	 */
	public function set_parity($parity)
	{
		if ($this->_dState !== self::SERIAL_DEVICE_SET) {
			throw new Kohana_Exception(
				'Unable to set parity : the device is either not set or opened',
				array()
			);

			return false;
		}

		$args = array(
			'none' => '-parenb',
			'odd'  => 'parenb parodd',
			'even' => 'parenb -parodd',
		);

		if (!isset($args[$parity])) 
		{
			throw new Kohana_Exception('Parity mode not supported', array());
			return false;
		}

		if ($this->_os === 'linux') 
		{
			$ret = $this->_exec(
				'stty -F ' . $this->_device . ' ' . $args[$parity],
				$out
			);
		} 
		elseif ($this->_os === 'osx') 
		{
			$ret = $this->_exec(
				'stty -f ' . $this->_device . ' ' . $args[$parity],
				$out
			);
		} 
		else 
		{
			$ret = $this->_exec(
				'mode ' . $this->_winDevice . ' PARITY=' . $parity{0},
				$out
			);
		}

		if ($ret === 0) 
		{
			return $this;
		}

		throw new Kohana_Exception('Unable to set parity : ' . $out[1], array());
		return false;
	}

	/**
	 * Sets the length of a character.
	 *
	 * @param  int  $int length of a character (5 <= length <= 8)
	 * @return bool
	 */
	public function set_character_length($int)
	{
		if ($this->_dState !== self::SERIAL_DEVICE_SET) 
		{
			throw new Kohana_Exception('Unable to set length of a character : the device is either not set or opened', array());
			return false;
		}

		$int = (int) $int;
		if ($int < 5) 
		{
			$int = 5;
		} 
		elseif ($int > 8) 
		{
			$int = 8;
		}

		if ($this->_os === 'linux') 
		{
			$ret = $this->_exec(
				'stty -F ' . $this->_device . ' cs' . $int,
				$out
			);
		} 
		elseif ($this->_os === 'osx') 
		{
			$ret = $this->_exec(
				'stty -f ' . $this->_device . ' cs' . $int,
				$out
			);
		} 
		else 
		{
			$ret = $this->_exec(
				'mode ' . $this->_winDevice . ' DATA=' . $int,
				$out
			);
		}

		if ($ret === 0) 
		{
			return $this;
		}

		throw new Kohana_Exception(
			'Unable to set character length : ' .$out[1],
			array()
		);

		return false;
	}

	/**
	 * Sets the length of stop bits.
	 *
	 * @param  float $length the length of a stop bit. It must be either 1,
	 *                       1.5 or 2. 1.5 is not supported under linux and on
	 *                       some computers.
	 * @return bool
	 */
	public function set_stop_bits($length)
	{
		if ($this->_dState !== self::SERIAL_DEVICE_SET) {
			throw new Kohana_Exception('Unable to set the length of a stop bit : the ' .
						  'device is either not set or opened', array());

			return false;
		}

		if ($length != 1
				&& $length != 2
				&& $length != 1.5
				&& !($length == 1.5 and $this->_os === 'linux')
			) 
		{
			throw new Kohana_Exception(
				'Specified stop bit length is invalid',
				array()
			);

			return false;
		}

		if ($this->_os === 'linux') 
		{
			$ret = $this->_exec(
				'stty -F ' . $this->_device . ' ' .
					(($length == 1) ? '-' : '') . 'cstopb',
				$out
			);
		} 
		elseif ($this->_os === 'osx') 
		{
			$ret = $this->_exec(
				'stty -f ' . $this->_device . ' ' .
					(($length == 1) ? '-' : '') . 'cstopb',
				$out
			);
		} 
		else 
		{
			$ret = $this->_exec(
				'mode ' . $this->_winDevice . ' STOP=' . $length,
				$out
			);
		}

		if ($ret === 0) 
		{
			return $this;
		}

		throw new Kohana_Exception(
			'Unable to set stop bit length : ' . $out[1],
			array()
		);

		return false;
	}

	/**
	 * Configures the flow control
	 *
	 * @param  string $mode Set the flow control mode. Availible modes :
	 *                      -> 'none' : no flow control
	 *                      -> 'rts/cts' : use RTS/CTS handshaking
	 *                      -> 'xon/xoff' : use XON/XOFF protocol
	 * @return bool
	 */
	public function set_flow_control($mode)
	{
		if ($this->_dState !== self::SERIAL_DEVICE_SET) {
			throw new Kohana_Exception('Unable to set flow control mode : the device is ' .
						  'either not set or opened', array());

			return false;
		}

		$linuxModes = array(
			'none'     => 'clocal -crtscts -ixon -ixoff',
			'rts/cts'  => '-clocal crtscts -ixon -ixoff',
			'xon/xoff' => '-clocal -crtscts ixon ixoff'
		);
		$windowsModes = array(
			'none'     => 'xon=off octs=off rts=on',
			'rts/cts'  => 'xon=off octs=on rts=hs',
			'xon/xoff' => 'xon=on octs=off rts=on',
		);

		if ($mode !== 'none' and $mode !== 'rts/cts' and $mode !== 'xon/xoff') {
			throw new Kohana_Exception('Invalid flow control mode specified', E_USER_ERROR);

			return false;
		}

		if ($this->_os === 'linux') {
			$ret = $this->_exec(
				'stty -F ' . $this->_device . ' ' . $linuxModes[$mode],
				$out
			);
		} elseif ($this->_os === 'osx') {
			$ret = $this->_exec(
				'stty -f ' . $this->_device . ' ' . $linuxModes[$mode],
				$out
			);
		} else {
			$ret = $this->_exec(
				'mode ' . $this->_winDevice . ' ' . $windowsModes[$mode],
				$out
			);
		}

		if ($ret === 0) 
		{
			return $this;
		} 
		else 
		{
			throw new Kohana_Exception(	'Unable to set flow control : ' . 
				$out[1], array() );

			return false;
		}
	}

	/**
	 * Sets a setserial parameter (cf man setserial)
	 * NO MORE USEFUL !
	 * 	-> No longer supported
	 * 	-> Only use it if you need it
	 *
	 * @param  string $param parameter name
	 * @param  string $arg   parameter value
	 * @return bool
	 */
	public function set_serial_flag($param, $arg = '')
	{
		if (!$this->_ckOpened()) 
		{
			return false;
		}

		$return = $this->_exec(
			'setserial ' . $this->_device . ' ' . $param . ' ' . $arg . ' 2>&1'
		);

		if ($return{0} === 'I') 
		{
			throw new Kohana_Exception('setserial: Invalid flag', array());
			return false;
		} 
		elseif ($return{0} === '/') 
		{
			throw new Kohana_Exception('setserial: Error with device file', array());
			return false;
		} 
		else 
		{
			return $this;
		}
	}

	//
	// CONFIGURE SECTION -- {STOP}
	//

	//
	// I/O SECTION -- {START}
	//

	/**
	 * Sends a string to the device
	 *
	 * @param string $str          string to be sent to the device
	 * @param float  $waitForReply time to wait for the reply (in seconds)
	 */
	public function send($str, $waitForReply = 0.1)
	{
		$this->_buffer .= $str;

		if ($this->_auto_flush === true) 
		{
			$this->flush_serial();
		}
		usleep((int) ($waitForReply * 1000000));
		return $this;
	}

	/**
	 * Reads the port until no new datas are availible, then return the content.
	 *
	 * @param int $count Number of characters to be read (will stop before
	 *                   if less characters are in the buffer)
	 * @return string
	 */
	public function read($count = 0)
	{
		if ($this->_dState !== self::SERIAL_DEVICE_OPENED) 
		{
			throw new Kohana_Exception('Device must be opened to read it', array());
			return false;
		}

		if ($this->_os === 'linux' || $this->_os === 'osx') {
			// Behavior in OSX isn't to wait for new data to recover, but just
			// grabs what's there!
			// Doesn't always work perfectly for me in OSX
			$content = ''; $i = 0;

			if ($count !== 0) 
			{
				while (($i += 128) === strlen($content))
				{
					if ($i > $count) 
					{
						$content .= fread($this->_dHandle, ($count - $i));
					} 
					else 
					{
						$content .= fread($this->_dHandle, 128);
					}
				}
			} 
			else 
			{
				while (($i += 128) === strlen($content))
				{
					$content .= fread($this->_dHandle, 128);
				} 
			}

			return $content;
		} 
		elseif ($this->_os === 'windows') 
		{
			// Windows port reading procedures still buggy
			$content = ''; $i = 0;

			if ($count !== 0) 
			{
				while (($i += 128) === strlen($content))
				{
					if ($i > $count) 
					{
						$content .= fread($this->_dHandle, ($count - $i));
					} 
					else 
					{
						$content .= fread($this->_dHandle, 128);
					}
				}
			} 
			else 
			{
				while (($i += 128) === strlen($content)) 
				{
					$content .= fread($this->_dHandle, 128);
				} 
			}

			return $content;
		}

		return false;
	}

	/**
	 * Flushes the output buffer
	 * Renamed from flush for osx compat. issues
	 *
	 * @return bool
	 */
	public function flush_serial()
	{
		if (!$this->_ckOpened()) 
		{
			return false;
		}

		if (fwrite($this->_dHandle, $this->_buffer) !== false) 
		{
			$this->_buffer = '';
			return $this;
		} 
		else 
		{
			$this->_buffer = '';
			throw new Kohana_Exception('Error while sending message', array());

			return false;
		}
	}

	//
	// I/O SECTION -- {STOP}
	//

	//
	// INTERNAL TOOLKIT -- {START}
	//

	protected function _ckOpened()
	{
		if ($this->_dState !== self::SERIAL_DEVICE_OPENED) 
		{
			throw new Kohana_Exception('Device must be opened', array());

			return false;
		}

		return true;
	}

	protected function _ckClosed()
	{
		if ($this->_dState === self::SERIAL_DEVICE_OPENED) 
		{
			throw new Kohana_Exception('Device must be closed', array());

			return false;
		}

		return true;
	}

	protected function _exec($cmd, &$out = null)
	{
		$desc = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w')
		);

		$proc = proc_open($cmd, $desc, $pipes);

		$ret = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		$retVal = proc_close($proc);

		if (func_num_args() == 2) 
		{
			$out = array($ret, $err);
		}
		return $retVal;
	}

	//
	// INTERNAL TOOLKIT -- {STOP}
	//
}
