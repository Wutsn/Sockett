class klientt {
    constructor(jmeno, host) {
        this.jmeno = jmeno;
        this.host = host;
        this.clid = GRS(5);
        this.memory = [];
        this.uepause = true;
    }
    socket_create() {
        this.socket = new WebSocket(this.host);
        this.socket.onopen = this.data_out("Connection was established...");
    }
    read_data(){
        var superbridge = this;
        this.socket.onmessage = function(msg) {
            var brmsg = msg.data;
            if(brmsg.includes("MSG-OK<->")) {
                var msgkey = arr_find(superbridge.memory ,brmsg.substring(10,13));
                superbridge.memory.splice(msgkey[0],1);
                console.log(superbridge.memory);
            } else if(brmsg.includes("MSG-ERR<->")) {
                var msgkey = arr_find(superbridge.memory ,brmsg.substring(11,14));
                if(superbridge.memory[msgkey[0]][1] > 2) {
                    alert("Part of data frame dropped -> exiting. (3 attempts)");
                    superbridge.terminator(true);
                } else {
                    superbridge.memory[msgkey[0]][1]++;
                    console.log("Resending key: "+msgkey);
                    superbridge.fail_toast(superbridge.memory[msgkey[0]][2]);
                    superbridge.memory[msgkey[0]][4] = 1;
                }
            } else if(brmsg.includes("UNKNOWN-ERR<->")) {
                for(let x = 0; x < superbridge.memory.length; x++) {
                    if(superbridge.memory[x][4] == 0) {
                        if(superbridge.memory[x][3] == false) {
                            superbridge.fail_toast(superbridge.memory[x][2]);
                            superbridge.memory[x][3] = true;
                            superbridge.memory[x][1]++;
                        } else {
                            if(superbridge.memory[x][1] < 6) {
                                if(superbridge.memory[x][1] == 5) {
                                    superbridge.fail_toast(superbridge.memory[x][2]);
                                }
                                superbridge.memory[x][1]++;
                            } else {
                                alert("Corrupted data. exiting. (5 attempts)");
                                superbridge.terminator(true);
                            }
                        }
                    }
                }
            } else {
                console.log(brmsg);
            }
        };
    }
    toast(msg) {
        var dmsg = [this.jmeno, this.clid, msg];
        var msgid = GRS(3);
        var finmsg = msg_pack(dmsg, msgid);
        //this.socket.send(finmsg);
        this.memory.push([msgid,0,finmsg,false,0]);
        this.memory.forEach(row => this.socket.send(row[2]));
        console.log((this.memory));
    }
    fail_toast(msg) {
        this.socket.send(msg);
    }
    data_out(data) {
        console.log(data);
    }
    terminator(sec) {
        if(sec == true) {
            this.socket.close();
            this.socket = null;
        }
    }
}

var host = "ws://109.164.113.230:10000";
function start() {
    name = prompt("Jaké je vaše jméno?");
    klient = new klientt(name, host);
    klient.socket_create();
    klient.read_data();
}
function stop() {
    klient.terminator(true);
}
function write(msgg) {
    var datalenght = msgg.length;
    if(datalenght <= 1900) {
        klient.toast(msgg);
    } else {
        alert("data too long ("+datalenght+" | max: 1900) <-> fix needed!");
    }
}
function msg_pack(msg, id) {
    var text = ["0000", id, msg];
    text = JSON.stringify(text);
    var textlen = text.length;
    var textdata = text.replace(text.substring(2,6),("000" + textlen).slice(-4));
    return textdata;
}
// Utilities

function arr_find(haystack, needle) {  //max 2 dimensions
    var haystacklen = haystack.length;
    for(let x = 0; x < haystacklen-1; x++) {
        if(Array.isArray(haystack[x])){
            var haystacklen2 = haystack[x].length;
            for(let y = 0; y < haystacklen2; y++) {
                if(haystack[x][y] == needle) {
                    return [x,y];
                }
            }
        } else {
            if(haystack[x] == needle) {
                return [x];
            }
        }
    }
    return false;
}
function GRS(length) {
    var result = '';
    var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    var charactersLength = characters.length;
    for ( var i = 0; i < length; i++ ) {
       result += characters.charAt(Math.floor(Math.random() * charactersLength));
    }
    return result;
}

function log(msg){ document.getElementById("log").innerHTML+="\n"+msg; }
function onkey(event){ if(event.keyCode==13){ write("aa"); } }