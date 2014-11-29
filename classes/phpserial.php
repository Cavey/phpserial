<?php defined('SYSPATH') OR die('No direct script access.');

class PHPSerial 
{
	const SERIAL_DEVICE_NOTSET = 0;
	const SERIAL_DEVICE_SET = 1;
	const SERIAL_DEVICE_OPENED = 2;
	
	protected $__device = null;
    protected $__winDevice = null;
	protected $__dHandle = null;
	protected $__dState = self::SERIAL_DEVICE_NOTSET;
	protected $__buffer = '';
	/**
	 * This var says if buffer should be flushed by sendMessage (true) or
	 * manually (false)
	 *
	 * @var bool
	 */
	protected $__autoFlush = true;
	
	protected $__os = '';

	/**
	* This var says if buffer should be flushed by sendMessage (true) or
	* manually (false)
	*
	* @var bool
	*/
	public $autoFlush = true;
	
	public function __construct($device = NULL)
	{
		$sysName = php_uname();
		if (substr($sysName, 0, 5) === 'Linux') 
		{
			$this->__os = 'linux';
			if ($this->__exec('stty') === 0) 
			{
				register_shutdown_function(array($this, 'close'));
			} 
			else 
			{
				throw new Kohana_Exception('No stty availible, unable to run.',E_USER_ERROR);
			}
		} 
		elseif (substr($sysName, 0, 6) === 'Darwin') 
		{
			$this->__os = 'osx';
			register_shutdown_function(array($this, 'close'));
		} 
		elseif (substr($sysName, 0, 7) === 'Windows') 
		{
			$this->__os = 'windows';
			register_shutdown_function(array($this, 'close'));
		} 
		else 
		{
			throw new Kohana_Exception('Host OS is neither osx, linux nor windows, unable to run.', E_USER_ERROR);
			exit();
		}
		if($device != NULL)
		{
			$this->set_device($device);
		}
	}
	//
	// OPEN/CLOSE DEVICE SECTION -- {START}
	//

	/**
	 * Device set function : used to set the device name/address.
	 * -> linux : use the device address, like /dev/ttyS0
	 * -> osx : use the device address, like /dev/tty.serial
	 * -> windows : use the COMxx device name, like COM1 (can also be used
	 *     with linux)
	 *
	 * @param  string $device the name of the device to be used
	 * @return bool
	 */
	public function set_device($device)
	{
		if ($this->__dState !== self::SERIAL_DEVICE_OPENED) 
		{
			if ($this->__os === 'linux') 
			{
				if (preg_match('@^COM(\\d+):?$@i', $device, $matches)) 
				{
					$device = '/dev/ttyS' . ($matches[1] - 1);
				}

				if ($this->__exec('stty -F ' . $device) === 0) 
				{
					$this->__device = $device;
					$this->__dState = self::SERIAL_DEVICE_SET;

					return true;
				}
			} 
			elseif ($this->__os === 'osx') 
			{
				if ($this->__exec('stty -f ' . $device) === 0) 
				{
					$this->__device = $device;
					$this->__dState = self::SERIAL_DEVICE_SET;

					return true;
				}
			} 
			elseif ($this->__os === 'windows') 
			{
				if (preg_match('@^COM(\\d+):?$@i', $device, $matches)
						and $this->__exec(
							exec('mode ' . $device . ' xon=on BAUD=9600')
						) === 0 ) 
				{
					$this->_winDevice = 'COM' . $matches[1];
					$this->__device = '\\.com' . $matches[1];
					$this->__dState = self::SERIAL_DEVICE_SET;

					return true;
				}
			}

			throw new Kohana_Exception('Specified serial port is not valid', array());

			return false;
		} 
		else 
		{
			throw new Kohana_Exception('You must close your device before to set an other ' .
						  'one', array());
			return false;
		}
	}

