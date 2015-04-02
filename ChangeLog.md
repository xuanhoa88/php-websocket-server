# Change Log #

## 0.2 ##

### Performance Updates ###

Changed all "resource Socket" parameters to "integer ClientID".<br>
This improves performance and makes it much easier to code with, eg. storing clients in arrays.<br>
<br>
Changed all occurrences of $wsListen to $wsRead<code>[</code>0<code>]</code>.<br>
<br>
Changed sizeof($wsRead)-1 to global variable $wsClientCount to hold the amount of clients connected.<br>
<br>
<h3>New Features</h3>

Added function wsGetClientSocket(integer ClientID)<br>
<br>
Added setting WS_MAX_CLIENTS_PER_IP<br>
<br>
Added "integer MessageLength" to callback wsOnMessage().<br>
New syntax: wsOnMessage(integer ClientID, string Message, integer MessageLength, boolean Binary)<br>
<br>
Added setting WS_MAX_FRAME_PAYLOAD_RECV, to limit the maximum payload data length of a frame.<br>
<br>
Added setting WS_MAX_MESSAGE_PAYLOAD_RECV, to limit the maximum payload data length of a message.<br>
<br>
<h3>Bug Fixes</h3>

Made wsStopServer() clear the $wsClients array.<br>
<br>
When the max amount of clients is reached and a client connects, socket_accept() is now called, then socket_close() is called instantly after.<br>
<br>
Fixed ping requests from being sent to clients after they connect and don't send any data, the server now waits WS_IDLE_LIMIT_READ seconds.<br>
<br>
Fixed wsStartServer() to close the listen socket before returning false, if the server was unable to start.<br>
<br>
When a frame is received from a client, the frame string length is now validated.<br>
This fixed a php warning where the mask key bit was set, but the actual mask key was less than 4 bytes.<br>
<br>
When a frame is received from a client with no payload data, the payload data decoding stage is now skipped.<br>
This fixed the payload data from being read as 1 byte, and pong replies to a client now send no payload data back if none was received.<br>
<br>
Clients in the connecting ready state that have not sent the opening handshake request, can now be timed out by the server, after WS_IDLE_LIMIT_READ seconds.<br>
<br>
Made wsStopServer() close the TCP connection after sending a close frame, for all clients.<br>
<br>
Made wsStopServer() only tell clients that the server is 'going away' if the opening handshake is complete.<br>
<br>
Added frame buffering for incoming frames. Frames over 4096 bytes can now be read!<br>
<br>
Fixed wsOnMessage() from being called with "boolean Binary" set to false, when a binary message is fragmented.<br>
<br>
Fixed reading too many bytes into a frame if more bytes were received than the amount specified in the frame's payload length. The extra bytes are now parsed to the next frame.<br>
<br>
If a non continuation frame is received from a client when there is data in the message buffer, the message buffer is now cleared before processing the frame.<br>
<br>
Limited a frame's payload data length to 2,147,479,538 (2,147,483,647 - 14 - 4095).<br>
The maximum integer in PHP is usually 2,147,483,647, then 14 bytes are taken for the frame's header length, and 4095 as a maximum for the last recv() call.<br>
<br>
Limited a message's payload data length to 2,147,483,647. This is usually the maximum integer in PHP.<br>
<br>
Changed the value of WS_STATUS_TIMEOUT from 1 to 3000, as range 0-999 is not used.<br>
<br>
Prevented data from being sent to a client after a close frame has been sent to that client.<br>
<br>
<h3>Small Changes</h3>

Changed WS_CLOSE_STATUS_TIMEOUT to WS_STATUS_TIMEOUT for consistency with other close status constant names.<br>
<br>
Changed WS_IDLE_LIMIT_READ to WS_TIMEOUT_RECV, and WS_IDLE_LIMIT_PONG to WS_TIMEOUT_PONG