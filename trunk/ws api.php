<?php

/*
	PHP WebSockets Server - Currently works with Firefox 6 (24/08/2011)
	
	Script Version 0.1
	http://code.google.com/p/php-websocket-server/
	
	WebSockets Version 07
	http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-07
	
	Please note that the current version of this script is in Beta mode, using this script for a company is not recommended.
	Whilst a big effort is made to follow the protocol documentation, the current script version may unknowingly differ.
	Please report any bugs you may find, all feedback and questions are welcome!
	
	
	[ Functions ]
		wsStartServer(host, port)                     -- returns true on success, or false if the server is already running
		wsStopServer()                                -- returns true on success, or false is the server is not running
		
		wsSend(socket, message, [ binary = false ] )  -- returns true on success, or false on failure
		wsClose(socket)                               -- returns true on success, or false if the socket was not internally stored
	
	[ Callbacks ]
		wsOnOpen(socket)                              -- called after a valid client handshake is completed
		wsOnMessage(socket, message, binary)          -- called when a message is received from the client. binary will be false is the message type is text, otherwise true if binary
		wsOnClose(socket, status)                     -- called when the closing handshake is completed, or the client closes the TCP connection
		                                                 only called for clients where the handshake is completed
		                                                 if the client triggered the close, and didn't specify a reason status code, status will be false
		                                                 if the client timed out (detected by the server), status will be WS_CLOSE_STATUS_TIMEOUT (not defined in WebSockets 7 protocol)
	
	[ Parameter Data Types ]
		resource socket
		string   message
		bool     binary
		int/bool status
		string   host
		int      port
*/

// settings
define('WS_MAX_CLIENTS',     100);
define('WS_IDLE_LIMIT_READ', 10); // seconds a client has to send data to the server, before the server sends it a ping request
define('WS_IDLE_LIMIT_PONG', 5);  // seconds a client has to reply to the ping request, before the connection is closed, and wsOnClose() is called with status WS_CLOSE_STATUS_TIMEOUT

// internal
define('WS_FIN',  128);
define('WS_MASK', 128);

define('WS_OPCODE_CONTINUATION', 0);
define('WS_OPCODE_TEXT',         1);
define('WS_OPCODE_BINARY',       2);
define('WS_OPCODE_CLOSE',        8);
define('WS_OPCODE_PING',         9);
define('WS_OPCODE_PONG',         10);

define('WS_PAYLOAD_LENGTH_16', 126);
define('WS_PAYLOAD_LENGTH_63', 127);

define('WS_READY_STATE_CONNECTING', 0);
define('WS_READY_STATE_OPEN',       1);
define('WS_READY_STATE_CLOSING',    2);
define('WS_READY_STATE_CLOSED',     3);

define('WS_STATUS_NORMAL_CLOSE',             1000);
define('WS_STATUS_GONE_AWAY',                1001);
define('WS_STATUS_PROTOCOL_ERROR',           1002);
define('WS_STATUS_UNSUPPORTED_MESSAGE_TYPE', 1003);
define('WS_STATUS_MESSAGE_TOO_BIG',          1004);

define('WS_CLOSE_STATUS_TIMEOUT', 1);

// global vars
$wsClients = array();
$wsRead    = array();
$wsListen  = false;

/*
	$wsClients[ i ] = array(
		0 => resource socket,               // client socket
		1 => string   readBuffer,           // a blank string when there's no incoming frames
		2 => integer  readyState,           // between 0 and 3
		3 => integer  lastRecvTime,         // initially set to 0
		4 => bool/int pingSentTime,         // false when the server is not waiting for a pong
		5 => bool/int closeStatus           // the close status that wsOnClose() will be called with
	)
	
	$wsRead[ int ] = resource socket        // int (not i), so there can be gaps, eg. 0, 1, 2, 5, 6 (skip 3 and 4)
	
	$wsListen = resource socket             // the socket listening for client connections
*/