	/**
	 * Opens the device for reading and/or writing.
	 *
	 * @param  string $mode Opening mode : same parameter as fopen()
	 * @return bool
	 */
	public function open($mode = 'r+b')
	{
		if ($this->__dState === self::SERIAL_DEVICE_OPENED) 
		{
			// We don't throw an exception here because that would be stupid
			return true;
		}

		if ($this->__dState === self::SERIAL_DEVICE_NOTSET) 
		{
			throw new Kohana_Exception( 'The device must be set before to be open',	array());
			return false;
		}

		if (!preg_match('@^[raw]\\+?b?$@', $mode)) 
		{
			throw new Kohana_Exception('Invalid opening mode : '.$mode.'. Use fopen() modes.',array()	);
			return false;
		}

		$this->__dHandle = @fopen($this->__device, $mode);

		if ($this->__dHandle !== false) 
		{
			stream_set_blocking($this->__dHandle, 0);
			$this->__dState = self::SERIAL_DEVICE_OPENED;
			return true;
		}

		$this->__dHandle = null;
		throw new Kohana_Exception('Unable to open the device', array());

		return false;
	}

	/**
	 * Closes the device
	 *
	 * @return bool
	 */
	public function close()
	{
		if ($this->__dState !== self::SERIAL_DEVICE_OPENED) 
		{
			return true;
		}

		if (fclose($this->__dHandle)) 
		{
			$this->__dHandle = null;
			$this->__dState = self::SERIAL_DEVICE_SET;
			return true;
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
		if ($this->__dState !== self::SERIAL_DEVICE_SET) 
		{
			throw new Kohana_Exception('Unable to set the baud rate : the device is ' .
						  'either not set or opened', array());
			return false;
		}

		$validBauds = array (
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

		if (isset($validBauds[$rate])) 
		{
			if ($this->__os === 'linux') 
			{
				$ret = $this->__exec(
					'stty -F ' . $this->__device . ' ' . (int) $rate,
					$out
				);
			} 
			elseif ($this->__os === 'osx') 
			{
				$ret = $this->__exec(
					'stty -f ' . $this->__device . ' ' . (int) $rate,
					$out
				);
			} 
			elseif ($this->__os === 'windows') 
			{
				$ret = $this->__exec(
					'mode ' . $this->_winDevice . ' BAUD=' . $validBauds[$rate],
					$out
				);
			} 
			else 
			{
				return false;
			}

			if ($ret !== 0) 
			{
				throw new Kohana_Exception(
					'Unable to set baud rate: ' . $out[1],
					array()
				);
				return false;
			}

			return true;
		} 
		else 
		{
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
		if ($this->__dState !== self::SERIAL_DEVICE_SET) {
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

		if ($this->__os === 'linux') 
		{
			$ret = $this->__exec(
				'stty -F ' . $this->__device . ' ' . $args[$parity],
				$out
			);
		} 
		elseif ($this->__os === 'osx') 
		{
			$ret = $this->__exec(
				'stty -f ' . $this->__device . ' ' . $args[$parity],
				$out
			);
		} 
		else 
		{
			$ret = $this->__exec(
				'mode ' . $this->_winDevice . ' PARITY=' . $parity{0},
				$out
			);
		}

		if ($ret === 0) 
		{
			return true;
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
		if ($this->__dState !== self::SERIAL_DEVICE_SET) 
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

		if ($this->__os === 'linux') 
		{
			$ret = $this->__exec(
				'stty -F ' . $this->__device . ' cs' . $int,
				$out
			);
		} 
		elseif ($this->__os === 'osx') 
		{
			$ret = $this->__exec(
				'stty -f ' . $this->__device . ' cs' . $int,
				$out
			);
		} 
		else 
		{
			$ret = $this->__exec(
				'mode ' . $this->_winDevice . ' DATA=' . $int,
				$out
			);
		}

		if ($ret === 0) 
		{
			return true;
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
		if ($this->__dState !== self::SERIAL_DEVICE_SET) {
			throw new Kohana_Exception('Unable to set the length of a stop bit : the ' .
						  'device is either not set or opened', array());

			return false;
		}

		if ($length != 1
				&& $length != 2
				&& $length != 1.5
				&& !($length == 1.5 and $this->__os === 'linux')
			) 
		{
			throw new Kohana_Exception(
				'Specified stop bit length is invalid',
				array()
			);

			return false;
		}

		if ($this->__os === 'linux') 
		{
			$ret = $this->__exec(
				'stty -F ' . $this->__device . ' ' .
					(($length == 1) ? '-' : '') . 'cstopb',
				$out
			);
		} 
		elseif ($this->__os === 'osx') 
		{
			$ret = $this->__exec(
				'stty -f ' . $this->__device . ' ' .
					(($length == 1) ? '-' : '') . 'cstopb',
				$out
			);
		} 
		else 
		{
			$ret = $this->__exec(
				'mode ' . $this->_winDevice . ' STOP=' . $length,
				$out
			);
		}

		if ($ret === 0) 
		{
			return true;
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
		if ($this->__dState !== self::SERIAL_DEVICE_SET) {
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

		if ($this->__os === 'linux') {
			$ret = $this->__exec(
				'stty -F ' . $this->__device . ' ' . $linuxModes[$mode],
				$out
			);
		} elseif ($this->__os === 'osx') {
			$ret = $this->__exec(
				'stty -f ' . $this->__device . ' ' . $linuxModes[$mode],
				$out
			);
		} else {
			$ret = $this->__exec(
				'mode ' . $this->_winDevice . ' ' . $windowsModes[$mode],
				$out
			);
		}

		if ($ret === 0) {
			return true;
		} else {
			throw new Kohana_Exception(
				'Unable to set flow control : ' . $out[1],
				E_USER_ERROR
			);

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
		if (!$this->__ckOpened()) 
		{
			return false;
		}

		$return = exec(
			'setserial ' . $this->__device . ' ' . $param . ' ' . $arg . ' 2>&1'
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
			return true;
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
	public function send_message($str, $waitForReply = 0.1)
	{
		$this->__buffer .= $str;

		if ($this->__autoFlush === true) 
		{
			$this->flush_serial();
		}

		usleep((int) ($waitForReply * 1000000));
	}

	/**
	 * Reads the port until no new datas are availible, then return the content.
	 *
	 * @param int $count Number of characters to be read (will stop before
	 *                   if less characters are in the buffer)
	 * @return string
	 */
	public function readPort($count = 0)
	{
		if ($this->__dState !== self::SERIAL_DEVICE_OPENED) {
			throw new Kohana_Exception('Device must be opened to read it', array());

			return false;
		}

		if ($this->__os === 'linux' || $this->__os === 'osx') {
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
						$content .= fread($this->__dHandle, ($count - $i));
					} 
					else 
					{
						$content .= fread($this->__dHandle, 128);
					}
				}
			} 
			else 
			{
				while (($i += 128) === strlen($content))
				{
					$content .= fread($this->__dHandle, 128);
				} 
			}

			return $content;
		} 
		elseif ($this->__os === 'windows') 
		{
			// Windows port reading procedures still buggy
			$content = ''; $i = 0;

			if ($count !== 0) 
			{
				while (($i += 128) === strlen($content))
				{
					if ($i > $count) 
					{
						$content .= fread($this->__dHandle, ($count - $i));
					} 
					else 
					{
						$content .= fread($this->__dHandle, 128);
					}
				}
			} 
			else 
			{
				while (($i += 128) === strlen($content)) 
				{
					$content .= fread($this->__dHandle, 128);
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
		if (!$this->__ckOpened()) 
		{
			return false;
		}

		if (fwrite($this->__dHandle, $this->__buffer) !== false) 
		{
			$this->__buffer = '';

			return true;
		} 
		else 
		{
			$this->__buffer = '';
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

	protected function __ckOpened()
	{
		if ($this->__dState !== self::SERIAL_DEVICE_OPENED) {
			throw new Kohana_Exception('Device must be opened', array());

			return false;
		}

		return true;
	}

	protected function __ckClosed()
	{
		if ($this->__dState === self::SERIAL_DEVICE_OPENED) 
		{
			throw new Kohana_Exception('Device must be closed', array());

			return false;
		}

		return true;
	}

	protected function __exec($cmd, &$out = null)
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
