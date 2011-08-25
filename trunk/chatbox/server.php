<?php

/*
	[ Chatbox Getting Started ]
	
	This is an example chat box script, which works with file 'ws api.php'
	
	Make sure you are using an internet browser which works with WebSockets version 07.
	As of writing this, 24/08/2011, the only browser supporting WebSockets 07 is Firefox 6.
	
	Stage 1 - Start the server
		Open your browser, and go to server.php (this file). For example: http://127.0.0.1/chatbox/server.php
		The page will appear to never load, this is supposed to happen and means the server is running.
	
	Stage 2 - Using the chatbox
		Open a new tab in your browser, and go to index.html. For example: http://127.0.0.1/chatbox/index.html
		Type a username into the top right box, press the connect button.
		You should now be able to send messages, receive messages, and see a list of users connected.
	
	
	[ Chatbox Protocol ]
	
	This chatbox script uses a very basic text protocol, described here.
	This protocol has nothing to do with the WebSockets extensions stuff.
	
	Client -> Server:
		JOIN username
		QUIT
		TEXT text here
	
	Server -> Client:
		ONJOIN username
		ONQUIT username
		ONTEXT username text here
		USERS username1 username2
		SERVER text here
	
	USERS sends a list of space-separated usernames to a client when that client has joined.
	SERVER sends text to a client that is not chat text, for example: Username already taken
	QUIT does not take a username because the server stores all usernames.
	The other ones should be pretty obvious really.
*/

// settings
define('CB_SERVER_BIND_HOST',    gethostbyaddr(gethostbyname($_SERVER['SERVER_NAME'])) ); // if this fails, try LAN IP, 127.0.0.1, or external IP
define('CB_SERVER_BIND_PORT',    9432); // also change at top of main.js
define('CB_MAX_USERNAME_LENGTH', 18);   // also change at top of main.js




// prevent the server from timing out
set_time_limit(0);

// include the web sockets server script
require '../ws api.php';




// users are stored in a 2 dimensional array
$users = array();
/*
	Syntax:
	
	$users[ i ] = array(
		0 => resource Socket,
		1 => string   Username
	)
*/

// when a client sends data to the server
function wsOnMessage($socket, $message, $binary) {
	// split the message by spaces into an array, and fetch the command
	$message = explode(' ', $message);
	$command = array_shift($message);
	
	// check which command was received
	if ($command == 'TEXT') {
		// a client has sent chat text to the server
		
		if (!isUser($socket)) {
			// the client has not yet sent a JOIN, and is trying to send a TEXT
			wsClose($socket);
			return;
		}
		
		// put the message back into a string
		$text = implode(' ', $message);
		
		if ($text == '') {
			// the text is blank
			wsSend($socket, 'SERVER Message was blank.');
			return;
		}
		
		// fetch the client's username, and send the chat text to all clients
		// the text is actually also sent back to the client which sent the text, which sort of acts as a confirmation that the text worked
		$username = getUsername($socket);
		sendChat($username, $text);
	}
	elseif ($command == 'JOIN') {
		// a client is joining the chat
		
		if (isUser($socket)) {
			// the client has already sent a JOIN
			wsClose($socket);
			return;
		}
		
		// fetch username, and trim any whitespace before and after the username
		$username = trim($message[0]);
		
		if ($username == '') {
			// the username is blank
			wsClose($socket);
			return;
		}
		if (strlen($username) > CB_MAX_USERNAME_LENGTH) {
			// username length is more than CB_MAX_USERNAME_LENGTH
			wsSend($socket, 'SERVER Username length cannot be more than '.CB_MAX_USERNAME_LENGTH.'.');
			wsClose($socket);
			return;
		}
		if (isUsername($username)) {
			// username is already being used by another client
			wsSend($socket, 'SERVER Username already taken.');
			wsClose($socket);
			return;
		}
		
		// store the client's socket variable and username into an array,
		// let all clients know about this client joining (not including the client joining),
		// and send a list of usernames to the client which is joining
		addUser($socket, $username);
	}
	elseif ($command == 'QUIT') {
		// a client is leaving the chat
		
		if (!isUser($socket)) {
			// the client has not yet sent a JOIN, and is trying to send a QUIT
			wsClose($socket);
			return;
		}
		
		// let all clients know about this client quitting, (not including the client quitting)
		// and remove the client's socket variable and username from the array
		removeUser($socket);
	}
	else {
		// unknown command received, close connection
		wsClose($socket);
	}
}

// when a client closes or lost connection
function wsOnClose($socket, $status) {
	// check if the client has successfully sent a JOIN
	if (isUser($socket)) {
		removeUser($socket);
	}
}

// user functions
function isUser($socket) {
	// checks if a user exists (if JOIN has been received from the client)
	global $users;
	foreach ($users as $user) {
		if ($user[0] == $socket) return true;
	}
	return false;
}
function addUser($socket, $username) {
	// adds a user
	global $users;
	$users[] = array($socket, $username);
	
	foreach ($users as $user) {
		if ($user[0] != $socket) {
			wsSend($user[0], 'ONJOIN '.$username);
		}
	}
	
	$usernames = getUsernames($socket);
	wsSend($socket, 'USERS '.implode(' ', $usernames));
}
function removeUser($socket) {
	// removes a user
	global $users;
	
	$username = getUsername($socket);
	
	foreach ($users as $user) {
		if ($user[0] != $socket) {
			wsSend($user[0], 'ONQUIT '.$username);
		}
	}
	
	array_splice($users, getUserArrayKey($socket), 1);
}
function getUserArrayKey($socket) {
	// gets the array key in $users for the client
	global $users;
	foreach ($users as $key => $user) {
		if ($user[0] == $socket) return $key;
	}
	return false;
}

// username functions
function isUsername($username) {
	// checks if a username is being used by any client
	global $users;
	foreach ($users as $user) {
		if ($user[1] == $username) return true;
	}
	return false;
}
function getUsername($socket) {
	// fetches the username from a client's socket variable
	global $users;
	foreach ($users as $user) {
		if ($user[0] == $socket) return $user[1];
	}
	return false;
}
function getUsernames($socket=false) {
	// fetches a list of usernames as an array,
	// optionally, not including the username for the client's socket variable sent to the function
	global $users;
	$usernames = array();
	foreach ($users as $user) {
		if ($socket == false || $user[0] != $socket) {
			$usernames[] = $user[1];
		}
	}
	return $usernames;
}

// chat functions
function sendChat($username, $text) {
	// sends chat text to all clients
	global $users;
	foreach ($users as $user) {
		wsSend($user[0], 'ONTEXT '.$username.' '.$text);
	}
}




// start the server
wsStartServer(CB_SERVER_BIND_HOST, CB_SERVER_BIND_PORT);

?>