// server state functions
function wsStartServer($host, $port) {
	global $wsListen, $wsRead;
	if ($wsListen) return false;
	
	if (!$wsListen = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))  return false;
	if (!socket_set_option($wsListen, SOL_SOCKET, SO_REUSEADDR, 1)) return false;
	if (!socket_bind($wsListen, $host, $port))                      return false;
	if (!socket_listen($wsListen, 10))                              return false;
	
	$wsRead[] = $wsListen;
	$write = array();
	$except = array();
	
	$buffer = '';
	$nextPingCheck = time() + 1;
	while ($wsListen) {
		$changed = $wsRead;
		$result = socket_select($changed, $write, $except, 1);
		
		if ($result === false) {
			socket_close($wsListen);
			return false;
		}
		elseif ($result > 0) {
			$remove = array();
			
			foreach ($changed as $socket) {
				if ($socket != $wsListen) {
					// client socket changed
					$bytes = @socket_recv($socket, $buffer, 4096, 0);
					
					if ($bytes === false) {
						// error on recv, remove client socket (will check to send close frame)
						$remove[] = $socket;
					}
					elseif ($bytes > 0) {
						// process handshake or frame(s)
						if (!wsProcessClient($socket, $buffer)) $remove[] = $socket;
					}
					else {
						// 0 bytes recv'd from socket (client closed TCP connection)
						// no point in sending close frame back to client, coz TCP is closed
						wsRemoveClient($socket);
					}
				}
				else {
					// listen socket changed
					if (sizeof($wsRead)-1 < WS_MAX_CLIENTS) {
						$client = socket_accept($wsListen);
						
						if ($client !== false) {
							wsAddClient($client);
						}
					}
				}
			}
			
			// remove the marked sockets
			foreach ($remove as $socket) {
				wsSendClientClose($socket, WS_STATUS_PROTOCOL_ERROR);
			}
		}
		
		if (time() >= $nextPingCheck) {
			wsCheckIdleClients();
			$nextPingCheck = time() + 1;
		}
	}
	
	return true; // returned when wsStopServer() is called
}
function wsStopServer() {
	global $wsClients, $wsRead, $wsListen;
	if (!$wsListen) return false;
	
	foreach ($wsClients as $client) {
		wsSendClientClose($client[0], WS_STATUS_GONE_AWAY);
	}
	socket_close($wsListen);
	
	$wsRead = array();
	$wsListen = false;
	
	return true;
}

// client timeout functions
function wsCheckIdleClients() {
	global $wsClients;
	
	$time = time();
	foreach ($wsClients as $key => $client) {
		if ($client[2] == WS_READY_STATE_OPEN) { // handshake completed, and ready state not closing or closed
			if ($client[4] !== false) { // ping request has already been sent to client, pending a pong reply
				if ($time >= $client[4] + WS_IDLE_LIMIT_PONG) { // client didn't respond to the server's ping request in WS_IDLE_LIMIT_PONG seconds
					wsSendClientClose($client[0], WS_CLOSE_STATUS_TIMEOUT);
					wsRemoveClient($client[0]);
				}
			}
			elseif ($time >= $client[3] + WS_IDLE_LIMIT_READ) { // last data was received >= WS_IDLE_LIMIT_READ seconds ago
				$wsClients[$key][4] = time();
				wsSendClientMessage($client[0], WS_OPCODE_PING, '');
			}
		}
	}
}

// client state functions
function wsGetClientArrayKey($socket) {
	global $wsClients;
	foreach ($wsClients as $key => $client) {
		if ($client[0] == $socket) return $key;
	}
	return false;
}
function wsAddClient($socket) {
	global $wsClients, $wsRead;
	$wsClients[] = array($socket, '', WS_READY_STATE_CONNECTING, 0, false, 0);
	$wsRead[] = $socket;
}
function wsRemoveClient($socket) {
	global $wsClients, $wsRead;
	
	$key = wsGetClientArrayKey($socket);
	
	$status = $wsClients[$key][5];
	if (function_exists('wsOnClose')) wsOnClose($socket, $status);
	
	socket_close($socket);
	
	array_splice($wsRead, array_search($socket, $wsRead), 1);
	array_splice($wsClients, $key, 1);
	
	return true;
}

