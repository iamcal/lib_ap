lib_ap - Admin Protocol Library
===============================

<b>This code has not been tested - it is a port of production code, but varies in many places. Use for illustration only.</b>

The admin protocol is a simple schema-less binary streaming protolcol used at Tiny Speck, based on Flickr's FSFS protocol which was in turn lossle based on Pascal strings. It allows to exchange of large data blobs (up to several gigabytes) while leaving the payload opaque - it is fast to read and write and no data needs escaping.

It has two primatives - a number and a string/blob. A number can have any value between 0 and 2^31 (the largest number PHP natively supports) and NULL. Strings can be up to this length in bytes. Accomodation could easily be made for larger values.

Numbers are encoded as length coded binary in the same style as the <a href="http://forge.mysql.com/wiki/MySQL_Internals_ClientServer_Protocol#Elements">MySQL Binary Protocol</a>. Strings are encoded as the length in bytes (as a length coded binary number) and then the contents. The string "hello world" would be sent as "<code><span style="color: red">[\x0B]</span>hello world</code>". A 1MB file would be sent as "<code><span style="color: red">[\xFD][\x10][\x00][\x00]</span></code>" followed by the contents of the file.

This library contains the primatives to read and write numbers and strings and connect to a server. For example:

	include('lib_ap.php');

	$sock = ap_connect($host, $port);

	$ret = read_obj($sock, '15a69ce29be8ce86f4ef3e3227fc42e7');

	function read_obj($sock, $key){

		#
		# write a 'request' to the server
		#

		$msg = ap_prepare_string("READ");
		$msg .= ap_prepare_string($key);

		$ret = ap_write($sock, $msg);
		if (!$ret['ok']) return $ret;


		#
		# check for success?
		#

		$ret = ap_read_number($sock);
		if (!$ret['ok']) return $ret;

		if ($ret['value'] != SUCCESS_CODE){
			return array(
				'ok' => 0,
				'error' => 'no_success_code',
			);
		}


		#
		# read the object
		#

		$ret = ap_read_string($sock);

		return $ret;
	}

The library uses the <i>cerberus return style</i>, where functions always return an array with a member called <code>ok</code> which contains zero or one. Unhandled failures are passed back up the chain by just returning the failure response from child calls.