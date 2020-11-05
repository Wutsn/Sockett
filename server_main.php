<?php 
/*
extension needed: mbstring

*/
#############################################################
$main_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($main_socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($main_socket, '0.0.0.0', '10000');
socket_listen($main_socket);
#########################################################################
$socket_list = array('main' => $main_socket);
$handshake_check = array();
$clients = array();
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
            $loop_data["msg"][] = "<{$addr_port}>[Connect]\n";
            $clients[] = array("ip:" => $addr_port, "lastframe" => "");
            #############################################################
        } else {
            if(socket_getpeername($read_socket, $addr, $port) === false) {
                    $addr_port = "{$addr}:{$port}";
                    unset($socket_list[$addr_port]);
                    unset($handshake_check[$addr_port]);
                    socket_close($read_socket);
                    echo "<{$addr_port}>[Close]\n"; // <-> endpoint - client leave
                    $loop_data["msg"][] = "Client {$addr_port} left. (Not responding)";
                    unset($clients[searchforclient($clients, $addr_port)]);
            }
            $addr_port = "$addr:$port";
            $cl = searchforclient($clients, $addr_port);
            if (!isset($handshake_check[$addr_port])) {
                # ##############################################
                handshake($read_socket);
                $handshake_check[$addr_port] = true;
                #########################################################
            } else {
                $bytes = socket_recv($read_socket, $buffer, 2048, 0); //0 //software limit is 2040 bytes, 8 bytes are reserved for socket data, if overflow -> error //still unsolved :(
                //srv_log("Original bytes: ". $bytes ." | Computed bytes: ". strlen($buffer) . " <-> \"".unmask($buffer)[0]."\"\n");
                if ($bytes === false || (ord($buffer[0]) & 15) == 8) {
                    #############################################
                    unset($socket_list[$addr_port]);
                    unset($handshake_check[$addr_port]);
                    socket_close($read_socket);
                    echo "<{$addr_port}>[Close]\n"; // <-> endpoint - client leave
                    $loop_data["msg"][] = "Client {$addr_port} left.";
                    unset($clients[$cl]);
                    break;
                    #####################################################
                } else {
                    ############################################
                    $msg = json_decode(unmask($buffer)[0]);
                    //srv_log($msg);
                    if($msg == "") { //no data, possible error, overflow or something -> tell to client to send all unconfirmed messages
                        $err_msg = mask(json_encode("UNKNOWN-ERR<->"));
                        socket_write($read_socket, $err_msg, strlen($err_msg));
                    } else {
                        if(intval(substr(unmask($buffer)[0],2,6)) != mb_strlen(unmask($buffer)[0])) { //message got corrupted over the network, tell client to send this frame again
                            $err_msg = mask(json_encode("MSG-ERR<->".$msg[1]));
                            socket_write($read_socket, $err_msg, strlen($err_msg));
                            break;
                        } else { //message is ok, tell to client to forgot this good frame
                            $suc_msg = mask(json_encode("MSG-OK<->".$msg[1]));
                            socket_write($read_socket, $suc_msg, strlen($suc_msg));
                            if($clients[$cl]["lastframe"] != $msg[1]) {
                                $loop_data["clients"][$addr_port] = $msg; // <-> endpoint - client data
                                $clients[$cl]["lastframe"] = $msg[1];
                            } else {
                                print("Skipping frame from $addr_port, duplicity.\n");
                            }
                            if(!isset($clients[$cl]["jmeno"])) {
                                $clients[$cl]["jmeno"] = $msg[2][0];
                                $clients[$cl]["uid"] = $msg[2][1];
                            }
                            
                        }
                    }
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
        }print_r($loop_data).print("\n<- loop data ->\n");
    }
    // end foreach
    print("\n");
}
// end while
function searchforclient($haystack, $needle) {
    if(count($haystack) != 0 ) {
        for($x = 0; $x < count($haystack); $x++) {
            if(isset($haystack[$x])) {
                if($haystack[$x]["ip:"] == $needle) {
                    return $x;
                }
            }
        }
    }
    return null;
 }
function parse_header($str) {
    $str = preg_replace('/\\r/', '', $str);
    preg_match_all('/^([^:\\n]+): (.*)$/m', $str, $matches);
    $header_list = array();
    foreach ($matches[1] as $i => $key) {
        $header_list[$key] = $matches[2][$i];
    }
    return $header_list;
}
function handshake($socket) {
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
    } elseif ($length >= 65536) {
        $header = pack('CCxN', $b1, 127, 0, $length);
    }
    return $header . $text;
}
function srv_log($data) {
    print(date("H:i:s")." | \n").print_r($data);
}

?>