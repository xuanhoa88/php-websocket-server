# PHP WebSocket Server 0.2 - Scripting & Settings #

## Functions ##

wsStartServer(string Host, integer Port)<br>
wsStopServer()<br>
wsSend(integer ClientID, string Message, <code>[</code> boolean Binary = false <code>]</code>)<br>
wsClose(integer ClientID)<br>
wsGetClientSocket(integer ClientID)<br>
<br>
<h2>Callbacks</h2>

wsOnOpen(integer ClientID)<br>
wsOnMessage(integer ClientID, string Message, integer MessageLength, boolean Binary)<br>
wsOnClose(integer ClientID, integer/false Status)<br>
<br>
<h2>Function Details</h2>

<b>wsStartServer(string Host, integer Port)</b>

This starts the server, which waits for clients to connect.<br>
This is the core function that runs the server, this function does not return until the server stops.<br>
<br>
<b>wsStopServer()</b>

This stops the server, and informs all clients that the server is shutting down.<br>
This function will cause <b>wsStartServer</b> to return true.<br>
<br>
<b>wsSend(integer ClientID, string Message, <code>[</code> boolean Binary = false <code>]</code>)</b>

This sends a message to a client.<br>
If the optional <i>Binary</i> parameter is true, the message type is set to binary.<br>
<br>
<b>wsClose(integer ClientID)</b>

This starts the closing handshake with a client, the connection will be closed when either:<br>
<ul><li>the client responds with a close frame<br>
</li><li>the client closes the TCP connection<br>
</li><li>the connection times out on the server (see setting WS_TIMEOUT_RECV)</li></ul>

<b>wsGetClientSocket(integer ClientID)</b>

This returns the socket resource for a client.<br>
<br>
<h2>Callback Details</h2>

<b>wsOnOpen(integer ClientID)</b>

This is called when the opening handshake for a client is completed.<br>
<br>
<b>wsOnMessage(integer ClientID, string Message, integer MessageLength, boolean Binary)</b>

This is called when the server receives a message from a client.<br>
The <i>Binary</i> parameter indicates whether the message type is binary or text.<br>
<br>
<b>wsOnClose(integer ClientID, integer/false Status)</b>

This is called when the server closes a client connection.<br>
The <i>Status</i> parameter will be false if the client triggered the close and did not specify a status code.<br>
<br>
<h2>Settings</h2>

At the top of <b>ws api.php</b>, 6 settings can optionally be changed.<br>
<br>
<b>WS_MAX_CLIENTS</b>

This is the maximum amount of clients that can be connected to the server at one time.<br>
<br>
<b>WS_MAX_CLIENTS_PER_IP</b>

This is the maximum amount of clients that can be connected to the server at one time on the same IP v4 address.<br>
<br>
<b>WS_TIMEOUT_RECV</b>

This is the amount of seconds a client has to send any data to the server, before the server sends the client a ping request.<br>
If the client has not completed the opening handshake, the ping request is skipped and the client connection is closed.<br>
<br>
<b>WS_TIMEOUT_PONG</b>

This works with <i>WS_TIMEOUT_RECV</i>, and is the amount of seconds a client has to respond to the ping request before the server closes the client connection.<br>
<br>
<b>WS_MAX_FRAME_PAYLOAD_RECV</b>

This is the maximum length, in bytes, of a frame's payload data. (a <i>message</i> consists of 1 or more frames)<br>
This is also internally limited to 2,147,479,538.<br>
<br>
<b>WS_MAX_MESSAGE_PAYLOAD_RECV</b>

This is the maximum length, in bytes, of a message's payload data.<br>
This is also internally limited to 2,147,483,647.