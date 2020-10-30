<?php 
#############################################################
$main_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($main_socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($main_socket, '0.0.0.0', '10000');
socket_listen($main_socket);
#########################################################################
$socket_list = array('main' => $main_socket);
$handshake_check = array();
$srv_msg = array("server_data" => "Server version 1.2 - telnet");
while (true) {
    $loop_data = array();
    //$loop_data["server_data"] = $srv_msg;
    ## ####################################################
    $read_socket_list = $socket_list;
    $result = @socket_select($read_socket_list, $write = NULL, $except = NULL, NULL);
    if ($result === false) {
        break;
    }
    #####################################################################
    foreach ($read_socket_list as $read_socket) {
        if ($read_socket == $main_socket) {
            ####################################################
            $sub_socket = socket_accept($main_socket);
            socket_getpeername($sub_socket, $addr, $port);
            $addr_port = "{$addr}:{$port}";
            $socket_list[$addr_port] = $sub_socket;
            echo "<{$addr_port}>[Connect]\n"; //<-> endpoint - client entry
            #############################################################
        } else {
            if(socket_getpeername($read_socket, $addr, $port) === false) {
                    $addr_port = "{$addr}:{$port}";
                    unset($socket_list[$addr_port]);
                    unset($handshake_check[$addr_port]);
                    socket_close($read_socket);
                    echo "<{$addr_port}>[Close]\n"; // <-> endpoint - client leave
                    $loop_data[] = "Client {$addr_port} left. (Not responding)";
            }
            $addr_port = "{$addr}:{$port}";
            if (!isset($handshake_check[$addr_port])) {
                # ##############################################
                handshake($read_socket);
                $handshake_check[$addr_port] = true;
                #########################################################
            } else {
                $bytes = socket_recv($read_socket, $buffer, 2048, 0); //software limit is 2030 bytes, 8 bytes are reserved for socket data, 10 bytes as reserve, if overflow -> error //still unsolved :(
                srv_log("Original bytes: ". $bytes ." | Computed bytes: ". strlen($buffer) . " <-> \"".unmask($buffer)[0]."\"\n");
                if ($bytes === false || (ord($buffer[0]) & 15) == 8) {
                    #############################################
                    unset($socket_list[$addr_port]);
                    unset($handshake_check[$addr_port]);
                    socket_close($read_socket);
                    echo "<{$addr_port}>[Close]\n"; // <-> endpoint - client leave
                    $loop_data[] = "Client {$addr_port} left.";
                    break;
                    #####################################################
                } else {
                    ############################################
                    $msg = unmask($buffer)[0];
                    $loop_data[] = "$addr_port <->".$msg; // <-> endpoint - client data
                    #####################################################
                }
            }
            // end if
        }
        // end if
    }
    if(count($loop_data) < 1) {
        continue;
    } else {
        $echo_msg = mask(json_encode($loop_data));
        foreach($socket_list as $r_cl) {
            if (!isset($handshake_check[$addr_port])) {
                continue;
            } else {
                if($r_cl == $main_socket) {
                    continue;
                }
                socket_write($r_cl, $echo_msg, strlen($echo_msg));
            }
        }
        print(json_encode($loop_data)." <-> test\n");
    }
    // end foreach
    print("\n");
}
// end while
function parse_header($str)
{
    $str = preg_replace('/\\r/', '', $str);
    preg_match_all('/^([^:\\n]+): (.*)$/m', $str, $matches);
    $header_list = array();
    foreach ($matches[1] as $i => $key) {
        $header_list[$key] = $matches[2][$i];
    }
    return $header_list;
}
function handshake($socket)
{
    $bytes = socket_recv($socket, $buffer, 2048, 0);
    $header_list = parse_header($buffer);
    $accept = $header_list['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    $accept = base64_encode(sha1($accept, true));
    $upgrade = array('HTTP/1.1 101 Switching Protocols', 'Upgrade: websocket', 'Connection: Upgrade', "Sec-WebSocket-Accept: {$accept}", "WebSocket-Origin: {$header_list['Origin']}", "WebSocket-Location: ws://{$header_list['Host']}/ws/server.php", "\r\n");
    $upgrade = implode("\r\n", $upgrade);
    socket_write($socket, $upgrade);
}
function unmask($payload) {
    $length = ord($payload[1]) & 127; //127
    if ($length == 126) {
        $masks = substr($payload, 4, 4);
        $data = substr($payload, 8);
    } elseif ($length == 127) {
        $masks = substr($payload, 10, 4);
        $data = substr($payload, 14);
    } else {
        $masks = substr($payload, 2, 4);
        $data = substr($payload, 6);
    }
    $text = '';
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return array($text, $length);
}
function mask($text) {
    $b1 = 0x80 | 0x1 & 0xf; // 0x1 text frame (FIN + opcode)
    $length = strlen($text);
    if ($length <= 125) {
        $header = pack('CC', $b1, $length);
    } elseif ($length > 125 && $length < 65536) {
        $header = pack('CCn', $b1, 126, $length);
        print("debug");
    } elseif ($length >= 65536) {
        print("debug2 \n");
        $header = pack('CCxN', $b1, 127, 0, $length);
    }
    return $header . $text;
}
function srv_log($data) {
    print(date("H:i:s")." | ".$data);
}
?>