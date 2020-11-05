class klientt {
    constructor(jmeno, host) {
        this.jmeno = jmeno; //name
        this.host = host; //host ip
        this.clid = this.GRS(5); //client id (if multile clients very useful)
        this.memory = []; //memory block
    }
    socket_create() {
        this.socket = new WebSocket(this.host);
        this.socket.onopen = this.data_out("Connection was established...");
    }
    read_data(){ //read from socket
        var superbridge = this;
        this.socket.onmessage = function(msg) {
            var brmsg = msg.data;
            if(brmsg.includes("MSG-OK<->")) { //if ok, just forget msg
                var msgkey = superbridge.arr_find(superbridge.memory ,brmsg.substring(10,13));
                superbridge.memory.splice(msgkey[0],1);
                //console.log(superbridge.memory);
            } else if(brmsg.includes("MSG-ERR<->")) { //if err, resend 3 times (msgid is ok, but part of msg got currupted over the network or sth)
                var msgkey = superbridge.arr_find(superbridge.memory ,brmsg.substring(11,14));
                if(superbridge.memory[msgkey[0]][1] > 2) {
                    alert("Part of data frame dropped -> exiting. (3 attempts)");
                    superbridge.terminator(true);
                } else {
                    superbridge.memory[msgkey[0]][1]++;
                    console.log("Resending key: "+msgkey);
                    superbridge.fail_toast(superbridge.memory[msgkey[0]][2]);
                    superbridge.memory[msgkey[0]][4] = 1;
                }
            } else if(brmsg.includes("UNKNOWN-ERR<->")) { //msg completely corrupted or server is not able to read it
                for(let x = 0; x < superbridge.memory.length; x++) {
                    if(superbridge.memory[x][4] == 0) { //if UNKNOWN-ERR do this
                        if(superbridge.memory[x][3] == false) {// if not marked, resend
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
                                alert("Corrupted data. exiting. (5 attempts)"); //drop if resend not succeed in 5 tries
                                superbridge.terminator(true);
                            }
                        }
                    }
                }
            } else {
                log(brmsg); //output data
                console.log(brmsg); //same but to console
            }
        };
    }
    toast(msg) { //push msg to memory and than loop tru memory and send
        var datalenght = msg.length;
        if(datalenght <= 1900) { //max lenght 1900 data (rest, like name, id, len, brackets don't)
            var dmsg = [this.jmeno, this.clid, msg];
            var msgid = this.GRS(3);
            var finmsg = this.msg_pack(dmsg, msgid);
            //this.socket.send(finmsg);
            this.memory.push([msgid,0,finmsg,false,0]);
            this.memory.forEach(row => this.socket.send(row[2]));
            //console.log((this.memory));
        } else {
            alert("data too long ("+datalenght+" | max: 1900) <-> fix needed!");
        }
    }
    fail_toast(msg) { //send msg no matter what
        this.socket.send(msg);
    }
    data_out(data) { //just log
        console.log(data);
    }
    terminator(sec) { //end connection
        if(sec == true) {
            this.socket.close();
            this.socket = null;
        }
    }
    msg_pack(msg, id) { //make msg compact, parse id with message (if msg fail, script can resend data)
        var text = ["0000", id, msg];
        text = JSON.stringify(text);
        var textlen = text.length;
        var textdata = text.replace(text.substring(2,6),("000" + textlen).slice(-4));
        return textdata;
    }
    arr_find(haystack, needle) {  //find needle (value), max 2 dimensions
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
    GRS(length) { //renerate random string 
        var result = '';
        var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var charactersLength = characters.length;
        for ( var i = 0; i < length; i++ ) {
           result += characters.charAt(Math.floor(Math.random() * charactersLength));
        }
        return result;
    }
}
var host = "ws://109.164.113.230:10000"; //your own ip w/port
function start() {
    name = prompt("Jaké je vaše jméno?"); //init for name
    klient = new klientt(name, host); //init klientt 
    klient.socket_create(); //connect
    klient.read_data(); //prepare for reading
}
function stop() { //terminate connection
    klient.terminator(true);
}
function write(msgg) { //handle function for input
    klient.toast(msgg);
}
function log(msg){ document.getElementById("log").innerHTML+=msg+"<br>"; }
function onkey(event){ if(event.keyCode==13){ write(document.getElementById("msg").value); } }
function txt_write() {write(document.getElementById("msg").value);}