// client read functions
function wsProcessClient($socket, &$buffer) {
	global $wsClients;
	
	$key = wsGetClientArrayKey($socket);
	if ($wsClients[$key][2] == WS_READY_STATE_OPEN || $wsClients[$key][2] == WS_READY_STATE_CLOSING) { // handshake completed, and ready state not closed
		$result = wsProcessClientMessage($key, $buffer);
	}
	elseif ($wsClients[$key][2] == WS_READY_STATE_CONNECTING) { // handshake not completed
		$result = wsProcessClientHandshake($socket, $buffer);
		if ($result) {
			$wsClients[$key][2] = WS_READY_STATE_OPEN;
			if (function_exists('wsOnOpen')) wsOnOpen($socket);
		}
	}
	else {
		$result = false; // ready state is set to closed
	}
	
	return $result;
}
function wsProcessClientHandshake($socket, &$buffer) {
	// fetch headers and request line
	$sep = strpos($buffer, "\r\n\r\n");
	if (!$sep) return false;
	
	$headers = explode("\r\n", substr($buffer, 0, $sep));
	$headersCount = sizeof($headers); // includes request line
	if ($headersCount < 1) return false;
	
	// fetch request and check it has at least 3 parts (space tokens)
	$request = &$headers[0];
	$requestParts = explode(' ', $request);
	$requestPartsSize = sizeof($requestParts);
	if ($requestPartsSize < 3) return false;
	
	// check request method is GET
	if (strtoupper($requestParts[0]) != 'GET') return false;
	
	// check request HTTP version is at least 1.1
	$httpPart = &$requestParts[$requestPartsSize - 1];
	$httpParts = explode('/', $httpPart);
	if (!isset($httpParts[1]) || (float) $httpParts[1] < 1.1) return false;
	
	// store headers into a keyed array: array[headerKey] = headerValue
	$headersKeyed = array();
	for ($i=1; $i<$headersCount; $i++) {
		$parts = explode(':', $headers[$i]);
		if (!isset($parts[1])) return false;
		
		$headersKeyed[trim($parts[0])] = trim($parts[1]);
	}
	
	// check Host header was received
	if (!isset($headersKeyed['Host'])) return false;
	
	// check Sec-WebSocket-Key header was received and decoded value length is 16
	if (!isset($headersKeyed['Sec-WebSocket-Key'])) return false;
	$key = $headersKeyed['Sec-WebSocket-Key'];
	if (strlen(base64_decode($key)) != 16) return false;
	
	// check Sec-WebSocket-Version header was received and value is 7
	if (!isset($headersKeyed['Sec-WebSocket-Version']) || (int) $headersKeyed['Sec-WebSocket-Version'] < 7) return false; // should really be != 7, but Firefox 7 beta users have WebSockets 10
	
	// work out hash to use in Sec-WebSocket-Accept reply header
	$hash = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
	
	// build headers
	$headers = array(
		'HTTP/1.1 101 Switching Protocols',
		'Upgrade: websocket',
		'Connection: Upgrade',
		'Sec-WebSocket-Accept: '.$hash
	);
	$headers = implode("\r\n", $headers)."\r\n\r\n";
	
	// send headers back to client
	$left = strlen($headers);
	do {
		$sent = @socket_send($socket, $headers, $left, 0);
		if ($sent === false) return false;
		
		$left -= $sent;
		if ($sent > 0) $headers = substr($headers, $sent);
	}
	while ($left > 0);
	
	return true;
}
function wsProcessClientMessage($clientArrayKey, &$buffer) {
	global $wsClients;
	
	// store the time this socket last received data (not done for sockets that haven't completed the handshake)
	$wsClients[$clientArrayKey][3] = time();
	
	// fetch first 2 bytes of header
	$octet0 = ord(substr($buffer, 0, 1));
	$octet1 = ord(substr($buffer, 1, 1));
	
	$fin = $octet0 & WS_FIN;
	$opcode = $octet0 & 15;
	
	$mask = $octet1 & WS_MASK;
	if (!$mask) return false; // close socket, as no mask bit was sent from the client
	
	// payload length
	$payloadLength = $octet1 & 127;
	if ($payloadLength == WS_PAYLOAD_LENGTH_16) {
		//$array = unpack('na', substr($buffer, 2, 2));
		//$payloadLength = $array['a'];
		$seek = 4;
	}
	elseif ($payloadLength == WS_PAYLOAD_LENGTH_63) {
		if (ord(substr($buffer, 1, 1)) & 128) return false; // most significant bit must be 0
		//$array0 = unpack('Na', substr($buffer, 2, 4));
		//$array1 = unpack('Na', substr($buffer, 6, 4));
		//$payloadLength = ($array0['a'] << 32) | $array1['a'];
		$seek = 10;
	}
	else {
		$seek = 2;
	}
	
	// read mask key
	$array = unpack('Na', substr($buffer, $seek, 4));
	$maskKey = $array['a'];
	$maskKey = array(
		$maskKey >> 24,
		($maskKey >> 16) & 255,
		($maskKey >> 8) & 255,
		$maskKey & 255
	);
	$seek += 4;
	
	// decode data
	$data = str_split(substr($buffer, $seek));
	foreach ($data as $key => $byte) {
		$data[$key] = chr(ord($byte) ^ ($maskKey[$key % 4]));
	}
	$data = implode('', $data);
	
	// check if frame is a control frame and fin is not set, which is invalid, as control frames cannot be fragmented
	if ($opcode & 8 && $fin != WS_FIN) {
		return false;
	}
	
	// check opcodes
	if ($opcode == WS_OPCODE_PING) {
		// received ping request
		return wsSendClientMessage($wsClients[$clientArrayKey][0], WS_OPCODE_PONG, $data);
	}
	elseif ($opcode == WS_OPCODE_PONG) {
		// received pong reply (it's valid that the server did not send a ping request for this pong reply)
		if ($wsClients[$clientArrayKey][4] !== false) {
			$wsClients[$clientArrayKey][4] = false;
		}
		return true;
	}
	elseif ($opcode == WS_OPCODE_CLOSE) {
		// received close request
		if (substr($data, 1, 1) !== false) {
			$array = unpack('na', substr($data, 0, 2));
			$status = $array['a'];
		}
		else {
			$status = false;
		}
		
		if ($wsClients[$clientArrayKey][2] == WS_READY_STATE_CLOSING) {
			// the server already sent a close frame to the client, this is the client's close frame reply
			// (no need to send another close frame to the client)
			$wsClients[$clientArrayKey][2] = WS_READY_STATE_CLOSED;
		}
		else {
			// the server has not already sent a close frame to the client, send one now
			wsSendClientClose($wsClients[$clientArrayKey][0], WS_STATUS_NORMAL_CLOSE);
		}
		
		wsRemoveClient($wsClients[$clientArrayKey][0]);
	}
	elseif ($opcode == WS_OPCODE_CONTINUATION || $opcode == WS_OPCODE_TEXT || $opcode == WS_OPCODE_BINARY) {
		// received continuation, text or binary frame
		if ($fin == WS_FIN) { // final frame of message
			if ($opcode != 0) { // first frame of message (non continuation frame), no need to move data into buffer in $wsClients
				if (function_exists('wsOnMessage')) wsOnMessage($wsClients[$clientArrayKey][0], $data, $opcode == 2);
			}
			else {
				$wsClients[$clientArrayKey][1] .= $data;
				if (function_exists('wsOnMessage')) wsOnMessage($wsClients[$clientArrayKey][0], $wsClients[$clientArrayKey][1], $opcode == 2);
				$wsClients[$clientArrayKey][1] = '';
			}
		}
		else { // more frame(s) of message to come
			$wsClients[$clientArrayKey][1] .= $data;
		}
	}
	
	return true;
}

