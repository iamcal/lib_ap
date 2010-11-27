<?
	#
	# lib_ap - Admin Protocol Library
	#

	# number of bytes to read from the network buffer at a time
	$GLOBALS[ap_max_read_block_size] = 4 * 1024;

	# timeouts (in seconds)
	$GLOBALS[ap_conn_timeout] = 5;
	$GLOBALS[ap_io_timeout] = 5;


	#############################################################################
	#
	# the writing functions
	#

	function ap_prepare_number($value){

		if ($value <= 250){
			return chr($value);
		}

		if ($value <= 0xFFFF){

			return chr(252).chr($value & 0xFF).chr(($value >> 8) & 0xFF);
		}

		if ($value <= 0xFFFFFF){

			return chr(253).chr($value & 0xFF).chr(($value >> 8) & 0xFF).chr(($value >> 16) & 0xFF);
		}

		return chr(254).chr($value & 0xFF).chr(($value >> 8) & 0xFF).chr(($value >> 16) & 0xFF).chr(($value >> 24) & 0xFF);
	}

	###

	function ap_prepare_string($string){

		return ap_prepare_number(strlen($string)).$string;
	}


	#############################################################################
	#
	# the reading functions
	#

	function ap_read_number($sock){

		$first = ap_read($sock, 1);

		if (!$first['ok']) return $first;

		$first = ord($first['data']);

		if ($first <= 250){
			return array(
				'ok' => 1,
				'value' => $first,
			);
		}

		if ($first == 251){
			return array(
				'ok' => 1,
				'value' => null,
			);
		}

		if ($first == 252){
			$b1 = ap_read($sock, 1);
			$b2 = ap_read($sock, 1);
			return ap_build_number($b1, $b2, null, null);
		}

		if ($first == 253){
			$b1 = ap_read($sock, 1);
			$b2 = ap_read($sock, 1);
			$b3 = ap_read($sock, 1);
			return ap_build_number($b1, $b2, $b3, null);
		}

		if ($first == 254){
			$b1 = ap_read($sock, 1);
			$b2 = ap_read($sock, 1);
			$b3 = ap_read($sock, 1);
			$b4 = ap_read($sock, 1);
			return ap_build_number($b1, $b2, $b3, $b4);
		}

		if ($first == 255){
			return array(
				'ok' => 0,
				'error' => 'number_no_64b',
			);
		}

		return array(
			'ok' => 0,
			'error' => 'number_bad_first_byte',
		);
	}

	function ap_build_number($b1, $b2, $b3, $b4){

		if (!is_null($b1) && !$b1['ok']) return $b1;
		if (!is_null($b2) && !$b2['ok']) return $b2;
		if (!is_null($b3) && !$b3['ok']) return $b3;
		if (!is_null($b4) && !$b4['ok']) return $b4;

		$v1 = is_null($b1) ? 0 : ord($b1['data']);
		$v2 = is_null($b2) ? 0 : ord($b2['data']);
		$v3 = is_null($b3) ? 0 : ord($b3['data']);
		$v4 = is_null($b4) ? 0 : ord($b4['data']);

		return array(
			'ok' => 1,
			'value' => ($v4 << 24) | ($v3 << 16) | ($v2 << 8) | $v1,
		);
	}

	function ap_read_string($sock){

		$len = ap_read_number($sock);

		if (!$len['ok']) return $len;

		return ap_read($sock, $len['value']);
	}


	#############################################################################
	#
	# network IO functions
	#

	function ap_read($sock, $len){

		#
		# end of file?
		#

		if (@feof($sock)){
			ap_disconnect($sock);
			return array(
				'ok' => 0,
				'error' => 'read_eof',
			);
		}

		#
		# try and read some data
		#

		$data = '';
		$meta = @stream_get_meta_data($sock);

		while ((strlen($data) < $len) && !$meta[timed_out] && !@feof($sock) && @is_resource($sock)){

			$diff = $len - strlen($data);
			$rlen = min($diff, $GLOBALS[ap_max_read_block_size]);

			$data .= @fread($sock, $rlen);

			$meta = @stream_get_meta_data($sock);
		}


		#
		# invalid resource?
		#

		if (!@is_resource($sock)){
			ap_disconnect($sock);
			return array(
				'ok' => 0,
				'error' => 'read_invalid_socket',
			);
		}


		#
		# check we didn't time out
		#

		if ($meta[timed_out]){
			ap_disconnect($sock);
			return array(
				'ok' => 0,
				'error' => 'read_timeout',
			);
		}


		#
		# check the socket didn't close
		#

		if (@feof($sock)){
			ap_disconnect($sock);
			return array(
				'ok' => 0,
				'error' => 'read_closed',
			);
		}


		#
		# check we filled our buffer
		#

		if (!strlen($data) == $len){
			ap_disconnect($sock);
			return array(
				'ok' => 0,
				'error' => 'read_short',
			);
		}

		return array(
			'ok' => 1,
			'data' => $data,
		);
	}

	###

	function ap_write($sock, $data){

		#
		# try and write the data
		#

		$written = 0;
		$len = strlen($data);

		while ($written < $len){

			if ($written){

				$ret = @fwrite($sock, substr($data, $written), $len - $written);
			}else{
				$ret = @fwrite($sock, $data, $len);
			}

			if ($ret === FALSE){
				ap_disconnect($sock);
				return array(
					'ok' => 0,
					'error' => 'write_failed',
				);
			}

			$written += $ret;
		}
		fflush($sock);


		#
		# check we didn't time out
		#

		$meta = @stream_get_meta_data($sock);

		if ($meta[timed_out]){
			ap_disconnect($sock);
			return array(
				'ok' => 0,
				'error' => 'write_timeout',
			);
		}

		return array(
			'ok' => 1,
		);
	}

	#############################################################################
	#
	# network connection functions
	#

	function ap_connect($host, $port){

		$errno = '';
		$errstr = '';

		$sock = @fsockopen($host, $port, $errno, $errstr, $GLOBALS[ap_conn_timeout]);

		if (!$sock){
			return 0;
		}


		#
		# set io timeout on the socket
		#

		@stream_set_timeout($sock, $GLOBALS[ap_io_timeout]);

		return $sock;
	}

	function ap_disconnect($sock){

		@fclose($sock);
	}

	#############################################################################
?>