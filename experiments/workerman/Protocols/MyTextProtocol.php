<?php
namespace Protocols;
/**
 * User defined protocol
 * Format Text+"\n"
 */
class MyTextProtocol
{
	/**
	 * @param $recv_buffer
	 * @return bool|int
	 */
	public static function input($recv_buffer)
    {
        // Find the position of the first occurrence of "\n"
        $pos = strpos($recv_buffer, "\n");
        // Not a complete package. Return 0 because the length of package can not be calculated
        if($pos === false)
        {
            return 0;
        }
        // Return length of the package
        return $pos+1;
    }

	/**
	 * @param $recv_buffer
	 * @return string
	 */
	public static function decode($recv_buffer)
    {
        return trim($recv_buffer);
    }

	/**
	 * @param $data
	 * @return string
	 */
	public static function encode($data)
    {
        return $data.PHP_EOL;
    }
}