// client write functions
function wsSendClientMessage($socket, $opcode, $message) {
	$messageLength = strlen($message);
	
	// set max payload length per frame
	$bufferSize = 4096;
	
	// work out amount of frames to send, based on $bufferSize
	$frameCount = ceil($messageLength / $bufferSize);
	if ($frameCount == 0) $frameCount = 1;
	
	// set last frame variables
	$maxFrame = $frameCount - 1;
	$lastFrameBufferLength = ($messageLength % $bufferSize) != 0 ? ($messageLength % $bufferSize) : ($messageLength != 0 ? $bufferSize : 0);
	
	// loop around all frames to send
	for ($i=0; $i<$frameCount; $i++) {
		// fetch fin, opcode and buffer length for frame
		$fin = $i != $maxFrame ? 0 : WS_FIN;
		$opcode = $i != 0 ? WS_OPCODE_CONTINUATION : $opcode;
		
		$bufferLength = $i != $maxFrame ? $bufferSize : $lastFrameBufferLength;
		
		// set payload length variables for frame
		if ($bufferLength <= 125) {
			$payloadLength = $bufferLength;
			$payloadLengthExtended = '';
			$payloadLengthExtendedLength = 0;
		}
		elseif ($bufferLength <= 65535) {
			$payloadLength = WS_PAYLOAD_LENGTH_16;
			$payloadLengthExtended = pack('n', $bufferLength);
			$payloadLengthExtendedLength = 2;
		}
		else {
			$payloadLength = WS_PAYLOAD_LENGTH_63;
			$payloadLengthExtended = pack('xxxxN', $bufferLength); // pack 32 bit int, should really be 64 bit int
			$payloadLengthExtendedLength = 8;
		}
		
		// set frame bytes
		$buffer = pack('n', (($fin | $opcode) << 8) | $payloadLength) . $payloadLengthExtended . substr($message, $i*$bufferSize, $bufferLength);
		
		// send frame
		$left = 2 + $payloadLengthExtendedLength + $bufferLength;
		do {
			$sent = @socket_send($socket, $buffer, $left, 0);
			if ($sent === false) return false;
			
			$left -= $sent;
			if ($sent > 0) $buffer = substr($buffer, $sent);
		}
		while ($left > 0);
	}
	
	return true;
}
function wsSendClientClose($socket, $status=false) {
	global $wsClients;
	
	$key = wsGetClientArrayKey($socket);
	if ($wsClients[$key][2] == WS_READY_STATE_CLOSING) return true;
	$wsClients[$key][2] = WS_READY_STATE_CLOSING;
	$wsClients[$key][5] = $status;
	
	$status = $status !== false ? pack('n', $status) : '';
	wsSendClientMessage($socket, WS_OPCODE_CLOSE, $status);
}

// client non-internal functions
function wsClose($socket) {
	global $wsClients;
	
	$key = wsGetClientArrayKey($socket);
	return wsSendClientClose($socket, WS_STATUS_NORMAL_CLOSE);
}
function wsSend($socket, $message, $binary=false) {
	return wsSendClientMessage($socket, $binary ? WS_OPCODE_BINARY : WS_OPCODE_TEXT, $message);
}

?>