<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of hexdec
 *
 * @author Caveman
 */
class bytearray 
{
	/**
	 * Where the data is stored internally
	 * @var array 
	 */
	protected $__data;
	
	//put your code here
	public function __construct($v=NULL)
	{
		if($v == null)
		{
			return $this;
		}
		else if(is_string($v))
		{
			$this->from_hex($v);
		}
		else if(is_array($v))
		{
			$this->from_dec($v);
		}
	}
	/**
	 * Factory for generating a bytearray
	 * @param mixed $v
	 * @return \bytearray
	 */
	public static function factory($v = NULL)
	{
		$bytearray = new bytearray($v);
		return $bytearray;
	}
	/**
	 * 
	 * @param string $hex Hexadecimal string, like ffe4f111
	 * @return \bytearray
	 */
	public function from_hex($hex)
	{
		$this->__data  = unpack("C*", pack("H*", $hex));
		return $this;
	}
	/**
	 * 
	 * @param array $hex Array of decimal numbers
	 * @return \bytearray
	 */
	public function from_dec($arr)
	{
		$this->__data  = $arr;
		return $this;
	}
	/**
	 * 
	 * @param string $bin Binary string
	 * @return \bytearray
	 */
	public function from_bin($bin)
	{
		$this->__data  = unpack("C*", $bin);
		return $this;
	}
	/**
	 * Returns as a binary string
	 * @return string Description
	 */
	public function __tostring()
	{
		return $this->as_bin();
	}
	/**
	 * Returns as a hexdecimal string
	 * @return string Returns a hex string.
	 */
	public function as_hex()
	{
		$str;
		foreach($this->data as $d)
		{
			$str .= dechex($d);
		}
		return $str;
	}
	public function as_bin()
	{
		return pack("H*", $this->as_hex());
	}
	/**
	 * Returns the data as an array of decimal values
	 * @return array
	 */
	public function as_array()
	{
		return $this->__data;
	}
	/**
	 * Use to add another bytearray, either as a hex string, array of decimals
	 * or bytearray object, to the current bytearray
	 * 
	 * @param mixed $add
	 * @return \bytearray
	 */
	public function add($add)
	{
		if(is_string($add))
		{
			$add = new bytearray($add);
		}
		if($add instanceof bytearray)
		{
			$add = $add->as_array();
		}
		foreach($this->__data as $key => $val)
		{
			if(isset($add[$key]))
			{
				$this->__data[$key] += $add[$key];
			}
		}
		return $this;
	}
	/**
	 * Replaces the parity byte having updated info in the bytearray
	 * @return \bytearray
	 */
	public function update_parity()
	{
		return $this->remove_parity()->append_parity();
	}
	/**
	 * Causes the parity bit to be appended to the end of the byte array
	 * @return \bytearray
	 */
	public function append_parity()
	{
		$parity = 0;
		foreach($this->__data as $d)
		{
			$parity ^= $d;
		}
		$this->__data[] = $parity;
		return $this;
	}
	/**
	 * Removes the last byte from the byte array
	 * @return \bytearray
	 */
	public function remove_parity()
	{
		unset($this->__data[count($this->data)]);
		return $this;
	}
}

